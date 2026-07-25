<?php

declare(strict_types=1);

namespace justinholtweb\owl\recurrence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Value object describing an event's schedule: a first occurrence (start/end in a
 * specific IANA timezone) plus an optional RFC 5545 RRULE string.
 *
 * RFC 5545 recurrence is wall-clock local: "every day at 09:00" must stay 09:00 in the
 * event's timezone across DST boundaries. We therefore carry the timezone explicitly and
 * expand in local time (see {@see OccurrenceExpander}), only converting to UTC at the end.
 *
 * Framework-agnostic by design — no Craft/Yii dependency.
 */
final class RecurrenceRule
{
    /**
     * @param DateTimeImmutable $start First occurrence start (must carry the event timezone).
     * @param DateTimeImmutable $end First occurrence end (must carry the event timezone).
     * @param string|null $rrule RRULE body without the "RRULE:" prefix and without DTSTART,
     *                           e.g. "FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10". Null/empty = single event.
     * @param bool $allDay
     */
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly ?string $rrule = null,
        public readonly bool $allDay = false,
    ) {
        if ($this->end < $this->start) {
            throw new InvalidArgumentException('Event end cannot be before its start.');
        }
        if ($this->start->getTimezone()->getName() !== $this->end->getTimezone()->getName()) {
            throw new InvalidArgumentException('Event start and end must share the same timezone.');
        }
    }

    public function timeZone(): DateTimeZone
    {
        return $this->start->getTimezone();
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null && trim($this->rrule) !== '';
    }

    /**
     * The event's duration as a calendar interval (captures days/hours/minutes), so it can be
     * re-applied to each occurrence start in local time. Adding a calendar interval in a
     * timezone-aware context keeps multi-day spans on the same wall-clock time across DST,
     * which is the behaviour users expect.
     */
    public function duration(): DateInterval
    {
        return $this->start->diff($this->end);
    }

    /**
     * Normalised RRULE string the way rlanvin/php-rrule expects it (no leading "RRULE:").
     */
    public function normalizedRrule(): ?string
    {
        if (!$this->isRecurring()) {
            return null;
        }

        return preg_replace('/^RRULE:/i', '', trim((string)$this->rrule));
    }
}
