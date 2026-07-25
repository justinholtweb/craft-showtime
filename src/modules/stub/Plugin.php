<?php

namespace justinholtweb\stub;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\models\Settings;
use justinholtweb\stub\services\Availability;
use justinholtweb\stub\services\Bookings;
use justinholtweb\stub\services\Customers;
use justinholtweb\stub\services\Emails;
use justinholtweb\stub\services\Payments;
use justinholtweb\stub\services\Providers;
use justinholtweb\stub\services\Services;
use justinholtweb\stub\variables\StubVariable;
use yii\base\Event;
use yii\base\Exception;

/**
 * Stub — Booking & Appointments for Craft CMS
 *
 * @property Services $services
 * @property Providers $providers
 * @property Bookings $bookings
 * @property Availability $availability
 * @property Customers $customers
 * @property Payments $payments
 * @property Emails $emails
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * When true, Stub is running as an internal module mounted inside the Showtime bundle
     * plugin rather than installed as a standalone plugin. In that mode Stub boots its
     * feature wiring but leaves control-panel "chrome" (nav, settings screen) to the
     * Showtime host, which unifies it with the other bundled plugins.
     *
     * Default false → standalone behavior is unchanged.
     */
    public bool $mountedUnderShowtime = false;

    /**
     * Set by the host when mounted: fn(string $payload, string $sigHeader): bool.
     *
     * When a host bundle owns the Stripe account, every webhook should be verified and
     * routed the same way no matter which URL Stripe was pointed at — otherwise a site that
     * configured this plugin's endpoint before bundling behaves subtly differently from one
     * that used the bundle's. Null (standalone) → Stub handles it itself.
     *
     * @var callable|null
     */
    public $stripeWebhookRouter = null;

    public static function config(): array
    {
        return [
            'components' => [
                'services' => Services::class,
                'providers' => Providers::class,
                'bookings' => Bookings::class,
                'availability' => Availability::class,
                'customers' => Customers::class,
                'payments' => Payments::class,
                'emails' => Emails::class,
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
        $this->_registerVariables();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerPermissions();
        $this->_registerEmailMessages();
    }

    /**
     * Control-panel chrome that only applies when Stub is installed as its own plugin.
     * When mounted under Showtime, the host owns the nav and the settings screen.
     *
     * Stub's nav and settings page are served via hasCpSection/hasCpSettings +
     * getCpNavItem()/settingsHtml(), which Craft only invokes for an installed plugin —
     * so there is nothing to unwire here. Kept so all bundled plugins share one mount
     * shape, and as the hook for anything chrome-ish added later.
     */
    private function bootChrome(): void
    {
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = $this->getSettings()->pluginName ?: 'Stub';

        $nav['subnav'] = [
            'dashboard' => ['label' => Craft::t('stub', 'Dashboard'), 'url' => 'stub/dashboard'],
            'calendar' => ['label' => Craft::t('stub', 'Calendar'), 'url' => 'stub/calendar'],
            'bookings' => ['label' => Craft::t('stub', 'Bookings'), 'url' => 'stub/bookings'],
            'services' => ['label' => Craft::t('stub', 'Services'), 'url' => 'stub/services'],
            'providers' => ['label' => Craft::t('stub', 'Providers'), 'url' => 'stub/providers'],
            'customers' => ['label' => Craft::t('stub', 'Customers'), 'url' => 'stub/customers'],
        ];

        return $nav;
    }

    /**
     * Refuse to install alongside a host bundle that already includes Stub.
     *
     * Both would register the Booking element type and share the `stub_*` tables, and
     * uninstalling either would then drop the other's data. The guard is skipped when the
     * host is installing Stub *as* a mounted module — that call is exactly this method
     * running with $mountedUnderShowtime already true.
     */
    protected function beforeInstall(): void
    {
        if (!$this->mountedUnderShowtime && Craft::$app->getPlugins()->isPluginInstalled('showtime')) {
            throw new Exception(
                'Stub is already included in the Showtime bundle, which is installed on this site. ' .
                'Installing it separately would register a second Booking element type and collide ' .
                'on the stub_* tables. Use Showtime’s bundled copy instead.'
            );
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('stub/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = Booking::class;
            }
        );
    }

    private function _registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('stub', StubVariable::class);
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['stub'] = 'stub/dashboard/index';
                $event->rules['stub/dashboard'] = 'stub/dashboard/index';
                $event->rules['stub/calendar'] = 'stub/calendar/index';
                $event->rules['stub/bookings'] = 'stub/bookings/index';
                $event->rules['stub/bookings/<bookingId:\d+>'] = 'stub/bookings/edit';
                $event->rules['stub/services'] = 'stub/services/index';
                $event->rules['stub/services/new'] = 'stub/services/edit';
                $event->rules['stub/services/<serviceId:\d+>'] = 'stub/services/edit';
                $event->rules['stub/providers'] = 'stub/providers/index';
                $event->rules['stub/providers/new'] = 'stub/providers/edit';
                $event->rules['stub/providers/<providerId:\d+>'] = 'stub/providers/edit';
                $event->rules['stub/providers/<providerId:\d+>/schedule'] = 'stub/providers/schedule';
                $event->rules['stub/customers'] = 'stub/customers/index';
                $event->rules['stub/customers/<customerId:\d+>'] = 'stub/customers/detail';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Site routes handled via action URLs
            }
        );
    }

    /**
     * The permissions Stub defines, keyed by permission name.
     *
     * Exposed so a host bundle can list them under its own single heading rather than
     * showing one heading per bundled plugin. The keys are the contract — controllers, nav
     * items and user groups all reference them — so they never change between modes.
     */
    public static function permissionDefinitions(): array
    {
        return [
            'stub:viewBookings' => [
                'label' => Craft::t('stub', 'View bookings'),
            ],
            'stub:manageBookings' => [
                'label' => Craft::t('stub', 'Manage bookings'),
            ],
            'stub:deleteBookings' => [
                'label' => Craft::t('stub', 'Delete bookings'),
            ],
            'stub:manageServices' => [
                'label' => Craft::t('stub', 'Manage services'),
            ],
            'stub:manageProviders' => [
                'label' => Craft::t('stub', 'Manage providers'),
            ],
            'stub:manageCustomers' => [
                'label' => Craft::t('stub', 'Manage customers'),
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
                    'heading' => Craft::t('stub', 'Stub'),
                    'permissions' => static::permissionDefinitions(),
                ];
            }
        );
    }

    /**
     * The emails Stub sends, keyed by their Craft system-message key.
     *
     * One source of truth: this both registers the messages with Craft (so their copy is
     * editable and translatable in the control panel) and describes them to a host bundle
     * that wants to list every bundled plugin's notifications on one screen. `setting` names
     * the settings attribute that switches each one on or off.
     *
     * `variables` lists the placeholders the body may use — the harness renders each body
     * against exactly that set, so copy referring to anything else is caught before a
     * customer's email silently fails to send.
     *
     * @return array<string, array{heading: string, description: string, setting: string, subject: string, body: string, variables: string[]}>
     */
    public static function emailDefinitions(): array
    {
        return [
            'stub_booking_confirmation' => [
                'variables' => ['referenceNumber', 'customerName', 'customerEmail', 'serviceName', 'providerName', 'dateFormatted', 'timeFormatted', 'priceFormatted', 'timezone'],
                'heading' => Craft::t('stub', 'Booking Confirmation'),
                'description' => Craft::t('stub', 'Sent to the customer when their booking is created.'),
                'setting' => 'sendCustomerConfirmation',
                'subject' => Craft::t('stub', 'Your booking has been confirmed — {{referenceNumber}}'),
                'body' => Craft::t('stub', "Hi {{customerName}},\n\nYour booking for {{serviceName}} with {{providerName}} on {{dateFormatted}} at {{timeFormatted}} has been confirmed.\n\nReference: {{referenceNumber}}\n\nThank you!"),
            ],
            'stub_admin_notification' => [
                'variables' => ['referenceNumber', 'customerName', 'customerEmail', 'serviceName', 'providerName', 'dateFormatted', 'timeFormatted', 'priceFormatted', 'timezone'],
                'heading' => Craft::t('stub', 'New Booking Notification'),
                'description' => Craft::t('stub', 'Sent to the admin address when any booking is created.'),
                'setting' => 'sendAdminNotification',
                'subject' => Craft::t('stub', 'New booking: {{referenceNumber}}'),
                'body' => Craft::t('stub', "A new booking has been created.\n\nReference: {{referenceNumber}}\nService: {{serviceName}}\nProvider: {{providerName}}\nCustomer: {{customerName}}\nDate: {{dateFormatted}} at {{timeFormatted}}"),
            ],
            'stub_booking_cancellation' => [
                'variables' => ['referenceNumber', 'customerName', 'customerEmail', 'serviceName', 'providerName', 'dateFormatted', 'timeFormatted', 'priceFormatted', 'timezone'],
                'heading' => Craft::t('stub', 'Booking Cancellation'),
                'description' => Craft::t('stub', 'Sent to the customer and the admin address when a booking is cancelled.'),
                'setting' => 'sendCancellationEmail',
                'subject' => Craft::t('stub', 'Booking cancelled — {{referenceNumber}}'),
                'body' => Craft::t('stub', "Hi {{customerName}},\n\nYour booking {{referenceNumber}} for {{serviceName}} on {{dateFormatted}} at {{timeFormatted}} has been cancelled.\n\nIf you have any questions, please contact us."),
            ],
        ];
    }

    private function _registerEmailMessages(): void
    {
        Event::on(
            \craft\services\SystemMessages::class,
            \craft\services\SystemMessages::EVENT_REGISTER_MESSAGES,
            function(\craft\events\RegisterEmailMessagesEvent $event) {
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
}
