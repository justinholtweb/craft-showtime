<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\jobs\SendOutgoingWebhook;
use justinholtweb\headcount\models\Plan;
use justinholtweb\headcount\records\WebhookLogRecord;
use yii\base\Component;

class Webhooks extends Component
{
    /**
     * Handler results that mean the event wasn't applied. Logged as `failed`, and answered
     * with an error so Stripe retries.
     */
    public const FAILED_RESULTS = ['failed', 'plan_not_found', 'locked'];

    /**
     * Handler results for events that aren't Headcount's to act on — a Checkout session some
     * other integration on the same Stripe account created, say.
     */
    public const IGNORED_RESULTS = ['ignored', 'missing_metadata', 'incomplete', 'unpaid'];

    private const CACHE_REJECTED = 'headcount:webhook-rejected:';
    private const CACHE_FALLBACK = 'headcount:checkout-fallback';
    private const FALLBACK_GRACE = 15 * 60;

    /**
     * Queue a signed outgoing webhook for the configured endpoint.
     * No-op when no outgoing webhook URL is set.
     */
    public function dispatchOutgoing(string $event, array $data): void
    {
        $settings = Headcount::getInstance()->getSettings();

        if (!$settings->outgoingWebhookUrl) {
            return;
        }

        Craft::$app->getQueue()->push(new SendOutgoingWebhook([
            'event' => $event,
            'data' => $data,
        ]));
    }

    public function processStripeEvent(\Stripe\Event $event): string
    {
        // Idempotency check
        $existing = WebhookLogRecord::findOne(['eventId' => $event->id]);
        if ($existing && $existing->status === 'processed') {
            return 'already_processed';
        }

        // Log the webhook
        $log = new WebhookLogRecord();
        $log->gateway = 'stripe';
        $log->eventId = $event->id;
        $log->eventType = $event->type;
        $log->payload = json_encode($event->data->toArray());

        try {
            $result = match ($event->type) {
                'checkout.session.completed' => $this->_handleCheckoutCompleted($event),
                'checkout.session.async_payment_succeeded' => $this->_handleCheckoutCompleted($event),
                'customer.subscription.created' => $this->_handleSubscriptionCreated($event),
                'customer.subscription.updated' => $this->_handleSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->_handleSubscriptionDeleted($event),
                'invoice.paid' => $this->_handleInvoicePaid($event),
                'invoice.payment_failed' => $this->_handleInvoicePaymentFailed($event),
                'invoice.payment_action_required' => $this->_handlePaymentActionRequired($event),
                'customer.subscription.trial_will_end' => $this->_handleTrialWillEnd($event),
                default => 'ignored',
            };

            $log->status = $this->logStatusFor($result);

            if ($log->status === 'failed') {
                $log->error = "The {$event->type} handler returned \"{$result}\".";
                Craft::error("Stripe webhook {$event->id} ({$event->type}) failed: {$result}", 'headcount');
            }

            $log->save();

            return $result;
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->error = $e->getMessage();
            $log->save();

            Craft::error('Stripe webhook processing failed: ' . $e->getMessage(), 'headcount');
            return 'failed';
        }
    }

    /**
     * Whether a handler result means the event wasn't dealt with and Stripe should retry.
     *
     * Until 5.4.1 everything but `ignored` was logged as processed, so a checkout that
     * couldn't become a subscription answered Stripe with a 200, was never retried, and left
     * nothing in the logs.
     */
    public function isFailure(string $result): bool
    {
        return in_array($result, self::FAILED_RESULTS, true);
    }

    /**
     * How a handler result is recorded in the webhook log.
     */
    public function logStatusFor(string $result): string
    {
        if ($this->isFailure($result)) {
            return 'failed';
        }

        return in_array($result, self::IGNORED_RESULTS, true) ? 'ignored' : 'processed';
    }

    /**
     * Note a webhook that was refused before it could be logged — a bad or missing
     * signature. Those never reach `headcount_webhook_logs`, so without this the only trace
     * of a mis-set signing secret is one line in the web log.
     */
    public function recordRejection(string $gateway, string $reason): void
    {
        Craft::$app->getCache()->set(self::CACHE_REJECTED . $gateway, [
            'time' => time(),
            'reason' => $reason,
        ], 60 * 60 * 24 * 30);
    }

