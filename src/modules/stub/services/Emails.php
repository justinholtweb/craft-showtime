<?php

namespace justinholtweb\stub\services;

use Craft;
use DateTime;
use DateTimeZone;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\helpers\BookingHelper;
use justinholtweb\stub\Plugin;
use yii\base\Component;

class Emails extends Component
{
    public function sendConfirmation(Booking $booking): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->sendCustomerConfirmation) {
            return false;
        }

        $customer = $booking->getCustomer();
        if (!$customer) {
            return false;
        }

        $vars = $this->_getEmailVariables($booking);

        return $this->_sendSystemEmail(
            'stub_booking_confirmation',
            $customer->email,
            $vars,
        );
    }

    public function sendAdminNotification(Booking $booking): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->sendAdminNotification || !$settings->adminEmail) {
            return false;
        }

        $vars = $this->_getEmailVariables($booking);

        return $this->_sendSystemEmail(
            'stub_admin_notification',
            $settings->adminEmail,
            $vars,
        );
    }

    public function sendCancellation(Booking $booking): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->sendCancellationEmail) {
            return false;
        }

        $vars = $this->_getEmailVariables($booking);

        // Send to customer
        $customer = $booking->getCustomer();
        if ($customer) {
            $this->_sendSystemEmail('stub_booking_cancellation', $customer->email, $vars);
        }

        // Send to admin
        if ($settings->adminEmail) {
            $this->_sendSystemEmail('stub_booking_cancellation', $settings->adminEmail, $vars);
        }

        return true;
    }

    private function _getEmailVariables(Booking $booking): array
    {
        $service = $booking->getService();
        $provider = $booking->getProvider();
        $customer = $booking->getCustomer();

        $dateFormatted = '';
        $timeFormatted = '';
        if ($booking->startDateTime) {
            $dt = new DateTime($booking->startDateTime, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($booking->timezone));
            $dateFormatted = $dt->format('l, F j, Y');
            $timeFormatted = $dt->format('g:i A');
        }

        return [
            'referenceNumber' => $booking->referenceNumber,
            'customerName' => $customer ? $customer->getFullName() : '',
            'customerEmail' => $customer ? $customer->email : '',
            'serviceName' => $service ? $service->name : '',
            'providerName' => $provider ? $provider->name : '',
            'dateFormatted' => $dateFormatted,
            'timeFormatted' => $timeFormatted,
            'priceFormatted' => BookingHelper::formatPrice($booking->price, $booking->currency),
            'timezone' => $booking->timezone,
            'booking' => $booking,
            'service' => $service,
            'provider' => $provider,
            'customer' => $customer,
        ];
    }

    private function _sendSystemEmail(string $key, string $toEmail, array $variables): bool
    {
        try {
            // composeFromKey renders the message's subject and body itself, at send time,
            // through the site's HTML email template — so anything rendered here would only
            // be thrown away.
            $email = Craft::$app->getMailer()
                ->composeFromKey($key, $variables)
                ->setTo($toEmail);

            return $email->send();
        } catch (\Throwable $e) {
            Craft::error("Failed to send email '{$key}' to {$toEmail}: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
