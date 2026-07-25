<?php

namespace justinholtweb\showtime\models;

use craft\base\Model;

/**
 * A membership perk: what holding a given plan does to something another module sells.
 */
class Perk extends Model
{
    public const TARGET_STUB_SERVICE = 'stub:service';
    public const TARGET_OWL_TICKET = 'owl:ticket';

    /**
     * Target type => human label, for the CP select.
     */
    public const TARGET_LABELS = [
        self::TARGET_STUB_SERVICE => 'Bookable service',
        self::TARGET_OWL_TICKET => 'Event ticket',
    ];

    public ?int $id = null;
    public ?int $planId = null;
    public string $targetType = self::TARGET_STUB_SERVICE;
    public ?int $targetId = null;

    /** Non-members can't book/buy this at all. */
    public bool $membersOnly = false;

    public ?float $discountPercent = null;
    public ?float $discountAmount = null;
    public bool $enabled = true;

    public ?string $uid = null;

    /**
     * Craft's base Model casts these to DateTime, but they're read as strings here.
     */
    public function datetimeAttributes(): array
    {
        return [];
    }

    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    public function defineRules(): array
    {
        return [
            [['planId', 'targetType', 'targetId'], 'required'],
            [['planId', 'targetId'], 'integer'],
            [['targetType'], 'string', 'max' => 64],
            [['discountPercent'], 'number', 'min' => 0, 'max' => 100],
            [['discountAmount'], 'number', 'min' => 0],
            [['membersOnly', 'enabled'], 'boolean'],
        ];
    }

    /**
     * This perk's price for something that normally costs $price.
     *
     * Percent is applied before a flat amount, so "20% off, then $5 off" reads the way it's
     * written. Never returns less than zero — a discount larger than the price means free,
     * not a refund.
     */
    public function appliedTo(float $price): float
    {
        if ($this->discountPercent !== null) {
            $price -= $price * ($this->discountPercent / 100);
        }

        if ($this->discountAmount !== null) {
            $price -= $this->discountAmount;
        }

        return max(0.0, round($price, 4));
    }

    /**
     * Whether this perk changes the price at all (as opposed to only granting access).
     */
    public function isDiscount(): bool
    {
        return $this->discountPercent !== null || $this->discountAmount !== null;
    }
}
