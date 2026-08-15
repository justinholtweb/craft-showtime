<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\base\Component;
use yii\base\Exception;

/**
 * Issues and updates Google Wallet membership cards.
 *
 * Google's model is the opposite of Apple's. The card lives on Google's servers, not on the
 * phone: a *class* describes what every card of this kind looks like, an *object* is one
 * member's card, and the phone only holds a reference. So there is no signing ceremony and
 * no per-device bookkeeping — updating a member's card is a PATCH, and it reaches every
 * device they've added it to.
 *
 * The one signed artefact is the "Save to Google Wallet" link, which is a JWT the service
 * account signs to prove the club really is offering this card to this person.
 */
class GoogleWallet extends Component
{
    private const API_BASE = 'https://walletobjects.googleapis.com/walletobjects/v1/';
    private const SCOPE = 'https://www.googleapis.com/auth/wallet_object.issuer';
    private const SAVE_URL = 'https://pay.google.com/gp/v/save/';

    private ?Client $_client = null;
    private ?array $_serviceAccount = null;
    private ?string $_accessToken = null;

    /**
     * A link that adds this membership to the member's Google Wallet.
     *
     * The card object is created (or brought up to date) first, so that following the link
     * shows current information rather than whatever was true when the link was built.
     *
     * @throws Exception
     */
    public function getSaveUrl(Subscription $subscription): string
    {
        $this->ensureConfigured();
        $this->upsertObject($subscription);

        $claims = [
            'iss' => $this->serviceAccount()['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'payload' => [
                'genericObjects' => [
                    ['id' => $this->objectId($subscription)],
                ],
            ],
        ];

        return self::SAVE_URL . $this->signJwt($claims);
    }

    /**
     * Create the member's card, or bring an existing one back in step.
     *
     * @throws Exception
     */
    public function upsertObject(Subscription $subscription): void
    {
        $this->ensureConfigured();
        $this->ensureClass();

        $objectId = $this->objectId($subscription);
        $body = $this->buildObject($subscription);

        // PATCH first: after the first season, updating is the common case, and asking
        // Google to create something that exists is an error rather than a no-op.
        try {
            $this->request('PATCH', 'genericObject/' . rawurlencode($objectId), $body);
            return;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }

        $this->request('POST', 'genericObject', $body + ['id' => $objectId]);
    }

    /**
     * Push the current state of a membership to Google.
     *
     * Swallows failures deliberately: this runs from a queue job alongside Apple pushes,
     * and one platform being unreachable must not stop the other from being told.
     */
    public function syncObject(Subscription $subscription): bool
    {
        try {
            $this->upsertObject($subscription);
            return true;
        } catch (\Throwable $e) {
            Craft::error(
                "Could not update the Google Wallet card for subscription #{$subscription->id}: " . $e->getMessage(),
                'headcount',
            );

            return false;
        }
    }

    /**
     * The card template every member's card points at.
     *
     * Created once and left alone. Google rejects an object whose class doesn't exist, and
     * checking is a single cheap GET, so it's checked rather than assumed.
     *
     * @throws Exception
     */
    public function ensureClass(): void
    {
        $classId = $this->classId();

        try {
            $this->request('GET', 'genericClass/' . rawurlencode($classId));
            return;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }

        $this->request('POST', 'genericClass', [
            'id' => $classId,
            'enableSmartTap' => false,
        ]);
    }

    /**
     * One member's card.
     *
     * `state` is what actually matters to someone showing it: an expired or cancelled
     * membership renders greyed out and sorts to the bottom of the wallet, which is the
     * Google equivalent of Apple's voided pass.
     */
    private function buildObject(Subscription $subscription): array
    {
        $wallet = Headcount::getInstance()->wallet;
        $card = $wallet->getCardData($subscription);

        $rows = [
            [
                'header' => Craft::t('headcount', 'Membership'),
                'body' => $card['planName'],
                'id' => 'plan',
            ],
            [
                'header' => Craft::t('headcount', 'Status'),
                'body' => $card['statusLabel'],
                'id' => 'status',
            ],
        ];

        if ($card['expiryDate']) {
            $rows[] = [
                'header' => Craft::t('headcount', 'Valid until'),
                'body' => $card['expiryDate']->format('j M Y'),
                'id' => 'expires',
            ];
        }

        $object = [
            'classId' => $this->classId(),
            'state' => $card['valid'] ? 'ACTIVE' : 'EXPIRED',
            'cardTitle' => $this->localized($card['organizationName']),
            'header' => $this->localized($card['memberName']),
            'subheader' => $this->localized($card['planName']),
            'hexBackgroundColor' => $this->hexColor(Headcount::getInstance()->getSettings()->walletBackgroundColor),
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $card['barcodeMessage'],
                'alternateText' => $this->shortReference($card['serialNumber']),
            ],
            'textModulesData' => $rows,
            'linksModuleData' => [
                'uris' => [[
                    'uri' => UrlHelper::siteUrl(),
                    'description' => Craft::t('headcount', 'Website'),
                    'id' => 'website',
                ]],
            ],
        ];

