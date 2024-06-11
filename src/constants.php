<?php

namespace Cardlink_Checkout;

class Constants
{
    public const MODULE_NAME = 'cardlink_checkout';

    public const TABLE_NAME_INSTALLMENTS = 'cardlink_checkout_installments';
    public const TABLE_NAME_STORED_TOKENS = 'cardlink_checkout_stored_tokens';

    public const ENABLE_IRIS_PAYMENTS = true;

    public const CONFIG_TITLE = 'CARDLINK_CHECKOUT_CONFIG_TITLE';
    public const CONFIG_DESCRIPTION = 'CARDLINK_CHECKOUT_CONFIG_DESCRIPTION';
    public const CONFIG_ORDER_STATUS_CAPTURED = 'CARDLINK_CHECKOUT_ORDER_STATUS_CAPTURED';
    public const CONFIG_ORDER_STATUS_AUTHORIZED = 'CARDLINK_CHECKOUT_ORDER_STATUS_AUTHORIZED';
    public const CONFIG_BUSINESS_PARTNER = 'CARDLINK_CHECKOUT_CONFIG_BUSINESS_PARTNER';
    public const CONFIG_MERCHANT_ID = 'CARDLINK_CHECKOUT_CONFIG_MERCHANT_ID';
    public const CONFIG_SHARED_SECRET = 'CARDLINK_CHECKOUT_CONFIG_SHARED_SECRET';
    public const CONFIG_TRANSACTION_ENVIRONMENT = 'CARDLINK_CHECKOUT_CONFIG_TRANSACTION_ENVIRONMENT';
    public const CONFIG_PAYMENT_ACTION = 'CARDLINK_CHECKOUT_CONFIG_PAYMENT_ACTION';
    public const CONFIG_ACCEPT_INSTALLMENTS = 'CARDLINK_CHECKOUT_CONFIG_ACCEPT_INSTALLMENTS';
    public const CONFIG_FIXED_MAX_INSTALLMENTS = 'CARDLINK_CHECKOUT_CONFIG_FIXED_MAX_INSTALLMENTS';
    public const CONFIG_ALLOW_TOKENIZATION = 'CARDLINK_CHECKOUT_CONFIG_ALLOW_TOKENIZATION';
    public const CONFIG_USE_IFRAME = 'CARDLINK_CHECKOUT_CONFIG_USE_IFRAME';
    public const CONFIG_FORCE_STORE_LANGUAGE = 'CARDLINK_CHECKOUT_CONFIG_FORCE_STORE_LANGUAGE';
    public const CONFIG_DISPLAY_LOGO = 'CARDLINK_CHECKOUT_CONFIG_DISPLAY_LOGO';
    public const CONFIG_CSS_URL = 'CARDLINK_CHECKOUT_CONFIG_CSS_URL';
    public const CONFIG_ENABLE_IRIS = 'CARDLINK_CHECKOUT_CONFIG_CONFIG_ENABLE_IRIS';
    public const CONFIG_DIAS_CODE = 'CARDLINK_CHECKOUT_CONFIG_DIAS_CODE';
    public const CONFIG_IRIS_TITLE = 'CARDLINK_CHECKOUT_CONFIG_IRIS_TITLE';
    public const CONFIG_IRIS_DESCRIPTION = 'CARDLINK_CHECKOUT_CONFIG_IRIS_DESCRIPTION';


    public const BUSINESS_PARTNER_CARDLINK = 'cardlink';
    public const BUSINESS_PARTNER_NEXI = 'nexi';
    public const BUSINESS_PARTNER_WORLDLINE = 'worldline';

    public const TRANSACTION_ENVIRONMENT_PRODUCTION = 'production';
    public const TRANSACTION_ENVIRONMENT_SANDBOX = 'sandbox';

    public const PAYMENT_ACTION_SALE = 'sale';
    public const PAYMENT_ACTION_AUTHORIZE = 'authorize';

    public const ACCEPT_INSTALLMENTS_NO = 'no';
    public const ACCEPT_INSTALLMENTS_FIXED = 'fixed';
    public const ACCEPT_INSTALLMENTS_ORDER_AMOUNT = 'order_amount';

    /**
     * The transaction was successfully authorized.
     */
    public const TRANSACTION_STATUS_AUTHORIZED = 'AUTHORIZED';

    /**
     * The transaction was successfully captured (sale finalized).
     */
    public const TRANSACTION_STATUS_CAPTURED = 'CAPTURED';

    /**
     * The transaction was canceled by the customer.
     */
    public const TRANSACTION_STATUS_CANCELED = 'CANCELED';

    /**
     * The transaction was refused by the payment gateway.
     */
    public const TRANSACTION_STATUS_REFUSED = 'REFUSED';

    /**
     * The transaction has generated an error in the payment gateway.
     */
    public const TRANSACTION_STATUS_ERROR = 'ERROR';
}