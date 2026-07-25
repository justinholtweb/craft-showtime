<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\RateLimitHelper;
use justinholtweb\stub\Plugin;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

class BookingFormController extends Controller
{
    protected array|int|bool $allowAnonymous = ['submit'];

    public function actionSubmit(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        // Honeypot — silently succeed for bots so they don't retry.
        if ($settings->enableHoneypot && !empty($request->getBodyParam($settings->honeypotFieldName))) {
            return $this->asJson(['success' => true]);
        }

        // Per-IP rate limit. Skip when set to 0.
        if ($settings->bookingsPerHour > 0
            && !RateLimitHelper::check('booking', $settings->bookingsPerHour, 3600)) {
            throw new TooManyRequestsHttpException('Too many booking attempts. Please try again later.');
        }

        $serviceId = (int)$request->getRequiredBodyParam('serviceId');
        $providerId = (int)$request->getRequiredBodyParam('providerId');
        $startDateTimeUtc = $request->getRequiredBodyParam('startDateTime');
        $timezone = $request->getBodyParam('timezone', 'UTC');
        $email = $request->getRequiredBodyParam('email');
        $firstName = $request->getRequiredBodyParam('firstName');
        $lastName = $request->getRequiredBodyParam('lastName');
        $phone = $request->getBodyParam('phone');
        $customerNotes = $request->getBodyParam('customerNotes');

        // Validate service
        $service = Plugin::getInstance()->services->getServiceById($serviceId);
        if (!$service || !$service->enabled) {
            return $this->asJson(['success' => false, 'error' => 'Invalid service.']);
        }

        // Calculate end time
        $startDt = new DateTime($startDateTimeUtc, new DateTimeZone('UTC'));
        $endDt = clone $startDt;
        $endDt->modify("+{$service->duration} minutes");

        // Find or create customer
        $customer = Plugin::getInstance()->customers->findOrCreate($email, $firstName, $lastName, $phone);

        // Create booking
        $booking = Plugin::getInstance()->bookings->createBooking([
            'serviceId' => $serviceId,
            'providerId' => $providerId,
            'customerId' => $customer->id,
            'startDateTime' => $startDt->format('Y-m-d H:i:s'),
            'endDateTime' => $endDt->format('Y-m-d H:i:s'),
            'timezone' => $timezone,
            'customerNotes' => $customerNotes,
        ]);

        if ($booking->hasErrors()) {
            return $this->asJson(['success' => false, 'errors' => $booking->getErrors()]);
        }

        // Send admin notification
        Plugin::getInstance()->emails->sendAdminNotification($booking);

        $requiresPayment = $service->price > 0 && $settings->paymentEnabled;

        return $this->asJson([
            'success' => true,
            'bookingId' => $booking->id,
            'referenceNumber' => $booking->referenceNumber,
            'paymentToken' => BookingHelper::generatePaymentToken($booking),
            'requiresPayment' => $requiresPayment,
            'price' => $service->price,
            'currency' => $service->currency,
        ]);
    }
}
