<?php

namespace justinholtweb\showtime;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\db\MigrationManager;
use craft\events\PluginEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\mail\Mailer;
use craft\services\Plugins;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\headcount\events\MatchGateRuleEvent;
use justinholtweb\headcount\events\RegisterGateTargetsEvent;
use justinholtweb\headcount\services\Gating as HeadcountGating;
use justinholtweb\owl\controllers\FeedController as OwlFeedController;
use justinholtweb\owl\events\FeedItemsEvent;
use justinholtweb\showtime\models\Settings;
use justinholtweb\showtime\services\Adoption;
use justinholtweb\showtime\services\CalendarFeed;
use justinholtweb\showtime\services\Dashboard;
use justinholtweb\showtime\services\Gates;
use justinholtweb\showtime\services\Notifications;
use justinholtweb\showtime\services\People;
use justinholtweb\showtime\services\Perks;
use justinholtweb\showtime\services\ProviderCalendars;
use justinholtweb\showtime\services\StripeWebhooks;
use justinholtweb\showtime\twig\ShowtimeVariable;
use justinholtweb\stub\events\BookingEvent;
use justinholtweb\stub\events\BusyIntervalsEvent;
use justinholtweb\stub\services\Availability as StubAvailability;
use justinholtweb\stub\services\Bookings as StubBookings;
use ReflectionClass;
use yii\base\Event;
use yii\mail\MailEvent;

/**
 * Showtime — events, bookings, and memberships for Craft CMS in one plugin.
 *
 * Showtime is a "host" plugin: it owns the single Craft plugin handle/license, and it
 * mounts the Owl, Stub, and Headcount plugins as internal Yii sub-modules. Each mounted
 * module keeps its own namespace, service container, templates, controllers, DB tables
 * and migration track — and because none of them is registered with Craft's Plugins
 * service, none requires its own license; only Showtime's is enforced.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Adoption $adoption
 * @property-read Dashboard $dashboard
 * @property-read CalendarFeed $calendarFeed
 * @property-read Gates $gates
 * @property-read Notifications $notifications
 * @property-read People $people
 * @property-read Perks $perks
 * @property-read ProviderCalendars $providerCalendars
 * @property-read StripeWebhooks $stripeWebhooks
 */
class Plugin extends BasePlugin
{
    /**
     * Sub-plugin handle => plugin class, in install order. Uninstall runs in reverse.
     */
    public const MODULES = [
        'stub' => \justinholtweb\stub\Plugin::class,
        'headcount' => \justinholtweb\headcount\Headcount::class,
        'owl' => \justinholtweb\owl\Owl::class,
    ];

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * Handle => mounted sub-module plugin instance, in MODULES order.
     *
     * @var BasePlugin[]
     */
    private array $mounted = [];

