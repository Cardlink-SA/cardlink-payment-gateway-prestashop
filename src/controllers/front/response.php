<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Response Handle Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutResponseModuleFrontController extends ModuleFrontController
{
    // public $ssl = true;
    // public $auth = true;
    // public $guestAllowed = false;

    public const ERROR_REDIRECT_URI = 'index.php?controller=order&step=1';

    public function postProcess()
    {
        \Cardlink_Checkout\PaymentHelper::forceSessionCookieSameSiteNone();

        $success = false;

        /**
         * Get current cart object from session
         */
        $contextCart = $this->context->cart;

        $responseData = Tools::getAllValues();

        $parts = explode('x', $responseData[Cardlink_Checkout\ApiFields::OrderId]);

        if (count($parts) < 2) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $cart = new Cart(intval($parts[0]));

        if (!Validate::isLoadedObject($cart)) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (
            !$this->module->active || $cart->id_customer == 0
        ) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $payMethod = @$responseData['payMethod'];

        if ($payMethod == 'IRIS') {
            $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET, null, null, null, '');
        } else {
            $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '');
        }

        // Verify that the response is coming from the payment gateway.
        $isValidPaymentGatewayResponse = Cardlink_Checkout\PaymentHelper::validateResponseData(
            $responseData,
            $sharedSecret
        );

        if (!$isValidPaymentGatewayResponse) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $customer = new Customer($cart->id_customer);

        // If the response identifies the transaction as either AUTHORIZED or CAPTURED.
        if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED
        ) {
            $isValidXlsBonusPaymentGatewayResponse = true;

            if (array_key_exists(Cardlink_Checkout\ApiFields::XlsBonusDigest, $responseData)) {
                $isValidXlsBonusPaymentGatewayResponse = Cardlink_Checkout\PaymentHelper::validateXlsBonusResponseData(
                    $responseData,
                    $sharedSecret
                );
            }

            if ($isValidXlsBonusPaymentGatewayResponse == true) {

                // Get the total amount including taxes, shipping, and discounts
                $total_amount = $cart->getOrderTotal(true, Cart::BOTH);

                if ($responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED) {
                    $orderState = (int) Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, null, null, null, Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED'));
                } else if ($responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED) {
                    $orderState = (int) Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, null, null, null, Configuration::get('PS_OS_PAYMENT'));
                } else {
                    Tools::redirect(self::ERROR_REDIRECT_URI);
                }

                try {
                    $this->module->validateOrder(
                        (int) $cart->id,
                        $orderState,
                        $total_amount,
                        $this->module->displayName,
                        null,
                        [
                            'transaction_id' => $responseData[Cardlink_Checkout\ApiFields::TransactionId]
                        ],
                        (int) $cart->id_currency,
                        false,
                        $customer->secure_key
                    );

                    $order_id = $this->module->currentOrder;
                    $order_details = new Order($order_id);

                    // if ($payMethod != 'IRIS') {

                    //     $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'order_payment` WHERE `order_reference` = \'' . pSQL($order_details->reference) . '\'';
                    //     $order_payments = Db::getInstance()->executeS($sql);

                    //     if (count($order_payments)) {
                    //         $order_payment = $order_payments[0];

                    //         if (array_key_exists(Cardlink_Checkout\ApiFields::ExtTokenPanEnd, $responseData)) {
                    //             // Create a DateTime object from the input string
                    //             $date = DateTime::createFromFormat('Ymd', $responseData[Cardlink_Checkout\ApiFields::ExtTokenExpiration]);

                    //             // Check if the date was created successfully
                    //             if ($date === false) {
                    //                 $formattedDate = 'n/a';
                    //             } else {
                    //                 // Format the date to MM/YYYY
                    //                 $formattedDate = $date->format('m/Y');
                    //             }
                    //         } else {
                    //             $formattedDate = 'n/a';
                    //         }

                    //         $cardNumber = array_key_exists(Cardlink_Checkout\ApiFields::ExtTokenPanEnd, $responseData)
                    //             ? str_pad($responseData[Cardlink_Checkout\ApiFields::ExtTokenPanEnd], 16, 'x', STR_PAD_LEFT)
                    //             : 'n/a';

                    //         \Db::getInstance()->update(
                    //             'order_payment',
                    //             [
                    //                 'transaction_id' => pSQL($responseData[Cardlink_Checkout\ApiFields::TransactionId]),
                    //                 'card_number' => pSQL($cardNumber),
                    //                 'card_expiration' => pSQL($formattedDate),
                    //                 'card_brand' => pSQL($responseData[Cardlink_Checkout\ApiFields::PaymentMethod])
                    //             ],
                    //             'id_order_payment = ' . (int) $order_payment['id_order_payment']
                    //         );
                    //     }
                    // }


                } catch (\Exception $ex) {
                    Tools::redirect(__PS_BASE_URI__ . 'index.php?controller=cart&message=' . $ex->getMessage());
                }


                try {
                    if (array_key_exists(Cardlink_Checkout\ApiFields::ExtToken, $responseData)) {
                        $stored_token = new Cardlink_Checkout\StoredToken();
                        $stored_token->active = true;
                        $stored_token->id_customer = $order_details->id_customer;
                        $stored_token->token = $responseData[Cardlink_Checkout\ApiFields::ExtToken];
                        $stored_token->type = $responseData[Cardlink_Checkout\ApiFields::PaymentMethod];
                        $stored_token->last_4digits = $responseData[Cardlink_Checkout\ApiFields::ExtTokenPanEnd];
                        $stored_token->expiration = $responseData[Cardlink_Checkout\ApiFields::ExtTokenExpiration];
                        $stored_token->save();
                    }
                } catch (\Exception $e) {
                }

                $id_order = $order_details->id;
                $id_shop = $order_details->id_shop;
                $id_lang = $order_details->id_lang;
                $success = true;
            }
        } else if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_REFUSED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_ERROR
        ) {
            //$this->errors = Cardlink_Checkout\PaymentHelper::markCanceledPayment($this->module, $order_details, true);
        }

        $useIframe = false;

        if ($payMethod != 'IRIS') {
            $useIframe = boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, null, null, null, '0'));
        }

        // If the payment flow executed inside the IFRAME, send out a redirection form page to force open the final response page in the parent frame (store window/tab).
        if ($useIframe) {
            if ($success) {
                $redirectParameters = [
                    'id_shop' => $id_shop,
                    'id_cart' => (int) $cart->id,
                    'id_module' => (int) $this->module->id,
                    'id_order' => $id_order,
                    'key' => $customer->secure_key
                ];

                $redirectParameters['message'] = array_key_exists(Cardlink_Checkout\ApiFields::Message, $responseData)
                    ? $responseData[Cardlink_Checkout\ApiFields::Message]
                    : '';

                $redirectUrl = Context::getContext()->link->getPageLink('order-confirmation', true, $id_lang, $redirectParameters, false, $id_shop);
            } else {
                $redirectParameters = [
                    'status' => $responseData[Cardlink_Checkout\ApiFields::Status],
                    'message' => array_key_exists(Cardlink_Checkout\ApiFields::Message, $responseData)
                        ? $responseData[Cardlink_Checkout\ApiFields::Message]
                        : '',
                    'key' => $customer->secure_key
                ];

                $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'payment', $redirectParameters, true);
            }

            $this->context->smarty->assign([
                'action' => explode('?', $redirectUrl)[0],
                'form_data' => $redirectParameters,
                'css_url' => $this->module->getPathUri() . 'views/css/front-custom.css',
                'use_iframe' => $useIframe
            ]);

            /**
             *  Load form template to be displayed in the checkout step
             */
            $this->setTemplate('module:cardlink_checkout/views/templates/front/response_form.tpl');
        } else {
            if ($success) {
                $redirectParameters = [
                    'controller' => 'order-confirmation',
                    'id_shop' => $id_shop,
                    'id_cart' => (int) $cart->id,
                    'id_module' => (int) $this->module->id,
                    'id_order' => $id_order,
                    'key' => $customer->secure_key
                ];

                Tools::redirect('index.php?' . http_build_query($redirectParameters));
            } else {
                $redirectParameters = [
                    'status' => $responseData[Cardlink_Checkout\ApiFields::Status],
                    'message' => array_key_exists(Cardlink_Checkout\ApiFields::Message, $responseData)
                        ? $responseData[Cardlink_Checkout\ApiFields::Message]
                        : '',
                    'key' => $customer->secure_key
                ];

                $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'payment', $redirectParameters, true);
                Tools::redirect($redirectUrl);
            }
            return;
        }
    }

}