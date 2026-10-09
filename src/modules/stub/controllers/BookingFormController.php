<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\PaymentHelper;
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
        try {
            $startDt = new DateTime((string)$startDateTimeUtc, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->asJson(['success' => false, 'error' => 'Invalid start time.']);
        }

        if (!Plugin::getInstance()->availability->isBookable($serviceId, $providerId, $startDt)) {
            return $this->asJson(['success' => false, 'error' => 'That time is no longer available. Please choose another.']);
        }

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

        // A booking that stands already — free, or pay-in-person — has nothing left to wait
        // for, so its confirmation goes now. (A paid booking's goes when Stripe confirms it.)
        if ($booking->bookingStatus === BookingStatus::Confirmed->value) {
            Plugin::getInstance()->emails->sendConfirmation($booking);
        }

        $dueNow = $booking->getAmountDueOnline();
        $requiresPayment = $dueNow > 0 && $settings->paymentEnabled;

        // What the confirmation screen says about money, once the online step (if any) is done.
        $after = clone $booking;
        if ($requiresPayment) {
            $after->amountPaid = $dueNow;
            $after->paymentStatus = PaymentHelper::statusFor((float)$after->price, $dueNow, $after->currency)->value;
        }

        return $this->asJson([
            'success' => true,
            'bookingId' => $booking->id,
            'referenceNumber' => $booking->referenceNumber,
            'paymentToken' => BookingHelper::generatePaymentToken($booking),
            'requiresPayment' => $requiresPayment,
            'price' => $booking->price,
            'currency' => $booking->currency,
            'paymentMode' => $booking->paymentMode,
            'amountDueNow' => $requiresPayment ? $dueNow : 0,
            'amountDueNowFormatted' => BookingHelper::formatPrice($requiresPayment ? $dueNow : 0, $booking->currency),
            'balanceDue' => $after->getBalanceDue(),
            'balanceDueFormatted' => BookingHelper::formatPrice($after->getBalanceDue(), $booking->currency),
            'paymentSummary' => $after->getPaymentSummary(),
        ]);
    }
}
