<?php

namespace justinholtweb\headcount\events;

use craft\base\ElementInterface;
use justinholtweb\headcount\models\AccessRule;
use yii\base\Event;

/**
 * Asks whoever registered a gate target whether one of its rules covers a given element.
 *
 * Fired only for scopes Headcount can't evaluate on its own — anything beyond `all` and
 * `element`. A handler that recognises `$rule->type` sets `$matches`; leaving it null
 * means "not mine", and the rule doesn't apply.
 *
 * Handlers must not assume they own the rule: check `$rule->elementType` and `$rule->type`
 * before answering, or you'll gate someone else's content.
 *
 * @see \justinholtweb\headcount\services\Gating::EVENT_MATCH_GATE_RULE
 */
class MatchGateRuleEvent extends Event
{
    public AccessRule $rule;

    public ElementInterface $element;

    /** Null until a handler claims the scope. */
    public ?bool $matches = null;
}
