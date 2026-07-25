<?php

declare(strict_types=1);

namespace justinholtweb\owl\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\owl\events\FeedItemsEvent;
use justinholtweb\owl\Owl;
use justinholtweb\owl\recurrence\DisplayInstant;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end feeds: a FullCalendar-compatible JSON endpoint and ICS exports.
 */
class FeedController extends Controller
{
    /**
     * @event FeedItemsEvent Fired after the calendar feed is built, so other code can add
     * items to it. The endpoint is anonymous — handlers must gate anything non-public.
     */
    public const EVENT_DEFINE_FEED_ITEMS = 'defineFeedItems';

    protected array|bool|int $allowAnonymous = true;

    /**
     * JSON event feed shaped for FullCalendar. Reads the `start`/`end` range it requests and
     * returns only occurrences overlapping that window.
     *
     *   GET owl/events.json?start=…&end=…&calendar=concerts
     */
    public function actionEvents(): Response
    {
        $request = Craft::$app->getRequest();

        $rangeStart = DateTimeHelper::toDateTime($request->getParam('start')) ?: new DateTime('-1 year');
        $rangeEnd = DateTimeHelper::toDateTime($request->getParam('end')) ?: new DateTime('+1 year');
        $calendarIds = $this->resolveCalendarIds($request->getParam('calendar'));

        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange($rangeStart, $rangeEnd, null, $calendarIds);
        $utc = new DateTimeZone('UTC');

        $events = [];
        foreach ($rows as $row) {
            $allDay = (bool)$row['allDay'];
            $tz = new DateTimeZone((string)($row['timezone'] ?: 'UTC'));

            // FullCalendar expects all-day events as floating date-only strings (the event's local
            // day), and timed events as absolute ISO-8601 instants.
            $start = DisplayInstant::forDisplay(new DateTimeImmutable((string)$row['startDate'], $utc), $tz, $allDay);
            $end = DisplayInstant::forDisplay(new DateTimeImmutable((string)$row['endDate'], $utc), $tz, $allDay);

            $events[] = [
                'id' => (int)$row['eventId'],
                'title' => (string)$row['title'],
                'start' => $allDay ? $start->format('Y-m-d') : $start->format('c'),
                'end' => $allDay ? $end->format('Y-m-d') : $end->format('c'),
                'allDay' => $allDay,
                'url' => !empty($row['uri']) ? UrlHelper::siteUrl((string)$row['uri']) : null,
                'color' => $row['color'] ?: null,
            ];
        }

        // Let other code contribute to the feed — e.g. a host bundle adding appointment
        // bookings so staff see one calendar. NOTE: this endpoint is anonymous, so a handler
        // adding anything non-public must check permissions itself.
        $event = new FeedItemsEvent([
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'calendarIds' => $calendarIds ?? [],
            'items' => $events,
        ]);

        $this->trigger(self::EVENT_DEFINE_FEED_ITEMS, $event);

        return $this->asJson($event->items);
    }

    /**
     * Subscribable ICS feed for a calendar: owl/calendar/<handle>.ics
     */
    public function actionCalendar(string $handle): Response
    {
        $calendar = Owl::getInstance()->calendars->getCalendarByHandle($handle);

        if ($calendar === null) {
            throw new NotFoundHttpException('Calendar not found.');
        }

        $ics = Owl::getInstance()->ics->calendarFeed($calendar);

        return $this->icsResponse($ics, $handle);
    }

    /**
     * ICS download for a single event: owl/event/<id>.ics
     */
    public function actionEvent(int $eventId): Response
    {
        $event = Owl::getInstance()->events->getEventById($eventId);

        if ($event === null || $event->getStatus() !== \craft\base\Element::STATUS_ENABLED) {
            throw new NotFoundHttpException('Event not found.');
        }

        $ics = Owl::getInstance()->ics->eventFeed($event);

        return $this->icsResponse($ics, 'event-' . $eventId);
    }

    /**
     * @return int[]|null
     */
    private function resolveCalendarIds(mixed $param): ?array
    {
        if ($param === null || $param === '') {
            return null;
        }

        $handles = is_array($param) ? $param : explode(',', (string)$param);
        $ids = [];
        foreach ($handles as $handle) {
            $calendar = Owl::getInstance()->calendars->getCalendarByHandle(trim((string)$handle));
            if ($calendar !== null) {
                $ids[] = $calendar->id;
            }
        }

        return $ids !== [] ? $ids : [0];
    }

    private function icsResponse(string $ics, string $filename): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        $response->headers->set('Content-Disposition', "inline; filename=\"{$filename}.ics\"");
        $response->content = $ics;

        return $response;
    }
}
