<?php

namespace justinholtweb\headcount\migrations;

use craft\db\Migration;
use craft\elements\Entry;

/**
 * Access rules stop being entry-only.
 *
 * Two changes to `headcount_access_rules`:
 *   - a new `elementType` column, defaulted to Entry so every existing rule keeps meaning
 *     exactly what it meant;
 *   - `type` widens from a fixed enum to a plain string, because the set of scopes is now
 *     open — whoever registers a gate target names its own scopes.
 *
 * The `entry` scope is renamed to `element`, which is the same thing generalised.
 *
 * Also makes `targetUid` nullable. Craft's `uid()` column type is NOT NULL, nothing ever
 * populated it, and the save path writes null into it — so *every* attempt to save an
 * access rule failed on an integrity constraint. It stays as an optional UID-stable
 * reference rather than being dropped.
 */
class m260726_090000_generalize_gating extends Migration
{
    private const TABLE = '{{%headcount_access_rules}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'elementType')) {
            $this->addColumn(
                self::TABLE,
                'elementType',
                $this->string(255)->notNull()->defaultValue(Entry::class)->after('name'),
            );
        }

        // On Postgres, Craft's enum() is a varchar plus a CHECK constraint; the column is
        // already wide enough but the constraint would reject any new scope key.
        if ($this->db->getIsPgsql()) {
            $this->_dropTypeCheckConstraints();
        }

        $this->alterColumn(self::TABLE, 'type', $this->string(64)->notNull());
        $this->alterColumn(self::TABLE, 'targetUid', $this->char(36)->null());

        $this->update(self::TABLE, ['type' => 'element'], ['type' => 'entry'], [], false);

        return true;
    }

    /**
     * Deliberately not reversible.
     *
     * Down would have to re-narrow `type` to the old enum, and any rule written against a
     * scope or element type that only exists post-upgrade has no valid value to go back to
     * — a silent data loss that turns access rules into no-ops. Reinstalling is the honest
     * answer.
     */
    public function safeDown(): bool
    {
        echo self::class . " cannot be reverted.\n";
        return false;
    }

    private function _dropTypeCheckConstraints(): void
    {
        $table = $this->db->getSchema()->getRawTableName(self::TABLE);

        $names = $this->db->createCommand(<<<SQL
            SELECT con.conname
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
            WHERE con.contype = 'c' AND rel.relname = :table AND att.attname = 'type'
        SQL)->bindValue(':table', $table)->queryColumn();

        foreach ($names as $name) {
            $this->execute("ALTER TABLE {{%$table}} DROP CONSTRAINT " . $this->db->quoteColumnName($name));
        }
    }
}
