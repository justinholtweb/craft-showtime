<?php

namespace justinholtweb\headcount\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * A kind of element that access rules can be written against.
 *
 * Headcount registers Craft entries itself. Anything else — Owl events, a plugin's custom
 * element type, assets — arrives through {@see \justinholtweb\headcount\services\Gating::EVENT_REGISTER_GATE_TARGETS},
 * which is what lets a rule gate content Headcount has never heard of.
 *
 * A target declares the *scopes* a rule may use within it: `all`, one specific element, or
 * something only the registering party can evaluate (a section, a calendar, a category).
 * Scopes whose key isn't one Headcount understands are resolved back to the registrant at
 * match time via `EVENT_MATCH_GATE_RULE`.
 */
class GateTarget extends Model
{
    /** Every element of this type. Evaluated by Headcount. */
    public const SCOPE_ALL = 'all';

    /** One element, chosen by ID. Evaluated by Headcount. */
    public const SCOPE_ELEMENT = 'element';

    /** @var class-string<ElementInterface>|'' */
    public string $elementType = '';

    /** Plural, human-readable: "Entries", "Events". */
    public string $label = '';

    /**
     * Scope key => scope definition.
     *
     * A definition is `['label' => string, 'target' => 'none'|'element'|'options', …]`,
     * where `target` says how the editor asks for the rule's target ID:
     *   - `none`    — the scope covers everything of this element type;
     *   - `element` — an element-select field, of `selectElementType` (default: this
     *                 target's own element type);
     *   - `options` — a dropdown built from the supplied `options` list.
     *
     * @var array<string, array{
     *     label: string,
     *     target?: string,
     *     options?: array<array{label: string, value: mixed}>,
     *     selectElementType?: class-string<ElementInterface>,
     * }>
     */
    public array $scopes = [];

    /**
     * The element type the editor should let you pick from for an `element`-target scope.
     */
    public function scopeSelectElementType(string $scope): string
    {
        return $this->scopes[$scope]['selectElementType'] ?? $this->elementType;
    }

    /**
     * Whether this target can evaluate the given scope key.
     */
    public function hasScope(string $scope): bool
    {
        return isset($this->scopes[$scope]);
    }

    /**
     * How the editor should collect this scope's target: `none`, `element` or `options`.
     */
    public function scopeTargetMode(string $scope): string
    {
        return $this->scopes[$scope]['target'] ?? 'none';
    }

    /**
     * Whether a rule using this scope needs a target ID.
     */
    public function scopeNeedsTarget(string $scope): bool
    {
        return $this->scopeTargetMode($scope) !== 'none';
    }
}
