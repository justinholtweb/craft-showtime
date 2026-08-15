<?php

namespace justinholtweb\headcount\jobs;

use craft\queue\BaseJob;
use justinholtweb\headcount\Headcount;

class SendExpirationReminders extends BaseJob
{
    public function execute($queue): void
    {
        $count = Headcount::getInstance()->subscriptions->sendExpirationReminders();

        if ($count > 0) {
            \Craft::info("Queued {$count} expiration reminder(s)", 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Sending expiration reminders';
    }
}
