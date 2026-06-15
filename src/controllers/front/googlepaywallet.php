<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Google Pay Wallet Controller
 *
 * This is the endpoint that googlepaydirect.js calls when the user
 * authorizes the Google Pay payment. The script appends "/sale" to
 * the wallet URL, and this controller handles the POST.
 *
 * Receives JSON from googlepaydirect.js (endWalletSessionGetResults):
 * {
 *   "version":          "2.1",
 *   "merchantId":       "...",
 *   "orderId":          "...",
 *   "amount":           "55.55",
 *   "currency":         "EUR",
 *   "googlePayResponse":"{...tokenised card data...}"
 * }
 *
 * Builds a <SaleRequest> XML via CardlinkXmlApi::walletSale(), POSTs to VPOS,
 * and returns one of:
 *   {"status":"success", "txId":"..."}
 *   {"status":"processing", ...3DS fields...}
 *   {"status":"fail", "error":"..."}
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use Cardlink_Checkout\GooglePayHelper;
use Cardlink_Checkout\CardlinkXmlApi;

class Cardlink_CheckoutGooglepaywalletModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Do not render any template — all responses are JSON.
     */
    public function display()
    {
    }

    /**
     * Process the wallet sale request.
     */
    public function postProcess()
    {
        // Allow cross-origin requests from the VPOS Google Pay script
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        try {
            $body = file_get_contents('php://input');
            $requestData = json_decode($body, true);

            if (!$requestData || empty($requestData['orderId'])) {
                $this->jsonResponse(['status' => 'fail', 'error' => 'Invalid request data']);
                return;
            }

            $mid = GooglePayHelper::getMerchantId();
            $sharedSecret = GooglePayHelper::getSharedSecret();
            $businessPartner = GooglePayHelper::getBusinessPartner();
            $environment = GooglePayHelper::getTransactionEnvironment();

            $payMethod = 'googlepay';
            $walletPaymentData = $requestData['googlePayResponse'] ?? '';

            $api = new CardlinkXmlApi($mid, $sharedSecret, $businessPartner, $environment);

            // Derive order description
            $parts = explode('x', $requestData['orderId']);
            $cartId = $parts[0] ?? $requestData['orderId'];
            $orderDesc = 'CART ' . $cartId;

            $apiResponse = $api->walletSale(
                $requestData['orderId'],
                $requestData['amount'],
                $requestData['currency'] ?? 'EUR',
                $walletPaymentData,
                $payMethod,
                $orderDesc
            );

            $jsonResponse = $this->buildJsonResponse($apiResponse, $requestData);
            $this->jsonResponse($jsonResponse);

        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Google Pay wallet error: ' . $e->getMessage(), 3);
            $this->jsonResponse([
                'status' => 'fail',
                'error' => 'Internal error processing wallet payment',
            ]);
        }
    }

    /**
     * Convert CardlinkXmlResponse to a JSON-friendly array.
     */
    private function buildJsonResponse($apiResponse, array $requestData): array
    {
        $status = strtoupper($apiResponse->getStatus() ?? '');
        $txId = $apiResponse->getTransactionId() ?? '';

        switch ($status) {
            case 'AUTHORIZED':
            case 'CAPTURED':
                // Direct payment success — create order immediately
                $redirectUrl = $this->createOrderFromDirectPayment($requestData, $apiResponse, $status);
                $result = ['status' => 'success', 'txId' => $txId];
                if (!empty($redirectUrl)) {
                    $result['redirectUrl'] = $redirectUrl;
                }
                return $result;

            case 'PROCESSING':
                $attrs = $apiResponse->get('attributes', []);
                $trExtId = $attrs['trExtId'] ?? '';
                $trMpiCounts = $attrs['trMpiCounts'] ?? '';
                $cardEncData = $attrs['cardEncData'] ?? '';
                $cardType = $attrs['cardType'] ?? 'visa';
                $orderId = $requestData['orderId'];
                $orderAmount = $requestData['amount'];
                $mid = GooglePayHelper::getMerchantId();

                // Calculate XID
                $xid = GooglePayHelper::calculateXID($txId, $trExtId, $trMpiCounts);

                // Extract cart ID from orderId
                $cartParts = explode('x', $orderId);
                $cartId = (int) ($cartParts[0] ?? 0);

                // Store transaction info
                GooglePayHelper::storeTransactionInfo($xid, [
                    'trId' => $txId,
                    'paymentTotal' => $orderAmount,
                    'currency' => $requestData['currency'] ?? 'EUR',
                    'payMethod' => $cardType,
                    'orderId' => $orderId,
                    'mid' => $mid,
                    'trExtId' => $trExtId,
                    'trMpiCounts' => $trMpiCounts,
                    'cardEncData' => $cardEncData,
                    'cartId' => $cartId,
                ]);

                // Persist to session AND file cache
                GooglePayHelper::persistToSession();
                GooglePayHelper::persistToCache();

                return [
                    'status' => 'processing',
                    'orderId' => $orderId,
                    'orderAmount' => $orderAmount,
                    'txId' => $txId,
                    'cardEncData' => $cardEncData,
                    'trExtId' => $trExtId,
                    'trMpiCounts' => $trMpiCounts,
                ];

            default:
                $errDesc = $apiResponse->get('Description')
                    ?? $apiResponse->getError()
                    ?? 'Payment declined';
                return [
                    'status' => 'fail',
                    'error' => $errDesc,
                ];
        }
    }

    /**
     * For direct success (no 3DS), create the PrestaShop order immediately.
     */
    private function createOrderFromDirectPayment(array $requestData, $apiResponse, string $vposStatus): string
    {
        try {
            $orderId = $requestData['orderId'];
            $cartParts = explode('x', $orderId);
            $cartId = (int) ($cartParts[0] ?? 0);

            $cart = new Cart($cartId);
            if (!Validate::isLoadedObject($cart)) {
                PrestaShopLogger::addLog("Cardlink Google Pay: Cart {$cartId} not found for direct payment", 3);
                return '';
            }

            // Check if order already exists
            $existingOrderId = Order::getIdByCartId($cartId);
            if ($existingOrderId) {
                return ''; // Already created
            }

            $customer = new Customer($cart->id_customer);
            $total_amount = $cart->getOrderTotal(true, Cart::BOTH);
            $isCaptured = ($vposStatus === 'CAPTURED');

            if ($isCaptured) {
                $orderState = (int) Configuration::get(
                    \Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                    null, null, null,
                    Configuration::get('PS_OS_PAYMENT')
                );
            } else {
                $orderState = (int) Configuration::get(
                    \Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                    null, null, null,
                    Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
                );
            }

            $module = Module::getInstanceByName(\Cardlink_Checkout\Constants::MODULE_NAME);
            $module->validateOrder(
                (int) $cart->id,
                $orderState,
                $total_amount,
                $module->displayName . ' (Google Pay)',
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );

            $newOrderId = $module->currentOrder;

            // Store Cardlink order ID
            \Cardlink_Checkout\PaymentResponseProcessor::storeCardlinkOrderId($newOrderId, $orderId);

            // Create transaction record
            $vposTxId = $apiResponse->getTransactionId() ?? '';
            $vposPayRef = $apiResponse->getPaymentRef() ?? '';

            try {
                \CardlinkPaymentTransaction::createTransaction([
                    'id_order' => $newOrderId,
                    'cardlink_order_id' => $orderId,
                    'cardlink_tx_id' => $vposTxId,
                    'cardlink_pay_status' => $vposStatus,
                    'cardlink_pay_method' => 'GOOGLEPAY',
                    'cardlink_pay_ref' => $vposPayRef,
                    'order_amount' => $total_amount,
                    'currency' => $requestData['currency'] ?? 'EUR',
                    'transaction_type' => $isCaptured ? 'sale' : 'authorize',
                ]);
            } catch (\Exception $txEx) {
                PrestaShopLogger::addLog('Cardlink Google Pay: Failed to create transaction: ' . $txEx->getMessage(), 3);
            }

            if ($isCaptured) {
                $order_details = new Order($newOrderId);
                $responseData = [
                    \Cardlink_Checkout\ApiFields::Status => $vposStatus,
                    \Cardlink_Checkout\ApiFields::TransactionId => $vposTxId,
                    \Cardlink_Checkout\ApiFields::PaymentMethod => 'GOOGLEPAY',
                    \Cardlink_Checkout\ApiFields::PaymentReferenceId => $vposPayRef,
                    \Cardlink_Checkout\ApiFields::OrderId => $orderId,
                    \Cardlink_Checkout\ApiFields::OrderAmount => $total_amount,
                    \Cardlink_Checkout\ApiFields::PaymentTotal => $apiResponse->getPaymentTotal() ?? $total_amount,
                    \Cardlink_Checkout\ApiFields::Currency => $requestData['currency'] ?? 'EUR',
                    \Cardlink_Checkout\ApiFields::Message => $apiResponse->get('Description', ''),
                ];
                \Cardlink_Checkout\PaymentResponseProcessor::handleCapturedPaymentRecord(
                    $order_details,
                    $responseData,
                    $total_amount,
                    $module->displayName . ' (Google Pay)'
                );
            } else {
                $order_details = new Order($newOrderId);
                \Cardlink_Checkout\PaymentResponseProcessor::handlePreAuthCleanup($order_details);
            }

            PrestaShopLogger::addLog("Cardlink Google Pay: Direct payment order created for cart {$cartId}", 1);

            $order_details = new Order($newOrderId);
            return Context::getContext()->link->getPageLink(
                'order-confirmation',
                true,
                null,
                [
                    'id_cart' => (int) $cart->id,
                    'id_module' => (int) $module->id,
                    'id_order' => $order_details->id,
                    'key' => $customer->secure_key,
                ]
            );
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Google Pay: Direct order creation failed: ' . $e->getMessage(), 3);
            return '';
        }
    }

    /**
     * Send a JSON response and exit.
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}
