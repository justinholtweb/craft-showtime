<?php

namespace justinholtweb\headcount\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A membership plan, field for field what `GET /actions/headcount/api/plans` returns.
 *
 * Resolved from {@see \justinholtweb\headcount\helpers\Payload::plan()}, the same array the
 * REST API sends, so the two can't drift. Stripe and PayPal IDs and the mapped user group
 * are never exposed.
 */
class PlanType
{
    public static function getName(): string
    {
        return 'HeadcountPlan';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'An enabled membership plan.',
            'fields' => fn() => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'name' => ['type' => Type::nonNull(Type::string())],
                'handle' => ['type' => Type::nonNull(Type::string())],
                'description' => ['type' => Type::string()],
                'price' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'The full price per billing period, or per term for a season plan.',
                ],
                'currency' => ['type' => Type::nonNull(Type::string())],
                'billingInterval' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`day`, `week`, `month` or `year`.',
                ],
                'billingIntervalCount' => ['type' => Type::nonNull(Type::int())],
                'termType' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`recurring`, or `fixed` for a season membership.',
                ],
                'seasonStart' => [
                    'type' => Type::string(),
                    'description' => 'ISO 8601 start of the season the plan is currently selling. Null for a recurring plan.',
                ],
                'seasonEnd' => [
                    'type' => Type::string(),
                    'description' => 'ISO 8601 end of the season the plan is currently selling. Null for a recurring plan.',
                ],
                'currentPrice' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'What someone joining today would pay, after any season proration.',
                ],
                'trialDays' => ['type' => Type::nonNull(Type::int())],
                'features' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'resolve' => fn(array $plan) => array_values(array_filter(
                        array_map(fn($feature) => is_scalar($feature) ? (string)$feature : null, $plan['features'] ?? []),
                        fn($feature) => $feature !== null && $feature !== '',
                    )),
                ],
            ],
        ]));
    }
}