    /**
     * Note that a signup was created from the checkout return rather than a webhook. If no
     * webhook follows it, Stripe isn't reaching the site.
     */
    public function recordCheckoutFallback(): void
    {
        Craft::$app->getCache()->set(self::CACHE_FALLBACK, time(), 60 * 60 * 24 * 30);
    }

    /**
     * What the site knows about incoming webhooks for a gateway.
     *
     * @return array{
     *     lastReceived: ?\DateTime,
     *     lastFailed: ?array{time: \DateTime, eventType: string, error: string},
     *     lastRejected: ?array{time: \DateTime, reason: string},
     *     lastFallback: ?\DateTime,
     * }
     */
    public function getHealth(string $gateway = 'stripe'): array
    {
        /** @var WebhookLogRecord|null $latest */
        // Headcount's own checkout markers live in this table too; they aren't webhooks.
        $latest = WebhookLogRecord::find()
            ->where(['gateway' => $gateway])
            ->andWhere(['not', ['eventType' => Subscriptions::CHECKOUT_RECORDED]])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        $lastFailed = null;

        if ($latest && $latest->status === 'failed') {
            $lastFailed = [
                'time' => DateTimeHelper::toDateTime($latest->dateCreated) ?: null,
                'eventType' => (string)$latest->eventType,
                'error' => (string)$latest->error,
            ];
        }

        $cache = Craft::$app->getCache();
        $rejected = $cache->get(self::CACHE_REJECTED . $gateway);
        $fallback = $gateway === 'stripe' ? $cache->get(self::CACHE_FALLBACK) : false;

        return [
            'lastReceived' => $latest ? (DateTimeHelper::toDateTime($latest->dateCreated) ?: null) : null,
            'lastFailed' => $lastFailed,
            'lastRejected' => is_array($rejected) ? [
                'time' => DateTimeHelper::toDateTime($rejected['time']) ?: null,
                'reason' => (string)$rejected['reason'],
            ] : null,
            'lastFallback' => $fallback ? (DateTimeHelper::toDateTime($fallback) ?: null) : null,
        ];
    }

    /**
     * Problems worth interrupting a control panel user for, as HTML-safe sentences.
     *
     * @return string[]
     */
    public function getStripeProblems(): array
    {
        $settings = Headcount::getInstance()->getSettings();

        if (!$settings->stripeEnabled) {
            return [];
        }

        // Mounted in a host bundle, the host verifies webhooks with its own (possibly shared)
        // secret, so Headcount's own field being empty means nothing.
        if (Headcount::getInstance()->stripeWebhookRouter === null && !$settings->secretIsSet('stripeWebhookSecret')) {
            return [Craft::t('headcount', 'Stripe webhooks are refused because no signing secret is set. New memberships won’t activate and cancellations won’t be applied.')];
        }

        $health = $this->getHealth('stripe');
        $received = $health['lastReceived']?->getTimestamp() ?? 0;
        $problems = [];

        if ($health['lastRejected'] && $health['lastRejected']['time']->getTimestamp() > $received) {
            $problems[] = Craft::t('headcount', 'Stripe webhooks are being rejected ({reason}). Check that the signing secret matches the endpoint in Stripe — sandboxes, test mode and live mode each have their own.', [
                'reason' => Html::encode($health['lastRejected']['reason']),
            ]);
        }

        // The webhook for a checkout normally lands within seconds of the member coming back.
        // A quarter of an hour of silence after one means Stripe isn't reaching this site.
        $fallback = $health['lastFallback']?->getTimestamp();

        if ($fallback && $received < $fallback && time() - $fallback > self::FALLBACK_GRACE) {
            $problems[] = Craft::t('headcount', 'A member checked out with Stripe, but no Stripe webhook has arrived since. Check the webhook endpoint in your Stripe dashboard — renewals, failed payments and cancellations won’t reach Headcount until it does.');
        }

        if ($health['lastFailed']) {
            $problems[] = Craft::t('headcount', 'The last Stripe webhook ({type}) failed: {error}', [
                'type' => Html::encode($health['lastFailed']['eventType']),
                'error' => Html::encode($health['lastFailed']['error']),
            ]);
        }

        return $problems;
    }

