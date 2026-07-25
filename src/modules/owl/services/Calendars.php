<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use Craft;
use craft\base\Component;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\records\CalendarRecord;
use Throwable;

/**
 * Calendar CRUD, backed by Project Config so calendar definitions (and their field layouts) version
 * with the rest of the project and propagate across environments.
 */
class Calendars extends Component
{
    public const CONFIG_CALENDARS_KEY = 'owl.calendars';

    /** @var Calendar[]|null */
    private ?array $_calendars = null;

    /**
     * @return Calendar[]
     */
    public function getAllCalendars(): array
    {
        if ($this->_calendars === null) {
            $this->_calendars = [];
            /** @var CalendarRecord[] $records */
            $records = CalendarRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all();
            foreach ($records as $record) {
                $this->_calendars[] = $this->createCalendarFromRecord($record);
            }
        }

        return $this->_calendars;
    }

    public function getCalendarById(int $id): ?Calendar
    {
        foreach ($this->getAllCalendars() as $calendar) {
            if ($calendar->id === $id) {
                return $calendar;
            }
        }

        return null;
    }

    public function getCalendarByHandle(string $handle): ?Calendar
    {
        foreach ($this->getAllCalendars() as $calendar) {
            if ($calendar->handle === $handle) {
                return $calendar;
            }
        }

        return null;
    }

    /**
     * Clears the in-memory calendar cache (call after creating/updating calendars mid-request).
     */
    public function refresh(): void
    {
        $this->_calendars = null;
    }

    /**
     * Saves a calendar to Project Config. The record + field layout are written by the change
     * handler ({@see handleChangedCalendar}).
     */
    public function save(Calendar $calendar, bool $runValidation = true): bool
    {
        $isNew = $calendar->id === null;

        if ($runValidation && !$calendar->validate()) {
            return false;
        }

        if ($isNew) {
            $calendar->uid = StringHelper::UUID();
        } elseif (!$calendar->uid) {
            $calendar->uid = Db::uidById('{{%owl_calendars}}', $calendar->id);
        }

        $configPath = self::CONFIG_CALENDARS_KEY . '.' . $calendar->uid;
        Craft::$app->getProjectConfig()->set($configPath, $calendar->getConfig());

        if ($isNew) {
            $calendar->id = Db::idByUid('{{%owl_calendars}}', $calendar->uid);
        }

        $this->refresh();

        return true;
    }

    public function deleteCalendarById(int $id): bool
    {
        $calendar = $this->getCalendarById($id);

        if ($calendar === null) {
            return true;
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_CALENDARS_KEY . '.' . $calendar->uid);
        $this->refresh();

        return true;
    }

    /**
     * Project Config: a calendar was added or updated — sync the record and its field layout.
     */
    public function handleChangedCalendar(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            $record = CalendarRecord::findOne(['uid' => $uid]) ?? new CalendarRecord();
            $record->uid = $uid;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->color = $data['color'] ?? null;
            $record->hasTickets = (bool)($data['hasTickets'] ?? false);
            $record->uriFormat = $data['uriFormat'] ?? null;
            $record->template = $data['template'] ?? null;
            $record->sortOrder = $data['sortOrder'] ?? null;

            if (!empty($data['fieldLayouts']) && !empty($config = reset($data['fieldLayouts']))) {
                $layout = FieldLayout::createFromConfig($config);
                $layout->id = $record->fieldLayoutId;
                $layout->type = Event::class;
                $layout->uid = key($data['fieldLayouts']);
                Craft::$app->getFields()->saveLayout($layout, false);
                $record->fieldLayoutId = $layout->id;
            } elseif ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->refresh();
    }

    /**
     * Project Config: a calendar was removed — delete its events, field layout, and record.
     */
    public function handleDeletedCalendar(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $record = CalendarRecord::findOne(['uid' => $uid]);

        if ($record === null) {
            return;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();
        try {
            foreach (Event::find()->calendarId($record->id)->status(null)->all() as $event2) {
                Craft::$app->getElements()->deleteElement($event2, true);
            }

            if ($record->fieldLayoutId !== null) {
                Craft::$app->getFields()->deleteLayoutById((int)$record->fieldLayoutId);
            }

            $record->delete();
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->refresh();
    }

    /**
     * Project Config rebuild: contribute every calendar's config.
     *
     * @return array<string,mixed>
     */
    public function rebuildProjectConfig(): array
    {
        $config = [];
        foreach ($this->getAllCalendars() as $calendar) {
            if ($calendar->uid !== null) {
                $config[$calendar->uid] = $calendar->getConfig();
            }
        }

        return $config;
    }

    private function createCalendarFromRecord(CalendarRecord $record): Calendar
    {
        return new Calendar([
            'id' => (int)$record->id,
            'name' => $record->name,
            'handle' => $record->handle,
            'color' => $record->color,
            'fieldLayoutId' => $record->fieldLayoutId !== null ? (int)$record->fieldLayoutId : null,
            'hasTickets' => (bool)$record->hasTickets,
            'uriFormat' => $record->uriFormat,
            'template' => $record->template,
            'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
            'uid' => $record->uid,
        ]);
    }
}
