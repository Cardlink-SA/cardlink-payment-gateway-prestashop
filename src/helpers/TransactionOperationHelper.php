<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Transaction Operations Helper - Capture, Void, Refund operations
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

namespace Cardlink_Checkout;

use Configuration;
use Db;
use DbQuery;
use Order;
use Exception;

require_once(dirname(__FILE__) . '/../apifields.php');
require_once(dirname(__FILE__) . '/../constants.php');
require_once(dirname(__FILE__) . '/../lib/CardlinkXmlApi.php');

/**
 * Helper class for transaction operations (capture, void, refund)
 */
class TransactionOperationHelper
{
    /**
     * Configuration key for verbose refund debug logs.
     * Can be set in ps_configuration without UI changes.
     */
    const CONFIG_DEBUG_REFUNDS = 'CARDLINK_CHECKOUT_DEBUG_REFUNDS';

    /**
     * Transaction type constants for backend API calls
     */
    const BACKEND_TRTYPE_CAPTURE = '3';
    const BACKEND_TRTYPE_VOID = '4';
    const BACKEND_TRTYPE_REFUND = '5';
    const BACKEND_TRTYPE_STATUS_CHECK = '7';

    /**
     * Transaction settlement status constants
     */
    const SETTLEMENT_NOT_SETTLED = 0;
    const SETTLEMENT_IN_TRANSIT = 10;
    const SETTLEMENT_SETTLED = 20;

    /**
     * Check transaction status via gateway API
     *
     * @param string $cardlink_order_id Gateway order ID
     * @param string $merchant_id Merchant ID
     * @param string $shared_secret Shared secret
     * @param string $business_partner Business partner (cardlink, nexi, worldline)
     * @param string $environment Transaction environment
     *
     * @return array|null Response array or null on failure
     */
    public static function checkTransactionStatus(
        $cardlink_order_id,
        $merchant_id,
        $shared_secret,
        $business_partner,
        $environment
    ) {
        $api = self::createApiClient($merchant_id, $shared_secret, $business_partner, $environment);
        $response = $api->status($cardlink_order_id);

        return self::convertApiResponseToArray($response);
    }

    /**
     * Capture a preauthorized transaction
     *
     * @param int $id_order PrestaShop order ID
     * @param string $cardlink_order_id Gateway order ID
     * @param float $amount Amount to capture
     * @param string $currency Currency code
     * @param string $merchant_id Merchant ID
     * @param string $shared_secret Shared secret
     * @param string $business_partner Business partner
     * @param string $environment Transaction environment
     *
     * @return array Response from gateway
     *
     * @throws Exception
     */
    public static function captureTransaction(
        $id_order,
        $cardlink_order_id,
        $amount,
        $currency,
        $merchant_id,
        $shared_secret,
        $business_partner,
        $environment
    ) {
        $api = self::createApiClient($merchant_id, $shared_secret, $business_partner, $environment);
        $response = $api->capture($cardlink_order_id, $amount, $currency);

        if (!$response->isSuccess()) {
            throw new Exception('Capture failed: ' . $response->getError());
        }

        // Store capture transaction record
        \CardlinkPaymentTransaction::createTransaction([
            'id_order' => $id_order,
            'cardlink_order_id' => $cardlink_order_id,
            'cardlink_tx_id' => $response->getTransactionId(),
            'cardlink_pay_status' => Constants::TRANSACTION_STATUS_CAPTURED,
            'cardlink_pay_method' => $response->getAttribute('PayMethod'),
            'cardlink_pay_ref' => $response->getPaymentRef(),
            'order_amount' => $amount,
            'currency' => $currency,
            'transaction_type' => 'capture'
        ]);

        return self::convertApiResponseToArray($response);
    }

