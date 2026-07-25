<?php

namespace justinholtweb\stub\helpers;

use Craft;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\Plugin;

class BookingHelper
{
    private const PAYMENT_TOKEN_ALGO = 'sha256';

    public static function generateReferenceNumber(): string
    {
        $prefix = Plugin::getInstance()->getSettings()->referencePrefix;
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));

        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Generate a payment token bound to a specific booking. Used to gate the anonymous
     * create-intent endpoint so only the client that just created the booking can pay.
     */
    public static function generatePaymentToken(Booking $booking): string
    {
        return self::signPayment((int)$booking->id, (string)$booking->referenceNumber, self::_paymentTokenSecret());
    }

    public static function verifyPaymentToken(string $token, Booking $booking): bool
    {
        return self::checkPayment($token, (int)$booking->id, (string)$booking->referenceNumber, self::_paymentTokenSecret());
    }

    /**
     * Pure HMAC of a booking's identity. Kept free of Craft/element dependencies so the
     * token contract can be unit-tested in isolation; callers supply the secret.
     */
    public static function signPayment(int $bookingId, string $referenceNumber, string $secret): string
    {
        return hash_hmac(self::PAYMENT_TOKEN_ALGO, $bookingId . ':' . $referenceNumber, $secret);
    }

    /**
     * Constant-time verification of a payment token against a booking's identity.
     */
    public static function checkPayment(string $token, int $bookingId, string $referenceNumber, string $secret): bool
    {
        if ($token === '' || $referenceNumber === '') {
            return false;
        }

        return hash_equals(self::signPayment($bookingId, $referenceNumber, $secret), $token);
    }

    private static function _paymentTokenSecret(): string
    {
        return Craft::$app->getConfig()->getGeneral()->securityKey . ':stub:payment';
    }

    public static function formatPrice(float $price, string $currency): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'A$',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($price, 2);
    }

    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($mins === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$mins}m";
    }
}
