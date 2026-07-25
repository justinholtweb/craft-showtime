<?php

declare(strict_types=1);

namespace justinholtweb\owl\records;

use craft\db\ActiveRecord;

/**
 * An EXDATE-style exclusion: a single date removed from an event's recurrence.
 *
 * @property int $id
 * @property int $eventId
 * @property string $date UTC instant of the excluded occurrence start
 */
class ExceptionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%owl_exceptions}}';
    }
}
