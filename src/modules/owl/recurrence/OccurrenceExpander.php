<?php

declare(strict_types=1);

namespace justinholtweb\owl\recurrence;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use RRule\RRule;
use RRule\RSet;

/**
 * Expands a {@see RecurrenceRule} into concrete {@see Occurrence} objects within a window.
 *
 * Design notes:
 *  - Expansion happens in the event's IANA timezone so wall-clock times are preserved across
 *    DST boundaries (RFC 5545 semantics), then each occurrence is converted to UTC for storage.
 *  - Occurrence end is computed by adding the event's calendar duration to each start in local
 *    time, which keeps multi-day/multi-hour spans on the expected wall-clock time across DST.
 *  - The window is queried slightly widened (back by the event duration) so occurrences that
 *    start before the window but overlap into it are not missed.
 *
 * Framework-agnostic — depends only on rlanvin/php-rrule and PHP's DateTime, never on Craft.
 */
final class OccurrenceExpander
{
    private readonly DateTimeZone $utc;

    /**
     * @param int $maxOccurrences Hard safety cap on how many occurrences a single rule may yield
     *                            within a window (guards against pathological infinite rules).
     */
    public function __construct(
        private readonly int $maxOccurrences = 100_000,
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param RecurrenceRule $rule
     * @param DateTimeImmutable $windowStart Inclusive lower bound (any timezone; compared by instant).
     * @param DateTimeImmutable $windowEnd Exclusive upper bound.
     * @param DateTimeImmutable[] $exceptions EXDATE instants to exclude (matched against occurrence starts).
     * @return Occurrence[] Occurrences overlapping the window, ordered by start ascending.
     */
    public function expand(
        RecurrenceRule $rule,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        array $exceptions = [],
    ): array {
        $tz = $rule->timeZone();
        $duration = $rule->duration();

        // Widen the lower bound so events that start before the window but spill into it are caught.
        $queryStart = $windowStart->setTimezone($tz)->sub($duration);
        $queryEnd = $windowEnd->setTimezone($tz);

        if (!$rule->isRecurring()) {
            return $this->expandSingle($rule, $windowStart, $windowEnd);
        }

        $dtStart = DateTime::createFromInterface($rule->start);
        $rrule = new RRule($rule->normalizedRrule(), $dtStart);

        $set = new RSet();
        $set->addRRule($rrule);
        foreach ($exceptions as $exception) {
            $set->addExDate(DateTime::createFromInterface($exception->setTimezone($tz)));
        }

        $occurrences = [];
        $starts = $set->getOccurrencesBetween($queryStart, $queryEnd, $this->maxOccurrences);

        foreach ($starts as $start) {
            $occurrence = $this->buildOccurrence(
                DateTimeImmutable::createFromInterface($start)->setTimezone($tz),
                $duration,
                $tz,
                $rule->allDay,
            );

            if ($occurrence->overlaps($windowStart, $windowEnd)) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * @return Occurrence[]
     */
    private function expandSingle(
        RecurrenceRule $rule,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array {
        $occurrence = new Occurrence(
            $rule->start->setTimezone($this->utc),
            $rule->end->setTimezone($this->utc),
            $rule->timeZone(),
            $rule->allDay,
        );

        return $occurrence->overlaps($windowStart, $windowEnd) ? [$occurrence] : [];
    }

    private function buildOccurrence(
        DateTimeImmutable $localStart,
        \DateInterval $duration,
        DateTimeZone $tz,
        bool $allDay,
    ): Occurrence {
        // Re-apply the calendar duration in local time (DST-aware), then normalise to UTC.
        $localEnd = $localStart->add($duration);

        return new Occurrence(
            $localStart->setTimezone($this->utc),
            $localEnd->setTimezone($this->utc),
            $tz,
            $allDay,
        );
    }
}