    /**
     * Void/Cancel a transaction
     *
     * @param int $id_order PrestaShop order ID
     * @param string $cardlink_order_id Gateway order ID
     * @param float $amount Amount to void
     * @param string $currency Currency code
     * @param string $merchant_id Merchant ID
     * @param string $shared_secret Shared secret
     * @param string $business_partner Business partner
     * @param string $environment Transaction environment
     *
     * @return array Response from gateway
     *
     * @throws Exception
     */
    public static function voidTransaction(
        $id_order,
        $cardlink_order_id,
        $amount,
        $currency,
        $merchant_id,
        $shared_secret,
        $business_partner,
        $environment
    ) {
        $api = self::createApiClient($merchant_id, $shared_secret, $business_partner, $environment);
        $response = $api->void($cardlink_order_id, $amount, $currency);

        if (!$response->isSuccess()) {
            throw new Exception('Void failed: ' . $response->getError());
        }

        // Store void transaction record
        \CardlinkPaymentTransaction::createTransaction([
            'id_order' => $id_order,
            'cardlink_order_id' => $cardlink_order_id,
            'cardlink_tx_id' => $response->getTransactionId(),
            'cardlink_pay_status' => Constants::TRANSACTION_STATUS_CANCELED,
            'cardlink_pay_method' => $response->getAttribute('PayMethod'),
            'cardlink_pay_ref' => $response->getPaymentRef(),
            'order_amount' => $amount,
            'currency' => $currency,
            'transaction_type' => 'void'
        ]);

        return self::convertApiResponseToArray($response);
    }

