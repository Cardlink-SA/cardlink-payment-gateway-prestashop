<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Response Processor Helper
 * Shared logic for both user-facing and webhook payment processing
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

namespace Cardlink_Checkout;

use Configuration;
use OrderHistory;
use Order;
use Cart;
use Exception;

/**
 * Helper class to process payment responses uniformly
 * Can be used by both response.php controller and background confirmation webhook
 */
class PaymentResponseProcessor
{
    /**
     * Process payment response and update order accordingly
     *
     * @param array $responseData Payment gateway response data
     * @param int $id_order PrestaShop order ID
     * @param Cart $cart Cart object
     * @param string $paymentMethod Payment method (card, IRIS)
     *
     * @return array Result array with status and message
     *
     * @throws Exception
     */
    public static function processPaymentResponse($responseData, $id_order, $cart, $paymentMethod = 'card')
    {
        $paymentStatus = $responseData[ApiFields::Status] ?? '';
        $cardlinkOrderId = $responseData[ApiFields::OrderId] ?? '';
        $txId = $responseData[ApiFields::TransactionId] ?? null;
        $paymentRef = $responseData[ApiFields::PaymentReferenceId] ?? null;
        $orderAmount = (float) ($responseData[ApiFields::OrderAmount] ?? 0);
        $currency = $responseData[ApiFields::Currency] ?? 'EUR';

        // Store or update transaction record
        $transaction = \CardlinkPaymentTransaction::getByCardlinkOrderId($cardlinkOrderId);

        if (!$transaction) {
            $transaction = \CardlinkPaymentTransaction::createTransaction([
                'id_order' => $id_order,
                'cardlink_order_id' => $cardlinkOrderId,
                'cardlink_tx_id' => $txId,
                'cardlink_pay_status' => $paymentStatus,
                'cardlink_pay_method' => $paymentMethod,
                'cardlink_pay_ref' => $paymentRef,
                'order_amount' => $orderAmount,
                'currency' => $currency,
                'transaction_type' => self::getTransactionTypeFromStatus($paymentStatus)
            ]);
        } else {
            $transaction->cardlink_tx_id = $txId ?? $transaction->cardlink_tx_id;
            $transaction->cardlink_pay_status = $paymentStatus;
            $transaction->cardlink_pay_method = $paymentMethod;
            $transaction->cardlink_pay_ref = $paymentRef ?? $transaction->cardlink_pay_ref;
            $transaction->save();
        }

        // Determine new order state based on payment status
        $newOrderState = self::getOrderStateForPaymentStatus($paymentStatus);

        // Update order status if different from current
        if ($id_order && $newOrderState !== null) {
            $order = new Order($id_order);
            if (\Validate::isLoadedObject($order)) {
                if ($order->current_state !== $newOrderState) {
                    $history = new OrderHistory();
                    $history->id_order = $id_order;
                    $history->changeIdOrderState($newOrderState, $id_order);
                    $history->save();
                }
            }
        }

        return [
            'success' => true,
            'status' => $paymentStatus,
            'order_id' => $id_order,
            'transaction_id' => $txId,
            'new_state' => $newOrderState
        ];
    }

    /**
     * Get transaction type from payment status
     *
     * @param string $paymentStatus Payment status from gateway
     *
     * @return string Transaction type
     */
    private static function getTransactionTypeFromStatus($paymentStatus)
    {
        switch ($paymentStatus) {
            case Constants::TRANSACTION_STATUS_AUTHORIZED:
                return 'authorize';
            case Constants::TRANSACTION_STATUS_CAPTURED:
                return 'sale';
            case Constants::TRANSACTION_STATUS_CANCELED:
                return 'cancel';
            case Constants::TRANSACTION_STATUS_REFUSED:
            case Constants::TRANSACTION_STATUS_ERROR:
                return 'error';
            default:
                return 'unknown';
        }
    }

