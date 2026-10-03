<?php

namespace justinholtweb\showtime\migrations;

use craft\db\Migration;
use justinholtweb\showtime\Plugin;

/**
 * Run the migration Stub gained with reminder emails (`reminderSentAt` on bookings).
 *
 * Same job as m260814_090000: the mounted modules aren't installed plugins, so their migration
 * tracks only move when the host asks. `syncModules()` is idempotent and covers every module.
 */
class m261003_000000_sync_stub_reminders extends Migration
{
    public function safeUp(): bool
    {
        Plugin::getInstance()->syncModules();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261003_000000_sync_stub_reminders cannot be reverted.\n";

        return false;
    }
}
