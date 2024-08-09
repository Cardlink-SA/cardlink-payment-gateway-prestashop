<?php

use \Cardlink_Checkout\Constants;

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
        \Cardlink_Checkout\PaymentHelper::forceSessionCookieSameSiteNone();

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        if ($cart->id == null) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect(self::ERROR_REDIRECT_URI);
        }

        $payment_method = Tools::getValue('payment_method', 'card');
        $installments = intval(Tools::getValue('installments', 0));
        $stored_token = intval(Tools::getValue('stored_token', 0));
        $tokenize_card = boolval(Tools::getValue('tokenize_card', 0));

        /**
         * Redirect the customer to the order confirmation page
         */
        $redirectionFormData = Cardlink_Checkout\PaymentHelper::getFormDataForOrder(
            $payment_method,
            $customer,
            $cart,
            $installments,
            $stored_token,
            $tokenize_card
        );

        if ($payment_method == 'IRIS') {
            $businessPartner = Configuration::get(Constants::CONFIG_IRIS_BUSINESS_PARTNER, null, null, null, Constants::BUSINESS_PARTNER_NEXI);
            $transactionEnvironment = Configuration::get(Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT, null, null, null, Constants::TRANSACTION_ENVIRONMENT_PRODUCTION);
        } else {
            $businessPartner = Configuration::get(Constants::CONFIG_BUSINESS_PARTNER, null, null, null, Constants::BUSINESS_PARTNER_CARDLINK);
            $transactionEnvironment = Configuration::get(Constants::CONFIG_TRANSACTION_ENVIRONMENT, null, null, null, Constants::TRANSACTION_ENVIRONMENT_PRODUCTION);
        }

        $this->context->smarty->assign([
            'action' => Cardlink_Checkout\PaymentHelper::getPaymentGatewayDataPostUrl($businessPartner, $transactionEnvironment),
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