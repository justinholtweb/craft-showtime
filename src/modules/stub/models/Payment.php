<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class Payment extends Model
{
    public ?int $id = null;
    public ?int $bookingId = null;
    public ?string $stripePaymentIntentId = null;
    public ?string $stripeChargeId = null;
    public float $amount = 0;
    public string $currency = 'USD';
    public string $status = 'pending';

    /**
     * `stripe` for a payment taken online, `manual` for one staff recorded with Mark as Paid.
     */
    public string $method = 'stripe';

    /**
     * When the money arrived, as a naive UTC string — what revenue reports count by.
     */
    public ?string $paidAt = null;
    public ?string $note = null;
    public ?int $recordedById = null;
    public ?array $stripeResponse = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     * These audit columns are stored and consumed as strings, so opt out of
     * Craft's automatic DateTime casting (which would violate the ?string types).
     */
    public function datetimeAttributes(): array
    {
        return [];
    }

    public function defineRules(): array
    {
        return [
            [['bookingId', 'amount', 'currency', 'status'], 'required'],
            [['amount'], 'number', 'min' => 0],
        ];
    }
}
