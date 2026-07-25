<?php

namespace justinholtweb\headcount\services;

use craft\db\Query;
use DateTime;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\base\Component;

class Reporting extends Component
{
    public function getActiveMemberCount(): int
    {
        return Subscription::find()->active()->count();
    }

    public function getActiveMemberCountByPlan(): array
    {
        $plans = Headcount::getInstance()->plans->getAllPlans();
        $counts = [];

        foreach ($plans as $plan) {
            $counts[$plan->handle] = [
                'plan' => $plan,
                'count' => Subscription::find()->planId($plan->id)->active()->count(),
            ];
        }

        return $counts;
    }

    public function getNewSubscriptionsOverTime(int $days = 30): array
    {
        $startDate = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        $results = (new Query())
            ->select([
                'DATE([[dateCreated]]) as date',
                'COUNT(*) as count',
            ])
            ->from('{{%headcount_subscriptions}}')
            ->where(['>=', 'dateCreated', $startDate])
            ->groupBy('DATE([[dateCreated]])')
            ->orderBy('date')
            ->all();

        // Fill in missing dates
        $data = [];
        $current = new DateTime("-{$days} days");
        $end = new DateTime();

        $resultMap = [];
        foreach ($results as $row) {
            $resultMap[$row['date']] = (int)$row['count'];
        }

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $data[] = [
                'date' => $dateStr,
                'count' => $resultMap[$dateStr] ?? 0,
            ];
            $current->modify('+1 day');
        }

        return $data;
    }

    public function getRevenueOverTime(int $days = 30): array
    {
        $startDate = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        $results = (new Query())
            ->select([
                'DATE([[dateCreated]]) as date',
                'SUM([[amount]]) as revenue',
            ])
            ->from('{{%headcount_subscriptions}}')
            ->where(['>=', 'dateCreated', $startDate])
            ->andWhere(['status' => [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING]])
            ->groupBy('DATE([[dateCreated]])')
            ->orderBy('date')
            ->all();

        $data = [];
        $current = new DateTime("-{$days} days");
        $end = new DateTime();

        $resultMap = [];
        foreach ($results as $row) {
            $resultMap[$row['date']] = (float)$row['revenue'];
        }

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $data[] = [
                'date' => $dateStr,
                'revenue' => $resultMap[$dateStr] ?? 0,
            ];
            $current->modify('+1 day');
        }

        return $data;
    }

    public function getChurnRate(int $days = 30): float
    {
        $startDate = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        $canceledCount = (int)(new Query())
            ->from('{{%headcount_subscriptions}}')
            ->where(['status' => Subscription::STATUS_CANCELED])
            ->andWhere(['>=', 'canceledAt', $startDate])
            ->count();

        $activeAtStart = (int)(new Query())
            ->from('{{%headcount_subscriptions}}')
            ->where(['in', 'status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_CANCELED]])
            ->andWhere(['<=', 'dateCreated', $startDate])
            ->count();

        if ($activeAtStart === 0) {
            return 0;
        }

        return round(($canceledCount / $activeAtStart) * 100, 2);
    }

    public function getTrialConversionRate(): float
    {
        $totalTrials = (int)(new Query())
            ->from('{{%headcount_subscriptions}}')
            ->where(['not', ['trialStartDate' => null]])
            ->count();

        if ($totalTrials === 0) {
            return 0;
        }

        $convertedTrials = (int)(new Query())
            ->from('{{%headcount_subscriptions}}')
            ->where(['not', ['trialStartDate' => null]])
            ->andWhere(['status' => Subscription::STATUS_ACTIVE])
            ->count();

        return round(($convertedTrials / $totalTrials) * 100, 2);
    }

    public function getMRR(): float
    {
        $subscriptions = Subscription::find()->active()->all();
        $mrr = 0;

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->getPlan();
            if (!$plan) {
                continue;
            }

            $monthlyAmount = match ($plan->billingInterval) {
                'day' => $subscription->amount * 30 / $plan->billingIntervalCount,
                'week' => $subscription->amount * 4.33 / $plan->billingIntervalCount,
                'month' => $subscription->amount / $plan->billingIntervalCount,
                'year' => $subscription->amount / (12 * $plan->billingIntervalCount),
                default => $subscription->amount,
            };

            $mrr += $monthlyAmount;
        }

        return round($mrr, 2);
    }

    public function getCouponUsageStats(): array
    {
        return (new Query())
            ->select(['code', 'description', 'currentUses', 'maxUses', 'discountType', 'discountAmount'])
            ->from('{{%headcount_coupons}}')
            ->where(['enabled' => true])
            ->orderBy('currentUses DESC')
            ->all();
    }

    public function getDashboardStats(): array
    {
        return [
            'activeMembers' => $this->getActiveMemberCount(),
            'mrr' => $this->getMRR(),
            'churnRate' => $this->getChurnRate(),
            'trialConversionRate' => $this->getTrialConversionRate(),
            'membersByPlan' => $this->getActiveMemberCountByPlan(),
        ];
    }
}
