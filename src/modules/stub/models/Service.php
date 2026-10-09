<?php

namespace justinholtweb\stub\models;

use Craft;
use craft\base\Model;
use craft\validators\ColorValidator;
use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\helpers\PaymentHelper;

class Service extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public int $duration = 60;
    public float $price = 0;
    public string $currency = 'USD';

    /**
     * How the price is collected — a {@see PaymentMode} value. Copied onto each booking.
     */
    public string $paymentMode = 'full';

    /**
     * For a deposit: `percent` of the price, or a `fixed` sum in the service's currency.
     */
    public string $depositType = PaymentHelper::DEPOSIT_PERCENT;
    public float $depositValue = 0;

    public int $bufferTimeBefore = 0;
    public int $bufferTimeAfter = 0;
    public int $capacity = 1;
    public string $color = '#2563eb';
    public bool $enabled = true;
    public int $sortOrder = 0;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $dateDeleted = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     * These audit columns are stored and consumed as strings, so opt out of
     * Craft's automatic DateTime casting (which would violate the ?string types).
     */
    public function datetimeAttributes(): array
    {
        return [];
    }

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'duration', 'currency'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            [['duration', 'bufferTimeBefore', 'bufferTimeAfter', 'capacity', 'sortOrder'], 'integer', 'min' => 0],
            [['duration', 'capacity'], 'integer', 'min' => 1],
            [['price'], 'number', 'min' => 0],
            [['currency'], 'string', 'length' => 3],
            // ISO 4217 alphabetic codes are uppercase; the shape check is deliberately
            // looser than a list membership check, since Commerce can supply codes the
            // built-in picker list doesn't carry.
            [['currency'], 'match', 'pattern' => '/^[A-Z]{3}$/'],
            // Craft's color input posts the hex without a leading `#`, so normalize
            // before matching. The pattern excludes `transparent` (too long for the
            // `char(7)` column) that ColorValidator would otherwise allow.
            [['color'], ColorValidator::class, 'pattern' => '/^#[0-9a-f]{6}$/'],
            [['paymentMode'], 'in', 'range' => array_column(PaymentMode::cases(), 'value')],
            [['depositType'], 'in', 'range' => [PaymentHelper::DEPOSIT_PERCENT, PaymentHelper::DEPOSIT_FIXED]],
            [['depositValue'], 'number', 'min' => 0],
            [['depositValue'], 'validateDeposit'],
        ];
    }

    /**
     * A deposit service has to ask for something, and no more than the price. A deposit of
     * nothing would confirm the booking with no payment at all, which is what pay-in-person
     * is for; a deposit of the whole price is full payment under another name.
     */
    public function validateDeposit(string $attribute): void
    {
        if ($this->paymentMode !== PaymentMode::Deposit->value || $this->price <= 0) {
            return;
        }

        if ($this->depositValue <= 0) {
            $this->addError($attribute, Craft::t('stub', 'Enter the deposit to take at booking.'));
        } elseif ($this->depositType === PaymentHelper::DEPOSIT_PERCENT && $this->depositValue >= 100) {
            $this->addError($attribute, Craft::t('stub', 'A deposit must be less than 100% of the price. Use full payment to take the whole price.'));
        } elseif ($this->depositType === PaymentHelper::DEPOSIT_FIXED && $this->depositValue >= $this->price) {
            $this->addError($attribute, Craft::t('stub', 'A deposit must be less than the price. Use full payment to take the whole price.'));
        }
    }

    public function getPaymentModeEnum(): PaymentMode
    {
        return PaymentMode::tryFrom($this->paymentMode) ?? PaymentMode::Full;
    }

    /**
     * The deposit this service asks for on a given price — the booking's, which a member
     * discount may have lowered from the service's own.
     */
    public function depositFor(float $price): float
    {
        if ($this->getPaymentModeEnum() !== PaymentMode::Deposit) {
            return 0.0;
        }

        return PaymentHelper::depositAmount($price, $this->depositType, $this->depositValue, $this->currency);
    }
}
