<?php

namespace justinholtweb\stub\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $providerId
 * @property int $serviceId
 */
class ProviderServiceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%stub_provider_services}}';
    }
}
