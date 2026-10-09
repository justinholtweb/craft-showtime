<?php

namespace justinholtweb\stub\helpers;

use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\services\Currencies;

/**
 * The money arithmetic behind deposits and part payments.
 *
 * Pure — no database, no booted Craft — so every rule here is unit-tested. Amounts are rounded
 * to the currency's minor unit (Stripe's table, via {@see Currencies::minorUnitDigits()}) at
 * every step, because a 33⅓% deposit on $100 has to be a sum someone can actually be charged,
 * and the balance has to be exactly what's left of it.
 */
class PaymentHelper
{
    public const DEPOSIT_PERCENT = 'percent';
    public const DEPOSIT_FIXED = 'fixed';

    public static function round(float $amount, string $currency): float
    {
        return round($amount, Currencies::minorUnitDigits($currency));
    }

    /**
     * The deposit owed on a price: a percentage of it, or a fixed sum — never more than the
     * price itself, and never negative.
     */
    public static function depositAmount(float $price, string $type, float $value, string $currency): float
    {
        if ($price <= 0 || $value <= 0) {
            return 0.0;
        }

        $amount = $type === self::DEPOSIT_FIXED ? $value : $price * $value / 100;

        return self::round(min(max($amount, 0.0), $price), $currency);
    }

    /**
     * What's still owed after what's been paid.
     */
    public static function balance(float $price, float $paid, string $currency): float
    {
        return max(0.0, self::round($price - $paid, $currency));
    }

    /**
     * The payment status that a price and an amount received add up to.
     *
     * Compared after rounding to the minor unit, so float noise in a sum of part payments
     * can't leave a booking a ten-thousandth of a cent short of paid.
     */
    public static function statusFor(float $price, float $paid, string $currency): PaymentStatus
    {
        if ($price <= 0 || self::balance($price, $paid, $currency) <= 0) {
            return PaymentStatus::Paid;
        }

        return self::round($paid, $currency) > 0 ? PaymentStatus::PartiallyPaid : PaymentStatus::Unpaid;
    }

    /**
     * How much the online payment step should charge right now.
     *
     * Full payment charges whatever is outstanding; a deposit is charged once, and only while
     * nothing has been paid; pay-in-person never charges online.
     */
    public static function amountDueOnline(PaymentMode $mode, float $price, float $paid, float $deposit, string $currency): float
    {
        $balance = self::balance($price, $paid, $currency);

        return match ($mode) {
            PaymentMode::Full => $balance,
            PaymentMode::Deposit => self::round($paid, $currency) > 0 ? 0.0 : min(self::round($deposit, $currency), $balance),
            PaymentMode::InPerson => 0.0,
        };
    }
}
