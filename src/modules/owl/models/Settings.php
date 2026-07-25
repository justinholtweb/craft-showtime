<?php

declare(strict_types=1);

namespace justinholtweb\owl\models;

use craft\base\Model;

/**
 * Plugin settings.
 */
class Settings extends Model
{
    /**
     * How many months ahead occurrences are materialised from a recurrence rule. The horizon is
     * rolled forward by a daily job. Keep this bounded so infinite rules don't generate forever.
     */
    public int $occurrenceHorizonMonths = 24;

    /**
     * Hard safety cap on occurrences generated for a single event within the horizon.
     */
    public int $maxOccurrencesPerEvent = 5000;

    /**
     * Whether to register Owl's demo/front-end templates route.
     */
    public bool $enableDemoTemplates = false;

    public function defineRules(): array
    {
        return [
            [['occurrenceHorizonMonths', 'maxOccurrencesPerEvent'], 'integer', 'min' => 1],
            [['occurrenceHorizonMonths', 'maxOccurrencesPerEvent'], 'required'],
            [['enableDemoTemplates'], 'boolean'],
        ];
    }
}
