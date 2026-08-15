<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use DateTime;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\WalletRegistrationRecord;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Wallet cards: issuing them to members, and serving Apple's pass web service.
 *
 * Two audiences with nothing in common share this controller because they share a subject.
 * The member-facing actions run inside a Craft session and answer to the person who owns the
 * membership. The `v1/*` actions are Apple's specified web service: they are called by iOS
 * itself, with no session, no CSRF token, and no cookies — authenticated instead by the
 * per-pass token that was baked into the pass when it was issued.
 *
 * @see https://developer.apple.com/documentation/walletpasses
 */
class WalletController extends Controller
{
    /**
     * The device endpoints have no session to authenticate with — iOS presents the pass's
     * own authentication token in an `Authorization` header instead, which each action
     * checks for itself. `verify` is anonymous because its whole purpose is being scanned by
     * a stranger's phone camera at a shop counter.
     */
    protected array|int|bool $allowAnonymous = [
        'register-device',
        'unregister-device',
        'list-registrations',
        'latest-pass',
        'log',
        'verify',
    ];

    public function beforeAction($action): bool
    {
        // iOS is not a browser and has no CSRF token to send. The device endpoints are
        // protected by the pass authentication token, which is per-pass and unguessable;
        // the member-facing actions keep Craft's normal protection.
        $this->enableCsrfValidation = !in_array($action->id, [
            'register-device',
            'unregister-device',
            'log',
        ], true);

        return parent::beforeAction($action);
    }

    // ---------------------------------------------------------------------
    // Members
    // ---------------------------------------------------------------------

    /**
     * Download a signed `.pkpass` for one of your own memberships.
     */
    public function actionApple(int $subscriptionId): Response
    {
        $this->requireLogin();

        $subscription = $this->ownedSubscription($subscriptionId);
        $wallet = Headcount::getInstance()->wallet;

        if (!$wallet->isAppleConfigured()) {
            throw new NotFoundHttpException('Apple Wallet is not available.');
        }

        $pass = Headcount::getInstance()->applePass->build($subscription);

        return $this->response->sendContentAsFile($pass, 'membership.pkpass', [
            'mimeType' => 'application/vnd.apple.pkpass',
            'inline' => true,
        ]);
    }

    /**
     * Hand the member off to Google to add the card.
     */
    public function actionGoogle(int $subscriptionId): Response
    {
        $this->requireLogin();

        $subscription = $this->ownedSubscription($subscriptionId);
        $wallet = Headcount::getInstance()->wallet;

        if (!$wallet->isGoogleConfigured()) {
            throw new NotFoundHttpException('Google Wallet is not available.');
        }

        return $this->redirect(Headcount::getInstance()->googleWallet->getSaveUrl($subscription));
    }

