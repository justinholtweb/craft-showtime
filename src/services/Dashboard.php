<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use justinholtweb\showtime\Plugin;
use Throwable;

/**
 * The one screen only the bundle can show: bookings and memberships side by side.
 *
 * Every read is null-safe and per-module, so the dashboard degrades to whatever is actually
 * mounted rather than fataling — Owl isn't mounted yet, and a module can always be added or
 * removed between releases.
 */
class Dashboard extends Component
{
    /**
     * @return array{bookings: ?array, memberships: ?array, combinedMonthly: float}
     */
    public function getStats(): array
    {
        $bookings = $this->bookingStats();
        $memberships = $this->membershipStats();

        return [
            'bookings' => $bookings,
            'memberships' => $memberships,
            // Booking revenue actually taken this month, plus recurring monthly revenue.
            // Two different kinds of number; shown as components as well as a total, since
            // the sum alone would be misleading.
            'combinedMonthly' => (float)($bookings['monthlyRevenue'] ?? 0) + (float)($memberships['mrr'] ?? 0),
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
