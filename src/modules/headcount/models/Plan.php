<?php

namespace justinholtweb\headcount\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;

class Plan extends Model
{
    /** Bills on the member's own anniversary, forever, until cancelled. */
    public const TERM_RECURRING = 'recurring';

    /** One payment buys membership until a fixed calendar date shared by every member. */
    public const TERM_FIXED = 'fixed';

    /** Charge for whole calendar months remaining — what a club means by "half a season". */
    public const PRORATION_MONTH = 'month';

    /** Charge to the day. Fairer arithmetic, unfamiliar prices. */
    public const PRORATION_DAY = 'day';

    /**
     * How far a repeating season is allowed to roll forward before we give up.
     *
     * Only a guard against a corrupt window (an end date at or before its start) spinning
     * the loop forever; 50 years is far past any plan anyone will still be selling.
     */
    private const MAX_SEASON_ROLLOVERS = 50;

    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public ?string $description = null;
    public ?int $userGroupId = null;
    public ?string $stripePriceId = null;
    public ?string $paypalPlanId = null;
    public float $price = 0;
    public string $currency = 'USD';
    public string $billingInterval = 'month';
    public int $billingIntervalCount = 1;
    public string $termType = self::TERM_RECURRING;
    public ?DateTime $seasonStartDate = null;
    public ?DateTime $seasonEndDate = null;
    public bool $seasonRepeats = true;
    public bool $prorate = false;
    public string $prorationBasis = self::PRORATION_MONTH;
    public int $trialDays = 0;
    public int $sortOrder = 0;
    public bool $enabled = true;
    public ?array $features = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Accept the shapes a date arrives in — a control-panel date field posts an array,
     * the database hands back a string, and callers pass DateTime.
     */
    public function setSeasonStartDate(mixed $value): void
    {
        $this->seasonStartDate = $this->normalizeDate($value);
    }

    public function setSeasonEndDate(mixed $value): void
    {
        $this->seasonEndDate = $this->normalizeDate($value);
    }

