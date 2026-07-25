<?php

namespace justinholtweb\headcount\jobs;

use craft\queue\BaseJob;
use justinholtweb\headcount\Headcount;

class ProcessExpiredSubscriptions extends BaseJob
{
    public function execute($queue): void
    {
        $count = Headcount::getInstance()->subscriptions->processExpiredSubscriptions();

        if ($count > 0) {
            \Craft::info("Processed {$count} expired subscriptions", 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing expired subscriptions';
    }
}
