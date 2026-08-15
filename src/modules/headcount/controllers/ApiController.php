<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

class ApiController extends Controller
{
    /**
     * Anonymous access is what lets a request reach this controller's own `_validateAuth()`
     * at all — Craft refuses a session-less request before `beforeAction()` runs otherwise,
     * which made API-key auth unreachable. Everything past `plans`/`plan` is still gated,
     * just by the API key rather than by Craft's session check.
     */
    protected array|int|bool $allowAnonymous = ['plans', 'plan', 'subscriptions', 'subscription', 'member'];

    public $enableCsrfValidation = false;

    /**
     * Whether the current request authenticated with the API key rather than a session.
     */
    private bool $authenticatedByKey = false;

    public function beforeAction($action): bool
    {
        // CSRF is off controller-wide so key-authenticated, session-less callers can POST
        // without a token. `checkout` is the only action that changes state, and it is
        // session-only, so it keeps CSRF validation — matching CheckoutController, which
        // exposes the same operation to the front end.
        $this->enableCsrfValidation = $action->id === 'checkout';

        if (!parent::beforeAction($action)) {
            return false;
        }

        // Check API key for non-public endpoints
        $publicActions = ['plans', 'plan'];
        if (!in_array($action->id, $publicActions)) {
            if (!$this->_validateAuth()) {
                // Thrown rather than `return false`: Craft treats a null action result as
                // "no route matched" and falls through to a 404, swallowing the status code.
                throw new UnauthorizedHttpException('Invalid or missing API key.');
            }
        }

        return true;
    }

    /**
     * GET /actions/headcount/api/plans
     */
    public function actionPlans(): Response
    {
        $plans = Headcount::getInstance()->plans->getAllPlans(true);

        $data = array_map(fn($plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'handle' => $plan->handle,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billingInterval' => $plan->billingInterval,
            'billingIntervalCount' => $plan->billingIntervalCount,
            'termType' => $plan->termType,
            'seasonStart' => $plan->getTermStart()?->format(\DateTimeInterface::ATOM),
            'seasonEnd' => $plan->getTermEnd()?->format(\DateTimeInterface::ATOM),
            'currentPrice' => $plan->getProratedPrice(),
            'trialDays' => $plan->trialDays,
            'features' => $plan->features,
        ], $plans);

        return $this->asJson(['plans' => array_values($data)]);
    }

