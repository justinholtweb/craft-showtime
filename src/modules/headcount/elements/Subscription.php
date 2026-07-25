<?php

namespace justinholtweb\headcount\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\headcount\elements\db\SubscriptionQuery;
use justinholtweb\headcount\Headcount;

/**
 * Subscription element type
 *
 * @property-read User|null $user
 * @property-read \justinholtweb\headcount\models\Plan|null $plan
 */
class Subscription extends Element
{
    // Statuses
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PAUSED = 'paused';

    public ?int $userId = null;
    public ?int $planId = null;
    public string $gateway = 'stripe';
    public ?string $gatewaySubscriptionId = null;
    public ?string $gatewayCustomerId = null;
    public string $status = self::STATUS_ACTIVE;
    public ?DateTime $trialStartDate = null;
    public ?DateTime $trialEndDate = null;
    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;
    public ?DateTime $canceledAt = null;
    public bool $cancelAtPeriodEnd = false;
    public float $amount = 0;
    public string $currency = 'USD';
    public ?array $metadata = null;

    private ?User $_user = null;
    private ?\justinholtweb\headcount\models\Plan $_plan = null;

    public static function displayName(): string
    {
        return Craft::t('headcount', 'Subscription');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('headcount', 'Subscriptions');
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_ACTIVE => ['label' => Craft::t('headcount', 'Active'), 'color' => 'green'],
            self::STATUS_TRIALING => ['label' => Craft::t('headcount', 'Trialing'), 'color' => 'blue'],
            self::STATUS_PAST_DUE => ['label' => Craft::t('headcount', 'Past Due'), 'color' => 'orange'],
            self::STATUS_CANCELED => ['label' => Craft::t('headcount', 'Canceled'), 'color' => 'red'],
            self::STATUS_EXPIRED => ['label' => Craft::t('headcount', 'Expired'), 'color' => 'disabled'],
            self::STATUS_PAUSED => ['label' => Craft::t('headcount', 'Paused'), 'color' => 'yellow'],
        ];
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public static function find(): SubscriptionQuery
    {
        return new SubscriptionQuery(static::class);
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('headcount', 'All Subscriptions'),
            ],
            ['heading' => Craft::t('headcount', 'Status')],
            [
                'key' => 'status:active',
                'label' => Craft::t('headcount', 'Active'),
                'criteria' => ['status' => self::STATUS_ACTIVE],
            ],
            [
                'key' => 'status:trialing',
                'label' => Craft::t('headcount', 'Trialing'),
                'criteria' => ['status' => self::STATUS_TRIALING],
            ],
            [
                'key' => 'status:past_due',
                'label' => Craft::t('headcount', 'Past Due'),
                'criteria' => ['status' => self::STATUS_PAST_DUE],
            ],
            [
                'key' => 'status:canceled',
                'label' => Craft::t('headcount', 'Canceled'),
                'criteria' => ['status' => self::STATUS_CANCELED],
            ],
            [
                'key' => 'status:expired',
                'label' => Craft::t('headcount', 'Expired'),
                'criteria' => ['status' => self::STATUS_EXPIRED],
            ],
        ];

        // Add plan-based sources
        $plans = Headcount::getInstance()->plans->getAllPlans();
        if (!empty($plans)) {
            $sources[] = ['heading' => Craft::t('headcount', 'By Plan')];
            foreach ($plans as $plan) {
                $sources[] = [
                    'key' => 'plan:' . $plan->id,
                    'label' => $plan->name,
                    'criteria' => ['planId' => $plan->id],
                ];
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'user' => Craft::t('headcount', 'User'),
            'plan' => Craft::t('headcount', 'Plan'),
            'status' => Craft::t('headcount', 'Status'),
            'gateway' => Craft::t('headcount', 'Gateway'),
            'amount' => Craft::t('headcount', 'Amount'),
            'startDate' => Craft::t('headcount', 'Start Date'),
            'endDate' => Craft::t('headcount', 'End Date'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['user', 'plan', 'status', 'gateway', 'amount', 'endDate'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['gatewaySubscriptionId', 'gatewayCustomerId'];
    }

    protected static function defineActions(string $source = null): array
    {
        return [
            Delete::class,
            Restore::class,
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'user':
                $user = $this->getUser();
                return $user ? Craft::$app->getView()->renderTemplate('_elements/element', ['element' => $user]) : '';

            case 'plan':
                $plan = $this->getPlan();
                return $plan ? $plan->name : '';

            case 'status':
                $statuses = static::statuses();
                $statusInfo = $statuses[$this->status] ?? null;
                if ($statusInfo) {
                    return '<span class="headcount-status headcount-status--' . $this->status . '">' . $statusInfo['label'] . '</span>';
                }
                return $this->status;

            case 'gateway':
                return ucfirst($this->gateway);

            case 'amount':
                return strtoupper($this->currency) . ' ' . number_format($this->amount, 2);

            case 'startDate':
                return $this->startDate ? Craft::$app->getFormatter()->asDate($this->startDate) : '';

            case 'endDate':
                return $this->endDate ? Craft::$app->getFormatter()->asDate($this->endDate) : '';
        }

        return parent::attributeHtml($attribute);
    }

    public function getUser(): ?User
    {
        if ($this->_user !== null) {
            return $this->_user;
        }

        if ($this->userId) {
            $this->_user = Craft::$app->getUsers()->getUserById($this->userId);
        }

        return $this->_user;
    }

    public function getPlan(): ?\justinholtweb\headcount\models\Plan
    {
        if ($this->_plan !== null) {
            return $this->_plan;
        }

        if ($this->planId) {
            $this->_plan = Headcount::getInstance()->plans->getPlanById($this->planId);
        }

        return $this->_plan;
    }

    public function setPlan(?\justinholtweb\headcount\models\Plan $plan): void
    {
        $this->_plan = $plan;
        $this->planId = $plan?->id;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('headcount/subscriptions/' . $this->id);
    }

    public function canView(User $user): bool
    {
        return $user->can('headcount-manageSubscriptions');
    }

    public function canSave(User $user): bool
    {
        return $user->can('headcount-manageSubscriptions');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('headcount-manageSubscriptions');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIALING]);
    }

    public function afterSave(bool $isNew): void
    {
        if ($isNew) {
            Craft::$app->db->createCommand()
                ->insert('{{%headcount_subscriptions}}', [
                    'id' => $this->id,
                    'userId' => $this->userId,
                    'planId' => $this->planId,
                    'gateway' => $this->gateway,
                    'gatewaySubscriptionId' => $this->gatewaySubscriptionId,
                    'gatewayCustomerId' => $this->gatewayCustomerId,
                    'status' => $this->status,
                    'trialStartDate' => Db::prepareDateForDb($this->trialStartDate),
                    'trialEndDate' => Db::prepareDateForDb($this->trialEndDate),
                    'startDate' => Db::prepareDateForDb($this->startDate),
                    'endDate' => Db::prepareDateForDb($this->endDate),
                    'canceledAt' => Db::prepareDateForDb($this->canceledAt),
                    'cancelAtPeriodEnd' => $this->cancelAtPeriodEnd,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                    'metadata' => $this->metadata ? json_encode($this->metadata) : null,
                ])
                ->execute();
        } else {
            Craft::$app->db->createCommand()
                ->update('{{%headcount_subscriptions}}', [
                    'userId' => $this->userId,
                    'planId' => $this->planId,
                    'gateway' => $this->gateway,
                    'gatewaySubscriptionId' => $this->gatewaySubscriptionId,
                    'gatewayCustomerId' => $this->gatewayCustomerId,
                    'status' => $this->status,
                    'trialStartDate' => Db::prepareDateForDb($this->trialStartDate),
                    'trialEndDate' => Db::prepareDateForDb($this->trialEndDate),
                    'startDate' => Db::prepareDateForDb($this->startDate),
                    'endDate' => Db::prepareDateForDb($this->endDate),
                    'canceledAt' => Db::prepareDateForDb($this->canceledAt),
                    'cancelAtPeriodEnd' => $this->cancelAtPeriodEnd,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                    'metadata' => $this->metadata ? json_encode($this->metadata) : null,
                ], ['id' => $this->id])
                ->execute();
        }

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        Craft::$app->db->createCommand()
            ->delete('{{%headcount_subscriptions}}', ['id' => $this->id])
            ->execute();

        parent::afterDelete();
    }

    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['userId', 'planId'], 'integer'];
        $rules[] = [['gateway'], 'in', 'range' => ['stripe', 'paypal', 'manual']];
        $rules[] = [['status'], 'in', 'range' => [
            self::STATUS_ACTIVE,
            self::STATUS_TRIALING,
            self::STATUS_PAST_DUE,
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
            self::STATUS_PAUSED,
        ]];
        $rules[] = [['amount'], 'number', 'min' => 0];
        $rules[] = [['currency'], 'string', 'max' => 3];
        $rules[] = [['cancelAtPeriodEnd'], 'boolean'];

        return $rules;
    }
}
