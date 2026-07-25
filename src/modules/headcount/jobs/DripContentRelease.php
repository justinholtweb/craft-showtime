<?php

namespace justinholtweb\headcount\jobs;

use craft\queue\BaseJob;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;

class DripContentRelease extends BaseJob
{
    public function execute($queue): void
    {
        $schedules = Headcount::getInstance()->drip->getAllSchedules(true);

        if (empty($schedules)) {
            return;
        }

        // Get all active subscriptions
        $subscriptions = Subscription::find()->active()->all();

        foreach ($subscriptions as $subscription) {
            if (!$subscription->startDate || !$subscription->userId) {
                continue;
            }

            foreach ($schedules as $schedule) {
                $planIds = $schedule->planIds ?? [];
                if (!in_array($subscription->planId, $planIds)) {
                    continue;
                }

                $unlockDate = (clone $subscription->startDate)->modify("+{$schedule->delayDays} days");
                $now = new \DateTime();

                // If content just unlocked today (within 24 hours)
                $diff = $now->diff($unlockDate);
                if (!$diff->invert && $diff->days === 0) {
                    // Send drip unlocked notification
                    $settings = Headcount::getInstance()->getSettings();
                    if ($settings->sendDripUnlockedEmail) {
                        Headcount::getInstance()->emails->sendDripUnlockedEmail($subscription, $schedule);
                    }
                }
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing drip content releases';
    }
}
