<?php

declare(strict_types=1);

namespace justinholtweb\owl\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\owl\Owl;

/**
 * Regenerates the materialised occurrence rows for an event off the request thread, and is also
 * run on a schedule to roll the generation horizon forward.
 */
class GenerateOccurrencesJob extends BaseJob
{
    public int $eventId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $event = Owl::getInstance()->events->getEventById($this->eventId, $this->siteId);

        if ($event === null) {
            return;
        }

        Owl::getInstance()->occurrences->regenerate($event);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('owl', 'Generating event occurrences');
    }
}
