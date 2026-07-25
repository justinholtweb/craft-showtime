<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use justinholtweb\headcount\events\MatchGateRuleEvent;
use justinholtweb\headcount\events\RegisterGateTargetsEvent;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\GateTarget;
use justinholtweb\owl\elements\Event as OwlEvent;
use justinholtweb\owl\events\FeedItemsEvent;
use justinholtweb\owl\Owl;
use justinholtweb\showtime\Plugin;
use Throwable;

/**
 * Member-only events: Headcount's access rules pointed at Owl.
 *
 * Headcount generalised gating past entries in 5.5.0 but deliberately doesn't know what an
 * event or a calendar is, so the host supplies both halves — the target that makes events
 * selectable in the rule editor, and the matcher that answers "is this event covered?".
 *
 * **Gating is about access; perks are about money.** A perk decides what a member *pays*
 * for a ticket (and can restrict who may buy one); a gate decides who may *see* the event
 * at all. They overlap in exactly one place — a gated event's tickets must not be buyable
 * by someone who can't see it — and {@see guardTicketPurchase()} is that overlap.
 */
class Gates extends Component
{
    /** Scope key for "every event on this calendar". Namespaced; Headcount hands it back. */
    public const SCOPE_CALENDAR = 'owl:calendar';

    /** @var array<int, bool> event ID => may the current user see it (per request) */
    private array $_access = [];

    /**
     * Make Owl events gateable in the access-rule editor.
     */
    public function registerTargets(RegisterGateTargetsEvent $event): void
    {
        if (Plugin::getInstance()->getModuleByHandle('owl') === null) {
            return;
        }

        $calendarOptions = [];

        try {
            foreach (Owl::getInstance()->calendars->getAllCalendars() as $calendar) {
                $calendarOptions[] = ['label' => $calendar->name, 'value' => $calendar->id];
            }
        } catch (Throwable $e) {
            Craft::warning("Showtime: couldn't list calendars for the gate editor — {$e->getMessage()}", __METHOD__);
        }

        $event->targets[OwlEvent::class] = new GateTarget([
            'elementType' => OwlEvent::class,
            'label' => Craft::t('showtime', 'Events'),
            'scopes' => [
                GateTarget::SCOPE_ALL => [
                    'label' => Craft::t('showtime', 'All events'),
                    'target' => 'none',
                ],
                self::SCOPE_CALENDAR => [
                    'label' => Craft::t('showtime', 'Events on a calendar'),
                    'target' => 'options',
                    'options' => $calendarOptions,
                ],
                GateTarget::SCOPE_ELEMENT => [
                    'label' => Craft::t('showtime', 'One specific event'),
                    'target' => 'element',
                ],
            ],
        ]);
    }

    /**
     * Answer Headcount's "does this rule cover this element?" for the calendar scope.
     *
     * Only claims rules that are unmistakably ours. Leaving `matches` null means "not mine"
     * — the safe answer, because an unclaimed rule gates nothing rather than everything.
     */
    public function matchRule(MatchGateRuleEvent $event): void
    {
        if ($event->rule->type !== self::SCOPE_CALENDAR || !$event->element instanceof OwlEvent) {
            return;
        }

        $event->matches = $event->rule->targetId !== null
            && $event->rule->targetId === $event->element->calendarId;
    }

    /**
     * Drop gated events from the calendar feed.
     *
     * `owl/events.json` is anonymous, so without this a member-only calendar still publishes
     * every event's title, time and URL — the gate would only cover the page it links to.
     * Bookings contributed by {@see CalendarFeed} are left alone: they carry their own
     * permission check and their own ID space.
     */
    public function filterFeed(FeedItemsEvent $event): void
    {
        if (!$this->hasEventRules()) {
            return;
        }

        $event->items = array_values(array_filter(
            $event->items,
            function(array $item) {
                if (isset($item['showtimeType']) || !is_numeric($item['id'] ?? null)) {
                    return true;
                }

                return $this->canSeeEvent((int)$item['id']);
            },
        ));
    }

    /**
     * Refuse a ticket for an event the buyer isn't allowed to see.
     *
     * Called on Commerce's add-to-cart path alongside the perks check. Without it, hiding an
     * event behind a plan would still leave its ticket purchasable by anyone who knows the
     * purchasable ID — the gate would protect the page and not the thing the page sells.
     */
    public function guardTicketPurchase(\craft\commerce\models\LineItem $lineItem): bool
    {
        $purchasable = $lineItem->getPurchasable();

        if (!$purchasable instanceof \justinholtweb\owl\elements\Ticket) {
            return true;
        }

        if (!$this->hasEventRules()) {
            return true;
        }

        $eventId = $purchasable->eventId;

        if ($eventId === null) {
            return true;
        }

        return $this->canSeeEvent((int)$eventId, $lineItem->getOrder()?->getCustomer());
    }

    /**
     * Whether any enabled access rule targets events at all.
     *
     * The overwhelmingly common answer is no, and it costs one already-memoized rule read —
     * which keeps the feed at zero extra queries on sites that never gate an event.
     */
    private function hasEventRules(): bool
    {
        /** @var Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        if ($headcount === null) {
            return false;
        }

        try {
            foreach ($headcount->gating->getAllRules(true) as $rule) {
                if ($rule->elementType === OwlEvent::class) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            Craft::warning("Showtime: couldn't read access rules — {$e->getMessage()}", __METHOD__);
        }

        return false;
    }

    /**
     * Whether a user may see one event, memoized for the request.
     *
     * A feed can carry the same event many times over (one item per occurrence), and each
     * evaluation costs a subscription lookup.
     */
    private function canSeeEvent(int $eventId, ?User $user = null): bool
    {
        $cacheable = $user === null;

        if ($cacheable && isset($this->_access[$eventId])) {
            return $this->_access[$eventId];
        }

        /** @var Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');
        $event = OwlEvent::find()->id($eventId)->status(null)->one();

        if ($headcount === null || $event === null) {
            // A feed item whose event we can't load is a data problem, not an access one —
            // and refusing here would blank the calendar. Let it through and log.
            Craft::warning("Showtime: gate skipped, event $eventId not found", __METHOD__);
            return true;
        }

        $allowed = $headcount->gating->canAccess($event, $user);

        if ($cacheable) {
            $this->_access[$eventId] = $allowed;
        }

        return $allowed;
    }
}
