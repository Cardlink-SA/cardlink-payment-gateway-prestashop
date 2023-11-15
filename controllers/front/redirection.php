<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Redirection Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutRedirectionModuleFrontController extends ModuleFrontController
{
    public const ERROR_REDIRECT_URI = 'index.php?controller=order&step=1';

    public function initContent()
    {
        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $id_order = Tools::getValue('id_order', 0);
        $payment_method = Tools::getValue('payment_method', 'card');
        $installments = intval(Tools::getValue('installments', 0));
        $stored_token = intval(Tools::getValue('stored_token', 0));
        $tokenize_card = boolval(Tools::getValue('tokenize_card', 0));

        $order_details = new Order((int) ($id_order));

        /**
         * Check if the requested order is linked to the customer
         */
        if ($order_details->id_customer != $cart->id_customer) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        /**
         * Redirect the customer to the order confirmation page
         */
        $redirectionFormData = Cardlink_Checkout\PaymentHelper::getFormDataForOrder(
            $payment_method,
            $customer,
            $order_details,
            $installments,
            $stored_token,
            $tokenize_card
        );

        $this->context->smarty->assign([
            'action' => Cardlink_Checkout\PaymentHelper::getPaymentGatewayDataPostUrl(),
            'form_data' => $redirectionFormData,
            'css_url' => $this->module->getPathUri() . 'views/css/front-custom.css',
            'use_iframe' => boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0'))
        ]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $this->setTemplate('module:cardlink_checkout/views/templates/front/redirection_form.tpl');
    }
}