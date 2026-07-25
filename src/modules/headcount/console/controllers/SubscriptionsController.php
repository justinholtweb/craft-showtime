<?php

namespace justinholtweb\headcount\console\controllers;

use craft\console\Controller;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\console\ExitCode;

class SubscriptionsController extends Controller
{
    /**
     * Process expired subscriptions.
     *
     * Usage: php craft headcount/subscriptions/expire
     */
    public function actionExpire(): int
    {
        $this->stdout("Processing expired subscriptions...\n");

        $count = Headcount::getInstance()->subscriptions->processExpiredSubscriptions();

        $this->stdout("Processed {$count} expired subscription(s).\n");

        return ExitCode::OK;
    }

    /**
     * Sync all subscriptions from payment providers.
     *
     * Usage: php craft headcount/subscriptions/sync
     */
    public function actionSync(): int
    {
        $this->stdout("Syncing subscriptions with payment providers...\n");

        $subscriptions = Subscription::find()
            ->status([Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])
            ->all();

        $synced = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            $this->stdout("  Syncing subscription #{$subscription->id} ({$subscription->gateway})...");

            try {
                if ($subscription->gateway === 'stripe' && $subscription->gatewaySubscriptionId) {
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
                        $this->stdout(" status changed to {$newStatus}");
                    }
                } elseif ($subscription->gateway === 'paypal' && $subscription->gatewaySubscriptionId) {
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
                        $this->stdout(" status changed to {$newStatus}");
                    }
                }

                $this->stdout(" OK\n");
                $synced++;
            } catch (\Throwable $e) {
                $this->stderr(" FAILED: {$e->getMessage()}\n");
                $failed++;
            }
        }

        $this->stdout("\nSync complete. {$synced} synced, {$failed} failed.\n");

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
