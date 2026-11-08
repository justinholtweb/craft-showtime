<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class CheckoutController extends Controller
{
    protected array|int|bool $allowAnonymous = ['success', 'cancel'];

    /**
     * How long after a checkout opened its return URL may still record it (Stripe expires an
     * unfinished session after 24 hours at most).
     */
    private const RETURN_WINDOW = 86400;

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

    /**
     * Stripe's return from a paid checkout.
     *
     * Records the subscription now rather than leaving it all to the webhook, so the member
     * has access the moment they land, and a webhook that is late, misconfigured or blocked
     * (basic auth on staging is the usual culprit) doesn't lose the signup. The webhook still
     * arrives afterwards and finds it already done. Then it forwards to the site's own
     * success page, `session_id` and all.
     */
    public function actionSuccess(): Response
    {
        $sessionId = (string)Craft::$app->getRequest()->getQueryParam('session_id', '');
        $headcount = Headcount::getInstance();

        // Checked before any API call: this action is public, and only a real session id is
        // worth asking Stripe about.
        if (preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId)) {
            try {
                $session = $headcount->stripe->getCheckoutSession($sessionId);

                // Only the member who paid, and only on the way back from paying. The URL stays
                // in their history; it is not a standing way to re-create a membership. Anything
                // else is left to the webhook.
                $ownerId = (int)($session->metadata->craft_user_id ?? $session->client_reference_id ?? 0);
                $isFresh = (int)($session->created ?? 0) > time() - self::RETURN_WINDOW;
                $result = $ownerId !== 0 && $ownerId === (int)Craft::$app->getUser()->getId() && $isFresh
                    ? $headcount->subscriptions->createFromStripeCheckoutSession($session)
                    : 'not_owner';

                if ($result === 'processed') {
                    $headcount->webhooks->recordCheckoutFallback();
                } elseif ($headcount->webhooks->isFailure($result)) {
                    Craft::warning("Checkout session {$sessionId} couldn't be recorded on return: {$result}", 'headcount');
                }
            } catch (\Throwable $e) {
                // The webhook can still do it; the member shouldn't see an error for this.
                Craft::warning("Checkout session {$sessionId} couldn't be recorded on return: {$e->getMessage()}", 'headcount');
            }
        }

        $url = UrlHelper::siteUrl(ltrim($headcount->getSettings()->checkoutSuccessUrl, '/'));

        if ($sessionId !== '') {
            $url = UrlHelper::urlWithParams($url, ['session_id' => $sessionId]);
        }

        return $this->redirect($url);
    }

    public function actionCancel(): Response
    {
        return $this->renderTemplate('headcount/checkout/cancel');
    }
}
