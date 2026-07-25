<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\elements\Ticket;

/**
 * Manages event tickets (Commerce purchasables). Pro edition + Craft Commerce required; this service
 * is only reachable when Commerce is installed (the element/purchasable types are registered then).
 */
class Tickets extends Component
{
    /**
     * @return Ticket[]
     */
    public function getTicketsForEvent(int $eventId): array
    {
        /** @var Ticket[] $tickets */
        $tickets = Ticket::find()->eventId($eventId)->status(null)->all();

        return $tickets;
    }

    public function getTicketById(int $id): ?Ticket
    {
        $ticket = Ticket::find()->id($id)->status(null)->one();

        return $ticket instanceof Ticket ? $ticket : null;
    }

    /**
     * Creates and saves a ticket for an event.
     *
     * Returns the ticket whether or not the save succeeded; callers must check
     * {@see Ticket::hasErrors()} (or the boolean-ish `$ticket->id`) before reporting success. A save
     * can fail on Commerce's unique-SKU constraint or other validation, and silently returning an
     * unsaved element would make the caller report a phantom "added" ticket.
     */
    public function createTicket(
        Event $event,
        string $name,
        float $price,
        ?int $capacity = null,
        ?string $sku = null,
    ): Ticket {
        $ticket = new Ticket();
        $ticket->eventId = (int)$event->id;
        $ticket->siteId = $event->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $ticket->ticketName = $name;
        $ticket->capacity = $capacity;
        $ticket->sku = $sku ?? $this->generateSku($event, $name);
        $ticket->basePrice = $price;
        $ticket->availableForPurchase = true;

        Craft::$app->getElements()->saveElement($ticket);

        return $ticket;
    }

    /**
     * Every ticket someone has actually bought, newest order first.
     *
     * Owl has no attendee table: a registration *is* a completed Commerce order containing a
     * ticket line item, and the line item's snapshot is what survives the event being edited
     * or deleted. Read straight from the order tables rather than through Commerce's element
     * queries — one query instead of one per order, and it doesn't need Commerce's classes to
     * be loadable.
     *
     * Matches on the order's email rather than its customer, because guest checkout is the
     * common case for event tickets and there's no user to match on.
     *
     * @return array<array{
     *     orderId: int, orderNumber: string, reference: ?string, dateOrdered: ?string,
     *     eventId: ?int, eventTitle: string, ticketName: string, qty: int, total: string,
     * }>
     */
    public function registrationsForEmail(string $email): array
    {
        if ($email === '' || !Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            return [];
        }

        $rows = (new Query())
            ->select([
                'o.id AS orderId', 'o.number', 'o.reference', 'o.dateOrdered',
                'li.purchasableId', 'li.description', 'li.qty', 'li.total', 'li.snapshot',
            ])
            ->from(['li' => '{{%commerce_lineitems}}'])
            ->innerJoin(['o' => '{{%commerce_orders}}'], '[[o.id]] = [[li.orderId]]')
            ->innerJoin(['t' => '{{%owl_tickets}}'], '[[t.id]] = [[li.purchasableId]]')
            ->where(['o.isCompleted' => true])
            ->andWhere(['o.email' => $email])
            ->orderBy(['o.dateOrdered' => SORT_DESC])
            ->all();

        return array_map(static function(array $row): array {
            // The snapshot is frozen at purchase time, so it still names the event even if
            // the event has since been renamed or deleted. Fall back to the line item's own
            // description, which Commerce always has.
            $snapshot = $row['snapshot'] ? json_decode((string)$row['snapshot'], true) : null;
            $snapshot = is_array($snapshot) ? $snapshot : [];

            return [
                'orderId' => (int)$row['orderId'],
                'orderNumber' => (string)$row['number'],
                'reference' => $row['reference'] !== null ? (string)$row['reference'] : null,
                'dateOrdered' => $row['dateOrdered'] !== null ? (string)$row['dateOrdered'] : null,
                'eventId' => isset($snapshot['eventId']) ? (int)$snapshot['eventId'] : null,
                'eventTitle' => (string)($snapshot['eventTitle'] ?? $row['description'] ?? ''),
                'ticketName' => (string)($snapshot['ticketName'] ?? ''),
                'qty' => (int)$row['qty'],
                'total' => (string)$row['total'],
            ];
        }, $rows);
    }

    private function generateSku(Event $event, string $name): string
    {
        $slug = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $name));

        // 8 hex chars keeps accidental collisions on the unique-SKU constraint vanishingly unlikely.
        return sprintf('OWL-%d-%s-%s', $event->id, $slug, strtoupper(substr(StringHelper::UUID(), 0, 8)));
    }
}