    /**
     * GET /actions/headcount/api/plan?handle=xxx
     */
    public function actionPlan(): Response
    {
        $handle = Craft::$app->getRequest()->getRequiredQueryParam('handle');
        $plan = Headcount::getInstance()->plans->getPlanByHandle($handle);

        if (!$plan) {
            return $this->asJson(['error' => 'Plan not found'])->setStatusCode(404);
        }

        return $this->asJson([
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'handle' => $plan->handle,
                'description' => $plan->description,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'billingInterval' => $plan->billingInterval,
                'billingIntervalCount' => $plan->billingIntervalCount,
                'trialDays' => $plan->trialDays,
                'features' => $plan->features,
            ],
        ]);
    }

    /**
     * GET /actions/headcount/api/subscriptions
     */
    public function actionSubscriptions(): Response
    {
        $member = $this->_resolveMember();
        if ($member instanceof Response) {
            return $member;
        }

        $subscriptions = Headcount::getInstance()->subscriptions->getUserSubscriptions($member->id);

        $data = array_map(fn(Subscription $sub) => $this->_serializeSubscription($sub), $subscriptions);

        return $this->asJson(['subscriptions' => $data]);
    }

    /**
     * GET /actions/headcount/api/subscription?id=xxx
     */
    public function actionSubscription(): Response
    {
        $member = $this->_resolveMember();
        if ($member instanceof Response) {
            return $member;
        }

        $id = Craft::$app->getRequest()->getRequiredQueryParam('id');
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($id);

        if (!$subscription) {
            return $this->asJson(['error' => 'Subscription not found'])->setStatusCode(404);
        }

        // Verify ownership. Previously this check was skipped entirely when there was no
        // session, which would have handed any subscription to a key-authenticated caller.
        if ($subscription->userId !== $member->id && !$member->admin) {
            return $this->asJson(['error' => 'Forbidden'])->setStatusCode(403);
        }

        return $this->asJson(['subscription' => $this->_serializeSubscription($subscription)]);
    }

    /**
     * POST /actions/headcount/api/checkout
     */
    public function actionCheckout(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $request = Craft::$app->getRequest();
        $planHandle = $request->getRequiredBodyParam('planHandle');
        $gateway = $request->getBodyParam('gateway', 'stripe');
        $coupon = $request->getBodyParam('coupon');

        $plan = Headcount::getInstance()->plans->getPlanByHandle($planHandle);
        if (!$plan || !$plan->enabled) {
            return $this->asJson(['error' => 'Plan not found'])->setStatusCode(404);
        }

        if ($plan->hasSeasonEnded()) {
            return $this->asJson(['error' => 'Season has ended'])->setStatusCode(410);
        }

        $userId = Craft::$app->getUser()->getId();

        if ($gateway === 'stripe') {
            $session = Headcount::getInstance()->stripe->createCheckoutSession($plan, $userId, $coupon);
            return $this->asJson(['checkoutUrl' => $session->url, 'sessionId' => $session->id]);
        }

        // See CheckoutController: PayPal's Subscriptions API can't express a fixed term.
        if ($gateway === 'paypal' && $plan->isFixedTerm()) {
            return $this->asJson(['error' => 'Season memberships are not available via PayPal'])->setStatusCode(400);
        }

        if ($gateway === 'paypal') {
            $result = Headcount::getInstance()->paypal->createSubscription($plan, $userId);
            return $this->asJson(['approvalUrl' => $result['approvalUrl']]);
        }

        return $this->asJson(['error' => 'Invalid gateway'])->setStatusCode(400);
    }

    /**
     * GET /actions/headcount/api/portal
     */
    public function actionPortal(): Response
    {
        $this->requireLogin();

        $userId = Craft::$app->getUser()->getId();
        $subscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($userId);

        $customerId = null;
        foreach ($subscriptions as $subscription) {
            if ($subscription->gateway === 'stripe' && $subscription->gatewayCustomerId) {
                $customerId = $subscription->gatewayCustomerId;
                break;
            }
        }

        if (!$customerId) {
            return $this->asJson(['error' => 'No Stripe subscription found'])->setStatusCode(404);
        }

        $returnUrl = Craft::$app->getRequest()->getQueryParam('returnUrl');
        $session = Headcount::getInstance()->stripe->createPortalSession($customerId, $returnUrl);

        return $this->asJson(['portalUrl' => $session->url]);
    }

    /**
     * GET /actions/headcount/api/member
     */
    public function actionMember(): Response
    {
        $member = $this->_resolveMember();
        if ($member instanceof Response) {
            return $member;
        }

        $subscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($member->id);

        return $this->asJson([
            'member' => [
                'id' => $member->id,
                'email' => $member->email,
                'firstName' => $member->firstName,
                'lastName' => $member->lastName,
                'subscriptions' => array_map(fn(Subscription $sub) => $this->_serializeSubscription($sub), $subscriptions),
            ],
        ]);
    }

    // -- Outgoing Webhooks --

    public static function fireOutgoingWebhook(string $event, array $data): void
    {
        Headcount::getInstance()->webhooks->dispatchOutgoing($event, $data);
    }

    private function _validateAuth(): bool
    {
        // Check session auth (logged-in user)
        if (Craft::$app->getUser()->getIdentity()) {
            return true;
        }

        // Check API key.
        //
        // Header only, deliberately: an API key passed in the query string leaks into web
        // server access logs, browser history, and the Referer header of any outbound link
        // on the response. hash_equals() keeps the comparison constant-time so the key
        // can't be recovered a byte at a time.
        $settings = Headcount::getInstance()->getSettings();
        if ($settings->apiKey) {
            $apiKey = Craft::$app->getRequest()->getHeaders()->get('X-Headcount-Api-Key');

            if (is_string($apiKey) && hash_equals($settings->apiKey, $apiKey)) {
                $this->authenticatedByKey = true;
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the member whose data the request may read.
     *
     * A session request always acts for the logged-in user, and deliberately ignores any
     * `userId`/`email` in the query string — honouring it would let any member read another
     * member's billing history. The API key is a single global server credential with no
     * member of its own, so a key-authenticated request has to name one.
     *
     * @return User|Response the member, or the error response to return instead
     */
    private function _resolveMember(): User|Response
    {
        if (!$this->authenticatedByKey) {
            $identity = Craft::$app->getUser()->getIdentity();
            if (!$identity) {
                // Unreachable — beforeAction() rejects a request with neither session nor key.
                return $this->asJson(['error' => 'Authentication required'])->setStatusCode(401);
            }

            return $identity;
        }

        $request = Craft::$app->getRequest();
        $userId = $request->getQueryParam('userId');
        $email = $request->getQueryParam('email');

        if ($userId === null && $email === null) {
            return $this->asJson([
                'error' => 'Key-authenticated requests must identify a member with userId or email',
            ])->setStatusCode(400);
        }

        $member = $userId !== null
            ? Craft::$app->getUsers()->getUserById((int)$userId)
            : User::find()->email($email)->status(null)->one();

        if (!$member) {
            return $this->asJson(['error' => 'Member not found'])->setStatusCode(404);
        }

        return $member;
    }

    private function _serializeSubscription(Subscription $subscription): array
    {
        $plan = $subscription->getPlan();

        return [
            'id' => $subscription->id,
            'planId' => $subscription->planId,
            'planHandle' => $plan?->handle,
            'planName' => $plan?->name,
            'gateway' => $subscription->gateway,
            'status' => $subscription->status,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'startDate' => $subscription->startDate?->format('c'),
            'endDate' => $subscription->endDate?->format('c'),
            'trialEndDate' => $subscription->trialEndDate?->format('c'),
            'cancelAtPeriodEnd' => $subscription->cancelAtPeriodEnd,
            'canceledAt' => $subscription->canceledAt?->format('c'),
            'dateCreated' => $subscription->dateCreated?->format('c'),
        ];
    }
}
