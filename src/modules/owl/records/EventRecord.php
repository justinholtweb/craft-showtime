<?php

declare(strict_types=1);

namespace justinholtweb\owl\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Per-event scheduling data, shared across sites (keyed by canonical element id). Dates, timezone,
 * and the recurrence rule are intentionally NOT per-site — an event happens at one instant
 * regardless of the content language.
 *
 * @property int $id Element id
 * @property int $calendarId
 * @property string $startDate UTC
 * @property string $endDate UTC
 * @property bool $allDay
 * @property string $timezone IANA timezone name
 * @property string|null $rrule
 * @property bool $repeating
 */
class EventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%owl_events}}';
    }

    public function getCalendar(): ActiveQueryInterface
    {
        return $this->hasOne(CalendarRecord::class, ['id' => 'calendarId']);
    }
}
