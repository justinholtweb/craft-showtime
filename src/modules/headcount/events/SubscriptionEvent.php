<?php

namespace justinholtweb\headcount\events;

use justinholtweb\headcount\elements\Subscription;
use yii\base\ModelEvent;

class SubscriptionEvent extends ModelEvent
{
    public ?Subscription $subscription = null;
    public bool $isNew = false;
}
