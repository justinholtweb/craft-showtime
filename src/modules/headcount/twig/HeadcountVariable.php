<?php

namespace justinholtweb\headcount\twig;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;

class HeadcountVariable
{
    /**
     * The plugin instance.
     *
     * Templates must not reach for `craft.app.plugins.getPlugin('headcount')` — that
     * returns null when Headcount is mounted inside a host bundle rather than installed
     * as its own plugin. This resolves in both modes.
     */
    public function getPlugin(): ?Headcount
    {
        return Headcount::getInstance();
    }

    /**
     * Check if the current user has an active subscription.
     */
    public function isSubscribed(?string $planHandle = null): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return false;
        }

        return Headcount::getInstance()->subscriptions->hasActiveSubscription($user->id, $planHandle);
    }

    /**
     * Check whether the current user can access an element (respects gating + drip).
     *
     * Accepts any element type, not just entries — an access rule can be written against
     * anything registered as a gate target.
     */
    public function canAccess(ElementInterface $element): bool
    {
        return Headcount::getInstance()->gating->canAccess($element);
    }

    /**
     * The gate that stopped the page currently being rendered, if any.
     *
     * Only set for the `paywall` behavior, where the page is deliberately allowed to render
     * so the template can show a teaser instead of the whole thing:
     *
     *     {% set gate = craft.headcount.gatingResult %}
     *     {% if gate %}
     *         {{ entry.body|striptags|slice(0, gate.teaserLength ?? 300) }}…
     *     {% else %}
     *         {{ entry.body }}
     *     {% endif %}
     *
     * Nothing is withheld automatically. A paywalled template that ignores this shows the
     * full page — use the `hide` or `redirect` behavior if that isn't acceptable.
     */
    public function gatingResult(): ?array
    {
        return Headcount::getInstance()->gating->currentResult;
    }

    /**
     * Get current user's active subscriptions.
     */
    public function subscriptions(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return [];
        }

        return Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);
    }

    /**
     * Get all available (enabled) plans.
     */
    public function plans(): array
    {
        return Headcount::getInstance()->plans->getAllPlans(true);
    }

    /**
     * Get a specific plan by handle.
     */
    public function plan(string $handle): ?Plan
    {
        return Headcount::getInstance()->plans->getPlanByHandle($handle);
    }

    /**
     * Get checkout URL for a plan.
     */
    public function checkoutUrl(string $planHandle, string $gateway = 'stripe'): string
    {
        return \craft\helpers\UrlHelper::actionUrl('headcount/checkout/create-session', [
            'planHandle' => $planHandle,
            'gateway' => $gateway,
        ]);
    }

    /**
     * Get portal URL for subscription management.
     */
    public function portalUrl(?string $returnUrl = null): string
    {
        $params = [];
        if ($returnUrl) {
            $params['returnUrl'] = $returnUrl;
        }

        return \craft\helpers\UrlHelper::actionUrl('headcount/portal/redirect', $params);
    }

    /**
     * Check if drip content is unlocked.
     */
    public function isUnlocked(Entry $entry): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $result = Headcount::getInstance()->drip->isUnlocked($entry, $user);

        return $result !== false;
    }

    /**
     * Get days until drip content unlocks.
     */
    public function unlocksIn(Entry $entry): ?int
    {
        $user = Craft::$app->getUser()->getIdentity();
        return Headcount::getInstance()->drip->daysUntilUnlocked($entry, $user);
    }

    /**
     * Render a coupon input field.
     */
    public function couponField(array $options = []): string
    {
        $name = $options['name'] ?? 'coupon';
        $placeholder = $options['placeholder'] ?? 'Enter coupon code';
        $class = $options['class'] ?? '';

        return '<input type="text" name="' . htmlspecialchars($name) . '" placeholder="' . htmlspecialchars($placeholder) . '" class="' . htmlspecialchars($class) . '">';
    }

    /**
     * Get subscription element query.
     */
    public function subscriptionQuery(): \justinholtweb\headcount\elements\db\SubscriptionQuery
    {
        return Subscription::find();
    }
}
