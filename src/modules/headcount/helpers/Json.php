<?php

namespace justinholtweb\headcount\helpers;

/**
 * JSON helpers for Headcount's `json()` columns.
 */
abstract class Json
{
    /**
     * Decode a JSON column value to an array (or null).
     *
     * Headcount's services assign `json_encode(...)` strings to Craft `json()`
     * columns, which encode the value a second time on the way into the
     * database. When a value is read back through Craft's ActiveRecord it is
     * decoded once automatically, but values read via a raw query (or populated
     * onto an element) are still double-encoded. This decodes defensively —
     * once or twice as needed — so it works for both single- and
     * double-encoded storage.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodeColumn(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        // Legacy double-encoded storage: one decode still leaves a JSON string.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
