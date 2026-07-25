<?php

namespace justinholtweb\showtime\twig;

use Craft;
use craft\elements\User;
use justinholtweb\showtime\models\Person;
use justinholtweb\showtime\Plugin;

/**
 * `craft.showtime` — the bundle's own template variable.
 *
 * Each mounted module keeps its own (`craft.stub`, `craft.headcount`, `craft.owl`); this is
 * only for the things that exist *because* they ship together.
 */
class ShowtimeVariable
{
    /**
     * Everything the bundle knows about one person: bookings, membership, ticket orders.
     *
     *     {% set person = craft.showtime.person(currentUser) %}
     *     {% set person = craft.showtime.person('someone@example.com') %}
     *
     * With no argument, the logged-in user — which is what a member account area wants.
     *
     * **This is not an authorization check.** It joins records on an email address, so it
     * will happily assemble anyone's history for anyone who asks. A front-end template must
     * only ever call it for the current user; passing a submitted address would hand a
     * visitor someone else's bookings.
     */
    public function person(User|string|null $subject = null): ?Person
    {
        $subject ??= Craft::$app->getUser()->getIdentity();

        if ($subject === null) {
            return null;
        }

        return Plugin::getInstance()->people->find($subject);
    }
}
