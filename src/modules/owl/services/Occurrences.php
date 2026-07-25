<?php

declare(strict_types=1);

namespace justinholtweb\owl\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\CalendarRecord;
use justinholtweb\owl\records\EventRecord;
use justinholtweb\owl\records\OccurrenceRecord;

/**
 * Materialises and queries event occurrences.
 *
 * Occurrences are generated from an event's recurrence rule up to a rolling horizon and stored as
 * plain rows, so calendar range queries and pagination are fast, indexed SQL.
 */
class Occurrences extends Component
{
    /**
     * Rebuild the occurrence rows for an event (called after save). Synchronous for simple events;
     * heavy recurring events should be pushed to {@see \justinholtweb\owl\jobs\GenerateOccurrencesJob}.
     */
    public function regenerate(Event $event): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $transaction = $db->beginTransaction();
        try {
            OccurrenceRecord::deleteAll(['eventId' => $event->id]);

            $occurrences = Owl::getInstance()->recurrence->expand(
                $event,
                $this->windowStart($event),
                $this->horizonEnd(),
            );

            if ($occurrences !== []) {
                $rows = [];
                foreach ($occurrences as $occurrence) {
                    $rows[] = [
                        $event->id,
                        $occurrence->start->format('Y-m-d H:i:s'),
                        $occurrence->end->format('Y-m-d H:i:s'),
                        $occurrence->allDay,
                        false,
                        false,
                        null,
                        $now,
                        $now,
                        StringHelper::UUID(),
                    ];
                }

                $db->createCommand()->batchInsert(
                    OccurrenceRecord::tableName(),
                    ['eventId', 'startDate', 'endDate', 'allDay', 'isException', 'isOverride', 'overrideData', 'dateCreated', 'dateUpdated', 'uid'],
                    $rows,
                )->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Returns enabled, live occurrences overlapping a date window, joined to their event and
     * calendar. Drives the front-end calendar JSON feed and ICS export.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getOccurrencesInRange(
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        ?int $siteId = null,
        ?array $calendarIds = null,
        ?int $eventId = null,
        ?int $limit = null,
    ): array {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        $query = (new Query())
            ->select([
                'eventId' => 'o.eventId',
                'startDate' => 'o.startDate',
                'endDate' => 'o.endDate',
                'allDay' => 'o.allDay',
                'timezone' => 'ev.timezone',
                'calendarId' => 'ev.calendarId',
                'title' => 'es.title',
                'uri' => 'es.uri',
                'color' => 'cal.color',
                'calendarHandle' => 'cal.handle',
            ])
            ->from(['o' => OccurrenceRecord::tableName()])
            ->innerJoin(['ev' => EventRecord::tableName()], '[[ev.id]] = [[o.eventId]]')
            ->innerJoin(['el' => Table::ELEMENTS], '[[el.id]] = [[ev.id]]')
            ->innerJoin(
                ['es' => Table::ELEMENTS_SITES],
                ['and', '[[es.elementId]] = [[ev.id]]', ['es.siteId' => $siteId]],
            )
            ->innerJoin(['cal' => CalendarRecord::tableName()], '[[cal.id]] = [[ev.calendarId]]')
            ->where([
                'el.enabled' => true,
                'es.enabled' => true,
                'el.dateDeleted' => null,
            ])
            ->andWhere(['<', 'o.startDate', Db::prepareDateForDb($rangeEnd)])
            ->andWhere(['>', 'o.endDate', Db::prepareDateForDb($rangeStart)])
            ->orderBy(['o.startDate' => SORT_ASC]);

        if ($calendarIds !== null) {
            $query->andWhere(['ev.calendarId' => $calendarIds]);
        }

        if ($eventId !== null) {
            $query->andWhere(['o.eventId' => $eventId]);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->all();
    }

    private function windowStart(Event $event): DateTimeImmutable
    {
        // Generate from the event's first occurrence (so past occurrences within the rule are stored)
        // up to the horizon.
        return DateTimeImmutable::createFromInterface($event->startDate)->setTimezone(new DateTimeZone('UTC'));
    }

    private function horizonEnd(): DateTimeImmutable
    {
        $months = Owl::getInstance()->getSettings()->occurrenceHorizonMonths;

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval("P{$months}M"));
    }
}
