<?php

declare(strict_types=1);

namespace justinholtweb\owl\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use justinholtweb\owl\Owl;

/**
 * Query for {@see \justinholtweb\owl\elements\Event} elements.
 *
 * This is the element-native query (one row per event). For an expanded, paginatable stream of
 * individual occurrences (e.g. feeding FullCalendar), use the Occurrences service, which reads the
 * materialised `owl_occurrences` table.
 */
class EventQuery extends ElementQuery
{
    public mixed $calendarId = null;
    public mixed $startsAfter = null;
    public mixed $startsBefore = null;
    public mixed $endsAfter = null;
    public mixed $endsBefore = null;
    public ?bool $allDay = null;
    public ?bool $repeating = null;

    protected array $defaultOrderBy = ['owl_events.startDate' => SORT_ASC];

    /**
     * Filter by calendar handle(s) or {@see \justinholtweb\owl\models\Calendar} id(s). Accepts a
     * single handle, a list of handles, or numeric ids (mixed lists are fine).
     */
    public function calendar(mixed $value): static
    {
        if ($value === null) {
            $this->calendarId = null;
            return $this;
        }

        $ids = [];
        foreach ((is_array($value) ? $value : [$value]) as $item) {
            if (is_numeric($item)) {
                $ids[] = (int)$item;
            } else {
                $calendar = Owl::getInstance()->calendars->getCalendarByHandle((string)$item);
                if ($calendar !== null) {
                    $ids[] = $calendar->id;
                }
            }
        }

        // `false` ensures a non-matching handle yields no results rather than all results.
        $this->calendarId = $ids !== [] ? $ids : false;

        return $this;
    }

    public function calendarId(mixed $value): static
    {
        $this->calendarId = $value;
        return $this;
    }

    /**
     * Events whose start is at/after the given date — the common "upcoming" filter.
     */
    public function startsAfter(mixed $value): static
    {
        $this->startsAfter = $value;
        return $this;
    }

    public function startsBefore(mixed $value): static
    {
        $this->startsBefore = $value;
        return $this;
    }

    public function endsAfter(mixed $value): static
    {
        $this->endsAfter = $value;
        return $this;
    }

    public function endsBefore(mixed $value): static
    {
        $this->endsBefore = $value;
        return $this;
    }

    public function allDay(?bool $value = true): static
    {
        $this->allDay = $value;
        return $this;
    }

    public function repeating(?bool $value = true): static
    {
        $this->repeating = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('owl_events');

        $this->query->select([
            'owl_events.calendarId',
            'owl_events.startDate',
            'owl_events.endDate',
            'owl_events.allDay',
            'owl_events.timezone',
            'owl_events.rrule',
            'owl_events.repeating',
        ]);

        if ($this->calendarId !== null) {
            $this->subQuery->andWhere(Db::parseParam('owl_events.calendarId', $this->calendarId));
        }

        if ($this->startsAfter !== null) {
            $this->subQuery->andWhere(['>=', 'owl_events.startDate', Db::prepareDateForDb($this->startsAfter)]);
        }

        if ($this->startsBefore !== null) {
            $this->subQuery->andWhere(['<', 'owl_events.startDate', Db::prepareDateForDb($this->startsBefore)]);
        }

        if ($this->endsAfter !== null) {
            $this->subQuery->andWhere(['>=', 'owl_events.endDate', Db::prepareDateForDb($this->endsAfter)]);
        }

        if ($this->endsBefore !== null) {
            $this->subQuery->andWhere(['<', 'owl_events.endDate', Db::prepareDateForDb($this->endsBefore)]);
        }

        if ($this->allDay !== null) {
            $this->subQuery->andWhere(['owl_events.allDay' => $this->allDay]);
        }

        if ($this->repeating !== null) {
            $this->subQuery->andWhere(['owl_events.repeating' => $this->repeating]);
        }

        return parent::beforePrepare();
    }
}
