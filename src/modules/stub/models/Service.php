<?php

namespace justinholtweb\stub\models;

use craft\base\Model;
use craft\validators\ColorValidator;

class Service extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public int $duration = 60;
    public float $price = 0;
    public string $currency = 'USD';
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
            // Craft's color input posts the hex without a leading `#`, so normalize
            // before matching. The pattern excludes `transparent` (too long for the
            // `char(7)` column) that ColorValidator would otherwise allow.
            [['color'], ColorValidator::class, 'pattern' => '/^#[0-9a-f]{6}$/'],
        ];
    }
}
