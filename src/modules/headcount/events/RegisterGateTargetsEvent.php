<?php

namespace justinholtweb\headcount\events;

use justinholtweb\headcount\models\GateTarget;
use yii\base\Event;

/**
 * Lets other code declare element types that access rules can gate.
 *
 * Headcount registers Craft entries; handlers append their own targets. Whatever is
 * registered here is what the access-rule editor offers, so a target that isn't
 * registered can't be selected — and a rule whose element type is no longer registered
 * stops matching rather than matching everything.
 *
 * @see \justinholtweb\headcount\services\Gating::EVENT_REGISTER_GATE_TARGETS
 */
class RegisterGateTargetsEvent extends Event
{
    /** @var GateTarget[] keyed by element type class name */
    public array $targets = [];
}
