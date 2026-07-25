<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class ProviderBlockedDate extends Model
{
    public ?int $id = null;
    public ?int $providerId = null;
    public string $startDate = '';
    public string $endDate = '';
    public ?string $reason = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
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
            [['providerId', 'startDate', 'endDate'], 'required'],
            [['startDate', 'endDate'], 'date', 'format' => 'php:Y-m-d'],
        ];
    }
}
