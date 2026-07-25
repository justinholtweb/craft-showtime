<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;

class Plan extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public ?int $userGroupId = null;
    public ?string $stripePriceId = null;
    public ?string $paypalPlanId = null;
    public float $price = 0;
    public string $currency = 'USD';
    public string $billingInterval = 'month';
    public int $billingIntervalCount = 1;
    public int $trialDays = 0;
    public int $sortOrder = 0;
    public bool $enabled = true;
    public ?array $features = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'billingInterval', 'currency'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-z0-9\-]*$/'],
            [['description'], 'string'],
            [['userGroupId', 'billingIntervalCount', 'trialDays', 'sortOrder'], 'integer'],
            [['price'], 'number', 'min' => 0],
            [['currency'], 'string', 'max' => 3],
            [['billingInterval'], 'in', 'range' => ['day', 'week', 'month', 'year']],
            [['enabled'], 'boolean'],
            [['stripePriceId', 'paypalPlanId'], 'string', 'max' => 255],
        ];
    }

    public function getIntervalLabel(): string
    {
        $labels = [
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'year' => 'yearly',
        ];

        if ($this->billingIntervalCount === 1) {
            return $labels[$this->billingInterval] ?? $this->billingInterval;
        }

        return "every {$this->billingIntervalCount} {$this->billingInterval}s";
    }

    public function getFormattedPrice(): string
    {
        return strtoupper($this->currency) . ' ' . number_format($this->price, 2);
    }
}
