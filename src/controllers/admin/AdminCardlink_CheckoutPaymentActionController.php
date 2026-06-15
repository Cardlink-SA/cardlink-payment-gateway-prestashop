<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Admin Order Payment Actions Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class AdminCardlink_CheckoutPaymentActionController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->bootstrap = true;
    }

    public function postProcess()
    {
        if (!Tools::isSubmit('cardlink_payment_action')) {
            return;
        }

        $id_order = (int) Tools::getValue('id_order');
        $action = Tools::getValue('action');
        $amount = (float) Tools::getValue('amount', 0);

        if (!$id_order || !$action) {
            $this->redirectToOrder($id_order, 'error', $this->l('Invalid request'));
            return;
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            $this->redirectToOrder($id_order, 'error', $this->l('Order not found'));
            return;
        }

        try {
            // Get the Cardlink order ID from the database (custom column, not in Order model)
            $cardlink_order_id = Db::getInstance()->getValue(
                'SELECT cardlink_order_id FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int)$id_order
            );
            
            if (empty($cardlink_order_id)) {
                $this->redirectToOrder($id_order, 'error', $this->l('No Cardlink order ID found for this order'));
                return;
            }
            
            // Attach to order object for use in perform methods
            $order->cardlink_order_id = $cardlink_order_id;

            // Get payment gateway configuration and payment method from transaction
            $transaction = CardlinkPaymentTransaction::getPrimaryTransactionByOrder($id_order);

            if (!$transaction) {
                $this->redirectToOrder($id_order, 'error', $this->l('No payment transaction found for this order'));
                return;
            }

            $payMethod = $transaction->cardlink_pay_method;
            if ($payMethod === 'IRIS') {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET);
                $businessPartner = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER);
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT);
            } elseif (stripos($payMethod, 'Google Pay') !== false || stripos($payMethod, 'GOOGLEPAY') !== false) {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET);
                $businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT);
            } elseif (stripos($payMethod, 'Apple Pay') !== false || stripos($payMethod, 'APPLEPAY') !== false) {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET);
                $businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT);
            } else {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET);
                $businessPartner = Configuration::get(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER);
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT);
            }

            // Perform the requested action
            switch ($action) {
                case 'capture':
                    $this->performCapture($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment);
                    break;

                case 'void':
                    $this->performVoid($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment);
                    break;

                case 'refund':
                    $this->performRefund($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment);
                    break;

                default:
                    $this->redirectToOrder($id_order, 'error', $this->l('Unknown action'));
            }

        } catch (Exception $e) {
            $this->redirectToOrder($id_order, 'error', $e->getMessage());
        }
    }

    /**
     * Redirect back to the order page with a message.
     * For AJAX requests, returns JSON instead of redirecting.
     */
    private function redirectToOrder($id_order, $type = 'success', $message = '')
    {
        if ($message) {
            $this->context->cookie->cardlink_message_type = $type;
            $this->context->cookie->cardlink_message = $message;
        }

        if ($this->isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => $type, 'message' => $message]);
            exit;
        }

        $url = $this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => $id_order, 'vieworder' => 1]);

        if (method_exists('Tools', 'redirectAdmin')) {
            Tools::redirectAdmin($url);
        } else {
            header('Location: ' . $url);
            exit;
        }
    }

    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Perform capture operation
     */
    private function performCapture($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment)
    {
        if ($transaction->cardlink_pay_status !== Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED) {
            $this->redirectToOrder($id_order, 'error', $this->l('Only authorized transactions can be captured'));
            return;
        }

        try {
            $status = Cardlink_Checkout\TransactionOperationHelper::checkTransactionStatus(
                $order->cardlink_order_id,
                $merchantId,
                $sharedSecret,
                $businessPartner,
                $environment
            );

            if (!$status || (!isset($status['OrderAmount']) && !isset($status['orderAmount']))) {
                $this->redirectToOrder($id_order, 'error', $this->l('Could not retrieve authorized amount from Cardlink API.'));
                return;
            }

            $authorizedAmount = Tools::ps_round((float)($status['OrderAmount'] ?? $status['orderAmount'] ?? 0), 2);
            if ($authorizedAmount <= 0) {
                $this->redirectToOrder($id_order, 'error', $this->l('Authorized amount from Cardlink API is invalid.'));
                return;
            }

            // Calculate already captured amount from order_payment table
            $capturedAmount = 0;
            $sql = 'SELECT SUM(op.amount) as captured_total 
                    FROM `' . _DB_PREFIX_ . 'order_payment` op 
                    WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
            $result = Db::getInstance()->getValue($sql);
            if ($result) {
                $capturedAmount = Tools::ps_round((float) $result, 2);
            }

            $remainingAmount = Tools::ps_round($authorizedAmount - $capturedAmount, 2);
            if ($remainingAmount <= 0) {
                $this->redirectToOrder($id_order, 'error', $this->l('The full authorized amount has already been captured.'));
                return;
            }

            $requestedAmount = Tools::ps_round((float)$amount, 2);
            if ($requestedAmount <= 0) {
                $requestedAmount = $remainingAmount;
            }

            if ($requestedAmount > $remainingAmount) {
                $this->redirectToOrder($id_order, 'error', sprintf($this->l('Capture amount cannot exceed the remaining balance of %s.'), $remainingAmount . ' ' . $transaction->currency));
                return;
            }

            $captureResponse = Cardlink_Checkout\TransactionOperationHelper::captureTransaction(
                $id_order,
                $order->cardlink_order_id,
                $requestedAmount,
                $transaction->currency,
                $merchantId,
                $sharedSecret,
                $businessPartner,
                $environment
            );

            // Get transaction ID from capture response (TxId from gateway)
            $captureTransactionId = $captureResponse['TxId'] ?? $captureResponse['txId'] ?? '';
            
            // Fallback to a generated ID if API doesn't return one
            if (empty($captureTransactionId)) {
                $captureTransactionId = 'CAP-' . $order->cardlink_order_id . '-' . time();
            }

            // Create payment record in PrestaShop's order_payment table upon capture
            // Must be done BEFORE changing order state, as changeIdOrderState may auto-create a payment record
            $orderPayment = null;
            try {
                $orderPayment = new OrderPayment();
                $orderPayment->order_reference = $order->reference;
                $orderPayment->id_currency = (int)$order->id_currency;
                $orderPayment->amount = $requestedAmount;
                $orderPayment->payment_method = 'Cardlink Checkout';
                $orderPayment->transaction_id = $captureTransactionId;
                $orderPayment->card_brand = $transaction->cardlink_pay_method ?? '';
                $orderPayment->date_add = date('Y-m-d H:i:s');
                
                // Store the employee who performed the capture
                if (isset($this->context->employee) && $this->context->employee->id) {
                    $orderPayment->id_employee = (int)$this->context->employee->id;
                }
                
                if (!$orderPayment->save()) {
                    PrestaShopLogger::addLog('Cardlink: OrderPayment save() returned false for order #' . $id_order, 3);
                    $orderPayment = null;
                } else {
                    PrestaShopLogger::addLog('Cardlink: Payment record created with ID ' . $orderPayment->id . ' for order #' . $id_order . ', amount: ' . $requestedAmount, 1);
                }
            } catch (Exception $payEx) {
                // Log error but don't fail the capture
                PrestaShopLogger::addLog('Cardlink: Failed to create payment record on capture: ' . $payEx->getMessage(), 3);
                $orderPayment = null;
            }

            // Update order status to captured - this may create an invoice if the order state is configured to do so
            $newOrderState = (int) Configuration::get(
                Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                null,
                null,
                null,
                Configuration::get('PS_OS_PAYMENT')
            );

            $history = new OrderHistory();
            $history->id_order = $id_order;
            $history->changeIdOrderState($newOrderState, $id_order);
            $history->save();

            // Delete any auto-created payment records from changeIdOrderState (those without transaction_id)
            // Keep only our explicitly created payment records that have a transaction_id
            try {
                $deleteQuery = 'DELETE FROM `' . _DB_PREFIX_ . 'order_payment` 
                     WHERE order_reference = \'' . pSQL($order->reference) . '\' 
                     AND (transaction_id IS NULL OR transaction_id = \'\')';
                $deleted = Db::getInstance()->execute($deleteQuery);
                if ($deleted) {
                    $affectedRows = Db::getInstance()->Affected_Rows();
                    if ($affectedRows > 0) {
                        PrestaShopLogger::addLog('Cardlink: Cleaned up ' . $affectedRows . ' auto-created payment records for order #' . $id_order, 1);
                    }
                }
            } catch (Exception $delEx) {
                PrestaShopLogger::addLog('Cardlink: Failed to clean up auto-created payment records: ' . $delEx->getMessage(), 3);
            }

            // Link payment to invoice(s) if they exist
            if ($orderPayment && $orderPayment->id) {
                try {
                    // Refresh order to get any newly created invoices
                    $order = new Order($id_order);
                    $invoices = $order->getInvoicesCollection();
                    if ($invoices && $invoices->count() > 0) {
                        foreach ($invoices as $invoice) {
                            // Check if link already exists
                            $exists = Db::getInstance()->getValue(
                                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_invoice_payment` 
                                 WHERE id_order_invoice = ' . (int)$invoice->id . ' 
                                 AND id_order_payment = ' . (int)$orderPayment->id
                            );
                            if (!$exists) {
                                Db::getInstance()->insert('order_invoice_payment', [
                                    'id_order_invoice' => (int)$invoice->id,
                                    'id_order_payment' => (int)$orderPayment->id,
                                    'id_order' => (int)$id_order
                                ]);
                            }
                        }
                    }
                } catch (Exception $linkEx) {
                    PrestaShopLogger::addLog('Cardlink: Failed to link payment to invoice: ' . $linkEx->getMessage(), 3);
                }
            }

            $this->redirectToOrder($id_order, 'success', $this->l('Payment has been successfully captured'));

        } catch (Exception $e) {
            $this->redirectToOrder($id_order, 'error', $this->l('Capture failed: ') . $e->getMessage());
        }
    }

    /**
     * Perform void operation
     */
    private function performVoid($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment)
    {
        if ($transaction->cardlink_pay_status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED) {
            // Void is only permitted on the same business day as the capture (before settlement).
            $origTxVoid = CardlinkPaymentTransaction::getOriginalTransactionByOrder($id_order);
            $isPreauthVoid = ($origTxVoid && $origTxVoid->transaction_type === 'authorize');
            if ($isPreauthVoid) {
                $capTxVoid = CardlinkPaymentTransaction::getCaptureTransactionByOrder($id_order);
                $voidRelevantDate = $capTxVoid
                    ? $capTxVoid->date_add
                    : ($origTxVoid ? $origTxVoid->date_add : null);
            } else {
                $voidRelevantDate = $origTxVoid ? $origTxVoid->date_add : null;
            }
            $isSameDayVoid = $voidRelevantDate
                && (date('Y-m-d', strtotime($voidRelevantDate)) === date('Y-m-d'));
            if (!$isSameDayVoid) {
                $this->redirectToOrder($id_order, 'error', $this->l('Captured transactions can only be voided on the same day. For refunds, use the Partial Refund button in the order page.'));
                return;
            }
        }

        // Resolve captured amount (used for both default and validation)
        $capturedForVoid = 0;
        $sqlCaptured = 'SELECT SUM(op.amount) as captured_total
                FROM `' . _DB_PREFIX_ . 'order_payment` op
                WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
        $capturedResult = Db::getInstance()->getValue($sqlCaptured);
        if ($capturedResult) {
            $capturedForVoid = Tools::ps_round((float) $capturedResult, 2);
        }

        if ($amount <= 0) {
            $amount = ($capturedForVoid > 0) ? $capturedForVoid : $transaction->order_amount;
        }

        // Void amount must not exceed the captured amount (gateway rejects reversals greater than the capture)
        if ($capturedForVoid > 0 && $amount > $capturedForVoid) {
            $this->redirectToOrder($id_order, 'error', sprintf($this->l('Void amount cannot exceed the captured amount of %s.'), $capturedForVoid . ' ' . $transaction->currency));
            return;
        }

        try {
            Cardlink_Checkout\TransactionOperationHelper::voidTransaction(
                $id_order,
                $order->cardlink_order_id,
                $amount,
                $transaction->currency,
                $merchantId,
                $sharedSecret,
                $businessPartner,
                $environment
            );

            $successMessage = $this->l('Payment has been successfully voided');

            // For AJAX requests, send the success response immediately after the gateway confirms
            // the void, BEFORE calling changeIdOrderState. changeIdOrderState sends email via
            // SMTP and triggers hooks which can take 30-60+ seconds, causing nginx to return 502.
            // fastcgi_finish_request() closes the HTTP response while allowing PHP to keep running.
            if ($this->isAjaxRequest()) {
                $this->context->cookie->cardlink_message_type = 'success';
                $this->context->cookie->cardlink_message = $successMessage;

                // Persist session/cookie data so the subsequent page reload reads it
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => $successMessage]);

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    if (ob_get_level()) {
                        ob_end_flush();
                    }
                    flush();
                }

                // Continue running after response is sent (order status update in background)
                ignore_user_abort(true);
            }

            // Update order status to canceled (may be slow - email notifications, hooks)
            try {
                $newOrderState = (int) Configuration::get('PS_OS_CANCELED');
                $history = new OrderHistory();
                $history->id_order = $id_order;
                $history->changeIdOrderState($newOrderState, $id_order);
                $history->save();
            } catch (\Throwable $orderStateEx) {
                PrestaShopLogger::addLog(
                    'Cardlink: Failed to update order status after void for order #' . $id_order . ': ' . $orderStateEx->getMessage(),
                    3
                );
            }

            if ($this->isAjaxRequest()) {
                exit;
            }

            $this->redirectToOrder($id_order, 'success', $successMessage);

        } catch (Exception $e) {
            $this->redirectToOrder($id_order, 'error', $this->l('Void failed: ') . $e->getMessage());
        }
    }

    /**
     * Perform refund operation
     */
    private function performRefund($id_order, $transaction, $order, $amount, $merchantId, $sharedSecret, $businessPartner, $environment)
    {
        // Block same-day refunds: captured transactions must be voided before settlement (same business day).
        if ($transaction->cardlink_pay_status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED) {
            $origTxRefund = CardlinkPaymentTransaction::getOriginalTransactionByOrder($id_order);
            $isPreauthRefund = ($origTxRefund && $origTxRefund->transaction_type === 'authorize');
            if ($isPreauthRefund) {
                $capTxRefund = CardlinkPaymentTransaction::getCaptureTransactionByOrder($id_order);
                $refundRelevantDate = $capTxRefund
                    ? $capTxRefund->date_add
                    : ($origTxRefund ? $origTxRefund->date_add : null);
            } else {
                $refundRelevantDate = $origTxRefund ? $origTxRefund->date_add : null;
            }
            if ($refundRelevantDate && date('Y-m-d', strtotime($refundRelevantDate)) === date('Y-m-d')) {
                $this->redirectToOrder($id_order, 'error', $this->l('Refunds are not available on the same day as the transaction. Use the Void/Cancel button instead.'));
                return;
            }
        }

        // Calculate captured amount from order_payment table
        $capturedAmount = 0;
        $sql = 'SELECT SUM(op.amount) as captured_total 
                FROM `' . _DB_PREFIX_ . 'order_payment` op 
                WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
        $result = Db::getInstance()->getValue($sql);
        if ($result) {
            $capturedAmount = Tools::ps_round((float) $result, 2);
        }

        if ($capturedAmount <= 0) {
            $this->redirectToOrder($id_order, 'error', $this->l('No captured payments found to refund'));
            return;
        }

        // Calculate already refunded amount from order_slip table
        $refundedAmount = 0;
        $sql = 'SELECT SUM(os.total_products_tax_incl + os.total_shipping_tax_incl) as refunded_total 
                FROM `' . _DB_PREFIX_ . 'order_slip` os 
                WHERE os.id_order = ' . (int)$id_order;
        $refundResult = Db::getInstance()->getValue($sql);
        if ($refundResult) {
            $refundedAmount = Tools::ps_round((float) $refundResult, 2);
        }

        $refundableAmount = Tools::ps_round($capturedAmount - $refundedAmount, 2);
        if ($refundableAmount <= 0) {
            $this->redirectToOrder($id_order, 'error', $this->l('The full captured amount has already been refunded.'));
            return;
        }

        $requestedAmount = Tools::ps_round((float)$amount, 2);
        if ($requestedAmount <= 0) {
            $requestedAmount = $refundableAmount;
        }

        if ($requestedAmount > $refundableAmount) {
            $this->redirectToOrder($id_order, 'error', sprintf($this->l('Refund amount cannot exceed the remaining balance of %s.'), $refundableAmount . ' ' . $transaction->currency));
            return;
        }

        try {
            $operationResponse = Cardlink_Checkout\TransactionOperationHelper::refundTransaction(
                $id_order,
                $order->cardlink_order_id,
                $requestedAmount,
                $capturedAmount,
                $transaction->currency,
                $merchantId,
                $sharedSecret,
                $businessPartner,
                $environment
            );

            $effectiveOperation = isset($operationResponse['cardlink_operation'])
                ? (string)$operationResponse['cardlink_operation']
                : 'refund';

            if ($effectiveOperation === 'void') {
                PrestaShopLogger::addLog(
                    'Cardlink: Refund request executed as VOID for order #' . (int)$id_order .
                    ' (amount: ' . $requestedAmount . ' ' . $transaction->currency . ')',
                    2
                );
            }

            // Create credit slip for the refunded amount
            // Set flag to prevent hookActionOrderSlipAdd from sending duplicate refund to gateway
            $this->context->cookie->cardlink_refund_in_progress = true;
            $this->createCreditSlip($order, $requestedAmount, $refundableAmount);
            unset($this->context->cookie->cardlink_refund_in_progress);

            // Determine if this is a full or partial refund
            $newRefundedTotal = $refundedAmount + $requestedAmount;
            $isFullRefund = ($newRefundedTotal >= $capturedAmount);

            $successMessage = ($effectiveOperation === 'void')
                ? sprintf($this->l('Payment of %s %s was successfully canceled (void) at gateway instead of refund.'), $requestedAmount, $transaction->currency)
                : sprintf($this->l('Payment of %s %s has been successfully refunded'), $requestedAmount, $transaction->currency);

            // For AJAX requests, send the success response immediately BEFORE changeIdOrderState.
            // changeIdOrderState sends email via SMTP and triggers hooks which can take 30-60+ seconds,
            // causing nginx to return 502. fastcgi_finish_request() closes the HTTP response while
            // allowing PHP to keep running.
            if ($this->isAjaxRequest()) {
                $this->context->cookie->cardlink_message_type = 'success';
                $this->context->cookie->cardlink_message = $successMessage;

                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => $successMessage]);

                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } else {
                    if (ob_get_level()) {
                        ob_end_flush();
                    }
                    flush();
                }

                ignore_user_abort(true);
            }

            // Update order status for full refunds (may be slow - email notifications, hooks)
            if ($isFullRefund) {
                try {
                    $newOrderState = (int) Configuration::get('PS_OS_REFUND');
                    $history = new OrderHistory();
                    $history->id_order = $id_order;
                    $history->changeIdOrderState($newOrderState, $id_order);
                    $history->save();
                } catch (\Throwable $orderStateEx) {
                    PrestaShopLogger::addLog(
                        'Cardlink: Failed to update order status after refund for order #' . $id_order . ': ' . $orderStateEx->getMessage(),
                        3
                    );
                }
            }

            if ($this->isAjaxRequest()) {
                exit;
            }

            $this->redirectToOrder($id_order, 'success', $successMessage);

        } catch (Exception $e) {
            $this->redirectToOrder($id_order, 'error', $this->l('Refund failed: ') . $e->getMessage());
        }
    }

    /**
     * Create a credit slip for the refunded amount
     * 
     * @param Order $order The order object
     * @param float $refundAmount The amount being refunded
     * @param float $maxRefundableAmount The maximum amount that can be refunded
     */
    private function createCreditSlip($order, $refundAmount, $maxRefundableAmount)
    {
        try {
            // Reload order to ensure we have fresh data
            $order = new Order((int)$order->id);
            
            // Get order products
            $orderProducts = $order->getProducts();
            if (empty($orderProducts)) {
                PrestaShopLogger::addLog('Cardlink: Cannot create credit slip - no products in order', 2);
                return;
            }

            // Calculate what percentage of the total we're refunding
            $refundPercentage = $refundAmount / $maxRefundableAmount;
            $isFullRefund = ($refundPercentage >= 0.9999); // Account for floating point precision

            // Prepare product list for credit slip
            $productList = [];
            $qtyList = [];
            $remainingRefundAmount = $refundAmount;

            // Get shipping amount to potentially include
            $shippingCostAmount = 0;
            
            if ($isFullRefund) {
                // Full refund: include all products with full quantities and shipping
                foreach ($orderProducts as $product) {
                    $productList[$product['id_order_detail']] = [
                        'id_order_detail' => (int)$product['id_order_detail'],
                        'quantity' => (int)$product['product_quantity'],
                        'unit_price' => (float)$product['unit_price_tax_incl'],
                        'amount' => (float)$product['total_price_tax_incl'],
                    ];
                    $qtyList[$product['id_order_detail']] = (int)$product['product_quantity'];
                }
                $shippingCostAmount = (float)$order->total_shipping_tax_incl;
            } else {
                // Partial refund: distribute proportionally across products
                foreach ($orderProducts as $product) {
                    if ($remainingRefundAmount <= 0) {
                        break;
                    }

                    $productTotal = (float)$product['total_price_tax_incl'];
                    $productQty = (int)$product['product_quantity'];
                    
                    if ($productQty <= 0 || $productTotal <= 0) {
                        continue;
                    }

                    // Calculate how much of this product's value to refund
                    $productRefundAmount = min($productTotal * $refundPercentage, $remainingRefundAmount);
                    
                    if ($productRefundAmount > 0) {
                        // For partial refunds, we use quantity 0 and set the amount directly
                        $productList[$product['id_order_detail']] = [
                            'id_order_detail' => (int)$product['id_order_detail'],
                            'quantity' => 0,
                            'unit_price' => (float)$product['unit_price_tax_incl'],
                            'amount' => $productRefundAmount,
                        ];
                        $qtyList[$product['id_order_detail']] = 0;
                        $remainingRefundAmount -= $productRefundAmount;
                    }
                }

                // If there's still remaining amount, add it to shipping
                if ($remainingRefundAmount > 0.01) {
                    $shippingCostAmount = min($remainingRefundAmount, (float)$order->total_shipping_tax_incl);
                }
            }

            if (empty($productList)) {
                // Fallback: create a simple credit slip using the first product
                $firstProduct = reset($orderProducts);
                if ($firstProduct) {
                    $productList[$firstProduct['id_order_detail']] = [
                        'id_order_detail' => (int)$firstProduct['id_order_detail'],
                        'quantity' => 0,
                        'unit_price' => (float)$firstProduct['unit_price_tax_incl'],
                        'amount' => $refundAmount,
                    ];
                    $qtyList[$firstProduct['id_order_detail']] = 0;
                    $shippingCostAmount = 0;
                }
            }

            // Create the credit slip using PrestaShop's OrderSlip class
            $orderSlip = new OrderSlip();
            $orderSlip->id_order = (int)$order->id;
            $orderSlip->id_customer = (int)$order->id_customer;
            $orderSlip->conversion_rate = $order->conversion_rate;
            $orderSlip->total_products_tax_excl = Tools::ps_round($refundAmount / (1 + ($order->carrier_tax_rate / 100)), 2);
            $orderSlip->total_products_tax_incl = Tools::ps_round($refundAmount - $shippingCostAmount, 2);
            $orderSlip->total_shipping_tax_excl = Tools::ps_round($shippingCostAmount / (1 + ($order->carrier_tax_rate / 100)), 2);
            $orderSlip->total_shipping_tax_incl = $shippingCostAmount;
            $orderSlip->shipping_cost = ($shippingCostAmount > 0);
            $orderSlip->shipping_cost_amount = $shippingCostAmount;
            $orderSlip->partial = !$isFullRefund;
            $orderSlip->amount = $refundAmount;
            $orderSlip->date_add = date('Y-m-d H:i:s');
            $orderSlip->date_upd = date('Y-m-d H:i:s');

            if ($orderSlip->add()) {
                // Add credit slip details for each product
                foreach ($productList as $id_order_detail => $productData) {
                    Db::getInstance()->insert('order_slip_detail', [
                        'id_order_slip' => (int)$orderSlip->id,
                        'id_order_detail' => (int)$id_order_detail,
                        'product_quantity' => (int)$productData['quantity'],
                        'unit_price_tax_excl' => Tools::ps_round($productData['unit_price'] / (1 + ($order->carrier_tax_rate / 100)), 6),
                        'unit_price_tax_incl' => (float)$productData['unit_price'],
                        'total_price_tax_excl' => Tools::ps_round($productData['amount'] / (1 + ($order->carrier_tax_rate / 100)), 6),
                        'total_price_tax_incl' => (float)$productData['amount'],
                        'amount_tax_excl' => Tools::ps_round($productData['amount'] / (1 + ($order->carrier_tax_rate / 100)), 6),
                        'amount_tax_incl' => (float)$productData['amount'],
                    ]);
                }

                PrestaShopLogger::addLog(
                    'Cardlink: Credit slip #' . $orderSlip->id . ' created for order #' . $order->id . ' - Amount: ' . $refundAmount,
                    1
                );
            } else {
                PrestaShopLogger::addLog('Cardlink: Failed to create credit slip for order #' . $order->id, 3);
            }

        } catch (Exception $e) {
            PrestaShopLogger::addLog('Cardlink: Error creating credit slip: ' . $e->getMessage(), 3);
        }
    }
}
