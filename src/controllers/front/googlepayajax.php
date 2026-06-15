<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Google Pay AJAX Controller
 * Handles all Google Pay AJAX endpoints:
 *   - init:      Returns script auth hash + MID for loading googlepaydirect.js
 *   - wallet:    Executes wallet SaleRequest via CardlinkXmlApi
 *   - createxid: Calculates 3DS XID from VPOS transaction fields
 *   - signdata:  Signs MPI form data for 3DS authentication
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use Cardlink_Checkout\Constants;
use Cardlink_Checkout\GooglePayHelper;
use Cardlink_Checkout\CardlinkXmlApi;

class Cardlink_CheckoutGooglepayajaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Do not render any template — all responses are JSON or plain text.
     */
    public function display()
    {
    }

    /**
     * Main entry point — routes to the appropriate handler based on the 'gpay_action' parameter.
     */
    public function postProcess()
    {
        $action = Tools::getValue('gpay_action', '');

        switch ($action) {
            case 'init':
                $this->handleInit();
                break;

            case 'wallet':
                $this->handleWallet();
                break;

            case 'createxid':
                $this->handleCreateXid();
                break;

            case 'signdata':
                $this->handleSignData();
                break;

            default:
                $this->jsonResponse(['error' => 'Invalid action'], 400);
                break;
        }
    }

    // =====================================================================
    //  INIT — Returns Google Pay script authentication data
    // =====================================================================

    private function handleInit()
    {
        try {
            $initData = GooglePayHelper::getScriptInitData();

            $this->jsonResponse([
                'success' => true,
                'mid' => $initData['mid'],
                'queryString' => $initData['queryString'],
                'vposVersion' => $initData['vposVersion'],
            ]);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Google Pay init error: ' . $e->getMessage(), 3);
            $this->jsonResponse([
                'success' => false,
                'message' => 'Failed to initialize Google Pay',
            ], 500);
        }
    }

    // =====================================================================
    //  WALLET — Processes the wallet sale request
    // =====================================================================

    /**
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
     * Returns one of:
     *   {"status":"success", "txId":"..."}
     *   {"status":"processing", "orderId":"...", "orderAmount":"...", "txId":"...",
     *    "cardEncData":"...", "trExtId":"...", "trMpiCounts":"..."}
     *   {"status":"fail", "error":"..."}
     */
    private function handleWallet()
    {
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

            // Derive order description from orderId
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

            $jsonResponse = $this->buildWalletJsonResponse($apiResponse, $requestData);
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
     * Convert a CardlinkXmlResponse to a JSON-friendly array.
     */
    private function buildWalletJsonResponse($apiResponse, array $requestData): array
    {
        $status = strtoupper($apiResponse->getStatus() ?? '');
        $txId = $apiResponse->getTransactionId() ?? '';

        switch ($status) {
            case 'AUTHORIZED':
            case 'CAPTURED':
                return ['status' => 'success', 'txId' => $txId];

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

                // Extract cart ID from orderId (format: {cartId}x{timestamp}x{suffix})
                $parts = explode('x', $orderId);
                $cartId = (int) ($parts[0] ?? 0);

                // Store transaction info for the 3DS callback
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

                // Persist to session AND file cache (cross-domain MPI redirect loses session)
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

    // =====================================================================
    //  CREATE XID — Calculates 3DS XID
    // =====================================================================

    private function handleCreateXid()
    {
        try {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);

            $trId = $data['trId'] ?? '';
            $trExtId = $data['trExtId'] ?? '';
            $trMpiCounts = $data['trMpiCounts'] ?? '';

            if (empty($trId)) {
                $this->textResponse('Missing trId', 400);
                return;
            }

            $xid = GooglePayHelper::calculateXID($trId, $trExtId, $trMpiCounts);
            $this->textResponse($xid);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Google Pay createXid error: ' . $e->getMessage(), 3);
            $this->textResponse('Error calculating XID', 500);
        }
    }

    // =====================================================================
    //  SIGN DATA — Signs 3DS MPI form data
    // =====================================================================

    private function handleSignData()
    {
        try {
            $body = file_get_contents('php://input');
            $data = json_decode($body, true);

            if (!$data) {
                $this->jsonResponse(['error' => 'Invalid JSON'], 400);
                return;
            }

            $result = GooglePayHelper::signMpiData($data);

            if (strpos($result['signature'], 'Error') === 0) {
                PrestaShopLogger::addLog('Cardlink Google Pay sign error: ' . $result['signature'], 3);
                $this->jsonResponse(['error' => $result['signature']], 500);
                return;
            }

            $this->jsonResponse($result);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Cardlink Google Pay signData error: ' . $e->getMessage(), 3);
            $this->jsonResponse(['error' => 'Internal error signing data'], 500);
        }
    }

    // =====================================================================
    //  RESPONSE HELPERS
    // =====================================================================

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data);
        exit;
    }

    private function textResponse(string $text, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $text;
        exit;
    }
}
