<?php

declare(strict_types=1);

namespace justinholtweb\owl\web\twig;

use yii\base\Behavior;

/**
 * Exposes `craft.owl` in Twig. Accessing `craft.owl` returns an {@see OwlVariable}, so templates
 * can write `craft.owl.events(...)` and `craft.owl.calendars()`.
 *
 * The variable lives on its own object (rather than merging methods straight onto CraftVariable) to
 * keep a clean `owl` namespace and avoid clashing with reserved methods such as Behavior::events().
 */
class CraftVariableBehavior extends Behavior
{
    public OwlVariable $owl;

    public function init(): void
    {
        parent::init();
        $this->owl = new OwlVariable();
    }
}
