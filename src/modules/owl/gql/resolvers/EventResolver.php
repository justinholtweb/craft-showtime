<?php

declare(strict_types=1);

namespace justinholtweb\owl\gql\resolvers;

use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\owl\elements\Event;
use yii\base\UnknownMethodException;

/**
 * Resolves Owl event GraphQL queries.
 */
class EventResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        if ($source === null) {
            $query = Event::find();
        } else {
            $query = $source->$fieldName;
        }

        if (!$query instanceof ElementQuery) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        if (!GqlHelper::isSchemaAwareOf(['owl.events'])) {
            return ElementCollection::empty();
        }

        return $query;
    }
}
