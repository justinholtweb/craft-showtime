<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\headcount\helpers\Json;
use justinholtweb\headcount\models\Plan;
use justinholtweb\headcount\records\PlanRecord;
use yii\base\Component;

class Plans extends Component
{
    private ?array $_plansById = null;
    private ?array $_plansByHandle = null;

    public function getAllPlans(bool $enabledOnly = false): array
    {
        $this->_loadPlans();

        $plans = $this->_plansById ?? [];

        if ($enabledOnly) {
            $plans = array_filter($plans, fn(Plan $plan) => $plan->enabled);
        }

        usort($plans, fn(Plan $a, Plan $b) => $a->sortOrder <=> $b->sortOrder);

        return $plans;
    }

    public function getPlanById(int $id): ?Plan
    {
        $this->_loadPlans();

        return $this->_plansById[$id] ?? null;
    }

    public function getPlanByHandle(string $handle): ?Plan
    {
        $this->_loadPlans();

        return $this->_plansByHandle[$handle] ?? null;
    }

    public function getPlanByStripePriceId(string $stripePriceId): ?Plan
    {
        foreach ($this->getAllPlans() as $plan) {
            if ($plan->stripePriceId === $stripePriceId) {
                return $plan;
            }
        }

        return null;
    }

    public function savePlan(Plan $plan): bool
    {
        if (!$plan->validate()) {
            return false;
        }

        $isNew = !$plan->id;

        if ($isNew) {
            $record = new PlanRecord();
        } else {
            $record = PlanRecord::findOne($plan->id);
            if (!$record) {
                return false;
            }
        }

        $record->name = $plan->name;
        $record->handle = $plan->handle;
        $record->description = $plan->description;
        $record->userGroupId = $plan->userGroupId;
        $record->stripePriceId = $plan->stripePriceId;
        $record->paypalPlanId = $plan->paypalPlanId;
        $record->price = $plan->price;
        $record->currency = $plan->currency;
        $record->billingInterval = $plan->billingInterval;
        $record->billingIntervalCount = $plan->billingIntervalCount;
        $record->termType = $plan->termType;
        $record->seasonStartDate = Db::prepareDateForDb($plan->seasonStartDate);
        $record->seasonEndDate = Db::prepareDateForDb($plan->seasonEndDate);
        $record->seasonRepeats = $plan->seasonRepeats;
        $record->prorate = $plan->prorate;
        $record->prorationBasis = $plan->prorationBasis;
        $record->trialDays = $plan->trialDays;
        $record->sortOrder = $plan->sortOrder;
        $record->enabled = $plan->enabled;
        $record->features = $plan->features ? json_encode($plan->features) : null;

        if (!$record->save()) {
            $plan->addErrors($record->getErrors());
            return false;
        }

        if ($isNew) {
            $plan->id = $record->id;
        }

        // Reset cache
        $this->_plansById = null;
        $this->_plansByHandle = null;

        return true;
    }

    public function deletePlanById(int $id): bool
    {
        $record = PlanRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->delete();

        // Reset cache
        $this->_plansById = null;
        $this->_plansByHandle = null;

        return true;
    }

    public function reorderPlans(array $planIds): bool
    {
        foreach ($planIds as $sortOrder => $planId) {
            Craft::$app->db->createCommand()
                ->update('{{%headcount_plans}}', [
                    'sortOrder' => $sortOrder,
                ], ['id' => $planId])
                ->execute();
        }

        $this->_plansById = null;
        $this->_plansByHandle = null;

        return true;
    }

    private function _loadPlans(): void
    {
        if ($this->_plansById !== null) {
            return;
        }

        $this->_plansById = [];
        $this->_plansByHandle = [];

        $rows = (new Query())
            ->select('*')
            ->from('{{%headcount_plans}}')
            ->orderBy('sortOrder')
            ->all();

        foreach ($rows as $row) {
            $plan = new Plan();
            $plan->id = (int)$row['id'];
            $plan->name = $row['name'];
            $plan->handle = $row['handle'];
            $plan->description = $row['description'];
            $plan->userGroupId = $row['userGroupId'] ? (int)$row['userGroupId'] : null;
            $plan->stripePriceId = $row['stripePriceId'];
            $plan->paypalPlanId = $row['paypalPlanId'];
            $plan->price = (float)$row['price'];
            $plan->currency = $row['currency'];
            $plan->billingInterval = $row['billingInterval'];
            $plan->billingIntervalCount = (int)$row['billingIntervalCount'];
            $plan->termType = $row['termType'] ?? Plan::TERM_RECURRING;
            $plan->seasonStartDate = DateTimeHelper::toDateTime($row['seasonStartDate'] ?? null) ?: null;
            $plan->seasonEndDate = DateTimeHelper::toDateTime($row['seasonEndDate'] ?? null) ?: null;
            $plan->seasonRepeats = (bool)($row['seasonRepeats'] ?? true);
            $plan->prorate = (bool)($row['prorate'] ?? false);
            $plan->prorationBasis = $row['prorationBasis'] ?? Plan::PRORATION_MONTH;
            $plan->trialDays = (int)$row['trialDays'];
            $plan->sortOrder = (int)$row['sortOrder'];
            $plan->enabled = (bool)$row['enabled'];
            $plan->features = Json::decodeColumn($row['features']);
            $plan->dateCreated = $row['dateCreated'];
            $plan->dateUpdated = $row['dateUpdated'];
            $plan->uid = $row['uid'];

            $this->_plansById[$plan->id] = $plan;
            $this->_plansByHandle[$plan->handle] = $plan;
        }
    }
}
