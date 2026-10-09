<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\events\BookingEvent;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\PaymentHelper;
use justinholtweb\stub\helpers\TimeHelper;
use justinholtweb\stub\models\Service;
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
        $booking = $this->_newBooking($attributes);

        // Auto-confirm free bookings
        $settings = Plugin::getInstance()->getSettings();
        if ($booking->price <= 0 && $settings->autoConfirmFreeBookings) {
            $booking->bookingStatus = BookingStatus::Confirmed->value;
            $booking->paymentStatus = PaymentStatus::Paid->value;
        } elseif ($booking->price > 0 && $booking->getPaymentModeEnum() === PaymentMode::InPerson) {
            // Pay in person: nothing is taken online, so there is nothing to wait for. The
            // booking stands, and stays unpaid until someone marks it paid.
            $booking->bookingStatus = BookingStatus::Confirmed->value;
            $booking->paymentStatus = PaymentStatus::Unpaid->value;
        } else {
            $booking->bookingStatus = BookingStatus::Pending->value;
            $booking->paymentStatus = PaymentStatus::Unpaid->value;
        }

        return $this->_saveNew($booking);
    }

    /**
     * A booking entered by staff in the control panel rather than submitted through the
     * front-end form.
     *
     * The difference from `createBooking()` is only who decides the two statuses. There is
     * no payment flow behind this — nobody is at a card form — so the status the admin
     * picked is taken at face value: a phone booking they've already been paid for is
     * confirmed and paid, one they haven't is confirmed and unpaid.
     *
     * Everything else is deliberately identical, including both save events, so an
     * integration listening for new bookings sees these too.
     */
    public function createManualBooking(array $attributes): Booking
    {
        $booking = $this->_newBooking($attributes);
        $booking->adminNotes = $attributes['adminNotes'] ?? null;

        $booking->bookingStatus = (BookingStatus::tryFrom((string)($attributes['bookingStatus'] ?? ''))
            ?? BookingStatus::Confirmed)->value;
        $booking->paymentStatus = (PaymentStatus::tryFrom((string)($attributes['paymentStatus'] ?? ''))
            ?? PaymentStatus::Unpaid)->value;

        if ($booking->paymentStatus === PaymentStatus::Paid->value) {
            $booking->paidAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } elseif ($booking->paymentStatus === PaymentStatus::PartiallyPaid->value) {
            // There's no amount on the form to say how much, so "partially" can't be honoured.
            $booking->paymentStatus = PaymentStatus::Unpaid->value;
        }

        $booking = $this->_saveNew($booking);

        // Entered as paid: the money was received with the booking, so it goes in the ledger
        // and counts as revenue today, just like a payment marked later would.
        if ($booking->id && $booking->paymentStatus === PaymentStatus::Paid->value && $booking->price > 0) {
            $booking->amountPaid = (float)$booking->price;
            Craft::$app->getElements()->saveElement($booking);
            Plugin::getInstance()->payments->logReceivedWithBooking(
                $booking,
                (float)$booking->price,
                Craft::$app->has('user', true) ? Craft::$app->getUser()->getId() : null,
            );
        }

        return $booking;
    }

    /**
     * The parts of a new booking that don't depend on where it came from.
     */
    private function _newBooking(array $attributes): Booking
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
            $booking->paymentMode = $service->paymentMode;
        }

        return $booking;
    }

    /**
     * Fire the save events around saving a new booking, and let a handler refuse it.
     */
    private function _saveNew(Booking $booking): Booking
    {
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

        // The deposit is worked out on the booking's price *after* the handlers have seen it,
        // because a handler may have changed it — a member discount — and a percentage deposit
        // has to be a percentage of what this customer is actually paying.
        $service = $booking->getService();
        $booking->depositAmount = $service instanceof Service ? $service->depositFor((float)$booking->price) : 0.0;

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

    /**
     * Add a payment to what a booking has received, and move its payment status on to match:
     * partially paid while a balance remains, paid once it doesn't. `paidAt` is when the last
     * of the money arrived.
     *
     * This updates the booking only; the ledger row is the caller's job (see `Payments`).
     */
    public function applyPayment(Booking $booking, float $amount, ?string $paymentIntentId = null): bool
    {
        $booking->amountPaid = PaymentHelper::round((float)$booking->amountPaid + $amount, $booking->currency);
        $booking->paymentStatus = PaymentHelper::statusFor((float)$booking->price, $booking->amountPaid, $booking->currency)->value;

        if ($paymentIntentId !== null) {
            $booking->stripePaymentIntentId = $paymentIntentId;
        }

        if ($booking->paymentStatus === PaymentStatus::Paid->value) {
            $booking->paidAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }

        return Craft::$app->getElements()->saveElement($booking);
    }

    /**
     * Mark a booking paid in full by a Stripe payment.
     *
     * @deprecated in 5.9.0. Use `applyPayment()`, which counts the amount actually received —
     * with deposits, one payment no longer means the whole price.
     */
    public function markPaid(Booking $booking, string $paymentIntentId): bool
    {
        return $this->applyPayment($booking, $booking->getBalanceDue(), $paymentIntentId);
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

            // Money received this month, from the payments ledger: a deposit counts in the
            // month it was paid and its balance in the month that was. Summing the prices of
            // bookings marked paid would count a deposit booking's whole price when its
            // balance arrived, and its deposit never.
            'monthlyRevenue' => Plugin::getInstance()->payments->getRevenueBetween($monthStart, $monthEnd),

            // What's still owed on bookings that are going ahead — a pay-in-person price, a
            // deposit's balance. Pending bookings are left out: one waiting on an online
            // payment that never came isn't money anyone is owed. Nor are trashed ones.
            'outstandingBalance' => (float)(new Query())
                ->from(['b' => '{{%stub_bookings}}'])
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[b.id]]')
                ->where(['b.paymentStatus' => [PaymentStatus::Unpaid->value, PaymentStatus::PartiallyPaid->value]])
                ->andWhere(['b.bookingStatus' => [BookingStatus::Confirmed->value, BookingStatus::Completed->value]])
                ->andWhere(['>', 'b.price', 0])
                ->andWhere(['e.dateDeleted' => null])
                ->sum(new \yii\db\Expression('[[b.price]] - [[b.amountPaid]]')),
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
