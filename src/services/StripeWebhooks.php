<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use justinholtweb\showtime\Plugin;
use Stripe\Webhook;
use Throwable;

/**
 * One Stripe webhook endpoint for the whole bundle.
 *
 * Standalone, Stub and Headcount each expose their own endpoint with its own signing
 * secret, so a customer running both configures two of everything in the Stripe dashboard
 * and gets to debug which one is misconfigured. The bundle shares one Stripe account, so it
 * should share one endpoint: `/actions/showtime/webhook/stripe`.
 *
 * Neither module needed changing for this — both already verify the signature themselves
 * and take the raw payload — so routing is a matter of reading the event type and handing
 * the payload to whichever one owns it. The modules' own endpoints keep working, so an
 * existing site that already has them configured is not broken by upgrading.
 */
class StripeWebhooks extends Component
{
    /**
     * Event-type prefixes belonging to bookings. Everything else is memberships:
     * subscriptions, invoices and Checkout sessions are all Headcount's.
     *
     * Stub looks its payment up by PaymentIntent id and no-ops when it doesn't recognise
     * one, so a membership-originated intent landing here is harmless.
     */
    private const BOOKING_PREFIXES = ['payment_intent.', 'charge.'];

    /**
     * Which module owns this event type.
     */
    public function routeFor(string $eventType): string
    {
        foreach (self::BOOKING_PREFIXES as $prefix) {
            if (str_starts_with($eventType, $prefix)) {
                return 'stub';
            }
        }

        return 'headcount';
    }

    /**
     * The signing secret this endpoint verifies against.
     *
     * The shared one if the bundle has it; otherwise whichever module has one configured,
     * so the unified endpoint still works on a site set up before the shared field existed.
     */
    public function signingSecret(): string
    {
        $shared = Plugin::getInstance()->getSettings()->stripeWebhookSecret;

        if ($shared !== '') {
            return (string)App::parseEnv($shared);
        }

        foreach (['headcount', 'stub'] as $handle) {
            $module = Plugin::getInstance()->getModuleByHandle($handle);
            $secret = $module?->getSettings()->stripeWebhookSecret ?? '';

            if ($secret !== '') {
                return (string)App::parseEnv($secret);
            }
        }

        return '';
    }

    /**
     * Verify and dispatch. Returns [handled, module, message].
     *
     * @return array{bool, ?string, string}
     */
    public function handle(string $payload, string $sigHeader): array
    {
        $secret = $this->signingSecret();

        if ($secret === '') {
            return [false, null, 'No Stripe webhook signing secret is configured.'];
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (Throwable $e) {
            // Deliberately vague to the caller: a detailed signature error tells an attacker
            // how close they got. The detail goes to the log.
            Craft::warning('Showtime: rejected a Stripe webhook — ' . $e->getMessage(), __METHOD__);
            return [false, null, 'Invalid signature.'];
        }

        $module = $this->routeFor((string)$event->type);

        try {
            if ($module === 'stub') {
                /** @var \justinholtweb\stub\Plugin|null $stub */
                $stub = Plugin::getInstance()->getModuleByHandle('stub');

                if ($stub === null) {
                    return [false, $module, 'Bookings module is not mounted.'];
                }

                // Handed the raw payload rather than the parsed event: Stub verifies it
                // again with its own resolved secret, which keeps its public API untouched
                // and means its standalone endpoint and this one behave identically.
                $stub->payments->handleWebhookEvent($payload, $sigHeader);

                return [true, $module, 'Handled by bookings.'];
            }

            /** @var \justinholtweb\headcount\Headcount|null $headcount */
            $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

            if ($headcount === null) {
                return [false, $module, 'Memberships module is not mounted.'];
            }

            $result = $headcount->webhooks->processStripeEvent($event);

            return [true, $module, (string)$result];
        } catch (Throwable $e) {
            // Stripe retries on a non-2xx, so a genuine processing failure should surface
            // rather than be swallowed into a 200.
            Craft::error("Showtime: Stripe webhook {$event->type} failed in $module — {$e->getMessage()}", __METHOD__);

            return [false, $module, 'Processing failed.'];
        }
    }
}
