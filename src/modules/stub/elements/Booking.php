<?php

namespace justinholtweb\stub\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\stub\elements\db\BookingQuery;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\helpers\TimeHelper;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\records\BookingRecord;

class Booking extends Element
{
    public ?int $serviceId = null;
    public ?int $providerId = null;
    public ?int $customerId = null;
    public string $bookingStatus = 'pending';
    public ?string $startDateTime = null;
    public ?string $endDateTime = null;
    public string $timezone = 'UTC';
    public string $referenceNumber = '';
    public float $price = 0;
    public string $currency = 'USD';
    public ?string $customerNotes = null;
    public ?string $adminNotes = null;
    public string $paymentStatus = 'unpaid';
    public ?string $stripePaymentIntentId = null;
    public ?string $paidAt = null;
    public ?string $cancelledAt = null;
    public ?string $cancellationReason = null;

    // Cached relations
    private ?object $_service = null;
    private ?object $_provider = null;
    private ?object $_customer = null;

    public static function displayName(): string
    {
        return Craft::t('stub', 'Booking');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('stub', 'Bookings');
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            'pending' => ['label' => Craft::t('stub', 'Pending'), 'color' => 'orange'],
            'confirmed' => ['label' => Craft::t('stub', 'Confirmed'), 'color' => 'green'],
            'completed' => ['label' => Craft::t('stub', 'Completed'), 'color' => 'blue'],
            'cancelled' => ['label' => Craft::t('stub', 'Cancelled'), 'color' => 'red'],
            'noShow' => ['label' => Craft::t('stub', 'No Show'), 'color' => 'grey'],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->bookingStatus;
    }

