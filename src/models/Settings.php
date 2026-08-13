<?php

namespace justinholtweb\showtime\models;

use Craft;
use craft\base\Model;

/**
 * Showtime's composite settings model.
 *
 * A mounted module never gets stored settings from Craft (they're merged into the
 * construction config by Plugins::createPlugin(), which only runs for installed plugins),
 * so the host owns them and hands each module its slice at mount time.
 *
 * The shared values are the point of the bundle: one Stripe account, one from-address, one
 * currency — entered once instead of once per plugin.
 *
 * They are deliberately **flat scalars, not nested arrays.** Craft packs associative arrays
 * into project config as `__assoc__` lists, and `project-config/set` on a path *inside* one
 * writes a loose sibling key that the unpacker then discards — so
 * `project-config/set plugins.showtime.settings.stripe.webhookSecret …` silently did
 * nothing. Flat keys make that whole class of mistake impossible.
 */
class Settings extends Model
{
    /**
     * Shared Stripe credentials. These use the same attribute names the modules use, so the
     * mapping to each module is an identity rather than a translation table.
     */
    public string $stripeSecretKey = '';
    public string $stripePublishableKey = '';
    public string $stripeWebhookSecret = '';

    /** Shared outgoing-mail identity. */
    public string $emailFromName = '';
    public string $emailFromEmail = '';

    public string $defaultCurrency = 'USD';

    /** Per-module overrides, keyed exactly like each module's own Settings model. */
    public array $owl = [];
    public array $stub = [];
    public array $headcount = [];

    /**
     * The shared currency is pushed into Stub and Headcount, both of which validate it as
     * an ISO 4217 code — so catch a bad one here, on the screen it was typed into, rather
     * than letting a module reject it later with no visible field to blame.
     *
     * Empty is valid and means "don't override": each module keeps its own default.
     */
    public function defineRules(): array
    {
        return [
            [['defaultCurrency'], 'match', 'pattern' => '/^[A-Z]{3}$/', 'skipOnEmpty' => true],
        ];
    }

    /**
     * The settings array handed to a module at mount time.
     *
     * Resolution order (last wins):
     *   1. shared values, under that module's own attribute names
     *   2. the module's slice of Showtime's settings
     *   3. config/<handle>.php — kept so existing customer configs and the standalone
     *      plugins' published docs keep working verbatim after adopting Showtime
     */
    public function forModule(string $handle): array
    {
        $overrides = match ($handle) {
            'owl' => $this->owl,
            'stub' => $this->stub,
            'headcount' => $this->headcount,
            default => [],
        };

        $shared = $this->sharedFor($handle);

        // For an attribute that has a shared counterpart, an empty override means "inherit",
        // not "blank". A module whose settings screen was saved before a shared value existed
        // stores an empty string, and without this that empty string would shadow the shared
        // value forever — you'd set the Stripe key once, centrally, and it would never arrive.
        foreach (array_keys($shared) as $attribute) {
            if (($overrides[$attribute] ?? null) === '') {
                unset($overrides[$attribute]);
            }
        }

        return array_merge(
            $shared,
            $overrides,
            Craft::$app->getConfig()->getConfigFromFile($handle),
        );
    }

    /**
     * Strip out anything a module inherited from the shared layer, so only genuine
     * overrides are stored.
     *
     * Without this, saving a module's settings screen writes back its *resolved* settings —
     * shared values included — freezing a copy of the shared Stripe key into the module's
     * override. Changing the shared key afterwards would then silently stop reaching that
     * module, which defeats the point of having a shared group at all.
     */
    public function withoutShared(string $handle, array $moduleSettings): array
    {
        foreach ($this->sharedFor($handle) as $attribute => $value) {
            if (array_key_exists($attribute, $moduleSettings) && $moduleSettings[$attribute] === $value) {
                unset($moduleSettings[$attribute]);
            }
        }

        return $moduleSettings;
    }

    /**
     * Shared settings under a module's own attribute names, omitting anything unset.
     */
    private function sharedFor(string $handle): array
    {
        $shared = [];

        // Stub and Headcount each name their Stripe credentials identically.
        if (in_array($handle, ['stub', 'headcount'], true)) {
            foreach (['stripeSecretKey', 'stripePublishableKey', 'stripeWebhookSecret'] as $attribute) {
                if ($this->$attribute !== '') {
                    $shared[$attribute] = $this->$attribute;
                }
            }
        }

        if ($handle === 'headcount' && $this->defaultCurrency !== '') {
            $shared['defaultCurrency'] = $this->defaultCurrency;
        }

        if ($handle === 'stub' && $this->defaultCurrency !== '') {
            $shared['defaultCurrency'] = $this->defaultCurrency;
        }

        return $shared;
    }
}
