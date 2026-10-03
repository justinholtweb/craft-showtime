<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\enums\BookingStatus;
use justinholtweb\stub\enums\PaymentStatus;
use justinholtweb\stub\helpers\TimeHelper;
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
            'settings' => Plugin::getInstance()->getSettings(),
            'remindersEnabled' => Plugin::getInstance()->reminders->isEnabled(),
        ]);
    }

    /**
     * The form for entering a booking by hand — a phone call, a walk-in, a regular whose
     * slot is always the same.
     */
    public function actionNew(?array $values = null, ?array $errors = null): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $providers = Plugin::getInstance()->providers->getAllProviders();

        $providerServiceIds = [];
        foreach ($providers as $provider) {
            $providerServiceIds[$provider->id] = $this->_serviceIdsFor($provider->id);
        }

        return $this->renderTemplate('stub/bookings/_new', [
            'title' => Craft::t('stub', 'New Booking'),
            'selectedSubnavItem' => 'bookings',
            'values' => ($values ?? []) + $this->_defaultValues($settings->defaultTimezone),
            'errors' => $errors ?? [],
            'services' => Plugin::getInstance()->services->getAllServices(),
            'providers' => $providers,
            // Which services each provider offers, so the form can narrow the provider
            // list as the service is picked without a round trip per keystroke.
            'providerServiceIds' => $providerServiceIds,
            'statuses' => BookingStatus::cases(),
            'paymentStatuses' => PaymentStatus::cases(),
        ]);
    }

    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $values = [
            'serviceId' => (int)$request->getBodyParam('serviceId'),
            'providerId' => (int)$request->getBodyParam('providerId'),
            'date' => trim((string)$request->getBodyParam('date')),
            'slot' => trim((string)$request->getBodyParam('slot')),
            'time' => trim((string)$request->getBodyParam('time')),
            'timezone' => trim((string)$request->getBodyParam('timezone')),
            'overrideAvailability' => (bool)$request->getBodyParam('overrideAvailability'),
            'email' => trim((string)$request->getBodyParam('email')),
            'firstName' => trim((string)$request->getBodyParam('firstName')),
            'lastName' => trim((string)$request->getBodyParam('lastName')),
            'phone' => trim((string)$request->getBodyParam('phone')),
            'customerNotes' => $request->getBodyParam('customerNotes') ?: null,
            'adminNotes' => $request->getBodyParam('adminNotes') ?: null,
            'bookingStatus' => (string)$request->getBodyParam('bookingStatus', BookingStatus::Confirmed->value),
            'paymentStatus' => (string)$request->getBodyParam('paymentStatus', PaymentStatus::Unpaid->value),
            'sendConfirmation' => (bool)$request->getBodyParam('sendConfirmation'),
        ];

        [$errors, $startUtc] = $this->_validateManualBooking($values);

        if ($errors) {
            return $this->_redisplayNew($values, $errors);
        }

        $service = Plugin::getInstance()->services->getServiceById($values['serviceId']);
        $endUtc = (clone $startUtc)->modify("+{$service->duration} minutes");

        $customer = Plugin::getInstance()->customers->findOrCreate(
            $values['email'],
            $values['firstName'],
            $values['lastName'],
            $values['phone'] ?: null,
        );

        // findOrCreate() returns the model either way, saved or not — and an unsaved one
        // has no ID to hang a booking off, which the foreign key would report as a
        // database error rather than something the admin can fix.
        if (!$customer->id) {
            return $this->_redisplayNew($values, $customer->getErrors() ?: [
                'email' => [Craft::t('stub', 'Couldn’t save this customer.')],
            ]);
        }

        $booking = Plugin::getInstance()->bookings->createManualBooking([
            'serviceId' => $values['serviceId'],
            'providerId' => $values['providerId'],
            'customerId' => $customer->id,
            'startDateTime' => $startUtc->format('Y-m-d H:i:s'),
            'endDateTime' => $endUtc->format('Y-m-d H:i:s'),
            'timezone' => $values['timezone'],
            'customerNotes' => $values['customerNotes'],
            'adminNotes' => $values['adminNotes'],
            'bookingStatus' => $values['bookingStatus'],
            'paymentStatus' => $values['paymentStatus'],
        ]);

        if ($booking->hasErrors() || !$booking->id) {
            return $this->_redisplayNew($values, $booking->getErrors() ?: [
                'serviceId' => [Craft::t('stub', 'Couldn’t save booking.')],
            ]);
        }

        // Only the customer's confirmation, and only if asked for. The admin notification
        // is deliberately left out: whoever just filled this form in is the admin.
        if ($values['sendConfirmation']) {
            Plugin::getInstance()->emails->sendConfirmation($booking);
        }

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Booking created.'));

        return $this->redirect("stub/bookings/{$booking->id}");
    }

    /**
     * Send this booking's reminder now, rather than waiting for the scheduled sweep.
     *
     * The escape hatch for a site without cron, and the way to test the copy.
     */
    public function actionSendReminder(): ?Response
    {
        $this->requirePostRequest();

        $bookingId = (int)Craft::$app->getRequest()->getRequiredBodyParam('bookingId');
        $booking = Plugin::getInstance()->bookings->getBookingById($bookingId);

        if (!$booking) {
            throw new \yii\web\NotFoundHttpException('Booking not found.');
        }

        $sent = Plugin::getInstance()->reminders->sendNow($booking);

        if ($sent > 0) {
            Craft::$app->getSession()->setNotice(Craft::t('stub', 'Reminder sent.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('stub', 'No reminder was sent. Check that reminders are turned on and the recipients have email addresses.'));
        }

        return $this->redirect("stub/bookings/{$bookingId}");
    }

    /**
     * @return array{0: array<string, string[]>, 1: ?DateTime} errors, and the UTC start
     */
    private function _validateManualBooking(array $values): array
    {
        $errors = [];
        $plugin = Plugin::getInstance();

        $service = $values['serviceId'] ? $plugin->services->getServiceById($values['serviceId']) : null;
        if (!$service) {
            $errors['serviceId'][] = Craft::t('stub', 'Choose a service.');
        }

        $provider = $values['providerId'] ? $plugin->providers->getProviderById($values['providerId']) : null;
        if (!$provider) {
            $errors['providerId'][] = Craft::t('stub', 'Choose a provider.');
        }

        if (!in_array($values['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'][] = Craft::t('stub', 'Choose a timezone.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['date'])) {
            $errors['date'][] = Craft::t('stub', 'Choose a date.');
        }

        foreach (['email' => 'Enter an email address.', 'firstName' => 'Enter a first name.', 'lastName' => 'Enter a last name.'] as $attribute => $message) {
            if ($values[$attribute] === '') {
                $errors[$attribute][] = Craft::t('stub', $message);
            }
        }

        if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = Craft::t('stub', 'Enter a valid email address.');
        }

        // Everything below needs a service, a provider, a date and a timezone to mean
        // anything, so stop here rather than reporting nonsense about the time as well.
        if ($errors) {
            return [$errors, null];
        }

        if ($values['overrideAvailability']) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values['time'])) {
                $errors['time'][] = Craft::t('stub', 'Enter a time as HH:MM.');
                return [$errors, null];
            }

            // Read in the timezone the form is working in, which is what the admin typed
            // the time in — not the server's.
            return [[], TimeHelper::convertToUtc("{$values['date']} {$values['time']}:00", $values['timezone'])];
        }

        // Not overriding: the provider has to actually offer this service, and the slot has
        // to still be free. The picker was populated from the same call, but that was some
        // seconds ago and a customer may have booked it in the meantime.
        if (!in_array($values['serviceId'], $this->_serviceIdsFor($values['providerId']), true)) {
            $errors['providerId'][] = Craft::t('stub', 'This provider doesn’t offer that service. Turn on “Override availability” to book it anyway.');
            return [$errors, null];
        }

        if ($values['slot'] === '') {
            $errors['slot'][] = Craft::t('stub', 'Choose a time.');
            return [$errors, null];
        }

        $available = $plugin->availability->getAvailableSlots(
            $values['serviceId'],
            $values['providerId'],
            $values['date'],
            $values['timezone'],
        );

        if (!in_array($values['slot'], array_column($available, 'utc'), true)) {
            $errors['slot'][] = Craft::t('stub', 'That time is no longer available. Pick another, or turn on “Override availability”.');
            return [$errors, null];
        }

        return [[], new DateTime($values['slot'], new DateTimeZone('UTC'))];
    }

    /**
     * Hand the posted values back to the form. Returning null is what makes Craft fall
     * through to the page the form posted from, which is `actionNew()`.
     */
    private function _redisplayNew(array $values, array $errors): null
    {
        Craft::$app->getSession()->setError(Craft::t('stub', 'Couldn’t create booking.'));
        Craft::$app->getUrlManager()->setRouteParams([
            'values' => $values,
            'errors' => $errors,
        ]);

        return null;
    }

    /**
     * The service IDs a provider offers, as integers.
     *
     * The query returns whatever the driver hands back, and MySQL hands back strings — so
     * a strict comparison against a posted ID silently says the provider doesn't offer a
     * service they do offer, and the same values reach the form's JS.
     *
     * @return int[]
     */
    private function _serviceIdsFor(int $providerId): array
    {
        return array_map('intval', Plugin::getInstance()->providers->getServiceIdsForProvider($providerId));
    }

    private function _defaultValues(string $defaultTimezone): array
    {
        return [
            'serviceId' => null,
            'providerId' => null,
            'date' => '',
            'slot' => '',
            'time' => '',
            'timezone' => $defaultTimezone,
            'overrideAvailability' => false,
            'email' => '',
            'firstName' => '',
            'lastName' => '',
            'phone' => '',
            'customerNotes' => null,
            'adminNotes' => null,
            'bookingStatus' => BookingStatus::Confirmed->value,
            'paymentStatus' => PaymentStatus::Unpaid->value,
            'sendConfirmation' => true,
        ];
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
