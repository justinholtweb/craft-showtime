<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\DateTimeHelper;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\jobs\SendOutgoingWebhook;
use justinholtweb\headcount\records\WebhookLogRecord;
use yii\base\Component;

class Webhooks extends Component
{
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
                'customer.subscription.created' => $this->_handleSubscriptionCreated($event),
                'customer.subscription.updated' => $this->_handleSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->_handleSubscriptionDeleted($event),
                'invoice.paid' => $this->_handleInvoicePaid($event),
                'invoice.payment_failed' => $this->_handleInvoicePaymentFailed($event),
                'invoice.payment_action_required' => $this->_handlePaymentActionRequired($event),
                'customer.subscription.trial_will_end' => $this->_handleTrialWillEnd($event),
                default => 'ignored',
            };

            $log->status = $result === 'ignored' ? 'ignored' : 'processed';
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

            $log->status = $result === 'ignored' ? 'ignored' : 'processed';
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
        $session = $event->data->object;
        $userId = (int)($session->metadata->craft_user_id ?? $session->client_reference_id ?? 0);
        $planId = (int)($session->metadata->plan_id ?? 0);

        if (!$userId || !$planId) {
            return 'missing_metadata';
        }

        // Check if subscription already exists
        $stripeSubId = $session->subscription ?? null;
        if ($stripeSubId) {
            $existing = Headcount::getInstance()->subscriptions->getSubscriptionByGatewayId($stripeSubId);
            if ($existing) {
                return 'already_exists';
            }
        }

        $plan = Headcount::getInstance()->plans->getPlanById($planId);
        if (!$plan) {
            return 'plan_not_found';
        }

        // Get full subscription details from Stripe
        $stripeSubscription = null;
        if ($stripeSubId) {
            $stripeSubscription = Headcount::getInstance()->stripe->getSubscription($stripeSubId);
        }

        $subscription = Headcount::getInstance()->subscriptions->createSubscription([
            'userId' => $userId,
            'planId' => $planId,
            'gateway' => 'stripe',
            'gatewaySubscriptionId' => $stripeSubId,
            'gatewayCustomerId' => $session->customer ?? null,
            'status' => $this->_mapStripeStatus($stripeSubscription?->status ?? 'active'),
            'startDate' => $stripeSubscription ? $stripeSubscription->current_period_start : time(),
            'endDate' => $stripeSubscription ? $stripeSubscription->current_period_end : null,
            'trialStartDate' => $stripeSubscription?->trial_start,
            'trialEndDate' => $stripeSubscription?->trial_end,
            'amount' => $plan->price,
            'currency' => $plan->currency,
        ]);

        if ($subscription) {
            // Send welcome email
            Headcount::getInstance()->emails->sendWelcomeEmail($subscription);
        }

        return $subscription ? 'processed' : 'failed';
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

        $newStatus = $this->_mapStripeStatus($stripeSubscription->status);
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

    private function _mapStripeStatus(string $stripeStatus): string
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
