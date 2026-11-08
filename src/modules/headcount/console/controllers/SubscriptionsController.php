<?php

namespace justinholtweb\headcount\console\controllers;

use craft\console\Controller;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\console\ExitCode;

class SubscriptionsController extends Controller
{
    /**
     * @var int How many days of Stripe checkouts `import` looks back over.
     */
    public int $days = 90;

    /**
     * @var bool Report what `import` would create without creating anything.
     */
    public bool $dryRun = false;

    /**
     * @var bool Don't send welcome emails for imported subscriptions.
     */
    public bool $skipEmails = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'import') {
            $options[] = 'days';
            $options[] = 'dryRun';
            $options[] = 'skipEmails';
        }

        return $options;
    }

    /**
     * Create subscriptions for Stripe checkouts Headcount never recorded.
     *
     * Headcount learns about a checkout from Stripe's webhook or the member's return to the
     * site. If both were missed — a misconfigured endpoint and a member who closed the tab —
     * the payment is in Stripe and nowhere else. This finds completed Headcount checkouts in
     * Stripe and creates any subscriptions that are missing, with their current Stripe status.
     * Running it again creates nothing new.
     *
     * Usage: php craft headcount/subscriptions/import [--days=90] [--dry-run] [--skip-emails]
     */
    public function actionImport(): int
    {
        $headcount = Headcount::getInstance();

        if (!$headcount->getSettings()->stripeEnabled) {
            $this->stderr("Stripe isn't enabled.\n");
            return ExitCode::CONFIG;
        }

        $since = time() - max(1, $this->days) * 86400;
        $this->stdout(($this->dryRun ? '[dry run] ' : '') . "Looking for Stripe checkouts from the last {$this->days} day(s)...\n");

        $counts = [];

        try {
            foreach ($headcount->stripe->getCompletedCheckoutSessions($since) as $session) {
                // Not one of ours: some other integration on the same Stripe account.
                if (empty($session->metadata->plan_id)) {
                    continue;
                }

                $userId = $session->metadata->craft_user_id ?? $session->client_reference_id ?? '?';
                $label = "  {$session->id} (user #{$userId}, plan #{$session->metadata->plan_id})";

                if ($this->dryRun) {
                    $recorded = $headcount->subscriptions->isCheckoutSessionRecorded($session->id)
                        || $headcount->subscriptions->getSubscriptionForCheckoutSession($session);
                    $result = $recorded ? 'already_exists' : 'would_create';
                } else {
                    try {
                        $result = $headcount->subscriptions->createFromStripeCheckoutSession($session, !$this->skipEmails);
                    } catch (\Throwable $e) {
                        $result = 'failed';
                        $this->stderr("{$label}: FAILED: {$e->getMessage()}\n");
                        $counts[$result] = ($counts[$result] ?? 0) + 1;
                        continue;
                    }
                }

                $counts[$result] = ($counts[$result] ?? 0) + 1;

                if ($result !== 'already_exists') {
                    $this->stdout("{$label}: {$result}\n");
                }
            }
        } catch (\Throwable $e) {
            $this->stderr("Couldn't list checkouts from Stripe: {$e->getMessage()}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$counts) {
            $this->stdout("No Headcount checkouts found in Stripe for that period.\n");
            return ExitCode::OK;
        }

        $summary = [];
        foreach ($counts as $result => $count) {
            $summary[] = "{$count} {$result}";
        }

        $this->stdout("\nDone: " . implode(', ', $summary) . ".\n");

        $failed = 0;
        foreach ($counts as $result => $count) {
            if ($headcount->webhooks->isFailure($result)) {
                $failed += $count;
            }
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
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
     * Email members whose membership expires within the reminder window.
     *
     * Run this daily. Each member is reminded once per term, so running it more often
     * doesn't send more email.
     *
     * Usage: php craft headcount/subscriptions/remind
     */
    public function actionRemind(): int
    {
        $this->stdout("Sending expiration reminders...\n");

        $count = Headcount::getInstance()->subscriptions->sendExpirationReminders();

        $this->stdout("Queued {$count} reminder(s).\n");

        return ExitCode::OK;
    }

    /**
     * Refresh the status of the subscriptions Headcount already has from their payment
     * providers. To create ones Headcount missed, use `import`.
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

        if (!$subscriptions) {
            $this->stdout("Headcount has no active subscriptions to sync. This updates subscriptions Headcount already has;\n");
            $this->stdout("to create ones it missed from Stripe, run: php craft headcount/subscriptions/import\n");
        }

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
