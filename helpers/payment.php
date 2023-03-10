<?php

namespace Cardlink_Checkout;

use Address;
use Cardlink_Checkout;
use Cart;
use CartRule;
use Configuration;
use Context;
use Country;
use Currency;
use DbQuery;
use Exception;
use Language;
use Module;
use Order;
use OrderHistory;
use State;
use Validate;

require_once(dirname(__FILE__) . '/../apifields.php');

/**
 * Helper class containing methods to handle payment related functionalities.
 * 
 * @author Cardlink S.A.
 */
class PaymentHelper
{
    // /**
    //  * Gets the URL of the Cardlink payment gateway according to the configured business partner and transaction environment.
    //  * 
    //  * @return string
    //  */
    // function getPaymentGatewayUrl()
    // {
    //     return Mage::getUrl('cardlink_checkout/payment/gateway', array('_secure' => true));
    // }

    /**
     * Returns the payment gateway redirection URL the configured Business Partner and the transactions environment.
     *
     * @return string The URL of the payment gateway.
     */
    public static function getPaymentGatewayDataPostUrl()
    {
        $businessPartner = Configuration::get(Constants::CONFIG_BUSINESS_PARTNER, null, null, null, Constants::BUSINESS_PARTNER_CARDLINK);
        $transactionEnvironment = Configuration::get(Constants::CONFIG_TRANSACTION_ENVIRONMENT, null, null, null, Constants::TRANSACTION_ENVIRONMENT_PRODUCTION);

        if ($transactionEnvironment == Constants::TRANSACTION_ENVIRONMENT_PRODUCTION) {
            switch ($businessPartner) {
                case Constants::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/shophandlermpi';

                case Constants::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/shophandlermpi';

                case Constants::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/shophandlermpi';

                default:
            }
        } else {
            switch ($businessPartner) {
                case Constants::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/shophandlermpi';

                case Constants::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/shophandlermpi';

                case Constants::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi';

                default:
            }
        }
        return NULL;
    }

    /**
     * Returns the maximum number of installments according to the order amount.
     * 
     * @param float|string $orderAmount The total amount of the order to be used for calculating the maximum number of installments.
     * @return int The maximum number of installments.
     */
    public static function getMaxInstallments($orderAmount)
    {
        $maxInstallments = 0;
        $acceptsInstallments = Configuration::get(Constants::CONFIG_ACCEPT_INSTALLMENTS, Constants::ACCEPT_INSTALLMENTS_NO);

        if ($acceptsInstallments == Constants::ACCEPT_INSTALLMENTS_FIXED) {
            $maxInstallments = (int) Configuration::get(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, 0);
        } else if ($acceptsInstallments == Constants::ACCEPT_INSTALLMENTS_ORDER_AMOUNT) {
            $ranges = new \PrestaShopCollection(Cardlink_Checkout\Installments::class);
            $ranges->orderBy('min_amount');

            if (count($ranges) > 0) {

                foreach ($ranges as $range) {
                    $min_amount = (float) $range->min_amount;
                    $max_amount = (float) $range->max_amount;

                    if (
                        $min_amount <= $orderAmount
                        && (
                            ($max_amount > 0 && $max_amount >= $orderAmount)
                            || $max_amount == 0
                        )
                    ) {
                        $maxInstallments = (int) $range->max_installments;
                    }
                }
            }
        }

        return $maxInstallments;
    }

    /**
     * Returns the URL that the customer will be redirected after a successful/failed/canceled payment transaction.
     * 
     * @return string The URL of the checkout payment success page.
     */
    private static function getTransactionReturnUrl($customer, $order_details)
    {
        $redirectParameters = [
            'key' => $customer->secure_key
        ];

        $id_lang = $order_details->id_lang;

        $url = Context::getContext()->link->getModuleLink(Constants::MODULE_NAME, 'response', $redirectParameters, true, $id_lang);
        return $url;
    }

    /**
     * Returns the required payment gateway's API value for the transaction type (trType) property.
     * 
     * @return string '1' for Sale/Capture, '2' for Authorize.
     */
    private static function getTransactionTypeValue()
    {
        $transactionType = Configuration::get(Constants::CONFIG_PAYMENT_ACTION, null, null, null, Constants::PAYMENT_ACTION_SALE);
        switch ($transactionType) {
            case Constants::PAYMENT_ACTION_SALE:
                return '1';

            case Constants::PAYMENT_ACTION_AUTHORIZE:
                return '2';
        }
    }

