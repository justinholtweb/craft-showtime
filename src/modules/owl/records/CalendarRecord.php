<?php

declare(strict_types=1);

namespace justinholtweb\owl\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $color
 * @property int|null $fieldLayoutId
 * @property bool $hasTickets
 * @property string|null $uriFormat
 * @property string|null $template
 * @property int|null $sortOrder
 * @property string $uid
 */
class CalendarRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%owl_calendars}}';
    }
}
