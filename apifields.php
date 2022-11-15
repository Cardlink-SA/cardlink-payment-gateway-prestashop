<?php

namespace Cardlink_Checkout;

/**
 * Class containing constants of API field names and their proper order in API requests and/or responses.
 * 
 * @author Cardlink S.A.
 */
class ApiFields
{
    const Version = 'version';
    const MerchantId = 'mid';
    const Language = 'lang';
    const DeviceCategory = 'deviceCategory';
    const OrderId = 'orderid';
    const OrderDescription = 'orderDesc';
    const OrderAmount = 'orderAmount';
    const Currency = 'currency';
    const PayerEmail = 'payerEmail';
    const PayerPhone = 'payerPhone';
    const BillCountry = 'billCountry';
    const BillState = 'billState';
    const BillZip = 'billZip';
    const BillCity = 'billCity';
    const BillAddress = 'billAddress';
    const Weight = 'weight';
    const Dimensions = "dimensions";
    const ShipCountry = "shipCountry";
    const ShipState = "shipState";
    const ShipZip = 'shipZip';
    const ShipCity = 'shipCity';
    const ShipAddress = 'shipAddress';
    const AddFraudScore = 'addFraudScore';
    const MaxPayRetries = 'maxPayRetries';
    const Reject3dsU = 'reject3dsU';
    const PaymentMethod = 'payMethod';
    const TransactionType = 'trType';
    const ExtInstallmentoffset = 'extInstallmentoffset';
    const ExtInstallmentperiod = 'extInstallmentperiod';
    const ExtRecurringfrequency = 'extRecurringfrequency';
    const ExtRecurringenddate = 'extRecurringenddate';
    const BlockScore = 'blockScore';
    const CssUrl = 'cssUrl';
    const ConfirmUrl = 'confirmUrl';
    const CancelUrl = 'cancelUrl';
    const ExtTokenOptions = 'extTokenOptions';
    const ExtToken = 'extToken';
    const ExtTokenPanEnd = 'extTokenPanEnd';
    const ExtTokenExpiration = 'extTokenExp';
    const Var1 = 'var1';
    const Var2 = 'var2';
    const Var3 = 'var3';
    const Var4 = 'var4';
    const Var5 = 'var5';
    const Var6 = 'var6';
    const Var7 = 'var7';
    const Var8 = 'var8';
    const Var9 = 'var9';
    const Digest = 'digest';
    const PaymentTotal = 'paymentTotal';
    const PaymentReferenceId = 'paymentRef';
    const Message = 'message';
    const TransactionId = 'txId';
    const RiskScore = 'riskScore';
    const Status = 'status';

    /**
     * Order of API fields for calculation of the digest field in a transaction request message.
     */
    const TRANSACTION_REQUEST_DIGEST_CALCULATION_FIELD_ORDER = [
        self::Version,
        self::MerchantId,
        self::Language,
        self::DeviceCategory,
        self::OrderId,
        self::OrderDescription,
        self::OrderAmount,
        self::Currency,
        self::PayerEmail,
        self::PayerPhone,
        self::BillCountry,
        self::BillState,
        self::BillZip,
        self::BillCity,
        self::BillAddress,
        self::Weight,
        self::Dimensions,
        self::ShipCountry,
        self::ShipState,
        self::ShipZip,
        self::ShipCity,
        self::ShipAddress,
        self::AddFraudScore,
        self::MaxPayRetries,
        self::Reject3dsU,
        self::PaymentMethod,
        self::TransactionType,
        self::ExtInstallmentoffset,
        self::ExtInstallmentperiod,
        self::ExtRecurringfrequency,
        self::ExtRecurringenddate,
        self::BlockScore,
        self::CssUrl,
        self::ConfirmUrl,
        self::CancelUrl,
        self::ExtTokenOptions,
        self::ExtToken,
        self::Var1,
        self::Var2,
        self::Var3,
        self::Var4,
        self::Var5,
        self::Var6,
        self::Var7,
        self::Var8,
        self::Var9,
        self::Digest,
    ];

    /**
     * Order of API fields for calculation of the digest field in a transaction response message.
     */
    const TRANSACTION_RESPONSE_DIGEST_CALCULATION_FIELD_ORDER = [
        self::Version,
        self::MerchantId,
        self::OrderId,
        self::Status,
        self::OrderAmount,
        self::Currency,
        self::PaymentTotal,
        self::Message,
        self::RiskScore,
        self::PaymentMethod,
        self::TransactionId,
        self::PaymentReferenceId,
        self::ExtToken,
        self::ExtTokenPanEnd,
        self::ExtTokenExpiration,
        self::Digest
    ];
}
