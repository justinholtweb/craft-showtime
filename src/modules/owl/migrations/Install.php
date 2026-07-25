<?php

declare(strict_types=1);

namespace justinholtweb\owl\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Owl install migration — creates the calendar, event, occurrence, and exception tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Drop in dependency order (children first).
        $this->dropTableIfExists('{{%owl_tickets}}');
        $this->dropTableIfExists('{{%owl_exceptions}}');
        $this->dropTableIfExists('{{%owl_occurrences}}');
        $this->dropTableIfExists('{{%owl_events}}');
        $this->dropTableIfExists('{{%owl_calendars}}');

        return true;
    }

    private function createTables(): void
    {
        $this->createTable('{{%owl_calendars}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'color' => $this->string(10),
            'fieldLayoutId' => $this->integer(),
            'hasTickets' => $this->boolean()->notNull()->defaultValue(false),
            'uriFormat' => $this->string(),
            'template' => $this->string(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%owl_events}}', [
            // id is the element id (1:1 with elements), not auto-increment.
            'id' => $this->integer()->notNull(),
            'calendarId' => $this->integer()->notNull(),
            'startDate' => $this->dateTime()->notNull(),
            'endDate' => $this->dateTime()->notNull(),
            'allDay' => $this->boolean()->notNull()->defaultValue(false),
            'timezone' => $this->string(64)->notNull()->defaultValue('UTC'),
            'rrule' => $this->text(),
            'repeating' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable('{{%owl_occurrences}}', [
            'id' => $this->primaryKey(),
            'eventId' => $this->integer()->notNull(),
            'startDate' => $this->dateTime()->notNull(),
            'endDate' => $this->dateTime()->notNull(),
            'allDay' => $this->boolean()->notNull()->defaultValue(false),
            'isException' => $this->boolean()->notNull()->defaultValue(false),
            'isOverride' => $this->boolean()->notNull()->defaultValue(false),
            'overrideData' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%owl_exceptions}}', [
            'id' => $this->primaryKey(),
            'eventId' => $this->integer()->notNull(),
            'date' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

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
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, '{{%owl_calendars}}', ['handle'], true);
        $this->createIndex(null, '{{%owl_events}}', ['calendarId'], false);
        // The hot path: range queries over occurrences for a calendar window.
        $this->createIndex(null, '{{%owl_occurrences}}', ['startDate', 'endDate'], false);
        $this->createIndex(null, '{{%owl_occurrences}}', ['eventId'], false);
        $this->createIndex(null, '{{%owl_exceptions}}', ['eventId', 'date'], true);
        $this->createIndex(null, '{{%owl_tickets}}', ['eventId'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, '{{%owl_calendars}}', ['fieldLayoutId'], Table::FIELDLAYOUTS, ['id'], 'SET NULL');
        $this->addForeignKey(null, '{{%owl_events}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_events}}', ['calendarId'], '{{%owl_calendars}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_occurrences}}', ['eventId'], '{{%owl_events}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_exceptions}}', ['eventId'], '{{%owl_events}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_tickets}}', ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%owl_tickets}}', ['eventId'], '{{%owl_events}}', ['id'], 'CASCADE');
    }
}
