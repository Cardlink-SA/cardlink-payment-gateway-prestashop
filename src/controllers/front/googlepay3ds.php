<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Google Pay 3DS Callback Controller
 *
 * The MPI (Merchant Plug-In) redirects back here after 3D-Secure authentication:
 *   okUrl  → .../googlepay3ds&result=success
 *   failUrl → .../googlepay3ds&result=failure
 *
 * Flow on success:
 *   1. Parse MPI response form fields (3DS authentication results)
 *   2. Look up stored transaction info by XID
 *   3. Send a SECOND SaleRequest to VPOS XML API with the 3DS data
 *   4. If CAPTURED/AUTHORIZED → create PrestaShop order → redirect to success page
 *
 * Flow on failure:
 *   1. Log the failure details
 *   2. Redirect to checkout with an error message
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use Cardlink_Checkout\Constants;
use Cardlink_Checkout\GooglePayHelper;
use Cardlink_Checkout\CardlinkXmlApi;
use Cardlink_Checkout\PaymentResponseProcessor;

class Cardlink_CheckoutGooglepay3dsModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public const ERROR_REDIRECT_URI = 'index.php?controller=order&step=1';

    /**
     * Handle the 3DS MPI callback.
     */
    public function postProcess()
    {
        // Force SameSite=None for session cookie to handle cross-domain MPI redirects
        \Cardlink_Checkout\PaymentHelper::forceSessionCookieSameSiteNone();

        $xid = '';
        try {
            $params = Tools::getAllValues();

            // Determine success/failure from route param and mdStatus
            $routeResult = Tools::getValue('result', '');
            $mdStatus = $params['mdStatus'] ?? '';
            $mdErrorMsg = $params['mdErrorMsg'] ?? '';

            // mdStatus: 0=no auth, 1=full auth, 2=couldn't auth, 3=denied, 4=attempted
            // Accept 0, 1, 2, 4 — let VPOS make the final decision
            $acceptable = ['0', '1', '2', '4'];

            if ($routeResult === 'failure' || !in_array($mdStatus, $acceptable, true)) {
                PrestaShopLogger::addLog(
                    "Cardlink Google Pay 3DS failed: mdStatus={$mdStatus}, msg={$mdErrorMsg}",
                    2
                );

                // Clean up stale transaction data
                $failedXid = $params['xid'] ?? '';
                if ($failedXid !== '') {
                    GooglePayHelper::removeTransactionInfo($failedXid);
                    GooglePayHelper::persistToSession();
                    GooglePayHelper::removeFromCache($failedXid);
                }

                $this->redirectWithError('3D-Secure authentication failed. Please try again or choose a different payment method.');
                return;
            }

            // ── Restore transaction info from session or cache ──
            GooglePayHelper::restoreFromSession();

            $xid = $params['xid'] ?? '';
            $txInfo = GooglePayHelper::getTransactionInfo($xid);

            // Fallback: try file cache (survives cross-domain MPI redirect)
            if (!$txInfo) {
                PrestaShopLogger::addLog(
                    "Cardlink Google Pay 3DS: Session lookup failed for XID={$xid}, trying cache",
                    1
                );
                $txInfo = GooglePayHelper::restoreFromCache($xid);
            }

            if (!$txInfo) {
                PrestaShopLogger::addLog(
                    "Cardlink Google Pay 3DS: No stored transaction info for XID={$xid}",
                    3
                );
                $this->redirectWithError('Payment session expired. Please try again.');
                return;
            }

            $txId = $txInfo['trId'] ?? '';
            $orderId = $txInfo['orderId'] ?? '';
            $paymentTotal = $txInfo['paymentTotal'] ?? '';
            $currency = $txInfo['currency'] ?? 'EUR';
            $payMethod = $txInfo['payMethod'] ?? 'visa';
            $cartId = $txInfo['cartId'] ?? 0;

            // ── Build 3DS data from MPI response fields ──
            $protocol = $params['protocol']
                ?? $params['tds2MessageVersion']
                ?? '';
            if ($protocol === '' && isset($params['version'])) {
                $mpiVer = $params['version'];
                if (version_compare($mpiVer, '2.0', '>=')) {
                    $protocol = '3DS2.2.0';
                }
            }

            $authenticationStatus = $params['txstatus']
                ?? $params['paresTxStatus']
                ?? '';

            $threeDSData = [
                'enrollmentStatus' => $params['veresEnrolledStatus'] ?? '',
                'authenticationStatus' => $authenticationStatus,
                'cavv' => $params['cavv'] ?? '',
                'xid' => $xid,
                'eci' => $params['eci'] ?? '',
                'protocol' => $protocol,
            ];

            // ── Send SECOND SaleRequest with 3DS results ──
            $api = new CardlinkXmlApi(
                GooglePayHelper::getMerchantId(),
                GooglePayHelper::getSharedSecret(),
                GooglePayHelper::getBusinessPartner(),
                GooglePayHelper::getTransactionEnvironment()
            );

            $orderDesc = 'CART ' . $cartId;

            $saleResponse = $api->walletSaleWith3DS(
                $orderId,
                $paymentTotal,
                $currency,
                $txId,
                $payMethod,
                $threeDSData,
                $orderDesc
            );

            $vposStatus = strtoupper($saleResponse->getStatus() ?? '');

            PrestaShopLogger::addLog(
                "Cardlink Google Pay 3DS: Second SaleResponse status={$vposStatus} for orderId={$orderId}",
                1
            );

            if (in_array($vposStatus, ['CAPTURED', 'AUTHORIZED'], true)) {
                // ── Payment succeeded — create order ──
                $this->createOrderAndRedirect($txInfo, $saleResponse, $vposStatus, $xid);
                return;
            }

            // ── Payment failed ──
            $errorDesc = $saleResponse->get('Description', '')
                ?: ($saleResponse->getError() ?? '');
            PrestaShopLogger::addLog(
                "Cardlink Google Pay 3DS: Payment failed status={$vposStatus}, error={$errorDesc}",
                3
            );

            // Clean up
            GooglePayHelper::removeTransactionInfo($xid);
            GooglePayHelper::persistToSession();
            GooglePayHelper::removeFromCache($xid);

            $this->redirectWithError('Payment could not be completed. Please try again or choose a different payment method.');

        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'Cardlink Google Pay 3DS error: ' . $e->getMessage(),
                3
            );

            if ($xid !== '') {
                try {
                    GooglePayHelper::removeTransactionInfo($xid);
                    GooglePayHelper::persistToSession();
                    GooglePayHelper::removeFromCache($xid);
                } catch (\Exception $cleanupEx) {
                    // Ignore cleanup errors
                }
            }

            $this->redirectWithError('An error occurred during payment processing. Please try again.');
        }
    }

    /**
     * Create a PrestaShop order from the cart and redirect to success page.
     */
    private function createOrderAndRedirect(
        array $txInfo,
        $saleResponse,
        string $vposStatus,
        string $xid
    ): void {
        $cartId = $txInfo['cartId'] ?? 0;
        $orderId = $txInfo['orderId'] ?? '';
        $paymentTotal = $txInfo['paymentTotal'] ?? '';
        $currency = $txInfo['currency'] ?? 'EUR';
        $payMethod = $txInfo['payMethod'] ?? 'visa';

        $cart = new Cart((int) $cartId);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog(
                "Cardlink Google Pay 3DS: Cart {$cartId} not found",
                3
            );
            $this->redirectWithError('Cart not found. Please try again.');
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            PrestaShopLogger::addLog(
                "Cardlink Google Pay 3DS: Customer not found for cart {$cartId}",
                3
            );
            $this->redirectWithError('Customer not found. Please try again.');
            return;
        }

        // Determine order state
        if ($vposStatus === Constants::TRANSACTION_STATUS_CAPTURED) {
            $orderState = (int) Configuration::get(
                Constants::CONFIG_ORDER_STATUS_CAPTURED,
                null, null, null,
                Configuration::get('PS_OS_PAYMENT')
            );
        } else {
            $orderState = (int) Configuration::get(
                Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                null, null, null,
                Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
            );
        }

        try {
            // Check if order was already created
            $existingOrderId = Order::getIdByCartId((int) $cart->id);
            if ($existingOrderId) {
                $order_details = new Order((int) $existingOrderId);
            } else {
                $total_amount = $cart->getOrderTotal(true, Cart::BOTH);
                $isCaptured = ($vposStatus === Constants::TRANSACTION_STATUS_CAPTURED);

                $this->module->validateOrder(
                    (int) $cart->id,
                    $orderState,
                    $total_amount,
                    $this->module->displayName . ' (Google Pay)',
                    null,
                    [],
                    (int) $cart->id_currency,
                    false,
                    $customer->secure_key
                );

                $order_id = $this->module->currentOrder;
                $order_details = new Order($order_id);

                // Store Cardlink order ID
                PaymentResponseProcessor::storeCardlinkOrderId($order_id, $orderId);

                // Create transaction record
                $vposTxId = $saleResponse->getTransactionId() ?? $txInfo['trId'];
                $vposPayRef = $saleResponse->getPaymentRef() ?? '';

                try {
                    \CardlinkPaymentTransaction::createTransaction([
                        'id_order' => $order_id,
                        'cardlink_order_id' => $orderId,
                        'cardlink_tx_id' => $vposTxId,
                        'cardlink_pay_status' => $vposStatus,
                        'cardlink_pay_method' => strtoupper($payMethod) . ' (Google Pay)',
                        'cardlink_pay_ref' => $vposPayRef,
                        'order_amount' => $total_amount,
                        'currency' => $currency,
                        'transaction_type' => $isCaptured ? 'sale' : 'authorize',
                    ]);
                } catch (\Exception $txEx) {
                    PrestaShopLogger::addLog(
                        'Cardlink Google Pay: Failed to create transaction record: ' . $txEx->getMessage(),
                        3
                    );
                }

                // Handle payment record
                if ($isCaptured) {
                    $responseData = [
                        \Cardlink_Checkout\ApiFields::Status => $vposStatus,
                        \Cardlink_Checkout\ApiFields::TransactionId => $vposTxId,
                        \Cardlink_Checkout\ApiFields::PaymentMethod => strtoupper($payMethod),
                        \Cardlink_Checkout\ApiFields::PaymentReferenceId => $vposPayRef,
                        \Cardlink_Checkout\ApiFields::OrderId => $orderId,
                        \Cardlink_Checkout\ApiFields::OrderAmount => $total_amount,
                        \Cardlink_Checkout\ApiFields::PaymentTotal => $saleResponse->getPaymentTotal() ?? $total_amount,
                        \Cardlink_Checkout\ApiFields::Currency => $currency,
                        \Cardlink_Checkout\ApiFields::Message => $saleResponse->get('Description', ''),
                    ];
                    PaymentResponseProcessor::handleCapturedPaymentRecord(
                        $order_details,
                        $responseData,
                        $total_amount,
                        $this->module->displayName . ' (Google Pay)'
                    );
                } else {
                    PaymentResponseProcessor::handlePreAuthCleanup($order_details);
                }
            }

            PrestaShopLogger::addLog(
                "Cardlink Google Pay 3DS: Order {$order_details->reference} created successfully",
                1
            );

            // Clean up transaction data
            GooglePayHelper::removeTransactionInfo($xid);
            GooglePayHelper::persistToSession();
            GooglePayHelper::removeFromCache($xid);

            // Redirect to order confirmation
            $redirectParameters = [
                'controller' => 'order-confirmation',
                'id_shop' => $order_details->id_shop,
                'id_cart' => (int) $cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => $order_details->id,
                'key' => $customer->secure_key,
            ];

            $this->redirectToStore('index.php', $redirectParameters);

        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'Cardlink Google Pay 3DS: Order creation failed: ' . $e->getMessage(),
                3
            );

            // Clean up
            GooglePayHelper::removeTransactionInfo($xid);
            GooglePayHelper::persistToSession();
            GooglePayHelper::removeFromCache($xid);

            $this->redirectWithError('Payment could not be completed. Please try again.');
        }
    }

    /**
     * Redirect to checkout with an error message.
     */
    private function redirectWithError(string $message): void
    {
        $this->context->cookie->__set('cardlink_gpay_error', $message);
        $this->redirectToStore(self::ERROR_REDIRECT_URI);
    }

    private function isIframeModalMode(): bool
    {
        return Configuration::get(
            Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE,
            null,
            null,
            null,
            Constants::THREE_DS_UI_MODE_REDIRECT
        ) === Constants::THREE_DS_UI_MODE_IFRAME_MODAL;
    }

    private function redirectToStore(string $url, array $params = []): void
    {
        if (!$this->isIframeModalMode()) {
            if (!empty($params)) {
                Tools::redirect($url . '?' . http_build_query($params));
            }
            Tools::redirect($url);
            return;
        }

        $formData = $params;
        if (empty($formData)) {
            $query = parse_url($url, PHP_URL_QUERY);
            if (!empty($query)) {
                parse_str($query, $formData);
            }
        }

        $action = explode('?', $url)[0];
        $this->context->smarty->assign([
            'action' => $action,
            'form_data' => $formData,
            'css_url' => $this->module->getPathUri() . 'views/css/front-custom.css',
            'use_iframe' => true,
        ]);

        $this->setTemplate('module:cardlink_checkout/views/templates/front/response_form.tpl');
    }
}
