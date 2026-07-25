<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property int $duration
 * @property float $price
 * @property string $currency
 * @property int $bufferTimeBefore
 * @property int $bufferTimeAfter
 * @property int $capacity
 * @property string $color
 * @property bool $enabled
 * @property int $sortOrder
 */
class ServiceRecord extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return '{{%stub_services}}';
    }
}
