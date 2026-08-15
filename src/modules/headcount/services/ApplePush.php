<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\records\WalletRegistrationRecord;
use yii\base\Component;
use yii\base\Exception;

/**
 * Tells iPhones that a membership card has changed.
 *
 * The push itself carries nothing — no title, no body, not even the serial number. It is
 * only a nudge: the device wakes, calls back to the pass web service, and asks what changed.
 * That is why a cancelled membership can grey out a card that is sitting in someone's wallet
 * without this site ever pushing content to their phone.
 *
 * Authentication is the Pass Type ID certificate itself, presented as a TLS client
 * certificate — the same certificate that signed the pass, which is how Apple knows the
 * push is coming from whoever issued the card. That means no separate APNs key to configure,
 * but it does mean curl has to be built with HTTP/2.
 */
class ApplePush extends Component
{
    private const ENDPOINT = 'https://api.push.apple.com/3/device/';

    /**
     * Notify every device holding a card for this membership.
     *
     * Returns the number of devices successfully notified. Devices Apple reports as gone
     * are unregistered as we go: a phone that has had the pass deleted, or been wiped,
     * would otherwise be pushed to forever.
     */
    public function pushForSubscription(Subscription $subscription): int
    {
        $wallet = Headcount::getInstance()->wallet;

        if (!$wallet->isAppleConfigured() || !$wallet->passUpdatesEnabled()) {
            return 0;
        }

        $registrations = $wallet->getRegistrationsForSubscription($subscription);

        if (!$registrations) {
            return 0;
        }

        try {
            $certificatePath = $this->writeClientCertificate();
        } catch (\Throwable $e) {
            Craft::error('Could not prepare the Apple push certificate: ' . $e->getMessage(), 'headcount');
            return 0;
        }

        $topic = App::parseEnv(Headcount::getInstance()->getSettings()->applePassTypeIdentifier);
        $pushed = 0;

        try {
            foreach ($registrations as $registration) {
                if ($this->push($registration, $certificatePath, $topic)) {
                    $pushed++;
                }
            }
        } finally {
            FileHelper::unlink($certificatePath);
        }

        return $pushed;
    }

    /**
     * One device. True when Apple accepted the push.
     */
    private function push(WalletRegistrationRecord $registration, string $certificatePath, string $topic): bool
    {
        if (!function_exists('curl_init')) {
            Craft::error('Apple Wallet push updates need PHP’s curl extension.', 'headcount');
            return false;
        }

        $handle = curl_init(self::ENDPOINT . $registration->pushToken);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apns-topic: ' . $topic,
                'apns-push-type: background',
                'Content-Type: application/json',
            ],
            CURLOPT_SSLCERT => $certificatePath,
            CURLOPT_TIMEOUT => 15,
            // APNs speaks HTTP/2 only. On a curl without it the request fails outright
            // rather than silently falling back, which is the honest outcome.
            CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : 3,
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($status === 200) {
            return true;
        }

        // 410 means the device is gone for good — Apple is explicit that the token should
        // not be used again, so the registration goes with it.
        if ($status === 410) {
            $registration->delete();
            return false;
        }

        Craft::warning(
            "Apple push for pass {$registration->serialNumber} failed ({$status}): " . ($error ?: (string)$body),
            'headcount',
        );

        return false;
    }

    /**
     * The Pass Type ID certificate as a PEM file curl can present.
     *
     * Written to Craft's temp directory for the length of one push run and removed
     * afterwards — it is the private key that signs every membership card the club issues,
     * and it has no business sitting on disk in a second place any longer than that.
     *
     * @throws Exception
     */
    private function writeClientCertificate(): string
    {
        $settings = Headcount::getInstance()->getSettings();
        $wallet = Headcount::getInstance()->wallet;

        $path = $wallet->resolvePath($settings->appleCertificatePath);

        if ($path === null) {
            throw new Exception('The Apple Wallet certificate could not be found.');
        }

        $certificates = [];
        $password = App::parseEnv($settings->appleCertificatePassword) ?: '';

        if (!openssl_pkcs12_read((string)file_get_contents($path), $certificates, $password)) {
            throw new Exception('Could not open the Apple Wallet certificate. Check the certificate password.');
        }

        $pemPath = Craft::$app->getPath()->getTempPath() . '/headcount-apns-' . bin2hex(random_bytes(8)) . '.pem';
        file_put_contents($pemPath, $certificates['cert'] . $certificates['pkey']);
        @chmod($pemPath, 0600);

        return $pemPath;
    }
}
