<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * This file is the declaration of the module.
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(dirname(__FILE__) . '/constants.php');
require_once(dirname(__FILE__) . '/apifields.php');
require_once(dirname(__FILE__) . '/helpers/payment.php');
require_once(dirname(__FILE__) . '/models/Installments.php');
require_once(dirname(__FILE__) . '/models/StoredToken.php');


class Cardlink_Checkout extends PaymentModule
{
    /**
     * Cardlink Checkout constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name = Cardlink_Checkout\Constants::MODULE_NAME;
        $this->tab = 'payments_gateways';
        $this->version = '1.0.6';
        $this->author = 'Cardlink S.A.';
        $this->controllers = array('payment', 'validation');
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'Cardlink Checkout';
        $this->description = 'Cardlink Payment Gateway (Redirect Mode)';
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
        $this->is_eu_compatible = 1;

        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('backOfficeHeader')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionEmailSendBefore')
            && $this->createWaitingPaymentOrderStateIfMissing()
            && Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS . '` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `min_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                `max_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                `max_installments` INT(4) NOT NULL DEFAULT "0",
                `date_add` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `date_upd` DATETIME ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')

            && Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_STORED_TOKENS . '` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_customer` INT(10) UNSIGNED NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT "1",
                `token` VARCHAR(50) DEFAULT NULL,
                `type` VARCHAR(20) NOT NULL,
                `last_4digits` CHAR(4) NOT NULL,
                `expiration` CHAR(8) NOT NULL,
                `date_add` DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `IDX_id_customer_active_expiration` (`id_customer`, `active`, `expiration`),
                UNIQUE KEY `IDX_unique_card` (`id_customer`, `token`, `type`, `last_4digits`, `expiration`),
                FOREIGN KEY (`id_customer`) REFERENCES `' . _DB_PREFIX_ . 'customer` (`id_customer`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall()
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS . '`;')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_STORED_TOKENS . '`;');
    }

    public function hookHeader($params)
    {
        $this->context->controller->addCSS($this->_path . 'views/css/front-custom.css', 'all', null, true);
    }

    public function hookBackOfficeHeader($params)
    {
        $this->context->controller->addJS($this->_path . 'views/js/admin-custom.js', true);
    }

    /**
     * Do not send order confirmation email to customer if status is set to "waiting payment".
     */
    public function hookActionEmailSendBefore($params)
    {
        if ($params['template'] === 'order_conf') {
            $order_id = (int) $params['templateVars']['{id_order}'];

            $order_details = new Order($order_id);

            if (
                Validate::isLoadedObject($order_details)
                && $order_details->module == Cardlink_Checkout\Constants::MODULE_NAME
                && ($order_details->current_state == Configuration::get('PS_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT')
                    || $order_details->current_state == Configuration::get('CARDLINK_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT'))
            ) {
                return false;
            }
        }
        return true;
    }

