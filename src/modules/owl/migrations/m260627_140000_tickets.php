<?php

declare(strict_types=1);

namespace justinholtweb\owl\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Adds the owl_tickets table for Commerce ticketing (Pro).
 */
class m260627_140000_tickets extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%owl_tickets}}')) {
            return true;
        }

        $this->createTable('{{%owl_tickets}}', [
            'id' => $this->integer()->notNull(),
            'eventId' => $this->integer()->notNull(),
            'ticketName' => $this->string()->notNull(),
            'capacity' => $this->integer(),
            'sold' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%owl_tickets}}', ['eventId'], false);
        $this->addForeignKey(null, '{{%owl_tickets}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_tickets}}', ['eventId'], '{{%owl_events}}', ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%owl_tickets}}');

        return true;
    }
}
