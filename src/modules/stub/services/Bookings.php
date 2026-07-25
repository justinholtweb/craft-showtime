<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\events\BookingEvent;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\TimeHelper;
use justinholtweb\stub\Plugin;
use yii\base\Component;

class Bookings extends Component
{
    public const EVENT_BEFORE_SAVE_BOOKING = 'beforeSaveBooking';
    public const EVENT_AFTER_SAVE_BOOKING = 'afterSaveBooking';
    public const EVENT_BEFORE_STATUS_CHANGE = 'beforeStatusChange';
    public const EVENT_AFTER_STATUS_CHANGE = 'afterStatusChange';

    public function createBooking(array $attributes): Booking
    {
        $booking = new Booking();
        $booking->serviceId = (int)$attributes['serviceId'];
        $booking->providerId = (int)$attributes['providerId'];
        $booking->customerId = (int)$attributes['customerId'];
        $booking->startDateTime = $attributes['startDateTime'];
        $booking->endDateTime = $attributes['endDateTime'];
        $booking->timezone = $attributes['timezone'] ?? 'UTC';
        $booking->referenceNumber = BookingHelper::generateReferenceNumber();
        $booking->customerNotes = $attributes['customerNotes'] ?? null;

        $service = Plugin::getInstance()->services->getServiceById($booking->serviceId);
        if ($service) {
            $booking->price = $service->price;
            $booking->currency = $service->currency;
        }

        // Auto-confirm free bookings
        $settings = Plugin::getInstance()->getSettings();
        if ($booking->price <= 0 && $settings->autoConfirmFreeBookings) {
            $booking->bookingStatus = BookingStatus::Confirmed->value;
            $booking->paymentStatus = PaymentStatus::Paid->value;
        } else {
            $booking->bookingStatus = BookingStatus::Pending->value;
            $booking->paymentStatus = PaymentStatus::Unpaid->value;
        }

        // Fire before event
        $event = new BookingEvent(['booking' => $booking, 'isNew' => true]);
        $this->trigger(self::EVENT_BEFORE_SAVE_BOOKING, $event);

        // A handler can refuse the booking outright — e.g. a service restricted to members.
        if (!$event->isValid) {
            if (!$booking->hasErrors()) {
                $booking->addError('serviceId', Craft::t('stub', 'This booking isn’t available.'));
            }

            return $booking;
        }

        if (!Craft::$app->getElements()->saveElement($booking)) {
            return $booking;
        }

        // Fire after event
        $this->trigger(self::EVENT_AFTER_SAVE_BOOKING, new BookingEvent(['booking' => $booking, 'isNew' => true]));

        return $booking;
    }

    public function getBookingById(int $id): ?Booking
    {
        return Booking::find()->id($id)->one();
    }

    public function getBookingByReference(string $referenceNumber): ?Booking
    {
        return Booking::find()->referenceNumber($referenceNumber)->one();
    }

    public function updateStatus(Booking $booking, BookingStatus $newStatus, ?string $reason = null): bool
    {
        $oldStatus = $booking->bookingStatus;

        $event = new BookingEvent(['booking' => $booking]);
        $this->trigger(self::EVENT_BEFORE_STATUS_CHANGE, $event);

        $booking->bookingStatus = $newStatus->value;

        if ($newStatus === BookingStatus::Cancelled) {
            $booking->cancelledAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $booking->cancellationReason = $reason;
        }

        $success = Craft::$app->getElements()->saveElement($booking);

        if ($success) {
            $this->trigger(self::EVENT_AFTER_STATUS_CHANGE, new BookingEvent(['booking' => $booking]));

            // Trigger emails
            if ($newStatus === BookingStatus::Confirmed) {
                Plugin::getInstance()->emails->sendConfirmation($booking);
            } elseif ($newStatus === BookingStatus::Cancelled) {
                Plugin::getInstance()->emails->sendCancellation($booking);
            }
        }

        return $success;
    }

    public function markPaid(Booking $booking, string $paymentIntentId): bool
    {
        $booking->paymentStatus = PaymentStatus::Paid->value;
        $booking->stripePaymentIntentId = $paymentIntentId;
        $booking->paidAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return Craft::$app->getElements()->saveElement($booking);
    }

    public function getTodaysBookings(): array
    {
        [$start, $end] = TimeHelper::periodBounds('day', Craft::$app->getTimeZone());

        return Booking::find()
            ->startDateTime($this->dateRangeParam($start, $end))
            ->bookingStatus('confirmed')
            ->orderBy(['startDateTime' => SORT_ASC])
            ->all();
    }

    public function getBookingsForDateRange(string $startDate, string $endDate, ?int $providerId = null): array
    {
        // Callers (e.g. FullCalendar) may pass ISO-8601 with an offset such as
        // "2026-07-19T00:00:00-04:00". Normalize to the system timezone, since
        // `Db::parseDateParam()` reads date params as system-local and handles the
        // conversion to the UTC values we store.
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $start = (new DateTime($startDate))->setTimezone($tz)->format('Y-m-d H:i:s');
        $end = (new DateTime($endDate))->setTimezone($tz)->format('Y-m-d H:i:s');

        // Overlap, not containment: a booking that straddles either edge of the range
        // still needs to show up on the calendar.
        $query = Booking::find()
            ->startDateTime("< {$end}")
            ->endDateTime("> {$start}")
            ->orderBy(['startDateTime' => SORT_ASC]);

        if ($providerId) {
            $query->providerId($providerId);
        }

        return $query->all();
    }

    public function getBookingStats(): array
    {
        // Periods are relative to the system timezone, not UTC — "today" on the
        // dashboard means today for the person looking at it.
        $tz = Craft::$app->getTimeZone();

        [$dayStart, $dayEnd] = TimeHelper::periodBounds('day', $tz);
        [$weekStart, $weekEnd] = TimeHelper::periodBounds('week', $tz);
        [$monthStart, $monthEnd] = TimeHelper::periodBounds('month', $tz);

        $utc = new DateTimeZone('UTC');

        return [
            'todayCount' => (int)Booking::find()
                ->startDateTime($this->dateRangeParam($dayStart, $dayEnd))
                ->bookingStatus('confirmed')
                ->count(),

            'weekCount' => (int)Booking::find()
                ->startDateTime($this->dateRangeParam($weekStart, $weekEnd))
                ->count(),

            'pendingCount' => (int)Booking::find()
                ->bookingStatus('pending')
                ->count(),

            // `paidAt` is queried directly rather than through `Db::parseDateParam()`,
            // so these bounds have to be converted to UTC by hand to match how it's
            // stored. The date params above must NOT be — Craft converts those itself.
            'monthlyRevenue' => (float)(new Query())
                ->from('{{%stub_bookings}}')
                ->where(['paymentStatus' => 'paid'])
                ->andWhere(['>=', 'paidAt', (clone $monthStart)->setTimezone($utc)->format('Y-m-d H:i:s')])
                ->andWhere(['<=', 'paidAt', (clone $monthEnd)->setTimezone($utc)->format('Y-m-d H:i:s')])
                ->sum('price'),
        ];
    }

    /**
     * Builds a single inclusive range param for a date query.
     *
     * `BookingQuery`'s date setters overwrite rather than merge, so chaining two calls
     * to bound both ends silently discards the first. Both bounds must go in together.
     *
     * @return string[]
     */
    private function dateRangeParam(DateTime $start, DateTime $end): array
    {
        return [
            'and',
            '>= ' . $start->format('Y-m-d H:i:s'),
            '<= ' . $end->format('Y-m-d H:i:s'),
        ];
    }
}
