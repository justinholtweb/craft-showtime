<?php

namespace justinholtweb\headcount\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One of the current user's subscriptions, field for field what the REST API's
 * `subscriptions` endpoint returns.
 *
 * Resolved from {@see \justinholtweb\headcount\helpers\Payload::subscription()}. Gateway
 * subscription and customer IDs and metadata are never exposed.
 */
class SubscriptionType
{
    public static function getName(): string
    {
        return 'HeadcountSubscription';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A subscription belonging to the current user.',
            'fields' => fn() => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'planId' => ['type' => Type::int()],
                'planHandle' => ['type' => Type::string()],
                'planName' => ['type' => Type::string()],
                'gateway' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`stripe`, `paypal` or `manual`.',
                ],
                'status' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => '`active`, `trialing`, `past_due`, `canceled`, `expired` or `paused`.',
                ],
                'amount' => ['type' => Type::nonNull(Type::float())],
                'currency' => ['type' => Type::nonNull(Type::string())],
                'startDate' => ['type' => Type::string(), 'description' => 'ISO 8601.'],
                'endDate' => ['type' => Type::string(), 'description' => 'ISO 8601. The end of the current billing period or term.'],
                'trialEndDate' => ['type' => Type::string(), 'description' => 'ISO 8601.'],
                'cancelAtPeriodEnd' => ['type' => Type::nonNull(Type::boolean())],
                'canceledAt' => ['type' => Type::string(), 'description' => 'ISO 8601.'],
                'dateCreated' => ['type' => Type::string(), 'description' => 'ISO 8601.'],
            ],
        ]));
    }
}
