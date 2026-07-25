<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\RateLimitHelper;
use justinholtweb\stub\Plugin;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

class PaymentController extends Controller
{
    protected array|int|bool $allowAnonymous = ['create-intent'];

    public function actionCreateIntent(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->paymentIntentsPerHour > 0
            && !RateLimitHelper::check('payment', $settings->paymentIntentsPerHour, 3600)) {
            throw new TooManyRequestsHttpException('Too many requests. Please try again later.');
        }

        $bookingId = (int)$request->getRequiredBodyParam('bookingId');
        $paymentToken = (string)$request->getBodyParam('paymentToken', '');

        $booking = Plugin::getInstance()->bookings->getBookingById($bookingId);

        // Verify the caller owns this booking via the HMAC token returned by actionSubmit.
        // Without this, anyone could enumerate booking IDs and trigger payment-intent creation.
        if (!$booking || !BookingHelper::verifyPaymentToken($paymentToken, $booking)) {
            return $this->asJson(['success' => false, 'error' => 'Booking not found.']);
        }

        if ($booking->price <= 0) {
            return $this->asJson(['success' => false, 'error' => 'No payment required.']);
        }

        $result = Plugin::getInstance()->payments->createPaymentIntent($booking);

        if (!$result) {
            return $this->asJson(['success' => false, 'error' => 'Failed to create payment.']);
        }

        return $this->asJson([
            'success' => true,
            'clientSecret' => $result['clientSecret'],
        ]);
    }
}
