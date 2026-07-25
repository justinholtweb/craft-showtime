<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class Customer extends Model
{
    public ?int $id = null;
    public ?int $userId = null;
    public string $email = '';
    public string $firstName = '';
    public string $lastName = '';
    public ?string $phone = null;
    public ?string $notes = null;
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
            [['email', 'firstName', 'lastName'], 'required'],
            [['email'], 'email'],
            [['firstName', 'lastName'], 'string', 'max' => 255],
        ];
    }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }
}
