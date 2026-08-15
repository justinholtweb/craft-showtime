<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use DateTimeInterface;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\base\Component;
use yii\base\Exception;
use ZipArchive;

/**
 * Builds and signs `.pkpass` files.
 *
 * A pass is a zip of a JSON description, its images, a manifest of SHA-1 digests of every
 * other file, and a detached PKCS#7 signature over that manifest made with the club's Pass
 * Type ID certificate. iOS checks all of it: a wrong digest, an unsigned manifest, a
 * certificate that doesn't match the pass type identifier, or a missing icon each produce
 * the same unhelpful "cannot be installed", so the failures worth distinguishing are
 * distinguished here instead, before the file ever reaches a phone.
 *
 * Written against OpenSSL directly rather than pulling in a pass library: the format is
 * small and stable, and the alternative is a dependency in the signing path of something a
 * member has to trust.
 */
class ApplePass extends Component
{
    /**
     * The images Apple looks for, and whether a pass is valid without them.
     *
     * `icon.png` is the one that is genuinely required — it's what appears in a push
     * notification and on the lock screen, and iOS refuses a pass that has no icon at all.
     */
    private const IMAGES = [
        'icon.png' => true,
        'icon@2x.png' => false,
        'logo.png' => false,
        'logo@2x.png' => false,
        'strip.png' => false,
        'strip@2x.png' => false,
        'thumbnail.png' => false,
        'thumbnail@2x.png' => false,
    ];

    /**
     * The raw bytes of a signed `.pkpass` for this membership.
     *
     * @throws Exception if the wallet isn't configured, the certificate can't be read, or
     *                   the pass can't be signed — never a half-built file.
     */
    public function build(Subscription $subscription): string
    {
        $wallet = Headcount::getInstance()->wallet;

        if (!$wallet->isAppleConfigured()) {
            throw new Exception('Apple Wallet is not configured.');
        }

        $settings = Headcount::getInstance()->getSettings();
        $certificatePath = $wallet->resolvePath($settings->appleCertificatePath);
        $wwdrPath = $wallet->resolvePath($settings->appleWwdrCertificatePath);

        $certificates = $this->readCertificate(
            $certificatePath,
            App::parseEnv($settings->appleCertificatePassword) ?: '',
        );

        $workingDir = $this->makeWorkingDirectory();

        try {
            $files = [];

            $passJson = json_encode($this->buildPassData($subscription), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            file_put_contents($workingDir . '/pass.json', $passJson);
            $files[] = 'pass.json';

            foreach ($this->collectImages() as $name => $sourcePath) {
                copy($sourcePath, $workingDir . '/' . $name);
                $files[] = $name;
            }

            // The manifest is the thing that is actually signed, so it must be written after
            // every other file and cover all of them.
            $manifest = [];
            foreach ($files as $file) {
                $manifest[$file] = sha1_file($workingDir . '/' . $file);
            }
            file_put_contents($workingDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES));

            $this->sign($workingDir . '/manifest.json', $workingDir . '/signature', $certificates, $wwdrPath);

            return $this->zip($workingDir, array_merge($files, ['manifest.json', 'signature']));
        } finally {
            FileHelper::removeDirectory($workingDir);
        }
    }

    /**
     * The pass.json body.
     *
     * Laid out as a club membership card reads: who it belongs to on the front in the
     * largest type, what it is and when it runs out beneath, and everything a member might
     * need to look up — including the fact that a screenshot of the card is not the card —
     * on the back.
     */
    public function buildPassData(Subscription $subscription): array
    {
        $wallet = Headcount::getInstance()->wallet;
        $settings = Headcount::getInstance()->getSettings();
        $card = $wallet->getCardData($subscription);

        $pass = [
            'formatVersion' => 1,
            'passTypeIdentifier' => App::parseEnv($settings->applePassTypeIdentifier),
            'teamIdentifier' => App::parseEnv($settings->appleTeamIdentifier),
            'organizationName' => $card['organizationName'],
            'description' => App::parseEnv($settings->walletDescription) ?: Craft::t('headcount', 'Membership card'),
            'serialNumber' => $card['serialNumber'],
            'logoText' => $card['organizationName'],
            'backgroundColor' => $settings->walletBackgroundColor,
            'foregroundColor' => $settings->walletForegroundColor,
            'labelColor' => $settings->walletLabelColor,
            'barcodes' => [[
                'format' => 'PKBarcodeFormatQR',
                'message' => $card['barcodeMessage'],
                'messageEncoding' => 'iso-8859-1',
                'altText' => $this->shortReference($card['serialNumber']),
            ]],
            'generic' => [
                'primaryFields' => [[
                    'key' => 'member',
                    'label' => Craft::t('headcount', 'MEMBER'),
                    'value' => $card['memberName'],
                ]],
                'secondaryFields' => array_values(array_filter([
                    [
                        'key' => 'plan',
                        'label' => Craft::t('headcount', 'MEMBERSHIP'),
                        'value' => $card['planName'],
                    ],
                    $card['expiryDate'] ? [
                        'key' => 'expires',
                        'label' => Craft::t('headcount', 'VALID UNTIL'),
                        'value' => $card['expiryDate']->format(DateTimeInterface::ATOM),
                        'dateStyle' => 'PKDateStyleMedium',
                        'timeStyle' => 'PKDateStyleNone',
                    ] : null,
                ])),
                'auxiliaryFields' => array_values(array_filter([
                    [
                        'key' => 'status',
                        'label' => Craft::t('headcount', 'STATUS'),
                        'value' => $card['statusLabel'],
                    ],
                    $card['memberSince'] ? [
                        'key' => 'since',
                        'label' => Craft::t('headcount', 'MEMBER SINCE'),
                        'value' => $card['memberSince']->format(DateTimeInterface::ATOM),
                        'dateStyle' => 'PKDateStyleMedium',
                        'timeStyle' => 'PKDateStyleNone',
                    ] : null,
                ])),
                'backFields' => [
                    [
                        'key' => 'reference',
                        'label' => Craft::t('headcount', 'Membership number'),
                        'value' => $this->shortReference($card['serialNumber']),
                    ],
                    [
                        'key' => 'verify',
                        'label' => Craft::t('headcount', 'Verify this card'),
                        'value' => $card['barcodeMessage'],
                    ],
                    [
                        'key' => 'website',
                        'label' => Craft::t('headcount', 'Website'),
                        'value' => UrlHelper::siteUrl(),
                    ],
                ],
            ],
        ];

        // iOS greys out a pass past its expiry date on its own, which is the behaviour that
        // makes a season card safe to hand to a shop: it stops looking valid on 1 July
        // whether or not the phone has spoken to this site since.
        if ($card['expiryDate']) {
            $pass['expirationDate'] = $card['expiryDate']->format(DateTimeInterface::ATOM);
        }

        // Voided is stronger and permanent — for a membership that ended early rather than
        // one that ran its course.
        if (!$card['valid']) {
            $pass['voided'] = true;
        }

        if ($wallet->passUpdatesEnabled()) {
            $pass['authenticationToken'] = $wallet->getAuthenticationToken($subscription);
            $pass['webServiceURL'] = UrlHelper::siteUrl('headcount/wallet/v1');
        }

        return $pass;
    }

