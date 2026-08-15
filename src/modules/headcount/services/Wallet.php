<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeInterface;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\jobs\PushWalletUpdate;
use justinholtweb\headcount\records\WalletRegistrationRecord;
use yii\base\Component;

/**
 * What a membership card says, and who is allowed to ask.
 *
 * Apple and Google draw cards from the same facts — who the member is, which plan, when it
 * runs out — so those facts are decided here once and the two platform services only
 * translate them into their own shapes. Identity and authentication live here too, for the
 * same reason: a serial number means the same thing to both, and to the scan endpoint.
 *
 * Nothing here needs a database column. A subscription's UID is already a stable, unguessable
 * public name for it, and every token is derived from that UID with the site's security key,
 * so tokens can be re-derived on demand and are invalidated wholesale if the key is rotated.
 */
class Wallet extends Component
{
    /**
     * Separate purposes get separate tokens.
     *
     * The pass authentication token is handed to Apple's web service, which sends it back on
     * every device request; the barcode token is displayed on a member's phone screen and
     * shown to strangers at a shop counter. Deriving both from one secret with different
     * purpose strings means capturing one — photographing a card, say — never yields the
     * other.
     */
    private const PURPOSE_PASS = 'headcount:pass-auth';
    private const PURPOSE_BARCODE = 'headcount:pass-barcode';

    public function isEnabled(): bool
    {
        return Headcount::getInstance()->getSettings()->walletEnabled;
    }

    public function isAppleConfigured(): bool
    {
        $settings = Headcount::getInstance()->getSettings();

        return $this->isEnabled()
            && $settings->appleWalletEnabled
            && App::parseEnv($settings->applePassTypeIdentifier)
            && App::parseEnv($settings->appleTeamIdentifier)
            && $this->resolvePath($settings->appleCertificatePath) !== null
            && $this->resolvePath($settings->appleWwdrCertificatePath) !== null;
    }

    public function isGoogleConfigured(): bool
    {
        $settings = Headcount::getInstance()->getSettings();

        return $this->isEnabled()
            && $settings->googleWalletEnabled
            && App::parseEnv($settings->googleWalletIssuerId)
            && $this->resolvePath($settings->googleWalletServiceAccountPath) !== null;
    }

    /**
     * Whether Apple should be told where to fetch updates.
     *
     * A pass carrying a web service URL that isn't served would have devices retrying
     * against a 404 forever, so the URL is only embedded when the service is switched on.
     */
    public function passUpdatesEnabled(): bool
    {
        return Headcount::getInstance()->getSettings()->applePassUpdatesEnabled;
    }

    /**
     * A setting holding a filesystem path, resolved and confirmed to exist — or null.
     *
     * Returning null rather than throwing lets the `isConfigured` checks answer "not set up"
     * without the caller distinguishing between a blank setting and a mistyped path; both
     * mean the same thing to a member trying to add a card.
     */
    public function resolvePath(string $setting): ?string
    {
        $path = App::parseEnv($setting);

        if (!$path) {
            return null;
        }

        $path = Craft::getAlias($path, false) ?: $path;

        return file_exists($path) ? $path : null;
    }

    /**
     * The card's public name for a subscription.
     *
     * The element UID: stable across everything except deletion, globally unique, and —
     * unlike the sequential element ID — it gives nothing away about how many members the
     * club has or who joined before whom.
     */
    public function getSerialNumber(Subscription $subscription): string
    {
        return (string)$subscription->uid;
    }

    public function getSubscriptionBySerial(string $serialNumber): ?Subscription
    {
        return Subscription::find()
            ->uid($serialNumber)
            ->status(null)
            ->one();
    }

    public function getAuthenticationToken(Subscription $subscription): string
    {
        return $this->token(self::PURPOSE_PASS, $this->getSerialNumber($subscription));
    }

    /**
     * Constant-time comparison, so a caller can't learn a token a character at a time.
     */
    public function validateAuthenticationToken(Subscription $subscription, string $token): bool
    {
        return hash_equals($this->getAuthenticationToken($subscription), $token);
    }

    public function getBarcodeToken(Subscription $subscription): string
    {
        return $this->token(self::PURPOSE_BARCODE, $this->getSerialNumber($subscription));
    }

    public function validateBarcodeToken(Subscription $subscription, string $token): bool
    {
        return hash_equals($this->getBarcodeToken($subscription), $token);
    }

    /**
     * What the barcode encodes: a URL to this site's verification page.
     *
     * Deliberately a URL and not an opaque identifier. The people scanning it are shop staff
     * offering a members' discount, with no app and no reader — a plain camera opens a page
     * that says valid or not valid, and no reader software has to exist for the card to be
     * useful.
     */
    public function getBarcodeMessage(Subscription $subscription): string
    {
        return UrlHelper::siteUrl('headcount/wallet/verify', [
            'serial' => $this->getSerialNumber($subscription),
            'token' => $this->getBarcodeToken($subscription),
        ]);
    }

