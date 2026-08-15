<?php

namespace justinholtweb\headcount\migrations;

use craft\db\Migration;
use justinholtweb\headcount\models\Plan;

/**
 * Plans stop being recurring-only.
 *
 * A recurring plan bills on each member's own anniversary, which is the wrong shape for a
 * club whose membership year is a fixed calendar window — everyone in a July–June season
 * should expire together on 30 June, no matter when they joined. That needs three things
 * a recurring plan can't express: a term that ends on a date rather than after an interval,
 * a price that can be reduced for someone joining halfway through, and a window that rolls
 * forward each year so the club isn't editing the plan every summer.
 *
 * Existing plans get `termType = recurring` and behave exactly as they did.
 */
class m260813_090000_seasonal_plans extends Migration
{
    private const TABLE = '{{%headcount_plans}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'termType')) {
            $this->addColumn(
                self::TABLE,
                'termType',
                $this->string(16)->notNull()->defaultValue(Plan::TERM_RECURRING)->after('billingIntervalCount'),
            );
        }

        if (!$this->db->columnExists(self::TABLE, 'seasonStartDate')) {
            $this->addColumn(self::TABLE, 'seasonStartDate', $this->dateTime()->null()->after('termType'));
        }

        if (!$this->db->columnExists(self::TABLE, 'seasonEndDate')) {
            $this->addColumn(self::TABLE, 'seasonEndDate', $this->dateTime()->null()->after('seasonStartDate'));
        }

        // On by default: a club that sets 1 July → 30 June once shouldn't have to come back
        // every year to move the window on. Off means the season runs once and the plan
        // stops selling when it ends.
        if (!$this->db->columnExists(self::TABLE, 'seasonRepeats')) {
            $this->addColumn(
                self::TABLE,
                'seasonRepeats',
                $this->boolean()->notNull()->defaultValue(true)->after('seasonEndDate'),
            );
        }

        if (!$this->db->columnExists(self::TABLE, 'prorate')) {
            $this->addColumn(
                self::TABLE,
                'prorate',
                $this->boolean()->notNull()->defaultValue(false)->after('seasonRepeats'),
            );
        }

        if (!$this->db->columnExists(self::TABLE, 'prorationBasis')) {
            $this->addColumn(
                self::TABLE,
                'prorationBasis',
                $this->string(16)->notNull()->defaultValue(Plan::PRORATION_MONTH)->after('prorate'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        foreach (['prorationBasis', 'prorate', 'seasonRepeats', 'seasonEndDate', 'seasonStartDate', 'termType'] as $column) {
            if ($this->db->columnExists(self::TABLE, $column)) {
                $this->dropColumn(self::TABLE, $column);
            }
        }

        return true;
    }
}
