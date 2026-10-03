<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\Plugin;
use yii\base\Component;

/**
 * Works out which bookings are due a reminder and sends it.
 *
 * Craft has no scheduler, so nothing here runs on its own — `stub/reminders/send` on a
 * cron is what drives it. That makes the sweep's two properties load-bearing: it must be
 * safe to run at any interval (it only ever picks up bookings inside the lead-time
 * window), and safe to run twice at once (a booking is claimed with an atomic UPDATE
 * before its email is composed, so an overlapping run finds nothing to do).
 */
class Reminders extends Component
{
    /**
     * How many bookings one sweep will send for. A run that hits this simply leaves the
     * rest for the next one — they stay inside the window until their start time passes.
     */
    public const DEFAULT_LIMIT = 250;

    /**
     * The window a booking's start time has to fall in to be due a reminder, as naive UTC
     * strings.
     *
     * The lower bound is "now" rather than "now minus something": a sweep that hasn't run
     * for three days should not mail people about appointments they have already been to.
     * The upper bound is what makes the sweep interval-agnostic — run it every five
     * minutes or once an hour and the same bookings come back, each exactly once.
     *
     * Pure, and takes `$now` rather than reading the clock, so the boundaries are testable
     * without a booted Craft.
     *
     * @return array{0: string, 1: string} [start, end]
     */
    public static function dueWindow(DateTime $now, int $leadTimeHours): array
    {
        $start = (clone $now)->setTimezone(new DateTimeZone('UTC'));
        $end = (clone $start)->modify("+{$leadTimeHours} hours");

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * Whether any reminder would be sent at all. When both switches are off the sweep has
     * nothing to do, and must not stamp bookings as reminded on the way past.
     */
    public function isEnabled(): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $settings->sendCustomerReminder || $settings->sendInternalReminder;
    }

    /**
     * Confirmed bookings starting inside the lead-time window that haven't been reminded.
     *
     * Only confirmed ones: a pending booking is one that hasn't been paid for, and telling
     * someone not to forget an appointment they don't have yet is worse than saying
     * nothing. Cancelled, completed and no-show bookings are excluded for the same reason.
     *
     * @return Booking[]
     */
    public function getDueBookings(?DateTime $now = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $leadTime = Plugin::getInstance()->getSettings()->reminderLeadTime;
        [$start, $end] = self::dueWindow($now ?? new DateTime('now', new DateTimeZone('UTC')), $leadTime);

        // `startDateTime` is queried directly rather than through the element query's date
        // param, which `Db::parseDateParam()` reads as system-local. These bounds are
        // already UTC, which is how the column is stored.
        $ids = (new Query())
            ->select(['id'])
            ->from('{{%stub_bookings}}')
            ->where(['bookingStatus' => BookingStatus::Confirmed->value])
            ->andWhere(['reminderSentAt' => null])
            ->andWhere(['>', 'startDateTime', $start])
            ->andWhere(['<=', 'startDateTime', $end])
            ->orderBy(['startDateTime' => SORT_ASC])
            ->limit($limit)
            ->column();

        if (!$ids) {
            return [];
        }

        // Hydrating through the element query is what drops anything in the trash — the
        // plugin table keeps the row until Craft garbage-collects the element.
        return Booking::find()
            ->id($ids)
            ->orderBy(['stub_bookings.startDateTime' => SORT_ASC])
            ->all();
    }

    /**
     * Send reminders for everything currently due.
     *
     * Callers that have already loaded the due list — the console command, which prints it
     * first — pass it in rather than making the sweep ask twice.
     *
     * @param Booking[]|null $bookings
     * @return array{sent: int, failed: int, bookings: int}
     */
    public function sendDue(?DateTime $now = null, int $limit = self::DEFAULT_LIMIT, ?array $bookings = null): array
    {
        $due = $bookings ?? $this->getDueBookings($now, $limit);
        $result = ['sent' => 0, 'failed' => 0, 'bookings' => count($due)];

        foreach ($due as $booking) {
            // Claim first. If another run got there in the moment between the query and
            // here, it owns the booking and this one moves on rather than sending a
            // second copy.
            if (!$this->claim($booking)) {
                $result['bookings']--;
                continue;
            }

            $sent = Plugin::getInstance()->emails->sendReminder($booking);

            if ($sent > 0) {
                $result['sent'] += $sent;
            } else {
                $result['failed']++;
                Craft::warning(
                    "No reminder went out for booking {$booking->referenceNumber}; it is marked as reminded and won't be retried.",
                    __METHOD__,
                );
            }
        }

        return $result;
    }

    /**
     * Send a reminder for one booking on request, from the control panel.
     *
     * Unlike the sweep this doesn't care whether the booking is due, or whether one has
     * already gone out — someone asked for it. It still stamps the booking, so the sweep
     * doesn't follow it up with a duplicate an hour later.
     *
     * @return int how many messages went out
     */
    public function sendNow(Booking $booking): int
    {
        $sent = Plugin::getInstance()->emails->sendReminder($booking);

        if ($sent > 0) {
            $this->stamp($booking);
        }

        return $sent;
    }

    /**
     * Take ownership of a booking's reminder.
     *
     * The `reminderSentAt IS NULL` in the WHERE clause is the whole point: the database
     * decides which of two concurrent runs gets to send, and the loser sees zero affected
     * rows.
     */
    private function claim(Booking $booking): bool
    {
        $at = $this->nowUtcString();

        $rows = Craft::$app->getDb()->createCommand()
            ->update(
                '{{%stub_bookings}}',
                ['reminderSentAt' => $at],
                ['and', ['id' => $booking->id], ['reminderSentAt' => null]],
            )
            ->execute();

        if ($rows === 0) {
            return false;
        }

        $booking->reminderSentAt = $at;

        return true;
    }

    /**
     * Record that a reminder went out, without re-saving the element.
     *
     * This is bookkeeping, not a content change: going through `saveElement()` would fire
     * the save events, touch `dateUpdated` and re-index the booking, all to write one
     * timestamp that nothing else keys off.
     */
    private function stamp(Booking $booking): void
    {
        $at = $this->nowUtcString();

        Craft::$app->getDb()->createCommand()
            ->update('{{%stub_bookings}}', ['reminderSentAt' => $at], ['id' => $booking->id])
            ->execute();

        $booking->reminderSentAt = $at;
    }

    private function nowUtcString(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
