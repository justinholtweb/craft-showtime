<?php

namespace justinholtweb\headcount\gql\resolvers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\helpers\Payload;
use justinholtweb\headcount\models\Plan;

/**
 * Resolves Headcount's GraphQL queries, and holds their boundaries.
 *
 * Membership fields read the session's user and nothing else: no query takes a user ID or
 * email. Each resolver re-checks its schema component, so a type reached some other way
 * still answers only to a schema that was granted it.
 */
class HeadcountResolver
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function resolvePlans(mixed $source, array $arguments): array
    {
        if (!GqlHelper::canSchema('headcount.plans')) {
            return [];
        }

        $plans = Headcount::getInstance()->plans->getAllPlans(true);

        if (isset($arguments['handle'])) {
            $handles = array_map('strval', array_filter((array)$arguments['handle'], 'is_string'));
            $plans = array_filter($plans, fn(Plan $plan) => in_array($plan->handle, $handles, true));
        }

        return array_values(array_map(fn(Plan $plan) => Payload::plan($plan), $plans));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolvePlan(mixed $source, array $arguments): ?array
    {
        if (!GqlHelper::canSchema('headcount.plans')) {
            return null;
        }

        $plan = Headcount::getInstance()->plans->getPlanByHandle((string)$arguments['handle']);

        // Disabled plans aren't for sale, so a headless front end has no business seeing them.
        return $plan && $plan->enabled ? Payload::plan($plan) : null;
    }

    /**
     * @return array{userId: int|null, isMember: bool, activePlanHandles: string[]}
     */
    public static function resolveMembership(mixed $source, array $arguments): array
    {
        $user = self::member();

        if ($user === null) {
            return ['userId' => null, 'isMember' => false, 'activePlanHandles' => []];
        }

        $handles = [];
        foreach (Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id) as $subscription) {
            $handle = $subscription->getPlan()?->handle;
            if ($handle !== null && !in_array($handle, $handles, true)) {
                $handles[] = $handle;
            }
        }

        return [
            'userId' => $user->id,
            'isMember' => $handles !== [] || self::hasActiveSubscription($user, null),
            'activePlanHandles' => $handles,
        ];
    }

    /**
     * @param array{userId: int|null} $source
     */
    public static function resolveIsSubscribed(array $source, array $arguments): bool
    {
        $user = self::member();

        if ($user === null || $user->id !== $source['userId']) {
            return false;
        }

        $plan = $arguments['plan'] ?? null;

        return self::hasActiveSubscription($user, is_string($plan) && $plan !== '' ? $plan : null);
    }

    /**
     * @param array{userId: int|null} $source
     * @return array<int, array<string, mixed>>
     */
    public static function resolveSubscriptions(array $source, array $arguments): array
    {
        $user = self::member();

        // The source carries the user it was built for; if that isn't the session's user
        // (it always is today), answer nothing rather than someone else's billing.
        if ($user === null || $user->id !== $source['userId']) {
            return [];
        }

        $service = Headcount::getInstance()->subscriptions;
        $subscriptions = !empty($arguments['activeOnly'])
            ? $service->getActiveSubscriptionsForUser($user->id)
            : $service->getUserSubscriptions($user->id);

        return array_values(array_map(fn(Subscription $sub) => Payload::subscription($sub), $subscriptions));
    }

    /**
     * The gate decision for one entry, or null when the schema couldn't read the entry itself.
     *
     * Asking about an entry the schema can't see would otherwise tell a token that a disabled
     * or out-of-scope entry exists, and which plans unlock it.
     *
     * @return array<string, mixed>|null
     */
    public static function resolveAccess(mixed $source, array $arguments): ?array
    {
        if (!GqlHelper::canSchema('headcount.membership')) {
            return null;
        }

        $entry = self::readableEntry((int)$arguments['entryId'], isset($arguments['siteId']) ? (int)$arguments['siteId'] : null);

        if ($entry === null) {
            return null;
        }

        $plugin = Headcount::getInstance();
        $user = self::member();
        $rule = $plugin->gating->getMatchingRule($entry);
        $result = $plugin->gating->evaluateAccess($entry, $user);

        $requiredPlans = [];
        if ($rule !== null && $rule->enabled) {
            foreach ($rule->planIds ?? [] as $planId) {
                $plan = $plugin->plans->getPlanById((int)$planId);
                if ($plan && $plan->enabled) {
                    $requiredPlans[] = $plan->handle;
                }
            }
        }

        $allowed = $result === null || $result['allowed'];
        $reason = $allowed ? null : ($result['reason'] ?? null);

        return [
            'allowed' => $allowed,
            'restricted' => $result !== null,
            'reason' => $reason,
            'behavior' => $allowed ? null : ($result['behavior'] ?? null),
            'redirectUrl' => $allowed ? null : ($result['redirectUrl'] ?? null),
            'teaserLength' => $allowed ? null : ($result['teaserLength'] ?? null),
            'requiredPlans' => $requiredPlans,
            'unlocksInDays' => $reason === 'drip' ? $plugin->drip->daysUntilUnlocked($entry, $user) : null,
        ];
    }

    /**
     * The user membership fields describe: whoever is logged in, never a named member.
     */
    private static function member(): ?User
    {
        if (!GqlHelper::canSchema('headcount.membership')) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof User ? $user : null;
    }

    private static function hasActiveSubscription(User $user, ?string $planHandle): bool
    {
        return Headcount::getInstance()->subscriptions->hasActiveSubscription($user->id, $planHandle);
    }

    /**
     * The entry, if it's live on a site this schema may read and in a section it may read.
     */
    private static function readableEntry(int $entryId, ?int $siteId): ?Entry
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $allowedSiteIds = array_map(fn($site) => $site->id, GqlHelper::getAllowedSites());
        if (!in_array($siteId, $allowedSiteIds, true)) {
            return null;
        }

        $sectionIds = [];
        foreach (GqlHelper::extractAllowedEntitiesFromSchema('read')['sections'] ?? [] as $uid) {
            $section = Craft::$app->getEntries()->getSectionByUid($uid);
            if ($section !== null) {
                $sectionIds[] = $section->id;
            }
        }

        if ($sectionIds === []) {
            return null;
        }

        return Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->sectionId($sectionIds)
            ->status('live')
            ->one();
    }
}
