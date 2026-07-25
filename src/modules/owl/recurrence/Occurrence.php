<?php

declare(strict_types=1);

namespace justinholtweb\owl\recurrence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A single concrete occurrence of an event, expanded from a recurrence rule.
 *
 * Start and end are always stored in UTC (ready to persist to a DATETIME column).
 * The original IANA timezone is retained so the wall-clock time can be reconstructed
 * for display.
 *
 * This class is deliberately framework-agnostic: it has no dependency on Craft or Yii
 * so the recurrence engine can be unit-tested in isolation.
 */
final class Occurrence
{
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly DateTimeZone $timeZone,
        public readonly bool $allDay = false,
    ) {
    }

    /**
     * The occurrence start as wall-clock time in the event's original timezone.
     */
    public function localStart(): DateTimeImmutable
    {
        return $this->start->setTimezone($this->timeZone);
    }

    /**
     * The occurrence end as wall-clock time in the event's original timezone.
     */
    public function localEnd(): DateTimeImmutable
    {
        return $this->end->setTimezone($this->timeZone);
    }

    /**
     * Whether this occurrence overlaps the given half-open window [windowStart, windowEnd).
     * Comparison is by absolute instant, so it is timezone-safe.
     */
    public function overlaps(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): bool
    {
        return $this->start < $windowEnd && $this->end > $windowStart;
    }
}
