<?php

namespace justinholtweb\stub\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\stub\elements\Booking;

/**
 * @method Booking[] all($db = null)
 * @method Booking|null one($db = null)
 * @method Booking|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class BookingQuery extends ElementQuery
{
    public ?int $serviceId = null;
    public ?int $providerId = null;
    public ?int $customerId = null;
    public ?string $bookingStatus = null;
    public ?string $paymentStatus = null;
    public ?string $referenceNumber = null;
    public mixed $startDateTime = null;
    public mixed $endDateTime = null;

    public function __construct($elementType, array $config = [])
    {
        // Bookings use custom statuses (pending, confirmed, completed, ...), so Craft's
        // default 'enabled' status would resolve to `bookingStatus = 'enabled'` and never
        // match a real booking. Default to no status filter instead.
        $config['status'] ??= null;

        parent::__construct($elementType, $config);
    }

    public function serviceId(?int $value): self
    {
        $this->serviceId = $value;
        return $this;
    }

    public function providerId(?int $value): self
    {
        $this->providerId = $value;
        return $this;
    }

    public function customerId(?int $value): self
    {
        $this->customerId = $value;
        return $this;
    }

    public function bookingStatus(?string $value): self
    {
        $this->bookingStatus = $value;
        return $this;
    }

    public function paymentStatus(?string $value): self
    {
        $this->paymentStatus = $value;
        return $this;
    }

    public function referenceNumber(?string $value): self
    {
        $this->referenceNumber = $value;
        return $this;
    }

    public function startDateTime(mixed $value): self
    {
        $this->startDateTime = $value;
        return $this;
    }

    public function endDateTime(mixed $value): self
    {
        $this->endDateTime = $value;
        return $this;
    }

    protected function statusCondition(string $status): mixed
    {
        return ['stub_bookings.bookingStatus' => $status];
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('stub_bookings');

        $this->query->select([
            'stub_bookings.serviceId',
            'stub_bookings.providerId',
            'stub_bookings.customerId',
            'stub_bookings.bookingStatus',
            'stub_bookings.startDateTime',
            'stub_bookings.endDateTime',
            'stub_bookings.timezone',
            'stub_bookings.referenceNumber',
            'stub_bookings.price',
            'stub_bookings.currency',
            'stub_bookings.customerNotes',
            'stub_bookings.adminNotes',
            'stub_bookings.paymentStatus',
            'stub_bookings.stripePaymentIntentId',
            'stub_bookings.paidAt',
            'stub_bookings.cancelledAt',
            'stub_bookings.cancellationReason',
        ]);

        if ($this->serviceId) {
            $this->subQuery->andWhere(['stub_bookings.serviceId' => $this->serviceId]);
        }

        if ($this->providerId) {
            $this->subQuery->andWhere(['stub_bookings.providerId' => $this->providerId]);
        }

        if ($this->customerId) {
            $this->subQuery->andWhere(['stub_bookings.customerId' => $this->customerId]);
        }

        if ($this->bookingStatus) {
            $this->subQuery->andWhere(['stub_bookings.bookingStatus' => $this->bookingStatus]);
        }

        if ($this->paymentStatus) {
            $this->subQuery->andWhere(['stub_bookings.paymentStatus' => $this->paymentStatus]);
        }

        if ($this->referenceNumber) {
            $this->subQuery->andWhere(['stub_bookings.referenceNumber' => $this->referenceNumber]);
        }

        if ($this->startDateTime) {
            $this->subQuery->andWhere(Db::parseDateParam('stub_bookings.startDateTime', $this->startDateTime));
        }

        if ($this->endDateTime) {
            $this->subQuery->andWhere(Db::parseDateParam('stub_bookings.endDateTime', $this->endDateTime));
        }

        return parent::beforePrepare();
    }
}