    /**
     * Get PrestaShop order state ID for a given payment status
     *
     * @param string $paymentStatus Payment status from gateway
     *
     * @return int|null Order state ID or null
     */
    private static function getOrderStateForPaymentStatus($paymentStatus)
    {
        switch ($paymentStatus) {
            case Constants::TRANSACTION_STATUS_AUTHORIZED:
                return (int) Configuration::get(
                    Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                    null,
                    null,
                    null,
                    Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
                );

            case Constants::TRANSACTION_STATUS_CAPTURED:
                return (int) Configuration::get(
                    Constants::CONFIG_ORDER_STATUS_CAPTURED,
                    null,
                    null,
                    null,
                    Configuration::get('PS_OS_PAYMENT')
                );

            case Constants::TRANSACTION_STATUS_CANCELED:
                return (int) Configuration::get('PS_OS_CANCELED');

            case Constants::TRANSACTION_STATUS_REFUSED:
            case Constants::TRANSACTION_STATUS_ERROR:
                return (int) Configuration::get('PS_OS_ERROR');

            default:
                return null;
        }
    }

    /**
     * Handle payment error/cancellation
     *
     * @param array $responseData Response data
     * @param int $id_order Order ID
     * @param string $errorType 'canceled' or 'error'
     *
     * @return array Result array
     */
    public static function handlePaymentError($responseData, $id_order, $errorType = 'error')
    {
        $cardlinkOrderId = $responseData[ApiFields::OrderId] ?? '';
        $errorMessage = $responseData[ApiFields::Message] ?? 'Unknown error';

        // Store transaction record with error status
        $transaction = \CardlinkPaymentTransaction::getByCardlinkOrderId($cardlinkOrderId);

        if (!$transaction) {
            $status = ($errorType === 'canceled') ?
                Constants::TRANSACTION_STATUS_CANCELED :
                Constants::TRANSACTION_STATUS_ERROR;

            \CardlinkPaymentTransaction::createTransaction([
                'id_order' => $id_order,
                'cardlink_order_id' => $cardlinkOrderId,
                'cardlink_pay_status' => $status,
                'transaction_type' => $errorType
            ]);
        }

        // Update order status to error or canceled
        if ($id_order) {
            $order = new Order($id_order);
            if (\Validate::isLoadedObject($order)) {
                $newOrderState = ($errorType === 'canceled') ?
                    (int) Configuration::get('PS_OS_CANCELED') :
                    (int) Configuration::get('PS_OS_ERROR');

                if ($order->current_state !== $newOrderState) {
                    $history = new OrderHistory();
                    $history->id_order = $id_order;
                    $history->changeIdOrderState($newOrderState, $id_order);
                    $history->save();
                }
            }
        }

        return [
            'success' => false,
            'status' => $errorType,
            'message' => $errorMessage,
            'order_id' => $id_order
        ];
    }

    /**
     * Check if response indicates a successful payment
     *
     * @param array $responseData Response data
     *
     * @return bool
     */
    public static function isSuccessfulPayment($responseData)
    {
        $status = $responseData[ApiFields::Status] ?? '';

        return in_array($status, [
            Constants::TRANSACTION_STATUS_AUTHORIZED,
            Constants::TRANSACTION_STATUS_CAPTURED
        ]);
    }

    /**
     * Check if response indicates payment was canceled by customer
     *
     * @param array $responseData Response data
     *
     * @return bool
     */
    public static function isPaymentCanceled($responseData)
    {
        return ($responseData[ApiFields::Status] ?? '') === Constants::TRANSACTION_STATUS_CANCELED;
    }

    /**
     * Check if response indicates payment was refused/errored
     *
     * @param array $responseData Response data
     *
     * @return bool
     */
    public static function isPaymentRefused($responseData)
    {
        $status = $responseData[ApiFields::Status] ?? '';

        return in_array($status, [
            Constants::TRANSACTION_STATUS_REFUSED,
            Constants::TRANSACTION_STATUS_ERROR
        ]);
    }

