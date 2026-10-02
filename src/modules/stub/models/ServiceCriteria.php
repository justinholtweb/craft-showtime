<?php

namespace justinholtweb\stub\models;

use InvalidArgumentException;

/**
 * A normalized, validated filter for `Services::getServices()`.
 *
 * Templates pass a loose hash — `{ provider: currentUser ? ... , handle: 'consultation' }` —
 * and this turns it into typed lists the query layer can trust. It is deliberately pure:
 * no database, no booted Craft, so the normalization rules are unit-testable on their own.
 *
 * Two decisions worth knowing about:
 *
 * 1. **An unknown key throws.** A silently-ignored `{ providor: … }` would return every
 *    service on the site, which reads as "the filter doesn't work" at best and leaks another
 *    author's services at worst. Failing loudly in the template is the kinder outcome.
 *
 * 2. **A filter that resolves to nothing matches nothing** (`matchesNothing`). `{ user:
 *    currentUser }` on a logged-out request has a null value; treating that as "no filter"
 *    would show the whole list to exactly the visitor who should see none of it.
 *
 * Provider handles and Craft user IDs are kept separate from provider IDs because resolving
 * them needs a query — see `Providers::getProviderIdsFor()`.
 */
final class ServiceCriteria
{
    /** @var int[]|null Service IDs, or null when unfiltered */
    public ?array $ids = null;

    /** @var string[]|null Service handles, or null when unfiltered */
    public ?array $handles = null;

    /** @var int[]|null Provider IDs, or null when unfiltered */
    public ?array $providerIds = null;

    /** @var string[]|null Provider handles, still to be resolved to IDs */
    public ?array $providerHandles = null;

    /** @var int[]|null Craft user IDs, still to be resolved to provider IDs */
    public ?array $userIds = null;

    /** Include disabled services (and providers, when resolving a provider filter). */
    public bool $includeDisabled = false;

    /** A filter was asked for, but nothing usable came through — match nothing. */
    public bool $matchesNothing = false;

    private const VALID_KEYS = [
        'id', 'ids',
        'handle', 'handles',
        'provider', 'providers',
        'user', 'users',
        'includeDisabled',
    ];

    /**
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException on an unknown key or an unusable value
     */
    public static function fromArray(array $config): self
    {
        $criteria = new self();

        if ($unknown = array_diff(array_keys($config), self::VALID_KEYS)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown service filter %s: %s. Valid keys are: %s.',
                count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', $unknown),
                implode(', ', self::VALID_KEYS),
            ));
        }

        if (array_key_exists('includeDisabled', $config)) {
            $criteria->includeDisabled = (bool)$config['includeDisabled'];
        }

        $criteria->_readIds($config);
        $criteria->_readHandles($config);
        $criteria->_readProviders($config);
        $criteria->_readUsers($config);

        return $criteria;
    }

    /** Whether any filter at all was applied. */
    public function isFiltered(): bool
    {
        return $this->matchesNothing
            || $this->ids !== null
            || $this->handles !== null
            || $this->hasProviderFilter();
    }

    /** Whether the result set is narrowed by provider, under any of the three spellings. */
    public function hasProviderFilter(): bool
    {
        return $this->providerIds !== null
            || $this->providerHandles !== null
            || $this->userIds !== null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function _readIds(array $config): void
    {
        $values = self::_collect($config, ['id', 'ids'], $present);
        if (!$present) {
            return;
        }

        $ids = [];
        foreach ($values as $value) {
            $ids[] = self::_toId($value, 'id');
        }

        $this->_assign($this->ids, $ids);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function _readHandles(array $config): void
    {
        $values = self::_collect($config, ['handle', 'handles'], $present);
        if (!$present) {
            return;
        }

        $handles = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf(
                    'A service `handle` must be a non-empty string, got %s. ' .
                    'Filtering by numeric ID uses the `id` key.',
                    get_debug_type($value),
                ));
            }
            $handles[] = trim($value);
        }

        $this->_assign($this->handles, $handles);
    }

    /**
     * A provider may arrive as an ID, a handle, or the Provider model itself. Numeric
     * strings are read as IDs — handles must start with a letter, so there is no overlap.
     *
     * @param array<string, mixed> $config
     */
    private function _readProviders(array $config): void
    {
        $values = self::_collect($config, ['provider', 'providers'], $present);
        if (!$present) {
            return;
        }

        $ids = [];
        $handles = [];

        foreach ($values as $value) {
            if (is_string($value) && !is_numeric($value)) {
                if (trim($value) === '') {
                    throw new InvalidArgumentException('A provider `handle` must not be empty.');
                }
                $handles[] = trim($value);
                continue;
            }

            $ids[] = self::_toId($value, 'provider');
        }

        if (!$ids && !$handles) {
            $this->matchesNothing = true;
            return;
        }

        if ($ids) {
            $this->providerIds = array_values(array_unique($ids));
        }
        if ($handles) {
            $this->providerHandles = array_values(array_unique($handles));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function _readUsers(array $config): void
    {
        $values = self::_collect($config, ['user', 'users'], $present);
        if (!$present) {
            return;
        }

        $ids = [];
        foreach ($values as $value) {
            if (is_string($value) && !is_numeric($value)) {
                throw new InvalidArgumentException(sprintf(
                    'A `user` filter takes a User element or its ID, got the string "%s". ' .
                    'Pass `currentUser`, or look the user up first.',
                    $value,
                ));
            }
            $ids[] = self::_toId($value, 'user');
        }

        $this->_assign($this->userIds, $ids);
    }

    /**
     * Read one or both spellings of a key into a flat list. `$present` reports whether the
     * caller asked for this filter at all, which is what separates "no filter" from "a
     * filter that came back empty".
     *
     * @param array<string, mixed> $config
     * @param string[] $keys
     * @return list<mixed>
     */
    private static function _collect(array $config, array $keys, ?bool &$present): array
    {
        $present = false;
        $values = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $present = true;
            $value = $config[$key];

            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null && $item !== '') {
                    $values[] = $item;
                }
            }
        }

        return $values;
    }

    /**
     * Elements and models are accepted in place of their IDs, so a template can pass
     * `currentUser` or a Provider straight through.
     */
    private static function _toId(mixed $value, string $key): int
    {
        if (is_object($value) && isset($value->id) && is_numeric($value->id)) {
            return (int)$value->id;
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            $id = (int)$value;
            if ($id > 0) {
                return $id;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'A `%s` filter takes a positive ID or an object with one, got %s.',
            $key,
            get_debug_type($value),
        ));
    }

    /**
     * @param int[]|string[]|null $target
     * @param int[]|string[] $values
     */
    private function _assign(?array &$target, array $values): void
    {
        if (!$values) {
            $this->matchesNothing = true;
            return;
        }

        $target = array_values(array_unique($values));
    }
}
