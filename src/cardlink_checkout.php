<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7, 8.x, 9.x.
 *
 * This file is the declaration of the module.
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use Cardlink_Checkout\PaymentHelper;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(dirname(__FILE__) . '/constants.php');
require_once(dirname(__FILE__) . '/apifields.php');
require_once(dirname(__FILE__) . '/helpers/payment.php');
require_once(dirname(__FILE__) . '/helpers/TransactionOperationHelper.php');
require_once(dirname(__FILE__) . '/helpers/PaymentResponseProcessor.php');
require_once(dirname(__FILE__) . '/helpers/GooglePayHelper.php');
require_once(dirname(__FILE__) . '/helpers/ApplePayHelper.php');
require_once(dirname(__FILE__) . '/models/Installments.php');
require_once(dirname(__FILE__) . '/models/StoredToken.php');
require_once(dirname(__FILE__) . '/models/PaymentTransaction.php');


class Cardlink_Checkout extends PaymentModule
{
    /**
     * Request-scope guard to avoid duplicate native refund sync when
     * multiple refund-related hooks fire in the same request.
     *
     * @var bool
     */
    private static $nativeRefundProcessedInRequest = false;

    /**
     * Cardlink Checkout constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name = Cardlink_Checkout\Constants::MODULE_NAME;
        $this->tab = 'payments_gateways';
        $this->version = '1.3.0';
        $this->author = 'Cardlink S.A.';
        $this->controllers = ['payment', 'validation', 'backgroundconfirmation', 'googlepayajax', 'googlepaywallet', 'googlepay3ds', 'applepayajax', 'applepaywallet', 'applepay3ds'];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->displayName = 'Cardlink Checkout';
        $this->description = 'Cardlink Payment Gateway (Redirect Mode)';
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => '9.99.99');
        $this->is_eu_compatible = 1;

        // Register admin controllers (hidden, not visible in menu)
        $this->tabs = [
            [
                'name' => 'Cardlink Payment Actions',
                'class_name' => 'AdminCardlink_CheckoutPaymentAction',
                'visible' => false,
                'parent_class_name' => 'AdminParentOrders',
            ],
        ];

        parent::__construct();

        $this->ensureCoreHooksRegisteredSafely();
    }

    /**
     * Safely ensure critical hooks are registered for existing installations.
     * Uses guarded calls to avoid back-office failures if registration is not possible.
     */
    private function ensureCoreHooksRegisteredSafely()
    {
        if (empty($this->id)) {
            return;
        }

        $requiredHooks = [
            'displayHeader',
            'moduleRoutes',
            'actionOrderSlipAdd',
            'actionObjectOrderSlipAddAfter',
            'actionFrontControllerSetMedia',
        ];

        foreach ($requiredHooks as $hookName) {
            try {
                if (!$this->isRegisteredInHook($hookName)) {
                    $this->registerHook($hookName);
                }
            } catch (\Exception $e) {
                // Ignore errors to prevent breaking BO pages.
            }
        }
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    { // Get MySQL version
        $mysqlVersion = Db::getInstance()->getVersion();

        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('displayHeader')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('moduleRoutes')
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('actionAdminControllerSetMedia')
            && $this->registerHook('actionAdminOrdersListingFieldsModifier')
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionOrderGridQueryBuilderModifier')
            && $this->registerHook('actionOrderSlipAdd')
            && $this->registerHook('actionObjectOrderSlipAddAfter')
            && (
                version_compare($mysqlVersion, '5.6.5', '<')
                ? Db::getInstance()->execute('
                    CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS . '` (
                        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `min_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                        `max_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                        `max_installments` INT(4) NOT NULL DEFAULT "0",
                        `date_add` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        `date_upd` DATETIME NULL DEFAULT NULL,
                        PRIMARY KEY (`id`)
                    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')
                : Db::getInstance()->execute('
                    CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_INSTALLMENTS . '` (
                        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `min_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                        `max_amount` DECIMAL(20, 2) NOT NULL DEFAULT "0",
                        `max_installments` INT(4) NOT NULL DEFAULT "0",
                        `date_add` DATETIME DEFAULT CURRENT_TIMESTAMP,
                        `date_upd` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`)
                    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')
            )
            && (
                version_compare($mysqlVersion, '5.6.5', '<')
                ? Db::getInstance()->execute('
                    CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_STORED_TOKENS . '` (
                        `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                        `id_customer` INT(10) UNSIGNED NOT NULL,
                        `active` TINYINT(1) NOT NULL DEFAULT "1",
                        `token` VARCHAR(50) DEFAULT NULL,
                        `type` VARCHAR(20) NOT NULL,
                        `last_4digits` CHAR(4) NOT NULL,
                        `expiration` CHAR(8) NOT NULL,
                        `date_add` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        INDEX `IDX_id_customer_active_expiration` (`id_customer`, `active`, `expiration`),
                        UNIQUE KEY `IDX_unique_card` (`id_customer`, `token`, `type`, `last_4digits`, `expiration`),
                        FOREIGN KEY (`id_customer`) REFERENCES `' . _DB_PREFIX_ . 'customer` (`id_customer`) ON DELETE CASCADE ON UPDATE CASCADE
                    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')
                : Db::getInstance()->execute('
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
                    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')
            )
            && Db::getInstance()->execute('
                CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . Cardlink_Checkout\Constants::TABLE_NAME_TRANSACTIONS . '` (
                    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_order` INT(10) UNSIGNED NOT NULL,
                    `cardlink_order_id` VARCHAR(50) NOT NULL,
                    `cardlink_tx_id` VARCHAR(100),
                    `cardlink_pay_status` VARCHAR(50),
                    `cardlink_pay_method` VARCHAR(50),
                    `cardlink_pay_ref` VARCHAR(100),
                    `order_amount` DECIMAL(20, 2),
                    `currency` VARCHAR(3),
                    `parent_transaction_id` INT(10) UNSIGNED,
                    `transaction_type` VARCHAR(50),
                    `date_add` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `date_upd` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `IDX_cardlink_order_id` (`cardlink_order_id`),
                    FOREIGN KEY (`id_order`) REFERENCES `' . _DB_PREFIX_ . 'orders` (`id_order`) ON DELETE CASCADE ON UPDATE CASCADE,
                    INDEX `IDX_id_order` (`id_order`)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;')
            && $this->addCardlinkOrderIdColumnToOrders();
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Add cardlink_order_id column to orders table
     *
     * @return bool
     */
    private function addCardlinkOrderIdColumnToOrders()
    {
        // Check if column already exists
        $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'' . _DB_PREFIX_ . 'orders\' AND COLUMN_NAME=\'cardlink_order_id\'';
        $result = Db::getInstance()->executeS($sql);

        if (!empty($result)) {
            return true; // Column already exists
        }

        // Add the column
        return Db::getInstance()->execute('
            ALTER TABLE `' . _DB_PREFIX_ . 'orders`
            ADD COLUMN `cardlink_order_id` VARCHAR(50) DEFAULT NULL,
            ADD INDEX `IDX_cardlink_order_id` (`cardlink_order_id`)
        ');
    }

    public function hookHeader($params)
    {
        $frontCssRelativePath = 'views/css/front-custom.css';
        $googlePayJsRelativePath = 'views/js/googlepay-checkout.js';
        $applePayJsRelativePath = 'views/js/applepay-checkout.js';

        $frontCssAbsolutePath = dirname(__FILE__) . '/' . $frontCssRelativePath;
        $googlePayJsAbsolutePath = dirname(__FILE__) . '/' . $googlePayJsRelativePath;
        $applePayJsAbsolutePath = dirname(__FILE__) . '/' . $applePayJsRelativePath;

        $frontCssVersion = @filemtime($frontCssAbsolutePath);
        $googlePayJsVersion = @filemtime($googlePayJsAbsolutePath);
        $applePayJsVersion = @filemtime($applePayJsAbsolutePath);

        if (!$frontCssVersion) {
            $frontCssVersion = time();
        }

        if (!$googlePayJsVersion) {
            $googlePayJsVersion = time();
        }
        if (!$applePayJsVersion) {
            $applePayJsVersion = time();
        }

        $frontCssUrl = $this->_path . $frontCssRelativePath . '?v=' . $frontCssVersion;
        $googlePayJsUrl = $this->_path . $googlePayJsRelativePath . '?v=' . $googlePayJsVersion;
        $applePayJsUrl = $this->_path . $applePayJsRelativePath . '?v=' . $applePayJsVersion;

        if (method_exists($this->context->controller, 'registerStylesheet')) {
            $this->context->controller->registerStylesheet(
                'module-cardlink-front-custom',
                $frontCssUrl,
                [
                    'media' => 'all',
                    'priority' => 150,
                ]
            );
        } else {
            $this->context->controller->addCSS($frontCssUrl, 'all', null, false);
        }

        if (method_exists($this->context->controller, 'registerJavascript')) {
            $this->context->controller->registerJavascript(
                'module-cardlink-googlepay-checkout',
                $googlePayJsUrl,
                [
                    'position' => 'bottom',
                    'priority' => 150,
                ]
            );
            $this->context->controller->registerJavascript(
                'module-cardlink-applepay-checkout',
                $applePayJsUrl,
                [
                    'position' => 'bottom',
                    'priority' => 150,
                ]
            );
        } else {
            $this->context->controller->addJS($googlePayJsUrl, false);
            $this->context->controller->addJS($applePayJsUrl, false);
        }
    }

    /**
     * PrestaShop 1.7+/8 compatibility alias for displayHeader hook.
     */
    public function hookDisplayHeader($params)
    {
        return $this->hookHeader($params);
    }

    /**
     * Reliable frontend media hook for PrestaShop 1.7/8 themes.
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!isset($this->context->controller)) {
            return;
        }

        $controllerName = Tools::getValue('controller');
        if ($controllerName === 'order' || $controllerName === 'checkout' || $controllerName === 'module') {
            $this->hookHeader($params);
        }
    }

    public function hookBackOfficeHeader($params)
    {
        $adminCustomJsRelativePath = 'views/js/admin-custom.js';
        $adminCustomJsAbsolutePath = dirname(__FILE__) . '/' . $adminCustomJsRelativePath;
        $adminCustomJsVersion = @filemtime($adminCustomJsAbsolutePath);

        if (!$adminCustomJsVersion) {
            $adminCustomJsVersion = time();
        }

        $this->context->controller->addJS($this->_path . $adminCustomJsRelativePath . '?v=' . $adminCustomJsVersion, false);
    }

    /**
     * Add CSS/JS for admin order page
     */
    public function hookActionAdminControllerSetMedia($params)
    {
        $controller = $this->context->controller;
        $controllerName = Tools::getValue('controller');

        $adminOrderCssRelativePath = 'views/css/admin-order.css';
        $adminOrderJsRelativePath = 'views/js/admin-order.js';
        $adminCustomJsRelativePath = 'views/js/admin-custom.js';

        $adminOrderCssVersion = @filemtime(dirname(__FILE__) . '/' . $adminOrderCssRelativePath);
        $adminOrderJsVersion = @filemtime(dirname(__FILE__) . '/' . $adminOrderJsRelativePath);
        $adminCustomJsVersion = @filemtime(dirname(__FILE__) . '/' . $adminCustomJsRelativePath);

        if (!$adminOrderCssVersion) {
            $adminOrderCssVersion = time();
        }
        if (!$adminOrderJsVersion) {
            $adminOrderJsVersion = time();
        }
        if (!$adminCustomJsVersion) {
            $adminCustomJsVersion = time();
        }
        
        // For individual order view page (AdminOrderController)
        if ($controller instanceof \AdminOrderController || $controllerName === 'AdminOrder') {
            $this->context->controller->addCSS($this->_path . $adminOrderCssRelativePath . '?v=' . $adminOrderCssVersion, 'all', null, false);
            $this->context->controller->addJS($this->_path . $adminOrderJsRelativePath . '?v=' . $adminOrderJsVersion, false);
        }
        
        // For orders list page (AdminOrdersController)
        if ($controller instanceof AdminOrdersController || $controllerName === 'AdminOrders') {
            $this->context->controller->addCSS($this->_path . $adminOrderCssRelativePath . '?v=' . $adminOrderCssVersion, 'all', null, false);
            $this->context->controller->addJS($this->_path . $adminCustomJsRelativePath . '?v=' . $adminCustomJsVersion, false);
        }
    }

    /**
     * Display Cardlink payment actions on admin order page (main section)
     * For PrestaShop 1.7.7+
     */
    public function hookDisplayAdminOrderMain($params)
    {
        return $this->renderAdminOrderPaymentActions($params);
    }

    /**
     * Display Cardlink payment actions on admin order page (side section)
     * For PrestaShop 1.7.7+
     */
    public function hookDisplayAdminOrderSide($params)
    {
        // We'll use the main section, but keep this for compatibility
        return '';
    }

    /**
     * Add "Paid" column to admin orders grid (PrestaShop 8 / Symfony grid)
     */
    public function hookActionOrderGridDefinitionModifier($params)
    {
        /** @var \PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface $definition */
        $definition = $params['definition'];

        $definition->getColumns()->addAfter(
            'total_paid_tax_incl',
            (new \PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn('paid_real'))
                ->setName($this->l('Paid'))
                ->setOptions([
                    'field' => 'paid_real',
                    'alignment' => 'right',
                ])
        );
    }

    /**
     * Add paid amount data to orders grid query (PrestaShop 8 / Symfony grid)
     */
    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        /** @var \Doctrine\DBAL\Query\QueryBuilder $searchQueryBuilder */
        $searchQueryBuilder = $params['search_query_builder'];
        
        // Join currency_lang table to get the symbol
        $searchQueryBuilder->leftJoin(
            'cur',
            _DB_PREFIX_ . 'currency_lang',
            'curl',
            'cur.id_currency = curl.id_currency AND curl.id_lang = ' . (int)\Context::getContext()->language->id
        );
        
        // Calculate paid amount (payments minus refunds)
        $paymentsSubquery = 'COALESCE((SELECT SUM(op.amount) FROM `' . _DB_PREFIX_ . 'order_payment` op WHERE op.order_reference = o.reference), 0)';
        $refundsSubquery = 'COALESCE((SELECT SUM(os.total_products_tax_incl + os.total_shipping_tax_incl) FROM `' . _DB_PREFIX_ . 'order_slip` os WHERE os.id_order = o.id_order), 0)';
        $paidSubquery = 'ROUND(' . $paymentsSubquery . ' - ' . $refundsSubquery . ', 2)';
        
        // Format with currency symbol and add colored badges:
        // - Green: fully paid (net paid >= order total)
        // - Orange: partially paid (net paid > 0 but < order total)
        // - Red: negative (refunds exceed payments - shouldn't happen normally)
        // - Grey: zero
        $searchQueryBuilder->addSelect(
            'CASE 
                WHEN ' . $paidSubquery . ' < 0 THEN CONCAT(\'<span class="badge rounded badge-danger">\', COALESCE(curl.symbol, cur.iso_code), FORMAT(' . $paidSubquery . ', 2), \'</span>\')
                WHEN ' . $paidSubquery . ' = 0 THEN CONCAT(\'<span class="badge rounded badge-secondary">\', COALESCE(curl.symbol, cur.iso_code), \'0.00</span>\')
                WHEN ' . $paidSubquery . ' < o.total_paid_tax_incl THEN CONCAT(\'<span class="badge rounded" style="background-color:#ff9800">\', COALESCE(curl.symbol, cur.iso_code), FORMAT(' . $paidSubquery . ', 2), \'</span>\')
                ELSE CONCAT(\'<span class="badge rounded badge-success">\', COALESCE(curl.symbol, cur.iso_code), FORMAT(' . $paidSubquery . ', 2), \'</span>\')
            END AS paid_real'
        );
    }

    /**
     * Legacy hook for older PrestaShop versions - Add "Paid" column to admin orders list
     */
    public function hookActionAdminOrdersListingFieldsModifier($params)
    {
        if (isset($params['fields'])) {
            // Add the "Paid" column after "Total" column
            $newFields = [];
            foreach ($params['fields'] as $key => $field) {
                $newFields[$key] = $field;
                if ($key === 'total_paid_tax_incl') {
                    $newFields['paid_real'] = [
                        'title' => $this->l('Paid'),
                        'align' => 'text-right',
                        'type' => 'price',
                        'currency' => true,
                        'callback' => 'getPaidAmount',
                        'callback_object' => $this,
                        'filter_key' => 'paid_real',
                        'havingFilter' => true,
                        'orderby' => false,
                    ];
                }
            }
            $params['fields'] = $newFields;
        }

        // Add paid amount to each order row
        if (isset($params['list']) && is_array($params['list'])) {
            foreach ($params['list'] as &$order) {
                $order['paid_real'] = $this->getPaidAmountForOrder($order['id_order']);
            }
        }
    }

    /**
     * Get actual paid amount for an order (payments minus refunds)
     */
    public function getPaidAmountForOrder($id_order)
    {
        $order = new Order((int)$id_order);
        if (!Validate::isLoadedObject($order)) {
            return 0;
        }

        // Get total payments
        $sql = 'SELECT SUM(op.amount) as paid_total 
                FROM `' . _DB_PREFIX_ . 'order_payment` op 
                WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
        $paidAmount = (float)Db::getInstance()->getValue($sql);
        
        // Get total refunds
        $sql = 'SELECT SUM(os.total_products_tax_incl + os.total_shipping_tax_incl) as refunded_total 
                FROM `' . _DB_PREFIX_ . 'order_slip` os 
                WHERE os.id_order = ' . (int)$id_order;
        $refundedAmount = (float)Db::getInstance()->getValue($sql);
        
        return $paidAmount - $refundedAmount;
    }

    /**
     * Callback for displaying paid amount in orders list
     */
    public function getPaidAmount($id_order, $tr)
    {
        return $tr['paid_real'] ?? 0;
    }

    /**
     * Render the payment action buttons for admin order page
     */
    private function renderAdminOrderPaymentActions($params)
    {
        $id_order = (int) $params['id_order'];
        
        if (!$id_order) {
            return '';
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }

        // Check if this order was paid with Cardlink
        if ($order->module !== $this->name) {
            return '';
        }

        // Get cardlink_order_id directly from database (Order class doesn't know about this custom column)
        $cardlink_order_id = Db::getInstance()->getValue(
            'SELECT cardlink_order_id FROM ' . _DB_PREFIX_ . 'orders WHERE id_order = ' . (int)$id_order
        );

        // Check if order has a Cardlink order ID
        if (empty($cardlink_order_id)) {
            return '';
        }

        // Get the transaction to determine current status
        $transaction = \CardlinkPaymentTransaction::getPrimaryTransactionByOrder($id_order);
        
        // If no transaction record exists, try to create one from existing order data
        if (!$transaction) {
            // Check if we can infer transaction data from the order
            // Try to create a transaction record for this order
            try {
                $transaction = \CardlinkPaymentTransaction::createTransaction([
                    'id_order' => $id_order,
                    'cardlink_order_id' => $cardlink_order_id,
                    'cardlink_tx_id' => null,
                    'cardlink_pay_status' => Cardlink_Checkout\Constants::TRANSACTION_STATUS_AUTHORIZED,
                    'cardlink_pay_method' => 'Card',
                    'cardlink_pay_ref' => null,
                    'order_amount' => $order->total_paid_tax_incl,
                    'currency' => Currency::getIsoCodeById((int)$order->id_currency),
                    'transaction_type' => 'initial'
                ]);
                
                if (!$transaction) {
                    // For legacy orders without transaction records, show info-only card
                    $this->context->smarty->assign([
                        'cardlink_order_id' => $cardlink_order_id,
                        'cardlink_pay_status' => 'Unknown (legacy order)',
                        'cardlink_amount' => $order->total_paid_tax_incl,
                        'cardlink_remaining_amount' => 0,
                        'cardlink_captured_amount' => $order->total_paid_tax_incl,
                        'cardlink_refundable_amount' => 0,
                        'cardlink_refunded_amount' => 0,
                        'cardlink_currency' => Currency::getIsoCodeById((int)$order->id_currency),
                        'cardlink_can_capture' => false,
                        'cardlink_can_void' => false,
                        'cardlink_can_refund' => false,
                        'cardlink_action_url' => '',
                        'cardlink_id_order' => $id_order,
                        'cardlink_transaction_id' => 'N/A',
                        'cardlink_pay_ref' => 'N/A',
                        'cardlink_pay_method' => 'Card',
                        'cardlink_flash_message' => '',
                        'cardlink_flash_message_type' => '',
                        'cardlink_is_legacy' => true,
                    ]);
                    return $this->display(__FILE__, 'views/templates/admin/order_payment_actions.tpl');
                }
            } catch (\Exception $e) {
                // For legacy orders without transaction records, show info-only card
                $this->context->smarty->assign([
                    'cardlink_order_id' => $cardlink_order_id,
                    'cardlink_pay_status' => 'Unknown (legacy order)',
                    'cardlink_amount' => $order->total_paid_tax_incl,
                    'cardlink_remaining_amount' => 0,
                    'cardlink_captured_amount' => $order->total_paid_tax_incl,
                    'cardlink_refundable_amount' => 0,
                    'cardlink_refunded_amount' => 0,
                    'cardlink_currency' => Currency::getIsoCodeById((int)$order->id_currency),
                    'cardlink_can_capture' => false,
                    'cardlink_can_void' => false,
                    'cardlink_can_refund' => false,
                    'cardlink_action_url' => '',
                    'cardlink_id_order' => $id_order,
                    'cardlink_transaction_id' => 'N/A',
                    'cardlink_pay_ref' => 'N/A',
                    'cardlink_pay_method' => 'Card',
                    'cardlink_flash_message' => '',
                    'cardlink_flash_message_type' => '',
                    'cardlink_is_legacy' => true,
                ]);
                return $this->display(__FILE__, 'views/templates/admin/order_payment_actions.tpl');
            }
        }

        // Don't show capture/void/refund buttons for IRIS payments
        if ($transaction->cardlink_pay_method === 'IRIS') {
            return '';
        }

        $payStatus = $transaction->cardlink_pay_status;
        $currency = $transaction->currency;

        // Get original transaction to determine payment type (sale vs preauthorization)
        $originalTransaction = \CardlinkPaymentTransaction::getOriginalTransactionByOrder($id_order);
        $originalType = $originalTransaction ? $originalTransaction->transaction_type : $transaction->transaction_type;
        $isPreauth = ($originalType === 'authorize');

        // Use original authorized amount for accurate display
        $authorizedAmount = $originalTransaction
            ? (float) $originalTransaction->order_amount
            : (float) $transaction->order_amount;

        // Calculate already captured amount from order_payment table
        $capturedAmount = 0;
        $sql = 'SELECT SUM(op.amount) as captured_total
                FROM `' . _DB_PREFIX_ . 'order_payment` op
                WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
        $result = Db::getInstance()->getValue($sql);
        if ($result) {
            $capturedAmount = (float) $result;
        }

        // Calculate remaining amount that can be captured
        $remainingAmount = $authorizedAmount - $capturedAmount;
        $hasCaptures = ($capturedAmount > 0);

        // Calculate already refunded amount from order_slip table
        $refundedAmount = 0;
        $sql = 'SELECT SUM(os.total_products_tax_incl + os.total_shipping_tax_incl) as refunded_total
                FROM `' . _DB_PREFIX_ . 'order_slip` os
                WHERE os.id_order = ' . (int)$id_order;
        $refundResult = Db::getInstance()->getValue($sql);
        if ($refundResult) {
            $refundedAmount = (float) $refundResult;
        }

        // Calculate remaining refundable amount (captured - already refunded)
        $refundableAmount = $capturedAmount - $refundedAmount;

        // Same-day check: determine the relevant transaction date for business rules
        // - Sale: use original sale date
        // - Preauth captured: use the capture date (when money was actually taken)
        $isSameDay = false;
        if ($capturedAmount > 0) {
            if ($isPreauth) {
                $captureTransaction = \CardlinkPaymentTransaction::getCaptureTransactionByOrder($id_order);
                $relevantDate = $captureTransaction
                    ? $captureTransaction->date_add
                    : ($originalTransaction ? $originalTransaction->date_add : null);
            } else {
                $relevantDate = $originalTransaction ? $originalTransaction->date_add : $transaction->date_add;
            }
            if ($relevantDate) {
                $isSameDay = (date('Y-m-d', strtotime($relevantDate)) === date('Y-m-d'));
            }
        }

        // Effective state flags
        $hasCanceled = ($transaction->transaction_type === 'void'
            || $transaction->cardlink_pay_status === Cardlink_Checkout\Constants::TRANSACTION_STATUS_CANCELED);
        $isAuthorizedPreauth = ($isPreauth && $capturedAmount <= 0 && !$hasCanceled);
        $isCapturedState = ($capturedAmount > 0 && !$hasCanceled);

        // Determine which buttons to show based on payment type, capture state, and same-day rule:
        //
        // Preauthorization AUTHORIZED: Capture + Reversal any day; no refund
        // Any CAPTURED (sale or captured preauth):
        //   - Same day  → Void only (settlement not yet complete)
        //   - Next day+ → Refund / partial refund only
        $canCapture = false;
        $canVoid = false;
        $canRefund = false;

        if ($isAuthorizedPreauth) {
            $canCapture = ($remainingAmount > 0);
            $canVoid = true;
        } elseif ($isCapturedState) {
            if ($isSameDay) {
                $canVoid = true;
            } else {
                $canRefund = ($refundableAmount > 0);
            }
        }

        // Build the action URL
        $actionUrl = $this->context->link->getAdminLink('AdminCardlink_CheckoutPaymentAction');

        // Check for flash messages from cookie
        $flashMessage = '';
        $flashMessageType = '';
        if (isset($this->context->cookie->cardlink_message) && $this->context->cookie->cardlink_message) {
            $flashMessage = $this->context->cookie->cardlink_message;
            $flashMessageType = $this->context->cookie->cardlink_message_type ?? 'info';
            // Clear the cookie
            unset($this->context->cookie->cardlink_message);
            unset($this->context->cookie->cardlink_message_type);
        }

        $this->context->smarty->assign([
            'cardlink_order_id' => $cardlink_order_id,
            'cardlink_pay_status' => $payStatus,
            'cardlink_amount' => $authorizedAmount,
            'cardlink_remaining_amount' => $remainingAmount,
            'cardlink_captured_amount' => $capturedAmount,
            'cardlink_refundable_amount' => $refundableAmount,
            'cardlink_refunded_amount' => $refundedAmount,
            'cardlink_currency' => $currency,
            'cardlink_can_capture' => $canCapture,
            'cardlink_can_void' => $canVoid,
            'cardlink_can_refund' => $canRefund,
            'cardlink_action_url' => $actionUrl,
            'cardlink_id_order' => $id_order,
            'cardlink_transaction_id' => $transaction->cardlink_tx_id,
            'cardlink_pay_ref' => $transaction->cardlink_pay_ref,
            'cardlink_pay_method' => $transaction->cardlink_pay_method,
            'cardlink_flash_message' => $flashMessage,
            'cardlink_flash_message_type' => $flashMessageType,
            'cardlink_hide_native_refund' => ($isAuthorizedPreauth || $canRefund),
        ]);

        $html = $this->display(__FILE__, 'views/templates/admin/order_payment_actions.tpl');

        // Hide PS's native partial refund button via CSS (no timing issues) and JS (broader selectors).
        // PS 8.x uses class .partial-refund-display; PS 1.7 legacy used #partial-refund-button / #desc-order-partial_refund.
        // The JS in admin-order.js reads data-hide-native-refund="1" and tries additional selectors.
        if ($isAuthorizedPreauth || $canRefund) {
            $html .= '<style>'
                . '.partial-refund-display,'      // PS 8.x
                . '#partial-refund-button,'       // PS 1.7.7+ Symfony admin
                . '#desc-order-partial_refund'    // PS 1.7 legacy admin
                . '{display:none!important}'
                . '</style>';
        }

        return $html;
    }

    /**
     * Hook triggered when a credit slip (order slip) is added via PrestaShop's native partial refund
     * This sends the refund to the Cardlink payment gateway
     *
     * @param array $params Contains 'order', 'productList', 'qtyList'
     */
    public function hookActionOrderSlipAdd($params)
    {
        if (self::$nativeRefundProcessedInRequest) {
            return;
        }

        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        $order = $params['order'];

        // Only process orders paid with this module
        if ($order->module !== $this->name) {
            return;
        }

        // Check if this refund was triggered by our own controller (avoid duplicate API calls)
        if (isset($this->context->cookie->cardlink_refund_in_progress) && $this->context->cookie->cardlink_refund_in_progress) {
            unset($this->context->cookie->cardlink_refund_in_progress);
            return;
        }

        // Block refunds on pre-authorized (not yet captured) Cardlink transactions.
        // The payment has not been settled; refunding before capture is impossible and
        // would leave the order marked as Refunded with no real money movement.
        $primaryTx = \CardlinkPaymentTransaction::getPrimaryTransactionByOrder((int)$order->id);
        if ($primaryTx) {
            $originalTx = \CardlinkPaymentTransaction::getOriginalTransactionByOrder((int)$order->id);
            $originalType = $originalTx ? $originalTx->transaction_type : $primaryTx->transaction_type;
            $capturedSql = 'SELECT SUM(amount) FROM `' . _DB_PREFIX_ . 'order_payment`'
                . ' WHERE order_reference = \'' . pSQL($order->reference) . '\'';
            $capturedCheck = (float) Db::getInstance()->getValue($capturedSql);
            if ($originalType === 'authorize' && $capturedCheck <= 0) {
                PrestaShopLogger::addLog(
                    'Cardlink: Blocked credit slip on pre-authorized (uncaptured) order #' . (int)$order->id
                    . '. Capture or void the payment first.',
                    2
                );
                return;
            }
        }

        // Strict rule: run online refund sync only when
        // credit slip generation is enabled and voucher generation is disabled.
        $eligibility = $this->evaluateOnlineRefundEligibility($params, (int)$order->id, 'actionOrderSlipAdd');
        if (!$eligibility['allowed']) {
            PrestaShopLogger::addLog(
                'Cardlink: Skipping online refund sync due to refund options for order #' . (int)$order->id
                . ' (requires credit slip=true and voucher=false; detected credit_slip=' . $eligibility['credit_slip']
                . ', voucher=' . $eligibility['voucher'] . ', source=' . $eligibility['source'] . ')',
                1
            );
            return;
        }

        // Capture the slip ID as early as possible so we can roll back on gateway failure.
        // PS creates the credit slip before firing this hook, so on failure we must delete it.
        $resolvedSlipId = null;
        if (isset($params['id_order_slip'])) {
            $resolvedSlipId = (int)$params['id_order_slip'];
        } elseif (isset($params['orderSlip']) && is_object($params['orderSlip']) && isset($params['orderSlip']->id)) {
            $resolvedSlipId = (int)$params['orderSlip']->id;
        }

        try {
            // Get Cardlink order ID
            $cardlink_order_id = Db::getInstance()->getValue(
                'SELECT cardlink_order_id FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int)$order->id
            );

            if (empty($cardlink_order_id)) {
                PrestaShopLogger::addLog('Cardlink: Cannot process native refund - no Cardlink order ID for order #' . $order->id, 3);
                return;
            }

            // Get the transaction
            $transaction = \CardlinkPaymentTransaction::getPrimaryTransactionByOrder($order->id);
            if (!$transaction) {
                PrestaShopLogger::addLog('Cardlink: Cannot process native refund - no transaction found for order #' . $order->id, 3);
                return;
            }

            // First, prefer direct amount extraction from hook-provided OrderSlip object.
            $refundAmount = $this->extractRefundAmountFromOrderSlipParam($params);

            // If not available, resolve persisted credit slip row.
            if ($refundAmount <= 0) {
                // Resolve the exact credit slip created by this hook execution.
                // Using only "latest by order" is race-prone when multiple slips exist.
                $slip = $this->resolveOrderSlipFromHookParams($params, (int)$order->id);

                // Some PrestaShop flows trigger this hook before order_slip row becomes visible.
                if (!$slip) {
                    $slip = $this->waitForLatestOrderSlip($order->id, 5, 200);
                }

                if ($slip) {
                    // Primary DB path: use persisted credit slip totals.
                    $refundAmount = (float)$slip['total_products_tax_incl'] + (float)$slip['total_shipping_tax_incl'];
                    $refundAmount = Tools::ps_round($refundAmount, 2);
                    if ($resolvedSlipId === null && isset($slip['id_order_slip'])) {
                        $resolvedSlipId = (int)$slip['id_order_slip'];
                    }
                }
            }

            if ($refundAmount <= 0) {
                // Fallback path: derive amount from hook payload when the slip row
                // is not yet queryable at this hook timing.
                $refundAmount = $this->calculateRefundAmountFromHookParams($params, (int)$order->id);

                if ($refundAmount <= 0) {
                    // Last fallback for payloads with empty productList/qtyList.
                    $refundAmount = $this->extractRefundAmountFromAlternPayload($params);
                }

                $productCount = (isset($params['productList']) && is_array($params['productList'])) ? count($params['productList']) : 0;
                $qtyCount = (isset($params['qtyList']) && is_array($params['qtyList'])) ? count($params['qtyList']) : 0;

                PrestaShopLogger::addLog(
                    'Cardlink: Credit slip row not found for order #' . $order->id .
                    ', using hook payload fallback amount: ' . $refundAmount . ' ' . $transaction->currency .
                    ' (hook keys: ' . implode(', ', array_keys((array)$params)) .
                    ', productList count: ' . (int)$productCount . ', qtyList count: ' . (int)$qtyCount . ')',
                    2
                );
            }

            if ($refundAmount <= 0) {
                PrestaShopLogger::addLog(
                    'Cardlink: Cannot process native refund - refund amount is zero for order #' . $order->id
                    . ' | altern snapshot: ' . $this->buildAlternDebugSnapshot($params),
                    2
                );
                return;
            }

            // Get captured amount for the refund API call
            $capturedAmount = 0;
            $sql = 'SELECT SUM(op.amount) as captured_total 
                    FROM `' . _DB_PREFIX_ . 'order_payment` op 
                    WHERE op.order_reference = \'' . pSQL($order->reference) . '\'';
            $result = Db::getInstance()->getValue($sql);
            if ($result) {
                $capturedAmount = Tools::ps_round((float) $result, 2);
            }

            if ($capturedAmount <= 0) {
                PrestaShopLogger::addLog('Cardlink: Cannot process native refund - no captured payments for order #' . $order->id, 2);
                return;
            }

            // Get payment gateway configuration
            $payMethod = $transaction->cardlink_pay_method;
            if ($payMethod === 'IRIS') {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET);
                $businessPartner = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER);
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT);
            } elseif (stripos($payMethod, 'Google Pay') !== false || stripos($payMethod, 'GOOGLEPAY') !== false) {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET);
                $businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT);
            } elseif (stripos($payMethod, 'Apple Pay') !== false || stripos($payMethod, 'APPLEPAY') !== false) {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET);
                $businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT);
            } else {
                $merchantId = Configuration::get(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID);
                $sharedSecret = Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET);
                $businessPartner = Configuration::get(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER);
                $environment = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT);
            }

            // Same-day check: captured transactions must be voided (not refunded) on the same business day.
            // Use the original sale/capture date, not the most recent transaction date.
            $originalTransactionHook = \CardlinkPaymentTransaction::getOriginalTransactionByOrder($order->id);
            $isPreauthHook = ($originalTransactionHook && $originalTransactionHook->transaction_type === 'authorize');
            if ($isPreauthHook) {
                $captureTransactionHook = \CardlinkPaymentTransaction::getCaptureTransactionByOrder($order->id);
                $hookRelevantDate = $captureTransactionHook
                    ? $captureTransactionHook->date_add
                    : ($originalTransactionHook ? $originalTransactionHook->date_add : null);
            } else {
                $hookRelevantDate = $originalTransactionHook
                    ? $originalTransactionHook->date_add
                    : $transaction->date_add;
            }

            if ($hookRelevantDate && date('Y-m-d', strtotime($hookRelevantDate)) === date('Y-m-d')) {
                $isFullSameDayRefund = abs($refundAmount - $capturedAmount) < 0.01;
                if (!$isFullSameDayRefund) {
                    PrestaShopLogger::addLog(
                        'Cardlink: Same-day partial refund blocked for order #' . $order->id
                        . '. Captured payments can only be voided (full amount) on the same business day.',
                        2
                    );
                    $this->clearPersistedRefundOptionDecision();
                    return;
                }

                // Full same-day refund → perform void at gateway instead
                Cardlink_Checkout\TransactionOperationHelper::voidTransaction(
                    $order->id,
                    $cardlink_order_id,
                    $capturedAmount,
                    $transaction->currency,
                    $merchantId,
                    $sharedSecret,
                    $businessPartner,
                    $environment
                );
                PrestaShopLogger::addLog(
                    'Cardlink: Same-day refund executed as VOID for order #' . $order->id
                    . ', Amount: ' . $capturedAmount . ' ' . $transaction->currency,
                    2
                );
                self::$nativeRefundProcessedInRequest = true;
                $this->clearPersistedRefundOptionDecision();
                return;
            }

            // Send refund to Cardlink gateway
            $operationResponse = Cardlink_Checkout\TransactionOperationHelper::refundTransaction(
                $order->id,
                $cardlink_order_id,
                $refundAmount,
                $capturedAmount,
                $transaction->currency,
                $merchantId,
                $sharedSecret,
                $businessPartner,
                $environment
            );

            $effectiveOperation = isset($operationResponse['cardlink_operation'])
                ? (string)$operationResponse['cardlink_operation']
                : 'refund';

            PrestaShopLogger::addLog(
                'Cardlink: Native partial refund processed - Order #' . $order->id . ', Amount: ' . $refundAmount . ' ' . $transaction->currency . ', Gateway operation: ' . strtoupper($effectiveOperation),
                $effectiveOperation === 'void' ? 2 : 1
            );

            self::$nativeRefundProcessedInRequest = true;
            $this->clearPersistedRefundOptionDecision();

        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Cardlink: Failed to process native refund for order #' . $order->id . ': ' . $e->getMessage(), 3
            );
            $this->clearPersistedRefundOptionDecision();

            // Roll back the credit slip that PS already created before this hook fired.
            // If we have no slip ID yet, find the latest one for this order.
            if (!$resolvedSlipId) {
                $latestSlip = Db::getInstance()->getRow(
                    'SELECT id_order_slip FROM `' . _DB_PREFIX_ . 'order_slip`'
                    . ' WHERE id_order = ' . (int)$order->id
                    . ' ORDER BY id_order_slip DESC LIMIT 1'
                );
                if ($latestSlip) {
                    $resolvedSlipId = (int)$latestSlip['id_order_slip'];
                }
            }

            if ($resolvedSlipId) {
                try {
                    Db::getInstance()->delete('order_slip_detail', 'id_order_slip = ' . (int)$resolvedSlipId);
                    Db::getInstance()->delete('order_slip', 'id_order_slip = ' . (int)$resolvedSlipId);
                    PrestaShopLogger::addLog(
                        'Cardlink: Rolled back credit slip #' . $resolvedSlipId
                        . ' for order #' . $order->id . ' after gateway sync failure.',
                        2
                    );
                } catch (Exception $rollbackEx) {
                    PrestaShopLogger::addLog(
                        'Cardlink: Could not roll back credit slip #' . $resolvedSlipId
                        . ' for order #' . $order->id . ': ' . $rollbackEx->getMessage(), 3
                    );
                }
            }
        }
    }

    /**
     * Late hook fired after OrderSlip object is created.
     * Some PrestaShop flows trigger actionOrderSlipAdd too early with empty payload.
     * This hook gives a reliable slip id and order id for refund synchronization.
     *
     * @param array $params
     */
    public function hookActionObjectOrderSlipAddAfter($params)
    {
        if (self::$nativeRefundProcessedInRequest) {
            return;
        }

        if (!isset($params['object']) || !is_object($params['object'])) {
            return;
        }

        $orderSlip = $params['object'];
        if (!isset($orderSlip->id) || !isset($orderSlip->id_order)) {
            return;
        }

        $loadedOrderSlip = new OrderSlip((int)$orderSlip->id);
        if (Validate::isLoadedObject($loadedOrderSlip)) {
            $orderSlip = $loadedOrderSlip;
        }

        $order = new Order((int)$orderSlip->id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $proxyParams = [
            'order' => $order,
            'id_order_slip' => (int)$orderSlip->id,
            'orderSlip' => $orderSlip,
        ];

        $this->hookActionOrderSlipAdd($proxyParams);
    }

    /**
     * Extract refund amount directly from hook-provided OrderSlip object/array.
     *
     * @param array $params
     * @return float
     */
    private function extractRefundAmountFromOrderSlipParam($params)
    {
        if (!isset($params['orderSlip'])) {
            return 0.0;
        }

        $orderSlip = $params['orderSlip'];
        if (is_object($orderSlip)) {
            $orderSlip = get_object_vars($orderSlip);
        }
        if (!is_array($orderSlip)) {
            return 0.0;
        }

        $productsIncl = isset($orderSlip['total_products_tax_incl']) && is_numeric($orderSlip['total_products_tax_incl'])
            ? (float)$orderSlip['total_products_tax_incl']
            : 0.0;
        $shippingIncl = isset($orderSlip['total_shipping_tax_incl']) && is_numeric($orderSlip['total_shipping_tax_incl'])
            ? (float)$orderSlip['total_shipping_tax_incl']
            : 0.0;

        $total = $productsIncl + $shippingIncl;
        if ($total > 0) {
            return Tools::ps_round($total, 2);
        }

        if (isset($orderSlip['amount']) && is_numeric($orderSlip['amount'])) {
            $amount = (float)$orderSlip['amount'];
            if ($amount > 0) {
                return Tools::ps_round($amount, 2);
            }
        }

        return 0.0;
    }

    /**
     * Resolve credit slip row from hook parameters.
     *
     * @param array $params Hook params from actionOrderSlipAdd
     * @param int $idOrder
     *
     * @return array|false
     */
    private function resolveOrderSlipFromHookParams($params, $idOrder)
    {
        // PrestaShop versions may pass the slip as object under orderSlip.
        if (isset($params['orderSlip']) && is_object($params['orderSlip']) && isset($params['orderSlip']->id)) {
            $idOrderSlip = (int)$params['orderSlip']->id;
            if ($idOrderSlip > 0) {
                $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` WHERE id_order_slip = ' . $idOrderSlip . ' LIMIT 1';
                $row = Db::getInstance()->getRow($sql);
                if (!empty($row)) {
                    return $row;
                }
            }
        }

        // Some versions/modules may pass the slip ID directly.
        if (isset($params['id_order_slip'])) {
            $idOrderSlip = (int)$params['id_order_slip'];
            if ($idOrderSlip > 0) {
                $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` WHERE id_order_slip = ' . $idOrderSlip . ' LIMIT 1';
                $row = Db::getInstance()->getRow($sql);
                if (!empty($row)) {
                    return $row;
                }
            }
        }

        // Legacy/fallback: use latest credit slip for the order.
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` 
                WHERE id_order = ' . (int)$idOrder . ' 
                ORDER BY id_order_slip DESC LIMIT 1';

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Retry fetching latest order slip for a short period.
     *
     * @param int $idOrder
     * @param int $attempts
     * @param int $delayMs
     *
     * @return array|false
     */
    private function waitForLatestOrderSlip($idOrder, $attempts = 5, $delayMs = 200)
    {
        $attempts = max(1, (int)$attempts);
        $delayUs = max(0, (int)$delayMs) * 1000;

        for ($i = 0; $i < $attempts; $i++) {
            $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'order_slip` '
                . 'WHERE id_order = ' . (int)$idOrder . ' '
                . 'ORDER BY id_order_slip DESC LIMIT 1';

            $row = Db::getInstance()->getRow($sql);
            if (!empty($row)) {
                return $row;
            }

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        return false;
    }

    /**
     * Calculate refund amount from actionOrderSlipAdd hook payload.
     *
     * @param array $params
     *
     * @return float
     */
    private function calculateRefundAmountFromHookParams($params, $idOrder)
    {
        $refundAmount = 0.0;
        $qtyList = isset($params['qtyList']) && is_array($params['qtyList']) ? $params['qtyList'] : [];
        $productList = isset($params['productList']) && is_array($params['productList']) ? $params['productList'] : [];
        $qtyByDetail = $this->normalizeQtyListByOrderDetail($qtyList);

        foreach ($productList as $key => $productRaw) {
            $product = is_object($productRaw) ? get_object_vars($productRaw) : $productRaw;
            if (!is_array($product)) {
                continue;
            }

            $idOrderDetail = 0;
            if (isset($product['id_order_detail'])) {
                $idOrderDetail = (int)$product['id_order_detail'];
            } elseif (is_numeric($key)) {
                $idOrderDetail = (int)$key;
            }

            $qty = 0.0;
            if ($idOrderDetail > 0 && isset($qtyByDetail[$idOrderDetail])) {
                $qty = (float)$qtyByDetail[$idOrderDetail];
            }

            if ($qty <= 0 && isset($product['quantity']) && is_numeric($product['quantity'])) {
                $qty = (float)$product['quantity'];
            }

            // If amount exists and qty context is missing, still accept it as best effort.
            if (isset($product['amount']) && is_numeric($product['amount']) && $qty <= 0) {
                $refundAmount += (float)$product['amount'];
                continue;
            }

            if ($qty <= 0) {
                continue;
            }

            if (isset($product['unit_price_tax_incl']) && is_numeric($product['unit_price_tax_incl'])) {
                $refundAmount += ((float)$product['unit_price_tax_incl']) * $qty;
                continue;
            }

            if (isset($product['unit_price']) && is_numeric($product['unit_price'])) {
                $refundAmount += ((float)$product['unit_price']) * $qty;
                continue;
            }

            if (isset($product['amount']) && is_numeric($product['amount'])) {
                $refundAmount += (float)$product['amount'];
            }
        }

        // If payload does not carry unit prices, compute from order_detail table by refunded qty.
        if ($refundAmount <= 0 && !empty($qtyByDetail)) {
            $detailIds = array_keys($qtyByDetail);
            $detailIds = array_map('intval', $detailIds);
            $detailIds = array_filter($detailIds, function ($v) {
                return $v > 0;
            });

            if (!empty($detailIds)) {
                $sql = 'SELECT id_order_detail, unit_price_tax_incl '
                    . 'FROM `' . _DB_PREFIX_ . 'order_detail` '
                    . 'WHERE id_order = ' . (int)$idOrder . ' '
                    . 'AND id_order_detail IN (' . implode(',', $detailIds) . ')';
                $rows = Db::getInstance()->executeS($sql);

                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        $idOrderDetail = isset($row['id_order_detail']) ? (int)$row['id_order_detail'] : 0;
                        if ($idOrderDetail <= 0 || !isset($qtyByDetail[$idOrderDetail])) {
                            continue;
                        }

                        $unit = isset($row['unit_price_tax_incl']) ? (float)$row['unit_price_tax_incl'] : 0.0;
                        $qty = (float)$qtyByDetail[$idOrderDetail];
                        $refundAmount += $unit * $qty;
                    }
                }
            }
        }

        // Optional shipping part if present in payload.
        $shippingCandidates = [
            'shipping_cost_amount',
            'shippingCostAmount',
            'shipping_amount',
            'shippingAmount',
            'shipping',
        ];
        foreach ($shippingCandidates as $candidate) {
            if (isset($params[$candidate]) && is_numeric($params[$candidate])) {
                $refundAmount += (float)$params[$candidate];
                break;
            }
        }

        return Tools::ps_round($refundAmount, 2);
    }

    /**
     * Normalize qtyList payload to id_order_detail => refunded quantity.
     *
     * @param array $qtyList
     * @return array
     */
    private function normalizeQtyListByOrderDetail($qtyList)
    {
        $normalized = [];

        foreach ($qtyList as $key => $entry) {
            // Common shape: qtyList[id_order_detail] = qty
            if (is_numeric($entry)) {
                $idOrderDetail = is_numeric($key) ? (int)$key : 0;
                $qty = (float)$entry;
                if ($idOrderDetail > 0 && $qty > 0) {
                    $normalized[$idOrderDetail] = $qty;
                }
                continue;
            }

            // Alternate shape: list of arrays/objects with explicit fields.
            if (is_object($entry)) {
                $entry = get_object_vars($entry);
            }
            if (!is_array($entry)) {
                continue;
            }

            $idOrderDetail = 0;
            if (isset($entry['id_order_detail']) && is_numeric($entry['id_order_detail'])) {
                $idOrderDetail = (int)$entry['id_order_detail'];
            } elseif (is_numeric($key)) {
                $idOrderDetail = (int)$key;
            }

            $qty = 0.0;
            $qtyCandidates = ['quantity', 'qty', 'product_quantity', 'product_quantity_refunded'];
            foreach ($qtyCandidates as $candidate) {
                if (isset($entry[$candidate]) && is_numeric($entry[$candidate])) {
                    $qty = (float)$entry[$candidate];
                    break;
                }
            }

            if ($idOrderDetail > 0 && $qty > 0) {
                $normalized[$idOrderDetail] = $qty;
            }
        }

        return $normalized;
    }

    /**
     * Extract explicit refund amount from alternate payload structures.
     *
     * @param array $params
     * @return float
     */
    private function extractRefundAmountFromAlternPayload($params)
    {
        $sources = [];
        if (is_array($params)) {
            $sources[] = $params;
            if (isset($params['altern'])) {
                $altern = $params['altern'];
                if (is_object($altern)) {
                    $altern = get_object_vars($altern);
                }
                if (is_array($altern)) {
                    $sources[] = $altern;
                }
            }
        }

        // Prefer explicit total refund amount fields when present.
        $singleValueCandidates = [
            'refund_amount',
            'refundAmount',
            'total_to_refund',
            'totalRefund',
            'amount',
        ];

        foreach ($sources as $source) {
            foreach ($singleValueCandidates as $candidate) {
                if (isset($source[$candidate]) && is_numeric($source[$candidate])) {
                    $value = (float)$source[$candidate];
                    if ($value > 0) {
                        return Tools::ps_round($value, 2);
                    }
                }
            }

            // Fallback: sum product + shipping totals if both are provided.
            $products = null;
            $shipping = 0.0;

            if (isset($source['total_products_tax_incl']) && is_numeric($source['total_products_tax_incl'])) {
                $products = (float)$source['total_products_tax_incl'];
            }
            if (isset($source['total_shipping_tax_incl']) && is_numeric($source['total_shipping_tax_incl'])) {
                $shipping = (float)$source['total_shipping_tax_incl'];
            }

            if ($products !== null) {
                $sum = $products + $shipping;
                if ($sum > 0) {
                    return Tools::ps_round($sum, 2);
                }
            }
        }

        return 0.0;
    }

    /**
     * Build a compact debug snapshot for the optional altern payload.
     *
     * @param array $params
     * @return string
     */
    private function buildAlternDebugSnapshot($params)
    {
        if (!is_array($params) || !isset($params['altern'])) {
            return 'n/a';
        }

        $altern = $params['altern'];
        if (is_object($altern)) {
            $altern = get_object_vars($altern);
        }

        if (!is_array($altern) || empty($altern)) {
            return 'empty';
        }

        $snapshot = [];
        foreach ($altern as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $snapshot[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $snapshot[$key] = 'array(' . count($value) . ')';
            } elseif (is_object($value)) {
                $snapshot[$key] = 'object(' . get_class($value) . ')';
            } else {
                $snapshot[$key] = gettype($value);
            }
        }

        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : 'unencodable';
    }

    /**
     * Decide if online refund should be executed based on BO refund options.
     *
     * Requirement: credit slip must be true AND voucher must be false.
     *
     * @param array $params
     * @param int $idOrder
     * @param string $context
     * @return array{allowed:bool,credit_slip:string,voucher:string,source:string}
     */
    private function evaluateOnlineRefundEligibility($params, $idOrder, $context = '')
    {
        $creditSlip = $this->extractBooleanFromRefundContext($params, [
            'generateCreditSlip',
            'generate_credit_slip',
        ], true);

        $voucher = $this->extractBooleanFromRefundContext($params, [
            'generateVoucher',
            'generate_voucher',
            'generateDiscount',
            'generate_discount',
            'refund_voucher',
        ], false);

        $source = 'runtime';

        // Persist explicit decision when both options are present.
        if ($creditSlip !== null && $voucher !== null) {
            $this->persistRefundOptionDecision($idOrder, $creditSlip, $voucher);
        }

        // Later hooks may not carry checkbox fields; recover from persisted decision.
        if ($creditSlip === null || $voucher === null) {
            $persisted = $this->getPersistedRefundOptionDecision($idOrder);
            if ($persisted !== null) {
                if ($creditSlip === null) {
                    $creditSlip = $persisted['credit_slip'];
                }
                if ($voucher === null) {
                    $voucher = $persisted['voucher'];
                }
                $source = 'persisted';
            }
        }

        // For actionOrderSlipAdd context: presence of order slip implies
        // credit slip generation is true. Missing voucher key is treated as
        // unchecked (false), which matches common HTML checkbox behavior.
        if ($context === 'actionOrderSlipAdd') {
            if ($creditSlip === null) {
                $creditSlip = true;
                $source = ($source === 'runtime') ? 'inferred_hook' : $source;
            }
            if ($voucher === null) {
                $voucher = false;
                if ($source === 'runtime' || $source === 'inferred_hook') {
                    $source = 'inferred_hook';
                }
            }
        }

        $allowed = ($creditSlip === true && $voucher === false);

        return [
            'allowed' => $allowed,
            'credit_slip' => $this->boolToDebugString($creditSlip),
            'voucher' => $this->boolToDebugString($voucher),
            'source' => $source,
        ];
    }

    /**
     * Persist detected refund option decision in cookie for subsequent hooks.
     *
     * @param int $idOrder
     * @param bool $creditSlip
     * @param bool $voucher
     *
     * @return void
     */
    private function persistRefundOptionDecision($idOrder, $creditSlip, $voucher)
    {
        if (!isset($this->context) || !isset($this->context->cookie)) {
            return;
        }

        $this->context->cookie->cardlink_refund_option_order_id = (int)$idOrder;
        $this->context->cookie->cardlink_refund_option_credit_slip = $creditSlip ? 1 : 0;
        $this->context->cookie->cardlink_refund_option_voucher = $voucher ? 1 : 0;
    }

    /**
     * Retrieve persisted refund option decision for the given order.
     *
     * @param int $idOrder
     * @return array|null
     */
    private function getPersistedRefundOptionDecision($idOrder)
    {
        if (!isset($this->context) || !isset($this->context->cookie)) {
            return null;
        }

        if (!isset($this->context->cookie->cardlink_refund_option_order_id)) {
            return null;
        }

        $storedOrderId = (int)$this->context->cookie->cardlink_refund_option_order_id;
        if ($storedOrderId !== (int)$idOrder) {
            return null;
        }

        if (!isset($this->context->cookie->cardlink_refund_option_credit_slip)
            || !isset($this->context->cookie->cardlink_refund_option_voucher)
        ) {
            return null;
        }

        return [
            'credit_slip' => ((int)$this->context->cookie->cardlink_refund_option_credit_slip) === 1,
            'voucher' => ((int)$this->context->cookie->cardlink_refund_option_voucher) === 1,
        ];
    }

    /**
     * Remove persisted refund option decision from cookie.
     *
     * @return void
     */
    private function clearPersistedRefundOptionDecision()
    {
        if (!isset($this->context) || !isset($this->context->cookie)) {
            return;
        }

        unset($this->context->cookie->cardlink_refund_option_order_id);
        unset($this->context->cookie->cardlink_refund_option_credit_slip);
        unset($this->context->cookie->cardlink_refund_option_voucher);
    }

    /**
     * Read a boolean-like option from hook params, altern payload, or request.
     *
     * @param array $params
    * @param array $candidateKeys
    * @param bool $allowKeywordSearch
     * @return bool|null
     */
    private function extractBooleanFromRefundContext($params, $candidateKeys, $allowKeywordSearch = true)
    {
        $sources = [];
        if (is_array($params)) {
            $sources[] = $params;

            if (isset($params['altern'])) {
                $altern = $params['altern'];
                if (is_object($altern)) {
                    $altern = get_object_vars($altern);
                }
                if (is_array($altern)) {
                    $sources[] = $altern;
                }
            }
        }

        foreach ($candidateKeys as $key) {
            foreach ($sources as $source) {
                if (array_key_exists($key, $source)) {
                    return $this->normalizeBooleanValue($source[$key]);
                }
            }

            $requestValue = Tools::getValue($key, null);
            if ($requestValue !== null) {
                return $this->normalizeBooleanValue($requestValue);
            }

            if ($allowKeywordSearch) {
                $requestValue = $this->findRequestValueByKeyword($key);
                if ($requestValue !== null) {
                    return $this->normalizeBooleanValue($requestValue);
                }
            }
        }

        return null;
    }

    /**
     * Search request payload for keys that contain a given keyword.
     * Useful when refund options are nested in structured form fields.
     *
     * @param string $keyword
     * @return mixed|null
     */
    private function findRequestValueByKeyword($keyword)
    {
        $needle = strtolower((string)$keyword);
        if ($needle === '') {
            return null;
        }

        $searchSpace = [
            isset($_POST) && is_array($_POST) ? $_POST : [],
            isset($_REQUEST) && is_array($_REQUEST) ? $_REQUEST : [],
        ];

        foreach ($searchSpace as $source) {
            $value = $this->findValueInArrayByKeyKeyword($source, $needle);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Recursively find first value whose key contains the given keyword.
     *
     * @param array $source
     * @param string $needle
     * @return mixed|null
     */
    private function findValueInArrayByKeyKeyword($source, $needle)
    {
        foreach ($source as $key => $value) {
            $keyString = strtolower((string)$key);
            if ($keyString !== '' && strpos($keyString, $needle) !== false) {
                return $value;
            }

            if (is_array($value)) {
                $nested = $this->findValueInArrayByKeyKeyword($value, $needle);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Normalize mixed value to bool or null when unknown.
     *
     * @param mixed $value
     * @return bool|null
     */
    private function normalizeBooleanValue($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((float)$value) > 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Convert tri-state bool to string for logs.
     *
     * @param bool|null $value
     * @return string
     */
    private function boolToDebugString($value)
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        return 'null';
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
            $title = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_TITLE, 'Pay using card');
            $description = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, '');

            $enableIrisPayments = Cardlink_Checkout\Constants::ENABLE_IRIS_PAYMENTS && Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE, '0');

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
            $displayLogo = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, '1');
            $cssUrl = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_CSS_URL, '');

            if (substr($cssUrl, 0, strlen('https://')) != 'https://') {
                $cssUrl = '';
            }

            $iris_title = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE, 'Pay using IRIS');
            $iris_description = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION, '');

            $iris_businessPartner = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER, Cardlink_Checkout\Constants::BUSINESS_PARTNER_CARDLINK);
            $iris_merchantId = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID, '');
            $iris_sharedSecret = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET, '');
            $iris_transactionEnvironment = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);

