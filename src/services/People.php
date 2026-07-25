<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\elements\User;
use justinholtweb\headcount\Headcount;
use justinholtweb\owl\Owl;
use justinholtweb\showtime\models\Person;
use justinholtweb\showtime\Plugin;
use justinholtweb\stub\Plugin as Stub;
use Throwable;

/**
 * The identity graph: one person's bookings, membership and event tickets in one object.
 *
 * §7.1 of the plan, and deliberately the *narrow* version of it. Nothing here writes, nothing
 * merges schemas, and no plugin gains a foreign key to another: each of the three exposes a
 * small resolver keyed on an email address, and this joins their answers at read time.
 * That's the whole trick, and it's why all three still ship on their own.
 *
 * Every lookup is wrapped: a module that errors contributes nothing rather than taking the
 * screen down with it. A person panel missing one section is legible; a 500 isn't.
 */
class People extends Component
{
    /**
     * Assemble what the bundle knows about one person.
     *
     * Accepts a Craft user or a bare email address, because half the records involved never
     * had a user attached in the first place.
     */
    public function find(User|string $subject): Person
    {
        $user = $subject instanceof User ? $subject : Craft::$app->getUsers()->getUserByUsernameOrEmail($subject);
        $email = $subject instanceof User ? (string)$subject->email : trim($subject);

        $person = new Person([
            'email' => $email,
            'user' => $user,
        ]);

        if ($email === '') {
            return $person;
        }

        $this->addBookings($person);
        $this->addSubscriptions($person);
        $this->addRegistrations($person);

        return $person;
    }

    /**
     * Addresses matching a search term, for the CP lookup.
     *
     * Searches Stub's customers and Craft's users, since between them they cover everyone
     * with a name on file. Someone known only by a ticket order won't appear — you'd have to
     * search Commerce for that, and the exact address always resolves regardless.
     *
     * @return array<string, string> email => display name
     */
    public function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $found = [];

        /** @var Stub|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub !== null) {
            try {
                foreach ($stub->customers->searchCustomers($query) as $customer) {
                    if ($customer->email) {
                        $found[strtolower($customer->email)] = $customer->getFullName() ?: $customer->email;
                    }
                }
            } catch (Throwable $e) {
                Craft::warning("Showtime people: customer search failed — {$e->getMessage()}", __METHOD__);
            }
        }

        foreach (User::find()->search($query)->limit(50)->all() as $user) {
            if ($user->email) {
                // Don't let a user record overwrite a customer's fuller name.
                $found[strtolower($user->email)] ??= $user->getFullName() ?: $user->username ?: $user->email;
            }
        }

        ksort($found);

        return $found;
    }

    private function addBookings(Person $person): void
    {
        /** @var Stub|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub === null) {
            return;
        }

        try {
            $customer = $stub->customers->getCustomerByEmail($person->email);

            if ($customer === null && $person->user !== null) {
                $customer = $stub->customers->getCustomerForUser($person->user);
            }

            if ($customer === null) {
                return;
            }

            $person->customer = $customer;
            $person->bookings = $customer->id ? $stub->customers->getBookingsForCustomer($customer->id) : [];
        } catch (Throwable $e) {
            Craft::warning("Showtime people: couldn't read bookings — {$e->getMessage()}", __METHOD__);
        }
    }

    private function addSubscriptions(Person $person): void
    {
        /** @var Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        if ($headcount === null) {
            return;
        }

        try {
            $person->subscriptions = $headcount->members->subscriptionsForEmail($person->email);
        } catch (Throwable $e) {
            Craft::warning("Showtime people: couldn't read subscriptions — {$e->getMessage()}", __METHOD__);
        }
    }

    private function addRegistrations(Person $person): void
    {
        if (Plugin::getInstance()->getModuleByHandle('owl') === null) {
            return;
        }

        try {
            $person->registrations = Owl::getInstance()->tickets->registrationsForEmail($person->email);
        } catch (Throwable $e) {
            Craft::warning("Showtime people: couldn't read ticket orders — {$e->getMessage()}", __METHOD__);
        }
    }
}
