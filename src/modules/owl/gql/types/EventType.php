<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\gql\interfaces\EventInterface;

/**
 * GraphQL object type for an Owl event.
 */
class EventType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            EventInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Event $source */
        return match ($resolveInfo->fieldName) {
            'calendarHandle' => $source->getCalendar()?->handle,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }
}
