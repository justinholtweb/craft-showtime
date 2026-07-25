<?php

declare(strict_types=1);

namespace justinholtweb\owl\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\owl\Owl;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Control panel ticket management for an event. Pro edition + Craft Commerce required.
 */
class TicketsController extends Controller
{
    public function actionIndex(int $eventId): Response
    {
        $this->requirePermission('owl-manageEvents');
        $this->requireTicketing();

        $event = Owl::getInstance()->events->getEventById($eventId);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        return $this->renderTemplate('owl/tickets/index', [
            'event' => $event,
            'tickets' => Owl::getInstance()->tickets->getTicketsForEvent($eventId),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('owl-manageEvents');
        $this->requireTicketing();

        $request = Craft::$app->getRequest();
        $eventId = (int)$request->getRequiredBodyParam('eventId');

        $event = Owl::getInstance()->events->getEventById($eventId);
        if ($event === null) {
            throw new NotFoundHttpException('Event not found.');
        }

        $capacity = $request->getBodyParam('capacity');
        $capacity = ($capacity === '' || $capacity === null) ? null : (int)$capacity;

        $ticket = Owl::getInstance()->tickets->createTicket(
            $event,
            (string)$request->getBodyParam('ticketName'),
            (float)$request->getBodyParam('price'),
            $capacity,
        );

        if ($ticket->hasErrors()) {
            $reason = implode(' ', $ticket->getFirstErrors()) ?: Craft::t('owl', 'Please check the ticket details.');
            Craft::$app->getSession()->setError(Craft::t('owl', 'Couldn’t add ticket: {reason}', ['reason' => $reason]));

            return $this->redirect("owl/events/{$eventId}/tickets");
        }

        Craft::$app->getSession()->setNotice(Craft::t('owl', 'Ticket added.'));

        return $this->redirect("owl/events/{$eventId}/tickets");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('owl-manageEvents');
        $this->requireTicketing();

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');
        $ticket = Owl::getInstance()->tickets->getTicketById($id);

        if ($ticket !== null) {
            Craft::$app->getElements()->deleteElement($ticket, true);
        }

        return $this->asSuccess(Craft::t('owl', 'Ticket deleted.'));
    }

    private function requireTicketing(): void
    {
        if (!Owl::getInstance()->commerceAvailable()) {
            throw new ForbiddenHttpException('Ticketing requires the Pro edition and Craft Commerce.');
        }
    }
}
