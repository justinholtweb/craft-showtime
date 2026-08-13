<?php

namespace justinholtweb\stub\services;

use Craft;
use NumberFormatter;
use yii\base\Component;

/**
 * Currency support for services, bookings and payments.
 *
 * The class is split deliberately:
 *
 * - **Static methods** are pure currency facts — how many decimal places a code has,
 *   how to render it, what the built-in list is. They depend on nothing but ext-intl
 *   (a hard requirement of craftcms/cms), so they work before Craft is booted and are
 *   unit-testable without a test site.
 * - **Instance methods** answer "what can this *site* offer?", which depends on the
 *   settings and on whether Commerce is installed. Those need a booted app.
 */
class Currencies extends Component
{
    /**
     * The built-in picker list, in rough order of how often a booking site will want them.
     *
     * Deliberately short. Craft Commerce offers the whole ISO-4217 set, but a booking
     * plugin charging through one Stripe account almost never needs more than one of
     * these — a 150-entry dropdown makes the common case worse to serve the rare one.
     * Sites that do need something else get it from Commerce (see getAvailableCurrencies())
     * or by keeping whatever code is already saved, which is never dropped from the list.
     */
    private const COMMON = [
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'CHF' => 'Swiss Franc',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'NZD' => 'New Zealand Dollar',
        'JPY' => 'Japanese Yen',
        'SEK' => 'Swedish Krona',
        'NOK' => 'Norwegian Krone',
        'DKK' => 'Danish Krone',
        'PLN' => 'Polish Złoty',
        'CZK' => 'Czech Koruna',
        'HUF' => 'Hungarian Forint',
        'ZAR' => 'South African Rand',
        'SGD' => 'Singapore Dollar',
        'HKD' => 'Hong Kong Dollar',
        'INR' => 'Indian Rupee',
        'BRL' => 'Brazilian Real',
        'MXN' => 'Mexican Peso',
        'ILS' => 'Israeli New Shekel',
        'AED' => 'UAE Dirham',
        'TRY' => 'Turkish Lira',
        'KRW' => 'South Korean Won',
        'THB' => 'Thai Baht',
    ];

    /**
     * Currencies Stripe bills in whole units — there is no "cents" to multiply up to.
     *
     * This is Stripe's list, not ICU's, and the two genuinely disagree: ICU reports ISK
     * as zero-decimal, while Stripe expects ISK amounts in aurar (×100). Formatting can
     * follow ICU, but the charge amount must follow Stripe or every payment in the
     * disputed currency is off by 100×.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const STRIPE_ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Currencies Stripe bills in thousandths. Stripe additionally requires the minor
     * amount to be a multiple of 10, so 1.234 KWD is charged as 1230, not 1234.
     *
     * @see https://docs.stripe.com/currencies#three-decimal
     */
    private const STRIPE_THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /**
     * Fallback locale for formatting when there is no booted app to ask (CLI tooling,
     * unit tests). Matches Craft's own default formatting locale.
     */
    private const FALLBACK_LOCALE = 'en-US';

    // Pure currency facts
    // =========================================================================

    /**
     * The built-in list as `code => name`.
     *
     * @return array<string, string>
     */
    public static function commonCurrencies(): array
    {
        return self::COMMON;
    }

    /**
     * Whether a code is a well-formed ISO-4217 alphabetic code.
     *
     * Deliberately a shape check rather than a membership check: Commerce and existing
     * saved rows can both legitimately supply codes outside the built-in list.
     */
    public static function isValidCode(string $code): bool
    {
        return (bool)preg_match('/^[A-Z]{3}$/', $code);
    }