    /**
     * Handle payment record creation/update for captured (sale) transactions
     * Creates payment record with card details and links to invoice
     *
     * @param Order $order PrestaShop Order object
     * @param array $responseData Payment gateway response data
     * @param float $amount Payment amount
     * @param string $paymentMethodName Display name for payment method
     *
     * @return bool Success status
     */
    public static function handleCapturedPaymentRecord($order, $responseData, $amount, $paymentMethodName = 'Cardlink Checkout')
    {
        try {
            $cardNumber = 'n/a';
            $cardExpiration = 'n/a';
            
            if (array_key_exists(ApiFields::ExtTokenPanEnd, $responseData)) {
                $cardNumber = str_pad($responseData[ApiFields::ExtTokenPanEnd], 16, 'x', STR_PAD_LEFT);
                
                if (array_key_exists(ApiFields::ExtTokenExpiration, $responseData)) {
                    $date = \DateTime::createFromFormat('Ymd', $responseData[ApiFields::ExtTokenExpiration]);
                    if ($date !== false) {
                        $cardExpiration = $date->format('m/Y');
                    }
                }
            }

            // First, delete any auto-created payment records (those without transaction_id or with empty transaction_id)
            // This prevents duplicates when validateOrder creates one automatically
            \Db::getInstance()->delete(
                'order_payment',
                'order_reference = \'' . pSQL($order->reference) . '\' AND (transaction_id IS NULL OR transaction_id = \'\')'
            );

            // Check if a payment record with our transaction_id already exists
            $transactionId = $responseData[ApiFields::TransactionId] ?? '';
            $payMethod = $responseData[ApiFields::PaymentMethod] ?? $responseData['payMethod'] ?? 'card';
            
            $sql = 'SELECT id_order_payment FROM `' . _DB_PREFIX_ . 'order_payment` 
                    WHERE `order_reference` = \'' . pSQL($order->reference) . '\' 
                    AND `transaction_id` = \'' . pSQL($transactionId) . '\' LIMIT 1';
            $existingPayment = \Db::getInstance()->getValue($sql);
            
            if ($existingPayment) {
                // Update existing payment record with card details
                \Db::getInstance()->update(
                    'order_payment',
                    [
                        'card_number' => pSQL($cardNumber),
                        'card_expiration' => pSQL($cardExpiration),
                        'card_brand' => pSQL($payMethod)
                    ],
                    'id_order_payment = ' . (int)$existingPayment
                );
            } else {
                // Create new payment record with full details
                $orderPayment = new \OrderPayment();
                $orderPayment->order_reference = $order->reference;
                $orderPayment->id_currency = (int)$order->id_currency;
                $orderPayment->amount = $amount;
                $orderPayment->payment_method = $paymentMethodName;
                $orderPayment->transaction_id = $transactionId;
                $orderPayment->card_number = $cardNumber;
                $orderPayment->card_expiration = $cardExpiration;
                $orderPayment->card_brand = $payMethod;
                $orderPayment->date_add = date('Y-m-d H:i:s');
                $orderPayment->save();

                if ($orderPayment->id && $transactionId !== '') {
                    // Deduplicate: if a concurrent request also inserted a record for this
                    // transaction, keep only the one with the lowest id and discard the rest.
                    $minId = (int)\Db::getInstance()->getValue(
                        'SELECT MIN(id_order_payment) FROM `' . _DB_PREFIX_ . 'order_payment`
                         WHERE `order_reference` = \'' . pSQL($order->reference) . '\'
                         AND `transaction_id` = \'' . pSQL($transactionId) . '\''
                    );

                    if ($minId && $minId !== (int)$orderPayment->id) {
                        // A concurrent insert beat us; remove our duplicate.
                        $orderPayment->delete();
                        $orderPayment->id = $minId;
                    } elseif ($minId) {
                        // We were first; remove any later duplicates.
                        \Db::getInstance()->execute(
                            'DELETE FROM `' . _DB_PREFIX_ . 'order_payment`
                             WHERE `order_reference` = \'' . pSQL($order->reference) . '\'
                             AND `transaction_id` = \'' . pSQL($transactionId) . '\'
                             AND `id_order_payment` > ' . $minId
                        );
                    }
                }

                // Link payment to invoice if one exists
                if ($orderPayment->id) {
                    $invoices = $order->getInvoicesCollection();
                    if ($invoices && $invoices->count() > 0) {
                        foreach ($invoices as $invoice) {
                            // Check if link already exists
                            $exists = \Db::getInstance()->getValue(
                                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_invoice_payment`
                                 WHERE id_order_invoice = ' . (int)$invoice->id . '
                                 AND id_order_payment = ' . (int)$orderPayment->id
                            );
                            if (!$exists) {
                                \Db::getInstance()->insert('order_invoice_payment', [
                                    'id_order_invoice' => (int)$invoice->id,
                                    'id_order_payment' => (int)$orderPayment->id,
                                    'id_order' => (int)$order->id
                                ]);
                            }
                        }
                    }
                }
            }
            
            return true;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('Cardlink: Failed to handle captured payment record: ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Handle pre-authorized transaction cleanup
     * Deletes auto-created payment records and invoices for pre-auth orders
     *
     * @param Order $order PrestaShop Order object
     *
     * @return bool Success status
     */
    public static function handlePreAuthCleanup($order)
    {
        try {
            // Delete all auto-created payment records
            // The payment record will be created when a capture is performed
            \Db::getInstance()->delete(
                'order_payment',
                'order_reference = \'' . pSQL($order->reference) . '\''
            );

            // Delete any auto-created invoices for pre-authorized orders
            // Invoice will be created when capture is performed with the captured amount
            $invoices = $order->getInvoicesCollection();
            if ($invoices && $invoices->count() > 0) {
                foreach ($invoices as $invoice) {
                    // Delete invoice-payment links first
                    \Db::getInstance()->delete(
                        'order_invoice_payment',
                        'id_order_invoice = ' . (int)$invoice->id
                    );
                    // Delete the invoice
                    $invoice->delete();
                }
            }
            
            return true;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('Cardlink: Failed to handle pre-auth cleanup: ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Store Cardlink order ID in the orders table
     *
     * @param int $orderId PrestaShop order ID
     * @param string $cardlinkOrderId Cardlink gateway order ID
     *
     * @return bool Success status
     */
    public static function storeCardlinkOrderId($orderId, $cardlinkOrderId)
    {
        try {
            return \Db::getInstance()->update(
                'orders',
                ['cardlink_order_id' => pSQL($cardlinkOrderId)],
                'id_order = ' . (int)$orderId
            );
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('Cardlink: Failed to store cardlink_order_id: ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Full order processing after successful payment
     * Handles transaction record, payment record, and invoice management
     *
     * @param int $orderId PrestaShop order ID
     * @param Order $order PrestaShop Order object
     * @param array $responseData Payment gateway response data
     * @param float $amount Order amount
     * @param string $paymentMethodName Display name for payment method
     *
     * @return bool Success status
     */
    public static function processSuccessfulPayment($orderId, $order, $responseData, $amount, $paymentMethodName = 'Cardlink Checkout')
    {
        $cardlinkOrderId = $responseData[ApiFields::OrderId] ?? '';
        $paymentStatus = $responseData[ApiFields::Status] ?? '';
        $payMethod = $responseData[ApiFields::PaymentMethod] ?? $responseData['payMethod'] ?? 'card';
        $isCaptured = ($paymentStatus === Constants::TRANSACTION_STATUS_CAPTURED);

        // Store Cardlink order ID
        self::storeCardlinkOrderId($orderId, $cardlinkOrderId);

        // Create/update transaction record
        $transaction = \CardlinkPaymentTransaction::getByCardlinkOrderId($cardlinkOrderId);
        
        if (!$transaction) {
            try {
                \CardlinkPaymentTransaction::createTransaction([
                    'id_order' => $orderId,
                    'cardlink_order_id' => $cardlinkOrderId,
                    'cardlink_tx_id' => $responseData[ApiFields::TransactionId] ?? null,
                    'cardlink_pay_status' => $paymentStatus,
                    'cardlink_pay_method' => $payMethod,
                    'cardlink_pay_ref' => $responseData[ApiFields::PaymentReferenceId] ?? null,
                    'order_amount' => $amount,
                    'currency' => $responseData[ApiFields::Currency] ?? \Currency::getIsoCodeById((int)$order->id_currency),
                    'transaction_type' => $isCaptured ? 'sale' : 'authorize'
                ]);
            } catch (\Exception $txEx) {
                \PrestaShopLogger::addLog('Cardlink: Failed to create transaction record: ' . $txEx->getMessage(), 3);
            }
        } else {
            // Update existing transaction
            $transaction->cardlink_tx_id = $responseData[ApiFields::TransactionId] ?? $transaction->cardlink_tx_id;
            $transaction->cardlink_pay_status = $paymentStatus;
            $transaction->cardlink_pay_method = $payMethod;
            $transaction->cardlink_pay_ref = $responseData[ApiFields::PaymentReferenceId] ?? $transaction->cardlink_pay_ref;
            $transaction->save();
        }

        // Handle payment record based on transaction type
        if ($isCaptured) {
            self::handleCapturedPaymentRecord($order, $responseData, $amount, $paymentMethodName);
        } else {
            self::handlePreAuthCleanup($order);
        }

        return true;
    }
}
