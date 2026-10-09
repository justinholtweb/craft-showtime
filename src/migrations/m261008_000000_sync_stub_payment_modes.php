<?php

namespace justinholtweb\showtime\migrations;

use craft\db\Migration;
use justinholtweb\showtime\Plugin;

/**
 * Run the migration Stub gained with deposits and pay-in-person (payment modes on services,
 * amounts paid on bookings, and the payments ledger).
 *
 * Same job as m261003_000000: the mounted modules aren't installed plugins, so their migration
 * tracks only move when the host asks. `syncModules()` is idempotent and covers every module.
 */
class m261008_000000_sync_stub_payment_modes extends Migration
{
    public function safeUp(): bool
    {
        Plugin::getInstance()->syncModules();

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261008_000000_sync_stub_payment_modes cannot be reverted.\n";

        return false;
    }
}
