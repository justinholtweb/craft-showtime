<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql\arguments;

use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL query arguments for Owl events.
 */
class EventArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'calendar' => [
                'name' => 'calendar',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to events in the given calendar handle(s).',
            ],
            'calendarId' => [
                'name' => 'calendarId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results by calendar ID.',
            ],
            'startsAfter' => [
                'name' => 'startsAfter',
                'type' => Type::string(),
                'description' => 'Narrows the results to events starting on or after the given date.',
            ],
            'startsBefore' => [
                'name' => 'startsBefore',
                'type' => Type::string(),
                'description' => 'Narrows the results to events starting before the given date.',
            ],
            'allDay' => [
                'name' => 'allDay',
                'type' => Type::boolean(),
                'description' => 'Narrows the results to (non-)all-day events.',
            ],
            'repeating' => [
                'name' => 'repeating',
                'type' => Type::boolean(),
                'description' => 'Narrows the results to (non-)repeating events.',
            ],
        ]);
    }
}
