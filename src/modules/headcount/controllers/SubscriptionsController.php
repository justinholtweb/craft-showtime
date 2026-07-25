<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SubscriptionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-manageSubscriptions');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('headcount/subscriptions/index');
    }

    public function actionEdit(int $elementId): Response
    {
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($elementId);
        if (!$subscription) {
            throw new NotFoundHttpException('Subscription not found');
        }

        $plans = Headcount::getInstance()->plans->getAllPlans();
        $planOptions = [];
        foreach ($plans as $plan) {
            $planOptions[$plan->id] = $plan->name;
        }

        return $this->renderTemplate('headcount/subscriptions/edit', [
            'subscription' => $subscription,
            'planOptions' => $planOptions,
            'statusOptions' => [
                Subscription::STATUS_ACTIVE => Craft::t('headcount', 'Active'),
                Subscription::STATUS_TRIALING => Craft::t('headcount', 'Trialing'),
                Subscription::STATUS_PAST_DUE => Craft::t('headcount', 'Past Due'),
                Subscription::STATUS_CANCELED => Craft::t('headcount', 'Canceled'),
                Subscription::STATUS_EXPIRED => Craft::t('headcount', 'Expired'),
                Subscription::STATUS_PAUSED => Craft::t('headcount', 'Paused'),
            ],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $subscriptionId = $request->getBodyParam('subscriptionId');

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($subscriptionId);
        if (!$subscription) {
            throw new NotFoundHttpException('Subscription not found');
        }

        $newStatus = $request->getBodyParam('status', $subscription->status);

        if ($newStatus !== $subscription->status) {
            Headcount::getInstance()->subscriptions->updateSubscriptionStatus($subscription, $newStatus);
        }

        $subscription->planId = $request->getBodyParam('planId') ?: $subscription->planId;
        $subscription->amount = (float)$request->getBodyParam('amount', $subscription->amount);

        if (!Craft::$app->getElements()->saveElement($subscription)) {
            return $this->asFailure('Could not save subscription.');
        }

        return $this->asSuccess(
            'Subscription saved.',
            [],
            'headcount/subscriptions/' . $subscription->id
        );
    }

    public function actionCancel(): ?Response
    {
        $this->requirePostRequest();

        $subscriptionId = Craft::$app->getRequest()->getRequiredBodyParam('subscriptionId');
        $atPeriodEnd = (bool)Craft::$app->getRequest()->getBodyParam('atPeriodEnd', true);

        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($subscriptionId);
        if (!$subscription) {
            throw new NotFoundHttpException('Subscription not found');
        }

        // Cancel in gateway if needed
        if ($subscription->gateway === 'stripe' && $subscription->gatewaySubscriptionId) {
            Headcount::getInstance()->stripe->cancelSubscription(
                $subscription->gatewaySubscriptionId,
                $atPeriodEnd
            );
        } elseif ($subscription->gateway === 'paypal' && $subscription->gatewaySubscriptionId) {
            Headcount::getInstance()->paypal->cancelSubscription($subscription->gatewaySubscriptionId);
        }

        Headcount::getInstance()->subscriptions->cancelSubscription($subscription, $atPeriodEnd);

        return $this->asSuccess('Subscription canceled.');
    }
}
