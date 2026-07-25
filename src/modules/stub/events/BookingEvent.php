<?php

namespace justinholtweb\stub\events;

use justinholtweb\stub\elements\Booking;
use yii\base\Event;

class BookingEvent extends Event
{
    public Booking $booking;
    public bool $isNew = false;

    /**
     * Set to false from an EVENT_BEFORE_SAVE_BOOKING handler to refuse the booking.
     *
     * Add an error to the booking to explain why; a generic one is added if you don't.
     * Defaults to true, so existing handlers are unaffected.
     */
    public bool $isValid = true;
}
