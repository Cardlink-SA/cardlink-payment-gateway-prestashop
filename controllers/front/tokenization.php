<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Redirection Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use Symfony\Component\HttpFoundation\JsonResponse;

class Cardlink_CheckoutTokenizationModuleFrontController extends ModuleFrontController
{
    /** @var bool If set to true, will be redirected to authentication page */
    public $auth = true;

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
            return new JsonResponse([
                'hasError' => true
            ], 403);
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        $stored_token = new Cardlink_Checkout\StoredToken((int)Tools::getValue('token_id', '0'));

        if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
            if ($stored_token != null && $stored_token->id > 0 && $stored_token->id_customer == $customer->id) {
                $stored_token->active = false;
                $stored_token->save();

                return new JsonResponse([
                    'hasError' => false
                ], 200);
            }
        }

        return new JsonResponse([
            'hasError' => true
        ], 404);
    }
}
