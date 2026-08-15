<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\DateTimeHelper;
use DateTime;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\events\SubscriptionEvent;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use yii\base\Component;

class Subscriptions extends Component
{
    public const EVENT_BEFORE_CREATE_SUBSCRIPTION = 'beforeCreateSubscription';
    public const EVENT_AFTER_CREATE_SUBSCRIPTION = 'afterCreateSubscription';
    public const EVENT_BEFORE_UPDATE_SUBSCRIPTION = 'beforeUpdateSubscription';
    public const EVENT_AFTER_UPDATE_SUBSCRIPTION = 'afterUpdateSubscription';
    public const EVENT_BEFORE_CANCEL_SUBSCRIPTION = 'beforeCancelSubscription';
    public const EVENT_AFTER_CANCEL_SUBSCRIPTION = 'afterCancelSubscription';

    public function createSubscription(array $attributes): ?Subscription
    {
        $subscription = new Subscription();
        $subscription->userId = $attributes['userId'] ?? null;
        $subscription->planId = $attributes['planId'] ?? null;
        $subscription->gateway = $attributes['gateway'] ?? 'stripe';
        $subscription->gatewaySubscriptionId = $attributes['gatewaySubscriptionId'] ?? null;
        $subscription->gatewayCustomerId = $attributes['gatewayCustomerId'] ?? null;
        $subscription->status = $attributes['status'] ?? Subscription::STATUS_ACTIVE;
        $subscription->trialStartDate = $this->_parseDate($attributes['trialStartDate'] ?? null);
        $subscription->trialEndDate = $this->_parseDate($attributes['trialEndDate'] ?? null);
        $subscription->startDate = $this->_parseDate($attributes['startDate'] ?? null);
        $subscription->endDate = $this->_parseDate($attributes['endDate'] ?? null);
        $subscription->cancelAtPeriodEnd = $attributes['cancelAtPeriodEnd'] ?? false;
        $subscription->amount = $attributes['amount'] ?? 0;
        $subscription->currency = $attributes['currency'] ?? 'USD';
        $subscription->metadata = $attributes['metadata'] ?? null;

        // Fire before event
        $event = new SubscriptionEvent(['subscription' => $subscription, 'isNew' => true]);
        $this->trigger(self::EVENT_BEFORE_CREATE_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            return null;
        }

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            Craft::error('Could not save subscription: ' . implode(', ', $subscription->getFirstErrors()), 'headcount');
            return null;
        }

        // Sync user groups
        Headcount::getInstance()->members->syncUserGroups($subscription);

        // Fire after event
        $this->trigger(self::EVENT_AFTER_CREATE_SUBSCRIPTION, new SubscriptionEvent([
            'subscription' => $subscription,
            'isNew' => true,
        ]));

        $this->_dispatchWebhook('subscription.created', $subscription);

