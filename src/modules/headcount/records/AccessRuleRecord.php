<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $elementType
 * @property string $type
 * @property int|null $targetId
 * @property string|null $targetUid
 * @property string|null $planIds
 * @property string $behavior
 * @property string|null $redirectUrl
 * @property int|null $teaserLength
 * @property int $sortOrder
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AccessRuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_access_rules}}';
    }
}
