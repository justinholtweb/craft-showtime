<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property int|null $userGroupId
 * @property string|null $stripePriceId
 * @property string|null $paypalPlanId
 * @property float $price
 * @property string $currency
 * @property string $billingInterval
 * @property int $billingIntervalCount
 * @property int $trialDays
 * @property int $sortOrder
 * @property bool $enabled
 * @property string|null $features JSON-encoded list of feature strings
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class PlanRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_plans}}';
    }
}
