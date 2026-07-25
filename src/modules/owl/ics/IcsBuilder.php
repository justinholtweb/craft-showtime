<?php

declare(strict_types=1);

namespace justinholtweb\owl\ics;

use DateTimeInterface;
use Spatie\IcalendarGenerator\Components\Calendar as IcsCalendar;
use Spatie\IcalendarGenerator\Components\Event as IcsEvent;

/**
 * Builds an RFC 5545 ICS document from concrete occurrences.
 *
 * Each occurrence is emitted as its own VEVENT with a stable, unique UID. Because Owl materialises
 * occurrences up to a bounded horizon, the output is always finite and renders identically in every
 * calendar client — no reliance on client-side RRULE expansion.
 *
 * Framework-agnostic: depends only on spatie/icalendar-generator and PHP's DateTime, never on Craft,
 * so it can be unit-tested in isolation.
 *
 * @phpstan-type IcsEventData array{uid:string, title:string, start:DateTimeInterface, end:DateTimeInterface, allDay?:bool, url?:string|null, description?:string|null}
 */
final class IcsBuilder
{
    /**
     * @param IcsEventData[] $events
     */
    public function build(string $calendarName, array $events): string
    {
        $calendar = IcsCalendar::create($calendarName);

        foreach ($events as $data) {
            $calendar->event($this->buildEvent($data));
        }

        return $calendar->get();
    }

    /**
     * @param IcsEventData $data
     */
    private function buildEvent(array $data): IcsEvent
    {
        $allDay = $data['allDay'] ?? false;

        $event = IcsEvent::create($data['title'])
            ->uniqueIdentifier($data['uid'])
            ->startsAt($data['start'], !$allDay)
            ->endsAt($data['end'], !$allDay);

        if ($allDay) {
            $event->fullDay();
        }

        if (!empty($data['url'])) {
            $event->url($data['url']);
        }

        if (!empty($data['description'])) {
            $event->description($data['description']);
        }

        return $event;
    }
}
