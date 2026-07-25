<?php

namespace justinholtweb\stub\variables;

use Craft;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\elements\db\BookingQuery;
use justinholtweb\stub\Plugin;
use Twig\Markup;

class StubVariable
{
    public function bookingForm(array $options = []): Markup
    {
        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_CP);

        $services = Plugin::getInstance()->services->getAllServices();
        $settings = Plugin::getInstance()->getSettings();

        $html = $view->renderTemplate('stub/frontend/booking-form', [
            'services' => $services,
            'settings' => $settings,
            'options' => $options,
        ]);

        $view->setTemplateMode($oldTemplateMode);
        return new Markup($html, 'UTF-8');
    }

    public function bookings(): BookingQuery
    {
        return Booking::find();
    }

    public function services(): array
    {
        return Plugin::getInstance()->services->getAllServices();
    }

    public function providers(int $serviceId = null): array
    {
        if ($serviceId) {
            return Plugin::getInstance()->providers->getProvidersByServiceId($serviceId);
        }
        return Plugin::getInstance()->providers->getAllProviders();
    }

    public function settings(): object
    {
        return Plugin::getInstance()->getSettings();
    }
}
