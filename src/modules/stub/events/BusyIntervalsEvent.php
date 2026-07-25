<?php

namespace justinholtweb\stub\events;

use yii\base\Event;

/**
 * Lets other code contribute time a provider is unavailable.
 *
 * Stub only knows about its own bookings, but a provider can be busy for reasons Stub has no
 * concept of — running a class, a synced external calendar, an all-hands. Handlers append to
 * `$intervals` and those windows stop generating bookable slots, exactly as an existing
 * booking does.
 *
 * Intervals are `['startDateTime' => 'Y-m-d H:i:s', 'endDateTime' => 'Y-m-d H:i:s']` in
 * **UTC**, matching how Stub stores booking times.
 */
class BusyIntervalsEvent extends Event
{
    public int $providerId;

    /** Start of the window being generated, UTC, `Y-m-d H:i:s`. */
    public string $startUtc;

    /** End of the window being generated, UTC, `Y-m-d H:i:s`. */
    public string $endUtc;

    /**
     * @var array<array{startDateTime: string, endDateTime: string}>
     */
    public array $intervals = [];
}