    public static function config(): array
    {
        return [
            'components' => [
                'adoption' => Adoption::class,
                'dashboard' => Dashboard::class,
                'calendarFeed' => CalendarFeed::class,
                'gates' => Gates::class,
                'notifications' => Notifications::class,
                'people' => People::class,
                'perks' => Perks::class,
                'providerCalendars' => ProviderCalendars::class,
                'stripeWebhooks' => StripeWebhooks::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->mountModules();
        $this->registerVariable();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerGlue();

        // Adoption starts inside the Install migration but can only finish once Craft has
        // committed the install and written `plugins.showtime` to project config.
        Event::on(Plugins::class, Plugins::EVENT_AFTER_INSTALL_PLUGIN, function(PluginEvent $event) {
            if ($event->plugin === $this) {
                $this->adoption->finalize();
            }
        });

        Craft::info('Showtime plugin loaded', __METHOD__);
    }

    /**
     * The mounted sub-modules, in install order.
     *
     * @return BasePlugin[]
     */
    public function getMountedModules(): array
    {
        return $this->mounted;
    }

    /**
     * Access a mounted sub-module by handle (e.g. $showtime->getModuleByHandle('stub')).
     */
    public function getModuleByHandle(string $handle): ?BasePlugin
    {
        return $this->mounted[$handle] ?? null;
    }

    /**
     * One nav item for the whole bundle.
     *
     * The mounted modules keep their own CP routes — rewriting every cpUrl() in three
     * plugins would be churn for no gain, and it keeps standalone and bundled behaviour
     * identical — so this is purely a front door onto them. Each entry is gated by the
     * permission its own module registers.
     */
    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = Craft::t('showtime', 'Showtime');

        $user = Craft::$app->getUser();

        $candidates = [
            'events' => ['Events', 'owl/events', 'owl-manageEvents'],
            'calendars' => ['Calendars', 'owl/calendars', 'owl-manageCalendars'],
            'bookings' => ['Bookings', 'stub/bookings', 'stub:viewBookings'],
            'calendar' => ['Calendar', 'stub/calendar', 'stub:viewBookings'],
            'services' => ['Services', 'stub/services', 'stub:manageServices'],
            'providers' => ['Providers', 'stub/providers', 'stub:manageProviders'],
            'members' => ['Members', 'headcount/subscriptions', 'headcount-manageSubscriptions'],
            'people' => ['People', 'showtime/people', 'stub:viewBookings'],
            'perks' => ['Member perks', 'showtime/perks', 'headcount-managePlans'],
            'provider-calendars' => ['Provider calendars', 'showtime/provider-calendars', 'stub:manageProviders'],
            'plans' => ['Plans', 'headcount/plans', 'headcount-managePlans'],
            'reports' => ['Reports', 'headcount/reports', 'headcount-viewReports'],
        ];

        $subnav = [];

        // The dashboard shows both halves of the bundle, so either side's permission earns
        // a look at it — the panels within are gated individually.
        if ($user->checkPermission('stub:viewBookings') || $user->checkPermission('headcount-manageSubscriptions')) {
            $subnav['dashboard'] = ['label' => Craft::t('showtime', 'Dashboard'), 'url' => 'showtime'];
        }

        foreach ($candidates as $key => [$label, $url, $permission]) {
            if ($user->checkPermission($permission)) {
                $subnav[$key] = ['label' => Craft::t('showtime', $label), 'url' => $url];
            }
        }

        if ($user->getIsAdmin()) {
            $subnav['notifications'] = [
                'label' => Craft::t('showtime', 'Notifications'),
                'url' => 'showtime/notifications',
            ];
            $subnav['settings'] = [
                'label' => Craft::t('showtime', 'Settings'),
                'url' => 'settings/plugins/showtime',
            ];
        }

        if ($subnav === []) {
            // Nothing this user may reach — don't show an item that 403s on click.
            return null;
        }

        $nav['subnav'] = $subnav;
        $nav['url'] = reset($subnav)['url'];

        return $nav;
    }

    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * The composite settings screen: the shared groups that are the point of the bundle,
     * plus each mounted module's own settings fields inlined under its handle.
     *
     * Stub's settings were served by Craft's plugin-settings page, which never fires for a
     * mounted module — so without this, they'd be unreachable. Its template is a fields-only
     * fragment, so wrapping it in {% namespace 'stub' %} lands its inputs at
     * settings[stub][…], exactly where Settings::$stub expects them. Headcount ships its own
     * CP settings routes, which do work when mounted, so it's linked rather than inlined.
     */
    protected function settingsHtml(): ?string
    {
        $stub = $this->getModuleByHandle('stub');

        return Craft::$app->getView()->renderTemplate('showtime/settings/_index', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'stubSettings' => $stub?->getSettings(),
            'stubPlugin' => $stub,
            'hasHeadcount' => $this->getModuleByHandle('headcount') !== null,
        ]);
    }