    public static function find(): BookingQuery
    {
        return new BookingQuery(static::class);
    }

    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('stub:viewBookings');
    }

    public function canSave(\craft\elements\User $user): bool
    {
        return $user->can('stub:manageBookings');
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        return $user->can('stub:deleteBookings');
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("stub/bookings/{$this->id}");
    }

    protected function cpEditUrl(): ?string
    {
        return $this->getCpEditUrl();
    }

    public function getUiLabel(): string
    {
        return $this->referenceNumber ?: "Booking #{$this->id}";
    }

    protected static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('stub', 'All Bookings'),
                'criteria' => [],
            ],
            [
                'key' => 'status:pending',
                'label' => Craft::t('stub', 'Pending'),
                'criteria' => ['bookingStatus' => 'pending'],
            ],
            [
                'key' => 'status:confirmed',
                'label' => Craft::t('stub', 'Confirmed'),
                'criteria' => ['bookingStatus' => 'confirmed'],
            ],
            [
                'key' => 'status:completed',
                'label' => Craft::t('stub', 'Completed'),
                'criteria' => ['bookingStatus' => 'completed'],
            ],
            [
                'key' => 'status:cancelled',
                'label' => Craft::t('stub', 'Cancelled'),
                'criteria' => ['bookingStatus' => 'cancelled'],
            ],
            [
                'key' => 'status:noShow',
                'label' => Craft::t('stub', 'No Show'),
                'criteria' => ['bookingStatus' => 'noShow'],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'referenceNumber' => Craft::t('stub', 'Reference'),
            'bookingStatus' => Craft::t('stub', 'Status'),
            'serviceId' => Craft::t('stub', 'Service'),
            'providerId' => Craft::t('stub', 'Provider'),
            'customerId' => Craft::t('stub', 'Customer'),
            'startDateTime' => Craft::t('stub', 'Date & Time'),
            'price' => Craft::t('stub', 'Price'),
            'paymentStatus' => Craft::t('stub', 'Payment'),
            'dateCreated' => Craft::t('stub', 'Created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['referenceNumber', 'bookingStatus', 'serviceId', 'providerId', 'startDateTime', 'paymentStatus'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'startDateTime' => Craft::t('stub', 'Date & Time'),
            'dateCreated' => Craft::t('stub', 'Date Created'),
            'referenceNumber' => Craft::t('stub', 'Reference'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['referenceNumber'];
    }

    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'bookingStatus':
                $status = BookingStatus::tryFrom($this->bookingStatus);
                return $status ? "<span class='status {$status->color()}'></span>{$status->label()}" : $this->bookingStatus;

            case 'paymentStatus':
                $status = PaymentStatus::tryFrom($this->paymentStatus);
                return $status ? "<span class='status {$status->color()}'></span>{$status->label()}" : $this->paymentStatus;

            case 'serviceId':
                $service = $this->getService();
                return $service ? $service->name : '';

            case 'providerId':
                $provider = $this->getProvider();
                return $provider ? $provider->name : '';

            case 'customerId':
                $customer = $this->getCustomer();
                return $customer ? "{$customer->firstName} {$customer->lastName}" : '';

            case 'startDateTime':
                $dt = $this->getLocalStartDateTime();
                return $dt ? $dt->format('M j, Y g:i A') : '';

            case 'price':
                return \justinholtweb\stub\helpers\BookingHelper::formatPrice($this->price, $this->currency);
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * `startDateTime` is a naive UTC string. Anything that displays it needs a real
     * DateTime in the booking's own timezone — parsing the raw string elsewhere (Twig's
     * `date` filter, say) reads it as server-local and reports the wrong time.
     */
    public function getLocalStartDateTime(): ?DateTime
    {
        return $this->startDateTime !== null
            ? TimeHelper::convertFromUtc($this->startDateTime, $this->timezone)
            : null;
    }

    public function getLocalEndDateTime(): ?DateTime
    {
        return $this->endDateTime !== null
            ? TimeHelper::convertFromUtc($this->endDateTime, $this->timezone)
            : null;
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            Delete::class,
        ];
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            $record = new BookingRecord();
            $record->id = $this->id;
        } else {
            $record = BookingRecord::findOne($this->id);
            if (!$record) {
                throw new \Exception("Invalid booking ID: {$this->id}");
            }
        }

        $record->serviceId = $this->serviceId;
        $record->providerId = $this->providerId;
        $record->customerId = $this->customerId;
        $record->bookingStatus = $this->bookingStatus;
        $record->startDateTime = $this->startDateTime;
        $record->endDateTime = $this->endDateTime;
        $record->timezone = $this->timezone;
        $record->referenceNumber = $this->referenceNumber;
        $record->price = $this->price;
        $record->currency = $this->currency;
        $record->customerNotes = $this->customerNotes;
        $record->adminNotes = $this->adminNotes;
        $record->paymentStatus = $this->paymentStatus;
        $record->stripePaymentIntentId = $this->stripePaymentIntentId;
        $record->paidAt = $this->paidAt;
        $record->cancelledAt = $this->cancelledAt;
        $record->cancellationReason = $this->cancellationReason;

        $record->save(false);

        parent::afterSave($isNew);
    }

    // Relations

    public function getService(): ?object
    {
        if ($this->_service === null && $this->serviceId) {
            $this->_service = Plugin::getInstance()->services->getServiceById($this->serviceId);
        }
        return $this->_service;
    }

    public function getProvider(): ?object
    {
        if ($this->_provider === null && $this->providerId) {
            $this->_provider = Plugin::getInstance()->providers->getProviderById($this->providerId);
        }
        return $this->_provider;
    }

    public function getCustomer(): ?object
    {
        if ($this->_customer === null && $this->customerId) {
            $this->_customer = Plugin::getInstance()->customers->getCustomerById($this->customerId);
        }
        return $this->_customer;
    }

    public function getBookingStatusEnum(): BookingStatus
    {
        return BookingStatus::from($this->bookingStatus);
    }

    public function getPaymentStatusEnum(): PaymentStatus
    {
        return PaymentStatus::from($this->paymentStatus);
    }
}
