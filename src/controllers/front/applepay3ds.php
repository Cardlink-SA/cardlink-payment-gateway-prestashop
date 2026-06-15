<?php

use Cardlink_Checkout\Constants;
use Cardlink_Checkout\ApplePayHelper;
use Cardlink_Checkout\CardlinkXmlApi;
use Cardlink_Checkout\PaymentResponseProcessor;

class Cardlink_CheckoutApplepay3dsModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public const ERROR_REDIRECT_URI = 'index.php?controller=order&step=1';

    public function postProcess()
    {
        \Cardlink_Checkout\PaymentHelper::forceSessionCookieSameSiteNone();

        $xid = '';
        try {
            $params = Tools::getAllValues();

            $routeResult = Tools::getValue('result', '');
            $mdStatus = $params['mdStatus'] ?? '';
            $mdErrorMsg = $params['mdErrorMsg'] ?? '';
            $acceptable = ['0', '1', '2', '4'];

            if ($routeResult === 'failure' || !in_array($mdStatus, $acceptable, true)) {
                PrestaShopLogger::addLog(
                    "Cardlink Apple Pay 3DS failed: mdStatus={$mdStatus}, msg={$mdErrorMsg}",
                    2
                );

                $failedXid = $params['xid'] ?? '';
                if ($failedXid !== '') {
                    ApplePayHelper::removeTransactionInfo($failedXid);
                    ApplePayHelper::persistToSession();
                    ApplePayHelper::removeFromCache($failedXid);
                }

                $this->redirectWithError('3D-Secure authentication failed. Please try again or choose a different payment method.');
                return;
            }

            ApplePayHelper::restoreFromSession();

            $xid = $params['xid'] ?? '';
            $txInfo = ApplePayHelper::getTransactionInfo($xid);

            if (!$txInfo) {
                PrestaShopLogger::addLog(
                    "Cardlink Apple Pay 3DS: Session lookup failed for XID={$xid}, trying cache",
                    1
                );
                $txInfo = ApplePayHelper::restoreFromCache($xid);
            }

            if (!$txInfo) {
                PrestaShopLogger::addLog(
                    "Cardlink Apple Pay 3DS: No stored transaction info for XID={$xid}",
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

            $api = new CardlinkXmlApi(
                ApplePayHelper::getMerchantId(),
                ApplePayHelper::getSharedSecret(),
                ApplePayHelper::getBusinessPartner(),
                ApplePayHelper::getTransactionEnvironment()
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
                "Cardlink Apple Pay 3DS: Second SaleResponse status={$vposStatus} for orderId={$orderId}",
                1
            );

            if (in_array($vposStatus, ['CAPTURED', 'AUTHORIZED'], true)) {
                $this->createOrderAndRedirect($txInfo, $saleResponse, $vposStatus, $xid);
                return;
            }

            $errorDesc = $saleResponse->get('Description', '')
                ?: ($saleResponse->getError() ?? '');
            PrestaShopLogger::addLog(
                "Cardlink Apple Pay 3DS: Payment failed status={$vposStatus}, error={$errorDesc}",
                3
            );

            ApplePayHelper::removeTransactionInfo($xid);
            ApplePayHelper::persistToSession();
            ApplePayHelper::removeFromCache($xid);

            $this->redirectWithError('Payment could not be completed. Please try again or choose a different payment method.');
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'Cardlink Apple Pay 3DS error: ' . $e->getMessage(),
                3
            );

            if ($xid !== '') {
                try {
                    ApplePayHelper::removeTransactionInfo($xid);
                    ApplePayHelper::persistToSession();
                    ApplePayHelper::removeFromCache($xid);
                } catch (\Exception $cleanupEx) {
                }
            }

            $this->redirectWithError('An error occurred during payment processing. Please try again.');
        }
    }

    private function createOrderAndRedirect(
        array $txInfo,
        $saleResponse,
        string $vposStatus,
        string $xid
    ): void {
        $cartId = $txInfo['cartId'] ?? 0;
        $orderId = $txInfo['orderId'] ?? '';
        $currency = $txInfo['currency'] ?? 'EUR';
        $payMethod = $txInfo['payMethod'] ?? 'visa';

        $cart = new Cart((int) $cartId);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog("Cardlink Apple Pay 3DS: Cart {$cartId} not found", 3);
            $this->redirectWithError('Cart not found. Please try again.');
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            PrestaShopLogger::addLog("Cardlink Apple Pay 3DS: Customer not found for cart {$cartId}", 3);
            $this->redirectWithError('Customer not found. Please try again.');
            return;
        }

        if ($vposStatus === Constants::TRANSACTION_STATUS_CAPTURED) {
            $orderState = (int) Configuration::get(
                Constants::CONFIG_ORDER_STATUS_CAPTURED,
                null,
                null,
                null,
                Configuration::get('PS_OS_PAYMENT')
            );
        } else {
            $orderState = (int) Configuration::get(
                Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                null,
                null,
                null,
                Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
            );
        }

        try {
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
                    $this->module->displayName . ' (Apple Pay)',
                    null,
                    [],
                    (int) $cart->id_currency,
                    false,
                    $customer->secure_key
                );

                $order_id = $this->module->currentOrder;
                $order_details = new Order($order_id);

                PaymentResponseProcessor::storeCardlinkOrderId($order_id, $orderId);

                $vposTxId = $saleResponse->getTransactionId() ?? $txInfo['trId'];
                $vposPayRef = $saleResponse->getPaymentRef() ?? '';

                try {
                    \CardlinkPaymentTransaction::createTransaction([
                        'id_order' => $order_id,
                        'cardlink_order_id' => $orderId,
                        'cardlink_tx_id' => $vposTxId,
                        'cardlink_pay_status' => $vposStatus,
                        'cardlink_pay_method' => strtoupper($payMethod) . ' (Apple Pay)',
                        'cardlink_pay_ref' => $vposPayRef,
                        'order_amount' => $total_amount,
                        'currency' => $currency,
                        'transaction_type' => $isCaptured ? 'sale' : 'authorize',
                    ]);
                } catch (\Exception $txEx) {
                    PrestaShopLogger::addLog(
                        'Cardlink Apple Pay: Failed to create transaction record: ' . $txEx->getMessage(),
                        3
                    );
                }

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
                        $this->module->displayName . ' (Apple Pay)'
                    );
                } else {
                    PaymentResponseProcessor::handlePreAuthCleanup($order_details);
                }
            }

            ApplePayHelper::removeTransactionInfo($xid);
            ApplePayHelper::persistToSession();
            ApplePayHelper::removeFromCache($xid);

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
            PrestaShopLogger::addLog('Cardlink Apple Pay 3DS: Order creation failed: ' . $e->getMessage(), 3);

            ApplePayHelper::removeTransactionInfo($xid);
            ApplePayHelper::persistToSession();
            ApplePayHelper::removeFromCache($xid);

            $this->redirectWithError('Payment could not be completed. Please try again.');
        }
    }

    private function redirectWithError(string $message): void
    {
        $this->context->cookie->__set('cardlink_apay_error', $message);
        $this->redirectToStore(self::ERROR_REDIRECT_URI);
    }

    private function isIframeModalMode(): bool
    {
        return Configuration::get(
            Constants::CONFIG_APPLEPAY_3DS_UI_MODE,
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
