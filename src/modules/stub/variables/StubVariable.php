<?php

namespace justinholtweb\stub\variables;

use Craft;
use craft\elements\User;
use justinholtweb\stub\elements\Booking;
use justinholtweb\stub\elements\db\BookingQuery;
use justinholtweb\stub\models\Provider;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\models\ServiceCriteria;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\services\Currencies;
use Twig\Markup;

class StubVariable
{
    /**
     * Render the booking form.
     *
     * With no options this offers every enabled service, as it always has. Pass any of the
     * service filter keys — see {@see services()} — to narrow what the form offers:
     *
     * ```twig
     * {{ craft.stub.bookingForm({ handle: 'consultation' }) }}   {# one service, one page #}
     * {{ craft.stub.bookingForm({ user: currentUser }) }}        {# this author's services #}
     * ```
     *
     * When a filter leaves exactly one service, the form pins it and skips the "select a
     * service" step; when a provider filter matches exactly one provider, it pins that too.
     * Pass `pinProvider: false` to keep the provider step even then. Filtering is a display
     * concern — see `Services::getServices()` for why it is not access control.
     *
     * @param array<string, mixed> $options
     */
    public function bookingForm(array $options = []): Markup
    {
        $pinProvider = (bool)($options['pinProvider'] ?? true);
        unset($options['pinProvider']);

        $criteria = ServiceCriteria::fromArray($options);
        $plugin = Plugin::getInstance();

        $services = $plugin->services->getServices($criteria);

        // Only pin when the template actually asked to narrow things. An unfiltered form on
        // a site that happens to have one service keeps its service step, so upgrading does
        // not silently change a page nobody asked to change.
        $pinnedService = ($criteria->isFiltered() && count($services) === 1) ? $services[0] : null;
        $pinnedProvider = null;

        if ($pinProvider && $criteria->hasProviderFilter()) {
            $providerIds = $plugin->providers->getProviderIdsFor(
                $criteria->providerIds,
                $criteria->providerHandles,
                $criteria->userIds,
                $criteria->includeDisabled,
            );

            if (count($providerIds) === 1) {
                $pinnedProvider = $plugin->providers->getProviderById($providerIds[0]);
            }
        }

        $view = Craft::$app->getView();
        $oldTemplateMode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_CP);

        $html = $view->renderTemplate('stub/frontend/booking-form', [
            'services' => $services,
            'settings' => $plugin->getSettings(),
            'pinnedService' => $pinnedService,
            'pinnedProvider' => $pinnedProvider,
            'options' => $options,
        ]);

        $view->setTemplateMode($oldTemplateMode);
        return new Markup($html, 'UTF-8');
    }

    public function bookings(): BookingQuery
    {
        return Booking::find();
    }

    /**
     * Enabled services, in sort order, optionally filtered.
     *
     * ```twig
     * {% for service in craft.stub.services({ user: currentUser }) %}     {# this user's #}
     * {% for service in craft.stub.services({ provider: 'jane' }) %}      {# by handle #}
     * {% for service in craft.stub.services({ handle: ['a', 'b'] }) %}    {# named ones #}
     * ```
     *
     * Filter keys: `id`/`ids`, `handle`/`handles`, `provider`/`providers` (an ID, a handle
     * or a Provider), `user`/`users` (a User element or its ID), and `includeDisabled`.
     * Unknown keys throw rather than being ignored, and a filter that resolves to nothing —
     * `{ user: currentUser }` on a logged-out request — returns nothing rather than
     * everything.
     *
     * @param array<string, mixed> $criteria
     * @return Service[]
     */
    public function services(array $criteria = []): array
    {
        return Plugin::getInstance()->services->getServices($criteria);
    }

    /**
     * A single service by ID or handle, for a page dedicated to one service.
     */
    public function service(int|string $ref): ?Service
    {
        $services = Plugin::getInstance()->services;

        return is_int($ref) || ctype_digit($ref)
            ? $services->getServiceById((int)$ref)
            : $services->getServiceByHandle($ref);
    }

    /**
     * Providers, optionally limited to those offering one service.
     */
    public function providers(?int $serviceId = null): array
    {
        if ($serviceId) {
            return Plugin::getInstance()->providers->getProvidersByServiceId($serviceId);
        }
        return Plugin::getInstance()->providers->getAllProviders();
    }

    /**
     * A single provider, by ID, handle, or the Craft user they are linked to.
     *
     * ```twig
     * {% set provider = craft.stub.provider(currentUser) %}
     * {% set provider = craft.stub.provider('jane') %}
     * ```
     */
    public function provider(User|int|string|null $ref): ?Provider
    {
        $providers = Plugin::getInstance()->providers;

        if ($ref === null || $ref === '') {
            return null;
        }

        if ($ref instanceof User) {
            return $ref->id ? $providers->getProviderByUserId($ref->id) : null;
        }

        return is_int($ref) || ctype_digit($ref)
            ? $providers->getProviderById((int)$ref)
            : $providers->getProviderByHandle($ref);
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
