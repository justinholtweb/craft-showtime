<?php

namespace justinholtweb\headcount\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use justinholtweb\headcount\events\MatchGateRuleEvent;
use justinholtweb\headcount\events\RegisterGateTargetsEvent;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\helpers\Json;
use justinholtweb\headcount\models\AccessRule;
use justinholtweb\headcount\models\GateTarget;
use justinholtweb\headcount\records\AccessRuleRecord;
use yii\base\Component;
use yii\web\NotFoundHttpException;

/**
 * Membership gating: which content requires which plan, and what happens when it doesn't
 * have it.
 *
 * Gating is **not entry-only**. A rule names an element *type* and a *scope* within it, and
 * anything can register itself as gateable through `EVENT_REGISTER_GATE_TARGETS` — that's
 * how the Showtime bundle gates Owl events without Headcount knowing what an event is.
 */
class Gating extends Component
{
    /**
     * @event RegisterGateTargetsEvent Declares the element types access rules can gate.
     */
    public const EVENT_REGISTER_GATE_TARGETS = 'registerGateTargets';

    /**
     * @event MatchGateRuleEvent Asks whether a rule with a foreign scope covers an element.
     */
    public const EVENT_MATCH_GATE_RULE = 'matchGateRule';

    /**
     * The result of the gate applied to the element currently being requested, if any.
     *
     * Set by {@see enforce()} for the `paywall` behavior, where the page is allowed to
     * render and the template decides what to show. Read it via
     * `craft.headcount.gatingResult`.
     */
    public ?array $currentResult = null;

    private ?array $_rules = null;

    /** @var GateTarget[]|null keyed by element type */
    private ?array $_targets = null;

    /**
     * Whether a user may see this element, taking gating and drip into account.
     *
     * The generalised entry point named in the bundle plan: works for any element type,
     * defaults to the logged-in user, and treats "no rule" as unrestricted.
     */
    public function canAccess(ElementInterface $element, ?User $user = null): bool
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        $result = $this->evaluateAccess($element, $user);

