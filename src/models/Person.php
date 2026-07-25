<?php

namespace justinholtweb\showtime\models;

use craft\base\Model;
use craft\elements\User;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\models\Customer;

/**
 * One human, as the bundle knows them.
 *
 * The three plugins key on three different things — Stub on its own customer record,
 * Headcount on a Craft user, Owl on a Commerce order's email — and the plan is explicit
 * that merging those schemas would destabilise three shipping products. So this is a
 * **read-side join**, assembled per request and never stored: the email address is the
 * only thing all three reliably share, and it's the key.
 *
 * That means it inherits the email's weaknesses, and callers should know it: two people
 * sharing an address look like one person, and one person using two addresses looks like
 * two. Both are recoverable in a CP panel that a human reads. Neither would be acceptable
 * in something that decided what to charge — which is why perks resolve a user themselves
 * rather than going through here.
 */
class Person extends Model
{
    /** The address everything was joined on. */
    public string $email = '';

    public ?User $user = null;

    public ?Customer $customer = null;

    /** @var Booking[] newest first */
    public array $bookings = [];

    /** @var Subscription[] active first */
    public array $subscriptions = [];

    /** @var array<array<string, mixed>> completed ticket orders, newest first */
    public array $registrations = [];

    /**
     * The best name we have for them, falling back to the address.
     */
    public function getName(): string
    {
        $candidates = [
            $this->customer?->getFullName(),
            $this->user?->getFullName(),
            $this->user?->username,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        return $this->email;
    }

    /**
     * Whether the bundle knows anything at all about this address.
     */
    public function isKnown(): bool
    {
        return $this->user !== null
            || $this->customer !== null
            || $this->bookings !== []
            || $this->subscriptions !== []
            || $this->registrations !== [];
    }

    public function getActiveSubscription(): ?Subscription
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->status === 'active') {
                return $subscription;
            }
        }

        return null;
    }
}
