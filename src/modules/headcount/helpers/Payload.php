<?php

namespace justinholtweb\headcount\helpers;

use DateTimeInterface;
use justinholtweb\headcount\elements\Subscription;
use justinholtweb\headcount\models\Plan;

/**
 * The shapes Headcount hands to headless clients.
 *
 * The REST API and GraphQL both build their responses from here, so a plan or a
 * subscription reads the same whichever one a front end asks. Gateway IDs, customer IDs
 * and metadata never leave: nothing a client could act on at Stripe or PayPal.
 */
abstract class Payload
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(Plan $plan): array
    {
        // The season the plan is currently selling, not the term a member joining right
        // now would get — those differ mid-season, and a client showing "Season: X to Y"
        // means the former. What today's joiner would pay is `currentPrice`.
        $window = $plan->getSeasonWindow();

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'handle' => $plan->handle,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'billingInterval' => $plan->billingInterval,
            'billingIntervalCount' => $plan->billingIntervalCount,
            'termType' => $plan->termType,
            'seasonStart' => $window ? $window['start']->format(DateTimeInterface::ATOM) : null,
            'seasonEnd' => $window ? $window['end']->format(DateTimeInterface::ATOM) : null,
            'currentPrice' => $plan->getProratedPrice(),
            'trialDays' => $plan->trialDays,
            'features' => $plan->features,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscription(Subscription $subscription): array
    {
        $plan = $subscription->getPlan();

        return [
            'id' => $subscription->id,
            'planId' => $subscription->planId,
            'planHandle' => $plan?->handle,
            'planName' => $plan?->name,
            'gateway' => $subscription->gateway,
            'status' => $subscription->status,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'startDate' => $subscription->startDate?->format('c'),
            'endDate' => $subscription->endDate?->format('c'),
            'trialEndDate' => $subscription->trialEndDate?->format('c'),
            'cancelAtPeriodEnd' => $subscription->cancelAtPeriodEnd,
            'canceledAt' => $subscription->canceledAt?->format('c'),
            'dateCreated' => $subscription->dateCreated?->format('c'),
        ];
    }
}