    /**
     * The page a scanned membership card leads to.
     *
     * Written for whoever is holding the scanner — a shop assistant deciding whether to
     * apply a members' discount — so it answers the only question they have, in one word,
     * before any of the detail.
     *
     * A wrong or missing token renders the same "not valid" answer as an expired membership
     * rather than an error: someone probing serial numbers learns nothing from the
     * difference, and staff shouldn't have to interpret a stack trace.
     */
    public function actionVerify(): Response
    {
        $request = Craft::$app->getRequest();
        $serial = (string)$request->getQueryParam('serial', '');
        $token = (string)$request->getQueryParam('token', '');

        $wallet = Headcount::getInstance()->wallet;
        $subscription = $serial !== '' ? $wallet->getSubscriptionBySerial($serial) : null;

        $authentic = $subscription !== null && $wallet->validateBarcodeToken($subscription, $token);
        $valid = $authentic && $wallet->isValid($subscription);

        $card = $authentic ? $wallet->getCardData($subscription) : null;

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'valid' => $valid,
                'memberName' => $card['memberName'] ?? null,
                'planName' => $card['planName'] ?? null,
                'expiryDate' => $card['expiryDate']?->format(\DateTimeInterface::ATOM),
            ]);
        }

        $view = Craft::$app->getView();
        $variables = ['valid' => $valid, 'card' => $card];

        // A club that wants the check page in its own colours drops a `headcount/wallet/verify`
        // template into its own templates directory; otherwise the plugin's plain one is used.
        if ($view->doesTemplateExist('headcount/wallet/verify', View::TEMPLATE_MODE_SITE)) {
            return $this->renderTemplate('headcount/wallet/verify', $variables, View::TEMPLATE_MODE_SITE);
        }

        return $this->renderTemplate('headcount/wallet/verify', $variables, View::TEMPLATE_MODE_CP);
    }

    // ---------------------------------------------------------------------
    // Apple's pass web service
    // ---------------------------------------------------------------------

    /**
     * `POST v1/devices/{device}/registrations/{passType}/{serial}`
     *
     * 201 the first time a device adds this pass, 200 if it already had it — iOS uses the
     * distinction to decide whether it needs to fetch the pass again.
     */
    public function actionRegisterDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
    ): Response {
        $subscription = $this->authenticatedPass($serialNumber);

        $body = json_decode((string)Craft::$app->getRequest()->getRawBody(), true);
        $pushToken = $body['pushToken'] ?? null;

        if (!is_string($pushToken) || $pushToken === '') {
            return $this->statusOnly(400);
        }

        $created = Headcount::getInstance()->wallet->registerDevice(
            $deviceLibraryIdentifier,
            $passTypeIdentifier,
            $serialNumber,
            $pushToken,
            $subscription->id,
        );

        return $this->statusOnly($created ? 201 : 200);
    }

    /**
     * `DELETE v1/devices/{device}/registrations/{passType}/{serial}`
     */
    public function actionUnregisterDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
    ): Response {
        $this->authenticatedPass($serialNumber);

        Headcount::getInstance()->wallet->unregisterDevice(
            $deviceLibraryIdentifier,
            $passTypeIdentifier,
            $serialNumber,
        );

        return $this->statusOnly(200);
    }

    /**
     * `GET v1/devices/{device}/registrations/{passType}?passesUpdatedSince=`
     *
     * Which of this device's passes have changed. Unauthenticated by design — Apple's spec
     * has no token on this call, and it discloses only opaque serial numbers to a caller who
     * already knows the device identifier.
     *
     * 204 when nothing has changed; iOS treats a 200 with an empty list as an error.
     */
    public function actionListRegistrations(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
    ): Response {
        $since = (int)Craft::$app->getRequest()->getQueryParam('passesUpdatedSince', 0);

        /** @var WalletRegistrationRecord[] $registrations */
        $registrations = WalletRegistrationRecord::find()
            ->where([
                'deviceLibraryIdentifier' => $deviceLibraryIdentifier,
                'passTypeIdentifier' => $passTypeIdentifier,
            ])
            ->all();

        $wallet = Headcount::getInstance()->wallet;
        $serialNumbers = [];
        $lastUpdated = $since;

        foreach ($registrations as $registration) {
            $subscription = $wallet->getSubscriptionBySerial($registration->serialNumber);

            if (!$subscription) {
                continue;
            }

            $updated = $subscription->dateUpdated?->getTimestamp() ?? 0;

            if ($updated > $since) {
                $serialNumbers[] = $registration->serialNumber;
                $lastUpdated = max($lastUpdated, $updated);
            }
        }

        if (!$serialNumbers) {
            return $this->statusOnly(204);
        }

        return $this->asJson([
            'lastUpdated' => (string)$lastUpdated,
            'serialNumbers' => $serialNumbers,
        ]);
    }

    /**
     * `GET v1/passes/{passType}/{serial}`
     *
     * The current pass. Answers 304 to a device that already has this version, so a push
     * that turns out to change nothing costs one conditional request rather than a rebuilt
     * and re-signed pass.
     */
    public function actionLatestPass(string $passTypeIdentifier, string $serialNumber): Response
    {
        $subscription = $this->authenticatedPass($serialNumber);

        $modified = $subscription->dateUpdated ?? new DateTime();
        $ifModifiedSince = Craft::$app->getRequest()->getHeaders()->get('if-modified-since');

        if ($ifModifiedSince && @strtotime($ifModifiedSince) >= $modified->getTimestamp()) {
            return $this->statusOnly(304);
        }

        $pass = Headcount::getInstance()->applePass->build($subscription);

        $this->response->getHeaders()->set('Last-Modified', gmdate('D, d M Y H:i:s', $modified->getTimestamp()) . ' GMT');

        return $this->response->sendContentAsFile($pass, 'membership.pkpass', [
            'mimeType' => 'application/vnd.apple.pkpass',
            'inline' => true,
        ]);
    }

    /**
     * `POST v1/log`
     *
     * iOS reports pass problems here — the only visibility there is into why a device
     * rejected a pass, since the device itself shows the member nothing useful.
     */
    public function actionLog(): Response
    {
        $body = json_decode((string)Craft::$app->getRequest()->getRawBody(), true);

        foreach ($body['logs'] ?? [] as $line) {
            Craft::warning('Apple Wallet: ' . (is_string($line) ? $line : json_encode($line)), 'headcount');
        }

        return $this->statusOnly(200);
    }

    // ---------------------------------------------------------------------

    /**
     * The subscription a device request is about, or a 401.
     *
     * Apple sends `Authorization: ApplePass <token>`, where the token is the one embedded in
     * the pass at issue time. An unknown serial is answered with 401 rather than 404 so that
     * a caller can't use the endpoint to enumerate which memberships exist.
     */
    private function authenticatedPass(string $serialNumber): Subscription
    {
        $wallet = Headcount::getInstance()->wallet;
        $header = (string)Craft::$app->getRequest()->getHeaders()->get('authorization', '');
        $token = preg_match('/^ApplePass\s+(.+)$/i', trim($header), $matches) ? $matches[1] : '';

        $subscription = $wallet->getSubscriptionBySerial($serialNumber);

        if (!$subscription || $token === '' || !$wallet->validateAuthenticationToken($subscription, $token)) {
            throw new ForbiddenHttpException('Invalid pass authentication token.');
        }

        return $subscription;
    }

    /**
     * The member's own subscription, or a 403.
     */
    private function ownedSubscription(int $subscriptionId): Subscription
    {
        $subscription = Headcount::getInstance()->subscriptions->getSubscriptionById($subscriptionId);

        if (!$subscription) {
            throw new NotFoundHttpException('Subscription not found.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user || ($subscription->userId !== $user->id && !$user->can('headcount-manageSubscriptions'))) {
            throw new ForbiddenHttpException('That membership isn’t yours.');
        }

        return $subscription;
    }

    /**
     * A bare status code. Apple's spec expects empty bodies on these, and Craft would
     * otherwise try to render a template for them.
     */
    private function statusOnly(int $statusCode): Response
    {
        $this->response->format = Response::FORMAT_RAW;
        $this->response->setStatusCode($statusCode);
        $this->response->content = '';

        return $this->response;
    }
}
