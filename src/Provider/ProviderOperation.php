<?php

declare(strict_types=1);

namespace EbizChargeShopware\Provider;

enum ProviderOperation: string
{
    case CONNECTION_TEST = 'GetMerchantTransactionData';
    case GET_WEBFORM_URL = 'GetEbizWebFormURL';
    case GET_TRANSACTION_DETAILS = 'GetTransactionDetails';
    case SEARCH_RECEIVED_PAYMENTS = 'SearchEbizWebFormReceivedPayments';
    case MARK_WEBFORM_PAYMENT_APPLIED = 'MarkEbizWebFormPaymentAsApplied';
    case RUN_TRANSACTION = 'runTransaction';
    case ADD_CUSTOMER = 'AddCustomer';
    case SEARCH_CUSTOMERS = 'SearchCustomers';
    case GET_CUSTOMER_TOKEN = 'GetCustomerToken';
    case GET_CUSTOMER_PAYMENT_METHOD_PROFILES = 'GetCustomerPaymentMethodProfiles';
    case DELETE_CUSTOMER_PAYMENT_METHOD_PROFILE = 'DeleteCustomerPaymentMethodProfile';
    case SET_DEFAULT_CUSTOMER_PAYMENT_METHOD_PROFILE = 'SetDefaultCustomerPaymentMethodProfile';
    case RUN_CUSTOMER_TRANSACTION = 'runCustomerTransaction';

    public function bodyRoot(): string
    {
        return match ($this) {
            self::CONNECTION_TEST => 'getMerchantTransactionData',
            self::GET_WEBFORM_URL => 'getEbizWebFormURL',
            self::GET_TRANSACTION_DETAILS => 'getTransactionDetails',
            self::SEARCH_RECEIVED_PAYMENTS => 'searchEbizWebFormReceivedPayments',
            self::MARK_WEBFORM_PAYMENT_APPLIED => 'markEbizWebFormPaymentAsApplied',
            self::RUN_TRANSACTION => 'runTransaction',
            self::ADD_CUSTOMER => 'addCustomer',
            self::SEARCH_CUSTOMERS => 'searchCustomers',
            self::GET_CUSTOMER_TOKEN => 'getCustomerToken',
            self::GET_CUSTOMER_PAYMENT_METHOD_PROFILES => 'getCustomerPaymentMethodProfiles',
            self::DELETE_CUSTOMER_PAYMENT_METHOD_PROFILE => 'deleteCustomerPaymentMethodProfile',
            self::SET_DEFAULT_CUSTOMER_PAYMENT_METHOD_PROFILE => 'setDefaultCustomerPaymentMethodProfile',
            self::RUN_CUSTOMER_TRANSACTION => 'runCustomerTransaction',
        };
    }
}
