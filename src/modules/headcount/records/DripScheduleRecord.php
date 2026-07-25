<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string|null $planIds
 * @property string $type
 * @property int|null $targetId
 * @property string|null $targetUid
 * @property int $delayDays
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class DripScheduleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_drip_schedules}}';
    }
}
