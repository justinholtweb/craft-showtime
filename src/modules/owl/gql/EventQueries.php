<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\owl\gql\arguments\EventArguments;
use justinholtweb\owl\gql\interfaces\EventInterface;
use justinholtweb\owl\gql\resolvers\EventResolver;

/**
 * The GraphQL queries Owl registers. Names are prefixed with `owl` to avoid collisions.
 */
class EventQueries
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function getQueries(): array
    {
        return [
            'owlEvents' => [
                'type' => Type::listOf(EventInterface::getType()),
                'args' => EventArguments::getArguments(),
                'resolve' => EventResolver::class . '::resolve',
                'description' => 'This query is used to query for Owl events.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'owlEventCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => EventArguments::getArguments(),
                'resolve' => EventResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of Owl events.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'owlEvent' => [
                'type' => EventInterface::getType(),
                'args' => EventArguments::getArguments(),
                'resolve' => EventResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a single Owl event.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
        ];
    }
}
