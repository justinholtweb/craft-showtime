<?php

declare(strict_types=1);

namespace justinholtweb\owl\recurrence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Resolves a stored occurrence instant into the value a feed should present.
 *
 * Occurrences are persisted as an absolute UTC instant of the event's local wall-clock start/end.
 * Timed events are genuine instants and should be presented in UTC. All-day events, however, are
 * *floating calendar dates* (RFC 5545 DATE / FullCalendar `allDay` semantics): the day they fall on
 * is the event's local date, not the UTC date. Reinterpreting the stored instant in UTC drops
 * all-day events onto the wrong day for every timezone east of UTC (e.g. an all-day event stored as
 * 2026-07-18 15:00Z for Asia/Tokyo is really 2026-07-19). This helper re-anchors the local date to
 * UTC midnight so downstream formatters emit the correct day with no spurious timezone offset.
 *
 * Framework-agnostic — depends only on PHP's DateTime, never on Craft, so it is unit-testable.
 */
final class DisplayInstant
{
    /**
     * @param DateTimeImmutable $storedUtc The stored occurrence instant (assumed to carry UTC).
     * @param DateTimeZone $timeZone The event's IANA timezone.
     * @param bool $allDay Whether the occurrence is all-day (a floating calendar date).
     * @return DateTimeImmutable For timed events, the UTC instant unchanged. For all-day events,
     *                           the event-local date anchored to 00:00:00 UTC (floating).
     */
    public static function forDisplay(
        DateTimeImmutable $storedUtc,
        DateTimeZone $timeZone,
        bool $allDay,
    ): DateTimeImmutable {
        if (!$allDay) {
            return $storedUtc;
        }

        $localDate = $storedUtc->setTimezone($timeZone)->format('Y-m-d');

        return new DateTimeImmutable($localDate . ' 00:00:00', new DateTimeZone('UTC'));
    }
}
