<?php

namespace justinholtweb\headcount;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\AuthorizationCheckEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\Application;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\headcount\assets\HeadcountAsset;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\models\Settings;
use justinholtweb\headcount\services\ApplePass;
use justinholtweb\headcount\services\ApplePush;
use justinholtweb\headcount\services\Coupons;
use justinholtweb\headcount\services\Drip;
use justinholtweb\headcount\services\Emails;
use justinholtweb\headcount\services\Gating;
use justinholtweb\headcount\services\GoogleWallet;
use justinholtweb\headcount\services\Members;
use justinholtweb\headcount\services\PayPal;
use justinholtweb\headcount\services\Plans;
use justinholtweb\headcount\services\Reporting;
use justinholtweb\headcount\services\Stripe;
use justinholtweb\headcount\services\Subscriptions;
use justinholtweb\headcount\services\Wallet;
use justinholtweb\headcount\services\Webhooks;
use justinholtweb\headcount\twig\HeadcountTwigExtension;
use justinholtweb\headcount\twig\HeadcountVariable;
use justinholtweb\headcount\widgets\MembershipOverviewWidget;
use justinholtweb\headcount\widgets\RevenueWidget;
use yii\base\ActionEvent;
use yii\base\Event;

/**
 * Headcount - Membership & Subscription Management for Craft CMS 5
 *
 * @property-read Plans $plans
 * @property-read Subscriptions $subscriptions
 * @property-read Gating $gating
 * @property-read Stripe $stripe
 * @property-read PayPal $paypal
 * @property-read Webhooks $webhooks
 * @property-read Drip $drip
 * @property-read Coupons $coupons
 * @property-read Members $members
 * @property-read Reporting $reporting
 * @property-read Emails $emails
 * @property-read Wallet $wallet
 * @property-read ApplePass $applePass
 * @property-read ApplePush $applePush
 * @property-read GoogleWallet $googleWallet
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class Headcount extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * When true, Headcount is running as an internal module mounted inside the Showtime
     * bundle plugin rather than installed as a standalone plugin. In that mode Headcount
     * boots its feature wiring but leaves control-panel "chrome" (nav) to the Showtime
     * host, which unifies it with the other bundled plugins.
     *
     * Default false → standalone behavior is unchanged.
     */
    public bool $mountedUnderShowtime = false;

    /**
     * Set by the host when mounted: fn(array $settings): bool.
     *
     * Craft persists plugin settings for *installed* plugins only, so a mounted module has
     * nowhere of its own to write to. The host injects a writer here rather than Headcount
     * knowing anything about the host. Null (standalone) → Craft's Plugins service.
     *
     * @var callable|null
     */
    public $settingsSaver = null;

    /**
     * Set by the host when mounted: fn(string $payload, string $sigHeader): bool.
     *
     * When a host bundle owns the Stripe account, every webhook should be verified and
     * routed the same way no matter which URL Stripe was pointed at — otherwise a site that
     * configured this plugin's endpoint before bundling behaves subtly differently from one
     * that used the bundle's. Null (standalone) → Headcount handles it itself.
     *
     * @var callable|null
     */
    public $stripeWebhookRouter = null;

    public static function config(): array
    {
        return [
            'components' => [
                'plans' => Plans::class,
                'subscriptions' => Subscriptions::class,
                'gating' => Gating::class,
                'stripe' => Stripe::class,
                'paypal' => PayPal::class,
                'webhooks' => Webhooks::class,
                'drip' => Drip::class,
                'coupons' => Coupons::class,
                'members' => Members::class,
                'reporting' => Reporting::class,
                'emails' => Emails::class,
                'wallet' => Wallet::class,
                'applePass' => ApplePass::class,
                'applePush' => ApplePush::class,
                'googleWallet' => GoogleWallet::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->bootFeatures();

        if (!$this->mountedUnderShowtime) {
            $this->bootChrome();
        }
    }

    /**
     * Functionality that must run in BOTH modes (standalone and mounted under Showtime).
     */
    private function bootFeatures(): void
    {
        $this->_registerElementTypes();
        $this->_registerEmailMessages();
        $this->_registerCpUrlRules();
        $this->_registerSiteUrlRules();
        $this->_registerPermissions();
        $this->_registerWidgets();
        $this->_registerTemplateVariable();
        $this->_registerTwigExtension();
        $this->_registerContentGating();
        $this->_registerTemplateHooks();
        $this->_registerCpAssets();
    }

    /**
     * Control-panel chrome that only applies when Headcount is installed as its own plugin.
     * When mounted under Showtime, the host owns the nav.
     *
     * Headcount's nav is served via hasCpSection + getCpNavItem(), which Craft only invokes
     * for an installed plugin — so there is nothing to unwire here. Kept so all bundled
     * plugins share one mount shape.
     */
    private function bootChrome(): void
    {
    }

    /**
     * Persist the plugin's settings.
     *
     * Standalone this is Craft's Plugins service. Mounted under a host, the host owns
     * settings storage and injects a writer (see $settingsSaver).
     */
    public function saveSettings(array $settings): bool
    {
        if ($this->settingsSaver !== null) {
            return (bool)call_user_func($this->settingsSaver, $settings);
        }

        return Craft::$app->getPlugins()->savePluginSettings($this, $settings);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Headcount';

        $user = Craft::$app->getUser()->getIdentity();

        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'headcount'],
            'plans' => ['label' => 'Plans', 'url' => 'headcount/plans'],
            'subscriptions' => ['label' => 'Subscriptions', 'url' => 'headcount/subscriptions'],
            'access-rules' => ['label' => 'Access Rules', 'url' => 'headcount/access-rules'],
            'drip' => ['label' => 'Drip', 'url' => 'headcount/drip'],
            'coupons' => ['label' => 'Coupons', 'url' => 'headcount/coupons'],
        ];

        if ($user && $user->can('headcount-viewReports')) {
            $item['subnav']['reports'] = ['label' => 'Reports', 'url' => 'headcount/reports'];
        }

        $item['subnav']['settings'] = ['label' => 'Settings', 'url' => 'headcount/settings'];

        return $item;
    }

    /**
     * Refuse to install alongside a host bundle that already includes Headcount.
     *
     * Both would register the Subscription element type and share the `headcount_*` tables,
     * and uninstalling either would then drop the other's data. The guard is skipped when
     * the host is installing Headcount *as* a mounted module — that call is exactly this
     * method running with $mountedUnderShowtime already true.
     */
    protected function beforeInstall(): void
    {
        if (!$this->mountedUnderShowtime && Craft::$app->getPlugins()->isPluginInstalled('showtime')) {
            throw new \yii\base\Exception(
                'Headcount is already included in the Showtime bundle, which is installed on this ' .
                'site. Installing it separately would register a second Subscription element type ' .
                'and collide on the headcount_* tables. Use Showtime’s bundled copy instead.'
            );
        }
    }

    /**
     * The member lifecycle emails, keyed by their Craft system-message key.
     *
     * One source of truth: this registers the messages with Craft (so their copy is editable
     * under Settings → Email → System Messages and translatable), tells {@see Emails} which
     * setting switches each one on, and describes them to a host bundle that wants to list
     * every bundled plugin's notifications on one screen.
     *
     * `variables` lists the placeholders the body may use — the harness renders each body
     * against exactly that set, so copy referring to anything else is caught before a
     * member's email silently fails to send.
     *
     * @return array<string, array{heading: string, description: string, setting: string, subject: string, body: string, variables: string[]}>
     */
    public static function emailDefinitions(): array
    {
        return [
            'headcount_welcome' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency'],
                'heading' => Craft::t('headcount', 'Welcome'),
                'description' => Craft::t('headcount', 'Sent when a subscription first becomes active.'),
                'setting' => 'sendWelcomeEmail',
                'subject' => Craft::t('headcount', 'Welcome to {{siteName}}'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nWelcome to {{siteName}}! Your {{planName}} subscription is now active.\n\nThank you for joining."),
            ],
            'headcount_receipt' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency', 'amount'],
                'heading' => Craft::t('headcount', 'Payment Receipt'),
                'description' => Craft::t('headcount', 'Sent after each successful subscription payment.'),
                'setting' => 'sendPaymentReceiptEmail',
                'subject' => Craft::t('headcount', 'Payment receipt'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nWe've received your payment of {{currency}} {{amount}} for your {{planName}} subscription.\n\nThank you."),
            ],
            'headcount_payment_failed' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency'],
                'heading' => Craft::t('headcount', 'Payment Failed'),
                'description' => Craft::t('headcount', 'Sent when a renewal payment is declined.'),
                'setting' => 'sendPaymentFailedEmail',
                'subject' => Craft::t('headcount', 'Payment failed — action required'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nWe were unable to process your payment for {{planName}}. Please update your payment method to avoid any interruption to your subscription.\n\nYou can manage your subscription at {{siteUrl}}"),
            ],
            'headcount_expiration_reminder' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency'],
                'heading' => Craft::t('headcount', 'Expiration Reminder'),
                'description' => Craft::t('headcount', 'Sent a set number of days before a subscription expires.'),
                'setting' => 'sendExpirationReminderEmail',
                'subject' => Craft::t('headcount', 'Your subscription is expiring soon'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nYour {{planName}} subscription is expiring soon. If you have automatic renewal enabled, no action is needed."),
            ],
            'headcount_trial_ending' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency'],
                'heading' => Craft::t('headcount', 'Trial Ending'),
                'description' => Craft::t('headcount', 'Sent shortly before a free trial converts to a paid subscription.'),
                'setting' => 'sendTrialEndingEmail',
                'subject' => Craft::t('headcount', 'Your trial is ending soon'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nYour trial for {{planName}} is ending soon. After it ends you'll be billed at the regular rate.\n\nIf you'd like to continue, no action is needed."),
            ],
            'headcount_cancellation' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency'],
                'heading' => Craft::t('headcount', 'Subscription Canceled'),
                'description' => Craft::t('headcount', 'Sent when a subscription is cancelled.'),
                'setting' => 'sendCancellationEmail',
                'subject' => Craft::t('headcount', 'Subscription canceled'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nYour {{planName}} subscription has been canceled. You'll keep access until the end of your current billing period.\n\nWe're sorry to see you go."),
            ],
            'headcount_drip_unlocked' => [
                'variables' => ['firstName', 'email', 'planName', 'siteName', 'siteUrl', 'currency', 'scheduleName'],
                'heading' => Craft::t('headcount', 'Drip Content Unlocked'),
                'description' => Craft::t('headcount', 'Sent when a drip schedule releases new content to a member.'),
                'setting' => 'sendDripUnlockedEmail',
                'subject' => Craft::t('headcount', 'New content unlocked'),
                'body' => Craft::t('headcount', "Hi {{firstName}},\n\nNew content has been unlocked for you as part of your {{planName}} subscription.\n\nVisit {{siteUrl}} to take a look."),
            ],
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'headcount/settings/index',
            ['settings' => $this->getSettings()]
        );
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Subscription::class;
            }
        );
    }

    private function _registerEmailMessages(): void
    {
        Event::on(
            SystemMessages::class,
            SystemMessages::EVENT_REGISTER_MESSAGES,
            function(RegisterEmailMessagesEvent $event) {
                foreach (static::emailDefinitions() as $key => $definition) {
                    $event->messages[] = [
                        'key' => $key,
                        'heading' => $definition['heading'],
                        'subject' => $definition['subject'],
                        'body' => $definition['body'],
                    ];
                }
            }
        );
    }

    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['headcount'] = 'headcount/reporting/dashboard';
                $event->rules['headcount/plans'] = 'headcount/plans/index';
                $event->rules['headcount/plans/new'] = 'headcount/plans/edit';
                $event->rules['headcount/plans/<planId:\d+>'] = 'headcount/plans/edit';
                $event->rules['headcount/subscriptions'] = 'headcount/subscriptions/index';
                $event->rules['headcount/subscriptions/<elementId:\d+>'] = 'headcount/subscriptions/edit';
                $event->rules['headcount/access-rules'] = 'headcount/access-rules/index';
                $event->rules['headcount/access-rules/new'] = 'headcount/access-rules/edit';
                $event->rules['headcount/access-rules/<ruleId:\d+>'] = 'headcount/access-rules/edit';
                $event->rules['headcount/drip'] = 'headcount/drip/index';
                $event->rules['headcount/drip/new'] = 'headcount/drip/edit';
                $event->rules['headcount/drip/<scheduleId:\d+>'] = 'headcount/drip/edit';
                $event->rules['headcount/coupons'] = 'headcount/coupons/index';
                $event->rules['headcount/coupons/new'] = 'headcount/coupons/edit';
                $event->rules['headcount/coupons/<couponId:\d+>'] = 'headcount/coupons/edit';
                $event->rules['headcount/reports'] = 'headcount/reporting/index';
                $event->rules['headcount/settings'] = 'headcount/settings/index';
                $event->rules['headcount/settings/stripe'] = 'headcount/settings/stripe';
                $event->rules['headcount/settings/paypal'] = 'headcount/settings/paypal';
                $event->rules['headcount/settings/emails'] = 'headcount/settings/emails';
                $event->rules['headcount/settings/wallet'] = 'headcount/settings/wallet';
            }
        );
    }

    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['headcount/checkout'] = 'headcount/checkout/create-session';
                $event->rules['headcount/checkout/success'] = 'headcount/checkout/success';
                $event->rules['headcount/checkout/cancel'] = 'headcount/checkout/cancel';
                $event->rules['headcount/portal'] = 'headcount/portal/redirect';

                // Member-facing wallet card links.
                $event->rules['headcount/wallet/apple/<subscriptionId:\d+>'] = 'headcount/wallet/apple';
                $event->rules['headcount/wallet/google/<subscriptionId:\d+>'] = 'headcount/wallet/google';
                $event->rules['headcount/wallet/verify'] = 'headcount/wallet/verify';

                // Apple's PassKit web service. The paths below the `v1` prefix are Apple's,
                // not ours — iOS constructs them from the `webServiceURL` baked into each
                // pass, so they can't be renamed. Matching on the HTTP verb is what
                // separates registering a device from unregistering it: same URL, and the
                // method is the whole difference.
                $device = 'headcount/wallet/v1/devices/<deviceLibraryIdentifier:[^\/]+>/registrations/<passTypeIdentifier:[^\/]+>';
                $event->rules["POST {$device}/<serialNumber:[^\/]+>"] = 'headcount/wallet/register-device';
                $event->rules["DELETE {$device}/<serialNumber:[^\/]+>"] = 'headcount/wallet/unregister-device';
                $event->rules["GET {$device}"] = 'headcount/wallet/list-registrations';
                $event->rules['GET headcount/wallet/v1/passes/<passTypeIdentifier:[^\/]+>/<serialNumber:[^\/]+>'] = 'headcount/wallet/latest-pass';
                $event->rules['POST headcount/wallet/v1/log'] = 'headcount/wallet/log';
            }
        );
    }

    /**
     * The permissions Headcount defines, keyed by permission name.
     *
     * Exposed so a host bundle can list them under its own single heading rather than
     * showing one heading per bundled plugin. The keys are the contract — controllers, nav
     * items and user groups all reference them — so they never change between modes.
     */
    public static function permissionDefinitions(): array
    {
        return [
            'headcount-managePlans' => [
                'label' => 'Manage membership plans',
            ],
            'headcount-manageSubscriptions' => [
                'label' => 'Manage subscriptions',
            ],
            'headcount-manageAccessRules' => [
                'label' => 'Manage access rules',
            ],
            'headcount-viewReports' => [
                'label' => 'View reports',
            ],
            'headcount-manageCoupons' => [
                'label' => 'Manage coupons',
            ],
            'headcount-manageDrip' => [
                'label' => 'Manage drip schedules',
            ],
        ];
    }

    private function _registerPermissions(): void
    {
        // Mounted, the host registers these under one combined heading.
        if ($this->mountedUnderShowtime) {
            return;
        }

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Headcount',
                    'permissions' => static::permissionDefinitions(),
                ];
            }
        );
    }

    private function _registerWidgets(): void
    {
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = MembershipOverviewWidget::class;
                $event->types[] = RevenueWidget::class;
            }
        );
    }

    private function _registerTemplateVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('headcount', HeadcountVariable::class);
            }
        );
    }

    private function _registerTwigExtension(): void
    {
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Craft::$app->getView()->registerTwigExtension(new HeadcountTwigExtension());
        }
    }

    /**
     * Enforce access rules on the front end.
     *
     * Two hooks, because they cover different things and neither is enough alone:
     *
     *  1. `Elements::EVENT_AUTHORIZE_VIEW` answers `craft.app.elements.canView(el)` for any
     *     element type — the question templates and other plugins ask.
     *  2. `beforeAction` is what actually **stops a request**. Craft resolves a front-end
     *     element URL and renders its template without ever calling `canView()`, so a rule
     *     enforced only at (1) protects nothing: visiting the URL directly serves the page.
     *     This is the hook that makes redirect / paywall / hide mean something.
     */
    private function _registerContentGating(): void
    {
        if (!Craft::$app->getRequest()->getIsSiteRequest()) {
            return;
        }

        Event::on(
            Elements::class,
            Elements::EVENT_AUTHORIZE_VIEW,
            function(AuthorizationCheckEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->element;
                $result = $this->gating->evaluateAccess($element, $event->user);

                if ($result !== null && !$result['allowed']) {
                    $event->authorized = false;
                    $event->handled = true;
                }
            }
        );

        if (!$this->getSettings()->enforceAccessRules) {
            return;
        }

        Event::on(
            Application::class,
            Application::EVENT_BEFORE_ACTION,
            function(ActionEvent $event) {
                $element = Craft::$app->getUrlManager()->getMatchedElement();

                if (!$element) {
                    return;
                }

                $redirectUrl = $this->gating->enforce($element, Craft::$app->getUser()->getIdentity());

                if ($redirectUrl !== null) {
                    Craft::$app->getResponse()->redirect($redirectUrl);
                    $event->isValid = false;
                }
            }
        );
    }

    private function _registerCpAssets(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function() {
                Craft::$app->getView()->registerAssetBundle(HeadcountAsset::class);
            }
        );
    }

    private function _registerTemplateHooks(): void
    {
        Craft::$app->getView()->hook('cp.entries.edit.details', function(array &$context) {
            $entry = $context['entry'] ?? null;
            if (!$entry) {
                return '';
            }

            return Craft::$app->getView()->renderTemplate(
                'headcount/_sidebar/entry-gating',
                ['entry' => $entry]
            );
        });
    }
}
