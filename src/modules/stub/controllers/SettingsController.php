<?php

namespace justinholtweb\stub\controllers;

use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        return $this->renderTemplate('stub/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'plugin' => Plugin::getInstance(),
        ]);
    }
}
