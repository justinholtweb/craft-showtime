<?php

namespace justinholtweb\headcount\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;

class SyncSubscriptionStatus extends BaseJob
{
    public ?int $subscriptionId = null;

    public function execute($queue): void
    {
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($this->subscriptionId);
        if (!$subscription) {
            return;
        }

        if ($subscription->gateway === 'stripe' && $subscription->gatewaySubscriptionId) {
            $this->syncStripeSubscription($subscription);
        } elseif ($subscription->gateway === 'paypal' && $subscription->gatewaySubscriptionId) {
            $this->syncPayPalSubscription($subscription);
        }
    }

    private function syncStripeSubscription(Subscription $subscription): void
    {
        try {
            $stripeSubscription = Headcount::getInstance()->stripe->getSubscription($subscription->gatewaySubscriptionId);

            $statusMap = [
                'active' => Subscription::STATUS_ACTIVE,
                'trialing' => Subscription::STATUS_TRIALING,
                'past_due' => Subscription::STATUS_PAST_DUE,
                'canceled' => Subscription::STATUS_CANCELED,
                'unpaid' => Subscription::STATUS_PAST_DUE,
                'paused' => Subscription::STATUS_PAUSED,
            ];

            $newStatus = $statusMap[$stripeSubscription->status] ?? $subscription->status;

            if ($newStatus !== $subscription->status) {
                Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, $newStatus);
            }
        } catch (\Throwable $e) {
            Craft::error("Failed to sync Stripe subscription {$subscription->gatewaySubscriptionId}: " . $e->getMessage(), 'headcount');
        }
    }

    private function syncPayPalSubscription(Subscription $subscription): void
    {
        try {
            $paypalSubscription = Headcount::getInstance()->paypal->getSubscription($subscription->gatewaySubscriptionId);

            $statusMap = [
                'ACTIVE' => Subscription::STATUS_ACTIVE,
                'SUSPENDED' => Subscription::STATUS_PAUSED,
                'CANCELLED' => Subscription::STATUS_CANCELED,
                'EXPIRED' => Subscription::STATUS_EXPIRED,
            ];

            $newStatus = $statusMap[$paypalSubscription['status'] ?? ''] ?? $subscription->status;

            if ($newStatus !== $subscription->status) {
                Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, $newStatus);
            }
        } catch (\Throwable $e) {
            Craft::error("Failed to sync PayPal subscription {$subscription->gatewaySubscriptionId}: " . $e->getMessage(), 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Syncing subscription #{$this->subscriptionId} status";
    }
}
