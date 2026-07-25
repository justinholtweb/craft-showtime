<?php

namespace justinholtweb\stub\models;

use craft\base\Model;

class ProviderService extends Model
{
    public ?int $id = null;
    public ?int $providerId = null;
    public ?int $serviceId = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['providerId', 'serviceId'], 'required'],
        ];
    }
}
