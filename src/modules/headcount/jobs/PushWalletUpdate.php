<?php

namespace justinholtweb\headcount\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\headcount\Headcount;

/**
 * Bring a member's wallet cards back in step with their membership.
 *
 * Both platforms are attempted independently: Apple's devices are nudged into re-fetching
 * the pass, and Google's copy of the card is patched directly. Neither failing stops the
 * other — a member with both should not lose the update on one because the other's service
 * was unreachable.
 */
class PushWalletUpdate extends BaseJob
{
    public ?int $subscriptionId = null;

    public function execute($queue): void
    {
        $plugin = Headcount::getInstance();
        $subscription = $plugin->subscriptions->getSubscriptionById($this->subscriptionId);

        if (!$subscription) {
            return;
        }

        if ($plugin->wallet->isGoogleConfigured()) {
            $plugin->googleWallet->syncObject($subscription);
        }

        if ($plugin->wallet->isAppleConfigured()) {
            try {
                $plugin->applePush->pushForSubscription($subscription);
            } catch (\Throwable $e) {
                Craft::error(
                    "Could not push Apple Wallet updates for subscription #{$this->subscriptionId}: " . $e->getMessage(),
                    'headcount',
                );
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Updating wallet cards for subscription #{$this->subscriptionId}";
    }
}