    private function createWaitingPaymentOrderStateIfMissing()
    {
        $defaultPsCheckoutWaitingStateExists = Configuration::get('PS_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT');
        $cardlinkPsCheckoutWaitingStateExists = Configuration::get('CARDLINK_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT');

        // If the state does not exist, we create it.
        if (!$defaultPsCheckoutWaitingStateExists && !$cardlinkPsCheckoutWaitingStateExists) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#34209E';
            $order_state->unremovable = true;
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = array();
            $order_state->name = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $order_state->name[$language['id_lang']] = $this->l('Waiting for Credit Card Payment', false, $language['locale']);
                $order_state->template[$language['id_lang']] = 'payment';
            }
            // Update object
            if ($order_state->add()) {
                $source = dirname(__FILE__) . '/icons/card-inserting-16.gif';
                $destination = dirname(__FILE__) . '/../../img/os/' . (int) $order_state->id . '.gif';
                copy($source, $destination);

                Configuration::updateValue('CARDLINK_CHECKOUT_STATE_WAITING_CREDIT_CARD_PAYMENT', $order_state->id);
            }
        }

        return true;
    }

    private function getPostedMultilingualValue($baseName)
    {
        $value = [];
        foreach (Language::getLanguages(false) as $k => $language) {
            $langId = (int) $language['id_lang'];

            $value[$langId] = Tools::getValue($baseName . '_' . $langId);
        }
        return $value;
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // this part is executed only when the form is submitted
        if (Tools::isSubmit('submit' . $this->name)) {
            // // retrieve the configuration values set by the user
            $title = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_TITLE, '');
            $description = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, '');
            $orderStatusCaptured = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, Configuration::get('PS_OS_PAYMENT'));
            $orderStatusAuthorized = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED'));
            $businessPartner = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER, Cardlink_Checkout\Constants::BUSINESS_PARTNER_CARDLINK);
            $merchantId = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID, '');
            $sharedSecret = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, '');
            $transactionEnvironment = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
            $paymentAction = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION, Cardlink_Checkout\Constants::PAYMENT_ACTION_SALE);
            $acceptInstallments = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS, Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_NO);
            $fixedInstallments = max(0, min(60, Tools::getValue(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, '3')));
            $allowTokenization = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, '0');
            $iframeCheckout = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0');
            $forceStoreLanguage = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, '0');
            $displayLogo = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, '1');
            $cssUrl = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_CSS_URL, '');

            if (substr($cssUrl, 0, strlen('https://')) != 'https://') {
                $cssUrl = '';
            }

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_TITLE, $title);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $description);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, $orderStatusCaptured);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, $orderStatusAuthorized);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER, $businessPartner);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID, $merchantId);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, $sharedSecret);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT, $transactionEnvironment);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION, $paymentAction);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS, $acceptInstallments);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, $fixedInstallments);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, $allowTokenization);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, $iframeCheckout);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, $forceStoreLanguage);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, $displayLogo);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_CSS_URL, $cssUrl);

            $output = $this->displayConfirmation($this->l('Settings updated'));
        }

        // display any message, then the form
        return $output . $this->displayForm();
    }

    /**
     * Builds the configuration form
     * @return string HTML code
     */
    public function displayForm()
    {
        $retCron = '<div class="panel col-lg-12">';
        $retCron .= '<div class="panel-heading">' . $this->l('Cron Configuration', false, $this->context->language->locale) . '</div>';
        $retCron .= ' <div style="padding-bottom:20px;">' . $this->l('Execute hourly to automatically cancel abandoned orders.', false, $this->context->language->locale) . '</div>';
        $retCron .= ' <div><strong>Cron URL:</strong> ' . Context::getContext()->link->getModuleLink(Cardlink_Checkout\Constants::MODULE_NAME, 'cron', array()) . '</div>';
        $retCron .= ' <div><strong>Cron script:</strong> ' . __DIR__ . DIRECTORY_SEPARATOR . 'cron.php' . '</div>';
        $retCron .= '</div>';

        $retA = $this->renderAdditionalOptionsList();
        $retC = $this->renderConfigurationForm();

        return $retC . $retA . $retCron;
    }

    protected function renderConfigurationForm()
    {
        $states = new OrderState(); //the id is possibly not required, did not try without
        $states2 = $states->getOrderStates((int) Configuration::get('PS_LANG_DEFAULT')); // the id is the language id.
        $order_states = [];

        foreach ($states2 as $state) {
            $order_states[] = [
                'id_option' => $state['id_order_state'],
                'name' => $state['name']
            ];
        }

        // Init Fields form array
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'lang' => true,
                        'name' => Cardlink_Checkout\Constants::CONFIG_TITLE,
                        'label' => $this->l('Title'),
                        'desc' => $this->l('The title of the payment method to be displayed during the checkout.'),
                        'hint' => null,
                        'size' => 50,
                        'maxlength' => 50,
                        'required' => true,
                    ],
                    [
                        'type' => 'textarea',
                        'lang' => true,
                        'name' => Cardlink_Checkout\Constants::CONFIG_DESCRIPTION,
                        'label' => $this->l('Description'),
                        'desc' => $this->l('A short description of the payment method to be displayed during the checkout.'),
                        'hint' => null,
                        'cols' => 50,
                        'rows' => 10,
                        'required' => false,
                    ],
                    [
                        'type' => 'select',
                        'lang' => false,
                        'name' => Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED,
                        'label' => $this->l('Captured Payment Order Status'),
                        'desc' => $this->l('The status of an order after a payment has been successfully captured.'),
                        'hint' => null,
                        'required' => true,
                        'options' => [
                            'query' => $order_states,
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'lang' => false,
                        'name' => Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED,
                        'label' => $this->l('Authorized Payment Order Status'),
                        'desc' => $this->l('The status of an order after a payment has been successfully authorized.'),
                        'hint' => null,
                        'required' => true,
                        'options' => [
                            'query' => $order_states,
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER,
                        'label' => $this->l('Business Partner'),
                        'desc' => $this->l('Identify the business partner that will handle payment transactions as agreed with Cardlink.'),
                        'hint' => null,
                        'required' => true,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => Cardlink_Checkout\Constants::BUSINESS_PARTNER_CARDLINK,
                                    'name' => 'Cardlink'
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::BUSINESS_PARTNER_NEXI,
                                    'name' => 'Nexi'
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE,
                                    'name' => 'Worldline'
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'text',
                        'name' => Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID,
                        'label' => $this->l('Merchant ID'),
                        'desc' => $this->l('The merchant ID provided by Cardlink.'),
                        'hint' => null,
                        'size' => 20,
                        'maxlength' => 20,
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'name' => Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET,
                        'label' => $this->l('Shared Secret'),
                        'desc' => $this->l('The shared secret code provided by Cardlink.'),
                        'hint' => null,
                        'size' => 30,
                        'maxlength' => 30,
                        'required' => true,
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT,
                        'label' => $this->l('Transaction Environment'),
                        'desc' => $this->l('Identify the working environment for payment transactions.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_PRODUCTION,
                                    'name' => $this->l('Production')
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX,
                                    'name' => $this->l('Sandbox')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION,
                        'label' => $this->l('Payment Action'),
                        'desc' => $this->l('Identify the type of transaction to perform. By selecting the "Authorize" option, you will need to manually capture the order amount on Cardlink\'s merchant dashboard.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => Cardlink_Checkout\Constants::PAYMENT_ACTION_SALE,
                                    'name' => $this->l('Finalize Payment')
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::PAYMENT_ACTION_AUTHORIZE,
                                    'name' => $this->l('Authorize')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS,
                        'label' => $this->l('Accept Installments'),
                        'desc' => $this->l('Enable installment payments and define the maximum number of Installments.')
                        . ' ' . $this->l('Always save your settings after changing this option.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_NO,
                                    'name' => $this->l('No Installments')
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_FIXED,
                                    'name' => $this->l('Fixed Maximum Number')
                                ],
                                [
                                    'id_option' => Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_ORDER_AMOUNT,
                                    'name' => $this->l('Based on Order Amount')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'text',
                        'name' => Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS,
                        'label' => $this->l('Fixed Maximum Installments'),
                        'desc' => $this->l('The maximum number of installments available for all orders.') . ' ' . $this->l('Valid values: 0 to 60.'),
                        'hint' => $this->l('Use numeric values.')
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION,
                        'label' => $this->l('Allow Tokenization'),
                        'desc' => $this->l('Allow customers to select whether they want to secure store their payment cards for future checkouts.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '1',
                                    'name' => $this->l('Enabled')
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Disabled')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_USE_IFRAME,
                        'label' => $this->l('Checkout without Leaving Your Store'),
                        'desc' => $this->l('Perform the payment flow without having the customers leave your website for Cardlink\'s payment gateway. You will need to have a valid SSL certificate properly configured on your domain.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '1',
                                    'name' => $this->l('Enabled')
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Disabled')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE,
                        'label' => $this->l('Force Store Language'),
                        'desc' => $this->l('Instruct Cardlink\'s Payment Gateway to use the language of the store that the order gets placed.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '1',
                                    'name' => $this->l('Force store language')
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Autodetect customer language')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'select',
                        'name' => Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO,
                        'label' => $this->l('Display Cardlink Logo'),
                        'desc' => $this->l('Display the Cardlink logo next to the payment method title.'),
                        'hint' => null,
                        'options' => [
                            'query' => [
                                [
                                    'id_option' => '1',
                                    'name' => $this->l('Display Logo')
                                ],
                                [
                                    'id_option' => '0',
                                    'name' => $this->l('Hide Logo')
                                ]
                            ],
                            'id' => 'id_option',
                            'name' => 'name'
                        ]
                    ],
                    [
                        'type' => 'text',
                        'name' => Cardlink_Checkout\Constants::CONFIG_CSS_URL,
                        'label' => $this->l('CSS URL'),
                        'desc' => $this->l('Full URL of custom CSS stylesheet, to be used to display payment page styles.'),
                        'hint' => null,
                        'size' => 50,
                        'maxlength' => 255,
                        'required' => false,
                        'pattern' => 'https://.*'
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->table = $this->table;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->name]);
        $helper->submit_action = 'submit' . $this->name;
        $helper->tpl_vars = array(
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        // Default language
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;

        $languages = $this->context->controller->getLanguages();
        foreach ($languages as $k => $language) {
            $languages[$k]['is_default'] = (int) $language['id_lang'] == $lang->id;
        }
        $helper->languages = $languages;

        $shop = Context::getContext()->shop;
        $idShopGroup = (int) $shop->id_shop_group;
        $idShop = (int) $shop->id;

        // Load current or default values into the form
        foreach ($helper->languages as $language) {
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_TITLE][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TITLE, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay through Cardlink', false, $language['locale']));
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_DESCRIPTION][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay Via Cardlink: Accepts Visa, Mastercard, Maestro, American Express, Diners, Discover.', false, $language['locale']));
        }
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, null, null, null, Configuration::get('PS_OS_PAYMENT'));
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, null, null, null, Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED'));
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER, null, null, null, Cardlink_Checkout\Constants::BUSINESS_PARTNER_CARDLINK);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT, null, null, null, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION, null, null, null, Cardlink_Checkout\Constants::PAYMENT_ACTION_SALE);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS, null, null, null, 'no');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, null, null, null, '3');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_USE_IFRAME] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, null, null, null, '1');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_CSS_URL] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_CSS_URL, null, null, null, '');

        return $helper->generateForm([$form]);
    }

    protected function renderAdditionalOptionsList()
    {
        $acceptsOrderBasedInstallments = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS) == Cardlink_Checkout\Constants::ACCEPT_INSTALLMENTS_ORDER_AMOUNT;

        $this->fields_list = [
            'min_amount' => [
                'type' => 'price',
                'title' => $this->l('Min Amount'),
                'orderby' => true,
                'search' => false,
                'remove_onclick' => !$acceptsOrderBasedInstallments
            ],
            'max_amount' => [
                'type' => 'price',
                'title' => $this->l('Max Amount'),
                'search' => false,
                'orderby' => false,
                'remove_onclick' => !$acceptsOrderBasedInstallments
            ],
            'max_installments' => [
                'type' => 'text',
                'title' => $this->l('Maximum Installments'),
                'search' => false,
                'orderby' => false,
                'width' => 'auto',
                'remove_onclick' => !$acceptsOrderBasedInstallments
            ]
        ];

        $admin_token = Tools::getAdminTokenLite('AdminCardlink_CheckoutInstallmentsManager');
        $linkToInstallmentsRangerManagerController = Context::getContext()->link->getAdminLink('AdminCardlink_CheckoutInstallmentsManager', false);

        $helperList = new HelperList();
        $helperList->token = $admin_token;
        $helperList->title = $this->l('Order Amount Range Installments') . ' ' . (!$acceptsOrderBasedInstallments ? '(' . $this->l('Save your settings first to edit this section') . ')' : '');
        $helperList->table = Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS;
        $helperList->className = Cardlink_Checkout\Installments::class;
        $helperList->identifier = Cardlink_Checkout\Installments::IDENTIFIER;
        $helperList->currentIndex = $linkToInstallmentsRangerManagerController;
        $helperList->actions = $acceptsOrderBasedInstallments ? ['edit', 'delete'] : [];
        $helperList->shopLinkType = '';
        $helperList->show_toolbar = false;
        $helperList->simple_header = false; //For showing add and refresh button

        if ($acceptsOrderBasedInstallments) {
            $helperList->toolbar_btn['new'] = [
                'href' => $linkToInstallmentsRangerManagerController . '&add' . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS . '&token=' . $admin_token,
                'desc' => $this->l('Add Range')
            ];
        }

        $sql = new DbQuery();
        $sql->select('*');
        $sql->from(Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS);
        $sql->orderBy('min_amount');
        $content = Db::getInstance()->ExecuteS($sql);

        $helperList->listTotal = count($content);

        return $helperList->generateList($content, $this->fields_list);
    }

    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }

        $total_cart = $params['cart']->getOrderTotal();
        $customer = $this->context->customer;

        global $cookie;
        $idLang = (int) $cookie->id_lang;

        $shop = Context::getContext()->shop;
        $idShopGroup = (int) $shop->id_shop_group;
        $idShop = (int) $shop->id;

        $title = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TITLE, $idLang, $idShopGroup, $idShop, '');
        $description = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $idLang, $idShopGroup, $idShop, '');
        $maxInstallments = Cardlink_Checkout\PaymentHelper::getMaxInstallments($total_cart);
        $allowsTokenization = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, '0') == '1';
        $customerTokens = [];

        if ($customer->isLogged() && $allowsTokenization) {
            $storedTokens = new \PrestaShopCollection(Cardlink_Checkout\StoredToken::class);
            $storedTokens->where('id_customer', '=', $customer->id);
            $storedTokens->where('active', '=', true);
            $storedTokens->orderBy('expiration', 'DESC');


            foreach ($storedTokens as $storedToken) {
                if ($storedToken->isValid()) {
                    $customerTokens[] = [
                        'id' => $storedToken->id,
                        'type' => $storedToken->type,
                        'type_label' => strtoupper($storedToken->type),
                        'last_digits' => $storedToken->last_4digits,
                        'expires' => $storedToken->getFormattedExpiryDate(),
                        'image_url' => $this->_path . 'views/images/' . $storedToken->type . '.png'
                    ];
                } else {
                    $storedToken->active = false;
                    $storedToken->save();
                }
            }
        }

        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'validation', [], true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign([
            'action' => $formAction,
            'isLoggedIn' => $customer->isLogged(),
            'cardlink_logo_url' => $this->_path . 'views/images/cardlink.svg',
            'title' => $title,
            'description' => trim($description),
            'acceptsInstallments' => $maxInstallments > 1,
            'maxInstallments' => $maxInstallments,
            'allowsTokenization' => $allowsTokenization,
            'storedTokens' => $customerTokens,
            'deleteStoredTokenUrl' => $this->context->link->getModuleLink($this->name, 'tokenization', ['ajax' => true], true)
        ]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:cardlink_checkout/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $paymentOption->setModuleName(Cardlink_Checkout\Constants::MODULE_NAME)
            ->setCallToActionText($title)
            ->setForm($paymentForm)
            //->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/images/cardlink.svg'))
        ;

        return [$paymentOption];
    }

    /**
     * Display a message in the paymentReturn hook
     * 
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:cardlink_checkout/views/templates/hook/payment_return.tpl');
    }

    /**
     * Fetch the content of $template_name inside the folder
     * current_theme/mails/current_iso_lang/ if found, otherwise in
     * mails/current_iso_lang.
     *
     * @param string $template_name template name with extension
     * @param int $mail_type Mail::TYPE_HTML or Mail::TYPE_TEXT
     * @param array $var sent to smarty as 'list'
     *
     * @return string
     */
    public function getEmailTemplateContent($template_name, $mail_type, $var)
    {
        return parent::getEmailTemplateContent($template_name, $mail_type, $var);
    }

    public function createOrderCartRules(
        Order $order,
        Cart $cart,
        $order_list,
        $total_reduction_value_ti,
        $total_reduction_value_tex,
        $id_order_state
    )
    {
        return parent::createOrderCartRules(
            $order,
            $cart,
            $order_list,
            $total_reduction_value_ti,
            $total_reduction_value_tex,
            $id_order_state
        );
    }
}