    /**
     * @return array{cert: string, pkey: string}
     * @throws Exception
     */
    private function readCertificate(string $path, string $password): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new Exception("Could not read the Apple Wallet certificate at {$path}.");
        }

        $certificates = [];

        if (!openssl_pkcs12_read($contents, $certificates, $password)) {
            // Overwhelmingly the password, and OpenSSL's own error says nothing useful.
            throw new Exception('Could not open the Apple Wallet certificate. Check the certificate password.');
        }

        return $certificates;
    }

    /**
     * @throws Exception
     */
    private function sign(string $manifestPath, string $signaturePath, array $certificates, string $wwdrPath): void
    {
        $signed = openssl_pkcs7_sign(
            $manifestPath,
            $signaturePath,
            $certificates['cert'],
            $certificates['pkey'],
            [],
            PKCS7_BINARY | PKCS7_DETACHED,
            $wwdrPath,
        );

        if (!$signed) {
            throw new Exception('Could not sign the pass manifest: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        file_put_contents($signaturePath, $this->derFromSmime(file_get_contents($signaturePath)));
    }

    /**
     * Pull the DER signature out of what OpenSSL actually wrote.
     *
     * `openssl_pkcs7_sign()` emits an S/MIME message — headers, a MIME boundary, then the
     * signature base64-encoded — but a pass must contain the bare DER bytes. There is no
     * OpenSSL flag for this in PHP, so the payload is cut out of the envelope by hand.
     *
     * @throws Exception
     */
    private function derFromSmime(string $smime): string
    {
        $marker = 'filename="smime.p7s"';
        $start = strpos($smime, $marker);

        if ($start === false) {
            throw new Exception('The signed manifest was not in the expected S/MIME format.');
        }

        $body = substr($smime, $start + strlen($marker));
        $end = strpos($body, '------');
        $body = $end === false ? $body : substr($body, 0, $end);

        $der = base64_decode(trim($body), true);

        if ($der === false || $der === '') {
            throw new Exception('The pass signature could not be decoded.');
        }

        return $der;
    }

    /**
     * @return array<string, string> filename => source path
     * @throws Exception if the one genuinely required image is missing
     */
    private function collectImages(): array
    {
        $wallet = Headcount::getInstance()->wallet;
        $settings = Headcount::getInstance()->getSettings();
        $directory = $wallet->resolvePath($settings->walletImagePath);

        $found = [];

        foreach (self::IMAGES as $name => $required) {
            $path = $directory ? $directory . DIRECTORY_SEPARATOR . $name : null;

            if ($path && is_file($path)) {
                $found[$name] = $path;
                continue;
            }

            if ($required) {
                throw new Exception(
                    "Apple Wallet passes need an icon.png. Add one to the wallet image directory" .
                    ($directory ? " ({$directory})." : ', which is not set.')
                );
            }
        }

        return $found;
    }

    /**
     * @throws Exception
     */
    private function zip(string $directory, array $files): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new Exception('Building a wallet pass needs PHP’s zip extension.');
        }

        $archivePath = $directory . '/pass.pkpass';
        $zip = new ZipArchive();

        if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create the pass archive.');
        }

        foreach ($files as $file) {
            $zip->addFile($directory . '/' . $file, $file);
        }

        $zip->close();

        $contents = file_get_contents($archivePath);

        if ($contents === false) {
            throw new Exception('Could not read the finished pass.');
        }

        return $contents;
    }

    private function makeWorkingDirectory(): string
    {
        $path = Craft::$app->getPath()->getTempPath() . '/headcount-pass-' . bin2hex(random_bytes(8));
        FileHelper::createDirectory($path);

        return $path;
    }

    /**
     * A membership number a human can read out over a counter.
     *
     * The serial is a UUID, which nobody is going to dictate; the first block of it is
     * enough to find a member by, and the QR code carries the whole thing anyway.
     */
    private function shortReference(string $serialNumber): string
    {
        return strtoupper(substr(str_replace('-', '', $serialNumber), 0, 8));
    }
}
