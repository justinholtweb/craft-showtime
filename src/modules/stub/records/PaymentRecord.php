<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $bookingId
 * @property string|null $stripePaymentIntentId
 * @property string|null $stripeChargeId
 * @property float $amount
 * @property string $currency
 * @property string $status
 * @property string $method
 * @property string|null $paidAt
 * @property string|null $note
 * @property int|null $recordedById
 * @property mixed $stripeResponse
 */
class PaymentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_payments}}';
    }
}
