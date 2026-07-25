<?php

declare(strict_types=1);

namespace justinholtweb\owl\migrations;

use craft\db\Migration;

/**
 * Adds per-calendar front-end URL settings (uriFormat + template).
 */
class m260627_120000_calendar_url_settings extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%owl_calendars}}', 'uriFormat')) {
            $this->addColumn('{{%owl_calendars}}', 'uriFormat', $this->string()->after('hasTickets'));
        }

        if (!$this->db->columnExists('{{%owl_calendars}}', 'template')) {
            $this->addColumn('{{%owl_calendars}}', 'template', $this->string()->after('uriFormat'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%owl_calendars}}', 'uriFormat')) {
            $this->dropColumn('{{%owl_calendars}}', 'uriFormat');
        }

        if ($this->db->columnExists('{{%owl_calendars}}', 'template')) {
            $this->dropColumn('{{%owl_calendars}}', 'template');
        }

        return true;
    }
}
