<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\showtime\models\Perk;
use justinholtweb\showtime\Plugin;
use Throwable;

/**
 * Member perks — the bundle's headline feature, and the reason it costs more than any one
 * plugin: a Headcount plan changing what a member pays for a Stub booking.
 *
 * It lives in the host rather than in either plugin because it is a relationship *between*
 * them. Stub knows nothing about plans; Headcount knows nothing about services. Neither can
 * own this without depending on the other, which would end both of them shipping standalone.
 */
class Perks extends Component
{
    /**
     * @return Perk[]
     */
    public function getAllPerks(): array
    {
        return array_map(
            fn(array $row) => new Perk($row),
            $this->query()->orderBy(['targetType' => SORT_ASC, 'targetId' => SORT_ASC])->all(),
        );
    }

    public function getPerkById(int $id): ?Perk
    {
        $row = $this->query()->where(['id' => $id])->one();

        return $row ? new Perk($row) : null;
    }

    /**
     * Every enabled perk attached to one thing.
     *
     * @return Perk[]
     */
    public function forTarget(string $targetType, int $targetId): array
    {
        return array_map(
            fn(array $row) => new Perk($row),
            $this->query()
                ->where(['targetType' => $targetType, 'targetId' => $targetId, 'enabled' => true])
                ->all(),
        );
    }

    /**
     * The best perk this user actually qualifies for on this target, or null.
     *
     * "Best" is the one leaving the lowest price, so overlapping plans never punish someone
     * for holding two of them. Access-only perks (no discount) qualify but don't compete on
     * price.
     */
    public function bestForUser(?User $user, string $targetType, int $targetId, float $price): ?Perk
    {
        if ($user === null) {
            return null;
        }

        $planIds = $this->activePlanIds($user);

        if ($planIds === []) {
            return null;
        }

        $best = null;
        $bestPrice = null;

        foreach ($this->forTarget($targetType, $targetId) as $perk) {
            if (!in_array($perk->planId, $planIds, true)) {
                continue;
            }

            $candidate = $perk->appliedTo($price);

            if ($bestPrice === null || $candidate < $bestPrice) {
                $best = $perk;
                $bestPrice = $candidate;
            }
        }

        return $best;
    }

