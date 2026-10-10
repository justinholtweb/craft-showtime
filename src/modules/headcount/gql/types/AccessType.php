<?php

namespace justinholtweb\headcount\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Whether the current user may see an entry, and if not, why — the gate decision a
 * headless front end needs to show a paywall or a teaser instead of the content.
 */
class AccessType
{
    public static function getName(): string
    {
        return 'HeadcountAccess';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'The gate decision for one entry and the current user.',
            'fields' => fn() => [
                'allowed' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the current user may see the entry.',
                ],
                'restricted' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether an access rule covers the entry at all.',
                ],
                'reason' => [
                    'type' => Type::string(),
                    'description' => 'Why access was refused: `subscription_required` or `drip`. Null when allowed.',
                ],
                'behavior' => [
                    'type' => Type::string(),
                    'description' => 'What the rule asks a front end to do when refused: `redirect`, `paywall` or `hide`.',
                ],
                'redirectUrl' => ['type' => Type::string()],
                'teaserLength' => [
                    'type' => Type::int(),
                    'description' => 'For `paywall`: how many characters of the content to show.',
                ],
                'requiredPlans' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'Handles of the enabled plans that unlock the entry.',
                ],
                'unlocksInDays' => [
                    'type' => Type::int(),
                    'description' => 'For `drip`: days until the entry unlocks for this member.',
                ],
            ],
        ]));
    }
}