    public function processPayPalEvent(array $data): string
    {
        $eventType = $data['event_type'] ?? '';
        $eventId = $data['id'] ?? '';

        // Idempotency check
        $existing = WebhookLogRecord::findOne(['eventId' => $eventId]);
        if ($existing && $existing->status === 'processed') {
            return 'already_processed';
        }

        $log = new WebhookLogRecord();
        $log->gateway = 'paypal';
        $log->eventId = $eventId;
        $log->eventType = $eventType;
        $log->payload = json_encode($data);

        try {
            $result = match ($eventType) {
                'BILLING.SUBSCRIPTION.ACTIVATED' => $this->_handlePayPalSubscriptionActivated($data),
                'BILLING.SUBSCRIPTION.CANCELLED' => $this->_handlePayPalSubscriptionCancelled($data),
                'BILLING.SUBSCRIPTION.SUSPENDED' => $this->_handlePayPalSubscriptionSuspended($data),
                'BILLING.SUBSCRIPTION.EXPIRED' => $this->_handlePayPalSubscriptionExpired($data),
                'PAYMENT.SALE.COMPLETED' => $this->_handlePayPalPaymentCompleted($data),
                'PAYMENT.SALE.DENIED' => $this->_handlePayPalPaymentDenied($data),
                default => 'ignored',
            };

            $log->status = $this->logStatusFor($result);
            $log->save();

            return $result;
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->error = $e->getMessage();
            $log->save();

            Craft::error('PayPal webhook processing failed: ' . $e->getMessage(), 'headcount');
            return 'failed';
        }
    }

    // -- Stripe Handlers --

    private function _handleCheckoutCompleted(\Stripe\Event $event): string
    {
        return Headcount::getInstance()->subscriptions->createFromStripeCheckoutSession($event->data->object);
    }

    private function _handleSubscriptionCreated(\Stripe\Event $event): string
    {
        // Usually handled by checkout.session.completed, but handle as fallback
        $stripeSubscription = $event->data->object;
        $existing = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubscription->id);

        if ($existing) {
            return 'already_exists';
        }

