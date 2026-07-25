<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The person panel: one customer's bookings, membership and tickets on one screen.
 */
class PeopleController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Either side of the bundle earns a look — the panels within are gated individually,
        // the same way the dashboard works.
        $user = Craft::$app->getUser();

        if (!$user->checkPermission('stub:viewBookings') && !$user->checkPermission('headcount-manageSubscriptions')) {
            throw new ForbiddenHttpException('You don’t have permission to look people up.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $showtime = Plugin::getInstance();
        $query = trim((string)Craft::$app->getRequest()->getParam('q', ''));
        $email = trim((string)Craft::$app->getRequest()->getParam('email', ''));

        $user = Craft::$app->getUser();

        return $this->renderTemplate('showtime/people/_index', [
            'query' => $query,
            'results' => $query !== '' && $email === '' ? $showtime->people->search($query) : [],
            'person' => $email !== '' ? $showtime->people->find($email) : null,
            // A booking screen and a membership screen are separately permissioned
            // elsewhere; putting them side by side must not quietly widen either.
            'canSeeBookings' => $user->checkPermission('stub:viewBookings'),
            'canSeeMembership' => $user->checkPermission('headcount-manageSubscriptions'),
            'canSeeTickets' => $user->checkPermission('owl-manageEvents'),
        ]);
    }
}
