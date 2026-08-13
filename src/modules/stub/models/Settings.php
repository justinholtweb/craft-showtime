<?php

namespace justinholtweb\stub\models;

use craft\base\Model;
use craft\validators\ColorValidator;

class Settings extends Model
{
    // General
    public string $pluginName = 'Stub';
    public string $defaultCurrency = 'USD';
    public string $defaultTimezone = 'America/New_York';
    public int $minimumNotice = 60; // minutes
    public int $maxAdvanceBooking = 90; // days
    public int $slotInterval = 15; // minutes

    // Booking
    public bool $autoConfirmFreeBookings = true;
    public bool $requirePhone = false;
    public bool $allowCustomerNotes = true;

    /**
     * Attach a customer to the Craft user with the same email address.
     *
     * On by default, which is what Stub has always done for new customers; this extends it
     * to a customer who books again after registering an account, and makes it switchable
     * for sites where a shared address (a household, an office) shouldn't imply one person.
     */
    public bool $linkCustomersToUsers = true;

    public string $referencePrefix = 'STB';
    public string $bookingPageUrl = '';
    public string $termsUrl = '';

    // Stripe
    public string $stripePublishableKey = '';
    public string $stripeSecretKey = '';
    public string $stripeWebhookSecret = '';
    public bool $paymentEnabled = false;

    // Notifications
    public string $adminEmail = '';
    public bool $sendCustomerConfirmation = true;
    public bool $sendAdminNotification = true;
    public bool $sendCancellationEmail = true;

    // Appearance
    public string $primaryColor = '#2563eb';
    public bool $embedStripeJs = true;

    // Anti-abuse
    public bool $enableHoneypot = true;
    public string $honeypotFieldName = 'stub_hp';
    public int $bookingsPerHour = 10;
    public int $paymentIntentsPerHour = 30;

    public function defineRules(): array
    {
        return [
            [['pluginName', 'defaultCurrency', 'defaultTimezone'], 'required'],
            [['pluginName'], 'string', 'max' => 50],
            [['defaultCurrency'], 'string', 'length' => 3],
            // ISO 4217 alphabetic codes are uppercase; the shape check is deliberately
            // looser than a list membership check, since Commerce can supply codes the
            // built-in picker list doesn't carry.
            [['defaultCurrency'], 'match', 'pattern' => '/^[A-Z]{3}$/'],
            [['minimumNotice', 'maxAdvanceBooking', 'slotInterval'], 'integer', 'min' => 1],
            [['adminEmail'], 'email', 'skipOnEmpty' => true],
            // Craft's color input posts the hex without a leading `#`, so normalize
            // before matching. The pattern excludes `transparent`, which is not a
            // usable value for the front-end accent color.
            [['primaryColor'], ColorValidator::class, 'pattern' => '/^#[0-9a-f]{6}$/'],
            [['bookingsPerHour', 'paymentIntentsPerHour'], 'integer', 'min' => 0],
            [['honeypotFieldName'], 'string', 'max' => 50],
            [['enableHoneypot', 'linkCustomersToUsers'], 'boolean'],
        ];
    }
}
