<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $userId
 * @property int|null $planId
 * @property string $gateway
 * @property string|null $gatewaySubscriptionId
 * @property string|null $gatewayCustomerId
 * @property string $status
 * @property string|null $trialStartDate
 * @property string|null $trialEndDate
 * @property string|null $startDate
 * @property string|null $endDate
 * @property string|null $canceledAt
 * @property bool $cancelAtPeriodEnd
 * @property float $amount
 * @property string $currency
 * @property array|null $metadata
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SubscriptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_subscriptions}}';
    }
}
