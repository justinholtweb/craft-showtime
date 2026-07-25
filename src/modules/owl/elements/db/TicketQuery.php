<?php

declare(strict_types=1);

namespace justinholtweb\owl\elements\db;

use craft\commerce\elements\db\PurchasableQuery;
use craft\helpers\Db;

/**
 * Query for {@see \justinholtweb\owl\elements\Ticket} purchasables. Extends Commerce's
 * PurchasableQuery so SKU and pricing columns load alongside Owl's ticket columns.
 */
class TicketQuery extends PurchasableQuery
{
    public mixed $eventId = null;

    public function eventId(mixed $value): static
    {
        $this->eventId = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('owl_tickets');

        $this->query->select([
            'owl_tickets.eventId',
            'owl_tickets.ticketName',
            'owl_tickets.capacity',
            'owl_tickets.sold',
        ]);

        if ($this->eventId !== null) {
            $this->subQuery->andWhere(Db::parseParam('owl_tickets.eventId', $this->eventId));
        }

        return parent::beforePrepare();
    }
}