    /**
     * Loads the order information for order ID.
     * 
     * @param int|string $orderId The entity ID of the order.
     * @return array An associative array containing the data that will be sent to the payment gateway's API endpoint to perform the requested transaction.
     */
    public static function getFormDataForOrder($customer, $order_details, $installments, $stored_token, $tokenize_card)
    {
        $id_order = intval($order_details->id);
        $billing_details = new Address(intval($order_details->id_address_invoice));
        $shipping_details = new Address(intval($order_details->id_address_delivery));

        $billing_country = new Country(intval($billing_details->id_country));
        $billing_state = new State(intval($billing_details->id_state));
        $shipping_country = new Country(intval($shipping_details->id_country));
        $shipping_state = new State(intval($shipping_details->id_state));
        $currency = new Currency(intval($order_details->id_currency), null, intval($order_details->id_shop));

        $merchantId = Configuration::get(Constants::CONFIG_MERCHANT_ID);
        $sharedSecret = Configuration::get(Constants::CONFIG_SHARED_SECRET);

        $acceptsInstallments = Configuration::get(Constants::CONFIG_ACCEPT_INSTALLMENTS) != Constants::ACCEPT_INSTALLMENTS_NO;

        // Version number - must be '2'
        $formData[ApiFields::Version] = '2';
        // Device category - always '0'
        $formData[ApiFields::DeviceCategory] = '0';
        //// Maximum number of payment retries - set to 10
        //$formData[ApiFields::MaxPayRetries] = '10';

        // The Merchant ID
        $formData[ApiFields::MerchantId] = $merchantId;

        // The type of transaction to perform (Sale/Authorize).
        $formData[ApiFields::TransactionType] = self::getTransactionTypeValue();

        $returnUrl = self::getTransactionReturnUrl($customer, $order_details);

        // Transaction success/failure return URLs
        $formData[ApiFields::ConfirmUrl] = $returnUrl;
        $formData[ApiFields::CancelUrl] = $returnUrl;

        // Order information
        $formData[ApiFields::OrderDescription] = "ORDER ${id_order}";
        $formData[ApiFields::OrderId] = $id_order;
        $formData[ApiFields::OrderAmount] = floatval($order_details->total_paid); // Get order total amount
        $formData[ApiFields::Currency] = $currency->iso_code; // Get order currency code

        // Payer/customer information
        $formData[ApiFields::PayerEmail] = $customer->email;
        $formData[ApiFields::PayerPhone] = $billing_details->phone;

        // Billing information
        $formData[ApiFields::BillCountry] = $billing_country->iso_code;
        // if ($billing_state->id != null) {
        //     $formData[ApiFields::BillState] = $billing_state->iso_code;
        // }
        $formData[ApiFields::BillZip] = $billing_details->postcode;
        $formData[ApiFields::BillCity] = $billing_details->city;
        $formData[ApiFields::BillAddress] = $billing_details->address1;

        // Shipping information
        $formData[ApiFields::ShipCountry] = $shipping_country->iso_code;
        // if ($billing_state->id != null) {
        //     $formData[ApiFields::ShipState] = $shipping_state->iso_code;
        // }
        $formData[ApiFields::ShipZip] = $shipping_details->postcode;
        $formData[ApiFields::ShipCity] = $shipping_details->city;
        $formData[ApiFields::ShipAddress] = $shipping_details->address1;

        // The optional URL of a CSS file to be included in the pages of the payment gateway for custom formatting.
        $cssUrl = trim(Configuration::get(Constants::CONFIG_CSS_URL));
        if ($cssUrl != '' && substr($cssUrl, 0, strlen('https://')) == 'https://') {
            $formData[ApiFields::CssUrl] = $cssUrl;
        }

        // Instruct the payment gateway to use the store language for its UI.
        if (boolval(Configuration::get(Constants::CONFIG_FORCE_STORE_LANGUAGE))) {
            $iso_code = Language::getIsoById(intval($order_details->id_lang));
            $formData[ApiFields::Language] = explode('_', $iso_code)[0];
        }
        // Installments information.
        if ($acceptsInstallments && $installments > 1) {
            $formData[ApiFields::ExtInstallmentoffset] = 0;
            $formData[ApiFields::ExtInstallmentperiod] = $installments;
        }

        // Tokenization
        if (boolval(Configuration::get(Constants::CONFIG_ALLOW_TOKENIZATION, '0'))) {
            if ($stored_token > 0) {
                $storedToken = new Cardlink_Checkout\StoredToken($stored_token);

                if ($storedToken != null && $storedToken->isValid() && $storedToken->id_customer == $customer->id) {
                    $formData[ApiFields::ExtTokenOptions] = 100;
                    $formData[ApiFields::ExtToken] = $storedToken->token;
                }
            } else if ($tokenize_card) {
                $formData[ApiFields::ExtTokenOptions] = 100;
            }
        }

        // Calculate the digest of the transaction request data and append it.
        $signedFormData = self::signRequestFormData($formData, $sharedSecret);

        // if ($helper->logDebugInfoEnabled()) {
        //     $helper->logMessage("Valid payment request created for order {$signedFormData[ApiFields::OrderId]}.");
        //     $helper->logMessage($signedFormData);
        // }

        return $signedFormData;
    }

