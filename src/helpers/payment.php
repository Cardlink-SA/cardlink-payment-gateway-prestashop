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
use Db;
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

    public static function getPaidOrderStatuses()
    {
        $db = Db::getInstance();
        $paidStatuses = [
            Configuration::get('PS_OS_PAYMENT'), // Payment accepted
            // Add other status IDs if you have custom statuses indicating payment
        ];

        $sql = 'SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1';
        $results = $db->executeS($sql);

        foreach ($results as $result) {
            $paidStatuses[] = $result['id_order_state'];
        }

        return $paidStatuses;
    }


    /**
     * Returns the payment gateway redirection URL the configured Business Partner and the transactions environment.
     *
     * @return string The URL of the payment gateway.
     */
    public static function getPaymentGatewayDataPostUrl($businessPartner, $transactionEnvironment)
    {
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
    private static function getTransactionReturnUrl($customer, $cart)
    {
        $redirectParameters = [
            'key' => $customer->secure_key
        ];

        $id_lang = $cart->id_lang;

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

    public static function getFormDataForOrder($payment_method, $customer, $cart, $installments, $stored_token, $tokenize_card)
    {
        $billing_details = new Address(intval($cart->id_address_invoice));
        $shipping_details = new Address(intval($cart->id_address_delivery));

        $billing_country = new Country(intval($billing_details->id_country));
        $billing_state = new State(intval($billing_details->id_state));
        $shipping_country = new Country(intval($shipping_details->id_country));
        $shipping_state = new State(intval($shipping_details->id_state));
        $currency = new Currency(intval($cart->id_currency), null, intval($cart->id_shop));

        global $cookie;
        $idLang = (int) $cookie->id_lang;

        $shop = Context::getContext()->shop;
        $idShopGroup = (int) $shop->id_shop_group;
        $idShop = (int) $shop->id;

        $enableIrisPayments = Cardlink_Checkout\Constants::ENABLE_IRIS_PAYMENTS && boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE, $idLang, $idShopGroup, $idShop, '0'));

        if ($payment_method == 'IRIS' && $enableIrisPayments) {
            $merchantId = Configuration::get(Constants::CONFIG_IRIS_MERCHANT_ID);
            $sharedSecret = Configuration::get(Constants::CONFIG_IRIS_SHARED_SECRET);
        } else {
            $merchantId = Configuration::get(Constants::CONFIG_MERCHANT_ID);
            $sharedSecret = Configuration::get(Constants::CONFIG_SHARED_SECRET);
        }

        $sellerId = trim(Configuration::get(Constants::CONFIG_IRIS_SELLER_ID));
        $acceptsInstallments = Configuration::get(Constants::CONFIG_ACCEPT_INSTALLMENTS) != Constants::ACCEPT_INSTALLMENTS_NO;

        // Get the total amount including taxes, shipping, and discounts
        $total_amount = $cart->getOrderTotal(true, Cart::BOTH);

        // Version number - must be '2'
        $formData[ApiFields::Version] = '2';
        // Device category - always '0'
        $formData[ApiFields::DeviceCategory] = '0';
        //// Maximum number of payment retries - set to 10
        //$formData[ApiFields::MaxPayRetries] = '10';

        // The Merchant ID
        $formData[ApiFields::MerchantId] = $merchantId;

        $returnUrl = self::getTransactionReturnUrl($customer, $cart);

        // Transaction success/failure return URLs
        $formData[ApiFields::ConfirmUrl] = $returnUrl;
        $formData[ApiFields::CancelUrl] = $returnUrl;

        // Order information
        $formData[ApiFields::OrderId] = $cart->id . 'x' . date("His");
        $formData[ApiFields::OrderAmount] = floatval($total_amount); // Get order total amount
        $formData[ApiFields::Currency] = $currency->iso_code; // Get order currency code

        if ($payment_method == 'IRIS' && $enableIrisPayments) {
            $formData[ApiFields::PaymentMethod] = 'IRIS';
            // The type of transaction to perform (Sale/Authorize).
            $formData[ApiFields::TransactionType] = '1';

            if (Configuration::get(Constants::CONFIG_IRIS_BUSINESS_PARTNER, null, null, null, Constants::BUSINESS_PARTNER_NEXI) == Constants::BUSINESS_PARTNER_NEXI) {
                $formData[ApiFields::OrderDescription] = self::generateIrisRFCode($sellerId, $formData[ApiFields::OrderId], $formData[ApiFields::OrderAmount]);
            }
        } else {
            $formData[ApiFields::OrderDescription] = 'CART ' . $cart->id;

            // The type of transaction to perform (Sale/Authorize).
            $formData[ApiFields::TransactionType] = self::getTransactionTypeValue();
        }

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

        if ($payment_method == 'IRIS' && $enableIrisPayments) {
            $cssUrl = trim(Configuration::get(Constants::CONFIG_IRIS_CSS_URL));
        } else {
            $cssUrl = trim(Configuration::get(Constants::CONFIG_CSS_URL));
        }

        if ($cssUrl != '' && substr($cssUrl, 0, strlen('https://')) == 'https://') {
            $formData[ApiFields::CssUrl] = $cssUrl;
        }

        // Instruct the payment gateway to use the store language for its UI.
        if (boolval(Configuration::get(Constants::CONFIG_FORCE_STORE_LANGUAGE))) {
            $iso_code = Language::getIsoById(intval($cart->id_lang));
            $formData[ApiFields::Language] = explode('_', $iso_code)[0];
        }

        if ($payment_method != 'IRIS') {
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
     * Generate the Request Fund (RF) code for IRIS payments.
     * @param string $diasCustomerCode The DIAS customer code of the merchant.
     * @param mixed $orderId The ID of the order.
     * @param mixed $amount The amount due.
     * @return string The generated RF code.
     */
    public static function generateIrisRFCode(string $diasCustomerCode, $orderId, $amount)
    {
        /* calculate payment check code */
        $paymentSum = 0;

        if ($amount > 0) {
            $ordertotal = str_replace([','], '.', (string) $amount);
            $ordertotal = number_format($ordertotal, 2, '', '');
            $ordertotal = strrev($ordertotal);
            $factor = [1, 7, 3];
            $idx = 0;
            for ($i = 0; $i < strlen($ordertotal); $i++) {
                $idx = $idx <= 2 ? $idx : 0;
                $paymentSum += $ordertotal[$i] * $factor[$idx];
                $idx++;
            }
        }

        $orderIdNum = (int) filter_var($orderId, FILTER_SANITIZE_NUMBER_INT);

        $randomNumber = substr(str_pad($orderIdNum, 13, '0', STR_PAD_LEFT), -13);
        $paymentCode = $paymentSum ? ($paymentSum % 8) : '8';
        $systemCode = '12';
        $tempCode = $diasCustomerCode . $paymentCode . $systemCode . $randomNumber . '271500';
        $mod97 = bcmod($tempCode, '97');

        $cd = 98 - (int) $mod97;
        $cd = str_pad((string) $cd, 2, '0', STR_PAD_LEFT);
        $rf_payment_code = 'RF' . $cd . $diasCustomerCode . $paymentCode . $systemCode . $randomNumber;

        return $rf_payment_code;
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
     * Validate the response data of the payment gateway for Alpha Bonus transactions 
     * by recalculating and comparing the data digests in order to identify legitimate incoming request.
     * 
     * @param array $formData The payment gateway response data.
     * @param string $sharedSecret The shared secret code of the merchant.
     * 
     * @return bool Identifies that the incoming data were sent by the payment gateway.
     */
    public static function validateXlsBonusResponseData($formData, $sharedSecret)
    {
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_RESPONSE_XLSBONUS_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if ($field != ApiFields::XlsBonusDigest) {
                if (array_key_exists($field, $formData)) {
                    $concatenatedData .= $formData[$field];
                }
            }
        }

        $concatenatedData .= $sharedSecret;
        $generatedDigest = self::GenerateDigest($concatenatedData);

        return $formData[ApiFields::XlsBonusDigest] == $generatedDigest;
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

    public static function forceSessionCookieSameSiteNone()
    {
        @session_set_cookie_params(['samesite' => 'None', 'secure' => true]);
        $sessionStarted = @session_start();

        if (!$sessionStarted) {
            header("Content-Type: text/plain");
            echo "Failed to start session";
            die();
        }

        // Get the session cookie parameters
        $params = session_get_cookie_params();

        // Manually set the session cookie with SameSite=None
        setcookie(
            session_name(), // The name of the session cookie
            session_id(),   // The session ID
            [
                'expires' => $params['lifetime'] ? time() + $params['lifetime'] : 0,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => true, // SameSite=None requires Secure to be true
                'httponly' => $params['httponly'],
                'samesite' => 'None'
            ]
        );
    }
}