        // If it wasn't created via checkout, we may not have metadata
        // This is a fallback - primary creation happens via checkout.session.completed
        return 'ignored';
    }

    private function _handleSubscriptionUpdated(\Stripe\Event $event): string
    {
        $stripeSubscription = $event->data->object;
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubscription->id);

        if (!$subscription) {
            return 'not_found';
        }

        // Detect a plan change (upgrade/downgrade) from the subscription's price.
        $newPriceId = $stripeSubscription->items->data[0]->price->id ?? null;
        if ($newPriceId) {
            $newPlan = Headcount::getInstance()->plans->getPlanByStripePriceId($newPriceId);
            if ($newPlan && $newPlan->id !== $subscription->planId) {
                Headcount::getInstance()->subscriptions->changePlan($subscription, $newPlan);
            }
        }

        $newStatus = $this->mapStripeStatus($stripeSubscription->status);
        // toDateTime() returns false for an unparseable/missing value; coerce that
        // to null so the ?DateTime property assignment can't throw a TypeError.
        $subscription->endDate = DateTimeHelper::toDateTime($stripeSubscription->current_period_end) ?: null;
        $subscription->cancelAtPeriodEnd = $stripeSubscription->cancel_at_period_end;

        if ($stripeSubscription->canceled_at) {
            $subscription->canceledAt = DateTimeHelper::toDateTime($stripeSubscription->canceled_at) ?: null;
        }

        Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, $newStatus);

        return 'processed';
    }

    private function _handleSubscriptionDeleted(\Stripe\Event $event): string
    {
        $stripeSubscription = $event->data->object;
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubscription->id);

        if (!$subscription) {
            return 'not_found';
        }

        Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_CANCELED);

        // Send cancellation email
        Headcount::getInstance()->emails->sendCancellationEmail($subscription);

        return 'processed';
    }

    private function _handleInvoicePaid(\Stripe\Event $event): string
    {
        $invoice = $event->data->object;
        $stripeSubId = $invoice->subscription ?? null;

        if (!$stripeSubId) {
            return 'no_subscription';
        }

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubId);
        if (!$subscription) {
            return 'not_found';
        }

        // Update subscription period
        if ($invoice->lines?->data[0] ?? null) {
            $lineItem = $invoice->lines->data[0];
            $subscription->endDate = DateTimeHelper::toDateTime($lineItem->period->end ?? null) ?: null;
        }

        if ($subscription->status === Subscription::STATUS_PAST_DUE) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_ACTIVE);
        } else {
            Craft::$app->getElements()->saveElement($subscription);
        }

        // Send receipt email
        Headcount::getInstance()->emails->sendPaymentReceiptEmail($subscription, (float)($invoice->amount_paid / 100));

        return 'processed';
    }

    private function _handleInvoicePaymentFailed(\Stripe\Event $event): string
    {
        $invoice = $event->data->object;
        $stripeSubId = $invoice->subscription ?? null;

        if (!$stripeSubId) {
            return 'no_subscription';
        }

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubId);
        if (!$subscription) {
            return 'not_found';
        }

        Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_PAST_DUE);

        // Send payment failed email
        Headcount::getInstance()->emails->sendPaymentFailedEmail($subscription);

        return 'processed';
    }

    private function _handlePaymentActionRequired(\Stripe\Event $event): string
    {
        // Log but don't change status - Stripe handles SCA
        return 'processed';
    }

    private function _handleTrialWillEnd(\Stripe\Event $event): string
    {
        $stripeSubscription = $event->data->object;
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubscription->id);

        if (!$subscription) {
            return 'not_found';
        }

        // Send trial ending email
        Headcount::getInstance()->emails->sendTrialEndingEmail($subscription);

        return 'processed';
    }

    // -- PayPal Handlers --

    private function _handlePayPalSubscriptionActivated(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['id'] ?? null;

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_ACTIVE);
            return 'processed';
        }

        return 'not_found';
    }

    private function _handlePayPalSubscriptionCancelled(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['id'] ?? null;

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_CANCELED);
            Headcount::getInstance()->emails->sendCancellationEmail($subscription);
            return 'processed';
        }

        return 'not_found';
    }

    private function _handlePayPalSubscriptionSuspended(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['id'] ?? null;

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_PAUSED);
            return 'processed';
        }

        return 'not_found';
    }

    private function _handlePayPalSubscriptionExpired(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['id'] ?? null;

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_EXPIRED);
            return 'processed';
        }

        return 'not_found';
    }

    private function _handlePayPalPaymentCompleted(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['billing_agreement_id'] ?? null;

        if (!$paypalSubId) {
            return 'no_subscription';
        }

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            $amount = (float)($resource['amount']['total'] ?? 0);
            Headcount::getInstance()->emails->sendPaymentReceiptEmail($subscription, $amount);
            return 'processed';
        }

        return 'not_found';
    }

    private function _handlePayPalPaymentDenied(array $data): string
    {
        $resource = $data['resource'] ?? [];
        $paypalSubId = $resource['billing_agreement_id'] ?? null;

        if (!$paypalSubId) {
            return 'no_subscription';
        }

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($paypalSubId);
        if ($subscription) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, Subscription::STATUS_PAST_DUE);
            Headcount::getInstance()->emails->sendPaymentFailedEmail($subscription);
            return 'processed';
        }

        return 'not_found';
    }

    public function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active' => Subscription::STATUS_ACTIVE,
            'trialing' => Subscription::STATUS_TRIALING,
            'past_due' => Subscription::STATUS_PAST_DUE,
            'canceled' => Subscription::STATUS_CANCELED,
            'unpaid' => Subscription::STATUS_PAST_DUE,
            'incomplete' => Subscription::STATUS_PAST_DUE,
            'incomplete_expired' => Subscription::STATUS_EXPIRED,
            'paused' => Subscription::STATUS_PAUSED,
            default => Subscription::STATUS_ACTIVE,
        };
    }
}