    /**
     * Whether the card should currently be honoured.
     *
     * Trialing counts: a trial member is a member. Anything past its end date does not,
     * even if a sweep hasn't yet moved it to `expired` — the card must not outlive the
     * membership just because cron is late.
     */
    public function isValid(Subscription $subscription, ?DateTimeInterface $at = null): bool
    {
        $at ??= new DateTime();

        if (!in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING], true)) {
            return false;
        }

        return $subscription->endDate === null || $subscription->endDate >= $at;
    }

    /**
     * The facts a card displays, in platform-neutral form.
     *
     * @return array{
     *     serialNumber: string,
     *     memberName: string,
     *     memberSince: ?DateTime,
     *     planName: string,
     *     organizationName: string,
     *     expiryDate: ?DateTime,
     *     statusLabel: string,
     *     valid: bool,
     *     barcodeMessage: string,
     * }
     */
    public function getCardData(Subscription $subscription): array
    {
        $settings = Headcount::getInstance()->getSettings();
        $user = $subscription->getUser();
        $plan = $subscription->getPlan();

        $name = $user
            ? trim(($user->firstName ?? '') . ' ' . ($user->lastName ?? ''))
            : '';

        if ($name === '' && $user) {
            $name = $user->username ?: $user->email;
        }

        return [
            'serialNumber' => $this->getSerialNumber($subscription),
            'memberName' => $name ?: Craft::t('headcount', 'Member'),
            'memberSince' => $subscription->startDate,
            'planName' => $plan?->name ?? Craft::t('headcount', 'Membership'),
            'organizationName' => App::parseEnv($settings->walletOrganizationName)
                ?: Craft::$app->getSystemName(),
            'expiryDate' => $subscription->endDate,
            'statusLabel' => $this->statusLabel($subscription),
            'valid' => $this->isValid($subscription),
            'barcodeMessage' => $this->getBarcodeMessage($subscription),
        ];
    }

    /**
     * Register a device to be told about a pass. Returns false if it was already registered.
     *
     * Apple distinguishes the two: a fresh registration is a 201, a repeat is a 200, and
     * devices use the difference to decide whether to fetch the pass again.
     */
    public function registerDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
        string $pushToken,
        ?int $subscriptionId,
    ): bool {
        $record = WalletRegistrationRecord::findOne([
            'deviceLibraryIdentifier' => $deviceLibraryIdentifier,
            'passTypeIdentifier' => $passTypeIdentifier,
            'serialNumber' => $serialNumber,
        ]);

        if ($record) {
            // The push token rotates; the registration it belongs to doesn't.
            if ($record->pushToken !== $pushToken) {
                $record->pushToken = $pushToken;
                $record->save();
            }

            return false;
        }

        $record = new WalletRegistrationRecord();
        $record->deviceLibraryIdentifier = $deviceLibraryIdentifier;
        $record->passTypeIdentifier = $passTypeIdentifier;
        $record->serialNumber = $serialNumber;
        $record->pushToken = $pushToken;
        $record->subscriptionId = $subscriptionId;
        $record->save();

        return true;
    }

    public function unregisterDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
    ): bool {
        $record = WalletRegistrationRecord::findOne([
            'deviceLibraryIdentifier' => $deviceLibraryIdentifier,
            'passTypeIdentifier' => $passTypeIdentifier,
            'serialNumber' => $serialNumber,
        ]);

        if (!$record) {
            return false;
        }

        $record->delete();

        return true;
    }

    /**
     * @return WalletRegistrationRecord[]
     */
    public function getRegistrationsForSubscription(Subscription $subscription): array
    {
        return WalletRegistrationRecord::findAll([
            'serialNumber' => $this->getSerialNumber($subscription),
        ]);
    }

    /**
     * Push every card for this membership, on both platforms, out of band.
     *
     * Called whenever a subscription changes in a way a card would show. Queued rather than
     * inline because it talks to Apple's push service and Google's API, and a payment
     * webhook that has to wait on either is a payment webhook that times out.
     */
    public function queueUpdate(Subscription $subscription): void
    {
        if (!$this->isEnabled() || !$subscription->id) {
            return;
        }

        if (!$this->isAppleConfigured() && !$this->isGoogleConfigured()) {
            return;
        }

        Craft::$app->getQueue()->push(new PushWalletUpdate([
            'subscriptionId' => $subscription->id,
        ]));
    }

    private function statusLabel(Subscription $subscription): string
    {
        if (!$this->isValid($subscription)) {
            return Craft::t('headcount', 'Expired');
        }

        return match ($subscription->status) {
            Subscription::STATUS_TRIALING => Craft::t('headcount', 'Trial'),
            default => Craft::t('headcount', 'Active'),
        };
    }

    private function token(string $purpose, string $serialNumber): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;

        return hash_hmac('sha256', $purpose . '|' . $serialNumber, $key);
    }
}
