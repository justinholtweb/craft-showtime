<?php

namespace justinholtweb\stub\events;

use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\models\Payment;
use yii\base\Event;

class PaymentEvent extends Event
{
    public Booking $booking;
    public Payment $payment;
}
