<?php

namespace justinholtweb\headcount\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\headcount\gql\resolvers\HeadcountResolver;

/**
 * The current user's membership: whether they're a member, of what, and their subscriptions.
 *
 * Always the user the request's session belongs to. There is no argument naming a member,
 * so a schema can never be used to read someone else's billing.
 */
class MembershipType
{
    public static function getName(): string
    {
        return 'HeadcountMembership';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'The current user’s membership. A guest gets an empty one.',
            'fields' => fn() => [
                'userId' => [
                    'type' => Type::int(),
                    'description' => 'The logged-in user’s ID, or null for a guest.',
                ],
                'isMember' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the user has an active or trialing subscription.',
                ],
                'activePlanHandles' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'Handles of the plans the user is actively subscribed to.',
                ],
                'isSubscribed' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'args' => [
                        'plan' => [
                            'type' => Type::string(),
                            'description' => 'A plan handle. Leave out to ask about any plan.',
                        ],
                    ],
                    'description' => 'Same as `craft.headcount.isSubscribed()`.',
                    'resolve' => HeadcountResolver::class . '::resolveIsSubscribed',
                ],
                'subscriptions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(SubscriptionType::getType()))),
                    'args' => [
                        'activeOnly' => [
                            'type' => Type::boolean(),
                            'description' => 'Only active and trialing subscriptions. Defaults to false: every subscription, newest first.',
                        ],
                    ],
                    'resolve' => HeadcountResolver::class . '::resolveSubscriptions',
                ],
            ],
        ]));
    }
}
