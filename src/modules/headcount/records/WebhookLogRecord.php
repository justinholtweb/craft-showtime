<?php

namespace justinholtweb\headcount\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $gateway
 * @property string|null $eventId
 * @property string|null $eventType
 * @property string|null $payload
 * @property string $status
 * @property string|null $error
 * @property string $dateCreated
 */
class WebhookLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%headcount_webhook_logs}}';
    }
}
