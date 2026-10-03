<?php

namespace justinholtweb\stub\migrations;

use craft\db\Migration;

/**
 * Adds the column the reminder sweep stamps so a booking is only ever reminded once.
 *
 * Null means "not yet reminded", which is the correct state for every existing booking:
 * reminders are opt-in and off by default, so an upgrade sends nothing until the site
 * turns them on and schedules the command.
 */
class m260917_120000_add_reminder_sent_at extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%stub_bookings}}';

        if (!$this->db->columnExists($table, 'reminderSentAt')) {
            $this->addColumn($table, 'reminderSentAt', $this->dateTime()->after('cancellationReason'));
            $this->createIndex(null, $table, ['bookingStatus', 'reminderSentAt', 'startDateTime']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%stub_bookings}}';

        if ($this->db->columnExists($table, 'reminderSentAt')) {
            $this->dropColumn($table, 'reminderSentAt');
        }

        return true;
    }
}
