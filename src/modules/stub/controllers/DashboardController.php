<?php

namespace justinholtweb\stub\controllers;

use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('stub:viewBookings');
        return true;
    }

    public function actionIndex(): Response
    {
        $stats = Plugin::getInstance()->bookings->getBookingStats();
        $todaysBookings = Plugin::getInstance()->bookings->getTodaysBookings();
        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('stub/dashboard/_index', [
            'stats' => $stats,
            'todaysBookings' => $todaysBookings,
            'settings' => $settings,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }
}