    /**
     * Refund a captured transaction with smart fallback to void
     *
     * @param int $id_order PrestaShop order ID
     * @param string $cardlink_order_id Gateway order ID
     * @param float $amount Amount to refund
     * @param float $original_amount Original transaction amount
     * @param string $currency Currency code
     * @param string $merchant_id Merchant ID
     * @param string $shared_secret Shared secret
     * @param string $business_partner Business partner
     * @param string $environment Transaction environment
     *
     * @return array Response from gateway
     *
     * @throws Exception
     */
    public static function refundTransaction(
        $id_order,
        $cardlink_order_id,
        $amount,
        $original_amount,
        $currency,
        $merchant_id,
        $shared_secret,
        $business_partner,
        $environment
    ) {
        $isFullRefund = abs($amount - $original_amount) < 0.01;
        $debugEnabled = self::isRefundDebugEnabled();

        self::logRefundDebug($id_order, 'Refund flow started', 1, [
            'cardlink_order_id' => $cardlink_order_id,
            'amount' => (float) $amount,
            'original_amount' => (float) $original_amount,
            'currency' => $currency,
            'is_full_refund' => $isFullRefund,
            'business_partner' => $business_partner,
            'environment' => $environment,
            'merchant_id_masked' => self::maskMerchantId($merchant_id),
            'debug_enabled' => $debugEnabled,
        ]);

        $api = self::createApiClient($merchant_id, $shared_secret, $business_partner, $environment);

        if ($debugEnabled) {
            $api->setDebug(true, function ($message) use ($id_order, $cardlink_order_id) {
                self::logRefundDebug($id_order, 'CardlinkXmlApi [' . $cardlink_order_id . '] ' . $message, 1);
            });
        }

        try {
            self::logRefundDebug($id_order, 'Checking gateway status before refund', 1, [
                'cardlink_order_id' => $cardlink_order_id,
            ]);

            $statusResponse = $api->status($cardlink_order_id);
            $statusData = self::convertApiResponseToArray($statusResponse);

            self::logRefundDebug($id_order, 'Gateway status response received', 1, [
                'is_success' => $statusResponse->isSuccess(),
                'status' => $statusData['Status'] ?? ($statusData['status'] ?? null),
                'settlement_status' => $statusData['SettlementStatus'] ?? ($statusData['settlementStatus'] ?? null),
                'tx_id' => $statusData['TxId'] ?? ($statusData['txId'] ?? null),
                'pay_ref' => $statusData['PaymentRef'] ?? ($statusData['paymentRef'] ?? null),
            ]);

            if (!$statusResponse->isSuccess()) {
                self::logRefundDebug($id_order, 'Gateway status call failed — assuming settled, proceeding with refund', 2, [
                    'status_error' => $statusResponse->getError(),
                    'api_last_response' => self::buildApiTransportSnapshot($api),
                ]);
                // Fall through with assumed-settled status. If the transaction is not yet settled,
                // the gateway will reject the refund with an O1 error and the void fallback below handles it.
                $settlementStatus = self::SETTLEMENT_SETTLED;
            } else {
                $settlementStatus = $statusResponse->getSettlementStatus() ?? self::SETTLEMENT_SETTLED;
            }

            if ($settlementStatus === self::SETTLEMENT_NOT_SETTLED) {
                if ($isFullRefund) {
                    self::logRefundDebug($id_order, 'Transaction not settled, switching full refund to void', 2, [
                        'cardlink_order_id' => $cardlink_order_id,
                        'amount' => (float) $amount,
                    ]);

                    $voidResult = self::voidTransaction(
                        $id_order,
                        $cardlink_order_id,
                        $amount,
                        $currency,
                        $merchant_id,
                        $shared_secret,
                        $business_partner,
                        $environment
                    );

                    $voidResult['cardlink_operation'] = 'void';
                    $voidResult['cardlink_operation_reason'] = 'NOT_SETTLED';
                    return $voidResult;
                }

                self::logRefundDebug($id_order, 'Partial refund blocked because transaction is not settled', 2, [
                    'cardlink_order_id' => $cardlink_order_id,
                    'amount' => (float) $amount,
                ]);

                throw new Exception(
                    'Partial refund is not possible on unsettled transactions. ' .
                        'You can only void the full amount.'
                );
            }

            if ($settlementStatus === self::SETTLEMENT_IN_TRANSIT) {
                if ($isFullRefund) {
                    self::logRefundDebug($id_order, 'Transaction in settlement transit, trying void first', 2, [
                        'cardlink_order_id' => $cardlink_order_id,
                        'amount' => (float) $amount,
                    ]);

                    try {
                        $voidResult = self::voidTransaction(
                            $id_order,
                            $cardlink_order_id,
                            $amount,
                            $currency,
                            $merchant_id,
                            $shared_secret,
                            $business_partner,
                            $environment
                        );

                        $voidResult['cardlink_operation'] = 'void';
                        $voidResult['cardlink_operation_reason'] = 'IN_TRANSIT';
                        return $voidResult;
                    } catch (Exception $e) {
                        self::logRefundDebug($id_order, 'Void fallback failed in IN_TRANSIT state, attempting refund', 2, [
                            'void_error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    self::logRefundDebug($id_order, 'Partial refund blocked because transaction is in settlement transit', 2, [
                        'cardlink_order_id' => $cardlink_order_id,
                        'amount' => (float) $amount,
                    ]);

                    throw new Exception(
                        'Transaction is in settlement transit. Please wait until settlement is complete ' .
                            '(usually the next business day) and try again.'
                    );
                }
            }

            self::logRefundDebug($id_order, 'Sending refund request to gateway', 1, [
                'cardlink_order_id' => $cardlink_order_id,
                'amount' => (float) $amount,
                'currency' => $currency,
            ]);

            $refundResponse = $api->refund($cardlink_order_id, $amount, $currency);

            if (!$refundResponse->isSuccess()) {
                $errorCode = $refundResponse->get('ErrorCode') ?: $refundResponse->get('errorCode');

                self::logRefundDebug($id_order, 'Gateway refund call failed', 3, [
                    'error_code' => $errorCode,
                    'error_message' => $refundResponse->getError(),
                    'api_last_response' => self::buildApiTransportSnapshot($api),
                ]);

                if ($errorCode === 'O1' && $isFullRefund) {
                    self::logRefundDebug($id_order, 'Refund not allowed (O1), trying full void fallback', 2, [
                        'cardlink_order_id' => $cardlink_order_id,
                        'amount' => (float) $amount,
                    ]);

                    try {
                        $voidResult = self::voidTransaction(
                            $id_order,
                            $cardlink_order_id,
                            $amount,
                            $currency,
                            $merchant_id,
                            $shared_secret,
                            $business_partner,
                            $environment
                        );

                        $voidResult['cardlink_operation'] = 'void';
                        $voidResult['cardlink_operation_reason'] = 'REFUND_O1';
                        return $voidResult;
                    } catch (Exception $voidException) {
                        self::logRefundDebug($id_order, 'Void fallback after O1 refund error failed', 3, [
                            'void_error' => $voidException->getMessage(),
                        ]);

                        throw new Exception(
                            'Both refund and void operations failed. ' .
                                'Refund error: ' . $refundResponse->getError() . '. ' .
                                'Void error: ' . $voidException->getMessage()
                        );
                    }
                }

                if (!$isFullRefund && $errorCode === 'O1') {
                    throw new Exception(
                        'Original transaction is not refundable and partial void is not supported. ' .
                            'Please wait for settlement or create a credit memo for the full amount.'
                    );
                }

                throw new Exception('Refund failed: ' . $refundResponse->getError());
            }

            \CardlinkPaymentTransaction::createTransaction([
                'id_order' => $id_order,
                'cardlink_order_id' => $cardlink_order_id,
                'cardlink_tx_id' => $refundResponse->getTransactionId(),
                'cardlink_pay_status' => 'REFUNDED',
                'cardlink_pay_method' => $refundResponse->getAttribute('PayMethod'),
                'cardlink_pay_ref' => $refundResponse->getPaymentRef(),
                'order_amount' => $amount,
                'currency' => $currency,
                'transaction_type' => 'refund'
            ]);

            self::logRefundDebug($id_order, 'Refund flow completed successfully', 1, [
                'cardlink_order_id' => $cardlink_order_id,
                'refund_tx_id' => $refundResponse->getTransactionId(),
                'pay_ref' => $refundResponse->getPaymentRef(),
                'amount' => (float) $amount,
                'currency' => $currency,
            ]);

            $refundResult = self::convertApiResponseToArray($refundResponse);
            $refundResult['cardlink_operation'] = 'refund';
            return $refundResult;
        } catch (Exception $e) {
            self::logRefundDebug($id_order, 'Refund flow failed', 3, [
                'cardlink_order_id' => $cardlink_order_id,
                'error' => $e->getMessage(),
                'amount' => (float) $amount,
                'currency' => $currency,
                'is_full_refund' => $isFullRefund,
            ]);
            throw $e;
        }
    }

    /**
     * Determine whether verbose refund debug should be enabled.
     * Enabled automatically in development mode or by configuration flag.
     *
     * @return bool
     */
    private static function isRefundDebugEnabled()
    {
        $configEnabled = false;
        if (class_exists('Configuration')) {
            $configEnabled = (bool) call_user_func(['Configuration', 'get'], self::CONFIG_DEBUG_REFUNDS);
        }
        $isDevMode = defined('_PS_MODE_DEV_') && (bool) constant('_PS_MODE_DEV_');

        return $configEnabled || $isDevMode;
    }

    /**
     * Centralized refund debug logger.
     *
     * @param int $id_order
     * @param string $message
     * @param int $severity 1=info, 2=warning, 3=error
     * @param array $context
     *
     * @return void
     */
    private static function logRefundDebug($id_order, $message, $severity = 1, array $context = [])
    {
        // Trim operational noise in normal mode: keep verbose refund traces only
        // when refund debug is enabled. Always keep error-level logs.
        if ((int) $severity < 3 && !self::isRefundDebugEnabled()) {
            return;
        }

        if (!empty($context)) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $message .= ' | context=' . $encoded;
            }
        }

