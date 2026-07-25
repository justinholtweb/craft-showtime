<?php

namespace justinholtweb\headcount\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use justinholtweb\headcount\Headcount;

/**
 * One access rule: "content matching X requires an active subscription to one of Y".
 *
 * Two dimensions, and they're easy to confuse:
 *   - **elementType** — what *kind* of thing this rule is about (entries, Owl events, …).
 *   - **type** — which ones of that kind (all of them, one by ID, everything in a section).
 *
 * `elementType` defaults to Entry so every rule written before gating was generalised keeps
 * meaning exactly what it meant.
 */
class AccessRule extends Model
{
    public ?int $id = null;
    public string $name = '';

    /** @var class-string<\craft\base\ElementInterface> */
    public string $elementType = Entry::class;

    /**
     * The scope within the element type — a key of the registered
     * {@see GateTarget::$scopes} for `$elementType`.
     */
    public string $type = 'section';

    public ?int $targetId = null;
    public ?string $targetUid = null;
    public ?array $planIds = null;
    public string $behavior = 'redirect';
    public ?string $redirectUrl = null;
    public ?int $teaserLength = null;
    public int $sortOrder = 0;
    public bool $enabled = true;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['name', 'elementType', 'type', 'behavior'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['elementType'], 'string', 'max' => 255],
            [['type'], 'string', 'max' => 64],
            [['behavior'], 'in', 'range' => ['redirect', 'paywall', 'hide']],
            [['targetId', 'teaserLength', 'sortOrder'], 'integer'],
            [['redirectUrl'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
            [['type'], 'validateScope'],
        ];
    }

    /**
     * The scope has to be one the element type actually offers, and one that has a target
     * chosen when it needs one. Without this a rule can be saved that silently never
     * matches — the worst failure mode for an access rule, since it reads as "protected".
     */
    public function validateScope(string $attribute): void
    {
        $target = Headcount::getInstance()->gating->getGateTarget($this->elementType);

        if ($target === null) {
            $this->addError('elementType', Craft::t('headcount', 'Nothing is registered to gate that element type.'));
            return;
        }

        if (!$target->hasScope($this->type)) {
            $this->addError($attribute, Craft::t('headcount', 'That isn\'t a scope {label} supports.', [
                'label' => $target->label,
            ]));
            return;
        }

        if ($target->scopeNeedsTarget($this->type) && !$this->targetId) {
            $this->addError('targetId', Craft::t('headcount', 'Choose what this rule applies to.'));
        }
    }

    /**
     * Human-readable "Entries · All entries", for rule listings.
     */
    public function getScopeDescription(): string
    {
        $target = Headcount::getInstance()->gating->getGateTarget($this->elementType);

        if ($target === null) {
            return Craft::t('headcount', 'Unavailable ({type})', ['type' => $this->elementType]);
        }

        return $target->label . ' · ' . ($target->scopes[$this->type]['label'] ?? $this->type);
    }
}
