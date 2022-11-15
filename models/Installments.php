<?php

namespace Cardlink_Checkout;

use ObjectModel;

class Installments extends ObjectModel
{
    public const IDENTIFIER = 'id';

    public $id;
    public $min_amount;
    public $max_amount;
    public $max_installments;
    public $date_add;
    public $date_upd;
    // 

    /*
    * @see ObjectModel::$definition => (this is the Model)
    */
    public static $definition = [
        'table' => Constants::TABLE_NAME_INSTALLMENTS,
        'primary' => self::IDENTIFIER,
        'fields' => [
            self::IDENTIFIER => ['type' => ObjectModel::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'min_amount' => ['type' => ObjectModel::TYPE_FLOAT],
            'max_amount' => ['type' => ObjectModel::TYPE_FLOAT],
            'max_installments' => ['type' => ObjectModel::TYPE_INT],
            'date_add' => ['type' => ObjectModel::TYPE_DATE],
            'date_upd' => ['type' => ObjectModel::TYPE_DATE]
        ],
    ];
}
