<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $code
 * @property string|null $description
 * @property string $discountType
 * @property float $discountAmount
 * @property string|null $planIds
 * @property int|null $maxUses
 * @property int $currentUses
 * @property string|null $expiresAt
 * @property string|null $stripeCouponId
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class CouponRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_coupons}}';
    }
}