    /**
     * Bring every mounted module's schema up to date. Idempotent.
     *
     * Three cases, and a module can be in any of them on a given site:
     *   - **installed standalone** → adopt it in place (no data movement), then apply any
     *     migrations the vendored copy adds on top;
     *   - **already migrated under this host** (its `plugin:<handle>` track has history) →
     *     just apply what's new;
     *   - **brand new** → run its Install migration.
     *
     * Called from Showtime's Install migration and again from the thin sync migration each
     * release adds when a sub-plugin gains a migration or a new module joins the bundle.
     */
    public function syncModules(): void
    {
        foreach ($this->getMountedModules() as $handle => $module) {
            $migrator = $module->getMigrator();

            if ($this->adoption->isInstalledStandalone($handle)) {
                echo "    > adopting the existing $handle install (data left in place) ...\n";
                $this->adoption->adopt($handle, $module->schemaVersion);
                $migrator->up();
                continue;
            }

            if ($migrator->getMigrationHistory(1) !== []) {
                echo "    > migrating mounted module: $handle ...\n";
                $migrator->up();
                continue;
            }

            echo "    > installing mounted module: $handle ...\n";
            $module->install();
        }

        // On a fresh install, finalize() has to wait for EVENT_AFTER_INSTALL_PLUGIN: Craft
        // writes the whole `plugins.showtime` node after install() returns, clobbering
        // anything written before that. When a module is adopted during an *update*
        // migration instead — a new module joining the bundle on a site that already runs it
        // standalone — that event never fires, and nothing is going to overwrite us, so it
        // has to finish here or the adopted plugin's config node and settings are stranded.
        //
        // `isPluginInstalled('showtime')` is the discriminator: during the Install migration
        // Craft hasn't yet added the host to its stored plugin info, so it reads false.
        if ($this->adoption->hasPendingWork() && Craft::$app->getPlugins()->isPluginInstalled('showtime')) {
            $this->adoption->finalize();
        }
    }

    /**
     * Persist a mounted module's settings into the host's per-module slice.
     *
     * Injected into modules that own their settings screen (see mount()), so their existing
     * "save settings" controller keeps working verbatim in both modes.
     */
    public function saveModuleSettings(string $handle, array $moduleSettings): bool
    {
        $settings = $this->getSettings();
        $settings->$handle = $settings->withoutShared($handle, $moduleSettings);

        if (!Craft::$app->getPlugins()->savePluginSettings($this, $settings->toArray())) {
            return false;
        }

        $this->getModuleByHandle($handle)?->setSettings($moduleSettings);

        return true;
    }

