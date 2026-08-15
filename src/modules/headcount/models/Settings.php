<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;

class Settings extends Model
{
    // Stripe
    public string $stripeSecretKey = '';
    public string $stripePublishableKey = '';
    public string $stripeWebhookSecret = '';
    public bool $stripeEnabled = true;

    // PayPal
    public string $paypalClientId = '';
    public string $paypalClientSecret = '';
    public string $paypalWebhookId = '';
    public bool $paypalSandbox = true;
    public bool $paypalEnabled = false;

    // General
    public string $defaultCurrency = 'USD';
    public string $checkoutSuccessUrl = '/membership/thank-you';
    public string $checkoutCancelUrl = '/membership/plans';
    public string $loginUrl = '/login';
    public string $pricingUrl = '/membership/plans';

    /**
     * Whether access rules stop a front-end request on their own.
     *
     * Before 5.2.0 they never did — Craft resolves an element URL without asking whether
     * the visitor may view it, so a rule only applied where a template called
     * `craft.headcount.canAccess()`. Turning this off restores that (rules still evaluate,
     * nothing is blocked automatically), for a site whose templates already handle it.
     */
    public bool $enforceAccessRules = true;

    /**
     * Wallet passes — Apple Wallet and Google Wallet.
     *
     * Every credential here belongs to the *site owner*, not to Headcount: a pass is signed
     * by the club's own Apple Pass Type ID certificate and issued under the club's own
     * Google Wallet issuer account, because both platforms bind a pass to the organisation
     * that vouches for it. A plugin can't ship either. All of these accept environment
     * variables, and the file paths should point somewhere outside the web root.
     */
    public bool $walletEnabled = false;

    /** Shown on the card as the issuing organisation. Falls back to the system name. */
    public string $walletOrganizationName = '';

    /** Apple requires a human-readable description for accessibility. */
    public string $walletDescription = 'Membership card';

    public string $walletBackgroundColor = 'rgb(28,28,30)';
    public string $walletForegroundColor = 'rgb(255,255,255)';
    public string $walletLabelColor = 'rgb(170,170,180)';

    /**
     * Directory holding the pass images: icon.png, icon@2x.png, logo.png, logo@2x.png.
     * Apple rejects a pass with no icon, so this is effectively required for Apple.
     */
    public string $walletImagePath = '';

    // Apple Wallet
    public bool $appleWalletEnabled = false;
    public string $applePassTypeIdentifier = '';
    public string $appleTeamIdentifier = '';

    /** Path to the Pass Type ID certificate exported as .p12, and its export password. */
    public string $appleCertificatePath = '';
    public string $appleCertificatePassword = '';

    /** Path to Apple's WWDR intermediate certificate (.pem). */
    public string $appleWwdrCertificatePath = '';

    /**
     * Whether to run the PassKit web service — device registration plus APNs pushes, so a
     * card already on a phone updates itself when the membership changes.
     *
     * Off, passes are still issued and still carry their expiry date; they just go stale on
     * the device if a membership is cancelled mid-term.
     */
    public bool $applePassUpdatesEnabled = true;

    // Google Wallet
    public bool $googleWalletEnabled = false;
    public string $googleWalletIssuerId = '';

    /** Path to the service account JSON key with Wallet Object Issuer access. */
    public string $googleWalletServiceAccountPath = '';

    // Email
    public bool $sendWelcomeEmail = true;
    public bool $sendPaymentReceiptEmail = true;
    public bool $sendPaymentFailedEmail = true;
    public bool $sendExpirationReminderEmail = true;
    public bool $sendTrialEndingEmail = true;
    public bool $sendCancellationEmail = true;
    public bool $sendDripUnlockedEmail = true;
    public int $expirationReminderDays = 3;

    // Outgoing Webhooks
    public string $outgoingWebhookUrl = '';
    public string $outgoingWebhookSecret = '';

    // API
    public string $apiKey = '';

    public function defineRules(): array
    {
        return [
            [['stripeSecretKey', 'stripePublishableKey', 'stripeWebhookSecret'], 'string'],
            [['paypalClientId', 'paypalClientSecret', 'paypalWebhookId'], 'string'],
            [['defaultCurrency'], 'string', 'max' => 3],
            [['checkoutSuccessUrl', 'checkoutCancelUrl', 'loginUrl', 'pricingUrl'], 'string'],
            [['outgoingWebhookUrl', 'outgoingWebhookSecret', 'apiKey'], 'string'],
            [['stripeEnabled', 'paypalEnabled', 'paypalSandbox', 'enforceAccessRules'], 'boolean'],
            [[
                'walletOrganizationName',
                'walletDescription',
                'walletBackgroundColor',
                'walletForegroundColor',
                'walletLabelColor',
                'walletImagePath',
                'applePassTypeIdentifier',
                'appleTeamIdentifier',
                'appleCertificatePath',
                'appleCertificatePassword',
                'appleWwdrCertificatePath',
                'googleWalletIssuerId',
                'googleWalletServiceAccountPath',
            ], 'string'],
            [[
                'walletEnabled',
                'appleWalletEnabled',
                'applePassUpdatesEnabled',
                'googleWalletEnabled',
            ], 'boolean'],
            [[
                'sendWelcomeEmail',
                'sendPaymentReceiptEmail',
                'sendPaymentFailedEmail',
                'sendExpirationReminderEmail',
                'sendTrialEndingEmail',
                'sendCancellationEmail',
                'sendDripUnlockedEmail',
            ], 'boolean'],
            [['expirationReminderDays'], 'integer', 'min' => 1],
        ];
    }
}
