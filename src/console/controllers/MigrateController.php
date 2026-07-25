<?php

namespace justinholtweb\showtime\console\controllers;

use craft\console\Controller;
use justinholtweb\showtime\Plugin;
use yii\console\ExitCode;

/**
 * Bring every mounted module's schema up to date.
 *
 * Craft's own `migrate/all` only knows about installed plugins, and the mounted modules
 * deliberately aren't installed — their migrations run under Showtime's single handle. This
 * is the CI/deploy equivalent, and it's idempotent, so it's safe to run unconditionally.
 */
class MigrateController extends Controller
{
    public function actionAll(): int
    {
        Plugin::getInstance()->syncModules();

        $this->stdout("Mounted modules are up to date.\n");

        return ExitCode::OK;
    }
}
