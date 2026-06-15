<?php

/**
 * Cardlink Checkout - A Payment Module for PrestaShop 1.7
 *
 * Payment Transaction Model - Stores gateway transaction details
 *
 * @author Cardlink S.A. <ecommerce_support@cardlink.gr>
 * @license https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

use PrestaShop\PrestaShop\Core\Foundation\Database\EntityManager;

class CardlinkPaymentTransaction extends ObjectModel
{
    /**
     * @var int Order ID
     */
    public $id_order;

    /**
     * @var string Gateway's order ID (includes 4-character suffix, e.g., "000000123xABCD")
     */
    public $cardlink_order_id;

    /**
     * @var string Transaction ID from gateway
     */
    public $cardlink_tx_id;

    /**
     * @var string Payment status (AUTHORIZED, CAPTURED, CANCELED, REFUSED, ERROR)
     */
    public $cardlink_pay_status;

    /**
     * @var string Payment method (VISA, MASTERCARD, IRIS, etc.)
     */
    public $cardlink_pay_method;

    /**
     * @var string Payment reference from gateway
     */
    public $cardlink_pay_ref;

    /**
     * @var float Order amount
     */
    public $order_amount;

    /**
     * @var string Currency code
     */
    public $currency;

    /**
     * @var int Parent transaction ID (for partial refunds/voids)
     */
    public $parent_transaction_id;

    /**
     * @var string Transaction type (sale, authorize, capture, void, refund)
     */
    public $transaction_type;

    /**
     * @var datetime Creation date
     */
    public $date_add;

    /**
     * @var datetime Last update date
     */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'cardlink_payment_transactions',
        'primary' => 'id',
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => true),
            'cardlink_order_id' => array('type' => self::TYPE_STRING, 'size' => 50, 'required' => true),
            'cardlink_tx_id' => array('type' => self::TYPE_STRING, 'size' => 100),
            'cardlink_pay_status' => array('type' => self::TYPE_STRING, 'size' => 50),
            'cardlink_pay_method' => array('type' => self::TYPE_STRING, 'size' => 50),
            'cardlink_pay_ref' => array('type' => self::TYPE_STRING, 'size' => 100),
            'order_amount' => array('type' => self::TYPE_FLOAT),
            'currency' => array('type' => self::TYPE_STRING, 'size' => 3),
            'parent_transaction_id' => array('type' => self::TYPE_INT),
            'transaction_type' => array('type' => self::TYPE_STRING, 'size' => 50),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Get the primary transaction for an order
     *
     * @param int $id_order
     * @return CardlinkPaymentTransaction|null
     */
    public static function getPrimaryTransactionByOrder($id_order)
    {
        // Use direct SQL with executeS instead of DbQuery with getRow
        // due to PrestaShop compatibility issues with getRow on custom tables
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'cardlink_payment_transactions` 
                WHERE `id_order` = ' . (int)$id_order . ' 
                AND (`parent_transaction_id` IS NULL OR `parent_transaction_id` = 0)
                ORDER BY `date_add` DESC 
                LIMIT 1';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return null;
        }

        $result = $results[0];
        $transaction = new self();
        foreach ($result as $key => $value) {
            if (property_exists($transaction, $key)) {
                $transaction->$key = $value;
            }
        }
        $transaction->id = $result['id'];

        return $transaction;
    }

    /**
     * Get all transactions for an order
     *
     * @param int $id_order
     * @return array
     */
    public static function getTransactionsByOrder($id_order)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('cardlink_payment_transactions');
        $sql->where('id_order = ' . (int)$id_order);
        $sql->orderBy('date_add DESC');

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Get transaction by gateway order ID
     *
     * @param string $cardlink_order_id
     * @return CardlinkPaymentTransaction|null
     */
    public static function getByCardlinkOrderId($cardlink_order_id)
    {
        // Use direct SQL with executeS instead of DbQuery with getRow
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'cardlink_payment_transactions` 
                WHERE `cardlink_order_id` = "' . pSQL($cardlink_order_id) . '"
                LIMIT 1';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return null;
        }

        $result = $results[0];
        $transaction = new self();
        foreach ($result as $key => $value) {
            if (property_exists($transaction, $key)) {
                $transaction->$key = $value;
            }
        }
        $transaction->id = $result['id'];

        return $transaction;
    }

    /**
     * Get the original payment transaction (sale or authorize type) for an order.
     * Used to determine the original payment type and date for same-day business rules.
     *
     * @param int $id_order
     * @return CardlinkPaymentTransaction|null
     */
    public static function getOriginalTransactionByOrder($id_order)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'cardlink_payment_transactions`
                WHERE `id_order` = ' . (int)$id_order . '
                AND `transaction_type` IN (\'sale\', \'authorize\')
                ORDER BY `date_add` ASC
                LIMIT 1';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return null;
        }

        $result = $results[0];
        $transaction = new self();
        foreach ($result as $key => $value) {
            if (property_exists($transaction, $key)) {
                $transaction->$key = $value;
            }
        }
        $transaction->id = $result['id'];

        return $transaction;
    }

    /**
     * Get the most recent capture transaction for an order.
     * Used to determine the capture date for same-day business rules on preauth transactions.
     *
     * @param int $id_order
     * @return CardlinkPaymentTransaction|null
     */
    public static function getCaptureTransactionByOrder($id_order)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'cardlink_payment_transactions`
                WHERE `id_order` = ' . (int)$id_order . '
                AND `transaction_type` = \'capture\'
                ORDER BY `date_add` DESC
                LIMIT 1';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return null;
        }

        $result = $results[0];
        $transaction = new self();
        foreach ($result as $key => $value) {
            if (property_exists($transaction, $key)) {
                $transaction->$key = $value;
            }
        }
        $transaction->id = $result['id'];

        return $transaction;
    }

    /**
     * Create a new transaction record
     *
     * @param array $data
     * @return CardlinkPaymentTransaction
     */
    public static function createTransaction($data)
    {
        $transaction = new self();
        $transaction->id_order = $data['id_order'];
        $transaction->cardlink_order_id = $data['cardlink_order_id'];
        $transaction->cardlink_tx_id = $data['cardlink_tx_id'] ?? null;
        $transaction->cardlink_pay_status = $data['cardlink_pay_status'] ?? null;
        $transaction->cardlink_pay_method = $data['cardlink_pay_method'] ?? null;
        $transaction->cardlink_pay_ref = $data['cardlink_pay_ref'] ?? null;
        $transaction->order_amount = $data['order_amount'] ?? 0;
        $transaction->currency = $data['currency'] ?? 'EUR';
        $transaction->parent_transaction_id = $data['parent_transaction_id'] ?? null;
        $transaction->transaction_type = $data['transaction_type'] ?? 'sale';

        if ($transaction->save()) {
            return $transaction;
        }

        return null;
    }
}
