<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class PortalController extends Controller
{
    protected array|int|bool $allowAnonymous = [];

    public function actionRedirect(): Response
    {
        $this->requireLogin();

        $userId = Craft::$app->getUser()->getId();
        $subscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($userId);

        // Find a Stripe subscription with a customer ID
        $customerId = null;
        foreach ($subscriptions as $subscription) {
            if ($subscription->gateway === 'stripe' && $subscription->gatewayCustomerId) {
                $customerId = $subscription->gatewayCustomerId;
                break;
            }
        }

        if (!$customerId) {
            Craft::$app->getSession()->setError('No active Stripe subscription found.');
            return $this->redirect(Craft::$app->getRequest()->getReferrer() ?? '/');
        }

        $returnUrl = Craft::$app->getRequest()->getQueryParam('returnUrl') ?: null;
        $session = Headcount::getInstance()->stripe->createPortalSession($customerId, $returnUrl);

        return $this->redirect($session->url);
    }
}
