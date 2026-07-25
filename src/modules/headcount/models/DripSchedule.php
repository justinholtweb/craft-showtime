<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;

class DripSchedule extends Model
{
    public ?int $id = null;
    public string $name = '';
    public ?array $planIds = null;
    public string $type = 'section';
    public ?int $targetId = null;
    public ?string $targetUid = null;
    public int $delayDays = 0;
    public bool $enabled = true;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name', 'type'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['type'], 'in', 'range' => ['section', 'entryType', 'category', 'entry']],
            [['targetId', 'delayDays'], 'integer'],
            [['delayDays'], 'integer', 'min' => 0],
            [['enabled'], 'boolean'],
        ];
    }
}
