<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\gql\interfaces\EventInterface;
use justinholtweb\owl\gql\types\EventType;

/**
 * Generates the GraphQL object type for Owl events. Owl uses a single, non-contextual event type.
 */
class EventGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $type = static::generateType($context);

        return [$type->name => $type];
    }

    public static function generateType(mixed $context): ObjectType
    {
        $typeName = Event::gqlTypeNameByContext($context);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new EventType([
            'name' => $typeName,
            'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions(
                EventInterface::getFieldDefinitions(),
                $typeName,
            ),
        ]));
    }
}
