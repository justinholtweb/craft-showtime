<?php

namespace justinholtweb\headcount\migrations;

use craft\db\Migration;

/**
 * Apple Wallet device registrations.
 *
 * A `.pkpass` on a phone isn't a copy of the membership — it's a client that expects to be
 * told when the membership changes. Apple's PassKit web service asks the site to remember,
 * for every pass on every device, the push token to notify: without that, a card stays
 * "active" on the lock screen after the member's season has ended.
 *
 * Rows are owned by the device, not by us: the phone creates one when the pass is added and
 * deletes it when the pass is removed. `subscriptionId` is denormalised from the serial
 * number so the push job can go the other way — from a membership that just changed to the
 * handful of devices holding it.
 *
 * Google Wallet needs no equivalent: its passes live on Google's servers and are updated by
 * calling the API, so there is nothing per-device to store.
 */
class m260813_100000_wallet_passes extends Migration
{
    private const TABLE = '{{%headcount_wallet_registrations}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'deviceLibraryIdentifier' => $this->string(255)->notNull(),
            'passTypeIdentifier' => $this->string(255)->notNull(),
            'serialNumber' => $this->string(255)->notNull(),
            'pushToken' => $this->string(255)->notNull(),
            'subscriptionId' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per pass per device. Re-adding a pass updates the push token rather than
        // accumulating rows that would each fire their own redundant push.
        $this->createIndex(
            null,
            self::TABLE,
            ['deviceLibraryIdentifier', 'passTypeIdentifier', 'serialNumber'],
            true,
        );
        $this->createIndex(null, self::TABLE, ['serialNumber']);
        $this->createIndex(null, self::TABLE, ['subscriptionId']);

        // Deleting a subscription element takes its registrations with it — the pass it
        // describes no longer refers to anything.
        $this->addForeignKey(null, self::TABLE, ['subscriptionId'], '{{%headcount_subscriptions}}', ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);

        return true;
    }
}
