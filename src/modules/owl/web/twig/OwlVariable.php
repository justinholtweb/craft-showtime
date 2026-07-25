<?php

declare(strict_types=1);

namespace justinholtweb\owl\web\twig;

use Craft;
use DateTime;
use DateTimeZone;
use justinholtweb\owl\elements\db\EventQuery;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\OccurrenceRecord;

/**
 * The object returned by `craft.owl` in Twig.
 *
 * Usage:
 *   {% set upcoming = craft.owl.events.startsAfter(now).orderBy('startDate ASC').limit(10).all() %}
 *   {% set calendars = craft.owl.calendars() %}
 */
class OwlVariable
{
    /**
     * Returns an Event element query, optionally configured from a criteria hash.
     */
    public function events(array $criteria = []): EventQuery
    {
        /** @var EventQuery $query */
        $query = Event::find();

        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        return $query;
    }

    /**
     * @return Calendar[]
     */
    public function calendars(): array
    {
        return Owl::getInstance()->calendars->getAllCalendars();
    }

    /**
     * The number of materialised occurrences for an event.
     */
    public function occurrenceCount(Event $event): int
    {
        return (int)OccurrenceRecord::find()->where(['eventId' => $event->id])->count();
    }

    /**
     * Upcoming occurrences across all calendars (or a given calendar), each as a row with
     * title, start/end (UTC), timezone, allDay, uri, and calendar color.
     *
     * @return array<int,array<string,mixed>>
     */
    public function upcoming(int $limit = 20, ?string $calendar = null, string $within = '+1 year'): array
    {
        $calendarIds = null;
        if ($calendar !== null) {
            $model = Owl::getInstance()->calendars->getCalendarByHandle($calendar);
            $calendarIds = $model !== null ? [$model->id] : [0];
        }

        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange(
            new DateTime('now'),
            new DateTime($within),
            null,
            $calendarIds,
            null,
            // Bound the query itself — occurrences are ordered by start ascending, so the first
            // $limit rows are the upcoming ones. Slicing in PHP would first materialise every
            // occurrence in the window (potentially thousands for a daily rule) just to keep a few.
            max(0, $limit),
        );

        // Expose the stored UTC instants as DateTime objects so Twig's `date` filter localises
        // them correctly (a bare datetime string would be parsed in the system timezone instead).
        $utc = new DateTimeZone('UTC');
        foreach ($rows as &$row) {
            $row['start'] = new DateTime((string)$row['startDate'], $utc);
            $row['end'] = new DateTime((string)$row['endDate'], $utc);
        }
        unset($row);

        return $rows;
    }
}
