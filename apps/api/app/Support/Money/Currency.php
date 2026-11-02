<?php

namespace App\Support\Money;

/**
 * Currencies the platform handles. Customers always pay in SAR;
 * supplier quotations may arrive in USD or CNY.
 */
enum Currency: string
{
    case SAR = 'SAR';
    case USD = 'USD';
    case CNY = 'CNY';

    /**
     * Number of decimal places in the currency's minor unit
     * (100 halalas = 1 SAR, 100 cents = 1 USD, 100 fen = 1 CNY).
     */
    public function decimals(): int
    {
        return 2;
    }
}