            $iris_displayLogo = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_DISPLAY_LOGO, '1');
            $iris_cssUrl = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_IRIS_CSS_URL, '');

            $iframeCheckout = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, '0');
            $forceStoreLanguage = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, '0');


            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_TITLE, $title);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $description);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER, $businessPartner);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID, $merchantId);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, $sharedSecret);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT, $transactionEnvironment);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION, $paymentAction);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS, $acceptInstallments);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, $fixedInstallments);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, $allowTokenization);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, $displayLogo);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_CSS_URL, $cssUrl);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE, $enableIrisPayments);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE, $iris_title);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION, $iris_description);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER, $iris_businessPartner);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID, $iris_merchantId);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET, $iris_sharedSecret);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT, $iris_transactionEnvironment);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_DISPLAY_LOGO, $iris_displayLogo);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_IRIS_CSS_URL, $iris_cssUrl);

            // Google Pay settings
            $enableGooglePay = Cardlink_Checkout\Constants::ENABLE_GOOGLEPAY && Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE, '0');
            $googlepay_title = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE, 'Pay with Google Pay');
            $googlepay_description = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION, '');
            $googlepay_businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
            $googlepay_merchantId = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID, '');
            $googlepay_sharedSecret = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET, '');
            $googlepay_transactionEnvironment = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
            $googlepay_displayLogo = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DISPLAY_LOGO, '1');
            $googlepay_mpiPrivateKey = Tools::getValue(
                Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY,
                Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY, null, null, null, '')
            );
            $googlepay_3dsUiMode = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT);

            $enableApplePay = Cardlink_Checkout\Constants::ENABLE_APPLEPAY && Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE, '0');
            $applepay_title = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE, 'Pay with Apple Pay');
            $applepay_description = self::getPostedMultilingualValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION, '');
            $applepay_businessPartner = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
            $applepay_merchantId = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID, '');
            $applepay_sharedSecret = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET, '');
            $applepay_transactionEnvironment = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
            $applepay_displayLogo = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DISPLAY_LOGO, '1');
            $applepay_mpiPrivateKey = Tools::getValue(
                Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY,
                Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY, null, null, null, '')
            );
            $applepay_3dsUiMode = Tools::getValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE, $enableGooglePay);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE, $googlepay_title);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION, $googlepay_description);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_BUSINESS_PARTNER, $googlepay_businessPartner);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID, $googlepay_merchantId);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET, $googlepay_sharedSecret);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT, $googlepay_transactionEnvironment);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DISPLAY_LOGO, $googlepay_displayLogo);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY, $googlepay_mpiPrivateKey);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE, $googlepay_3dsUiMode);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE, $enableApplePay);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE, $applepay_title);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION, $applepay_description);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_BUSINESS_PARTNER, $applepay_businessPartner);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID, $applepay_merchantId);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET, $applepay_sharedSecret);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT, $applepay_transactionEnvironment);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DISPLAY_LOGO, $applepay_displayLogo);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY, $applepay_mpiPrivateKey);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE, $applepay_3dsUiMode);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, $orderStatusCaptured);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, $orderStatusAuthorized);

            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, $iframeCheckout);
            Configuration::updateValue(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, $forceStoreLanguage);

            $output = $this->displayConfirmation($this->l('Settings updated'));
        }

        // display any message, then the form
        return $output . $this->displayForm();
    }

    /**
     * Register custom front routes for Google Pay endpoints that include extra path segments.
     * Needed for googlepaydirect.js calls to /module/cardlink_checkout/googlepaywallet/sale.
     *
     * @return array
     */
    public function hookModuleRoutes()
    {
        return [
            'module-cardlink_checkout-googlepaywallet-sale' => [
                'rule' => 'module/cardlink_checkout/googlepaywallet/sale',
                'keywords' => [],
                'controller' => 'googlepaywallet',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
            'module-cardlink_checkout-applepaywallet-sale' => [
                'rule' => 'module/cardlink_checkout/applepaywallet/sale',
                'keywords' => [],
                'controller' => 'applepaywallet',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
            'module-cardlink_checkout-applepaywallet-start-session' => [
                'rule' => 'module/cardlink_checkout/applepaywallet/start-session',
                'keywords' => [],
                'controller' => 'applepaywallet',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
            'module-cardlink_checkout-applepaywallet-startSession' => [
                'rule' => 'module/cardlink_checkout/applepaywallet/startSession',
                'keywords' => [],
                'controller' => 'applepaywallet',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
        ];
    }

    /**
     * Builds the configuration form
     * @return string HTML code
     */
    public function displayForm()
    {
        $retA = $this->renderAdditionalOptionsList();
        $retC = $this->renderConfigurationForm();

        return $retC . $retA;
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

        $bgConfirmationUrl = $this->context->link->getModuleLink($this->name, 'backgroundconfirmation', [], true);
        $bgConfirmationInfoHtml = '
            <div class="alert alert-info" style="margin-bottom:20px;">
                <p><strong>' . $this->l('Background Confirmation (Server-to-Server Notification)') . '</strong></p>
                <p>' . $this->l('To enable reliable order creation even when customers close their browser before returning to the store, you must activate the Background Confirmation (Server-to-Server notification) feature with Cardlink.') . '</p>
                <p>' . sprintf(
                    $this->l('Copy the URL below and send it to Cardlink support at %s to have it registered as your payment notification endpoint.'),
                    '<a href="mailto:ecommerce_support@cardlink.gr">ecommerce_support@cardlink.gr</a>'
                ) . '</p>
                <label class="control-label">' . $this->l('Background Confirmation URL') . '</label>
                <div class="input-group" style="margin-top:4px;">
                    <input type="text" class="form-control" readonly onclick="this.select();" value="' . htmlspecialchars($bgConfirmationUrl, ENT_QUOTES) . '" style="font-family:monospace;">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" onclick="var f=this.closest(\'.input-group\').querySelector(\'input\');f.select();document.execCommand(\'copy\');">' . $this->l('Copy') . '</button>
                    </span>
                </div>
                <small class="help-block" style="margin-top:4px;">' . $this->l('Click on the field to select the URL, then copy it.') . '</small>
            </div>';

        // Init Fields form array
        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Settings'),
                ],
                'input' => [
                    [
                        'type' => 'html',
                        'name' => 'background_confirmation_info',
                        'html_content' => $bgConfirmationInfoHtml,
                    ],
                    [
                        'type' => 'html',
                        'name' => 'custom_divider_1',
                        'html_content' => '<div style="border-bottom: 1px solid #000; margin: 10px 0;"><h3 class="modal-title">' . $this->l('Pay by Card') . '</h3></div>',
                    ],
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
                    ]
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];

        if (Cardlink_Checkout\Constants::ENABLE_IRIS_PAYMENTS) {
            $form['form']['input'] = array_merge($form['form']['input'], [
                [
                    'type' => 'html',
                    'name' => 'custom_divider_2',
                    'html_content' => '<div style="border-bottom: 1px solid #000; margin: 10px 0;"><h3 class="modal-title">' . $this->l('Pay through IRIS') . '</h3></div>',
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE,
                    'label' => $this->l('Pay through IRIS'),
                    'desc' => $this->l('Allow customers to pay using IRIS.'),
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
                    'type' => 'text',
                    'lang' => true,
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE,
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION,
                    'label' => $this->l('Description'),
                    'desc' => $this->l('A short description of the payment method to be displayed during the checkout.'),
                    'hint' => null,
                    'cols' => 50,
                    'rows' => 10,
                    'required' => false,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER,
                    'label' => $this->l('IRIS Business Partner'),
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID,
                    'label' => $this->l('IRIS Merchant ID'),
                    'desc' => $this->l('The merchant ID provided by Cardlink.'),
                    'hint' => null,
                    'size' => 20,
                    'maxlength' => 20,
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET,
                    'label' => $this->l('IRIS Shared Secret'),
                    'desc' => $this->l('The shared secret code provided by Cardlink.'),
                    'hint' => null,
                    'size' => 30,
                    'maxlength' => 30,
                    'required' => true,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT,
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_DISPLAY_LOGO,
                    'label' => $this->l('Display IRIS Logo'),
                    'desc' => $this->l('Display the IRIS logo next to the payment method title.'),
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_IRIS_CSS_URL,
                    'label' => $this->l('CSS URL'),
                    'desc' => $this->l('Full URL of custom CSS stylesheet, to be used to display IRIS payment page styles.'),
                    'hint' => null,
                    'size' => 50,
                    'maxlength' => 255,
                    'required' => false,
                    'pattern' => 'https://.*'
                ]
            ]);
        }

        if (Cardlink_Checkout\Constants::ENABLE_GOOGLEPAY) {
            $form['form']['input'] = array_merge($form['form']['input'], [
                [
                    'type' => 'html',
                    'name' => 'custom_divider_googlepay',
                    'html_content' => '<div style="border-bottom: 1px solid #000; margin: 10px 0;"><h3 class="modal-title">' . $this->l('Pay with Google Pay') . '</h3></div>',
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE,
                    'label' => $this->l('Enable Google Pay'),
                    'desc' => $this->l('Allow customers to pay using Google Pay.'),
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
                    'type' => 'text',
                    'lang' => true,
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE,
                    'label' => $this->l('Title'),
                    'desc' => $this->l('The title of the Google Pay payment method to be displayed during the checkout.'),
                    'hint' => null,
                    'size' => 50,
                    'maxlength' => 50,
                    'required' => true,
                ],
                [
                    'type' => 'textarea',
                    'lang' => true,
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION,
                    'label' => $this->l('Description'),
                    'desc' => $this->l('A short description of the Google Pay payment method to be displayed during the checkout.'),
                    'hint' => null,
                    'cols' => 50,
                    'rows' => 10,
                    'required' => false,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_BUSINESS_PARTNER,
                    'label' => $this->l('Google Pay Business Partner'),
                    'desc' => $this->l('Google Pay is supported only through Worldline.'),
                    'hint' => null,
                    'required' => true,
                    'options' => [
                        'query' => [
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID,
                    'label' => $this->l('Google Pay Merchant ID'),
                    'desc' => $this->l('The merchant ID provided by Cardlink for Google Pay.'),
                    'hint' => null,
                    'size' => 20,
                    'maxlength' => 20,
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET,
                    'label' => $this->l('Google Pay Shared Secret'),
                    'desc' => $this->l('The shared secret code provided by Cardlink for Google Pay.'),
                    'hint' => null,
                    'size' => 30,
                    'maxlength' => 30,
                    'required' => true,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT,
                    'label' => $this->l('Transaction Environment'),
                    'desc' => $this->l('Identify the working environment for Google Pay transactions.'),
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DISPLAY_LOGO,
                    'label' => $this->l('Display Google Pay Logo'),
                    'desc' => $this->l('Display the Google Pay logo next to the payment method title.'),
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
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE,
                    'label' => $this->l('3DS Display Mode'),
                    'desc' => $this->l('Choose how the 3D-Secure challenge is displayed for Google Pay.'),
                    'hint' => null,
                    'options' => [
                        'query' => [
                            [
                                'id_option' => Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT,
                                'name' => $this->l('Open in same tab (redirect)')
                            ],
                            [
                                'id_option' => Cardlink_Checkout\Constants::THREE_DS_UI_MODE_IFRAME_MODAL,
                                'name' => $this->l('Open inside modal iframe')
                            ]
                        ],
                        'id' => 'id_option',
                        'name' => 'name'
                    ]
                ]
            ]);
        }

        if (Cardlink_Checkout\Constants::ENABLE_APPLEPAY) {
            $form['form']['input'] = array_merge($form['form']['input'], [
                [
                    'type' => 'html',
                    'name' => 'custom_divider_applepay',
                    'html_content' => '<div style="border-bottom: 1px solid #000; margin: 10px 0;"><h3 class="modal-title">' . $this->l('Pay with Apple Pay') . '</h3></div>',
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE,
                    'label' => $this->l('Enable Apple Pay'),
                    'desc' => $this->l('Allow customers to pay using Apple Pay.'),
                    'options' => [
                        'query' => [
                            ['id_option' => '1', 'name' => $this->l('Enabled')],
                            ['id_option' => '0', 'name' => $this->l('Disabled')],
                        ],
                        'id' => 'id_option',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'lang' => true,
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE,
                    'label' => $this->l('Title'),
                    'desc' => $this->l('The title of the Apple Pay payment method to be displayed during the checkout.'),
                    'size' => 50,
                    'maxlength' => 50,
                    'required' => true,
                ],
                [
                    'type' => 'textarea',
                    'lang' => true,
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION,
                    'label' => $this->l('Description'),
                    'desc' => $this->l('A short description of the Apple Pay payment method to be displayed during the checkout.'),
                    'cols' => 50,
                    'rows' => 10,
                    'required' => false,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_BUSINESS_PARTNER,
                    'label' => $this->l('Apple Pay Business Partner'),
                    'desc' => $this->l('Apple Pay is supported only through Worldline.'),
                    'required' => true,
                    'options' => [
                        'query' => [
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID,
                    'label' => $this->l('Apple Pay Merchant ID'),
                    'desc' => $this->l('The merchant ID provided by Cardlink for Apple Pay.'),
                    'size' => 20,
                    'maxlength' => 20,
                    'required' => true,
                ],
                [
                    'type' => 'text',
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET,
                    'label' => $this->l('Apple Pay Shared Secret'),
                    'desc' => $this->l('The shared secret code provided by Cardlink for Apple Pay.'),
                    'size' => 30,
                    'maxlength' => 30,
                    'required' => true,
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT,
                    'label' => $this->l('Transaction Environment'),
                    'desc' => $this->l('Identify the working environment for Apple Pay transactions.'),
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
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DISPLAY_LOGO,
                    'label' => $this->l('Display Apple Pay Logo'),
                    'desc' => $this->l('Display the Apple Pay logo next to the payment method title.'),
                    'options' => [
                        'query' => [
                            ['id_option' => '1', 'name' => $this->l('Display Logo')],
                            ['id_option' => '0', 'name' => $this->l('Hide Logo')],
                        ],
                        'id' => 'id_option',
                        'name' => 'name'
                    ]
                ],
                [
                    'type' => 'select',
                    'name' => Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE,
                    'label' => $this->l('3DS Display Mode'),
                    'desc' => $this->l('Choose how the 3D-Secure challenge is displayed for Apple Pay.'),
                    'options' => [
                        'query' => [
                            [
                                'id_option' => Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT,
                                'name' => $this->l('Open in same tab (redirect)')
                            ],
                            [
                                'id_option' => Cardlink_Checkout\Constants::THREE_DS_UI_MODE_IFRAME_MODAL,
                                'name' => $this->l('Open inside modal iframe')
                            ]
                        ],
                        'id' => 'id_option',
                        'name' => 'name'
                    ]
                ],
            ]);
        }

        $form['form']['input'] = array_merge($form['form']['input'], [
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
            ]
        ]);


        $form['form']['input'] = array_merge($form['form']['input'], [
            [
                'type' => 'html',
                'name' => 'custom_divider_1',
                'html_content' => '<div style="border-bottom: 1px solid #000; margin: 10px 0;"><h3 class="modal-title">' . $this->l('Display Settings') . '</h3></div>',
            ],
            [
                'type' => 'select',
                'name' => Cardlink_Checkout\Constants::CONFIG_USE_IFRAME,
                'label' => $this->l('Checkout without Leaving Your Store'),
                'desc' => $this->l('For card payments only! Perform the payment flow without having the customers leave your website for Cardlink\'s payment gateway. You will need to have a valid SSL certificate properly configured on your domain.'),
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
        ]);

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
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_DESCRIPTION][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay via Cardlink: Accepts Visa, Mastercard, Maestro, American Express, Diners, Discover.', false, $language['locale']));
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay through IRIS', false, $language['locale']));
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay via your bank\'s web banking application.', false, $language['locale']));
        }

        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_BUSINESS_PARTNER, null, null, null, Cardlink_Checkout\Constants::BUSINESS_PARTNER_CARDLINK);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_MERCHANT_ID, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_SHARED_SECRET, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TRANSACTION_ENVIRONMENT, null, null, null, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_PAYMENT_ACTION, null, null, null, Cardlink_Checkout\Constants::PAYMENT_ACTION_SALE);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ACCEPT_INSTALLMENTS, null, null, null, 'no');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_FIXED_MAX_INSTALLMENTS, null, null, null, '3');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ALLOW_TOKENIZATION, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DISPLAY_LOGO, null, null, null, '1');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_CSS_URL] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_CSS_URL, null, null, null, '');

        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_BUSINESS_PARTNER, null, null, null, Cardlink_Checkout\Constants::BUSINESS_PARTNER_NEXI);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_MERCHANT_ID, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_SHARED_SECRET, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_TRANSACTION_ENVIRONMENT, null, null, null, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_DISPLAY_LOGO] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_DISPLAY_LOGO, null, null, null, '1');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_IRIS_CSS_URL] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_CSS_URL, null, null, null, '');

        // Google Pay field values
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE, null, null, null, '0');
        foreach ($helper->languages as $language) {
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay with Google Pay', false, $language['locale']));
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION, $language['id_lang'], $idShopGroup, $idShop, $this->l('Fast, simple checkout with Google Pay.', false, $language['locale']));
        }
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_BUSINESS_PARTNER] = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MERCHANT_ID, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_SHARED_SECRET, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT, null, null, null, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DISPLAY_LOGO] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DISPLAY_LOGO, null, null, null, '1');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE, null, null, null, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT);

        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE, null, null, null, '0');
        foreach ($helper->languages as $language) {
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE, $language['id_lang'], $idShopGroup, $idShop, $this->l('Pay with Apple Pay', false, $language['locale']));
            $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION][$language['id_lang']] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION, $language['id_lang'], $idShopGroup, $idShop, $this->l('Fast, secure checkout with Apple Pay.', false, $language['locale']));
        }
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_BUSINESS_PARTNER] = Cardlink_Checkout\Constants::BUSINESS_PARTNER_WORLDLINE;
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MERCHANT_ID, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_SHARED_SECRET, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT, null, null, null, Cardlink_Checkout\Constants::TRANSACTION_ENVIRONMENT_SANDBOX);
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DISPLAY_LOGO] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DISPLAY_LOGO, null, null, null, '1');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_MPI_PRIVATE_KEY, null, null, null, '');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE, null, null, null, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT);

        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_USE_IFRAME] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_USE_IFRAME, null, null, null, '0');
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_FORCE_STORE_LANGUAGE, null, null, null, '0');

        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_CAPTURED, null, null, null, Configuration::get('PS_OS_PAYMENT'));
        $helper->fields_value[Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED] = Configuration::get(Cardlink_Checkout\Constants::CONFIG_ORDER_STATUS_AUTHORIZED, null, null, null, Configuration::get('PS_CHECKOUT_STATE_AUTHORIZED'));

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
        $content = Db::getInstance()->executeS($sql);

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

        $idLang = (int) Context::getContext()->cookie->id_lang;

        $shop = Context::getContext()->shop;
        $idShopGroup = (int) $shop->id_shop_group;
        $idShop = (int) $shop->id;

        $title = Configuration::get(Cardlink_Checkout\Constants::CONFIG_TITLE, $idLang, $idShopGroup, $idShop, '');
        $description = Configuration::get(Cardlink_Checkout\Constants::CONFIG_DESCRIPTION, $idLang, $idShopGroup, $idShop, '');
        $iris_title = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_TITLE, $idLang, $idShopGroup, $idShop, '');
        $iris_description = Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_DESCRIPTION, $idLang, $idShopGroup, $idShop, '');

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

        $frontCssRelativePath = 'views/css/front-custom.css';
        $frontCssVersion = @filemtime(dirname(__FILE__) . '/' . $frontCssRelativePath);
        if (!$frontCssVersion) {
            $frontCssVersion = time();
        }
        $frontCssUrl = $this->_path . $frontCssRelativePath . '?v=' . $frontCssVersion;

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
            'deleteStoredTokenUrl' => $this->context->link->getModuleLink($this->name, 'tokenization', ['ajax' => true], true),
            'iris_title' => $iris_title,
            'iris_description' => trim($iris_description),
            'iris_logo_url' => $this->_path . 'views/images/logo_iris.jpg',
            'front_css_url' => $frontCssUrl,
        ]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:cardlink_checkout/views/templates/hook/card_payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $cardPaymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
        $cardPaymentOption->setModuleName(Cardlink_Checkout\Constants::MODULE_NAME)
            ->setCallToActionText($title)
            ->setForm($paymentForm);
        //->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/images/cardlink.svg'))

        $paymentMethodOptions = [$cardPaymentOption];

        $enableIrisPayments = Cardlink_Checkout\Constants::ENABLE_IRIS_PAYMENTS && boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_IRIS_ENABLE, $idLang, $idShopGroup, $idShop, '0'));

        if ($enableIrisPayments) {
            /**
             *  Load form template to be displayed in the checkout step
             */
            $irisPaymentForm = $this->fetch('module:cardlink_checkout/views/templates/hook/iris_payment_options.tpl');

            $irisPaymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $irisPaymentOption->setModuleName(Cardlink_Checkout\Constants::MODULE_NAME)
                ->setCallToActionText($iris_title)
                ->setForm($irisPaymentForm)
                //->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/images/iris.svg'))
            ;

            $paymentMethodOptions[] = $irisPaymentOption;
        }

        $googlepayInitialError = '';
        if (isset($this->context->cookie->cardlink_gpay_error) && !empty($this->context->cookie->cardlink_gpay_error)) {
            $googlepayInitialError = (string) $this->context->cookie->cardlink_gpay_error;
            unset($this->context->cookie->cardlink_gpay_error);

            if (isset($this->context->controller) && isset($this->context->controller->errors) && is_array($this->context->controller->errors)) {
                $this->context->controller->errors[] = $googlepayInitialError;
            }
        }

        $applepayInitialError = '';
        if (isset($this->context->cookie->cardlink_apay_error) && !empty($this->context->cookie->cardlink_apay_error)) {
            $applepayInitialError = (string) $this->context->cookie->cardlink_apay_error;
            unset($this->context->cookie->cardlink_apay_error);

            if (isset($this->context->controller) && isset($this->context->controller->errors) && is_array($this->context->controller->errors)) {
                $this->context->controller->errors[] = $applepayInitialError;
            }
        }

        $enableGooglePayPayments = Cardlink_Checkout\Constants::ENABLE_GOOGLEPAY && boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_ENABLE, $idLang, $idShopGroup, $idShop, '0'));

        if ($enableGooglePayPayments) {
            $googlepay_title = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_TITLE, $idLang, $idShopGroup, $idShop, '');
            $googlepay_description = Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_DESCRIPTION, $idLang, $idShopGroup, $idShop, '');

            $cart = $params['cart'];
            $currency = new \Currency($cart->id_currency);
            $currencyIso = $currency->iso_code;
            $googlePayCheckoutJsRelativePath = 'views/js/googlepay-checkout.js';
            $googlePayCheckoutJsVersion = @filemtime(dirname(__FILE__) . '/' . $googlePayCheckoutJsRelativePath);
            if (!$googlePayCheckoutJsVersion) {
                $googlePayCheckoutJsVersion = time();
            }

            $googlePayCheckoutJsUrl = $this->_path . $googlePayCheckoutJsRelativePath . '?v=' . $googlePayCheckoutJsVersion;

            if (method_exists($this->context->controller, 'registerJavascript')) {
                $this->context->controller->registerJavascript(
                    'module-cardlink-googlepay-checkout',
                    $googlePayCheckoutJsUrl,
                    [
                        'priority' => 150,
                        'position' => 'bottom',
                    ]
                );
            } else {
                $this->context->controller->addJS($googlePayCheckoutJsUrl, false);
            }

            $this->smarty->assign([
                'action' => $formAction,
                'googlepay_logo_url' => $this->_path . 'views/images/googlepay.svg',
                'googlepay_description' => trim($googlepay_description),
                'googlepay_script_info_url' => $this->context->link->getModuleLink($this->name, 'googlepayajax', ['gpay_action' => 'init'], true),
                'googlepay_wallet_url' => $this->context->link->getModuleLink($this->name, 'googlepaywallet', [], true),
                'googlepay_create_xid_url' => $this->context->link->getModuleLink($this->name, 'googlepayajax', ['gpay_action' => 'createxid'], true),
                'googlepay_sign_data_url' => $this->context->link->getModuleLink($this->name, 'googlepayajax', ['gpay_action' => 'signdata'], true),
                'googlepay_mpi_url' => Cardlink_Checkout\GooglePayHelper::getMpiUrl(),
                'googlepay_3ds_success_url' => $this->context->link->getModuleLink($this->name, 'googlepay3ds', ['status' => 'success'], true),
                'googlepay_3ds_failure_url' => $this->context->link->getModuleLink($this->name, 'googlepay3ds', ['status' => 'failure'], true),
                'googlepay_direct_script_url' => Cardlink_Checkout\GooglePayHelper::getDirectScriptUrl(),
                'googlepay_cart_id' => $cart->id,
                'googlepay_order_total' => number_format($total_cart, 2, '.', ''),
                'googlepay_currency_code' => $currencyIso,
                'googlepay_currency_numeric' => Cardlink_Checkout\GooglePayHelper::getCurrencyNumericCode($currencyIso),
                'googlepay_checkout_js_url' => $googlePayCheckoutJsUrl,
                'front_css_url' => $frontCssUrl,
                'googlepay_initial_error' => $googlepayInitialError,
                'googlepay_3ds_ui_mode' => Configuration::get(Cardlink_Checkout\Constants::CONFIG_GOOGLEPAY_3DS_UI_MODE, null, null, null, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT),
            ]);

            $googlepayPaymentForm = $this->fetch('module:cardlink_checkout/views/templates/hook/googlepay_payment_options.tpl');

            $googlepayPaymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $googlepayPaymentOption->setModuleName(Cardlink_Checkout\Constants::MODULE_NAME)
                ->setCallToActionText($googlepay_title)
                ->setForm($googlepayPaymentForm);

            $paymentMethodOptions[] = $googlepayPaymentOption;
        }

        $enableApplePayPayments = Cardlink_Checkout\Constants::ENABLE_APPLEPAY && boolval(Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_ENABLE, $idLang, $idShopGroup, $idShop, '0'));
        if ($enableApplePayPayments) {
            $applepay_title = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_TITLE, $idLang, $idShopGroup, $idShop, '');
            $applepay_description = Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_DESCRIPTION, $idLang, $idShopGroup, $idShop, '');

            $cart = $params['cart'];
            $currency = new \Currency($cart->id_currency);
            $currencyIso = $currency->iso_code;
            $applePayCheckoutJsRelativePath = 'views/js/applepay-checkout.js';
            $applePayCheckoutJsVersion = @filemtime(dirname(__FILE__) . '/' . $applePayCheckoutJsRelativePath);
            if (!$applePayCheckoutJsVersion) {
                $applePayCheckoutJsVersion = time();
            }

            $applePayCheckoutJsUrl = $this->_path . $applePayCheckoutJsRelativePath . '?v=' . $applePayCheckoutJsVersion;

            if (method_exists($this->context->controller, 'registerJavascript')) {
                $this->context->controller->registerJavascript(
                    'module-cardlink-applepay-checkout',
                    $applePayCheckoutJsUrl,
                    [
                        'priority' => 150,
                        'position' => 'bottom',
                    ]
                );
            } else {
                $this->context->controller->addJS($applePayCheckoutJsUrl, false);
            }

            $this->smarty->assign([
                'action' => $formAction,
                'applepay_logo_url' => $this->_path . 'views/images/applepay.svg',
                'applepay_description' => trim($applepay_description),
                'applepay_script_info_url' => $this->context->link->getModuleLink($this->name, 'applepayajax', ['apay_action' => 'init'], true),
                'applepay_wallet_url' => $this->context->link->getModuleLink($this->name, 'applepaywallet', [], true),
                'applepay_create_xid_url' => $this->context->link->getModuleLink($this->name, 'applepayajax', ['apay_action' => 'createxid'], true),
                'applepay_sign_data_url' => $this->context->link->getModuleLink($this->name, 'applepayajax', ['apay_action' => 'signdata'], true),
                'applepay_mpi_url' => Cardlink_Checkout\ApplePayHelper::getMpiUrl(),
                'applepay_3ds_success_url' => $this->context->link->getModuleLink($this->name, 'applepay3ds', ['status' => 'success'], true),
                'applepay_3ds_failure_url' => $this->context->link->getModuleLink($this->name, 'applepay3ds', ['status' => 'failure'], true),
                'applepay_direct_script_url' => Cardlink_Checkout\ApplePayHelper::getDirectScriptUrl(),
                'applepay_cart_id' => $cart->id,
                'applepay_order_total' => number_format($total_cart, 2, '.', ''),
                'applepay_currency_code' => $currencyIso,
                'applepay_currency_numeric' => Cardlink_Checkout\ApplePayHelper::getCurrencyNumericCode($currencyIso),
                'applepay_checkout_js_url' => $applePayCheckoutJsUrl,
                'front_css_url' => $frontCssUrl,
                'applepay_initial_error' => $applepayInitialError,
                'applepay_3ds_ui_mode' => Configuration::get(Cardlink_Checkout\Constants::CONFIG_APPLEPAY_3DS_UI_MODE, null, null, null, Cardlink_Checkout\Constants::THREE_DS_UI_MODE_REDIRECT),
            ]);

            $applepayPaymentForm = $this->fetch('module:cardlink_checkout/views/templates/hook/applepay_payment_options.tpl');

            $applepayPaymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $applepayPaymentOption->setModuleName(Cardlink_Checkout\Constants::MODULE_NAME)
                ->setCallToActionText($applepay_title)
                ->setForm($applepayPaymentForm);

            $paymentMethodOptions[] = $applepayPaymentOption;
        }

        return $paymentMethodOptions;
    }

    /**
     * Display a message in the displayPaymentReturn hook
     * 
     * @param array $params
     * @return string
     */
    public function hookDisplayPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:cardlink_checkout/views/templates/hook/payment_return.tpl');
    }
}
