<?php

declare(strict_types=1);

namespace justinholtweb\owl;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\elements\Ticket;
use justinholtweb\owl\gql\EventQueries;
use justinholtweb\owl\gql\interfaces\EventInterface;
use justinholtweb\owl\models\Settings;
use justinholtweb\owl\services\Calendars;
use justinholtweb\owl\services\Events;
use justinholtweb\owl\services\Ics;
use justinholtweb\owl\services\Occurrences;
use justinholtweb\owl\services\Recurrence;
use justinholtweb\owl\services\Tickets;
use justinholtweb\owl\web\twig\CraftVariableBehavior;
use yii\base\Event as YiiEvent;

/**
 * Owl — events & calendar plugin for Craft CMS 5.
 *
 * @method static Owl getInstance()
 * @method Settings getSettings()
 * @property-read Calendars $calendars
 * @property-read Events $events
 * @property-read Ics $ics
 * @property-read Occurrences $occurrences
 * @property-read Recurrence $recurrence
 * @property-read Tickets $tickets
 */
class Owl extends Plugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.0.2';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /**
     * When true, Owl is running as an internal module mounted inside a host bundle plugin
     * rather than installed as a standalone plugin. In that mode Owl boots its feature
     * wiring but leaves control-panel "chrome" (nav, settings screen, its own permission
     * heading) to the host, which unifies it with the other bundled plugins.
     *
     * Default false → standalone behavior is unchanged.
     */
    public bool $mountedUnderShowtime = false;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'calendars' => Calendars::class,
                'events' => Events::class,
                'ics' => Ics::class,
                'occurrences' => Occurrences::class,
                'recurrence' => Recurrence::class,
                'tickets' => Tickets::class,
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
     * Functionality that must run in BOTH modes (standalone and mounted under a host).
     */
    private function bootFeatures(): void
    {
        $this->attachEventHandlers();
        $this->registerProjectConfigHandlers();
    }

    /**
     * Control-panel chrome that only applies when Owl is installed as its own plugin.
     *
     * Owl's nav and settings page are served via hasCpSection/hasCpSettings +
     * getCpNavItem()/settingsHtml(), which Craft only invokes for an installed plugin — so
     * there is nothing to unwire here. Kept so all bundled plugins share one mount shape.
     */
    private function bootChrome(): void
    {
    }

    /**
     * The permissions Owl defines, keyed by permission name.
     *
     * Exposed so a host bundle can list them under its own single heading rather than
     * showing one heading per bundled plugin. The keys are the contract — controllers, nav
     * items and user groups all reference them — so they never change between modes.
     */
    public static function permissionDefinitions(): array
    {
        return [
            'owl-manageEvents' => ['label' => Craft::t('owl', 'Manage events')],
            'owl-manageCalendars' => ['label' => Craft::t('owl', 'Manage calendars')],
        ];
    }

    /**
     * Refuse to install alongside a host bundle that already includes Owl.
     *
     * Both would register the Event element type and share the `owl_*` tables, and
     * uninstalling either would then drop the other's data. Skipped when the host is
     * installing Owl *as* a mounted module — that call is this method running with
     * $mountedUnderShowtime already true.
     */
    protected function beforeInstall(): void
    {
        if (!$this->mountedUnderShowtime && Craft::$app->getPlugins()->isPluginInstalled('showtime')) {
            throw new \yii\base\Exception(
                'Owl is already included in the Showtime bundle, which is installed on this site. ' .
                'Installing it separately would register a second Event element type and collide ' .
                'on the owl_* tables. Use Showtime’s bundled copy instead.'
            );
        }
    }

    /**
     * Whether the active edition includes Commerce ticketing and other Pro features.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Whether Commerce is installed so the Pro ticketing layer can boot.
     */
    public function commerceAvailable(): bool
    {
        return $this->isPro()
            && Craft::$app->getPlugins()->isPluginInstalled('commerce');
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = Craft::t('owl', 'Owl');
        $nav['url'] = 'owl/events';
        $nav['subnav'] = [];

        $user = Craft::$app->getUser();

        if ($user->checkPermission('owl-manageEvents')) {
            $nav['subnav']['events'] = [
                'label' => Craft::t('owl', 'Events'),
                'url' => 'owl/events',
            ];
        }

        if ($user->checkPermission('owl-manageCalendars')) {
            $nav['subnav']['calendars'] = [
                'label' => Craft::t('owl', 'Calendars'),
                'url' => 'owl/calendars',
            ];
        }

        if ($user->getIsAdmin()) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('owl', 'Settings'),
                'url' => 'settings/plugins/owl',
            ];
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('owl/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerProjectConfigHandlers(): void
    {
        $calendars = $this->calendars;

        Craft::$app->getProjectConfig()
            ->onAdd(Calendars::CONFIG_CALENDARS_KEY . '.{uid}', [$calendars, 'handleChangedCalendar'])
            ->onUpdate(Calendars::CONFIG_CALENDARS_KEY . '.{uid}', [$calendars, 'handleChangedCalendar'])
            ->onRemove(Calendars::CONFIG_CALENDARS_KEY . '.{uid}', [$calendars, 'handleDeletedCalendar']);

        YiiEvent::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            function(RebuildConfigEvent $event) use ($calendars) {
                $event->config[Calendars::CONFIG_CALENDARS_KEY] = $calendars->rebuildProjectConfig();
            }
        );
    }

    private function attachEventHandlers(): void
    {
        $commerceInstalled = Craft::$app->getPlugins()->isPluginInstalled('commerce');

        // Register the Event element type (and the Ticket purchasable element when Commerce exists).
        YiiEvent::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) use ($commerceInstalled) {
                $event->types[] = Event::class;
                if ($commerceInstalled) {
                    $event->types[] = Ticket::class;
                }
            }
        );

        // Register the Ticket purchasable type with Commerce.
        if ($commerceInstalled) {
            YiiEvent::on(
                \craft\commerce\services\Purchasables::class,
                \craft\commerce\services\Purchasables::EVENT_REGISTER_PURCHASABLE_ELEMENT_TYPES,
                function(RegisterComponentTypesEvent $event) {
                    $event->types[] = Ticket::class;
                }
            );
        }

        // Control panel routes.
        YiiEvent::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) use ($commerceInstalled) {
                $event->rules['owl'] = 'owl/events/index';
                $event->rules['owl/events'] = 'owl/events/index';
                $event->rules['owl/events/new'] = 'owl/events/edit';
                $event->rules['owl/events/<eventId:\d+>'] = 'owl/events/edit';
                $event->rules['owl/calendars'] = 'owl/calendars/index';
                $event->rules['owl/calendars/new'] = 'owl/calendars/edit';
                $event->rules['owl/calendars/<calendarId:\d+>'] = 'owl/calendars/edit';

                if ($commerceInstalled) {
                    $event->rules['owl/events/<eventId:\d+>/tickets'] = 'owl/tickets/index';
                }
            }
        );

        // Front-end feeds (FullCalendar JSON + ICS).
        YiiEvent::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['owl/events.json'] = 'owl/feed/events';
                $event->rules['owl/calendar/<handle:{handle}>.ics'] = 'owl/feed/calendar';
                $event->rules['owl/event/<eventId:\d+>.ics'] = 'owl/feed/event';
            }
        );

        // Expose craft.owl.* in Twig.
        YiiEvent::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(YiiEvent $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->attachBehavior('owl', CraftVariableBehavior::class);
            }
        );

        // GraphQL.
        YiiEvent::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            function(RegisterGqlTypesEvent $event) {
                $event->types[] = EventInterface::class;
            }
        );

        YiiEvent::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge($event->queries, EventQueries::getQueries());
            }
        );

        YiiEvent::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $event->queries['Owl'] = [
                    'owl.events:read' => ['label' => Craft::t('owl', 'View Owl events')],
                ];
            }
        );

        // User-group permissions. Mounted, the host registers these under one combined
        // heading alongside the other bundled plugins'.
        if (!$this->mountedUnderShowtime) {
            YiiEvent::on(
                UserPermissions::class,
                UserPermissions::EVENT_REGISTER_PERMISSIONS,
                function(RegisterUserPermissionsEvent $event) {
                    $event->permissions[] = [
                        'heading' => 'Owl',
                        'permissions' => static::permissionDefinitions(),
                    ];
                }
            );
        }

        // Commerce ticketing (Pro) boots lazily so the plugin degrades to calendar-only.
        if ($this->commerceAvailable()) {
            // Registered in Phase 6 — Ticket purchasable type.
        }
    }
}
