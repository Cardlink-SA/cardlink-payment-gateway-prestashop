<?php

use PrestaShop\PrestaShop\Adapter\ServiceLocator;

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Redirection Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

class Cardlink_CheckoutCronModuleFrontController extends ModuleFrontController
{
    /** @var bool If set to true, will be redirected to authentication page */
    public $auth = false;

    public function initContent()
    {
        $this->ajax = 1;
        $orders_canceled = 0;

        $entityManager = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Foundation\\Database\\EntityManager');

        $orders = $entityManager->getRepository('Order')
            ->findBy([
                'current_state' => Configuration::get('PS_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT'),
                'module' => Cardlink_Checkout\Constants::MODULE_NAME
            ]);

        foreach ($orders as $order) {
            // Cancel orders not paid after one hour
            if (strtotime($order->date_add) < time() - 1 * 3600) {
                Cardlink_Checkout\PaymentHelper::markCanceledPayment($this->module, $order, false);
                $orders_canceled++;
            }
        }

        $this->ajaxRender($orders_canceled);
    }
}
