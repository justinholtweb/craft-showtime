<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $providerId
 * @property string $startDate
 * @property string $endDate
 * @property string|null $reason
 */
class ProviderBlockedDateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_blocked_dates}}';
    }
}
