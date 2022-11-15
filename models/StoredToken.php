<?php

namespace Cardlink_Checkout;

use ObjectModel;

class StoredToken extends ObjectModel
{
    public const IDENTIFIER = 'id';

    public $id;
    public $id_customer;
    public $active;
    public $token;
    public $type;
    public $last_4digits;
    public $expiration;
    public $date_add;
    // 

    /*
    * @see ObjectModel::$definition => (this is the Model)
    */
    public static $definition = [
        'table' => Constants::TABLE_NAME_STORED_TOKENS,
        'primary' => self::IDENTIFIER,
        'fields' => [
            self::IDENTIFIER => ['type' => ObjectModel::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'id_customer' => ['type' => ObjectModel::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'active' => ['type' => ObjectModel::TYPE_BOOL],
            'token' => ['type' => ObjectModel::TYPE_STRING, 'validate' => 'isString'],
            'type' => ['type' => ObjectModel::TYPE_STRING, 'validate' => 'isString'],
            'last_4digits' => ['type' => ObjectModel::TYPE_STRING, 'validate' => 'isString', 'size' => 4],
            'expiration' => ['type' => ObjectModel::TYPE_STRING, 'validate' => 'isString', 'size' => 8],
            'date_add' => ['type' => ObjectModel::TYPE_DATE]
        ],
    ];


    /**
     * Returns the year part of the card's expiration date.
     * 
     * @return string
     */
    public function getExpiryYear()
    {
        return substr($this->expiration, 0, 4);
    }

    /**
     * Returns the month part of the card's expiration date.
     * 
     * @return string
     */
    public function getExpiryMonth()
    {
        return substr($this->expiration, 4, 2);
    }

    /**
     * Returns the day part of the card's expiration date.
     * 
     * @return string
     */
    public function getExpiryDay()
    {
        return substr($this->expiration, 6, 2);
    }

    /**
     * Determines that the token is active and not currently expired.
     * 
     * @return bool
     */
    public function isValid()
    {
        return $this->active && date('Ymd') <= $this->expiration;
    }

    /**
     * Returns a formatted expiration date string containing the month and year parts.
     * 
     * @return string
     */
    public function getFormattedExpiryDate()
    {
        return str_pad($this->getExpiryMonth(), 2, '0', STR_PAD_LEFT) . '/' . $this->getExpiryYear();
    }
}
