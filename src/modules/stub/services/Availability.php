<?php

namespace justinholtweb\stub\services;

use craft\db\Query;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\events\BusyIntervalsEvent;
use justinholtweb\stub\helpers\TimeHelper;
use justinholtweb\stub\Plugin;
use yii\base\Component;

class Availability extends Component
{
    /**
     * @event BusyIntervalsEvent Fired when working out which windows a provider is
     * unavailable in, so other code can contribute its own.
     */
    public const EVENT_DEFINE_BUSY_INTERVALS = 'defineBusyIntervals';

    /**
     * Get available dates for a provider+service in a given month.
     *
     * @return string[] Array of Y-m-d date strings that have at least one available slot
     */
    public function getAvailableDates(int $serviceId, int $providerId, int $year, int $month, string $customerTimezone = 'UTC'): array
    {
        $service = Plugin::getInstance()->services->getServiceById($serviceId);
        $provider = Plugin::getInstance()->providers->getProviderById($providerId);

        if (!$service || !$provider) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $availableDates = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dateObj = new DateTime($date, new DateTimeZone($provider->timezone));

            // Skip past dates
            $now = TimeHelper::nowInTimezone($provider->timezone);
            if ($dateObj->format('Y-m-d') < $now->format('Y-m-d')) {
                continue;
            }

            // Skip beyond max advance booking
            $maxDate = clone $now;
            $maxDate->modify("+{$settings->maxAdvanceBooking} days");
            if ($dateObj > $maxDate) {
                continue;
            }

            // Check blocked dates
            if (Plugin::getInstance()->providers->isDateBlocked($providerId, $date)) {
                continue;
            }

            // Check if provider works this day
            $dayOfWeek = (int)$dateObj->format('w');
            $schedule = Plugin::getInstance()->providers->getScheduleForDay($providerId, $dayOfWeek);
            if (!$schedule || !$schedule->enabled) {
                continue;
            }

            $availableDates[] = $date;
        }

