<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use craft\base\Component;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\owl\events\IcsRowsEvent;
use justinholtweb\owl\ics\IcsBuilder;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use justinholtweb\owl\recurrence\DisplayInstant;

/**
 * Generates ICS feeds for calendars and individual events.
 */
class Ics extends Component
{
    /**
     * @event IcsRowsEvent Before an ICS feed is built. Remove rows a visitor shouldn't see — the
     * feeds are anonymous.
     */
    public const EVENT_DEFINE_ICS_ROWS = 'defineIcsRows';

    /**
     * A subscribable ICS feed of every materialised occurrence in a calendar.
     */
    public function calendarFeed(Calendar $calendar, ?int $siteId = null): string
    {
        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange(
            $this->farPast(),
            $this->farFuture(),
            $siteId,
            [$calendar->id],
        );

        $rows = $this->filterRows($rows, calendar: $calendar);

        return (new IcsBuilder())->build($calendar->name, $this->rowsToEvents($rows));
    }

    /**
     * An ICS document for a single event (all of its occurrences), or null when a handler has
     * hidden every occurrence — an empty feed would still carry the event's title.
     */
    public function eventFeed(\justinholtweb\owl\elements\Event $event): ?string
    {
        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange(
            $this->farPast(),
            $this->farFuture(),
            $event->siteId,
            null,
            $event->id,
        );

        $filtered = $this->filterRows($rows, event: $event);

        if ($rows !== [] && $filtered === []) {
            return null;
        }

        return (new IcsBuilder())->build((string)$event->title, $this->rowsToEvents($filtered));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function filterRows(array $rows, ?Calendar $calendar = null, ?\justinholtweb\owl\elements\Event $event = null): array
    {
        if (!$this->hasEventHandlers(self::EVENT_DEFINE_ICS_ROWS)) {
            return $rows;
        }

        $e = new IcsRowsEvent(['rows' => $rows, 'calendar' => $calendar, 'event' => $event]);
        $this->trigger(self::EVENT_DEFINE_ICS_ROWS, $e);

        return array_values($e->rows);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function rowsToEvents(array $rows): array
    {
        $host = parse_url(UrlHelper::baseSiteUrl(), PHP_URL_HOST) ?: 'owl';
        $utc = new DateTimeZone('UTC');

        $events = [];
        foreach ($rows as $row) {
            $allDay = (bool)$row['allDay'];
            $tz = new DateTimeZone((string)($row['timezone'] ?: 'UTC'));

            // UID is keyed off the absolute instant so it stays stable regardless of all-day
            // date re-anchoring below.
            $instant = new DateTimeImmutable((string)$row['startDate'], $utc);

            $start = DisplayInstant::forDisplay($instant, $tz, $allDay);
            $end = DisplayInstant::forDisplay(new DateTimeImmutable((string)$row['endDate'], $utc), $tz, $allDay);

            $events[] = [
                'uid' => sprintf('owl-%d-%d@%s', $row['eventId'], $instant->getTimestamp(), $host),
                'title' => (string)$row['title'],
                'start' => $start,
                'end' => $end,
                'allDay' => $allDay,
                'url' => !empty($row['uri']) ? UrlHelper::siteUrl((string)$row['uri']) : null,
            ];
        }

        return $events;
    }

    private function farPast(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-5 years');
    }

    private function farFuture(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+10 years');
    }
}
