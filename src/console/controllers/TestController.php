<?php

namespace justinholtweb\showtime\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Console;
use craft\web\View;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\models\AccessRule;
use justinholtweb\headcount\models\Plan;
use justinholtweb\headcount\widgets\MembershipOverviewWidget;
use justinholtweb\headcount\widgets\RevenueWidget;
use justinholtweb\owl\elements\Event as OwlEvent;
use justinholtweb\owl\elements\Ticket;
use justinholtweb\owl\models\Calendar;
use justinholtweb\showtime\models\Perk;
use justinholtweb\showtime\Plugin;
use justinholtweb\showtime\services\Gates;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\models\Provider;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\models\Settings as StubSettings;
use yii\console\ExitCode;

/**
 * Integration harness for the mount contract.
 *
 * markhuot/craft-pest is incompatible with Craft 5.9 (see the Owl plugin's notes), and the
 * things that can break under a mount are all *stateful* — they only fail against a real
 * Craft + MySQL. So the contract is asserted here, in a console command that runs inside
 * the app: `php craft showtime/test/run`. Exits non-zero on any failure, so it works in CI.
 */
class TestController extends Controller
{
    private int $passed = 0;
    private int $failed = 0;

    /**
     * Assert the full mount contract for every mounted module.
     */
    public function actionRun(): int
    {
        $showtime = Plugin::getInstance();

        $this->heading('Host');

        $this->check(
            'Showtime is installed',
            Craft::$app->getPlugins()->isPluginInstalled('showtime'),
        );

        // Only the handles Showtime actually mounts — a plugin that isn't part of the bundle
        // is free to stay installed standalone alongside it.
        $installedSubPlugins = array_values(array_filter(
            array_keys(Plugin::MODULES),
            fn(string $handle) => Craft::$app->getPlugins()->isPluginInstalled($handle),
        ));

        $this->check(
            'no mounted sub-plugin is separately installed (⇒ no separate license)',
            $installedSubPlugins === [],
            'installed: ' . implode(', ', $installedSubPlugins),
        );

        $this->check(
            'mounted modules: ' . implode(', ', array_keys($showtime->getMountedModules())),
            $showtime->getMountedModules() !== [],
        );

        // The host's own tables must be created by Install.php, never by a later migration:
        // Craft runs the Install migration and then marks every *other* migration as applied
        // WITHOUT running it, so a table added incrementally never exists on a clean install.
        // That is not theoretical — both of these were missing from every fresh install for
        // several releases, because no one installed from scratch. This check fails on the
        // one site configuration that would catch it.
        $missingTables = array_values(array_filter(
            ['{{%showtime_perks}}', '{{%showtime_provider_calendars}}'],
            fn(string $table) => !Craft::$app->getDb()->tableExists($table),
        ));

        $this->check(
            'every table the host owns exists (⇒ Install.php creates them, not a migration)',
            $missingTables === [],
            'missing: ' . implode(', ', $missingTables),
        );

        foreach (array_keys($showtime->getMountedModules()) as $handle) {
            $this->testMount($handle);
        }

        if (isset($showtime->getMountedModules()['stub'])) {
            $this->testStub();
        }

        if (isset($showtime->getMountedModules()['headcount'])) {
            $this->testHeadcount();
        }

        if (isset($showtime->getMountedModules()['owl'])) {
            $this->testOwl();
        }

        $this->testSharedSettings();
        $this->testPerks();
        $this->testProviderCalendars();
        $this->testGating();
        $this->testNotifications();
        $this->testPeople();

        $this->reportData();

        $this->stdout("\n");
        $this->stdout("$this->passed passed, $this->failed failed\n", $this->failed ? Console::FG_RED : Console::FG_GREEN);

        return $this->failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Write a distinctive per-module override into Showtime's settings, so a follow-up
     * `showtime/test/run` proves host→module settings propagation across a real request.
     */
    public function actionSeedSettings(): int
    {
        $showtime = Plugin::getInstance();
        $settings = $showtime->getSettings();

        $settings->stripeSecretKey = 'sk_test_showtime_probe';
        $settings->stripeWebhookSecret = 'whsec_showtime_probe_secret';
        $settings->stub = ['minimumNotice' => 4242, 'pluginName' => 'Showtime Bookings'];

        if (!Craft::$app->getPlugins()->savePluginSettings($showtime, $settings->toArray())) {
            $this->stderr("Failed to save Showtime settings\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Seeded Showtime settings. Now re-run: php craft showtime/test/run\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Send one of the bundle's emails to an address, to see what actually arrives.
     *
     * The assertions in `run` cover the wiring; this covers the thing they can't — whether
     * the message renders and delivers the way the site is configured, shared sender and
     * email template included.
     *
     *     php craft showtime/test/send-email headcount_welcome me@example.com
     */
    public function actionSendEmail(string $key, string $to): int
    {
        $definitions = Plugin::getInstance()->notifications->definitions();

        if (!isset($definitions[$key])) {
            $this->stderr("Unknown message '$key'. Known: " . implode(', ', array_keys($definitions)) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $message = Craft::$app->getMailer()
            ->composeFromKey($key, array_fill_keys($definitions[$key]['variables'], 'sample'))
            ->setTo($to);

        if (!$message->send()) {
            $this->stderr("Send failed. Check the mailer settings and the logs.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Sent '$key' to $to.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * The parts of the contract that are identical for every mounted module.
     */
    private function testMount(string $handle): void
    {
        $showtime = Plugin::getInstance();
        $module = $showtime->getModuleByHandle($handle);

        $this->heading("Mount contract: $handle");

        $this->check('module instance exists', $module !== null);
        if ($module === null) {
            return;
        }

        $class = $module::class;

        $this->check(
            "$class::getInstance() resolves to the mounted instance",
            $class::getInstance() === $module,
        );

        $this->check(
            "app->getModule('$handle') resolves (⇒ actions/$handle/* routing)",
            Craft::$app->getModule($handle) === $module,
        );

        $this->check(
            'handle is the module id',
            $module->handle === $handle,
            "got: $module->handle",
        );

        $this->check(
            'mountedUnderShowtime = true (⇒ standalone CP chrome skipped)',
            $module->mountedUnderShowtime === true,
        );

        $this->check(
            'isInstalled = true',
            $module->isInstalled === true,
        );

        // 4. migrator — Craft's Plugins service sets this from outside; a mounted module
        // only has it because Showtime reproduces _setPluginMigrator().
        $migrator = $module->getMigrator();

        $this->check(
            "migrator track = plugin:$handle (same as standalone ⇒ adoption keeps history)",
            $migrator->track === "plugin:$handle",
            "got: $migrator->track",
        );

        $this->check(
            'migrator path points into src/modules/' . $handle,
            str_contains((string)$migrator->migrationPath, "src/modules/$handle/migrations"),
            'got: ' . $migrator->migrationPath,
        );

        $history = (new Query())
            ->select(['name'])
            ->from(\craft\db\Table::MIGRATIONS)
            ->where(['track' => "plugin:$handle"])
            ->column();

        $this->check(
            'migration history recorded (' . count($history) . ' rows)',
            $history !== [],
        );

        $this->check(
            'Install migration marked as applied',
            (bool)array_filter($history, fn($name) => str_ends_with((string)$name, 'Install')),
            'history: ' . implode(', ', array_map('strval', $history)),
        );

        // 2. settings — the module's live settings must equal what the host handed it.
        $injected = $showtime->getSettings()->forModule($handle);
        $live = $module->getSettings();

        $mismatched = [];
        foreach ($injected as $attribute => $value) {
            if (!property_exists($live, $attribute)) {
                continue;
            }
            if ($live->$attribute != $value) {
                $mismatched[] = $attribute;
            }
        }

        $this->check(
            'host settings reached the module (' . count($injected) . ' injected)',
            $mismatched === [],
            'mismatched: ' . implode(', ', $mismatched),
        );

        $this->check(
            'translation category resolves',
            Craft::t($handle, 'Showtime probe') !== '',
        );
    }

    /**
     * Stub-specific state: tables, services, element type, templates, and real CRUD.
     */
    private function testStub(): void
    {
        /** @var \justinholtweb\stub\Plugin $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        $this->heading('Stub: schema');

        $tables = [
            'stub_services', 'stub_providers', 'stub_provider_schedules',
            'stub_provider_breaks', 'stub_provider_blocked_dates', 'stub_provider_services',
            'stub_customers', 'stub_bookings', 'stub_payments',
        ];

        $missing = array_values(array_filter(
            $tables,
            fn(string $table) => Craft::$app->getDb()->getTableSchema('{{%' . $table . '}}', true) === null,
        ));

        $this->check(
            count($tables) . ' tables created by the mounted install migration',
            $missing === [],
            'missing: ' . implode(', ', $missing),
        );

        $this->heading('Stub: services');

        $components = ['services', 'providers', 'bookings', 'availability', 'customers', 'payments', 'emails'];
        $unresolved = $this->unresolvedComponents($stub, $components);

        $this->check(
            'all ' . count($components) . ' services resolve',
            $unresolved === [],
            'unresolved: ' . implode(', ', $unresolved),
        );

        $this->check(
            'settings model is Stub\'s own',
            $stub->getSettings() instanceof StubSettings,
        );

        $this->heading('Stub: element type + templates');

        $this->check(
            'Booking element type registered with Craft',
            in_array(Booking::class, Craft::$app->getElements()->getAllElementTypes(), true),
        );

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $resolved = $view->resolveTemplate('stub/frontend/booking-form');
        $settingsTemplate = $view->resolveTemplate('stub/settings');
        $view->setTemplateMode($oldMode);

        $this->check('frontend booking-form template resolves', $resolved !== false);
        $this->check('CP settings template resolves', $settingsTemplate !== false);

        $this->heading('Stub: CRUD through the mounted services');

        $service = new Service([
            'name' => 'Showtime probe service',
            'handle' => 'showtimeProbeService',
            'duration' => 30,
            'price' => 0,
        ]);
        $this->check('service saved', $stub->services->saveService($service) && $service->id !== null);

        $provider = new Provider([
            'name' => 'Showtime Probe',
            'handle' => 'showtimeProbe',
            'email' => 'probe@example.test',
            'serviceIds' => $service->id ? [$service->id] : [],
        ]);
        $this->check('provider saved', $stub->providers->saveProvider($provider) && $provider->id !== null);

        $customer = $stub->customers->findOrCreate('probe@example.test', 'Showtime', 'Probe');
        $this->check('customer created', $customer->id !== null);

        $booking = null;

        if ($service->id && $provider->id && $customer->id) {
            $start = new \DateTime('+3 days 15:00', new \DateTimeZone('UTC'));
            $end = (clone $start)->modify('+30 minutes');

            $booking = $stub->bookings->createBooking([
                'serviceId' => $service->id,
                'providerId' => $provider->id,
                'customerId' => $customer->id,
                'startDateTime' => $start->format('Y-m-d H:i:s'),
                'endDateTime' => $end->format('Y-m-d H:i:s'),
                'timezone' => 'UTC',
            ]);

            $this->check('booking element saved', $booking->id !== null, implode('; ', $booking->getErrorSummary(true)));
            $this->check(
                'booking has a reference number',
                $booking->referenceNumber !== '',
                "got: $booking->referenceNumber",
            );
            $this->check(
                'booking query finds it back',
                $booking->id !== null && Booking::find()->id($booking->id)->status(null)->one() !== null,
            );
        }

        // Cleanup — the harness must be re-runnable.
        if ($booking?->id) {
            Craft::$app->getElements()->deleteElement($booking, true);
        }
        if ($customer->id) {
            Craft::$app->getDb()->createCommand()->delete('{{%stub_customers}}', ['id' => $customer->id])->execute();
        }
        if ($provider->id) {
            Craft::$app->getDb()->createCommand()->delete('{{%stub_providers}}', ['id' => $provider->id])->execute();
        }
        if ($service->id) {
            Craft::$app->getDb()->createCommand()->delete('{{%stub_services}}', ['id' => $service->id])->execute();
        }

        $this->check(
            'probe rows cleaned up',
            $stub->customers->getCustomerByEmail('probe@example.test') === null,
        );
    }

    /**
     * Headcount-specific state: tables, services, element type, widgets, console commands,
     * gating, the settings-saver seam, and real CRUD.
     */
    private function testHeadcount(): void
    {
        /** @var \justinholtweb\headcount\Headcount $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        $this->heading('Headcount: schema');

        $tables = [
            'headcount_plans', 'headcount_subscriptions', 'headcount_access_rules',
            'headcount_drip_schedules', 'headcount_coupons', 'headcount_webhook_logs',
        ];

        $missing = array_values(array_filter(
            $tables,
            fn(string $table) => Craft::$app->getDb()->getTableSchema('{{%' . $table . '}}', true) === null,
        ));

        $this->check(
            count($tables) . ' tables present',
            $missing === [],
            'missing: ' . implode(', ', $missing),
        );

        $this->heading('Headcount: services');

        $components = [
            'plans', 'subscriptions', 'gating', 'stripe', 'paypal', 'webhooks',
            'drip', 'coupons', 'members', 'reporting', 'emails',
        ];

        $unresolved = $this->unresolvedComponents($headcount, $components);

        $this->check(
            'all ' . count($components) . ' services resolve',
            $unresolved === [],
            'unresolved: ' . implode(', ', $unresolved),
        );

        $this->heading('Headcount: registrations');

        $this->check(
            'Subscription element type registered',
            in_array(Subscription::class, Craft::$app->getElements()->getAllElementTypes(), true),
        );

        $widgetTypes = Craft::$app->getDashboard()->getAllWidgetTypes();
        $this->check(
            'both dashboard widgets registered',
            in_array(MembershipOverviewWidget::class, $widgetTypes, true)
                && in_array(RevenueWidget::class, $widgetTypes, true),
        );

        // Console commands are the one surface that only resolves if the module's
        // controllerNamespace survived the mount (Stub has none, so this is new here).
        $this->check(
            'console commands resolve (headcount/subscriptions, headcount/sync)',
            Craft::$app->createController('headcount/subscriptions/expire') !== false
                && Craft::$app->createController('headcount/sync/status') !== false,
        );

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $settingsTemplate = $view->resolveTemplate('headcount/settings/index');
        $sidebarTemplate = $view->resolveTemplate('headcount/_sidebar/entry-gating');
        $view->setTemplateMode($oldMode);

        $this->check('CP settings template resolves', $settingsTemplate !== false);
        $this->check('entry-gating sidebar template resolves', $sidebarTemplate !== false);

        $this->check(
            'craft.headcount.plugin resolves (replaces plugins.getPlugin, which is null when mounted)',
            (new \justinholtweb\headcount\twig\HeadcountVariable())->getPlugin() === $headcount,
        );

        $this->heading('Headcount: settings-saver seam');

        // Modules with their own settings screen can't reach Craft's Plugins service when
        // mounted; the host injects a writer. Round-trip a probe value and restore.
        $this->check('host injected a settingsSaver', $headcount->settingsSaver !== null);

        $settings = $headcount->getSettings();
        $original = $settings->toArray();
        $probe = array_merge($original, ['pricingUrl' => '/showtime-probe-pricing']);

        $saved = $headcount->saveSettings($probe);
        $this->check('saveSettings() succeeded', $saved);
        $this->check(
            'probe reached the live module',
            $headcount->getSettings()->pricingUrl === '/showtime-probe-pricing',
            'got: ' . $headcount->getSettings()->pricingUrl,
        );
        $this->check(
            'probe reached the host\'s per-module settings slice',
            (Plugin::getInstance()->getSettings()->headcount['pricingUrl'] ?? null) === '/showtime-probe-pricing',
        );

        $headcount->saveSettings($original);
        $this->check(
            'settings restored',
            $headcount->getSettings()->pricingUrl === ($original['pricingUrl'] ?? ''),
        );

        $this->heading('Headcount: gating + CRUD');

        $entry = \craft\elements\Entry::find()->status(null)->one();

        if ($entry !== null) {
            // The assertion is that it evaluates at all: gating reads JSON columns that have
            // historically been double-encoded, which used to fatal rather than return false.
            $error = null;
            try {
                $headcount->gating->evaluateAccess($entry, null);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $this->check('gating evaluates an entry without error', $error === null, (string)$error);
        } else {
            $this->check('gating skipped — no entries on this site', true);
        }

        $plan = new Plan([
            'name' => 'Showtime probe plan',
            'handle' => 'showtime-probe-plan',
            'price' => 10.0,
            'billingInterval' => 'month',
        ]);

        $this->check('plan saved', $headcount->plans->savePlan($plan) && $plan->id !== null, implode('; ', $plan->getErrorSummary(true)));
        $this->check(
            'plan reads back by handle',
            $plan->id !== null && $headcount->plans->getPlanByHandle('showtime-probe-plan')?->id === $plan->id,
        );

        if ($plan->id) {
            Craft::$app->getDb()->createCommand()->delete('{{%headcount_plans}}', ['id' => $plan->id])->execute();
        }

        // Checked against the DB, not the service — Plans caches its result set in-request.
        $this->check(
            'probe plan cleaned up',
            (new Query())->from('{{%headcount_plans}}')->where(['handle' => 'showtime-probe-plan'])->count() == 0,
        );
    }

    /**
     * Owl-specific state — the things no earlier module exercised: an asserted edition,
     * project-config-backed config with field layouts, GraphQL registration, and a Commerce
     * purchasable.
     */
    private function testOwl(): void
    {
        /** @var \justinholtweb\owl\Owl $owl */
        $owl = Plugin::getInstance()->getModuleByHandle('owl');

        $this->heading('Owl: schema + services');

        $tables = ['owl_calendars', 'owl_events', 'owl_occurrences', 'owl_exceptions', 'owl_tickets'];

        $missing = array_values(array_filter(
            $tables,
            fn(string $table) => Craft::$app->getDb()->getTableSchema('{{%' . $table . '}}', true) === null,
        ));

        $this->check(
            count($tables) . ' tables present',
            $missing === [],
            'missing: ' . implode(', ', $missing),
        );

        $components = ['calendars', 'events', 'ics', 'occurrences', 'recurrence', 'tickets'];
        $unresolved = $this->unresolvedComponents($owl, $components);

        $this->check(
            'all ' . count($components) . ' services resolve',
            $unresolved === [],
            'unresolved: ' . implode(', ', $unresolved),
        );

        $this->heading('Owl: edition + registrations');

        // Craft never license-checks a mounted module, so the edition is whatever the host
        // asserts at mount. The bundle includes Pro; without this, Owl silently runs Lite
        // and the ticketing layer never boots.
        $this->check('edition is pro (asserted by the host at mount)', $owl->is('pro'), "got: $owl->edition");
        $this->check('isPro() agrees', $owl->isPro());

        $elementTypes = Craft::$app->getElements()->getAllElementTypes();
        $this->check('Event element type registered', in_array(OwlEvent::class, $elementTypes, true));

        $commerce = Craft::$app->getPlugins()->isPluginInstalled('commerce');

        if ($commerce) {
            $this->check('Commerce present → Ticket purchasable registered', in_array(Ticket::class, $elementTypes, true));
            $this->check('commerceAvailable() true (pro + Commerce)', $owl->commerceAvailable());
        } else {
            $this->check('Commerce not installed — ticketing correctly dormant', !$owl->commerceAvailable());
        }

        // Proves the GraphQL handler is actually attached, not merely that the class exists.
        $schemaComponents = Craft::$app->getGql()->getAllSchemaComponents();
        $this->check(
            'GraphQL schema component owl.events:read registered',
            isset($schemaComponents['queries']['Owl']['owl.events:read']),
        );

        $this->check(
            'console commands resolve (owl/maintenance)',
            Craft::$app->createController('owl/maintenance/regenerate') !== false,
        );

        $this->heading('Owl: project config round-trip');

        // The one thing no other module exercises: config that lives in project config with
        // a field layout attached, written through PC handlers registered by a mounted module.
        $calendar = new Calendar([
            'name' => 'Showtime probe calendar',
            'handle' => 'showtimeProbeCalendar',
        ]);

        $saved = $owl->calendars->save($calendar);
        $this->check('calendar saved', $saved && $calendar->id !== null, implode('; ', $calendar->getErrorSummary(true)));

        if ($calendar->uid) {
            $this->check(
                'calendar reached project config',
                Craft::$app->getProjectConfig()->get('owl.calendars.' . $calendar->uid) !== null,
            );
        }

        if ($calendar->id) {
            $owl->calendars->deleteCalendarById($calendar->id);
        }

        $this->check(
            'probe calendar removed from project config',
            !$calendar->uid || Craft::$app->getProjectConfig()->get('owl.calendars.' . $calendar->uid) === null,
        );
    }

    /**
     * The bundle's headline promise: one Stripe account, entered once, reaching every module
     * that talks to Stripe — and *staying* live rather than being frozen into a per-module
     * copy the first time someone saves that module's settings screen.
     */
    private function testSharedSettings(): void
    {
        $showtime = Plugin::getInstance();
        $sharedKey = $showtime->getSettings()->stripeSecretKey;

        $this->heading('Shared settings');

        if ($sharedKey === '') {
            $this->check('no shared Stripe key configured — run showtime/test/seed-settings to exercise this', true);
            return;
        }

        foreach (['stub', 'headcount'] as $handle) {
            $module = $showtime->getModuleByHandle($handle);
            if ($module === null) {
                continue;
            }

            $resolved = $module->getSettings()->stripeSecretKey ?? null;

            $this->check(
                "$handle inherits the shared Stripe key",
                $resolved === $sharedKey,
                'got: ' . var_export($resolved, true),
            );
        }
    }

    /**
     * Member perks end to end — the bundle's headline feature.
     *
     * This is the assertion that matters commercially: a member with the right plan is
     * *charged* less for a booking, and a non-member is refused a members-only service. It
     * builds a whole scenario (plan → user → subscription → service → perk → booking) and
     * tears it down, so it can run repeatedly on a real site.
     */
    private function testPerks(): void
    {
        $showtime = Plugin::getInstance();
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = $showtime->getModuleByHandle('stub');
        /** @var \justinholtweb\headcount\Headcount|null $headcount */
        $headcount = $showtime->getModuleByHandle('headcount');

        $this->heading('Member perks (bundle glue)');

        if ($stub === null || $headcount === null) {
            $this->check('skipped — needs both bookings and memberships mounted', true);
            return;
        }

        $created = [];

        try {
            $plan = new Plan([
                'name' => 'Showtime perk probe plan',
                'handle' => 'showtime-perk-probe',
                'price' => 20.0,
                'billingInterval' => 'month',
            ]);
            $headcount->plans->savePlan($plan);
            $created['plan'] = $plan->id;

            $service = new Service([
                'name' => 'Showtime perk probe service',
                'handle' => 'showtimePerkProbeService',
                'duration' => 30,
                'price' => 100.0,
            ]);
            $stub->services->saveService($service);
            $created['service'] = $service->id;

            $provider = new Provider([
                'name' => 'Perk Probe Provider',
                'handle' => 'perkProbeProvider',
                'serviceIds' => [$service->id],
            ]);
            $stub->providers->saveProvider($provider);
            $created['provider'] = $provider->id;

            // 20% off, then $5 off → 100 becomes 75.
            $perk = new Perk([
                'planId' => $plan->id,
                'targetType' => Perk::TARGET_STUB_SERVICE,
                'targetId' => $service->id,
                'discountPercent' => 20.0,
                'discountAmount' => 5.0,
            ]);
            $this->check('perk saved', $showtime->perks->savePerk($perk), implode('; ', $perk->getErrorSummary(true)));
            $created['perk'] = $perk->id;

            $this->check('perk maths: 100 → 75 (20% then 5 off)', $perk->appliedTo(100.0) === 75.0, 'got: ' . $perk->appliedTo(100.0));
            $this->check('a discount never goes below zero', $perk->appliedTo(1.0) === 0.0);

            // A member: Craft user + active subscription + linked Stub customer.
            $member = new User(['username' => 'showtime-perk-member', 'email' => 'perk-member@example.test']);
            $memberSaved = Craft::$app->getElements()->saveElement($member);
            $created['member'] = $member->id;

            if (!$memberSaved || $member->id === null) {
                // Craft Solo allows exactly one user, so the rest of this scenario can't run
                // here. That's an edition limit, not a product failure — the pricing maths
                // above still ran, and the full scenario runs on any multi-user site.
                $this->check(
                    'membership scenario skipped — this Craft edition (' . Craft::$app->edition->name . ') can’t create additional users',
                    true,
                );
                return;
            }

            $subscription = $headcount->subscriptions->createSubscription([
                'userId' => $member->id,
                'planId' => $plan->id,
                'gateway' => 'manual',
                'status' => 'active',
            ]);
            $created['subscription'] = $subscription?->id;

            $this->check('probe subscription is active', $headcount->subscriptions->hasActiveSubscription($member->id));

            $memberCustomer = $stub->customers->findOrCreate('perk-member@example.test', 'Perk', 'Member');
            $memberCustomer->userId = $member->id;
            $stub->customers->saveCustomer($memberCustomer);
            $created['memberCustomer'] = $memberCustomer->id;

            $guest = $stub->customers->findOrCreate('perk-guest@example.test', 'Perk', 'Guest');
            $created['guestCustomer'] = $guest->id;

            // The actual test: book the same service as each, compare what gets stored.
            $memberBooking = $this->probeBooking($stub, $service->id, $provider->id, $memberCustomer->id);
            $created['memberBooking'] = $memberBooking?->id;

            $this->check(
                'member is charged the discounted price (75, not 100)',
                $memberBooking !== null && (float)$memberBooking->price === 75.0,
                'got: ' . ($memberBooking?->price ?? 'no booking'),
            );

            $guestBooking = $this->probeBooking($stub, $service->id, $provider->id, $guest->id);
            $created['guestBooking'] = $guestBooking?->id;

            $this->check(
                'non-member pays full price (100)',
                $guestBooking !== null && (float)$guestBooking->price === 100.0,
                'got: ' . ($guestBooking?->price ?? 'no booking'),
            );

            // Now make it members-only and confirm a non-member is actually refused, not
            // merely hidden — a crafted POST has to fail too.
            $perk->membersOnly = true;
            $showtime->perks->savePerk($perk);

            $refused = $this->probeBooking($stub, $service->id, $provider->id, $guest->id);
            $created['refusedBooking'] = $refused?->id;

            $this->check(
                'members-only service refuses a non-member booking',
                $refused === null || $refused->id === null,
                'booking was created anyway: ' . ($refused?->id ?? '-'),
            );

            $stillAllowed = $this->probeBooking($stub, $service->id, $provider->id, $memberCustomer->id);
            $created['memberBooking2'] = $stillAllowed?->id;

            $this->check(
                'members-only service still accepts a member',
                $stillAllowed !== null && $stillAllowed->id !== null,
            );
        } catch (\Throwable $e) {
            $this->check('perk scenario ran without error', false, $e->getMessage());
        } finally {
            $this->cleanUpPerkProbe($created);
        }

        $this->check(
            'probe rows cleaned up',
            (new Query())->from('{{%showtime_perks}}')->where(['targetType' => Perk::TARGET_STUB_SERVICE])
                ->andWhere(['in', 'targetId', (new Query())->select(['id'])->from('{{%stub_services}}')->where(['handle' => 'showtimePerkProbeService'])])
                ->count() == 0,
        );
    }

    private function probeBooking(mixed $stub, int $serviceId, int $providerId, int $customerId): ?Booking
    {
        $start = new \DateTime('+10 days 11:00', new \DateTimeZone('UTC'));
        $end = (clone $start)->modify('+30 minutes');

        return $stub->bookings->createBooking([
            'serviceId' => $serviceId,
            'providerId' => $providerId,
            'customerId' => $customerId,
            'startDateTime' => $start->format('Y-m-d H:i:s'),
            'endDateTime' => $end->format('Y-m-d H:i:s'),
            'timezone' => 'UTC',
        ]);
    }

    private function cleanUpPerkProbe(array $created): void
    {
        $db = Craft::$app->getDb();
        $elements = Craft::$app->getElements();

        foreach (['memberBooking', 'guestBooking', 'refusedBooking', 'memberBooking2'] as $key) {
            if (!empty($created[$key]) && ($booking = Booking::find()->id($created[$key])->status(null)->one())) {
                $elements->deleteElement($booking, true);
            }
        }

        if (!empty($created['subscription']) && ($sub = Subscription::find()->id($created['subscription'])->status(null)->one())) {
            $elements->deleteElement($sub, true);
        }

        if (!empty($created['member']) && ($user = Craft::$app->getUsers()->getUserById($created['member']))) {
            $elements->deleteElement($user, true);
        }

        foreach ([
            '{{%showtime_perks}}' => 'perk',
            '{{%stub_customers}}' => 'memberCustomer',
            '{{%stub_provider_services}}' => null,
        ] as $table => $key) {
            if ($key !== null && !empty($created[$key])) {
                $db->createCommand()->delete($table, ['id' => $created[$key]])->execute();
            }
        }

        foreach (['guestCustomer' => '{{%stub_customers}}', 'provider' => '{{%stub_providers}}', 'service' => '{{%stub_services}}', 'plan' => '{{%headcount_plans}}'] as $key => $table) {
            if (!empty($created[$key])) {
                $db->createCommand()->delete($table, ['id' => $created[$key]])->execute();
            }
        }
    }

    /**
     * Events a provider runs block their appointment slots.
     *
     * Asserted against the real availability engine rather than the glue in isolation: a
     * slot that exists before the event is linked must disappear once it is, and come back
     * when the link is removed.
     */
    private function testProviderCalendars(): void
    {
        $showtime = Plugin::getInstance();
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = $showtime->getModuleByHandle('stub');
        /** @var \justinholtweb\owl\Owl|null $owl */
        $owl = $showtime->getModuleByHandle('owl');

        $this->heading('Provider calendars (bundle glue)');

        if ($stub === null || $owl === null) {
            $this->check('skipped — needs both bookings and events mounted', true);
            return;
        }

        $created = [];

        try {
            $service = new Service([
                'name' => 'Showtime calendar probe service',
                'handle' => 'showtimeCalProbeService',
                'duration' => 60,
                'price' => 0,
            ]);
            $stub->services->saveService($service);
            $created['service'] = $service->id;

            $provider = new Provider([
                'name' => 'Calendar Probe Provider',
                'handle' => 'calProbeProvider',
                'timezone' => 'UTC',
                'serviceIds' => [$service->id],
            ]);
            $stub->providers->saveProvider($provider);
            $created['provider'] = $provider->id;

            // Available all day, every day, so the only thing that can remove a slot is the
            // event we're about to add.
            $schedules = [];
            for ($day = 0; $day <= 6; $day++) {
                $schedules[] = ['dayOfWeek' => $day, 'startTime' => '00:00', 'endTime' => '23:59', 'enabled' => true];
            }
            $stub->providers->saveSchedules($provider->id, $schedules);

            $calendar = new Calendar(['name' => 'Showtime probe calendar 2', 'handle' => 'showtimeCalProbe2']);
            $owl->calendars->save($calendar);
            $created['calendar'] = $calendar->id;

            // A day far enough out to clear any minimum-notice setting.
            $date = (new \DateTime('+20 days', new \DateTimeZone('UTC')))->format('Y-m-d');

            $before = $stub->availability->getAvailableSlots($service->id, $provider->id, $date, 'UTC');
            $this->check('provider has slots before any event is linked', $before !== [], 'got ' . count($before) . ' slots');

            // An event covering the whole probe day.
            $eventStart = new \DateTime($date . ' 00:00:00', new \DateTimeZone('UTC'));
            $eventEnd = (clone $eventStart)->modify('+23 hours 59 minutes');

            $db = Craft::$app->getDb();
            $now = \craft\helpers\Db::prepareDateForDb(new \DateTime());

            $owlEvent = new OwlEvent([
                'calendarId' => $calendar->id,
                'title' => 'Showtime probe event',
                'startDate' => $eventStart,
                'endDate' => $eventEnd,
                'timezone' => 'UTC',
            ]);
            Craft::$app->getElements()->saveElement($owlEvent);
            $created['event'] = $owlEvent->id;

            // Materialise one occurrence directly — the recurrence engine's job is tested in
            // Owl's own suite; what matters here is that an occurrence blocks a slot.
            $db->createCommand()->insert('{{%owl_occurrences}}', [
                'eventId' => $owlEvent->id,
                'startDate' => $eventStart->format('Y-m-d H:i:s'),
                'endDate' => $eventEnd->format('Y-m-d H:i:s'),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])->execute();

            // Not linked yet, so it must not block anything.
            $unlinked = $stub->availability->getAvailableSlots($service->id, $provider->id, $date, 'UTC');
            $this->check(
                'an event on an unlinked calendar does not block slots',
                count($unlinked) === count($before),
                'before ' . count($before) . ', now ' . count($unlinked),
            );

            $showtime->providerCalendars->setCalendarsForProvider($provider->id, [$calendar->id]);

            $linked = $stub->availability->getAvailableSlots($service->id, $provider->id, $date, 'UTC');
            $this->check(
                'once linked, the event blocks the provider’s slots',
                $linked === [],
                'still ' . count($linked) . ' slots',
            );

            $showtime->providerCalendars->setCalendarsForProvider($provider->id, []);

            $unlinkedAgain = $stub->availability->getAvailableSlots($service->id, $provider->id, $date, 'UTC');
            $this->check(
                'removing the link frees the slots again',
                count($unlinkedAgain) === count($before),
                'got ' . count($unlinkedAgain) . ', expected ' . count($before),
            );

            // Owl's feed endpoint is anonymous and bookings carry customer names, so the
            // glue must fail closed. A console request has no user, which is the same
            // position an anonymous visitor is in.
            $feedEvent = new \justinholtweb\owl\events\FeedItemsEvent([
                'rangeStart' => new \DateTimeImmutable('-1 year'),
                'rangeEnd' => new \DateTimeImmutable('+1 year'),
                'items' => [],
            ]);
            $showtime->calendarFeed->addBookings($feedEvent);

            $this->check(
                'calendar feed adds no bookings without view-bookings permission',
                $feedEvent->items === [],
                'leaked ' . count($feedEvent->items) . ' items',
            );

            // One Stripe endpoint for the bundle — routing by event type is the whole
            // contract, so pin it. Signature verification is exercised over HTTP.
            $routes = [
                'payment_intent.succeeded' => 'stub',
                'payment_intent.payment_failed' => 'stub',
                'charge.refunded' => 'stub',
                'customer.subscription.updated' => 'headcount',
                'invoice.paid' => 'headcount',
                'checkout.session.completed' => 'headcount',
            ];

            $misrouted = [];
            foreach ($routes as $type => $expected) {
                if ($showtime->stripeWebhooks->routeFor($type) !== $expected) {
                    $misrouted[] = $type;
                }
            }

            $this->check(
                'Stripe webhook events route to the right module',
                $misrouted === [],
                'misrouted: ' . implode(', ', $misrouted),
            );
        } catch (\Throwable $e) {
            $this->check('provider-calendar scenario ran without error', false, $e->getMessage());
        } finally {
            $db = Craft::$app->getDb();

            if (!empty($created['event'])) {
                $db->createCommand()->delete('{{%owl_occurrences}}', ['eventId' => $created['event']])->execute();
                if ($el = OwlEvent::find()->id($created['event'])->status(null)->one()) {
                    Craft::$app->getElements()->deleteElement($el, true);
                }
            }
            if (!empty($created['calendar'])) {
                $owl->calendars->deleteCalendarById($created['calendar']);
            }
            if (!empty($created['provider'])) {
                $db->createCommand()->delete('{{%stub_providers}}', ['id' => $created['provider']])->execute();
            }
            if (!empty($created['service'])) {
                $db->createCommand()->delete('{{%stub_services}}', ['id' => $created['service']])->execute();
            }
        }
    }

    /**
     * Not assertions — an inventory, so an adoption run visibly shows the customer's data
     * still sitting there afterwards.
     */
    private function reportData(): void
    {
        $this->heading('Data inventory');

        $counts = [
            'bookings' => '{{%stub_bookings}}',
            'services' => '{{%stub_services}}',
            'providers' => '{{%stub_providers}}',
            'customers' => '{{%stub_customers}}',
            'events' => '{{%owl_events}}',
            'calendars' => '{{%owl_calendars}}',
            'occurrences' => '{{%owl_occurrences}}',
            'plans' => '{{%headcount_plans}}',
            'subscriptions' => '{{%headcount_subscriptions}}',
            'access rules' => '{{%headcount_access_rules}}',
        ];

        foreach ($counts as $label => $table) {
            if (Craft::$app->getDb()->getTableSchema($table, true) === null) {
                continue;
            }
            $count = (new Query())->from($table)->count();
            $this->stdout("       $count $label\n");
        }
    }

    /**
     * Which of these service-locator components fail to resolve on a mounted module.
     *
     * A mounted module only has its services because the host merges the plugin's
     * static::config() into the construction config, so actually *constructing* each one is
     * the assertion — `has()` alone would pass on a definition that blows up on instantiation.
     *
     * @param string[] $components
     * @return string[]
     */
    private function unresolvedComponents(\yii\base\Module $module, array $components): array
    {
        $unresolved = [];

        foreach ($components as $component) {
            try {
                if (!$module->has($component)) {
                    $unresolved[] = $component;
                    continue;
                }
                $module->get($component);
            } catch (\Throwable) {
                $unresolved[] = $component;
            }
        }

        return $unresolved;
    }

    /**
     * Access rules beyond entries: Headcount gating pointed at Owl events.
     *
     * A console request carries no user, which puts these assertions in exactly the
     * position an anonymous visitor is in — the one that matters for a gate.
     */
    private function testGating(): void
    {
        $showtime = Plugin::getInstance();
        /** @var \justinholtweb\headcount\Headcount|null $headcount */
        $headcount = $showtime->getModuleByHandle('headcount');
        /** @var \justinholtweb\owl\Owl|null $owl */
        $owl = $showtime->getModuleByHandle('owl');

        $this->heading('Gating beyond entries (bundle glue)');

        if ($headcount === null || $owl === null) {
            $this->check('skipped — needs both memberships and events mounted', true);
            return;
        }

        $targets = $headcount->gating->getGateTargets();

        $this->check(
            'entries are still gateable',
            isset($targets[\craft\elements\Entry::class]),
            'targets: ' . implode(', ', array_keys($targets)),
        );

        $this->check(
            'the host registered Owl events as a gate target',
            isset($targets[OwlEvent::class]),
            'targets: ' . implode(', ', array_keys($targets)),
        );

        $this->check(
            'events can be scoped by calendar',
            isset($targets[OwlEvent::class]) && $targets[OwlEvent::class]->hasScope(Gates::SCOPE_CALENDAR),
        );

        $created = [];

        try {
            $gatedCalendar = new Calendar(['name' => 'Showtime gate probe (members)', 'handle' => 'showtimeGateMembers']);
            $owl->calendars->save($gatedCalendar);
            $created['gatedCalendar'] = $gatedCalendar->id;

            $openCalendar = new Calendar(['name' => 'Showtime gate probe (open)', 'handle' => 'showtimeGateOpen']);
            $owl->calendars->save($openCalendar);
            $created['openCalendar'] = $openCalendar->id;

            $start = new \DateTime('+30 days', new \DateTimeZone('UTC'));
            $end = (clone $start)->modify('+1 hour');

            $gatedEvent = new OwlEvent([
                'calendarId' => $gatedCalendar->id,
                'title' => 'Members-only probe event',
                'startDate' => $start,
                'endDate' => $end,
                'timezone' => 'UTC',
            ]);
            Craft::$app->getElements()->saveElement($gatedEvent);
            $created['gatedEvent'] = $gatedEvent->id;

            $openEvent = new OwlEvent([
                'calendarId' => $openCalendar->id,
                'title' => 'Open probe event',
                'startDate' => $start,
                'endDate' => $end,
                'timezone' => 'UTC',
            ]);
            Craft::$app->getElements()->saveElement($openEvent);
            $created['openEvent'] = $openEvent->id;

            // Nothing is gated yet, so both are visible even to nobody.
            $this->check(
                'an event with no rule is unrestricted',
                $headcount->gating->canAccess($gatedEvent, null),
            );

            // A scope no one claims must gate nothing. "Matches nothing" is a recoverable
            // mistake; "matches everything" takes the site down.
            $orphan = new AccessRule([
                'name' => 'Showtime probe — unclaimed scope',
                'elementType' => OwlEvent::class,
                'type' => Gates::SCOPE_CALENDAR,
                'targetId' => $gatedCalendar->id,
                'behavior' => 'hide',
            ]);
            $this->check(
                'a rule must name a scope its element type offers',
                (function() use ($headcount) {
                    $bogus = new AccessRule([
                        'name' => 'Showtime probe — bogus scope',
                        'elementType' => OwlEvent::class,
                        'type' => 'section',
                        'behavior' => 'hide',
                    ]);
                    return !$headcount->gating->saveRule($bogus);
                })(),
                'a rule that can never match reads as protection it does not give',
            );

            $this->check('the calendar-scoped rule saves', $headcount->gating->saveRule($orphan), implode('; ', $orphan->getErrorSummary(true)));
            $created['rule'] = $orphan->id;

            $this->check(
                'the rule gates events on its calendar',
                !$headcount->gating->canAccess($gatedEvent, null),
            );

            $this->check(
                'the rule leaves events on other calendars alone',
                $headcount->gating->canAccess($openEvent, null),
            );

            $this->check(
                'entries are untouched by an event rule',
                $headcount->gating->getRulesForElement(new \craft\elements\Entry()) === [],
            );

            // `hide` has to actually stop the request — a rule that only answers questions
            // templates remember to ask isn't enforcement.
            $threw = false;
            try {
                $headcount->gating->enforce($gatedEvent, null);
            } catch (\yii\web\NotFoundHttpException) {
                $threw = true;
            }
            $this->check('the "hide" behavior 404s the request', $threw);

            // `owl/events.json` is anonymous: a gated event must not leak its title there.
            $feedEvent = new \justinholtweb\owl\events\FeedItemsEvent([
                'rangeStart' => new \DateTimeImmutable('-1 year'),
                'rangeEnd' => new \DateTimeImmutable('+1 year'),
                'items' => [
                    ['id' => $gatedEvent->id, 'title' => 'Members-only probe event'],
                    ['id' => $openEvent->id, 'title' => 'Open probe event'],
                    ['id' => 'booking-1', 'title' => 'A booking', 'showtimeType' => 'booking'],
                ],
            ]);
            $showtime->gates->filterFeed($feedEvent);

            $remaining = array_column($feedEvent->items, 'id');

            $this->check(
                'the anonymous feed drops the gated event',
                !in_array($gatedEvent->id, $remaining, true),
                'remaining: ' . implode(', ', $remaining),
            );

            $this->check(
                'the anonymous feed keeps the open event and the bookings',
                in_array($openEvent->id, $remaining, true) && in_array('booking-1', $remaining, true),
                'remaining: ' . implode(', ', $remaining),
            );
        } catch (\Throwable $e) {
            $this->check('gating scenario ran without error', false, $e->getMessage());
        } finally {
            if (!empty($created['rule'])) {
                $headcount->gating->deleteRuleById($created['rule']);
            }
            foreach (['gatedEvent', 'openEvent'] as $key) {
                if (!empty($created[$key]) && ($el = OwlEvent::find()->id($created[$key])->status(null)->one())) {
                    Craft::$app->getElements()->deleteElement($el, true);
                }
            }
            foreach (['gatedCalendar', 'openCalendar'] as $key) {
                if (!empty($created[$key])) {
                    $owl->calendars->deleteCalendarById($created[$key]);
                }
            }
        }
    }

    /**
     * One sender and one screen for every email the bundle sends.
     */
    private function testNotifications(): void
    {
        $showtime = Plugin::getInstance();

        $this->heading('Notifications (bundle glue)');

        $definitions = $showtime->notifications->definitions();

        $this->check(
            'every mounted module’s emails are listed (' . count($definitions) . ')',
            $definitions !== [],
        );

        $missing = [];
        foreach ($definitions as $key => $definition) {
            if (Craft::$app->getSystemMessages()->getMessage($key) === null) {
                $missing[] = $key;
            }
        }

        // A listed message that Craft doesn't know about would show a toggle that switches
        // an email nobody can edit — and would fatal on composeFromKey when it fired.
        $this->check(
            'every listed message is registered with Craft (⇒ CP-editable copy)',
            $missing === [],
            'missing: ' . implode(', ', $missing),
        );

        // The shipped copy is Twig, rendered for the first time when a real member
        // subscribes or books — and Craft's strict-variables mode turns a placeholder the
        // sender doesn't supply into a hard failure. Rendering each body against exactly its
        // declared variables catches both here. Deliberately the *shipped* copy rather than
        // the stored message: a site is free to customise it, and that's not ours to police.
        $broken = [];
        foreach ($definitions as $key => $definition) {
            $variables = array_fill_keys($definition['variables'], 'x');

            try {
                Craft::$app->getView()->renderString($definition['subject'], $variables);
                Craft::$app->getView()->renderString($definition['body'], $variables);
            } catch (\Throwable $e) {
                $broken[] = $key . ' (' . $e->getMessage() . ')';
            }
        }

        $this->check(
            'every message renders against its declared variables',
            $broken === [],
            implode('; ', $broken),
        );

        $settings = $showtime->getSettings();
        $originalName = $settings->emailFromName;
        $originalEmail = $settings->emailFromEmail;

        try {
            $settings->emailFromName = 'Showtime Probe';
            $settings->emailFromEmail = 'probe@example.test';

            $key = array_key_first($definitions);
            $message = Craft::$app->getMailer()->composeFromKey($key, []);
            $showtime->notifications->applyFromIdentity(new \yii\mail\MailEvent(['message' => $message]));

            $this->check(
                'the shared sender is stamped onto a bundle email',
                $message->getFrom() === ['probe@example.test' => 'Showtime Probe'],
                'got: ' . json_encode($message->getFrom()),
            );

            // Craft's own emails must not be rebranded by the bundle.
            $foreign = Craft::$app->getMailer()->composeFromKey('account_activation', []);
            $showtime->notifications->applyFromIdentity(new \yii\mail\MailEvent(['message' => $foreign]));

            $this->check(
                'a message that isn’t the bundle’s is left alone',
                empty($foreign->getFrom()),
                'got: ' . json_encode($foreign->getFrom()),
            );

            // Someone who set a From deliberately outranks the shared identity.
            $explicit = Craft::$app->getMailer()->composeFromKey($key, [])->setFrom('explicit@example.test');
            $showtime->notifications->applyFromIdentity(new \yii\mail\MailEvent(['message' => $explicit]));

            $this->check(
                'an explicit From on the message wins',
                array_key_exists('explicit@example.test', (array)$explicit->getFrom()),
                'got: ' . json_encode($explicit->getFrom()),
            );
        } finally {
            $settings->emailFromName = $originalName;
            $settings->emailFromEmail = $originalEmail;
        }
    }

    /**
     * The identity graph: one person's records from three plugins, joined on their email.
     */
    private function testPeople(): void
    {
        $showtime = Plugin::getInstance();
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = $showtime->getModuleByHandle('stub');

        $this->heading('Identity graph (bundle glue)');

        $unknown = $showtime->people->find('nobody-at-all@showtime.probe');

        $this->check(
            'an unknown address resolves to an empty person, not an error',
            !$unknown->isKnown() && $unknown->email === 'nobody-at-all@showtime.probe',
        );

        $this->check(
            'an empty subject is handled',
            !$showtime->people->find('')->isKnown(),
        );

        if ($stub === null) {
            $this->check('skipped the joined scenario — needs bookings mounted', true);
            return;
        }

        $created = [];

        try {
            $service = new Service([
                'name' => 'Showtime people probe service',
                'handle' => 'showtimePeopleProbeService',
                'duration' => 30,
                'price' => 0,
            ]);
            $stub->services->saveService($service);
            $created['service'] = $service->id;

            $email = 'people-probe@showtime.test';
            $customer = $stub->customers->findOrCreate($email, 'Probe', 'Person');
            $created['customer'] = $customer->id;

            $person = $showtime->people->find($email);

            $this->check(
                'a customer with no Craft account still resolves',
                $person->isKnown() && $person->customer?->id === $customer->id,
            );

            $this->check(
                'the person takes their name from the customer record',
                $person->getName() === 'Probe Person',
                'got: ' . $person->getName(),
            );

            // A registered member and a guest customer with the same address are one person.
            // Craft Solo won't allow a second user, so the join is only assertable on Pro.
            if (Craft::$app->edition->name === 'Solo') {
                $this->check('skipped the user join — Craft Solo allows only one user', true);
            } else {
                $user = new User(['email' => $email, 'username' => $email, 'firstName' => 'Probe']);
                Craft::$app->getElements()->saveElement($user);
                $created['user'] = $user->id;

                $joined = $showtime->people->find($user);

                $this->check(
                    'a Craft user and a guest customer on one address are one person',
                    $joined->user?->id === $user->id && $joined->customer?->id === $customer->id,
                );

                $this->check(
                    'the customer record picks up the user on their next booking',
                    (function() use ($stub, $email, $user) {
                        $again = $stub->customers->findOrCreate($email, 'Probe', 'Person');
                        return $again->userId === $user->id;
                    })(),
                    'a guest who registers later should stop being two people',
                );

                $this->check(
                    'the module resolves the user itself, without the host',
                    $stub->customers->resolveUser($customer)?->id === $user->id,
                );
            }

            // Registrations need Commerce; without it the section is empty, not broken.
            $this->check(
                'ticket orders resolve (or are empty without Commerce)',
                is_array($person->registrations),
            );

            $this->check(
                'search finds the probe by name',
                array_key_exists($email, $showtime->people->search('Probe')),
                'got: ' . implode(', ', array_keys($showtime->people->search('Probe'))),
            );
        } catch (\Throwable $e) {
            $this->check('identity-graph scenario ran without error', false, $e->getMessage());
        } finally {
            $db = Craft::$app->getDb();

            if (!empty($created['user']) && ($el = User::find()->id($created['user'])->status(null)->one())) {
                Craft::$app->getElements()->deleteElement($el, true);
            }
            if (!empty($created['customer'])) {
                $db->createCommand()->delete('{{%stub_customers}}', ['id' => $created['customer']])->execute();
            }
            if (!empty($created['service'])) {
                $db->createCommand()->delete('{{%stub_services}}', ['id' => $created['service']])->execute();
            }
        }
    }

    private function heading(string $text): void
    {
        $this->stdout("\n$text\n", Console::FG_CYAN);
        $this->stdout(str_repeat('-', strlen($text)) . "\n");
    }

    private function check(string $label, bool $passed, string $detail = ''): void
    {
        if ($passed) {
            $this->passed++;
            $this->stdout("  ok   ", Console::FG_GREEN);
            $this->stdout("$label\n");
            return;
        }

        $this->failed++;
        $this->stdout("  FAIL ", Console::FG_RED);
        $this->stdout("$label\n");
        if ($detail !== '') {
            $this->stdout("       $detail\n", Console::FG_GREY);
        }
    }
}
