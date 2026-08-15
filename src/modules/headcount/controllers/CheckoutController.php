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

        // A one-off season that has finished has nothing left to sell. (A repeating season
        // never reports this — it has already rolled on to the next one.)
        if ($plan->hasSeasonEnded()) {
            return $this->asFailure(Craft::t('headcount', 'That season has ended.'));
        }

        $userId = Craft::$app->getUser()->getId();

        // PayPal is wired to its Subscriptions API, which bills on a cycle — it cannot
        // express "one payment, access until 30 June". Refusing is better than silently
        // signing a member up to a recurring plan they didn't buy.
        if ($gateway === 'paypal' && $plan->isFixedTerm()) {
            return $this->asFailure(Craft::t('headcount', 'Season memberships can\'t be paid for with PayPal. Please use card payment.'));
        }

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
