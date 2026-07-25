<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use Craft;
use craft\base\Component;
use justinholtweb\owl\elements\Event;

/**
 * Convenience helpers for working with Event elements.
 */
class Events extends Component
{
    public function getEventById(int $id, ?int $siteId = null): ?Event
    {
        $element = Craft::$app->getElements()->getElementById($id, Event::class, $siteId);

        return $element instanceof Event ? $element : null;
    }
}
