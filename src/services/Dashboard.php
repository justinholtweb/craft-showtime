<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\showtime\Plugin;
use Throwable;

/**
 * The one screen only the bundle can show: bookings, event tickets and memberships side by side.
 *
 * Every read is null-safe and per-module, so the dashboard degrades to whatever is actually
 * mounted rather than fataling — Owl isn't mounted yet, and a module can always be added or
 * removed between releases.
 */
class Dashboard extends Component
{
    /**
     * @return array{bookings: ?array, tickets: ?array, memberships: ?array, combinedMonthly: float}
     */
    public function getStats(): array
    {
        $bookings = $this->bookingStats();
        $tickets = $this->ticketStats();
        $memberships = $this->membershipStats();

        return [
            'bookings' => $bookings,
            'tickets' => $tickets,
            'memberships' => $memberships,
            // Booking and ticket money actually taken this month, plus recurring monthly
            // revenue. Two different kinds of number; shown as components as well as a total,
            // since the sum alone would be misleading.
            'combinedMonthly' => (float)($bookings['monthlyRevenue'] ?? 0)
                + (float)($tickets['monthlyRevenue'] ?? 0)
                + (float)($memberships['mrr'] ?? 0),
        ];
    }

    /**
     * Event ticket and registration revenue taken this month.
     *
     * Owl has no sales table of its own: a registration *is* a completed Commerce order with a
     * ticket line item (see `Tickets::registrationsForEmail()`). So this reads the order tables
     * directly, counting an order in the month it was **paid** — cash received, the same rule
     * Stub's figure follows for deposits and balances. Free registrations count as tickets
     * but add nothing. Trashed orders are left out, as Commerce's own reports leave them out.
     *
     * Null when Owl isn't mounted or ticketing can't run (Lite, or no Commerce).
     *
     * @return array{monthlyRevenue: float, monthlyTickets: int, monthlyRegistrations: int}|null
     */
    public function ticketStats(?DateTime $now = null): ?array
    {
        /** @var \justinholtweb\owl\Owl|null $owl */
        $owl = Plugin::getInstance()->getModuleByHandle('owl');

        if ($owl === null || !$owl->commerceAvailable()) {
            return null;
        }

        try {
            [$start, $end] = $this->monthBounds($now);

            $row = (new Query())
                ->select([
                    'revenue' => 'SUM([[li.total]])',
                    'tickets' => 'SUM([[li.qty]])',
                    'registrations' => 'COUNT(DISTINCT [[o.id]])',
                ])
                ->from(['li' => '{{%commerce_lineitems}}'])
                ->innerJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[li.orderId]]')
                ->innerJoin(['t' => '{{%owl_tickets}}'], '[[t.id]] = [[li.purchasableId]]')
                ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[o.id]]')
                ->where(['o.isCompleted' => true])
                ->andWhere(['o.paidStatus' => ['paid', 'overPaid']])
                ->andWhere(['>=', 'o.datePaid', $start])
                ->andWhere(['<=', 'o.datePaid', $end])
                ->andWhere(['e.dateDeleted' => null])
                ->one();

            return [
                'monthlyRevenue' => (float)($row['revenue'] ?? 0),
                'monthlyTickets' => (int)($row['tickets'] ?? 0),
                'monthlyRegistrations' => (int)($row['registrations'] ?? 0),
            ];
        } catch (Throwable $e) {
            Craft::warning("Showtime dashboard: couldn't read ticket revenue — {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * This calendar month in the system timezone — "this month" for the person looking at the
     * dashboard — as the naive UTC strings Commerce stores its dates in.
     *
     * @return array{0: string, 1: string}
     */
    private function monthBounds(?DateTime $now = null): array
    {
        $tz = new DateTimeZone(Craft::$app->getTimeZone());
        $now = $now ? (clone $now)->setTimezone($tz) : new DateTime('now', $tz);
        $utc = new DateTimeZone('UTC');

        $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $end = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return \justinholtweb\stub\elements\Booking[]
     */
    public function getTodaysBookings(): array
    {
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub === null) {
            return [];
        }

        try {
            return $stub->bookings->getTodaysBookings();
        } catch (Throwable $e) {
            Craft::warning("Showtime dashboard: couldn't read today's bookings — {$e->getMessage()}", __METHOD__);
            return [];
        }
    }

    private function bookingStats(): ?array
    {
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub === null) {
            return null;
        }

        try {
            return $stub->bookings->getBookingStats();
        } catch (Throwable $e) {
            Craft::warning("Showtime dashboard: couldn't read booking stats — {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    private function membershipStats(): ?array
    {
        /** @var \justinholtweb\headcount\Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        if ($headcount === null) {
            return null;
        }

        try {
            return $headcount->reporting->getDashboardStats();
        } catch (Throwable $e) {
            Craft::warning("Showtime dashboard: couldn't read membership stats — {$e->getMessage()}", __METHOD__);
            return null;
        }
    }
}
