<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class CheckoutController extends Controller
{
    protected array|int|bool $allowAnonymous = ['success', 'cancel'];

    public function actionCreateSession(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $request = Craft::$app->getRequest();
        $planHandle = $request->getRequiredBodyParam('planHandle');
        $couponCode = $request->getBodyParam('coupon');
        $gateway = $request->getBodyParam('gateway', 'stripe');

        $plan = Headcount::getInstance()->plans->getPlanByHandle($planHandle);
        if (!$plan || !$plan->enabled) {
            return $this->asFailure('Plan not found or not available.');
        }

        $userId = Craft::$app->getUser()->getId();

        if ($gateway === 'paypal' && Headcount::getInstance()->getSettings()->paypalEnabled) {
            $result = Headcount::getInstance()->paypal->createSubscription($plan, $userId);
            return $this->redirect($result['approvalUrl']);
        }

        // Default to Stripe
        $session = Headcount::getInstance()->stripe->createCheckoutSession($plan, $userId, $couponCode);

        return $this->redirect($session->url);
    }

    public function actionSuccess(): Response
    {
        $sessionId = Craft::$app->getRequest()->getQueryParam('session_id');

        return $this->renderTemplate('headcount/checkout/success', [
            'sessionId' => $sessionId,
        ]);
    }

    public function actionCancel(): Response
    {
        return $this->renderTemplate('headcount/checkout/cancel');
    }
}
