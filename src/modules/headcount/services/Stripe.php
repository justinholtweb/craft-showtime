<?php

namespace justinholtweb\headcount\services;

use Craft;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Coupon;
use Stripe\Customer;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use yii\base\Component;

class Stripe extends Component
{
    private ?StripeClient $_client = null;

    public function getClient(): StripeClient
    {
        if ($this->_client === null) {
            $settings = Headcount::getInstance()->getSettings();
            $secretKey = Craft::parseEnv($settings->stripeSecretKey);

            $this->_client = new StripeClient($secretKey);
        }

        return $this->_client;
    }

    public function createCheckoutSession(Plan $plan, int $userId, ?string $couponCode = null): CheckoutSession
    {
        $settings = Headcount::getInstance()->getSettings();
        $user = Craft::$app->getUsers()->getUserById($userId);

        $params = [
            'success_url' => Craft::$app->getSites()->getCurrentSite()->getBaseUrl() . ltrim($settings->checkoutSuccessUrl, '/') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => Craft::$app->getSites()->getCurrentSite()->getBaseUrl() . ltrim($settings->checkoutCancelUrl, '/'),
            'client_reference_id' => (string)$userId,
            'metadata' => [
                'craft_user_id' => $userId,
                'plan_id' => $plan->id,
                'plan_handle' => $plan->handle,
            ],
        ];

        if ($plan->isFixedTerm()) {
            $params = array_replace_recursive($params, $this->_fixedTermParams($plan));
        } else {
            $params['mode'] = 'subscription';
            $params['line_items'] = [[
                'price' => $plan->stripePriceId,
                'quantity' => 1,
            ]];

            if ($plan->trialDays > 0) {
                $params['subscription_data'] = [
                    'trial_period_days' => $plan->trialDays,
                ];
            }
        }

        if ($user && $user->email) {
            $params['customer_email'] = $user->email;
        }

        if ($couponCode) {
            $params['discounts'] = [['coupon' => $couponCode]];
        } else {
            $params['allow_promotion_codes'] = true;
        }

        return $this->getClient()->checkout->sessions->create($params);
    }

    /**
     * Checkout parameters for a season membership.
     *
     * A season is bought once, not billed on a cycle, so this is a `payment` session rather
     * than a `subscription` one — Stripe has no subscription object at the end of it, and
     * nothing renews. The price is built inline instead of using the plan's stored Stripe
     * Price because a pro-rata plan charges a different amount to everyone who joins on a
     * different day; a stored Price can only hold one number.
     *
     * The term dates ride along in metadata so the webhook can stamp the same window onto
     * the subscription without recomputing it — by then the clock has moved on, and a
     * purchase made seconds before midnight on the last day of a season must not be
     * recorded against the next one.
     */
    private function _fixedTermParams(Plan $plan): array
    {
        $termStart = $plan->getTermStart();
        $termEnd = $plan->getTermEnd();
        $amount = $plan->getProratedPrice();

        return [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($plan->currency),
                    'unit_amount' => (int)round($amount * 100),
                    'product_data' => [
                        'name' => $plan->name,
                        'description' => Craft::t('headcount', 'Membership through {date}', [
                            'date' => $termEnd?->format('j M Y') ?? '',
                        ]),
                    ],
                ],
            ]],
            'metadata' => [
                'term_type' => Plan::TERM_FIXED,
                'term_start' => $termStart?->format(\DateTimeInterface::ATOM),
                'term_end' => $termEnd?->format(\DateTimeInterface::ATOM),
            ],
        ];
    }

    public function createPortalSession(string $customerId, ?string $returnUrl = null): PortalSession
    {
        $settings = Headcount::getInstance()->getSettings();

        return $this->getClient()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl ?? Craft::$app->getSites()->getCurrentSite()->getBaseUrl(),
        ]);
    }

    public function createProduct(Plan $plan): Product
    {
        return $this->getClient()->products->create([
            'name' => $plan->name,
            'description' => $plan->description ?? '',
            'metadata' => [
                'headcount_plan_id' => $plan->id,
                'headcount_plan_handle' => $plan->handle,
            ],
        ]);
    }

    public function createPrice(Plan $plan, string $productId): Price
    {
        return $this->getClient()->prices->create([
            'product' => $productId,
            'unit_amount' => (int)($plan->price * 100),
            'currency' => strtolower($plan->currency),
            'recurring' => [
                'interval' => $plan->billingInterval,
                'interval_count' => $plan->billingIntervalCount,
            ],
            'metadata' => [
                'headcount_plan_id' => $plan->id,
            ],
        ]);
    }

    public function syncPlan(Plan $plan): void
    {
        // A season plan has no single price to store: pro-rata means the amount depends on
        // when the member joins, so its line item is built per checkout instead.
        if ($plan->isFixedTerm()) {
            return;
        }

        if (!$plan->stripePriceId) {
            $product = $this->createProduct($plan);
            $price = $this->createPrice($plan, $product->id);
            $plan->stripePriceId = $price->id;
            Headcount::getInstance()->plans->savePlan($plan);
        }
    }

    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): StripeSubscription
    {
        if ($atPeriodEnd) {
            return $this->getClient()->subscriptions->update($subscriptionId, [
                'cancel_at_period_end' => true,
            ]);
        }

        return $this->getClient()->subscriptions->cancel($subscriptionId);
    }

    public function resumeSubscription(string $subscriptionId): StripeSubscription
    {
        return $this->getClient()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => false,
        ]);
    }

    public function getSubscription(string $subscriptionId): StripeSubscription
    {
        return $this->getClient()->subscriptions->retrieve($subscriptionId);
    }

    public function getCustomer(string $customerId): Customer
    {
        return $this->getClient()->customers->retrieve($customerId);
    }

    public function createCoupon(string $code, string $type, float $amount, ?string $currency = null): Coupon
    {
        $params = [
            'id' => $code,
        ];

        if ($type === 'percent') {
            $params['percent_off'] = $amount;
        } else {
            $params['amount_off'] = (int)($amount * 100);
            $params['currency'] = strtolower($currency ?? 'usd');
        }

        return $this->getClient()->coupons->create($params);
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): \Stripe\Event
    {
        $settings = Headcount::getInstance()->getSettings();
        $webhookSecret = Craft::parseEnv($settings->stripeWebhookSecret);

        return Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
    }
}