    /**
     * How many decimal places Stripe expects for a currency's minor unit.
     */
    public static function minorUnitDigits(string $code): int
    {
        $code = strtoupper($code);

        if (in_array($code, self::STRIPE_ZERO_DECIMAL, true)) {
            return 0;
        }

        if (in_array($code, self::STRIPE_THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Convert a decimal price into the integer minor-unit amount Stripe charges.
     *
     * ¥1000 is 1000, not 100000; $10.00 is 1000; 1.234 KWD is 1230.
     */
    public static function toMinorUnits(float $amount, string $code): int
    {
        $digits = self::minorUnitDigits($code);
        $minor = (int)round($amount * (10 ** $digits));

        // Stripe rejects three-decimal amounts that aren't a multiple of 10.
        if ($digits === 3) {
            $minor = (int)(round($minor / 10) * 10);
        }

        return $minor;
    }

    /**
     * Format a price for display, using the currency's real symbol, decimal count and
     * symbol placement rather than assuming "$" and two decimals.
     *
     * @param string|null $locale Formatting locale; defaults to the site's.
     */
    public static function format(float $amount, string $code, ?string $locale = null): string
    {
        $code = strtoupper($code);

        if (!self::isValidCode($code)) {
            return number_format($amount, 2);
        }

        $formatter = new NumberFormatter($locale ?? self::currentLocale(), NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($amount, $code);

        // formatCurrency() returns false on an ICU failure rather than throwing.
        return $formatted === false ? $code . ' ' . number_format($amount, 2) : $formatted;
    }

    /**
     * The locale to format in. Falls back when there is no booted app, which keeps the
     * static formatting usable from unit tests and console tooling.
     *
     * The `class_exists` guard is not paranoia: Craft.php is loaded by Craft's bootstrap
     * rather than PSR-4 autoloading, so under plain PHPUnit the class genuinely isn't
     * there. Passing `false` keeps it from trying (and failing) to autoload.
     */
    public static function currentLocale(): string
    {
        if (!class_exists(Craft::class, false) || Craft::$app === null) {
            return self::FALLBACK_LOCALE;
        }

        try {
            return Craft::$app->getFormattingLocale()->id;
        } catch (\Throwable) {
            return self::FALLBACK_LOCALE;
        }
    }

    // Site-dependent list
    // =========================================================================

    /**
     * Every currency this site can pick, as `code => label`.
     *
     * The built-in list, plus anything Commerce is configured for, plus `$include` —
     * which callers pass as the value currently saved on the record being edited. That
     * last part matters: without it, editing a service priced in a currency that has
     * since been removed from Commerce would silently re-point it at the top of the
     * list on the next save.
     *
     * @param string|null $include A code to guarantee is present, whatever its origin.
     * @return array<string, string>
     */
    public function getAvailableCurrencies(?string $include = null): array
    {
        $currencies = self::COMMON;

        foreach ($this->getCommerceCurrencies() as $code) {
            if (!isset($currencies[$code])) {
                $currencies[$code] = $code;
            }
        }

        if ($include !== null) {
            $include = strtoupper($include);
            if (self::isValidCode($include) && !isset($currencies[$include])) {
                $currencies[$include] = $include;
            }
        }

        return $currencies;
    }

    /**
     * The available currencies shaped for Craft's `forms.selectField`.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getCurrencyOptions(?string $include = null): array
    {
        $options = [];

        foreach ($this->getAvailableCurrencies($include) as $code => $name) {
            $options[] = [
                'label' => $code === $name ? $code : "$code — $name",
                'value' => $code,
            ];
        }

        return $options;
    }

    /**
     * Currency codes configured across every Craft Commerce store.
     *
     * Commerce is not a dependency, so this is entirely defensive — every call is
     * guarded, and any surprise from a Commerce version we haven't seen degrades to
     * "no extra currencies" rather than taking the settings screen down with it.
     *
     * @return string[]
     */
    public function getCommerceCurrencies(): array
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            return [];
        }

        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');
        if ($commerce === null || !method_exists($commerce, 'getStores')) {
            return [];
        }

        try {
            $codes = [];

            foreach ($commerce->getStores()->getAllStores() as $store) {
                $code = $this->_storeCurrencyCode($store);
                if ($code !== null) {
                    $codes[] = $code;
                }

                $codes = array_merge($codes, $this->_paymentCurrencyCodes($commerce, $store));
            }

            return array_values(array_unique($codes));
        } catch (\Throwable $e) {
            Craft::warning('Could not read currencies from Craft Commerce: ' . $e->getMessage(), __METHOD__);
            return [];
        }
    }

    /**
     * Alternative currencies a Commerce store accepts payment in, on top of its base
     * currency — the closest thing Commerce has to "the currencies this site enabled".
     *
     * NOT verified against a live Commerce install; the docs are thin on this service
     * and its signature has moved between releases. Hence the belt-and-braces guards
     * and the no-argument retry: the worst case is that a site gets the store base
     * currencies only, which is still strictly better than the hardcoded five.
     *
     * @return string[]
     */
    private function _paymentCurrencyCodes(object $commerce, object $store): array
    {
        if (!method_exists($commerce, 'getPaymentCurrencies')) {
            return [];
        }

        $service = $commerce->getPaymentCurrencies();
        if (!method_exists($service, 'getAllPaymentCurrencies')) {
            return [];
        }

        try {
            $currencies = $service->getAllPaymentCurrencies($store->id ?? null);
        } catch (\ArgumentCountError | \TypeError) {
            $currencies = $service->getAllPaymentCurrencies();
        }

        $codes = [];

        foreach ($currencies as $currency) {
            $code = is_object($currency) && isset($currency->iso) ? $currency->iso : null;
            if (is_string($code) && self::isValidCode(strtoupper($code))) {
                $codes[] = strtoupper($code);
            }
        }

        return $codes;
    }

    /**
     * Pull the ISO code off a Commerce store model.
     *
     * Commerce 5 exposes both a `currencyIso` property and a `getCurrency()` model; which
     * one is populated has moved between releases, so try both before giving up.
     */
    private function _storeCurrencyCode(object $store): ?string
    {
        $code = null;

        if (isset($store->currencyIso) && is_string($store->currencyIso)) {
            $code = $store->currencyIso;
        } elseif (method_exists($store, 'getCurrency')) {
            $currency = $store->getCurrency();
            if ($currency !== null && method_exists($currency, 'getCode')) {
                $code = $currency->getCode();
            }
        }

        if (!is_string($code)) {
            return null;
        }

        $code = strtoupper($code);
        return self::isValidCode($code) ? $code : null;
    }
}
