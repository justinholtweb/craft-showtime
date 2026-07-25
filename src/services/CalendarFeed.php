<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\owl\events\FeedItemsEvent;
use Throwable;

/**
 * Appointment bookings alongside events in one calendar feed.
 *
 * The half of "one calendar" that points the other way: Owl's feed already answers "what's
 * on", and staff want bookings in that same view rather than flipping between two screens.
 */
class CalendarFeed extends Component
{
    /**
     * Distinguishes bookings from events at a glance. Deliberately not configurable yet —
     * one less setting to explain until someone asks for it.
     */
    private const BOOKING_COLOR = '#6b7280';

    /**
     * Add bookings in range to the feed.
     *
     * **Owl's feed endpoint is anonymous.** Bookings carry customer names, so this returns
     * without adding anything unless the current user may actually view bookings — otherwise
     * mounting the bundle would silently publish every customer's appointments. That check is
     * this method's job, not Owl's: Owl has no idea what a booking is.
     */
    public function addBookings(FeedItemsEvent $event): void
    {
        if (!Craft::$app->getUser()->checkPermission('stub:viewBookings')) {
            return;
        }

        $utc = new DateTimeZone('UTC');

        try {
            $rows = (new Query())
                ->select([
                    'b.id', 'b.startDateTime', 'b.endDateTime', 'b.timezone', 'b.bookingStatus',
                    'b.referenceNumber', 's.name AS serviceName', 's.color AS serviceColor',
                ])
                ->from(['b' => '{{%stub_bookings}}'])
                ->leftJoin(['s' => '{{%stub_services}}'], '[[s.id]] = [[b.serviceId]]')
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[b.id]]')
                ->where(['e.dateDeleted' => null])
                ->andWhere(['in', 'b.bookingStatus', ['pending', 'confirmed']])
                ->andWhere(['<', 'b.startDateTime', $event->rangeEnd->format('Y-m-d H:i:s')])
                ->andWhere(['>', 'b.endDateTime', $event->rangeStart->format('Y-m-d H:i:s')])
                ->all();
        } catch (Throwable $e) {
            Craft::warning("Showtime: couldn't read bookings for the calendar feed — {$e->getMessage()}", __METHOD__);
            return;
        }

        foreach ($rows as $row) {
            // Stub stores booking times in UTC; the feed wants absolute ISO-8601 instants,
            // so the offset is carried explicitly rather than left for a reader to guess.
            $start = new DateTimeImmutable((string)$row['startDateTime'], $utc);
            $end = new DateTimeImmutable((string)$row['endDateTime'], $utc);

            $event->items[] = [
                'id' => 'booking-' . $row['id'],
                'title' => trim(($row['serviceName'] ?? 'Booking') . ' — ' . $row['referenceNumber']),
                'start' => $start->format('c'),
                'end' => $end->format('c'),
                'allDay' => false,
                'url' => null,
                'color' => $row['serviceColor'] ?: self::BOOKING_COLOR,
                'showtimeType' => 'booking',
            ];
        }
    }
}
