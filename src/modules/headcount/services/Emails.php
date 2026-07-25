<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\jobs\SendMemberEmail;
use justinholtweb\headcount\models\DripSchedule;
use yii\base\Component;

/**
 * The member lifecycle emails.
 *
 * Every one of these is a **Craft system message**, so its copy is editable under
 * Settings → Email → System Messages, translatable, and rendered through whatever HTML
 * email template the site has configured — the same path Craft's own emails take. They used
 * to be strings hardcoded in this file, which meant no site could change a word of them and
 * no email template could reach them.
 *
 * Sending stays queued: a payment webhook shouldn't wait on an SMTP round-trip.
 */
class Emails extends Component
{
    public function sendWelcomeEmail(Subscription $subscription): void
    {
        $this->queue('headcount_welcome', $subscription);
    }

    public function sendPaymentReceiptEmail(Subscription $subscription, float $amount): void
    {
        $this->queue('headcount_receipt', $subscription, [
            'amount' => number_format($amount, 2),
        ]);
    }

    public function sendPaymentFailedEmail(Subscription $subscription): void
    {
        $this->queue('headcount_payment_failed', $subscription);
    }

    public function sendExpirationReminderEmail(Subscription $subscription): void
    {
        $this->queue('headcount_expiration_reminder', $subscription);
    }

    public function sendTrialEndingEmail(Subscription $subscription): void
    {
        $this->queue('headcount_trial_ending', $subscription);
    }

    public function sendCancellationEmail(Subscription $subscription): void
    {
        $this->queue('headcount_cancellation', $subscription);
    }

    public function sendDripUnlockedEmail(Subscription $subscription, DripSchedule $schedule): void
    {
        $this->queue('headcount_drip_unlocked', $subscription, [
            'scheduleName' => $schedule->name,
        ]);
    }

    /**
     * Queue one lifecycle email, if its setting allows it and there's someone to send it to.
     *
     * @param array<string, scalar> $extraVariables
     */
    private function queue(string $key, Subscription $subscription, array $extraVariables = []): void
    {
        $definition = Headcount::emailDefinitions()[$key] ?? null;
        $settings = Headcount::getInstance()->getSettings();

        if ($definition === null || !$settings->{$definition['setting']}) {
            return;
        }

        $user = $subscription->getUser();

        if (!$user) {
            return;
        }

        Craft::$app->getQueue()->push(new SendMemberEmail([
            'key' => $key,
            'to' => $user->email,
            // Scalars only: the job is serialized into the queue table, so an element in the
            // payload both bloats it and is stale by the time the worker runs.
            'variables' => array_merge([
                'firstName' => $user->firstName ?: ($user->username ?: Craft::t('headcount', 'Member')),
                'email' => $user->email,
                'planName' => $subscription->getPlan()?->name ?? '',
                'siteName' => Craft::$app->getSystemName(),
                'siteUrl' => Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '',
                'currency' => strtoupper($subscription->currency ?: 'USD'),
            ], $extraVariables),
        ]));
    }
}
