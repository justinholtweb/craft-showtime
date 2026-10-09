<?php

namespace justinholtweb\stub\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Deposits and pay-in-person.
 *
 * Services gain a payment mode and a deposit; bookings record the mode they were made under,
 * the deposit asked for, and how much has been received; and `stub_payments` becomes a ledger of
 * every payment — Stripe's and the ones staff record — with the moment the money arrived.
 *
 * Every existing service is `full`, which is what they all were. The backfill makes the ledger
 * agree with the bookings already paid, so a month's revenue reads the same before and after:
 * a paid booking's `amountPaid` is its price, a succeeded Stripe payment takes the booking's
 * `paidAt`, and a booking marked paid with no payment behind it (a manual booking entered as
 * paid) gets a `manual` ledger row for its price.
 */
class m261008_000000_payment_modes extends Migration
{
    public function safeUp(): bool
    {
        $services = '{{%stub_services}}';
        $bookings = '{{%stub_bookings}}';
        $payments = '{{%stub_payments}}';

        if (!$this->db->columnExists($services, 'paymentMode')) {
            $this->addColumn($services, 'paymentMode', $this->string(16)->notNull()->defaultValue('full')->after('currency'));
            $this->addColumn($services, 'depositType', $this->string(16)->notNull()->defaultValue('percent')->after('paymentMode'));
            $this->addColumn($services, 'depositValue', $this->decimal(14, 4)->notNull()->defaultValue(0)->after('depositType'));
        }

        if (!$this->db->columnExists($bookings, 'paymentMode')) {
            $this->addColumn($bookings, 'paymentMode', $this->string(16)->notNull()->defaultValue('full')->after('paymentStatus'));
            $this->addColumn($bookings, 'depositAmount', $this->decimal(14, 4)->notNull()->defaultValue(0)->after('paymentMode'));
            $this->addColumn($bookings, 'amountPaid', $this->decimal(14, 4)->notNull()->defaultValue(0)->after('depositAmount'));

            $this->update($bookings, ['amountPaid' => new \yii\db\Expression('[[price]]')], ['paymentStatus' => 'paid'], [], false);
        }

        if (!$this->db->columnExists($payments, 'method')) {
            $this->addColumn($payments, 'method', $this->string(16)->notNull()->defaultValue('stripe')->after('status'));
            $this->addColumn($payments, 'paidAt', $this->dateTime()->after('method'));
            $this->addColumn($payments, 'note', $this->string()->after('paidAt'));
            $this->addColumn($payments, 'recordedById', $this->integer()->after('note'));
            $this->createIndex(null, $payments, ['status', 'paidAt']);
            $this->addForeignKey(null, $payments, ['recordedById'], '{{%users}}', ['id'], 'SET NULL');

            $this->_backfillLedger();
        }

        return true;
    }

    private function _backfillLedger(): void
    {
        $succeeded = (new Query())
            ->select(['p.id', 'p.bookingId', 'p.dateUpdated', 'ledgerPaidAt' => 'p.paidAt', 'b.paidAt'])
            ->from(['p' => '{{%stub_payments}}'])
            ->leftJoin(['b' => '{{%stub_bookings}}'], '[[b.id]] = [[p.bookingId]]')
            ->where(['p.status' => 'succeeded'])
            ->all($this->db);

        // Only rows with no date yet: idempotent, and never moves a date the ledger already has.
        $covered = [];
        foreach ($succeeded as $row) {
            if ($row['ledgerPaidAt'] === null) {
                $this->update('{{%stub_payments}}', ['paidAt' => $row['paidAt'] ?? $row['dateUpdated']], ['id' => $row['id']], [], false);
            }
            $covered[(int)$row['bookingId']] = true;
        }

        $paidWithoutPayment = (new Query())
            ->select(['id', 'price', 'currency', 'paidAt', 'dateUpdated'])
            ->from('{{%stub_bookings}}')
            ->where(['paymentStatus' => 'paid'])
            ->andWhere(['>', 'price', 0])
            ->all($this->db);

        foreach ($paidWithoutPayment as $row) {
            if (isset($covered[(int)$row['id']])) {
                continue;
            }

            $this->insert('{{%stub_payments}}', [
                'bookingId' => (int)$row['id'],
                'amount' => $row['price'],
                'currency' => $row['currency'],
                'status' => 'succeeded',
                'method' => 'manual',
                'paidAt' => $row['paidAt'] ?? $row['dateUpdated'],
            ]);
        }
    }

    public function safeDown(): bool
    {
        echo "m261008_000000_payment_modes cannot be reverted.\n";

        return false;
    }
}
