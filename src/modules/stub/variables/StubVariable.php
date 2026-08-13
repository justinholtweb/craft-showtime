<?php

namespace justinholtweb\stub\variables;

use Craft;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\elements\db\BookingQuery;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\services\Currencies;
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

    /**
     * Format a price with its currency's symbol, decimal count and symbol placement.
     *
     * Exposed so templates don't have to reach for `|number_format(2)`, which quietly
     * assumes every currency has two decimals and puts the code after the amount.
     */
    public function formatPrice(float $price, ?string $currency = null): string
    {
        $currency ??= Plugin::getInstance()->getSettings()->defaultCurrency;

        return Currencies::format($price, $currency);
    }

    /**
     * Currencies this site can price in, as `code => label`. Includes anything
     * configured in Craft Commerce when it's installed.
     *
     * @return array<string, string>
     */
    public function currencies(): array
    {
        return Plugin::getInstance()->currencies->getAvailableCurrencies();
    }
}
