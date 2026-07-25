<?php

declare(strict_types=1);

namespace justinholtweb\owl\events;

use DateTimeInterface;
use yii\base\Event;

/**
 * Lets other code contribute items to the calendar feed.
 *
 * Items are FullCalendar-shaped: `id`, `title`, `start`, `end`, `allDay`, `url`, `color`.
 *
 * **The feed is served anonymously.** Anything a handler appends is public unless the
 * handler checks permissions itself — so a handler contributing anything non-public must
 * gate on the current user.
 */
class FeedItemsEvent extends Event
{
    public DateTimeInterface $rangeStart;
    public DateTimeInterface $rangeEnd;

    /** @var int[] */
    public array $calendarIds = [];

    /** @var array<array<string, mixed>> */
    public array $items = [];
}
