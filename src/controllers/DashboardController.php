<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\Plugin;
use yii\web\Response;

/**
 * The bundle's landing screen.
 */
class DashboardController extends Controller
{
    /**
     * Readable by anyone who can see either side of the bundle. The panels themselves are
     * gated individually — a bookings coordinator shouldn't see revenue figures they can't
     * reach through the Reports screen.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $user = Craft::$app->getUser();

        if (!$user->checkPermission('stub:viewBookings') && !$user->checkPermission('headcount-manageSubscriptions')) {
            $this->requirePermission('stub:viewBookings');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();
        $dashboard = Plugin::getInstance()->dashboard;

        return $this->renderTemplate('showtime/dashboard/_index', [
            'stats' => $dashboard->getStats(),
            'todaysBookings' => $user->checkPermission('stub:viewBookings') ? $dashboard->getTodaysBookings() : [],
            'canViewBookings' => $user->checkPermission('stub:viewBookings'),
            'canViewMembers' => $user->checkPermission('headcount-manageSubscriptions'),
            'canViewRevenue' => $user->checkPermission('headcount-viewReports'),
        ]);
    }
}
