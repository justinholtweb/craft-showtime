<?php

declare(strict_types=1);

namespace justinholtweb\owl\records;

use craft\db\ActiveRecord;

/**
 * Per-ticket data (the Commerce purchasable columns live on Commerce's own tables).
 *
 * @property int $id Element id
 * @property int $eventId
 * @property string $ticketName
 * @property int|null $capacity Null = unlimited
 * @property int $sold
 */
class TicketRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%owl_tickets}}';
    }
}
