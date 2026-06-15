<?php

use Cardlink_Checkout\ApplePayHelper;
use Cardlink_Checkout\CardlinkXmlApi;

class Cardlink_CheckoutApplepaywalletModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function display()
    {
    }

    public function postProcess()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }

        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');

        if (substr($path, -13) === 'start-session' || substr($path, -12) === 'startSession') {
            $this->handleStartSession();
            return;
        }

        $this->handleSale();
    }

    private function handleStartSession()
    {
        try {
            $body = file_get_contents('php://input');
            $requestData = json_decode($body, true);

            $this->handleStartSessionData($requestData);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay start-session error: ' . $e->getMessage(), 3);
            $this->jsonResponse(['error' => 'Internal error starting Apple Pay session'], 500);
        }
    }

    private function handleStartSessionData($requestData)
    {
        try {

            if (!$requestData || empty($requestData['validationUrl'])) {
                $this->jsonResponse(['error' => 'Invalid start-session request data'], 400);
                return;
            }

            $mid = ApplePayHelper::getMerchantId();
            $sharedSecret = ApplePayHelper::getSharedSecret();
            $businessPartner = ApplePayHelper::getBusinessPartner();
            $environment = ApplePayHelper::getTransactionEnvironment();

            if (empty($mid) || empty($sharedSecret)) {
                $this->jsonResponse(['error' => 'Apple Pay configuration is incomplete'], 500);
                return;
            }

            $orderId = 'O' . (int) (microtime(true) * 1000);
            $amount = isset($requestData['amount']) ? (string) $requestData['amount'] : '0.01';
            $currency = strtoupper((string) ($requestData['currency'] ?? 'EUR'));
            $validationUrl = (string) $requestData['validationUrl'];

            $api = new CardlinkXmlApi($mid, $sharedSecret, $businessPartner, $environment);
            $walletResponse = $api->walletSession(
                $orderId,
                $amount,
                $currency,
                $validationUrl,
                'Apple Pay Direct'
            );

            $walletData = $walletResponse->getData();

            if (empty($walletData)) {
                $errorDesc = $walletResponse->get('Description', '') ?: ($walletResponse->getError() ?? 'Wallet session failed');
                $this->jsonResponse(['error' => $errorDesc], 500);
                return;
            }

            if (is_array($walletData)) {
                $this->jsonResponse($walletData);
                return;
            }

            $decoded = json_decode((string) $walletData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->jsonResponse($decoded);
                return;
            }

            http_response_code(200);
            header('Content-Type: application/json; charset=UTF-8');
            echo (string) $walletData;
            exit;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay start-session error: ' . $e->getMessage(), 3);
            $this->jsonResponse(['error' => 'Internal error starting Apple Pay session'], 500);
        }
    }

    private function handleSale()
    {
        try {
            $body = file_get_contents('php://input');
            $requestData = json_decode($body, true);

            if (!$requestData) {
                $this->jsonResponse(['status' => 'fail', 'error' => 'Invalid request data']);
                return;
            }

            if (empty($requestData['orderId']) && !empty($requestData['validationUrl'])) {
                $this->handleStartSessionData($requestData);
                return;
            }

            if (empty($requestData['orderId'])) {
                $this->jsonResponse(['status' => 'fail', 'error' => 'Invalid request data']);
                return;
            }

            $mid = ApplePayHelper::getMerchantId();
            $sharedSecret = ApplePayHelper::getSharedSecret();
            $businessPartner = ApplePayHelper::getBusinessPartner();
            $environment = ApplePayHelper::getTransactionEnvironment();

            $api = new CardlinkXmlApi($mid, $sharedSecret, $businessPartner, $environment);

            $orderId = $requestData['orderId'];
            $parts = explode('x', $orderId);
            $cartId = $parts[0] ?? $orderId;
            $orderDesc = 'CART ' . $cartId;

            $apiResponse = $api->walletSale(
                $orderId,
                $requestData['amount'],
                $requestData['currency'] ?? 'EUR',
                $requestData['applePayResponse'] ?? '',
                'applepay',
                $orderDesc
            );

            $this->jsonResponse($this->buildJsonResponse($apiResponse, $requestData));
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Apple Pay sale error: ' . $e->getMessage(), 3);
            $this->jsonResponse(['status' => 'fail', 'error' => 'Internal error processing wallet payment']);
        }
    }

    private function buildJsonResponse($apiResponse, array $requestData): array
    {
        $status = strtoupper($apiResponse->getStatus() ?? '');
        $txId = $apiResponse->getTransactionId() ?? '';

        switch ($status) {
            case 'AUTHORIZED':
            case 'CAPTURED':
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
                $mid = ApplePayHelper::getMerchantId();

                $xid = ApplePayHelper::calculateXID($txId, $trExtId, $trMpiCounts);

                $cartParts = explode('x', $orderId);
                $cartId = (int) ($cartParts[0] ?? 0);

                ApplePayHelper::storeTransactionInfo($xid, [
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

                ApplePayHelper::persistToSession();
                ApplePayHelper::persistToCache();

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
                $errDesc = $apiResponse->get('Description') ?? $apiResponse->getError() ?? 'Payment declined';
                return ['status' => 'fail', 'error' => $errDesc];
        }
    }

    private function createOrderFromDirectPayment(array $requestData, $apiResponse, string $vposStatus): string
    {
        try {
            $orderId = $requestData['orderId'];
            $cartParts = explode('x', $orderId);
            $cartId = (int) ($cartParts[0] ?? 0);

            $cart = new Cart($cartId);
            if (!Validate::isLoadedObject($cart)) {
                PrestaShopLogger::addLog("Cardlink Apple Pay: Cart {$cartId} not found for direct payment", 3);
                return '';
            }

            $existingOrderId = Order::getIdByCartId($cartId);
            if ($existingOrderId) {
                return '';
            }

            $customer = new Customer($cart->id_customer);
            $total_amount = $cart->getOrderTotal(true, Cart::BOTH);
            $isCaptured = ($vposStatus === 'CAPTURED');

            if ($isCaptured) {
                $orderState = (int) Configuration::get(
                    \Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                    null,
                    null,
                    null,
                    Configuration::get('PS_OS_PAYMENT')
                );
            } else {
                $orderState = (int) Configuration::get(
                    \Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                    null,
                    null,
                    null,
                    Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
                );
            }

            $module = Module::getInstanceByName(\Cardlink_Checkout\Constants::MODULE_NAME);
            $module->validateOrder(
                (int) $cart->id,
                $orderState,
                $total_amount,
                $module->displayName . ' (Apple Pay)',
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );

            $newOrderId = $module->currentOrder;
            \Cardlink_Checkout\PaymentResponseProcessor::storeCardlinkOrderId($newOrderId, $orderId);

            $vposTxId = $apiResponse->getTransactionId() ?? '';
            $vposPayRef = $apiResponse->getPaymentRef() ?? '';

            try {
                \CardlinkPaymentTransaction::createTransaction([
                    'id_order' => $newOrderId,
                    'cardlink_order_id' => $orderId,
                    'cardlink_tx_id' => $vposTxId,
                    'cardlink_pay_status' => $vposStatus,
                    'cardlink_pay_method' => 'APPLEPAY',
                    'cardlink_pay_ref' => $vposPayRef,
                    'order_amount' => $total_amount,
                    'currency' => $requestData['currency'] ?? 'EUR',
                    'transaction_type' => $isCaptured ? 'sale' : 'authorize',
                ]);
            } catch (\Exception $txEx) {
                PrestaShopLogger::addLog('Cardlink Apple Pay: Failed to create transaction: ' . $txEx->getMessage(), 3);
            }

            if ($isCaptured) {
                $order_details = new Order($newOrderId);
                $responseData = [
                    \Cardlink_Checkout\ApiFields::Status => $vposStatus,
                    \Cardlink_Checkout\ApiFields::TransactionId => $vposTxId,
                    \Cardlink_Checkout\ApiFields::PaymentMethod => 'APPLEPAY',
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
                    $module->displayName . ' (Apple Pay)'
                );
            } else {
                $order_details = new Order($newOrderId);
                \Cardlink_Checkout\PaymentResponseProcessor::handlePreAuthCleanup($order_details);
            }

            PrestaShopLogger::addLog("Cardlink Apple Pay: Direct payment order created for cart {$cartId}", 1);

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
            PrestaShopLogger::addLog('Cardlink Apple Pay: Direct order creation failed: ' . $e->getMessage(), 3);
            return '';
        }
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }
}