    /**
     * Sign a bank request with the merchant's shared key and insert the digest in the data.
     * 
     * @param array $formData The payment request data.
     * @param string $sharedSecret The shared secret code of the merchant.
     * 
     * @return array The original request data put in proper order including the calculated data digest.
     */
    public static function signRequestFormData($formData, $sharedSecret)
    {
        $ret = [];
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_REQUEST_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if (array_key_exists($field, $formData)) {
                $ret[$field] = trim($formData[$field]);
                $concatenatedData .= $ret[$field];
            }
        }

        $concatenatedData .= $sharedSecret;
        $ret[ApiFields::Digest] = self::generateDigest($concatenatedData);

        return $ret;
    }

    /**
     * Validate the response data of the payment gateway by recalculating and comparing the data digests in order to identify legitimate incoming request.
     * 
     * @param array $formData The payment gateway response data.
     * @param string $sharedSecret The shared secret code of the merchant.
     * 
     * @return bool Identifies that the incoming data were sent by the payment gateway.
     */
    public static function validateResponseData($formData, $sharedSecret)
    {
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_RESPONSE_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if ($field != ApiFields::Digest) {
                if (array_key_exists($field, $formData)) {
                    $concatenatedData .= $formData[$field];
                }
            }
        }

        $concatenatedData .= $sharedSecret;
        $generatedDigest = self::GenerateDigest($concatenatedData);

        return $formData[ApiFields::Digest] == $generatedDigest;
    }

    /**
     * Generate the message digest from a concatenated data string.
     * 
     * @param string $concatenatedData The data to calculate the digest for.
     */
    public static function generateDigest($concatenatedData)
    {
        return base64_encode(hash('sha256', $concatenatedData, true));
    }

    /**
     * Mark an order as canceled, store additional payment information and restore the user's cart.
     * 
     * @param Order The order object.
     * @param array The data from the payment gateway's response.
     */
    public static function markSuccessfulPayment(Module $module, Order $order_details, $responseData)
    {
        $errors = [];

        if (
            $order_details->current_state == Configuration::get('PS_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT')
            || $order_details->current_state == Configuration::get('CARDLINK_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT')
        ) {
            $order_details->addOrderPayment(
                $order_details->total_paid,
                null,
                $responseData[ApiFields::TransactionId]
            );

            $history = new OrderHistory();
            $history->id_order = (int) $order_details->id;

            if ($responseData[ApiFields::Status] === Constants::TRANSACTION_STATUS_AUTHORIZED) {
                $history->changeIdOrderState(
                    (int) Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, null, null, null, Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED')),
                    (int) ($order_details->id),
                    true
                );
            } else if ($responseData[ApiFields::Status] === Constants::TRANSACTION_STATUS_CAPTURED) {
                $history->changeIdOrderState(
                    (int) Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, null, null, null, Configuration::get('PS_OS_PAYMENT')),
                    (int) ($order_details->id),
                    true
                );
            }

            $history->addWithEmail(true);
            $history->save();
        }

        return $errors;
    }

    /**
     * Mark an order as canceled, store additional payment information and restore the user's cart.
     * 
     * @param Order $order_details The order object.
     * @param bool $restoreCart Define whether to restore the user's cart from the order.
     */
    public static function markCanceledPayment(Module $module, Order $order_details, $restoreCart = false)
    {
        $errors = [];

        $id_canceled_state = Configuration::get('PS_OS_CANCELED');

        if ($order_details->current_state != $id_canceled_state) {
            // Cancel order and revert cart contents.
            $history = new OrderHistory();
            $history->id_order = (int) $order_details->id;
            $history->changeIdOrderState($id_canceled_state, (int) ($order_details->id));
            $history->save();

            if ($restoreCart) {
                $errors = self::restoreCart($module, $order_details);
            }
        }

        return $errors;
    }

    /**
     * Restore last active quote based on checkout session
     *
     * @return bool True if quote restored successfully, false otherwise
     */
    public static function restoreCart(Module $module, Order $order_details)
    {
        $errors = [];

        $id_order = (int) $order_details->id;
        $oldCart = new Cart(Order::getCartIdStatic($id_order, $order_details->id_customer));
        $duplication = $oldCart->duplicate();

        if (!$duplication || !Validate::isLoadedObject($duplication['cart'])) {
            $errors[] = $module->l('Sorry. We cannot renew your order.', 'cardlink_checkout');
        } elseif (!$duplication['success']) {
            $errors[] = $module->l('Some items are no longer available, and we are unable to renew your order.', 'cardlink_checkout');
        } else {
            try {
                $context = Context::getContext();
                $context->cookie->id_cart = $duplication['cart']->id;
                $context->cart = $duplication['cart'];
                CartRule::autoAddToCart($context);
                $context->cookie->write();
            } catch (Exception $e) {
            }
        }

        return $errors;
    }
}