        return $subscription;
    }

    public function updateSubscriptionStatus(Subscription $subscription, string $newStatus): bool
    {
        $oldStatus = $subscription->status;
        $subscription->status = $newStatus;

        $event = new SubscriptionEvent([
            'subscription' => $subscription,
            'isNew' => false,
        ]);
        $this->trigger(self::EVENT_BEFORE_UPDATE_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            return false;
        }

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            return false;
        }

        // Sync user groups on status change
        Headcount::getInstance()->members->syncUserGroups($subscription);

        // A membership card already on a member's phone shows the old status until it is
        // told otherwise, so every status change nudges the wallets too.
        Headcount::getInstance()->wallet->queueUpdate($subscription);

        $this->trigger(self::EVENT_AFTER_UPDATE_SUBSCRIPTION, new SubscriptionEvent([
            'subscription' => $subscription,
            'isNew' => false,
        ]));

        // Emit the specific terminal event, or the generic update otherwise.
        if ($newStatus !== $oldStatus && $newStatus === Subscription::STATUS_CANCELED) {
            $this->_dispatchWebhook('subscription.canceled', $subscription);
        } elseif ($newStatus !== $oldStatus && $newStatus === Subscription::STATUS_EXPIRED) {
            $this->_dispatchWebhook('subscription.expired', $subscription);
        } else {
            $this->_dispatchWebhook('subscription.updated', $subscription);
        }

        return true;
    }

    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = true): bool
    {
        $event = new SubscriptionEvent(['subscription' => $subscription, 'isNew' => false]);
        $this->trigger(self::EVENT_BEFORE_CANCEL_SUBSCRIPTION, $event);

        if (!$event->isValid) {
            return false;
        }

        if ($atPeriodEnd) {
            $subscription->cancelAtPeriodEnd = true;
            $subscription->canceledAt = new DateTime();
        } else {
            $subscription->status = Subscription::STATUS_CANCELED;
            $subscription->canceledAt = new DateTime();
            $subscription->cancelAtPeriodEnd = false;
        }

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            return false;
        }

        // Sync user groups if immediately canceled
        if (!$atPeriodEnd) {
            Headcount::getInstance()->members->syncUserGroups($subscription);
            Headcount::getInstance()->wallet->queueUpdate($subscription);
        }

        $this->trigger(self::EVENT_AFTER_CANCEL_SUBSCRIPTION, new SubscriptionEvent([
            'subscription' => $subscription,
            'isNew' => false,
        ]));

        // Immediate cancellation is terminal; a period-end cancellation just
        // flags the subscription (the terminal event fires when it expires).
        $this->_dispatchWebhook($atPeriodEnd ? 'subscription.updated' : 'subscription.canceled', $subscription);

        return true;
    }

    /**
     * Move a subscription to a different plan, emitting member.upgraded or
     * member.downgraded based on the price delta.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): bool
    {
        $oldPlan = $subscription->getPlan();

        if ($oldPlan && $oldPlan->id === $newPlan->id) {
            return true;
        }

        $subscription->setPlan($newPlan);
        $subscription->amount = $newPlan->price;
        $subscription->currency = $newPlan->currency;

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            return false;
        }

        // The plan's mapped user group may differ; re-sync.
        Headcount::getInstance()->members->syncUserGroups($subscription);

        // The card names the plan, so it is now out of date.
        Headcount::getInstance()->wallet->queueUpdate($subscription);

        $this->_dispatchWebhook('subscription.updated', $subscription);

        if ($oldPlan) {
            if ($newPlan->price > $oldPlan->price) {
                $this->_dispatchWebhook('member.upgraded', $subscription);
            } elseif ($newPlan->price < $oldPlan->price) {
                $this->_dispatchWebhook('member.downgraded', $subscription);
            }
        }

        return true;
    }

    public function getSubscriptionById(int $id): ?Subscription
    {
        return Subscription::find()->id($id)->one();
    }

    public function getSubscriptionByGatewayId(string $gatewaySubscriptionId): ?Subscription
    {
        return Subscription::find()
            ->gatewaySubscriptionId($gatewaySubscriptionId)
            ->one();
    }

    public function getActiveSubscriptionsForUser(int $userId): array
    {
        return Subscription::find()
            ->userId($userId)
            ->active()
            ->all();
    }

    public function getUserSubscriptions(int $userId): array
    {
        return Subscription::find()
            ->userId($userId)
            ->orderBy('dateCreated DESC')
            ->all();
    }

    public function hasActiveSubscription(int $userId, ?string $planHandle = null): bool
    {
        $query = Subscription::find()
            ->userId($userId)
            ->active();

        if ($planHandle) {
            $query->planHandle($planHandle);
        }

        return $query->exists();
    }

    /**
     * Retire every subscription whose end date has passed.
     *
     * Two things end at an end date, and they aren't the same thing:
     *
     *  - a member who asked to cancel is **canceled** — they chose to leave;
     *  - a season membership, or any term that simply ran out, is **expired** — the term was
     *    always going to end on that date and nobody cancelled anything.
     *
     * A recurring subscription that nobody cancelled is left alone: its end date is the end
     * of a *billing period*, and the gateway will either renew it or tell us it failed. Only
     * a fixed term expires on its own, which is the whole point of a season.
     */
    public function processExpiredSubscriptions(): int
    {
        $count = 0;
        $subscriptions = Subscription::find()
            ->status([Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->endDate('<= ' . (new DateTime())->format('Y-m-d H:i:s'))
            ->all();

        foreach ($subscriptions as $subscription) {
            if ($subscription->cancelAtPeriodEnd) {
                $this->updateSubscriptionStatus($subscription, Subscription::STATUS_CANCELED);
                $count++;
                continue;
            }

            if ($subscription->getPlan()?->isFixedTerm()) {
                $this->updateSubscriptionStatus($subscription, Subscription::STATUS_EXPIRED);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Warn members whose membership runs out within the reminder window.
     *
     * Nothing used to call this — the setting and the editable message existed, the send
     * did not — so a site could configure a reminder that never went out. It matters more
     * now: a season membership doesn't renew itself, so this email is the only thing
     * standing between a member and silently losing access on 1 July.
     *
     * Each subscription is reminded once per term. The term it was reminded about is
     * recorded rather than a simple "reminded" flag, so a renewed membership with a new end
     * date gets its own reminder next year.
     */
    public function sendExpirationReminders(): int
    {
        $settings = Headcount::getInstance()->getSettings();

        if (!$settings->sendExpirationReminderEmail) {
            return 0;
        }

        $now = new DateTime();
        $threshold = (clone $now)->modify('+' . max(1, $settings->expirationReminderDays) . ' days');

        $subscriptions = Subscription::find()
            ->status([Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->endDate(['and', '>= ' . $now->format('Y-m-d H:i:s'), '<= ' . $threshold->format('Y-m-d H:i:s')])
            ->all();

        $sent = 0;

        foreach ($subscriptions as $subscription) {
            $term = $subscription->endDate?->format(\DateTimeInterface::ATOM);

            if ($term === null || ($subscription->metadata['expirationReminderSentFor'] ?? null) === $term) {
                continue;
            }

            Headcount::getInstance()->emails->sendExpirationReminderEmail($subscription);

            $subscription->metadata = array_merge($subscription->metadata ?? [], [
                'expirationReminderSentFor' => $term,
            ]);

            // Stamped only after the email is queued: a failed save means the member is
            // reminded twice, which is a great deal better than not at all.
            Craft::$app->getElements()->saveElement($subscription);

            $sent++;
        }

        return $sent;
    }

    private function _dispatchWebhook(string $event, Subscription $subscription): void
    {
        Headcount::getInstance()->webhooks->dispatchOutgoing($event, $this->_webhookData($subscription));
    }

    private function _webhookData(Subscription $subscription): array
    {
        $plan = $subscription->getPlan();

        return [
            'subscriptionId' => $subscription->id,
            'userId' => $subscription->userId,
            'planId' => $subscription->planId,
            'planHandle' => $plan?->handle,
            'status' => $subscription->status,
            'gateway' => $subscription->gateway,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
        ];
    }

    private function _parseDate(mixed $value): ?DateTime
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTime) {
            return $value;
        }

        if (is_numeric($value)) {
            return DateTimeHelper::toDateTime($value) ?: null;
        }

        if (is_string($value)) {
            return DateTimeHelper::toDateTime($value) ?: null;
        }

        return null;
    }
}
