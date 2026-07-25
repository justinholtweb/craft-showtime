<?php

declare(strict_types=1);

namespace justinholtweb\owl\elements;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use justinholtweb\owl\elements\db\TicketQuery;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\TicketRecord;
use yii\validators\Validator;

/**
 * A ticket for an event — a Commerce purchasable. One Ticket per (event × ticket type), with its
 * own price, SKU, and optional capacity. Pro edition + Craft Commerce required.
 *
 * Capacity is managed by Owl (rather than Commerce inventory): {@see getIsAvailable()} blocks sold-
 * out tickets and {@see afterOrderComplete()} increments the sold counter.
 */
class Ticket extends Purchasable
{
    public ?int $eventId = null;
    public ?string $ticketName = null;
    public ?int $capacity = null;
    public int $sold = 0;

    private ?Event $_event = null;

    public static function displayName(): string
    {
        return Craft::t('owl', 'Ticket');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('owl', 'ticket');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('owl', 'Tickets');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('owl', 'tickets');
    }

    public static function hasInventory(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): TicketQuery
    {
        return new TicketQuery(static::class);
    }

    public function getEvent(): ?Event
    {
        if ($this->_event === null && $this->eventId !== null) {
            $this->_event = Owl::getInstance()->events->getEventById($this->eventId);
        }

        return $this->_event;
    }

    public function getDescription(): string
    {
        $eventTitle = $this->getEvent()?->title ?? Craft::t('owl', 'Event');

        return trim($eventTitle . ' — ' . (string)$this->ticketName);
    }

    public function getIsAvailable(): bool
    {
        if (!parent::getIsAvailable()) {
            return false;
        }

        return $this->capacity === null || $this->sold < $this->capacity;
    }

    /**
     * Remaining capacity, or null if unlimited.
     */
    public function getRemaining(): ?int
    {
        return $this->capacity === null ? null : max(0, $this->capacity - $this->sold);
    }

    /**
     * Enforce capacity at the line-item level. {@see getIsAvailable()} is only a boolean gate — it
     * cannot stop a single cart from requesting a quantity larger than the remaining capacity — so
     * without this a capacity-limited ticket could be oversold in one order (`hasInventory()` is
     * false, so Commerce's own stock checks are bypassed). The requested quantity is aggregated
     * across every line for this ticket in the cart, matching Commerce's own quantity validation.
     */
    public function getLineItemRules(LineItem $lineItem): array
    {
        $rules = parent::getLineItemRules($lineItem);

        $rules[] = [
            'qty',
            function(string $attribute, mixed $params, Validator $validator) use ($lineItem): void {
                $remaining = $this->getRemaining();
                if ($remaining === null) {
                    return;
                }

                $order = $lineItem->getOrder();
                if ($order !== null && $order->isCompleted) {
                    return;
                }

                $requested = (int)$lineItem->qty;
                if ($order !== null) {
                    foreach ($order->getLineItems() as $item) {
                        if ($item->purchasableId === $this->id && $item->id !== $lineItem->id) {
                            $requested += (int)$item->qty;
                        }
                    }
                }

                if ($requested > $remaining) {
                    $validator->addError($lineItem, $attribute, Craft::t('owl', 'Only {num} ticket(s) remaining for “{description}”.', [
                        'num' => $remaining,
                        'description' => $this->getDescription(),
                    ]));
                }
            },
        ];

        return $rules;
    }

    public function getSnapshot(): array
    {
        // Freeze the event/ticket details so the order survives later edits or deletion.
        return array_merge(parent::getSnapshot(), [
            'eventId' => $this->eventId,
            'eventTitle' => $this->getEvent()?->title,
            'ticketName' => $this->ticketName,
            'sku' => $this->getSku(),
            'description' => $this->getDescription(),
        ]);
    }

    public function afterOrderComplete(Order $order, LineItem $lineItem): void
    {
        $qty = (int)$lineItem->qty;

        if ($qty > 0 && $this->id !== null) {
            // Atomic increment so concurrent orders don't lose counts.
            TicketRecord::updateAllCounters(['sold' => $qty], ['id' => $this->id]);
            $this->sold += $qty;
        }

        parent::afterOrderComplete($order, $lineItem);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = (!$isNew ? TicketRecord::findOne($this->id) : null) ?? new TicketRecord();
            $record->id = (int)$this->id;
            $record->eventId = (int)$this->eventId;
            $record->ticketName = (string)$this->ticketName;
            $record->capacity = $this->capacity;

            if ($isNew) {
                $record->sold = 0;
            }

            $record->save(false);
        }

        parent::afterSave($isNew);
    }
}
