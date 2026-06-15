<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Background Confirmation (Server-to-Server) Webhook Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutBackgroundconfirmationModuleFrontController extends ModuleFrontController
{
    public $ssl = false;
    public $auth = false;
    public $guestAllowed = true;

    public function postProcess()
    {
        header('Content-Type: application/json; charset=utf-8');

        // Log client IP
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        try {
            // Validate User-Agent
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (strpos($userAgent, 'Modirum HTTPClient') === false) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Invalid User-Agent',
                    'ip' => $clientIp
                ]);
                exit;
            }

            // Get and validate request data
            $responseData = Tools::getAllValues();

            if (empty($responseData)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Empty request'
                ]);
                exit;
            }

            // Extract order ID from the payment gateway response
            $parts = explode('x', $responseData[Cardlink_Checkout\ApiFields::OrderId] ?? '');
            if (count($parts) < 2) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Invalid order ID format'
                ]);
                exit;
            }

            $id_cart = intval($parts[0]);
            $id_order = null;

            // Get order from cart
            $cart = new Cart($id_cart);
            if (!Validate::isLoadedObject($cart)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Cart not found'
                ]);
                exit;
            }

            // Get the actual order (Order::getByCartId returns Order|false, not an array)
            $orderFromCart = Order::getByCartId($id_cart);
            if ($orderFromCart !== false && Validate::isLoadedObject($orderFromCart)) {
                $id_order = $orderFromCart->id;
            }

            // Validate payment gateway response signature
            $payMethod = $responseData['payMethod'] ?? 'card';
            if ($payMethod === 'IRIS') {
                $sharedSecret = Configuration::get(
                    Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET,
                    null,
                    null,
                    null,
                    ''
                );
                $merchantId = Configuration::get(
                    Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID,
                    null,
                    null,
                    null,
                    ''
                );
            } else {
                $sharedSecret = Configuration::get(
                    Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET,
                    null,
                    null,
                    null,
                    ''
                );
                $merchantId = Configuration::get(
                    Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID,
                    null,
                    null,
                    null,
                    ''
                );
            }

            // Validate merchant ID
            if (($responseData[Cardlink_Checkout\ApiFields::MerchantId] ?? '') !== $merchantId) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Merchant ID mismatch'
                ]);
                exit;
            }

            // Enforce HTTPS in production mode
            $isProduction = Configuration::get(
                Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT
            ) === Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_PRODUCTION;

            if ($isProduction && !isset($_SERVER['HTTPS'])) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'HTTPS required in production'
                ]);
                exit;
            }

            // Validate response signature
            $isValidSignature = Cardlink_Checkout\PaymentHelper::validateResponseData(
                $responseData,
                $sharedSecret
            );

            if (!$isValidSignature) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'status' => 'error',
                    'message' => 'Invalid signature'
                ]);
                exit;
            }

            // Get the payment status
            $paymentStatus = $responseData[Cardlink_Checkout\ApiFields::Status] ?? '';
            $cardlinkOrderId = $responseData[Cardlink_Checkout\ApiFields::OrderId] ?? '';
            $isCaptured = ($paymentStatus === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED);
            $isAuthorized = ($paymentStatus === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED);
            $isSuccessful = ($isCaptured || $isAuthorized);

            // Check idempotency - if order is already in final state, skip processing
            if ($id_order) {
                $order = new Order($id_order);
                if (Validate::isLoadedObject($order)) {
                    $capturedState = (int) Configuration::get(
                        Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                        null,
                        null,
                        null,
                        Configuration::get('PS_OS_PAYMENT')
                    );

                    $finalStates = [
                        Configuration::get('PS_OS_DELIVERED'),
                        Configuration::get('PS_OS_CANCELED'),
                        Configuration::get('PS_OS_REFUND'),
                        Configuration::get('PS_OS_ERROR'),
                        $capturedState,
                    ];

                    if (in_array($order->current_state, $finalStates)) {
                        http_response_code(200);
                        echo json_encode([
                            'success' => true,
                            'status' => 'already_processed',
                            'message' => 'Order already in final state',
                            'order_id' => $id_order,
                            'order_state' => $order->current_state,
                            'order_status' => $paymentStatus
                        ]);
                        exit;
                    }
                }
            }

            // If order doesn't exist yet and payment was successful, create the order
            if (!$id_order && $isSuccessful) {
                try {
                    $customer = new Customer($cart->id_customer);
                    if (!Validate::isLoadedObject($customer)) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'status' => 'error',
                            'message' => 'Customer not found for cart'
                        ]);
                        exit;
                    }

                    // Determine order state based on payment status
                    if ($isCaptured) {
                        $orderState = (int) Configuration::get(
                            Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                            null,
                            null,
                            null,
                            Configuration::get('PS_OS_PAYMENT')
                        );
                    } else {
                        $orderState = (int) Configuration::get(
                            Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                            null,
                            null,
                            null,
                            Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
                        );
                    }

                    // Get the module instance
                    $module = Module::getInstanceByName('cardlink_checkout');
                    if (!$module) {
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'status' => 'error',
                            'message' => 'Module not found'
                        ]);
                        exit;
                    }

                    // Get the total amount
                    $total_amount = $cart->getOrderTotal(true, Cart::BOTH);

                    // Create the order
                    $module->validateOrder(
                        (int) $cart->id,
                        $orderState,
                        $total_amount,
                        $module->displayName,
                        null,
                        [],
                        (int) $cart->id_currency,
                        false,
                        $customer->secure_key
                    );

                    $id_order = $module->currentOrder;
                    $order = new Order($id_order);

                    if (!Validate::isLoadedObject($order)) {
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'status' => 'error',
                            'message' => 'Failed to create order'
                        ]);
                        exit;
                    }

                    // Store Cardlink order ID
                    Cardlink_Checkout\PaymentResponseProcessor::storeCardlinkOrderId($id_order, $cardlinkOrderId);

                    // Create transaction record
                    CardlinkPaymentTransaction::createTransaction([
                        'id_order' => $id_order,
                        'cardlink_order_id' => $cardlinkOrderId,
                        'cardlink_tx_id' => $responseData[Cardlink_Checkout\ApiFields::TransactionId] ?? null,
                        'cardlink_pay_status' => $paymentStatus,
                        'cardlink_pay_method' => $payMethod,
                        'cardlink_pay_ref' => $responseData[Cardlink_Checkout\ApiFields::PaymentReferenceId] ?? null,
                        'order_amount' => $total_amount,
                        'currency' => Currency::getIsoCodeById((int) $cart->id_currency),
                        'transaction_type' => $isCaptured ? 'sale' : 'authorize'
                    ]);

                    // Handle payment record and invoice based on transaction type
                    if ($isCaptured) {
                        Cardlink_Checkout\PaymentResponseProcessor::handleCapturedPaymentRecord(
                            $order,
                            $responseData,
                            $total_amount,
                            $module->displayName
                        );
                    } else {
                        Cardlink_Checkout\PaymentResponseProcessor::handlePreAuthCleanup($order);
                    }

                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'status' => 'order_created',
                        'message' => 'Order created successfully',
                        'order_id' => $id_order,
                        'order_state' => $orderState,
                        'order_status' => $paymentStatus
                    ]);
                    exit;

                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'status' => 'error',
                        'message' => 'Failed to create order: ' . $e->getMessage()
                    ]);
                    exit;
                }
            }

            // If order doesn't exist and payment was not successful, just acknowledge
            if (!$id_order && !$isSuccessful) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'status' => 'no_action',
                    'message' => 'Payment not successful, no order to create',
                    'order_status' => $paymentStatus
                ]);
                exit;
            }

            // Store transaction data for existing order
            $transaction = CardlinkPaymentTransaction::getByCardlinkOrderId($cardlinkOrderId);

            if (!$transaction && $id_order) {
                CardlinkPaymentTransaction::createTransaction([
                    'id_order' => $id_order,
                    'cardlink_order_id' => $cardlinkOrderId,
                    'cardlink_tx_id' => $responseData[Cardlink_Checkout\ApiFields::TransactionId] ?? null,
                    'cardlink_pay_status' => $paymentStatus,
                    'cardlink_pay_method' => $payMethod,
                    'cardlink_pay_ref' => $responseData[Cardlink_Checkout\ApiFields::PaymentReferenceId] ?? null,
                    'order_amount' => $responseData[Cardlink_Checkout\ApiFields::OrderAmount] ?? 0,
                    'currency' => $responseData[Cardlink_Checkout\ApiFields::Currency] ?? 'EUR',
                    'transaction_type' => $isCaptured ? 'sale' : 'authorize'
                ]);
            } elseif ($transaction) {
                $transaction->cardlink_tx_id = $responseData[Cardlink_Checkout\ApiFields::TransactionId] ?? $transaction->cardlink_tx_id;
                $transaction->cardlink_pay_status = $paymentStatus;
                $transaction->cardlink_pay_method = $payMethod;
                $transaction->cardlink_pay_ref = $responseData[Cardlink_Checkout\ApiFields::PaymentReferenceId] ?? $transaction->cardlink_pay_ref;
                $transaction->save();
            }

            // Update existing order status based on payment status
            if ($id_order) {
                $order = new Order($id_order);
                if (Validate::isLoadedObject($order)) {
                    $newOrderState = null;

                    switch ($paymentStatus) {
                        case Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED:
                            $newOrderState = (int) Configuration::get(
                                Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                                null,
                                null,
                                null,
                                Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')
                            );
                            break;

                        case Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED:
                            $newOrderState = (int) Configuration::get(
                                Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                                null,
                                null,
                                null,
                                Configuration::get('PS_OS_PAYMENT')
                            );
                            break;

                        case Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED:
                            $newOrderState = (int) Configuration::get('PS_OS_CANCELED');
                            break;

                        case Cardlink_Checkout\Constants::TRANSACTION_STATUS_REFUSED:
                        case Cardlink_Checkout\Constants::TRANSACTION_STATUS_ERROR:
                            $newOrderState = (int) Configuration::get('PS_OS_ERROR');
                            break;
                    }

                    if ($newOrderState !== null && $newOrderState !== $order->current_state) {
                        $history = new OrderHistory();
                        $history->id_order = $id_order;
                        $history->changeIdOrderState($newOrderState, $id_order);
                        $history->save();
                    }

                    // Store Cardlink order ID in the order
                    Cardlink_Checkout\PaymentResponseProcessor::storeCardlinkOrderId($id_order, $cardlinkOrderId);

                    // Handle payment record and invoice based on transaction type
                    // For captured (sale) transactions, create/update payment record with card details
                    // For pre-authorized transactions, clean up any auto-created payment/invoice
                    $orderAmount = (float)($responseData[Cardlink_Checkout\ApiFields::OrderAmount] ?? $order->total_paid);
                    
                    if ($isCaptured) {
                        Cardlink_Checkout\PaymentResponseProcessor::handleCapturedPaymentRecord(
                            $order,
                            $responseData,
                            $orderAmount,
                            'Cardlink Checkout'
                        );
                    } else {
                        Cardlink_Checkout\PaymentResponseProcessor::handlePreAuthCleanup($order);
                    }

                    http_response_code(200);
                    echo json_encode([
                        'success' => true,
                        'status' => 'processed',
                        'message' => 'Order updated successfully',
                        'order_id' => $id_order,
                        'order_state' => $newOrderState,
                        'order_status' => $paymentStatus
                    ]);
                    exit;
                }
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'status' => 'ok',
                'message' => 'Request processed',
                'order_id' => $id_order,
                'order_status' => $paymentStatus
            ]);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }

        exit;
    }
}