        if ($card['expiryDate']) {
            $object['validTimeInterval'] = [
                'end' => ['date' => $card['expiryDate']->format(DateTimeInterface::ATOM)],
            ];
        }

        return $object;
    }

    private function classId(): string
    {
        return $this->issuerId() . '.headcount-membership';
    }

    private function objectId(Subscription $subscription): string
    {
        $serial = Headcount::getInstance()->wallet->getSerialNumber($subscription);

        // Google only allows alphanumerics, dots, underscores and hyphens in an object ID.
        return $this->issuerId() . '.' . preg_replace('/[^A-Za-z0-9._-]/', '', $serial);
    }

    private function issuerId(): string
    {
        return App::parseEnv(Headcount::getInstance()->getSettings()->googleWalletIssuerId);
    }

    /**
     * @throws Exception
     */
    private function ensureConfigured(): void
    {
        if (!Headcount::getInstance()->wallet->isGoogleConfigured()) {
            throw new Exception('Google Wallet is not configured.');
        }
    }

    /**
     * @throws Exception
     */
    private function serviceAccount(): array
    {
        if ($this->_serviceAccount !== null) {
            return $this->_serviceAccount;
        }

        $settings = Headcount::getInstance()->getSettings();
        $path = Headcount::getInstance()->wallet->resolvePath($settings->googleWalletServiceAccountPath);

        if ($path === null) {
            throw new Exception('The Google Wallet service account key file could not be found.');
        }

        $account = json_decode((string)file_get_contents($path), true);

        if (!is_array($account) || !isset($account['client_email'], $account['private_key'])) {
            throw new Exception('The Google Wallet service account key file is not a valid service account JSON key.');
        }

        return $this->_serviceAccount = $account;
    }

    /**
     * Exchange the service account key for an access token, the JWT-bearer way.
     *
     * @throws Exception
     */
    private function accessToken(): string
    {
        if ($this->_accessToken !== null) {
            return $this->_accessToken;
        }

        $account = $this->serviceAccount();
        $tokenUri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();

        $assertion = $this->signJwt([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $response = $this->client()->post($tokenUri, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);

        $data = json_decode((string)$response->getBody(), true);

        if (!isset($data['access_token'])) {
            throw new Exception('Google refused the Wallet service account credentials.');
        }

        return $this->_accessToken = $data['access_token'];
    }

    /**
     * @throws Exception
     */
    private function signJwt(array $claims): string
    {
        $account = $this->serviceAccount();

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        if (!openssl_sign($signingInput, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new Exception('Could not sign the Google Wallet request: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken(),
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->client()->request($method, self::API_BASE . $path, $options);

        return json_decode((string)$response->getBody(), true) ?: [];
    }

    private function client(): Client
    {
        return $this->_client ??= Craft::createGuzzleClient(['timeout' => 30]);
    }

    private function localized(string $value): array
    {
        return [
            'defaultValue' => [
                'language' => 'en',
                'value' => $value,
            ],
        ];
    }

    /**
     * Google wants `#rrggbb`; the shared setting is CSS `rgb(r,g,b)` because that is the
     * only form Apple accepts.
     */
    private function hexColor(string $color): string
    {
        if (preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            return $color;
        }

        if (preg_match('/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i', $color, $matches)) {
            return sprintf('#%02x%02x%02x', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        return '#1c1c1e';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function shortReference(string $serialNumber): string
    {
        return strtoupper(substr(str_replace('-', '', $serialNumber), 0, 8));
    }
}
