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
     * Before 5.5.0 they never did — Craft resolves an element URL without asking whether
     * the visitor may view it, so a rule only applied where a template called
     * `craft.headcount.canAccess()`. Turning this off restores that (rules still evaluate,
     * nothing is blocked automatically), for a site whose templates already handle it.
     */
    public bool $enforceAccessRules = true;

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
