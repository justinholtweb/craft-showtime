<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $userId
 * @property string $email
 * @property string $firstName
 * @property string $lastName
 * @property string|null $phone
 * @property string|null $notes
 */
class CustomerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_customers}}';
    }
}
