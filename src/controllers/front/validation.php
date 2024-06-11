<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Order Validation Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $authorized = false;

        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (
            !$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'cardlink_checkout') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->l('This payment method is not available.', 'cardlink_checkout'));
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $total_order = (float) $this->context->cart->getOrderTotal(true, Cart::BOTH);

        /**
         * Place the order
         */

        $orderState = 0;
        $pendingPaymentOrderStates = Cardlink_Checkout\PaymentHelper::getPendingPaymentOrderStates();

        foreach ($pendingPaymentOrderStates as $val) {
            if ($val !== false) {
                $orderState = $val;
                break;
            }
        }

        $this->module->validateOrder(
            (int) $this->context->cart->id,
            $orderState,
            $total_order,
            $this->module->displayName,
            null,
            [],
            (int) $this->context->currency->id,
            false,
            $customer->secure_key
        );

        $payment_method = Tools::getValue('payment_method', 'card');
        $installments = (int) Tools::getValue('installments', '0');
        $stored_token = Tools::getValue('stored_token', '0');
        $tokenize_card = boolval(Tools::getValue('tokenize_card', '0'));
        $max_installments = Cardlink_Checkout\PaymentHelper::getMaxInstallments($total_order);

        $redirectParameters = [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => $this->module->currentOrder,
            'key' => $customer->secure_key,
            'payment_method' => $payment_method,
            'installments' => min($installments, $max_installments),
            'stored_token' => $stored_token,
            'tokenize_card' => $tokenize_card
        ];

        $redirectUrl = $this->context->link->getModuleLink($this->module->name, 'redirection', $redirectParameters, true);

        if (boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0'))) {
            $this->context->smarty->assign([
                'src' => $redirectUrl
            ]);
            $this->setTemplate('module:cardlink_checkout/views/templates/front/iframe.tpl');
        } else {
            Tools::redirect($redirectUrl);
        }
    }
}