        if (class_exists('PrestaShopLogger')) {
            call_user_func(
                ['PrestaShopLogger', 'addLog'],
                'Cardlink Refund Debug [Order #' . (int) $id_order . ']: ' . $message,
                (int) $severity
            );
        }
    }

    /**
     * Build a compact transport snapshot from the last API interaction.
     *
     * @param CardlinkXmlApi $api
     * @return array
     */
    private static function buildApiTransportSnapshot(CardlinkXmlApi $api)
    {
        $request = $api->getLastRequest();
        $response = $api->getLastResponse();

        return [
            'request_url' => isset($request['url']) ? $request['url'] : null,
            'expected_response_type' => isset($request['expectedResponseType']) ? $request['expectedResponseType'] : null,
            'http_code' => isset($response['http_code']) ? $response['http_code'] : null,
            'curl_errno' => isset($response['curl_errno']) ? $response['curl_errno'] : null,
            'curl_error' => isset($response['curl_error']) ? $response['curl_error'] : null,
        ];
    }

    /**
     * Mask merchant id for safe logging.
     *
     * @param string $merchantId
     * @return string
     */
    private static function maskMerchantId($merchantId)
    {
        if (empty($merchantId)) {
            return '';
        }

        $prefix = substr($merchantId, 0, 4);
        return $prefix . str_repeat('*', max(strlen($merchantId) - 4, 0));
    }


    /**
     * Create a CardlinkXmlApi client instance
     *
     * @param string $merchant_id Merchant ID
     * @param string $shared_secret Shared secret
     * @param string $business_partner Business partner
     * @param string $environment Transaction environment
     *
     * @return CardlinkXmlApi
     */
    private static function createApiClient($merchant_id, $shared_secret, $business_partner, $environment)
    {
        return new CardlinkXmlApi(
            $merchant_id,
            $shared_secret,
            $business_partner,
            $environment
        );
    }

    /**
     * Convert CardlinkXmlResponse to array for backward compatibility
     *
     * @param CardlinkXmlResponse $response
     *
     * @return array
     */
    private static function convertApiResponseToArray($response)
    {
        $data = $response->getData();
        $data['isSuccess'] = $response->isSuccess();
        return $data;
    }
}
