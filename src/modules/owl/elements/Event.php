<?php

declare(strict_types=1);

namespace justinholtweb\owl\elements;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\owl\elements\db\EventQuery;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\EventRecord;

/**
 * Event element.
 *
 * Scheduling data (calendar, start/end, timezone, RRULE) lives on the shared `owl_events` table
 * keyed by the canonical element id — it is not per-site, because an event occurs at one instant
 * regardless of content language. Only the title/slug/custom-field content varies per site.
 */
class Event extends Element
{
    public ?int $calendarId = null;
    public ?DateTime $startDate = null;
    public ?DateTime $endDate = null;
    public bool $allDay = false;
    public string $timezone = 'UTC';
    public ?string $rrule = null;
    public bool $repeating = false;

    private ?Calendar $_calendar = null;

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['calendarId', 'startDate', 'endDate', 'timezone'], 'required'],
            [['calendarId'], 'integer'],
            [['timezone'], 'string'],
            [['allDay'], 'boolean'],
            [['endDate'], 'validateEndDate'],
        ]);
    }

    /**
     * An event may not end before it starts.
     */
    public function validateEndDate(): void
    {
        if ($this->startDate !== null && $this->endDate !== null && $this->endDate < $this->startDate) {
            $this->addError('endDate', Craft::t('owl', 'The end date cannot be earlier than the start date.'));
        }
    }

    public static function displayName(): string
    {
        return Craft::t('owl', 'Event');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('owl', 'event');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('owl', 'Events');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('owl', 'events');
    }

    public static function refHandle(): ?string
    {
        return 'event';
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        return 'OwlEvent';
    }

    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext($this);
    }

    public static function gqlScopesByContext(mixed $context): array
    {
        return ['owl.events'];
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): EventQuery
    {
        return new EventQuery(static::class);
    }

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('owl', 'All events'),
                'criteria' => [],
            ],
        ];

        foreach (Owl::getInstance()->calendars->getAllCalendars() as $calendar) {
            $sources[] = [
                'key' => "calendar:{$calendar->uid}",
                'label' => $calendar->name,
                'criteria' => ['calendarId' => $calendar->id],
                'data' => ['handle' => $calendar->handle],
            ];
        }

        return $sources;
    }

    protected static function defineSortOptions(): array
    {
        return [
            'startDate' => Craft::t('owl', 'Start Date'),
            'endDate' => Craft::t('owl', 'End Date'),
            'title' => Craft::t('app', 'Title'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'calendar' => Craft::t('owl', 'Calendar'),
            'startDate' => Craft::t('owl', 'Start Date'),
            'endDate' => Craft::t('owl', 'End Date'),
            'repeating' => Craft::t('owl', 'Repeating'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['calendar', 'startDate', 'endDate', 'repeating'];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'calendar' => $this->getCalendar()?->name ?? '—',
            'repeating' => $this->repeating
                ? '<span data-icon="refresh" title="' . Craft::t('owl', 'Repeating') . '"></span>'
                : '—',
            default => parent::attributeHtml($attribute),
        };
    }

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['startDate', 'endDate']);
    }

    public function getCalendar(): ?Calendar
    {
        if ($this->_calendar === null && $this->calendarId !== null) {
            $this->_calendar = Owl::getInstance()->calendars->getCalendarById($this->calendarId);
        }

        return $this->_calendar;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->getCalendar()?->getFieldLayout();
    }

    public function getSupportedSites(): array
    {
        // Events propagate to every site; per-site rows carry only title/slug/content, while the
        // shared scheduling row stays singular. Scoping to calendar-enabled sites lands in Phase 5.
        return Craft::$app->getSites()->getAllSiteIds();
    }

    public function getUriFormat(): ?string
    {
        $calendar = $this->getCalendar();

        return $calendar !== null && $calendar->hasUrls() ? $calendar->uriFormat : null;
    }

    protected function route(): array|string|null
    {
        $calendar = $this->getCalendar();

        if ($calendar === null || !$calendar->hasUrls() || $this->getStatus() !== self::STATUS_ENABLED) {
            return null;
        }

        return [
            'templates/render',
            [
                'template' => (string)$calendar->template,
                'variables' => ['event' => $this],
            ],
        ];
    }

    protected function previewTargets(): array
    {
        return $this->getUriFormat() !== null
            ? [['label' => Craft::t('app', 'Primary {type} page', ['type' => self::lowerDisplayName()]), 'url' => '{url}']]
            : [];
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("owl/events/{$this->id}");
    }

    public function canView(User $user): bool
    {
        return $user->can('owl-manageEvents') || parent::canView($user);
    }

    public function canSave(User $user): bool
    {
        return $user->can('owl-manageEvents') || parent::canSave($user);
    }

    public function canDelete(User $user): bool
    {
        return $user->can('owl-manageEvents') || parent::canDelete($user);
    }

    public function canCreateDrafts(User $user): bool
    {
        return true;
    }

    public function afterSave(bool $isNew): void
    {
        // Only persist scheduling data and regenerate occurrences on the canonical, non-draft pass —
        // the shared table must not be written once per site or per provisional draft.
        if (!$this->propagating && !$this->getIsDraft() && !$this->getIsRevision()) {
            $record = (!$isNew ? EventRecord::findOne($this->id) : null) ?? new EventRecord();
            $record->id = (int)$this->id;
            $record->calendarId = (int)$this->calendarId;
            $record->startDate = Db::prepareDateForDb($this->startDate);
            $record->endDate = Db::prepareDateForDb($this->endDate);
            $record->allDay = $this->allDay;
            $record->timezone = $this->timezone;
            $record->rrule = $this->rrule;
            $this->repeating = $this->rrule !== null && trim($this->rrule) !== '';
            $record->repeating = $this->repeating;
            $record->save(false);

            Owl::getInstance()->occurrences->regenerate($this);
        }

        parent::afterSave($isNew);
    }
}