    /**
     * Is this target restricted to members, and does this user clear it?
     *
     * Returns true when access is allowed — including when no perk marks it members-only,
     * which is the overwhelmingly common case.
     */
    public function canAccess(?User $user, string $targetType, int $targetId): bool
    {
        $restricted = array_filter(
            $this->forTarget($targetType, $targetId),
            fn(Perk $perk) => $perk->membersOnly,
        );

        if ($restricted === []) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $planIds = $this->activePlanIds($user);

        foreach ($restricted as $perk) {
            if (in_array($perk->planId, $planIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the booker's membership to a booking before it saves.
     *
     * Called from Stub's EVENT_BEFORE_SAVE_BOOKING, which fires before the element is saved
     * and before Stub derives the Stripe amount from `$booking->price` — so adjusting the
     * price here is what the member is actually charged, not just what they're shown.
     *
     * Returns false to refuse the booking (members-only service, non-member booker); the
     * caller turns that into a validation error rather than a silent free booking.
     */
    public function applyToBooking(\justinholtweb\stub\elements\Booking $booking): bool
    {
        if (!$booking->serviceId) {
            return true;
        }

        $user = $this->userForBooking($booking);

        if (!$this->canAccess($user, Perk::TARGET_STUB_SERVICE, $booking->serviceId)) {
            return false;
        }

        $perk = $this->bestForUser($user, Perk::TARGET_STUB_SERVICE, $booking->serviceId, (float)$booking->price);

        if ($perk !== null && $perk->isDiscount()) {
            $booking->price = $perk->appliedTo((float)$booking->price);
        }

        return true;
    }

    /**
     * The Craft user behind a booking, if there is one.
     *
     * Stub's customers are their own records and only optionally linked to a Craft user, so
     * fall back to matching on email — someone who booked as a guest with the same address
     * their membership uses is still that member.
     */
    private function userForBooking(\justinholtweb\stub\elements\Booking $booking): ?User
    {
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub === null || !$booking->customerId) {
            return null;
        }

        try {
            $customer = $stub->customers->getCustomerById($booking->customerId);
        } catch (Throwable $e) {
            Craft::warning("Showtime perks: couldn't read the booking's customer — {$e->getMessage()}", __METHOD__);
            return null;
        }

        if ($customer === null) {
            return null;
        }

        if ($customer->userId) {
            $user = Craft::$app->getUsers()->getUserById($customer->userId);
            if ($user !== null) {
                return $user;
            }
        }

        return $customer->email ? Craft::$app->getUsers()->getUserByUsernameOrEmail($customer->email) : null;
    }

    /**
     * Apply the buyer's membership to an event-ticket line item.
     *
     * Commerce owns ticket pricing — an Owl Ticket is a Purchasable, not a row with a price
     * column — so the discount is expressed as a promotional price on the line item rather
     * than by mutating the purchasable, which is shared by every order.
     *
     * Returns false when the buyer may not have this ticket at all, which the caller turns
     * into a refusal on the add-to-cart event.
     */
    public function applyToLineItem(\craft\commerce\models\LineItem $lineItem): bool
    {
        $purchasable = $lineItem->getPurchasable();

        if (!$purchasable instanceof \justinholtweb\owl\elements\Ticket) {
            return true;
        }

        $user = $lineItem->getOrder()?->getCustomer();
        $ticketId = (int)$purchasable->id;

        if (!$this->canAccess($user, Perk::TARGET_OWL_TICKET, $ticketId)) {
            return false;
        }

        $perk = $this->bestForUser($user, Perk::TARGET_OWL_TICKET, $ticketId, (float)$lineItem->price);

        if ($perk !== null && $perk->isDiscount()) {
            $lineItem->setPromotionalPrice($perk->appliedTo((float)$lineItem->price));
        }

        return true;
    }

    public function savePerk(Perk $perk, bool $runValidation = true): bool
    {
        if ($runValidation && !$perk->validate()) {
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());

        $attributes = [
            'planId' => $perk->planId,
            'targetType' => $perk->targetType,
            'targetId' => $perk->targetId,
            'membersOnly' => $perk->membersOnly,
            'discountPercent' => $perk->discountPercent,
            'discountAmount' => $perk->discountAmount,
            'enabled' => $perk->enabled,
            'dateUpdated' => $now,
        ];

        $db = Craft::$app->getDb();

        if ($perk->id) {
            $db->createCommand()->update('{{%showtime_perks}}', $attributes, ['id' => $perk->id])->execute();
            return true;
        }

        $attributes['dateCreated'] = $now;
        $attributes['uid'] = \craft\helpers\StringHelper::UUID();

        $db->createCommand()->insert('{{%showtime_perks}}', $attributes)->execute();
        $perk->id = (int)$db->getLastInsertID('{{%showtime_perks}}');

        return true;
    }

    public function deletePerkById(int $id): bool
    {
        Craft::$app->getDb()->createCommand()->delete('{{%showtime_perks}}', ['id' => $id])->execute();

        return true;
    }

    /**
     * The plan IDs this user currently holds an active subscription to.
     *
     * @return int[]
     */
    private function activePlanIds(User $user): array
    {
        /** @var \justinholtweb\headcount\Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        if ($headcount === null) {
            return [];
        }

        try {
            $subscriptions = $headcount->subscriptions->getActiveSubscriptionsForUser($user->id);
        } catch (Throwable $e) {
            Craft::warning("Showtime perks: couldn't read subscriptions — {$e->getMessage()}", __METHOD__);
            return [];
        }

        return array_values(array_filter(array_map(
            fn($subscription) => $subscription->planId !== null ? (int)$subscription->planId : null,
            $subscriptions,
        )));
    }

    private function query(): Query
    {
        return (new Query())
            ->select([
                'id', 'planId', 'targetType', 'targetId', 'membersOnly',
                'discountPercent', 'discountAmount', 'enabled',
                'dateCreated', 'dateUpdated', 'uid',
            ])
            ->from('{{%showtime_perks}}');
    }
}
