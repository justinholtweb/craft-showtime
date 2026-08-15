<?php

namespace justinholtweb\showtime\migrations;

use craft\db\Migration;
use justinholtweb\showtime\Plugin;

/**
 * Run the migrations Headcount gained with season memberships and wallet cards.
 *
 * The mounted modules aren't installed plugins, so Craft's own updater never looks at their
 * migration tracks — a sub-plugin gaining a migration only reaches existing sites if the
 * host asks for it. `syncModules()` is idempotent and covers every module, so this is the
 * whole job: it applies Headcount's new `termType`/season columns and its wallet
 * registrations table, and does nothing on a site that already has them.
 *
 * Fresh installs don't need this — Showtime's Install migration calls the same method — but
 * running it twice is harmless, which is what makes it safe to add one of these per release.
 */
class m260814_090000_sync_headcount_wallet_and_seasons extends Migration
{
    public function safeUp(): bool
    {
        Plugin::getInstance()->syncModules();

        return true;
    }

    /**
     * Deliberately not reversible: the modules own their own down migrations, and rolling
     * this one back would have to guess which of them to undo.
     */
    public function safeDown(): bool
    {
        echo "m260814_090000_sync_headcount_wallet_and_seasons cannot be reverted.\n";

        return false;
    }
}
