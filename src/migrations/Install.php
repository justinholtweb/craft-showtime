<?php

namespace justinholtweb\showtime\migrations;

use craft\db\Migration;
use justinholtweb\showtime\Plugin;

/**
 * Showtime owns a single plugin handle, so it owns a single schemaVersion — and it
 * delegates most of the schema work to each mounted module.
 *
 * craft\base\Plugin::install()/uninstall() touch only the plugin's migrator (run its
 * Install migration, backfill migration history, set isInstalled); they never write to the
 * `plugins` table — that's Craft's Plugins service. So the host can call them directly and
 * each module's tables are created under its own `plugin:<handle>` migration track.
 *
 * If a module is already installed standalone on this site, it is **adopted** instead:
 * detached from Craft without dropping a byte, then brought up to the vendored copy's
 * schema. That makes `plugin/install showtime` the whole upgrade path for an existing
 * customer.
 *
 * **Every table the host itself owns has to be created here, not in a later migration.**
 * `craft\base\Plugin::install()` runs this migration and then marks every *other* migration
 * as applied **without running it**, so a table added by an incremental migration simply
 * never exists on a fresh install. That is not a hypothetical — `showtime_perks` and
 * `showtime_provider_calendars` were added that way and were missing from every clean
 * install until this was folded back in.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        // Modules first: the host's own tables hold foreign keys into theirs.
        Plugin::getInstance()->syncModules();

        $this->createPerksTable();
        $this->createProviderCalendarsTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Host tables first, for the mirror-image reason: they reference `stub_providers`,
        // `owl_calendars` and `headcount_plans`, so dropping the modules first fails on the
        // foreign keys and leaves the schema half-uninstalled.
        $this->dropTableIfExists('{{%showtime_provider_calendars}}');
        $this->dropTableIfExists('{{%showtime_perks}}');

        // Reverse order: later modules may hold foreign keys into earlier ones.
        $modules = array_reverse(Plugin::getInstance()->getMountedModules(), true);

        foreach ($modules as $handle => $module) {
            echo "    > uninstalling mounted module: $handle ...\n";
            $module->uninstall();

            // `Plugin::uninstall()` only runs the module's Install migration *down* — it
            // leaves the `plugin:<handle>` history behind, because for a real plugin Craft's
            // Plugins service clears that afterwards. Nothing clears it for a mounted
            // module, and syncModules() reads a non-empty history as "already installed,
            // just migrate up" — so a reinstall would find the tables gone, apply nothing,
            // and leave the site with a plugin whose schema doesn't exist.
            $module->getMigrator()->truncateHistory();
        }

        return true;
    }

    /**
     * Member perks — the first table Showtime owns itself.
     *
     * Everything else belongs to a mounted module. This one is deliberately the host's: it
     * links a Headcount plan to something sold by another module, which is a relationship no
     * single plugin can own without depending on another. Keeping it here is what lets all
     * three keep shipping standalone.
     */
    private function createPerksTable(): void
    {
        $this->createTable('{{%showtime_perks}}', [
            'id' => $this->primaryKey(),
            'planId' => $this->integer()->notNull(),
            // e.g. 'stub:service'. Namespaced by module so Owl ticket types and anything
            // added later slot in without a schema change.
            'targetType' => $this->string(64)->notNull(),
            'targetId' => $this->integer()->notNull(),
            'membersOnly' => $this->boolean()->notNull()->defaultValue(false),
            'discountPercent' => $this->decimal(5, 2),
            'discountAmount' => $this->decimal(14, 4),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%showtime_perks}}', ['targetType', 'targetId']);
        $this->createIndex(null, '{{%showtime_perks}}', ['planId']);

        // Headcount is always part of the bundle, so the coupling is safe here in a way it
        // would not be inside one of the plugins — and it means deleting a plan cleans up
        // the perks that referenced it.
        $this->addForeignKey(null, '{{%showtime_perks}}', ['planId'], '{{%headcount_plans}}', ['id'], 'CASCADE');
    }

    /**
     * Which event calendars a booking provider runs.
     *
     * Owl events have no notion of a provider and Stub providers have no notion of a
     * calendar, so the link has to be stated somewhere — and, like perks, it belongs to the
     * host rather than to either plugin.
     */
    private function createProviderCalendarsTable(): void
    {
        $this->createTable('{{%showtime_provider_calendars}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'calendarId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%showtime_provider_calendars}}', ['providerId', 'calendarId'], true);

        // Both modules are always part of the bundle, so deleting a provider or a calendar
        // cleans up the links that referenced it.
        $this->addForeignKey(null, '{{%showtime_provider_calendars}}', ['providerId'], '{{%stub_providers}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%showtime_provider_calendars}}', ['calendarId'], '{{%owl_calendars}}', ['id'], 'CASCADE');
    }
}
