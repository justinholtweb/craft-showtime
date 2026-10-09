<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentMode;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\events\PaymentEvent;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\helpers\PaymentHelper;
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

    public const METHOD_STRIPE = 'stripe';
    public const METHOD_MANUAL = 'manual';

    public function createPaymentIntent(Booking $booking): ?array
    {
        // What's due online now: the whole price, the deposit, or nothing at all for a
        // pay-in-person booking or one that's already been paid.
        $due = $booking->getAmountDueOnline();

        // Not simply price * 100: Stripe wants the currency's smallest unit, and for
        // zero-decimal currencies (JPY, KRW, …) that unit *is* the whole unit — sending
        // cents there would charge 100× the price.
        $amount = Currencies::toMinorUnits($due, $booking->currency);
        if ($amount <= 0) {
            return null;
        }

        $this->_initStripe();

        $paymentIntent = PaymentIntent::create([
            'amount' => $amount,
            'currency' => strtolower($booking->currency),
            'metadata' => [
                'booking_id' => $booking->id,
                'reference_number' => $booking->referenceNumber,
                'payment_kind' => $booking->getPaymentModeEnum() === PaymentMode::Deposit ? 'deposit' : 'full',
            ],
        ]);

        // Save payment record
        $record = new PaymentRecord();
        $record->bookingId = $booking->id;
        $record->stripePaymentIntentId = $paymentIntent->id;
        $record->amount = $due;
        $record->currency = $booking->currency;
        $record->status = 'pending';
        $record->method = self::METHOD_STRIPE;
        $record->save();

        // Update booking with payment intent ID
        $booking->stripePaymentIntentId = $paymentIntent->id;
        Craft::$app->getElements()->saveElement($booking);

        return [
            'clientSecret' => $paymentIntent->client_secret,
            'paymentIntentId' => $paymentIntent->id,
        ];
    }

    /**
     * Record a payment taken outside Stripe — cash at the desk, a card machine, a bank
     * transfer — against a booking: a deposit's balance, or a pay-in-person booking's price.
     *
     * The amount defaults to the whole balance and can't exceed it. Returns the ledger entry;
     * check `hasErrors()` before reporting success.
     */
    public function recordManualPayment(Booking $booking, ?float $amount = null, ?string $note = null, ?int $recordedById = null): Payment
    {
        $balance = $booking->getBalanceDue();
        $amount = PaymentHelper::round($amount ?? $balance, $booking->currency);

        $payment = new Payment([
            'bookingId' => $booking->id,
            'amount' => $amount,
            'currency' => $booking->currency,
            'status' => 'succeeded',
            'method' => self::METHOD_MANUAL,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            'recordedById' => $recordedById,
        ]);

        if ($booking->paymentStatus === PaymentStatus::Refunded->value) {
            $payment->addError('amount', Craft::t('stub', 'This booking has been refunded.'));
        } elseif ($balance <= 0) {
            $payment->addError('amount', Craft::t('stub', 'Nothing is owed on this booking.'));
        } elseif ($amount <= 0) {
            $payment->addError('amount', Craft::t('stub', 'Enter an amount greater than zero.'));
        } elseif ($amount > $balance) {
            $payment->addError('amount', Craft::t('stub', 'That’s more than the {balance} still owed.', [
                'balance' => BookingHelper::formatPrice($balance, $booking->currency),
            ]));
        }

        if ($payment->hasErrors()) {
            return $payment;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = $this->_logPayment($payment);

            if (!Plugin::getInstance()->bookings->applyPayment($booking, $amount)) {
                throw new \RuntimeException('Couldn’t save the booking: ' . implode(' ', $booking->getFirstErrors()));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $payment->id = $record->id;
        $payment->paidAt = $record->paidAt;

        $this->trigger(self::EVENT_PAYMENT_COMPLETED, new PaymentEvent([
            'booking' => $booking,
            'payment' => $payment,
        ]));

        return $payment;
    }

    /**
     * Every payment recorded against a booking, oldest first — pending and failed Stripe
     * attempts included, so the control panel can show what happened.
     *
     * @return Payment[]
     */
    public function getPaymentsForBooking(int $bookingId): array
    {
        $rows = (new Query())
            ->select(['id', 'bookingId', 'stripePaymentIntentId', 'stripeChargeId', 'amount', 'currency', 'status', 'method', 'paidAt', 'note', 'recordedById', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_payments}}')
            ->where(['bookingId' => $bookingId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => new Payment([
            'id' => (int)$row['id'],
            'bookingId' => (int)$row['bookingId'],
            'stripePaymentIntentId' => $row['stripePaymentIntentId'],
            'stripeChargeId' => $row['stripeChargeId'],
            'amount' => (float)$row['amount'],
            'currency' => $row['currency'],
            'status' => $row['status'],
            'method' => $row['method'],
            'paidAt' => $row['paidAt'],
            'note' => $row['note'],
            'recordedById' => $row['recordedById'] !== null ? (int)$row['recordedById'] : null,
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]), $rows);
    }

    /**
     * Money actually received between two moments: every succeeded payment — Stripe's and
     * the ones staff recorded — counted when it arrived. A deposit counts in the month it was
     * paid and its balance in the month that was, which is what makes this cash received
     * rather than bookings sold.
     *
     * Bounds are converted to UTC here, because `paidAt` is stored as naive UTC and is
     * queried directly rather than through `Db::parseDateParam()`.
     */
    public function getRevenueBetween(DateTime $start, DateTime $end): float
    {
        $utc = new DateTimeZone('UTC');

        return (float)(new Query())
            ->from('{{%stub_payments}}')
            ->where(['status' => 'succeeded'])
            ->andWhere(['>=', 'paidAt', (clone $start)->setTimezone($utc)->format('Y-m-d H:i:s')])
            ->andWhere(['<=', 'paidAt', (clone $end)->setTimezone($utc)->format('Y-m-d H:i:s')])
            ->sum('amount');
    }

    /**
     * Write a payment to the ledger.
     */
    private function _logPayment(Payment $payment): PaymentRecord
    {
        $record = new PaymentRecord();
        $record->bookingId = $payment->bookingId;
        $record->stripePaymentIntentId = $payment->stripePaymentIntentId;
        $record->amount = $payment->amount;
        $record->currency = $payment->currency;
        $record->status = $payment->status;
        $record->method = $payment->method;
        $record->paidAt = $payment->paidAt ?? self::_now();
        $record->note = $payment->note;
        $record->recordedById = $payment->recordedById;

        if (!$record->save()) {
            throw new \RuntimeException('Couldn’t record the payment: ' . implode(' ', $record->getFirstErrors()));
        }

        return $record;
    }

    /**
     * Ledger an amount received with the booking itself — a manual booking entered as already
     * paid. The booking already carries the amount; this only writes the ledger row behind it.
     */
    public function logReceivedWithBooking(Booking $booking, float $amount, ?int $recordedById = null): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->_logPayment(new Payment([
            'bookingId' => $booking->id,
            'amount' => $amount,
            'currency' => $booking->currency,
            'status' => 'succeeded',
            'method' => self::METHOD_MANUAL,
            'paidAt' => $booking->paidAt,
            'note' => Craft::t('stub', 'Recorded with the booking'),
            'recordedById' => $recordedById,
        ]));
    }

    private static function _now(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function handleWebhookEvent(string $payload, string $sigHeader): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $webhookSecret = trim((string)App::parseEnv($settings->stripeWebhookSecret));

        // Without a signing secret there is nothing to verify against: Stripe's library would
        // happily check a signature made with an empty key, which anyone can produce, and a forged
        // `payment_intent.succeeded` would mark a booking paid. Until 5.8.1 that is what happened.
        if ($webhookSecret === '') {
            Craft::error('Stripe webhook refused: no webhook signing secret is set in Stub’s settings.', __METHOD__);

            return false;
        }

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

        // Stripe retries a webhook until it gets a 2xx, and can deliver one twice. Now that a
        // payment *adds* to what a booking has received, a second delivery must not count the
        // money again.
        if ($paymentRecord && $paymentRecord->status === 'succeeded') {
            return;
        }

        // The amount is the ledger's, which is what this intent was created for. Without a
        // ledger row (it failed to save), fall back to what Stripe says it received.
        $amount = $paymentRecord
            ? (float)$paymentRecord->amount
            : Currencies::fromMinorUnits((int)($paymentIntent->amount_received ?? $paymentIntent->amount ?? 0), $booking->currency);

        if ($paymentRecord) {
            $paymentRecord->status = 'succeeded';
            $paymentRecord->paidAt = self::_now();
            $paymentRecord->stripeChargeId = $paymentIntent->latest_charge ?? null;
            $paymentRecord->stripeResponse = json_decode(json_encode($paymentIntent), true);
            $paymentRecord->save();
        }

        // Count the money, then confirm a booking that was waiting on it — which is what
        // sends the confirmation email, so it goes out with the amounts already up to date.
        Plugin::getInstance()->bookings->applyPayment($booking, $amount, $paymentIntent->id);

        if ($booking->bookingStatus === BookingStatus::Pending->value) {
            Plugin::getInstance()->bookings->updateStatus($booking, BookingStatus::Confirmed);
        }

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
                'method' => self::METHOD_STRIPE,
                'paidAt' => $paymentRecord->paidAt,
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
