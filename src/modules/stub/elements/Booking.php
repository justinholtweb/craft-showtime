<?php

namespace justinholtweb\stub\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\enums\Color;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\stub\elements\db\BookingQuery;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\PaymentHelper;
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

    /**
     * The service's {@see PaymentMode} when the booking was made. A snapshot, so changing
     * the service later never changes what this booking owes or how.
     */
    public string $paymentMode = 'full';

    /**
     * The deposit taken online at booking, for a deposit booking; 0 otherwise.
     */
    public float $depositAmount = 0;

    /**
     * Everything received so far — the deposit, a Stripe payment, payments staff recorded.
     * The ledger behind it is `stub_payments`.
     */
    public float $amountPaid = 0;

    public ?string $stripePaymentIntentId = null;
    public ?string $paidAt = null;
    public ?string $cancelledAt = null;
    public ?string $cancellationReason = null;

    /**
     * When the reminder for this booking went out, as a naive UTC string. Null means it
     * hasn't — which is what stops the sweep sending a second copy on its next run.
     */
    public ?string $reminderSentAt = null;

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
            'pending' => ['label' => Craft::t('stub', 'Pending'), 'color' => Color::Orange],
            'confirmed' => ['label' => Craft::t('stub', 'Confirmed'), 'color' => Color::Green],
            'completed' => ['label' => Craft::t('stub', 'Completed'), 'color' => Color::Blue],
            'cancelled' => ['label' => Craft::t('stub', 'Cancelled'), 'color' => Color::Red],
            'noShow' => ['label' => Craft::t('stub', 'No Show'), 'color' => Color::Gray],
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

    protected static function defineSources(?string $context = null): array
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
            'amountPaid' => Craft::t('stub', 'Paid'),
            'balanceDue' => Craft::t('stub', 'Balance Due'),
            'paymentMode' => Craft::t('stub', 'Payment Mode'),
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
                return BookingHelper::formatPrice($this->price, $this->currency);

            case 'amountPaid':
                return BookingHelper::formatPrice($this->amountPaid, $this->currency);

            case 'balanceDue':
                return BookingHelper::formatPrice($this->getBalanceDue(), $this->currency);

            case 'paymentMode':
                return Craft::t('stub', $this->getPaymentModeEnum()->label());
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

    public function getLocalReminderSentAt(): ?DateTime
    {
        return $this->reminderSentAt !== null
            ? TimeHelper::convertFromUtc($this->reminderSentAt, $this->timezone)
            : null;
    }

    protected static function defineActions(?string $source = null): array
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
        $record->paymentMode = $this->paymentMode;
        $record->depositAmount = $this->depositAmount;
        $record->amountPaid = $this->amountPaid;
        $record->stripePaymentIntentId = $this->stripePaymentIntentId;
        $record->paidAt = $this->paidAt;
        $record->cancelledAt = $this->cancelledAt;
        $record->cancellationReason = $this->cancellationReason;
        $record->reminderSentAt = $this->reminderSentAt;

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

    public function getPaymentModeEnum(): PaymentMode
    {
        return PaymentMode::tryFrom($this->paymentMode) ?? PaymentMode::Full;
    }

    /**
     * What's still owed: the price less everything received.
     */
    public function getBalanceDue(): float
    {
        return PaymentHelper::balance((float)$this->price, (float)$this->amountPaid, $this->currency);
    }

    /**
     * What the online payment step should charge now — the whole balance, the deposit, or
     * nothing (pay in person, or already paid). Refunded and cancelled bookings owe nothing
     * online.
     */
    public function getAmountDueOnline(): float
    {
        if (in_array($this->paymentStatus, [PaymentStatus::Paid->value, PaymentStatus::Refunded->value], true)
            || $this->bookingStatus === BookingStatus::Cancelled->value) {
            return 0.0;
        }

        return PaymentHelper::amountDueOnline(
            $this->getPaymentModeEnum(),
            (float)$this->price,
            (float)$this->amountPaid,
            (float)$this->depositAmount,
            $this->currency,
        );
    }

    /**
     * One sentence on where the money stands — for emails and the booking form's confirmation.
     *
     * Worded so it reads the same to the customer and to staff.
     */
    public function getPaymentSummary(): string
    {
        $format = fn(float $amount) => BookingHelper::formatPrice($amount, $this->currency);
        $price = (float)$this->price;

        if ($price <= 0) {
            return Craft::t('stub', 'No payment is required.');
        }

        if ($this->paymentStatus === PaymentStatus::Refunded->value) {
            return Craft::t('stub', 'This booking has been refunded.');
        }

        $balance = $this->getBalanceDue();

        if ($balance <= 0) {
            return Craft::t('stub', 'Paid in full: {amount}.', ['amount' => $format($price)]);
        }

        if ($this->amountPaid > 0) {
            return Craft::t('stub', 'Paid: {paid}. Balance due at the appointment: {balance}.', [
                'paid' => $format((float)$this->amountPaid),
                'balance' => $format($balance),
            ]);
        }

        return match ($this->getPaymentModeEnum()) {
            PaymentMode::Deposit => Craft::t('stub', 'A deposit of {deposit} is due now; the remaining {balance} is due at the appointment.', [
                'deposit' => $format((float)$this->depositAmount),
                'balance' => $format(PaymentHelper::balance($price, (float)$this->depositAmount, $this->currency)),
            ]),
            PaymentMode::InPerson => Craft::t('stub', 'Payment of {amount} is due at the appointment.', ['amount' => $format($balance)]),
            PaymentMode::Full => Craft::t('stub', 'Amount due: {amount}.', ['amount' => $format($balance)]),
        };
    }
}