        return $availableDates;
    }

    /**
     * Get available time slots for a specific date.
     *
     * @return array Array of ['time' => 'HH:MM', 'display' => 'h:mm A'] in customer timezone
     */
    public function getAvailableSlots(int $serviceId, int $providerId, string $date, string $customerTimezone = 'UTC'): array
    {
        $service = Plugin::getInstance()->services->getServiceById($serviceId);
        $provider = Plugin::getInstance()->providers->getProviderById($providerId);

        if (!$service || !$provider) {
            return [];
        }

        $settings = Plugin::getInstance()->getSettings();
        $providerTz = new DateTimeZone($provider->timezone);
        $customerTz = new DateTimeZone($customerTimezone);
        $utcTz = new DateTimeZone('UTC');

        // Check blocked
        if (Plugin::getInstance()->providers->isDateBlocked($providerId, $date)) {
            return [];
        }

        // Get schedule for this day of week
        $dateObj = new DateTime($date, $providerTz);
        $dayOfWeek = (int)$dateObj->format('w');
        $schedule = Plugin::getInstance()->providers->getScheduleForDay($providerId, $dayOfWeek);

        if (!$schedule || !$schedule->enabled) {
            return [];
        }

        // Working window in provider timezone, then convert to UTC
        $dayStart = new DateTime("{$date} {$schedule->startTime}", $providerTz);
        $dayEnd = new DateTime("{$date} {$schedule->endTime}", $providerTz);
        $dayStartUtc = clone $dayStart;
        $dayStartUtc->setTimezone($utcTz);
        $dayEndUtc = clone $dayEnd;
        $dayEndUtc->setTimezone($utcTz);

        // Get breaks for this day, convert to UTC
        $breaks = Plugin::getInstance()->providers->getBreaksForDay($providerId, $dayOfWeek);
        $breakPeriodsUtc = [];
        foreach ($breaks as $brk) {
            $breakStart = new DateTime("{$date} {$brk->startTime}", $providerTz);
            $breakEnd = new DateTime("{$date} {$brk->endTime}", $providerTz);
            $breakStart->setTimezone($utcTz);
            $breakEnd->setTimezone($utcTz);
            $breakPeriodsUtc[] = [$breakStart, $breakEnd];
        }

        // Query existing active bookings for this provider overlapping the working window
        $existingBookings = $this->_getExistingBookings(
            $providerId,
            $dayStartUtc->format('Y-m-d H:i:s'),
            $dayEndUtc->format('Y-m-d H:i:s'),
        );

        // Generate candidate slots
        $slotInterval = $settings->slotInterval;
        $duration = $service->duration;
        $bufferBefore = $service->bufferTimeBefore;
        $bufferAfter = $service->bufferTimeAfter;
        $capacity = $service->capacity;
        $minimumNotice = $settings->minimumNotice;

        $now = TimeHelper::nowUtc();
        $minimumStart = clone $now;
        $minimumStart->modify("+{$minimumNotice} minutes");

        $slots = [];
        $current = clone $dayStartUtc;
        $lastSlotStart = clone $dayEndUtc;
        $lastSlotStart->modify("-{$duration} minutes");

        while ($current <= $lastSlotStart) {
            $slotStart = clone $current;
            $slotEnd = clone $current;
            $slotEnd->modify("+{$duration} minutes");

            // With buffers
            $slotWithBufferStart = clone $slotStart;
            $slotWithBufferStart->modify("-{$bufferBefore} minutes");
            $slotWithBufferEnd = clone $slotEnd;
            $slotWithBufferEnd->modify("+{$bufferAfter} minutes");

            $reject = false;

            // Past check (with minimum notice)
            if ($slotStart < $minimumStart) {
                $reject = true;
            }

            // Break overlap check
            if (!$reject) {
                foreach ($breakPeriodsUtc as [$breakStart, $breakEnd]) {
                    if (TimeHelper::dateRangeOverlaps($slotStart, $slotEnd, $breakStart, $breakEnd)) {
                        $reject = true;
                        break;
                    }
                }
            }

            // Capacity check
            if (!$reject) {
                $overlapping = 0;
                foreach ($existingBookings as $booking) {
                    $bStart = new DateTime($booking['startDateTime'], $utcTz);
                    $bEnd = new DateTime($booking['endDateTime'], $utcTz);

                    if (TimeHelper::dateRangeOverlaps($slotWithBufferStart, $slotWithBufferEnd, $bStart, $bEnd)) {
                        $overlapping++;
                    }
                }

                if ($overlapping >= $capacity) {
                    $reject = true;
                }
            }

            if (!$reject) {
                $displayTime = clone $slotStart;
                $displayTime->setTimezone($customerTz);

                $slots[] = [
                    'time' => $displayTime->format('H:i'),
                    'display' => $displayTime->format('g:i A'),
                    'utc' => $slotStart->format('Y-m-d H:i:s'),
                ];
            }

            $current->modify("+{$slotInterval} minutes");
        }

        return $slots;
    }

    /**
     * Every window in which this provider is unavailable — their own bookings, plus anything
     * another plugin contributes via EVENT_DEFINE_BUSY_INTERVALS.
     */
    private function _getExistingBookings(int $providerId, string $startUtc, string $endUtc): array
    {
        $intervals = (new Query())
            ->select(['startDateTime', 'endDateTime'])
            ->from('{{%stub_bookings}}')
            ->where(['providerId' => $providerId])
            ->andWhere(['in', 'bookingStatus', ['pending', 'confirmed']])
            ->andWhere(['<', 'startDateTime', $endUtc])
            ->andWhere(['>', 'endDateTime', $startUtc])
            ->all();

        // A provider can be busy for reasons Stub knows nothing about — running a class,
        // a synced external calendar. Handlers append UTC intervals and those windows stop
        // producing bookable slots, exactly as an existing booking does.
        $event = new BusyIntervalsEvent([
            'providerId' => $providerId,
            'startUtc' => $startUtc,
            'endUtc' => $endUtc,
            'intervals' => $intervals,
        ]);

        $this->trigger(self::EVENT_DEFINE_BUSY_INTERVALS, $event);

        return $event->intervals;
    }
}
