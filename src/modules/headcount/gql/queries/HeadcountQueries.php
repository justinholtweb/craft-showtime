<?php

namespace justinholtweb\headcount\gql\queries;

use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\headcount\gql\resolvers\HeadcountResolver;
use justinholtweb\headcount\gql\types\AccessType;
use justinholtweb\headcount\gql\types\MembershipType;
use justinholtweb\headcount\gql\types\PlanType;

/**
 * Headcount's root queries — read-only.
 *
 * Two schema components: `headcount.plans:read` for the public plan list, and
 * `headcount.membership:read` for the current user's membership and gate checks. The
 * second only ever answers for the user the request's session belongs to.
 */
class HeadcountQueries extends Query
{
    /**
     * The root field names, so the GraphQL cache can recognise a query that asks for them.
     */
    public const FIELDS = ['headcountPlans', 'headcountPlan', 'headcountMembership', 'headcountAccess'];

    public static function getQueries(bool $checkToken = true): array
    {
        $queries = [];

        if (!$checkToken || GqlHelper::canSchema('headcount.plans')) {
            $queries['headcountPlans'] = [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(PlanType::getType()))),
                'args' => [
                    'handle' => [
                        'type' => Type::listOf(Type::string()),
                        'description' => 'Narrow to plans with these handles.',
                    ],
                ],
                'resolve' => HeadcountResolver::class . '::resolvePlans',
                'description' => 'Enabled membership plans, in their control-panel order.',
            ];
            $queries['headcountPlan'] = [
                'type' => PlanType::getType(),
                'args' => [
                    'handle' => ['type' => Type::nonNull(Type::string())],
                ],
                'resolve' => HeadcountResolver::class . '::resolvePlan',
                'description' => 'One enabled plan by handle, or null.',
            ];
        }

        if (!$checkToken || GqlHelper::canSchema('headcount.membership')) {
            $queries['headcountMembership'] = [
                'type' => Type::nonNull(MembershipType::getType()),
                'resolve' => HeadcountResolver::class . '::resolveMembership',
                'description' => 'The current user’s membership. Never anyone else’s.',
            ];
            $queries['headcountAccess'] = [
                'type' => AccessType::getType(),
                'args' => [
                    'entryId' => ['type' => Type::nonNull(Type::int())],
                    'siteId' => [
                        'type' => Type::int(),
                        'description' => 'Defaults to the current site.',
                    ],
                ],
                'resolve' => HeadcountResolver::class . '::resolveAccess',
                'description' => 'Whether the current user may see an entry. Null unless the entry is live and in a section this schema can read.',
            ];
        }

        return $queries;
    }
}