    /**
     * `craft.showtime` — only what exists because the three ship together. Each mounted
     * module still registers its own variable.
     */
    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('showtime', ShowtimeVariable::class);
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['showtime'] = 'showtime/dashboard/index';
                $event->rules['showtime/dashboard'] = 'showtime/dashboard/index';
                $event->rules['showtime/perks'] = 'showtime/perks/index';
                $event->rules['showtime/perks/new'] = 'showtime/perks/edit';
                $event->rules['showtime/perks/<perkId:\\d+>'] = 'showtime/perks/edit';
                $event->rules['showtime/provider-calendars'] = 'showtime/provider-calendars/index';
                $event->rules['showtime/notifications'] = 'showtime/notifications/index';
                $event->rules['showtime/people'] = 'showtime/people/index';
            }
        );
    }

    /**
     * One "Showtime" permission heading instead of one per bundled plugin.
     *
     * The permission *keys* are unchanged — they're the module's own, and the modules' own
     * controllers and nav still check them — so nothing about authorization changes; only
     * where they appear in the user-permissions UI. Each mounted module skips registering
     * its own heading (see its `_registerPermissions()`), which is why this has to put them
     * back.
     */
    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $permissions = [];

                foreach (self::MODULES as $class) {
                    if (method_exists($class, 'permissionDefinitions')) {
                        $permissions += $class::permissionDefinitions();
                    }
                }

                if ($permissions !== []) {
                    $event->permissions[] = [
                        'heading' => Craft::t('showtime', 'Showtime'),
                        'permissions' => $permissions,
                    ];
                }
            }
        );
    }

    /**
     * The cross-module behaviour that only exists because these plugins ship together.
     *
     * Deliberately all listeners on the modules' own events: the glue can be as opinionated
     * as it likes without any of the three learning about the others, which is what keeps
     * them shipping standalone.
     */
    private function registerGlue(): void
    {
        $this->registerGatingGlue();

        // One from-name and from-address for everything the bundle sends, entered once.
        // Hooked to the mailer rather than injected into each module, so it covers every
        // send path — including ones added later — and none of the three learns about the
        // host. See Notifications::applyFromIdentity().
        Event::on(
            Mailer::class,
            Mailer::EVENT_BEFORE_PREP,
            function(MailEvent $event) {
                $this->notifications->applyFromIdentity($event);
            }
        );

        if ($this->getModuleByHandle('stub') === null) {
            return;
        }

        // A member's plan changes what they pay — applied before Stub derives the Stripe
        // amount from the booking price, so it's what they're charged, not just what they
        // see. Also enforces members-only services, since a crafted POST would otherwise
        // walk straight past a hidden service.
        Event::on(
            StubBookings::class,
            StubBookings::EVENT_BEFORE_SAVE_BOOKING,
            function(BookingEvent $event) {
                if (!$event->isNew) {
                    return;
                }

                if (!$this->perks->applyToBooking($event->booking)) {
                    $event->isValid = false;
                    $event->booking->addError(
                        'serviceId',
                        Craft::t('showtime', 'This service is only available to members.')
                    );
                }
            }
        );

        if ($this->getModuleByHandle('owl') === null) {
            return;
        }

        // An event a provider is running blocks their appointment slots. Neither plugin can
        // see this on its own — Owl doesn't know what a provider is, Stub doesn't know what
        // an event is — so the host states the link and feeds one into the other.
        Event::on(
            StubAvailability::class,
            StubAvailability::EVENT_DEFINE_BUSY_INTERVALS,
            function(BusyIntervalsEvent $event) {
                $this->providerCalendars->addEventOccurrences($event);
            }
        );

        // Ticket pricing lives in Commerce, so member perks on tickets hook Commerce's line
        // items rather than the perks engine's own path: an Owl Ticket is a Purchasable with
        // no price column to adjust, and mutating the purchasable would change it for every
        // order rather than this member's.
        if (Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            Event::on(
                \craft\commerce\services\LineItems::class,
                \craft\commerce\services\LineItems::EVENT_POPULATE_LINE_ITEM,
                function(\craft\commerce\events\LineItemEvent $event) {
                    $this->perks->applyToLineItem($event->lineItem);
                }
            );

            // Populating can't refuse, so members-only tickets are enforced where a cart can
            // actually be stopped.
            Event::on(
                \craft\commerce\elements\Order::class,
                \craft\commerce\elements\Order::EVENT_BEFORE_ADD_LINE_ITEM,
                function(\craft\commerce\events\AddLineItemEvent $event) {
                    if (!$this->perks->applyToLineItem($event->lineItem)) {
                        $event->isValid = false;
                    }
                }
            );
        }

        // Tickets for an event the buyer isn't allowed to see aren't buyable either.
        // Registered next to the perks guard because they answer the same question from
        // different directions — see Gates for where the line between them sits.
        if (Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            Event::on(
                \craft\commerce\elements\Order::class,
                \craft\commerce\elements\Order::EVENT_BEFORE_ADD_LINE_ITEM,
                function(\craft\commerce\events\AddLineItemEvent $event) {
                    if (!$this->gates->guardTicketPurchase($event->lineItem)) {
                        $event->isValid = false;
                    }
                }
            );
        }

        // Gated events drop out of the feed *before* bookings are added to it — the two
        // handlers run in registration order, and bookings carry their own permission check
        // and their own ID space, so they must not be run through the event gate.
        Event::on(
            OwlFeedController::class,
            OwlFeedController::EVENT_DEFINE_FEED_ITEMS,
            function(FeedItemsEvent $event) {
                $this->gates->filterFeed($event);
            }
        );

        // …and the reverse: bookings show up in the events feed, so staff get one calendar
        // instead of two screens. Owl's feed is anonymous, so the handler gates on the
        // viewer's permissions — see CalendarFeed::addBookings().
        Event::on(
            OwlFeedController::class,
            OwlFeedController::EVENT_DEFINE_FEED_ITEMS,
            function(FeedItemsEvent $event) {
                $this->calendarFeed->addBookings($event);
            }
        );
    }

    /**
     * Point Headcount's access rules at the rest of the bundle.
     *
     * Independent of the other glue: gating needs Headcount and nothing else, and each
     * target registers itself only if its own module is mounted. Registered unconditionally
     * so a rule written against events keeps its meaning even on a request that never
     * touches Owl.
     */
    private function registerGatingGlue(): void
    {
        if ($this->getModuleByHandle('headcount') === null) {
            return;
        }

        Event::on(
            HeadcountGating::class,
            HeadcountGating::EVENT_REGISTER_GATE_TARGETS,
            function(RegisterGateTargetsEvent $event) {
                $this->gates->registerTargets($event);
            }
        );

        Event::on(
            HeadcountGating::class,
            HeadcountGating::EVENT_MATCH_GATE_RULE,
            function(MatchGateRuleEvent $event) {
                $this->gates->matchRule($event);
            }
        );
    }

    private function mountModules(): void
    {
        foreach (self::MODULES as $handle => $class) {
            $this->mounted[$handle] = $this->mount($class, $handle, $this->mountConfig($handle));
        }
    }

    /**
     * Per-module construction config beyond the common contract.
     *
     * Owl ships lite/pro editions; the bundle includes Pro, and Craft never license-checks
     * a mounted module, so the edition has to be asserted here.
     */
    private function mountConfig(string $handle): array
    {
        return match ($handle) {
            'owl' => ['edition' => 'pro'],
            default => [],
        };
    }

    /**
     * Instantiate a plugin class as an internal mounted module.
     *
     * craft\base\Plugin's constructor already does part of what Craft's Plugins service
     * would do for an installed plugin — it calls static::setInstance($this) (so the
     * class's getInstance() resolves), sets the default controllerNamespace, registers the
     * CP template root from basePath, and sets the i18n category from the id. The id we
     * pass IS the handle (Craft exposes `handle` as a read-only alias of the module id, so
     * it must NOT be passed in config). What Craft's Plugins service does that we have to
     * reproduce here (verified against craft\services\Plugins::createPlugin() and
     * ::_setPluginMigrator()):
     *
     *   1. merge the plugin's static config() — notably its `components` service map,
     *      without which e.g. $stub->bookings never resolves;
     *   2. supply `settings`, which Craft merges into the *construction* config from the
     *      plugins table + config/<handle>.php. getSettings() alone only ever returns
     *      defaults, so a mounted module would silently run unconfigured;
     *   3. set `isInstalled` (and `edition`, where the plugin has editions);
     *   4. set the `migrator` component — Craft sets this on the plugin from outside, so
     *      without it getMigrator() throws and nothing can install or migrate;
     *   5. setModule() so `actions/<handle>/*` controller routing resolves to it.
     *
     * Plus `mountedUnderShowtime`, so the plugin boots its features but skips the
     * standalone control-panel chrome that the host owns.
     *
     * @param class-string<BasePlugin> $class
     */
    private function mount(string $class, string $handle, array $extra = []): BasePlugin
    {
        // Defensive: if the plugin somehow already exists (e.g. installed standalone
        // alongside Showtime — a state the co-install guard should prevent), reuse it
        // rather than double-constructing.
        $existing = $class::getInstance();
        if ($existing !== null) {
            return $existing;
        }

        $config = array_merge($class::config(), $extra, [
            'mountedUnderShowtime' => true,
            'isInstalled' => true,
            'settings' => $this->getSettings()->forModule($handle),
        ]);

        // Modules with their own settings screen (rather than Craft's plugin settings page)
        // can't reach Craft's Plugins service to save — the host owns their storage.
        // Every webhook is verified and routed the same way regardless of which URL Stripe
        // was pointed at, so a site that configured a module's own endpoint before bundling
        // behaves identically to one using the bundle's.
        if (property_exists($class, 'stripeWebhookRouter')) {
            $config['stripeWebhookRouter'] = function(string $payload, string $sigHeader): bool {
                [$handled] = $this->stripeWebhooks->handle($payload, $sigHeader);
                return $handled;
            };
        }

        if (property_exists($class, 'settingsSaver')) {
            $config['settingsSaver'] = fn(array $settings): bool => $this->saveModuleSettings($handle, $settings);
        }

        /** @var BasePlugin $module */
        $module = new $class($handle, Craft::$app, $config);

        $this->setMigrator($module, $handle);

        Craft::$app->setModule($handle, $module);

        return $module;
    }

    /**
     * Mirror of craft\services\Plugins::_setPluginMigrator().
     *
     * The track deliberately matches the standalone plugin's (`plugin:<handle>`), so a
     * site that adopts Showtime after running the standalone keeps its migration history
     * and its data.
     */
    private function setMigrator(BasePlugin $module, string $handle): void
    {
        $namespace = (new ReflectionClass($module))->getNamespaceName();

        $module->set('migrator', [
            'class' => MigrationManager::class,
            'track' => "plugin:$handle",
            'migrationNamespace' => ($namespace ? $namespace . '\\' : '') . 'migrations',
            'migrationPath' => $module->getBasePath() . DIRECTORY_SEPARATOR . 'migrations',
        ]);
    }
}
