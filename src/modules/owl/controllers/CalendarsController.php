<?php

declare(strict_types=1);

namespace justinholtweb\owl\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel calendars controller.
 */
class CalendarsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('owl-manageCalendars');

        return $this->renderTemplate('owl/calendars/index', [
            'calendars' => Owl::getInstance()->calendars->getAllCalendars(),
        ]);
    }

    public function actionEdit(?int $calendarId = null, ?Calendar $calendar = null): Response
    {
        $this->requirePermission('owl-manageCalendars');

        if ($calendar === null) {
            if ($calendarId !== null) {
                $calendar = Owl::getInstance()->calendars->getCalendarById($calendarId);
                if ($calendar === null) {
                    throw new NotFoundHttpException('Calendar not found.');
                }
            } else {
                $calendar = new Calendar();
            }
        }

        $isNew = !$calendar->id;

        return $this->renderTemplate('owl/calendars/edit', [
            'calendar' => $calendar,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('owl', 'New Calendar') : $calendar->name,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('owl-manageCalendars');

        $request = Craft::$app->getRequest();
        $calendarId = $request->getBodyParam('calendarId');

        if ($calendarId) {
            $calendar = Owl::getInstance()->calendars->getCalendarById((int)$calendarId);
            if ($calendar === null) {
                throw new NotFoundHttpException('Calendar not found.');
            }
        } else {
            $calendar = new Calendar();
        }

        $calendar->name = $request->getBodyParam('name');
        $calendar->handle = $request->getBodyParam('handle');
        $calendar->color = $request->getBodyParam('color') ?: null;
        $calendar->hasTickets = (bool)$request->getBodyParam('hasTickets');
        $calendar->uriFormat = $request->getBodyParam('uriFormat') ?: null;
        $calendar->template = $request->getBodyParam('template') ?: null;

        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = \justinholtweb\owl\elements\Event::class;
        $calendar->setFieldLayout($fieldLayout);

        if (!Owl::getInstance()->calendars->save($calendar)) {
            Craft::$app->getSession()->setError(Craft::t('owl', 'Couldn’t save calendar.'));
            Craft::$app->getUrlManager()->setRouteParams(['calendar' => $calendar]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('owl', 'Calendar saved.'));

        return $this->redirectToPostedUrl($calendar);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('owl-manageCalendars');

        $calendarId = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        Owl::getInstance()->calendars->deleteCalendarById($calendarId);

        return $this->asSuccess(Craft::t('owl', 'Calendar deleted.'));
    }
}
