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
        $success = false;

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        // /**
        //  * Verify if this module is enabled and if the cart has
        //  * a valid customer, delivery address and invoice address
        //  */
        // if (
        //     !$this->module->active || $cart->id_customer == 0
        // ) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        // /** @var CustomerCore $customer */
        // $customer = new Customer($cart->id_customer);

        // /**
        //  * Check if this is a valid customer account
        //  */
        // if (!Validate::isLoadedObject($customer)) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        $responseData = Tools::getAllValues();

        // Verify that the response is coming from the payment gateway.
        $isValidPaymentGatewayResponse = Cardlink_Checkout\PaymentHelper::validateResponseData(
            $responseData,
            Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '')
        );

        if (!$isValidPaymentGatewayResponse) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $id_order = intval($responseData[Cardlink_Checkout\ApiFields::OrderId]);
        $order_details = new Order($id_order);

        $customer = new Customer($order_details->id_customer);

        // /**
        //  * Check if the requested order is linked to the customer
        //  */
        // if ($order_details->id_customer != $cart->id_customer) {
        //     Tools::redirect(self::ERROR_REDIRECT_URI);
        // }

        // if (Mage::helper('cardlink_checkout')->logDebugInfoEnabled()) {
        //     Mage::log("Received valid payment gateway response", null, 'cardlink.log', true);
        //     Mage::log(json_encode($responseData, JSON_PRETTY_PRINT), null, 'cardlink.log', true);
        // }

        // If the response identifies the transaction as either AUTHORIZED or CAPTURED.
        if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CAPTURED
        ) {
            // Set order as paid.
            $this->errors = Cardlink_Checkout\PaymentHelper::markSuccessfulPayment($this->module, $order_details, $responseData);

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

            $success = true;
        } else if (
            $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_REFUSED
            || $responseData[Cardlink_Checkout\ApiFields::Status] === Cardlink_Checkout\Constants::TRANSACTION_STATUS_ERROR
        ) {
            $this->errors = Cardlink_Checkout\PaymentHelper::markCanceledPayment($this->module, $order_details, $responseData);
        }

        $id_shop = $order_details->id_shop;
        $id_lang = $order_details->id_lang;

        // If the payment flow executed inside the IFRAME, send out a redirection form page to force open the final response page in the parent frame (store window/tab).
        if (boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME))) {
            $queryParameters = [];
            $controller = '';

            if ($success) {
                $controller = 'order-confirmation';
                $redirectParameters = [
                    'id_cart' => (int)$cart->id,
                    'id_module' => (int)$this->module->id,
                    'id_order' => $id_order
                ];
            } else {
                $controller = 'cart';
                $redirectParameters = [];
            }

            $redirectParameters['key'] = $customer->secure_key;
            $redirectParameters['message'] = $responseData[Cardlink_Checkout\ApiFields::Message];

            $redirectUrl = Context::getContext()->link->getPageLink($controller, true, $id_lang, $redirectParameters, false, $id_shop);
            $parts = parse_url($redirectUrl);
            if (isset($parts[PHP_URL_QUERY])) {
                parse_str($parts[PHP_URL_QUERY], $queryParameters);
            }

            $this->context->smarty->assign([
                'action' => explode('?', $redirectUrl)[0],
                'form_data' => $queryParameters,
                'css_url' =>  $this->module->getPathUri() . 'views/css/front-custom.css',
                'use_iframe' => boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0'))
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
                    'id_cart' => (int)$cart->id,
                    'id_module' => (int)$this->module->id,
                    'id_order' => $id_order,
                    'key' => $customer->secure_key
                ];

                Tools::redirect('index.php?' . http_build_query($redirectParameters));
            } else {
                Tools::redirect('index.php?controller=cart');
            }
            return;
        }
    }
}
