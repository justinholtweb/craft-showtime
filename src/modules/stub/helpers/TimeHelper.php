<?php

namespace justinholtweb\stub\helpers;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;

class TimeHelper
{
    public static function convertToUtc(string $dateTime, string $fromTimezone): DateTime
    {
        $tz = new DateTimeZone($fromTimezone);
        $dt = new DateTime($dateTime, $tz);
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt;
    }

    public static function convertFromUtc(string $dateTime, string $toTimezone): DateTime
    {
        $dt = new DateTime($dateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($toTimezone));
        return $dt;
    }

    public static function convertTimezone(string $dateTime, string $fromTimezone, string $toTimezone): DateTime
    {
        $dt = new DateTime($dateTime, new DateTimeZone($fromTimezone));
        $dt->setTimezone(new DateTimeZone($toTimezone));
        return $dt;
    }

    public static function timeToDateTime(string $time, string $date, string $timezone): DateTime
    {
        return new DateTime("{$date} {$time}", new DateTimeZone($timezone));
    }

    public static function formatForDisplay(DateTime $dt, string $timezone, string $format = 'g:i A'): string
    {
        $dt = clone $dt;
        $dt->setTimezone(new DateTimeZone($timezone));
        return $dt->format($format);
    }

    /**
     * Returns the inclusive [start, end] bounds of the calendar period containing
     * `$now`, in `$timezone`.
     *
     * Dashboard periods have to be anchored to a real timezone rather than UTC: "today"
     * means today for whoever is reading the page, and near midnight the two disagree.
     * Callers format the result themselves — local strings for date query params (which
     * `Db::parseDateParam()` reads as system-local), or UTC for columns queried directly.
     *
     * @param string $period One of `day`, `week` (Monday–Sunday), or `month`.
     * @return DateTime[] The start and end bounds, in `$timezone`.
     */
    public static function periodBounds(string $period, string $timezone, ?DateTime $now = null): array
    {
        $tz = new DateTimeZone($timezone);
        $start = $now !== null ? (clone $now)->setTimezone($tz) : new DateTime('now', $tz);
        $end = clone $start;

        switch ($period) {
            case 'day':
                break;
            case 'week':
                // PHP's "this week" is ISO-8601, so on a Sunday these resolve backwards
                // to the Monday six days earlier rather than forwards.
                $start->modify('monday this week');
                $end->modify('sunday this week');
                break;
            case 'month':
                $start->modify('first day of this month');
                $end->modify('last day of this month');
                break;
            default:
                throw new InvalidArgumentException("Unsupported period: {$period}");
        }

        return [$start->setTime(0, 0, 0), $end->setTime(23, 59, 59)];
    }

    public static function nowUtc(): DateTime
    {
        return new DateTime('now', new DateTimeZone('UTC'));
    }

    public static function nowInTimezone(string $timezone): DateTime
    {
        return new DateTime('now', new DateTimeZone($timezone));
    }

    public static function dateRangeOverlaps(
        DateTime $start1,
        DateTime $end1,
        DateTime $start2,
        DateTime $end2,
    ): bool {
        return $start1 < $end2 && $start2 < $end1;
    }
}