        return $result === null || $result['allowed'];
    }

    /**
     * Evaluate the gate on an element.
     *
     * @return array|null null when no rule applies (unrestricted); otherwise
     *                    `['allowed' => bool, 'behavior' => …, 'redirectUrl' => …,
     *                      'teaserLength' => …, 'rule' => AccessRule, 'reason' => …]`
     */
    public function evaluateAccess(ElementInterface $element, ?User $user): ?array
    {
        $rule = $this->getMatchingRule($element);

        if (!$rule || !$rule->enabled) {
            return null; // No rule = unrestricted
        }

        // Drip schedules are written against entries; nothing else can be dripped yet.
        if ($element instanceof Entry) {
            $dripResult = Headcount::getInstance()->drip->isUnlocked($element, $user);
            if ($dripResult === false) {
                return [
                    'allowed' => false,
                    'behavior' => $rule->behavior,
                    'redirectUrl' => $rule->redirectUrl,
                    'teaserLength' => $rule->teaserLength,
                    'rule' => $rule,
                    'reason' => 'drip',
                ];
            }
        }

        // Check if user has an active subscription to any of the required plans
        if ($user) {
            $planIds = $rule->planIds ?? [];
            if (!empty($planIds)) {
                $activeSubscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);
                foreach ($activeSubscriptions as $subscription) {
                    if (in_array($subscription->planId, $planIds)) {
                        return ['allowed' => true];
                    }
                }
            }

            // Also check user group membership (plans may have mapped user groups)
            foreach ($planIds as $planId) {
                $plan = Headcount::getInstance()->plans->getPlanById($planId);
                if ($plan && $plan->userGroupId && $user->isInGroup($plan->userGroupId)) {
                    return ['allowed' => true];
                }
            }
        }

        // User doesn't have access
        $settings = Headcount::getInstance()->getSettings();

        return [
            'allowed' => false,
            'behavior' => $rule->behavior,
            'redirectUrl' => $rule->redirectUrl ?: $settings->loginUrl,
            'teaserLength' => $rule->teaserLength,
            'rule' => $rule,
            'reason' => 'subscription_required',
        ];
    }

    /**
     * Apply the gate to the element a site request resolved to.
     *
     * Craft never calls `canView()` while routing a front-end request, so a rule that isn't
     * enforced here is only enforced by templates that remember to ask — which is no
     * enforcement at all. Called from Headcount's `beforeAction` listener.
     *
     * @return string|null a URL to redirect to, or null to let the request continue
     * @throws NotFoundHttpException when the rule's behavior is `hide`
     */
    public function enforce(ElementInterface $element, ?User $user): ?string
    {
        $result = $this->evaluateAccess($element, $user);

        if ($result === null || $result['allowed']) {
            return null;
        }

        switch ($result['behavior']) {
            case 'hide':
                throw new NotFoundHttpException();

            case 'paywall':
                // The page renders; the template branches on craft.headcount.gatingResult
                // and shows a teaser. Nothing is withheld automatically — a template that
                // ignores the result shows the whole thing.
                $this->currentResult = $result;
                return null;

            default:
                $url = $result['redirectUrl'] ?: UrlHelper::siteUrl();
                return UrlHelper::isFullUrl($url) ? $url : UrlHelper::siteUrl($url);
        }
    }

    /**
     * The element types that can be gated, keyed by class name.
     *
     * @return GateTarget[]
     */
    public function getGateTargets(): array
    {
        if ($this->_targets === null) {
            $event = new RegisterGateTargetsEvent([
                'targets' => [Entry::class => $this->_entryTarget()],
            ]);

            $this->trigger(self::EVENT_REGISTER_GATE_TARGETS, $event);

            $this->_targets = $event->targets;
        }

        return $this->_targets;
    }

    public function getGateTarget(string $elementType): ?GateTarget
    {
        return $this->getGateTargets()[$elementType] ?? null;
    }

    /**
     * The first enabled rule covering this element, in sort order.
     */
    public function getMatchingRule(ElementInterface $element): ?AccessRule
    {
        foreach ($this->getRulesForElement($element) as $rule) {
            return $rule;
        }

        return null;
    }

    /**
     * Every enabled rule covering this element, in sort order.
     *
     * @return AccessRule[]
     */
    public function getRulesForElement(ElementInterface $element): array
    {
        $matching = [];

        foreach ($this->getAllRules(true) as $rule) {
            if (!$element instanceof $rule->elementType) {
                continue;
            }

            if ($this->_ruleCovers($rule, $element)) {
                $matching[] = $rule;
            }
        }

        return $matching;
    }

    /**
     * @deprecated in 5.2.0. Use {@see getRulesForElement()}, which accepts any element.
     * @return AccessRule[]
     */
    public function getRulesForEntry(Entry $entry): array
    {
        return $this->getRulesForElement($entry);
    }

    public function getAllRules(bool $enabledOnly = false): array
    {
        if ($this->_rules === null) {
            $this->_loadRules();
        }

        if ($enabledOnly) {
            return array_filter($this->_rules, fn(AccessRule $rule) => $rule->enabled);
        }

        return $this->_rules;
    }

    public function getRuleById(int $id): ?AccessRule
    {
        $row = (new Query())
            ->select('*')
            ->from('{{%headcount_access_rules}}')
            ->where(['id' => $id])
            ->one();

        return $row ? $this->_createRuleFromRow($row) : null;
    }

    public function saveRule(AccessRule $rule): bool
    {
        if (!$rule->validate()) {
            return false;
        }

        $isNew = !$rule->id;

        if ($isNew) {
            $record = new AccessRuleRecord();
        } else {
            $record = AccessRuleRecord::findOne($rule->id);
            if (!$record) {
                return false;
            }
        }

        $record->name = $rule->name;
        $record->elementType = $rule->elementType;
        $record->type = $rule->type;
        $record->targetId = $rule->targetId;
        $record->targetUid = $rule->targetUid;
        $record->planIds = $rule->planIds ? json_encode($rule->planIds) : null;
        $record->behavior = $rule->behavior;
        $record->redirectUrl = $rule->redirectUrl;
        $record->teaserLength = $rule->teaserLength;
        $record->sortOrder = $rule->sortOrder;
        $record->enabled = $rule->enabled;

        if (!$record->save()) {
            $rule->addErrors($record->getErrors());
            return false;
        }

        if ($isNew) {
            $rule->id = $record->id;
        }

        $this->_rules = null;
        return true;
    }

    public function deleteRuleById(int $id): bool
    {
        $record = AccessRuleRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->delete();
        $this->_rules = null;

        return true;
    }

    /**
     * Craft entries, gateable by section, entry type, relation, or one at a time.
     */
    private function _entryTarget(): GateTarget
    {
        $sectionOptions = [];
        $entryTypeOptions = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sectionOptions[] = ['label' => $section->name, 'value' => $section->id];

            foreach ($section->getEntryTypes() as $entryType) {
                $entryTypeOptions[] = [
                    'label' => $section->name . ' — ' . $entryType->name,
                    'value' => $entryType->id,
                ];
            }
        }

        return new GateTarget([
            'elementType' => Entry::class,
            'label' => Craft::t('headcount', 'Entries'),
            'scopes' => [
                GateTarget::SCOPE_ALL => [
                    'label' => Craft::t('headcount', 'All entries'),
                    'target' => 'none',
                ],
                'section' => [
                    'label' => Craft::t('headcount', 'A section'),
                    'target' => 'options',
                    'options' => $sectionOptions,
                ],
                'entryType' => [
                    'label' => Craft::t('headcount', 'An entry type'),
                    'target' => 'options',
                    'options' => $entryTypeOptions,
                ],
                GateTarget::SCOPE_ELEMENT => [
                    'label' => Craft::t('headcount', 'One specific entry'),
                    'target' => 'element',
                ],
                'category' => [
                    'label' => Craft::t('headcount', 'Entries related to a category'),
                    'target' => 'element',
                    'selectElementType' => Category::class,
                ],
            ],
        ]);
    }

    /**
     * Whether one rule covers one element. The element type is already known to match.
     */
    private function _ruleCovers(AccessRule $rule, ElementInterface $element): bool
    {
        switch ($rule->type) {
            case GateTarget::SCOPE_ALL:
                return true;

            case GateTarget::SCOPE_ELEMENT:
                // Canonical ID, so a draft or revision of gated content is gated too.
                return $rule->targetId !== null && $rule->targetId === $element->getCanonicalId();

            case 'entryType':
                return $element instanceof Entry && $element->typeId === $rule->targetId;

            case 'section':
                return $element instanceof Entry && $element->sectionId === $rule->targetId;

            case 'category':
                return $element instanceof Entry && $this->_isRelatedTo($element, $rule->targetId);
        }

        // A scope Headcount doesn't define belongs to whoever registered the target. It
        // answers or the rule doesn't apply — an unclaimed scope must never gate anything,
        // since "matches nothing" is recoverable and "matches everything" takes a site down.
        $event = new MatchGateRuleEvent(['rule' => $rule, 'element' => $element]);
        $this->trigger(self::EVENT_MATCH_GATE_RULE, $event);

        return $event->matches === true;
    }

    /**
     * Whether the entry relates to a given element through one of its relational fields.
     */
    private function _isRelatedTo(Entry $entry, ?int $targetId): bool
    {
        if ($targetId === null) {
            return false;
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof \craft\fields\Categories && !$field instanceof \craft\fields\Entries) {
                continue;
            }

            $related = $entry->getFieldValue($field->handle);
            if (!$related) {
                continue;
            }

            foreach ($related->all() as $relatedElement) {
                if ($relatedElement->id === $targetId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function _loadRules(): void
    {
        $this->_rules = [];

        $rows = (new Query())
            ->select('*')
            ->from('{{%headcount_access_rules}}')
            ->orderBy('sortOrder')
            ->all();

        foreach ($rows as $row) {
            $this->_rules[] = $this->_createRuleFromRow($row);
        }
    }

    private function _createRuleFromRow(array $row): AccessRule
    {
        $rule = new AccessRule();
        $rule->id = (int)$row['id'];
        $rule->name = $row['name'];
        $rule->elementType = $row['elementType'] ?: Entry::class;
        $rule->type = $row['type'];
        $rule->targetId = $row['targetId'] ? (int)$row['targetId'] : null;
        $rule->targetUid = $row['targetUid'];
        $rule->planIds = Json::decodeColumn($row['planIds']);
        $rule->behavior = $row['behavior'];
        $rule->redirectUrl = $row['redirectUrl'];
        $rule->teaserLength = $row['teaserLength'] ? (int)$row['teaserLength'] : null;
        $rule->sortOrder = (int)$row['sortOrder'];
        $rule->enabled = (bool)$row['enabled'];
        $rule->dateCreated = $row['dateCreated'];
        $rule->dateUpdated = $row['dateUpdated'];
        $rule->uid = $row['uid'];

        return $rule;
    }
}
