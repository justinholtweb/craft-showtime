<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use yii\base\Component;

class Members extends Component
{
    /**
     * Sync user group membership based on subscription status.
     */
    public function syncUserGroups(Subscription $subscription): void
    {
        if (!$subscription->userId) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserById($subscription->userId);
        if (!$user) {
            return;
        }

        $plan = $subscription->getPlan();
        if (!$plan || !$plan->userGroupId) {
            return;
        }

        $currentGroupIds = array_map(
            fn($group) => $group->id,
            $user->getGroups()
        );

        if ($subscription->isActive()) {
            // Add user to the plan's user group
            if (!in_array($plan->userGroupId, $currentGroupIds)) {
                $currentGroupIds[] = $plan->userGroupId;
                Craft::$app->getUsers()->assignUserToGroups($user->id, $currentGroupIds);

                Craft::info("Granted group access for plan {$plan->handle} (subscription #{$subscription->id})", 'headcount');
            }
        } else {
            // Remove user from the plan's user group
            // But only if they don't have another active subscription to a plan with the same group
            $otherActiveSubscriptions = Subscription::find()
                ->userId($user->id)
                ->active()
                ->planId($plan->id)
                ->id(['not', $subscription->id])
                ->exists();

            if (!$otherActiveSubscriptions && in_array($plan->userGroupId, $currentGroupIds)) {
                $currentGroupIds = array_filter($currentGroupIds, fn($id) => $id !== $plan->userGroupId);
                Craft::$app->getUsers()->assignUserToGroups($user->id, $currentGroupIds);

                Craft::info("Revoked group access for plan {$plan->handle} (subscription #{$subscription->id})", 'headcount');
            }
        }
    }

    /**
     * Sync all user groups for a user based on their active subscriptions.
     */
    public function syncAllGroupsForUser(int $userId): void
    {
        $user = Craft::$app->getUsers()->getUserById($userId);
        if (!$user) {
            return;
        }

        $activeSubscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($userId);

        // Get current non-headcount group IDs (keep existing groups that aren't managed by us)
        $headcountGroupIds = $this->getHeadcountManagedGroupIds();
        $currentGroupIds = array_map(fn($g) => $g->id, $user->getGroups());
        $nonHeadcountGroupIds = array_diff($currentGroupIds, $headcountGroupIds);

        // Add groups from active subscriptions
        $newGroupIds = $nonHeadcountGroupIds;
        foreach ($activeSubscriptions as $subscription) {
            $plan = $subscription->getPlan();
            if ($plan && $plan->userGroupId) {
                $newGroupIds[] = $plan->userGroupId;
            }
        }

        $newGroupIds = array_unique($newGroupIds);
        Craft::$app->getUsers()->assignUserToGroups($userId, $newGroupIds);
    }

    /**
     * Get all user group IDs that are managed by Headcount plans.
     */
    public function getHeadcountManagedGroupIds(): array
    {
        $plans = Headcount::getInstance()->plans->getAllPlans();
        $groupIds = [];

        foreach ($plans as $plan) {
            if ($plan->userGroupId) {
                $groupIds[] = $plan->userGroupId;
            }
        }

        return array_unique($groupIds);
    }

    /**
     * Get member count for a specific plan.
     */
    public function getMemberCountForPlan(int $planId): int
    {
        return Subscription::find()
            ->planId($planId)
            ->active()
            ->count();
    }

    /**
     * Get total active member count.
     */
    public function getTotalActiveMemberCount(): int
    {
        return Subscription::find()
            ->active()
            ->count();
    }

    /**
     * The active subscription belonging to an email address, if any.
     *
     * Headcount keys memberships on Craft users, but the rest of the world — a booking, a
     * ticket order, a support enquiry — arrives holding an email address. This is the
     * translation, and it's the read-side entry point a host bundle uses to join a member
     * up with their records in other plugins.
     */
    public function forEmail(string $email): ?Subscription
    {
        return $this->subscriptionsForEmail($email)[0] ?? null;
    }

    /**
     * Every subscription belonging to an email address, active ones first.
     *
     * @return Subscription[]
     */
    public function subscriptionsForEmail(string $email): array
    {
        if ($email === '') {
            return [];
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);

        if ($user === null) {
            return [];
        }

        $subscriptions = Headcount::getInstance()->subscriptions->getUserSubscriptions($user->id);

        // Active first, so a caller that only wants "are they a member" can take the head of
        // the list rather than filtering.
        usort(
            $subscriptions,
            fn(Subscription $a, Subscription $b) => (int)($b->status === 'active') <=> (int)($a->status === 'active'),
        );

        return $subscriptions;
    }
}
