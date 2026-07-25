<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\helpers\App;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\events\PaymentEvent;
use justinholtweb\stub\models\Payment;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\records\PaymentRecord;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use yii\base\Component;

class Payments extends Component
{
    public const EVENT_PAYMENT_COMPLETED = 'paymentCompleted';

    private function _initStripe(): void
    {
        $secretKey = App::parseEnv(Plugin::getInstance()->getSettings()->stripeSecretKey);
        Stripe::setApiKey($secretKey);
    }

    public function createPaymentIntent(Booking $booking): ?array
    {
        $this->_initStripe();

        $amount = (int)round($booking->price * 100); // Convert to cents
        if ($amount <= 0) {
            return null;
        }

        $paymentIntent = PaymentIntent::create([
            'amount' => $amount,
            'currency' => strtolower($booking->currency),
            'metadata' => [
                'booking_id' => $booking->id,
                'reference_number' => $booking->referenceNumber,
            ],
        ]);

        // Save payment record
        $record = new PaymentRecord();
        $record->bookingId = $booking->id;
        $record->stripePaymentIntentId = $paymentIntent->id;
        $record->amount = $booking->price;
        $record->currency = $booking->currency;
        $record->status = 'pending';
        $record->save();

        // Update booking with payment intent ID
        $booking->stripePaymentIntentId = $paymentIntent->id;
        Craft::$app->getElements()->saveElement($booking);

        return [
            'clientSecret' => $paymentIntent->client_secret,
            'paymentIntentId' => $paymentIntent->id,
        ];
    }

    public function handleWebhookEvent(string $payload, string $sigHeader): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $webhookSecret = App::parseEnv($settings->stripeWebhookSecret);

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            Craft::error('Stripe webhook signature verification failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->_handlePaymentSuccess((object)$event->data->offsetGet('object'));
                break;

            case 'payment_intent.payment_failed':
                $this->_handlePaymentFailure((object)$event->data->offsetGet('object'));
                break;
        }

        return true;
    }

    private function _handlePaymentSuccess(object $paymentIntent): void
    {
        $bookingId = $paymentIntent->metadata->booking_id ?? null;
        if (!$bookingId) {
            return;
        }

        $booking = Plugin::getInstance()->bookings->getBookingById((int)$bookingId);
        if (!$booking) {
            return;
        }

        // Update payment record
        $paymentRecord = PaymentRecord::findOne([
            'stripePaymentIntentId' => $paymentIntent->id,
        ]);

        if ($paymentRecord) {
            $paymentRecord->status = 'succeeded';
            $paymentRecord->stripeChargeId = $paymentIntent->latest_charge ?? null;
            $paymentRecord->stripeResponse = json_decode(json_encode($paymentIntent), true);
            $paymentRecord->save();
        }

        // Mark booking as paid + confirmed
        Plugin::getInstance()->bookings->markPaid($booking, $paymentIntent->id);
        Plugin::getInstance()->bookings->updateStatus($booking, BookingStatus::Confirmed);

        // Fire event
        if ($paymentRecord) {
            $payment = new Payment([
                'id' => $paymentRecord->id,
                'bookingId' => $paymentRecord->bookingId,
                'stripePaymentIntentId' => $paymentRecord->stripePaymentIntentId,
                'stripeChargeId' => $paymentRecord->stripeChargeId,
                'amount' => (float)$paymentRecord->amount,
                'currency' => $paymentRecord->currency,
                'status' => $paymentRecord->status,
            ]);
            $this->trigger(self::EVENT_PAYMENT_COMPLETED, new PaymentEvent([
                'booking' => $booking,
                'payment' => $payment,
            ]));
        }
    }

    private function _handlePaymentFailure(object $paymentIntent): void
    {
        $paymentRecord = PaymentRecord::findOne([
            'stripePaymentIntentId' => $paymentIntent->id,
        ]);

        if ($paymentRecord) {
            $paymentRecord->status = 'failed';
            $paymentRecord->stripeResponse = json_decode(json_encode($paymentIntent), true);
            $paymentRecord->save();
        }
    }
}
