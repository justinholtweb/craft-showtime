<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $deviceLibraryIdentifier Apple's opaque per-device identifier
 * @property string $passTypeIdentifier
 * @property string $serialNumber
 * @property string $pushToken APNs token to notify when this pass changes
 * @property int|null $subscriptionId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class WalletRegistrationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_wallet_registrations}}';
    }
}
