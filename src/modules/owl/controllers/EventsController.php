<?php

declare(strict_types=1);

namespace justinholtweb\owl\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\Owl;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel events controller: the element index plus a full-page edit screen.
 */
class EventsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePermission('owl-manageEvents');

        return $this->renderTemplate('owl/events/index', [
            'elementType' => Event::class,
        ]);
    }

    public function actionEdit(?int $eventId = null, ?Event $event = null): Response
    {
        $this->requirePermission('owl-manageEvents');

        if ($event === null) {
            if ($eventId !== null) {
                $event = Owl::getInstance()->events->getEventById($eventId);
                if ($event === null) {
                    throw new NotFoundHttpException('Event not found.');
                }
            } else {
                $event = new Event();
                $event->timezone = Craft::$app->getTimeZone();
            }
        }

        $calendars = Owl::getInstance()->calendars->getAllCalendars();
        $calendarOptions = [];
        foreach ($calendars as $calendar) {
            $calendarOptions[] = ['label' => $calendar->name, 'value' => $calendar->id];
        }

        $isNew = !$event->id;

        $ticketingEnabled = !$isNew
            && Owl::getInstance()->commerceAvailable()
            && ($event->getCalendar()?->hasTickets ?? false);

        return $this->renderTemplate('owl/events/edit', [
            'event' => $event,
            'isNew' => $isNew,
            'calendars' => $calendars,
            'calendarOptions' => $calendarOptions,
            'ticketingEnabled' => $ticketingEnabled,
            'title' => $isNew ? Craft::t('owl', 'New Event') : (string)$event->title,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('owl-manageEvents');

        $request = Craft::$app->getRequest();
        $eventId = $request->getBodyParam('eventId');

        if ($eventId) {
            $event = Owl::getInstance()->events->getEventById((int)$eventId);
            if ($event === null) {
                throw new NotFoundHttpException('Event not found.');
            }
        } else {
            $event = new Event();
        }

        $timezone = $request->getBodyParam('timezone') ?: Craft::$app->getTimeZone();

        $event->title = $request->getBodyParam('title');
        $event->calendarId = (int)$request->getBodyParam('calendarId') ?: null;
        $event->timezone = $timezone;
        $event->allDay = (bool)$request->getBodyParam('allDay');
        $event->startDate = $this->toEventDate($request->getBodyParam('startDate'), $timezone);
        $event->endDate = $this->toEventDate($request->getBodyParam('endDate'), $timezone);
        $event->rrule = trim((string)$request->getBodyParam('rrule')) ?: null;

        if (!Craft::$app->getElements()->saveElement($event)) {
            Craft::$app->getSession()->setError(Craft::t('owl', 'Couldn’t save event.'));
            Craft::$app->getUrlManager()->setRouteParams(['event' => $event]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('owl', 'Event saved.'));

        return $this->redirectToPostedUrl($event);
    }

    /**
     * Builds a DateTime from a posted date field, interpreting the entered wall-clock time in the
     * event's own timezone rather than the system timezone (Craft's date field always submits the
     * system tz in its hidden param, so we override it here).
     */
    private function toEventDate(mixed $value, string $timezone): ?\DateTime
    {
        if (is_array($value)) {
            if (($value['date'] ?? '') === '' && ($value['time'] ?? '') === '') {
                return null;
            }
            $value['timezone'] = $timezone;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }
}
