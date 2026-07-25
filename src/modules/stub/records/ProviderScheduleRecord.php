<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $providerId
 * @property int $dayOfWeek
 * @property string $startTime
 * @property string $endTime
 * @property bool $enabled
 */
class ProviderScheduleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_schedules}}';
    }
}
