<?php
/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment OK Controller
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */


class Cardlink_CheckoutPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        $this->display_column_left = false;
        parent::initContent();

        $this->context->smarty->assign([
            'error' => Tools::getValue('message')
        ]);

        $status = Tools::getValue('status');

        if (
            $status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED
            || $status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED
        ) {
            $this->setTemplate('module:cardlink_checkout/views/templates/front/payment_success.tpl');
        } elseif ($status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED) {
            $this->setTemplate('module:cardlink_checkout/views/templates/front/payment_canceled.tpl');
        } elseif ($status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_REFUSED) {
            $this->setTemplate('module:cardlink_checkout/views/templates/front/payment_denied.tpl');
        } elseif ($status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_ERROR) {
            $this->setTemplate('module:cardlink_checkout/views/templates/front/payment_error.tpl');
        } else {
            Tools::redirect($this->context->shop->getBaseURL(true));
        }
    }
}