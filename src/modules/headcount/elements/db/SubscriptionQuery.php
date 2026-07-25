<?php

namespace justinholtweb\headcount\elements\db;

use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\helpers\Json;
use yii\db\Connection;

/**
 * @extends ElementQuery<array-key, Subscription>
 *
 * @method Subscription[] all(?Connection $db = null)
 * @method Subscription|null one(?Connection $db = null)
 * @method Subscription|null nth(int $n, ?Connection $db = null)
 */
class SubscriptionQuery extends ElementQuery
{
    public ?int $userId = null;
    public ?int $planId = null;
    public ?string $gateway = null;
    public ?string $gatewaySubscriptionId = null;
    public ?string $gatewayCustomerId = null;
    public array|string|null $status = null;
    public mixed $startDate = null;
    public mixed $endDate = null;
    public ?string $planHandle = null;

    public function userId(?int $value): self
    {
        $this->userId = $value;
        return $this;
    }

    public function planId(?int $value): self
    {
        $this->planId = $value;
        return $this;
    }

    public function planHandle(?string $value): self
    {
        $this->planHandle = $value;
        return $this;
    }

    public function gateway(?string $value): self
    {
        $this->gateway = $value;
        return $this;
    }

    public function gatewaySubscriptionId(?string $value): self
    {
        $this->gatewaySubscriptionId = $value;
        return $this;
    }

    public function gatewayCustomerId(?string $value): self
    {
        $this->gatewayCustomerId = $value;
        return $this;
    }

    public function status(array|string|null $value): static
    {
        $this->status = $value;
        return $this;
    }

    public function startDate(mixed $value): self
    {
        $this->startDate = $value;
        return $this;
    }

    public function endDate(mixed $value): self
    {
        $this->endDate = $value;
        return $this;
    }

    public function active(): self
    {
        $this->status = [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING];
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('headcount_subscriptions');

        $this->query->select([
            'headcount_subscriptions.userId',
            'headcount_subscriptions.planId',
            'headcount_subscriptions.gateway',
            'headcount_subscriptions.gatewaySubscriptionId',
            'headcount_subscriptions.gatewayCustomerId',
            'headcount_subscriptions.status',
            'headcount_subscriptions.trialStartDate',
            'headcount_subscriptions.trialEndDate',
            'headcount_subscriptions.startDate',
            'headcount_subscriptions.endDate',
            'headcount_subscriptions.canceledAt',
            'headcount_subscriptions.cancelAtPeriodEnd',
            'headcount_subscriptions.amount',
            'headcount_subscriptions.currency',
            'headcount_subscriptions.metadata',
        ]);

        if ($this->userId !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.userId' => $this->userId]);
        }

        if ($this->planId !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.planId' => $this->planId]);
        }

        if ($this->planHandle !== null) {
            $this->subQuery->innerJoin(
                '{{%headcount_plans}} headcount_plans',
                '[[headcount_plans.id]] = [[headcount_subscriptions.planId]]'
            );
            $this->subQuery->andWhere(['headcount_plans.handle' => $this->planHandle]);
        }

        if ($this->gateway !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.gateway' => $this->gateway]);
        }

        if ($this->gatewaySubscriptionId !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.gatewaySubscriptionId' => $this->gatewaySubscriptionId]);
        }

        if ($this->gatewayCustomerId !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.gatewayCustomerId' => $this->gatewayCustomerId]);
        }

        if ($this->status !== null) {
            $this->subQuery->andWhere(['headcount_subscriptions.status' => $this->status]);
        }

        if ($this->startDate !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('headcount_subscriptions.startDate', $this->startDate));
        }

        if ($this->endDate !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('headcount_subscriptions.endDate', $this->endDate));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return ['headcount_subscriptions.status' => $status];
    }

    public function createElement(array $row): ElementInterface
    {
        // The `metadata` column is read straight from the join, so it arrives as
        // the raw (double-encoded) JSON string. Decode it to an array before it
        // is assigned to the element's typed `?array $metadata` property.
        if (array_key_exists('metadata', $row)) {
            $row['metadata'] = Json::decodeColumn($row['metadata']);
        }

        return parent::createElement($row);
    }
}
