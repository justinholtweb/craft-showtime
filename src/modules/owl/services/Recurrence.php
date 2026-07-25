<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use craft\base\Component;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\ExceptionRecord;
use justinholtweb\owl\recurrence\Occurrence;
use justinholtweb\owl\recurrence\OccurrenceExpander;
use justinholtweb\owl\recurrence\RecurrenceRule;

/**
 * Bridges a Craft {@see Event} into the framework-agnostic recurrence engine: reinterprets the
 * stored UTC instants as wall-clock time in the event's IANA timezone, loads EXDATE exceptions,
 * and expands occurrences for a window.
 */
class Recurrence extends Component
{
    /**
     * @return Occurrence[]
     */
    public function expand(Event $event, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $rule = $this->ruleForEvent($event);
        $expander = new OccurrenceExpander(Owl::getInstance()->getSettings()->maxOccurrencesPerEvent);

        return $expander->expand($rule, $windowStart, $windowEnd, $this->exceptionsForEvent($event));
    }

    public function ruleForEvent(Event $event): RecurrenceRule
    {
        $tz = new DateTimeZone($event->timezone !== '' ? $event->timezone : 'UTC');

        $start = DateTimeImmutable::createFromInterface($event->startDate)->setTimezone($tz);
        $end = DateTimeImmutable::createFromInterface($event->endDate)->setTimezone($tz);

        return new RecurrenceRule($start, $end, $event->rrule, $event->allDay);
    }

    /**
     * @return DateTimeImmutable[]
     */
    private function exceptionsForEvent(Event $event): array
    {
        // The `date` column holds the UTC instant of each excluded occurrence start.
        $rows = ExceptionRecord::find()
            ->select(['date'])
            ->where(['eventId' => $event->id])
            ->column();

        return array_map(
            static fn(string $date): DateTimeImmutable => new DateTimeImmutable($date, new DateTimeZone('UTC')),
            $rows,
        );
    }
}
