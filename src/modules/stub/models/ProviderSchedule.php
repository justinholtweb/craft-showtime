<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class ProviderSchedule extends Model
{
    public ?int $id = null;
    public ?int $providerId = null;
    public int $dayOfWeek = 0;
    public string $startTime = '09:00';
    public string $endTime = '17:00';
    public bool $enabled = true;
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
            [['providerId', 'dayOfWeek', 'startTime', 'endTime'], 'required'],
            [['dayOfWeek'], 'integer', 'min' => 0, 'max' => 6],
        ];
    }

    public function getDayLabel(): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $days[$this->dayOfWeek] ?? '';
    }
}
