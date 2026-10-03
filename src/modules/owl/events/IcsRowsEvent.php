<?php

declare(strict_types=1);

namespace justinholtweb\owl\events;

use justinholtweb\owl\elements\Event as OwlEvent;
use justinholtweb\owl\models\Calendar;
use yii\base\Event;

/**
 * Lets other code remove occurrences from an ICS feed before it is built.
 *
 * ICS feeds are served anonymously and subscribed to by calendar apps, so an event a site keeps
 * from some visitors — members-only, say — has to be filtered here as well as in the JSON feed
 * (`FeedController::EVENT_DEFINE_FEED_ITEMS`). Each row carries `eventId`, `calendarId`, `title`,
 * `startDate` and `endDate`. Exactly one of `calendar` and `event` is set.
 */
class IcsRowsEvent extends Event
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?Calendar $calendar = null;

    public ?OwlEvent $event = null;
}
