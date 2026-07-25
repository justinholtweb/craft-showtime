<?php

namespace justinholtweb\stub\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createServicesTable();
        $this->_createProvidersTable();
        $this->_createProviderSchedulesTable();
        $this->_createProviderBreaksTable();
        $this->_createProviderBlockedDatesTable();
        $this->_createProviderServicesTable();
        $this->_createCustomersTable();
        $this->_createBookingsTable();
        $this->_createPaymentsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%stub_payments}}');
        $this->dropTableIfExists('{{%stub_bookings}}');
        $this->dropTableIfExists('{{%stub_customers}}');
        $this->dropTableIfExists('{{%stub_provider_services}}');
        $this->dropTableIfExists('{{%stub_provider_blocked_dates}}');
        $this->dropTableIfExists('{{%stub_provider_breaks}}');
        $this->dropTableIfExists('{{%stub_provider_schedules}}');
        $this->dropTableIfExists('{{%stub_providers}}');
        $this->dropTableIfExists('{{%stub_services}}');

        return true;
    }

    private function _createServicesTable(): void
    {
        $this->createTable('{{%stub_services}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'description' => $this->text(),
            'duration' => $this->integer()->notNull()->defaultValue(60),
            'price' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->char(3)->notNull()->defaultValue('USD'),
            'bufferTimeBefore' => $this->integer()->notNull()->defaultValue(0),
            'bufferTimeAfter' => $this->integer()->notNull()->defaultValue(0),
            'capacity' => $this->integer()->notNull()->defaultValue(1),
            'color' => $this->string(7)->defaultValue('#2563eb'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_services}}', ['handle'], true);
        $this->createIndex(null, '{{%stub_services}}', ['enabled']);
        $this->createIndex(null, '{{%stub_services}}', ['sortOrder']);
        $this->createIndex(null, '{{%stub_services}}', ['dateDeleted']);
    }

    private function _createProvidersTable(): void
    {
        $this->createTable('{{%stub_providers}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'email' => $this->string(),
            'phone' => $this->string(),
            'bio' => $this->text(),
            'color' => $this->string(7)->defaultValue('#2563eb'),
            'timezone' => $this->string()->notNull()->defaultValue('America/New_York'),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_providers}}', ['handle'], true);
        $this->createIndex(null, '{{%stub_providers}}', ['userId']);
        $this->createIndex(null, '{{%stub_providers}}', ['enabled']);
        $this->createIndex(null, '{{%stub_providers}}', ['dateDeleted']);

        $this->addForeignKey(null, '{{%stub_providers}}', ['userId'], '{{%users}}', ['id'], 'SET NULL');
    }

    private function _createProviderSchedulesTable(): void
    {
        $this->createTable('{{%stub_provider_schedules}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'dayOfWeek' => $this->tinyInteger()->notNull(),
            'startTime' => $this->time()->notNull(),
            'endTime' => $this->time()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_provider_schedules}}', ['providerId', 'dayOfWeek'], true);

        $this->addForeignKey(null, '{{%stub_provider_schedules}}', ['providerId'], '{{%stub_providers}}', ['id'], 'CASCADE');
    }

    private function _createProviderBreaksTable(): void
    {
        $this->createTable('{{%stub_provider_breaks}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'dayOfWeek' => $this->tinyInteger()->notNull(),
            'startTime' => $this->time()->notNull(),
            'endTime' => $this->time()->notNull(),
            'label' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_provider_breaks}}', ['providerId', 'dayOfWeek']);

        $this->addForeignKey(null, '{{%stub_provider_breaks}}', ['providerId'], '{{%stub_providers}}', ['id'], 'CASCADE');
    }

    private function _createProviderBlockedDatesTable(): void
    {
        $this->createTable('{{%stub_provider_blocked_dates}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'startDate' => $this->date()->notNull(),
            'endDate' => $this->date()->notNull(),
            'reason' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_provider_blocked_dates}}', ['providerId', 'startDate', 'endDate']);

        $this->addForeignKey(null, '{{%stub_provider_blocked_dates}}', ['providerId'], '{{%stub_providers}}', ['id'], 'CASCADE');
    }

    private function _createProviderServicesTable(): void
    {
        $this->createTable('{{%stub_provider_services}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'serviceId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_provider_services}}', ['providerId', 'serviceId'], true);

        $this->addForeignKey(null, '{{%stub_provider_services}}', ['providerId'], '{{%stub_providers}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%stub_provider_services}}', ['serviceId'], '{{%stub_services}}', ['id'], 'CASCADE');
    }

    private function _createCustomersTable(): void
    {
        $this->createTable('{{%stub_customers}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer(),
            'email' => $this->string()->notNull(),
            'firstName' => $this->string()->notNull(),
            'lastName' => $this->string()->notNull(),
            'phone' => $this->string(),
            'notes' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_customers}}', ['email']);
        $this->createIndex(null, '{{%stub_customers}}', ['userId']);

        $this->addForeignKey(null, '{{%stub_customers}}', ['userId'], '{{%users}}', ['id'], 'SET NULL');
    }

    private function _createBookingsTable(): void
    {
        $this->createTable('{{%stub_bookings}}', [
            'id' => $this->integer()->notNull(),
            'serviceId' => $this->integer()->notNull(),
            'providerId' => $this->integer()->notNull(),
            'customerId' => $this->integer()->notNull(),
            'bookingStatus' => $this->string()->notNull()->defaultValue('pending'),
            'startDateTime' => $this->dateTime()->notNull(),
            'endDateTime' => $this->dateTime()->notNull(),
            'timezone' => $this->string()->notNull(),
            'referenceNumber' => $this->string()->notNull(),
            'price' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'currency' => $this->char(3)->notNull()->defaultValue('USD'),
            'customerNotes' => $this->text(),
            'adminNotes' => $this->text(),
            'paymentStatus' => $this->string()->notNull()->defaultValue('unpaid'),
            'stripePaymentIntentId' => $this->string(),
            'paidAt' => $this->dateTime(),
            'cancelledAt' => $this->dateTime(),
            'cancellationReason' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%stub_bookings}}', ['serviceId']);
        $this->createIndex(null, '{{%stub_bookings}}', ['providerId']);
        $this->createIndex(null, '{{%stub_bookings}}', ['customerId']);
        $this->createIndex(null, '{{%stub_bookings}}', ['bookingStatus']);
        $this->createIndex(null, '{{%stub_bookings}}', ['startDateTime']);
        $this->createIndex(null, '{{%stub_bookings}}', ['endDateTime']);
        $this->createIndex(null, '{{%stub_bookings}}', ['referenceNumber'], true);
        $this->createIndex(null, '{{%stub_bookings}}', ['paymentStatus']);

        $this->addForeignKey(null, '{{%stub_bookings}}', ['id'], '{{%elements}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%stub_bookings}}', ['serviceId'], '{{%stub_services}}', ['id']);
        $this->addForeignKey(null, '{{%stub_bookings}}', ['providerId'], '{{%stub_providers}}', ['id']);
        $this->addForeignKey(null, '{{%stub_bookings}}', ['customerId'], '{{%stub_customers}}', ['id']);
    }

    private function _createPaymentsTable(): void
    {
        $this->createTable('{{%stub_payments}}', [
            'id' => $this->primaryKey(),
            'bookingId' => $this->integer()->notNull(),
            'stripePaymentIntentId' => $this->string(),
            'stripeChargeId' => $this->string(),
            'amount' => $this->decimal(14, 4)->notNull(),
            'currency' => $this->char(3)->notNull()->defaultValue('USD'),
            'status' => $this->string()->notNull()->defaultValue('pending'),
            'stripeResponse' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%stub_payments}}', ['bookingId']);
        $this->createIndex(null, '{{%stub_payments}}', ['stripePaymentIntentId']);

        $this->addForeignKey(null, '{{%stub_payments}}', ['bookingId'], '{{%elements}}', ['id'], 'CASCADE');
    }
}
