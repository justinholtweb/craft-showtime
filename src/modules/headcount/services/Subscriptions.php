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
            }
        }

        return $count;
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
