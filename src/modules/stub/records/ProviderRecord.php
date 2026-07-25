<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;

/**
 * @property int $id
 * @property int|null $userId
 * @property string $name
 * @property string $handle
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $bio
 * @property string|null $color
 * @property string $timezone
 * @property bool $enabled
 * @property int $sortOrder
 */
class ProviderRecord extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return '{{%stub_providers}}';
    }
}
