<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class BookingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (in_array($action->id, ['index', 'edit'])) {
            $this->requirePermission('stub:viewBookings');
        } else {
            $this->requirePermission('stub:manageBookings');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('stub/bookings/_index', [
            'elementType' => Booking::class,
            'selectedSubnavItem' => 'bookings',
        ]);
    }

    public function actionEdit(int $bookingId): Response
    {
        $booking = Plugin::getInstance()->bookings->getBookingById($bookingId);
        if (!$booking) {
            throw new \yii\web\NotFoundHttpException('Booking not found.');
        }

        $service = $booking->getService();
        $provider = $booking->getProvider();
        $customer = $booking->getCustomer();

        return $this->renderTemplate('stub/bookings/_edit', [
            'booking' => $booking,
            'service' => $service,
            'provider' => $provider,
            'customer' => $customer,
            'title' => $booking->referenceNumber,
            'selectedSubnavItem' => 'bookings',
            'statuses' => BookingStatus::cases(),
        ]);
    }

    public function actionUpdateStatus(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $bookingId = (int)$request->getRequiredBodyParam('bookingId');
        $newStatus = $request->getRequiredBodyParam('bookingStatus');
        $reason = $request->getBodyParam('cancellationReason');
        $adminNotes = $request->getBodyParam('adminNotes');

        $booking = Plugin::getInstance()->bookings->getBookingById($bookingId);
        if (!$booking) {
            throw new \yii\web\NotFoundHttpException('Booking not found.');
        }

        if ($adminNotes !== null) {
            $booking->adminNotes = $adminNotes;
        }

        $status = BookingStatus::from($newStatus);
        Plugin::getInstance()->bookings->updateStatus($booking, $status, $reason);

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Booking updated.'));
        return $this->redirect("stub/bookings/{$bookingId}");
    }
}
