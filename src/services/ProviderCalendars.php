<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\stub\events\BusyIntervalsEvent;
use Throwable;

/**
 * Events a provider runs block their appointment slots.
 *
 * The scheduling problem this solves is mundane and very real: someone teaching a class at
 * 10am must not also be bookable for a 1:1 at 10am. Neither plugin can see it — Owl doesn't
 * know what a provider is, Stub doesn't know what an event is — so the host states the link
 * and feeds Owl's occurrences into Stub's availability engine as busy time.
 */
class ProviderCalendars extends Component
{
    /**
     * @return int[] calendar ids this provider runs
     */
    public function calendarIdsForProvider(int $providerId): array
    {
        return array_map('intval', (new Query())
            ->select(['calendarId'])
            ->from('{{%showtime_provider_calendars}}')
            ->where(['providerId' => $providerId])
            ->column());
    }

    /**
     * @return array<int, int[]> provider id => calendar ids
     */
    public function allLinks(): array
    {
        $links = [];

        foreach ((new Query())->select(['providerId', 'calendarId'])->from('{{%showtime_provider_calendars}}')->all() as $row) {
            $links[(int)$row['providerId']][] = (int)$row['calendarId'];
        }

        return $links;
    }

    /**
     * Replace one provider's calendar links.
     *
     * @param int[] $calendarIds
     */
    public function setCalendarsForProvider(int $providerId, array $calendarIds): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->delete('{{%showtime_provider_calendars}}', ['providerId' => $providerId])->execute();

        foreach (array_unique(array_filter(array_map('intval', $calendarIds))) as $calendarId) {
            $db->createCommand()->insert('{{%showtime_provider_calendars}}', [
                'providerId' => $providerId,
                'calendarId' => $calendarId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Add the provider's event occurrences to the busy windows Stub is about to work from.
     *
     * Owl stores occurrence times in UTC, which is exactly what the event wants, so no
     * conversion is needed — but that's a real coupling, so it's asserted by the harness
     * rather than assumed.
     */
    public function addEventOccurrences(BusyIntervalsEvent $event): void
    {
        $calendarIds = $this->calendarIdsForProvider($event->providerId);

        if ($calendarIds === []) {
            return;
        }

        try {
            $occurrences = (new Query())
                ->select(['o.startDate', 'o.endDate'])
                ->from(['o' => '{{%owl_occurrences}}'])
                ->innerJoin(['e' => '{{%owl_events}}'], '[[e.id]] = [[o.eventId]]')
                ->where(['e.calendarId' => $calendarIds])
                ->andWhere(['o.isException' => false])
                ->andWhere(['<', 'o.startDate', $event->endUtc])
                ->andWhere(['>', 'o.endDate', $event->startUtc])
                ->all();
        } catch (Throwable $e) {
            // Availability must never fail because of the glue — worst case the provider
            // looks more available than they are, which a human can still catch.
            Craft::warning("Showtime: couldn't read event occurrences for provider {$event->providerId} — {$e->getMessage()}", __METHOD__);
            return;
        }

        foreach ($occurrences as $occurrence) {
            $event->intervals[] = [
                'startDateTime' => $occurrence['startDate'],
                'endDateTime' => $occurrence['endDate'],
            ];
        }
    }
}