    /**
     * A DateTime is passed straight through; anything else goes to Craft's parser, which
     * knows about the control panel's array shape and the site's timezone (and, because it
     * asks the application for that timezone, needs one running).
     */
    private function normalizeDate(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'billingInterval', 'currency', 'termType'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-z0-9\-]*$/'],
            [['description'], 'string'],
            [['userGroupId', 'billingIntervalCount', 'trialDays', 'sortOrder'], 'integer'],
            [['price'], 'number', 'min' => 0],
            [['currency'], 'string', 'max' => 3],
            [['billingInterval'], 'in', 'range' => ['day', 'week', 'month', 'year']],
            [['termType'], 'in', 'range' => [self::TERM_RECURRING, self::TERM_FIXED]],
            [['prorationBasis'], 'in', 'range' => [self::PRORATION_MONTH, self::PRORATION_DAY]],
            [['enabled', 'seasonRepeats', 'prorate'], 'boolean'],
            [['stripePriceId', 'paypalPlanId'], 'string', 'max' => 255],
            [['seasonStartDate', 'seasonEndDate'], 'required', 'when' => fn(self $plan) => $plan->isFixedTerm()],
            [['seasonEndDate'], 'validateSeasonWindow'],
        ];
    }

    /**
     * A season has to be a window, and a repeating one has to fit inside a year.
     *
     * Without the second check a repeating 1 July → 30 June *2028* window overlaps its own
     * next occurrence, so "which season is a member in" stops having one answer.
     */
    public function validateSeasonWindow(): void
    {
        if (!$this->isFixedTerm() || !$this->seasonStartDate || !$this->seasonEndDate) {
            return;
        }

        if ($this->seasonEndDate <= $this->seasonStartDate) {
            $this->addError('seasonEndDate', \Craft::t('headcount', 'The season must end after it starts.'));
            return;
        }

        if ($this->seasonRepeats && $this->seasonStartDate->diff($this->seasonEndDate)->days >= 366) {
            $this->addError('seasonEndDate', \Craft::t('headcount', 'A repeating season must be shorter than a year, otherwise it would overlap its own next occurrence.'));
        }
    }

    public function isFixedTerm(): bool
    {
        return $this->termType === self::TERM_FIXED;
    }

    /**
     * The season this plan is selling as of `$at`, as `['start' => DateTime, 'end' => DateTime]`.
     *
     * For a repeating season the stored window is a template: it rolls forward a year at a
     * time until it hasn't already finished. It deliberately never rolls *backwards* — a
     * club that sets up next season in advance is selling into that season, not the last one.
     *
     * Null for a recurring plan, or a fixed-term plan whose window is incomplete.
     */
    public function getSeasonWindow(?DateTimeInterface $at = null): ?array
    {
        if (!$this->isFixedTerm() || !$this->seasonStartDate || !$this->seasonEndDate) {
            return null;
        }

        if ($this->seasonEndDate <= $this->seasonStartDate) {
            return null;
        }

        $at ??= new DateTime();
        $start = clone $this->seasonStartDate;
        $end = $this->_endOfDay(clone $this->seasonEndDate);

        if ($this->seasonRepeats) {
            $rollovers = 0;
            while ($end < $at && $rollovers++ < self::MAX_SEASON_ROLLOVERS) {
                $start = (clone $start)->modify('+1 year');
                $end = (clone $end)->modify('+1 year');
            }
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * When a membership bought at `$at` starts.
     *
     * Buying before the season opens buys the season, not the wait — access starts when the
     * season does. Buying mid-season starts immediately.
     */
    public function getTermStart(?DateTimeInterface $at = null): ?DateTime
    {
        $window = $this->getSeasonWindow($at);

        if (!$window) {
            return null;
        }

        $at ??= new DateTime();

        return $at > $window['start'] ? DateTimeHelper::toDateTime($at) : $window['start'];
    }

    /**
     * When a membership bought at `$at` expires: the end of that season, for everyone.
     */
    public function getTermEnd(?DateTimeInterface $at = null): ?DateTime
    {
        return $this->getSeasonWindow($at)['end'] ?? null;
    }

    /**
     * What someone joining at `$at` actually pays.
     *
     * Full price for a recurring plan, a plan that doesn't prorate, or anyone joining before
     * the season opens. Otherwise the price is scaled by how much of the season is left —
     * by whole calendar months (a club counting "nine months of a twelve-month season") or
     * by days. Rounded to the currency's minor unit and never above the full price.
     */
    public function getProratedPrice(?DateTimeInterface $at = null): float
    {
        $window = $this->getSeasonWindow($at);

        if (!$window || !$this->prorate) {
            return $this->price;
        }

        $at ??= new DateTime();

        if ($at <= $window['start']) {
            return $this->price;
        }

        if ($at >= $window['end']) {
            return 0.0;
        }

        if ($this->prorationBasis === self::PRORATION_DAY) {
            // Both ends counted, as with months: joining on the last day of the season still
            // buys that day, and must still cost something a gateway will accept.
            $total = (int)$window['start']->diff($window['end'])->days + 1;
            $remaining = (int)DateTimeHelper::toDateTime($at)->diff($window['end'])->days + 1;
        } else {
            $total = $this->_monthsBetween($window['start'], $window['end']);
            $remaining = $this->_monthsBetween($at, $window['end']);
        }

        if ($total <= 0) {
            return $this->price;
        }

        $prorated = round($this->price * min($remaining, $total) / $total, 2);

        return max(0.0, $prorated);
    }

    /**
     * Whether there is nothing left to sell.
     *
     * Only ever true for a one-off season that has finished — a repeating season always has
     * a next occurrence, and a recurring plan never expires as a whole.
     */
    public function hasSeasonEnded(?DateTimeInterface $at = null): bool
    {
        if (!$this->isFixedTerm() || $this->seasonRepeats) {
            return false;
        }

        $window = $this->getSeasonWindow($at);

        return $window !== null && ($at ?? new DateTime()) > $window['end'];
    }

    public function getIntervalLabel(): string
    {
        if ($this->isFixedTerm()) {
            return 'per season';
        }

        $labels = [
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'year' => 'yearly',
        ];

        if ($this->billingIntervalCount === 1) {
            return $labels[$this->billingInterval] ?? $this->billingInterval;
        }

        return "every {$this->billingIntervalCount} {$this->billingInterval}s";
    }

    public function getFormattedPrice(): string
    {
        return strtoupper($this->currency) . ' ' . number_format($this->price, 2);
    }

    /**
     * An end date entered without a time means the end of that day.
     *
     * A control-panel date field posts midnight, so a season ending "30 June" would
     * otherwise cut every member off as 30 June began — a day early, and on the wrong side
     * of a fixture.
     */
    private function _endOfDay(DateTime $date): DateTime
    {
        if ($date->format('H:i:s') === '00:00:00') {
            $date->setTime(23, 59, 59);
        }

        return $date;
    }

    /**
     * Whole calendar months from `$from` to `$to`, counting both ends.
     *
     * Joining in October of a July–June season leaves nine months, not the eight a plain
     * date subtraction gives: a member joining on the 20th still gets the rest of October.
     */
    private function _monthsBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $months = ((int)$to->format('Y') - (int)$from->format('Y')) * 12
            + ((int)$to->format('n') - (int)$from->format('n'))
            + 1;

        return max(0, $months);
    }
}
