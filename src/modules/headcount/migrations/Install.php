<?php

namespace justinholtweb\headcount\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createPlansTable();
        $this->_createSubscriptionsTable();
        $this->_createAccessRulesTable();
        $this->_createDripSchedulesTable();
        $this->_createCouponsTable();
        $this->_createWebhookLogsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%headcount_webhook_logs}}');
        $this->dropTableIfExists('{{%headcount_coupons}}');
        $this->dropTableIfExists('{{%headcount_drip_schedules}}');
        $this->dropTableIfExists('{{%headcount_access_rules}}');
        $this->dropTableIfExists('{{%headcount_subscriptions}}');
        $this->dropTableIfExists('{{%headcount_plans}}');

        return true;
    }

    private function _createPlansTable(): void
    {
        $this->createTable('{{%headcount_plans}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->text(),
            'userGroupId' => $this->integer(),
            'stripePriceId' => $this->string(255),
            'paypalPlanId' => $this->string(255),
            'price' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->string(3)->notNull()->defaultValue('USD'),
            'billingInterval' => $this->enum('billingInterval', ['day', 'week', 'month', 'year'])->notNull()->defaultValue('month'),
            'billingIntervalCount' => $this->integer()->notNull()->defaultValue(1),
            'trialDays' => $this->integer()->notNull()->defaultValue(0),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'features' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%headcount_plans}}', ['handle'], true);
        $this->addForeignKey(null, '{{%headcount_plans}}', ['userGroupId'], '{{%usergroups}}', ['id'], 'SET NULL');
    }

    private function _createSubscriptionsTable(): void
    {
        $this->createTable('{{%headcount_subscriptions}}', [
            'id' => $this->integer()->notNull(),
            'userId' => $this->integer(),
            'planId' => $this->integer(),
            'gateway' => $this->enum('gateway', ['stripe', 'paypal', 'manual'])->notNull()->defaultValue('stripe'),
            'gatewaySubscriptionId' => $this->string(255),
            'gatewayCustomerId' => $this->string(255),
            'status' => $this->string(50)->notNull()->defaultValue('active'),
            'trialStartDate' => $this->dateTime(),
            'trialEndDate' => $this->dateTime(),
            'startDate' => $this->dateTime(),
            'endDate' => $this->dateTime(),
            'canceledAt' => $this->dateTime(),
            'cancelAtPeriodEnd' => $this->boolean()->notNull()->defaultValue(false),
            'amount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->string(3)->notNull()->defaultValue('USD'),
            'metadata' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->addForeignKey(null, '{{%headcount_subscriptions}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%headcount_subscriptions}}', ['userId'], '{{%users}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%headcount_subscriptions}}', ['planId'], '{{%headcount_plans}}', ['id'], 'SET NULL');
        $this->createIndex(null, '{{%headcount_subscriptions}}', ['userId']);
        $this->createIndex(null, '{{%headcount_subscriptions}}', ['planId']);
        $this->createIndex(null, '{{%headcount_subscriptions}}', ['status']);
        $this->createIndex(null, '{{%headcount_subscriptions}}', ['gatewaySubscriptionId']);
        $this->createIndex(null, '{{%headcount_subscriptions}}', ['gateway']);
    }

    private function _createAccessRulesTable(): void
    {
        $this->createTable('{{%headcount_access_rules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            // What kind of element the rule is about…
            'elementType' => $this->string(255)->notNull()->defaultValue(\craft\elements\Entry::class),
            // …and which of them: a scope key belonging to that element type's gate target.
            // Open-ended on purpose — plugins register their own scopes, so this can't be
            // an enum. See services/Gating.php.
            'type' => $this->string(64)->notNull(),
            'targetId' => $this->integer(),
            // Optional UID-stable reference to the target. Nullable: nothing populates it,
            // and as a NOT NULL uid() column it made every rule save fail.
            'targetUid' => $this->char(36)->null(),
            'planIds' => $this->json(),
            'behavior' => $this->enum('behavior', ['redirect', 'paywall', 'hide'])->notNull()->defaultValue('redirect'),
            'redirectUrl' => $this->string(255),
            'teaserLength' => $this->integer(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%headcount_access_rules}}', ['type', 'targetId']);
        $this->createIndex(null, '{{%headcount_access_rules}}', ['sortOrder']);
    }

    private function _createDripSchedulesTable(): void
    {
        $this->createTable('{{%headcount_drip_schedules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'planIds' => $this->json(),
            'type' => $this->enum('type', ['section', 'entryType', 'category', 'entry'])->notNull(),
            'targetId' => $this->integer(),
            'targetUid' => $this->uid(),
            'delayDays' => $this->integer()->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%headcount_drip_schedules}}', ['type', 'targetId']);
    }

    private function _createCouponsTable(): void
    {
        $this->createTable('{{%headcount_coupons}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(255)->notNull(),
            'description' => $this->string(255),
            'discountType' => $this->enum('discountType', ['percent', 'flat'])->notNull()->defaultValue('percent'),
            'discountAmount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'planIds' => $this->json(),
            'maxUses' => $this->integer(),
            'currentUses' => $this->integer()->notNull()->defaultValue(0),
            'expiresAt' => $this->dateTime(),
            'stripeCouponId' => $this->string(255),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%headcount_coupons}}', ['code'], true);
    }

    private function _createWebhookLogsTable(): void
    {
        $this->createTable('{{%headcount_webhook_logs}}', [
            'id' => $this->primaryKey(),
            'gateway' => $this->string(50)->notNull(),
            'eventId' => $this->string(255),
            'eventType' => $this->string(255),
            'payload' => $this->text(),
            'status' => $this->enum('status', ['processed', 'failed', 'ignored'])->notNull()->defaultValue('processed'),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%headcount_webhook_logs}}', ['eventId']);
        $this->createIndex(null, '{{%headcount_webhook_logs}}', ['gateway', 'eventType']);
    }
}
