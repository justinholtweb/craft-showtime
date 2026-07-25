<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use yii\web\Response;

class PlansController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-managePlans');
        return true;
    }

    public function actionIndex(): Response
    {
        $plans = Headcount::getInstance()->plans->getAllPlans();

        return $this->renderTemplate('headcount/plans/index', [
            'plans' => $plans,
        ]);
    }

    public function actionEdit(?int $planId = null, ?Plan $plan = null): Response
    {
        if ($plan === null) {
            if ($planId !== null) {
                $plan = Headcount::getInstance()->plans->getPlanById($planId);
                if (!$plan) {
                    throw new \yii\web\NotFoundHttpException('Plan not found');
                }
            } else {
                $plan = new Plan();
            }
        }

        $isNew = !$plan->id;

        $userGroups = [];
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $userGroups[$group->id] = $group->name;
        }

        return $this->renderTemplate('headcount/plans/edit', [
            'plan' => $plan,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('headcount', 'New Plan') : $plan->name,
            'userGroups' => $userGroups,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $planId = $request->getBodyParam('planId');

        if ($planId) {
            $plan = Headcount::getInstance()->plans->getPlanById($planId);
            if (!$plan) {
                throw new \yii\web\NotFoundHttpException('Plan not found');
            }
        } else {
            $plan = new Plan();
        }

        $plan->name = $request->getBodyParam('name', $plan->name);
        $plan->handle = $request->getBodyParam('handle', $plan->handle);
        $plan->description = $request->getBodyParam('description', $plan->description);
        $plan->userGroupId = $request->getBodyParam('userGroupId') ?: null;
        $plan->stripePriceId = $request->getBodyParam('stripePriceId', $plan->stripePriceId);
        $plan->paypalPlanId = $request->getBodyParam('paypalPlanId', $plan->paypalPlanId);
        $plan->price = (float)$request->getBodyParam('price', $plan->price);
        $plan->currency = $request->getBodyParam('currency', $plan->currency);
        $plan->billingInterval = $request->getBodyParam('billingInterval', $plan->billingInterval);
        $plan->billingIntervalCount = (int)$request->getBodyParam('billingIntervalCount', $plan->billingIntervalCount);
        $plan->trialDays = (int)$request->getBodyParam('trialDays', $plan->trialDays);
        $plan->enabled = (bool)$request->getBodyParam('enabled', $plan->enabled);
        $plan->sortOrder = (int)$request->getBodyParam('sortOrder', $plan->sortOrder);

        $features = $request->getBodyParam('features');
        if (is_string($features)) {
            $features = array_filter(array_map('trim', explode("\n", $features)));
        }
        $plan->features = $features ?: null;

        if (!Headcount::getInstance()->plans->savePlan($plan)) {
            return $this->asFailure(
                Craft::t('headcount', 'Couldn\'t save plan.'),
                ['plan' => $plan]
            );
        }

        return $this->asSuccess(
            Craft::t('headcount', 'Plan saved.'),
            ['plan' => $plan],
            'headcount/plans/' . $plan->id
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $planId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        Headcount::getInstance()->plans->deletePlanById($planId);

        return $this->asSuccess(Craft::t('headcount', 'Plan deleted.'));
    }

    public function actionReorder(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $planIds = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Headcount::getInstance()->plans->reorderPlans($planIds);

        return $this->asSuccess();
    }
}
