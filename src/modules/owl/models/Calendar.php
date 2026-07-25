<?php

declare(strict_types=1);

namespace justinholtweb\owl\models;

use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\records\CalendarRecord;

/**
 * A calendar groups events, owns a field layout, a colour, a (Pro) ticketing toggle, and the
 * front-end URL settings (URI format + template) used to render its events.
 *
 * @mixin FieldLayoutBehavior
 */
class Calendar extends Model
{
    public ?int $id = null;
    public ?int $fieldLayoutId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $color = null;
    public bool $hasTickets = false;
    public ?string $uriFormat = null;
    public ?string $template = null;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Event::class,
            ],
        ];
    }

    public function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle', 'color', 'uriFormat', 'template'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            [
                ['handle'],
                UniqueValidator::class,
                'targetClass' => CalendarRecord::class,
                'filter' => function($query): void {
                    if ($this->id !== null) {
                        $query->andWhere(['not', ['id' => $this->id]]);
                    }
                },
            ],
            // A URI format requires a template to render it (and vice versa).
            [['template'], 'required', 'when' => fn(self $c): bool => (string)$c->uriFormat !== '', 'message' => 'A template is required when a URI format is set.'],
            [['uriFormat'], 'required', 'when' => fn(self $c): bool => (string)$c->template !== '', 'message' => 'A URI format is required when a template is set.'],
        ];
    }

    public function getFieldLayout(): ?FieldLayout
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');

        return $behavior->getFieldLayout();
    }

    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl("owl/calendars/{$this->id}");
    }

    /**
     * Whether events in this calendar have front-end URLs.
     */
    public function hasUrls(): bool
    {
        return (string)$this->uriFormat !== '' && (string)$this->template !== '';
    }

    /**
     * The Project Config representation of this calendar (including its field layout).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        $config = [
            'name' => $this->name,
            'handle' => $this->handle,
            'color' => $this->color,
            'hasTickets' => $this->hasTickets,
            'uriFormat' => $this->uriFormat ?: null,
            'template' => $this->template ?: null,
            'sortOrder' => $this->sortOrder,
        ];

        $fieldLayout = $this->getFieldLayout();
        $fieldLayoutConfig = $fieldLayout?->getConfig();

        if ($fieldLayout !== null && $fieldLayoutConfig !== null) {
            if (!$fieldLayout->uid) {
                $fieldLayout->uid = StringHelper::UUID();
            }
            $config['fieldLayouts'] = [$fieldLayout->uid => $fieldLayoutConfig];
        }

        return $config;
    }
}
