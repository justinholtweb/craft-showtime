<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int|null $id
 * @property int|null $serviceId
 * @property int|null $providerId
 * @property int|null $customerId
 * @property string $bookingStatus
 * @property string|null $startDateTime
 * @property string|null $endDateTime
 * @property string $timezone
 * @property string $referenceNumber
 * @property float $price
 * @property string $currency
 * @property string|null $customerNotes
 * @property string|null $adminNotes
 * @property string $paymentStatus
 * @property string|null $stripePaymentIntentId
 * @property string|null $paidAt
 * @property string|null $cancelledAt
 * @property string|null $cancellationReason
 */
class BookingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_bookings}}';
    }
}
