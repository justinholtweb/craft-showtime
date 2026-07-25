<?php

namespace justinholtweb\headcount\events;

use craft\elements\Entry;
use craft\elements\User;
use yii\base\Event;

class GatingEvent extends Event
{
    public ?Entry $entry = null;
    public ?User $user = null;
    public bool $allowed = false;
    public ?string $behavior = null;
    public ?string $redirectUrl = null;
    public ?int $teaserLength = null;
}
