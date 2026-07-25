<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\owl\gql\types\generators\EventGenerator;

/**
 * GraphQL interface implemented by all Owl events.
 */
class EventInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return EventGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all Owl events.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        EventGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'OwlEventInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'calendarId' => [
                'name' => 'calendarId',
                'type' => Type::int(),
                'description' => 'The ID of the calendar the event belongs to.',
            ],
            'calendarHandle' => [
                'name' => 'calendarHandle',
                'type' => Type::string(),
                'description' => 'The handle of the calendar the event belongs to.',
            ],
            'startDate' => [
                'name' => 'startDate',
                'type' => DateTime::getType(),
                'description' => 'The event start, in UTC.',
            ],
            'endDate' => [
                'name' => 'endDate',
                'type' => DateTime::getType(),
                'description' => 'The event end, in UTC.',
            ],
            'allDay' => [
                'name' => 'allDay',
                'type' => Type::boolean(),
                'description' => 'Whether the event is all-day.',
            ],
            'timezone' => [
                'name' => 'timezone',
                'type' => Type::string(),
                'description' => 'The IANA timezone the event occurs in.',
            ],
            'rrule' => [
                'name' => 'rrule',
                'type' => Type::string(),
                'description' => 'The RFC 5545 recurrence rule, if any.',
            ],
            'repeating' => [
                'name' => 'repeating',
                'type' => Type::boolean(),
                'description' => 'Whether the event repeats.',
            ],
        ]), self::getName());
    }
}
