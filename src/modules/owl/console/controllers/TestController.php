<?php

declare(strict_types=1);

namespace justinholtweb\owl\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use DateTimeZone;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\CalendarRecord;
use justinholtweb\owl\records\ExceptionRecord;
use justinholtweb\owl\records\OccurrenceRecord;
use yii\console\ExitCode;

/**
 * Integration test harness for the Craft layer. Exercises the real elements, services, queries, and
 * database against a running Craft install and exits non-zero on failure, so it can gate CI.
 *
 * Used in place of markhuot/craft-pest, which (as of 2026-06) requires symfony/css-selector ^6 and
 * is therefore not installable alongside Craft 5.9's symfony 7 dependencies.
 *
 *   php craft owl/test/run
 */
class TestController extends Controller
{
    private int $passed = 0;
    private int $failed = 0;

    public function actionRun(): int
    {
        $this->cleanup();

        try {
            $calendar = $this->testCalendarSave();
            $event = $this->testEventSaveStoresUtc($calendar);
            $this->testTimezoneDisplay($event);
            $this->testOccurrencesMaterialised($event);
            $this->testDstAcrossSpringForward($event);
            $this->testRuleEditRegenerates($event);
            $this->testExceptionRemovesOccurrence($event);
            $this->testEventQueryRange($calendar);
            $this->testOccurrencesInRange($calendar, $event);
            $this->testDisabledEventExcludedFromFeed($event);
            $this->testGraphql();
            $this->testIcsFeed($event);
            $this->testCommerceTicketing($event);
        } finally {
            $this->cleanup();
        }

        $this->stdout("\n", Console::RESET);
        $total = $this->passed + $this->failed;
        if ($this->failed === 0) {
            $this->stdout("✓ All {$total} checks passed.\n", Console::FG_GREEN, Console::BOLD);
            return ExitCode::OK;
        }

        $this->stdout("✗ {$this->failed} of {$total} checks failed.\n", Console::FG_RED, Console::BOLD);
        return ExitCode::UNSPECIFIED_ERROR;
    }

    private function testCalendarSave(): Calendar
    {
        $calendar = new Calendar();
        $calendar->name = 'Owl Test';
        $calendar->handle = 'owlTest';
        $calendar->color = '#FF8800';

        $saved = Owl::getInstance()->calendars->save($calendar);
        $this->assert($saved, 'Calendar saves');
        $this->assert($calendar->id !== null, 'Calendar receives an id');
        $this->assert(
            Owl::getInstance()->calendars->getCalendarByHandle('owlTest')?->name === 'Owl Test',
            'Calendar is retrievable by handle',
        );

        return $calendar;
    }

    private function testEventSaveStoresUtc(Calendar $calendar): Event
    {
        $tz = new DateTimeZone('America/New_York');

        $event = new Event();
        $event->calendarId = (int)$calendar->id;
        $event->title = 'TZ + DST Test';
        $event->timezone = 'America/New_York';
        // 2025-03-07 is before the spring-forward; 09:00 EST == 14:00 UTC.
        $event->startDate = new DateTime('2025-03-07 09:00:00', $tz);
        $event->endDate = new DateTime('2025-03-07 10:00:00', $tz);
        $event->rrule = 'FREQ=DAILY;COUNT=6';

        $this->assert(Craft::$app->getElements()->saveElement($event), 'Event saves');

        $record = \justinholtweb\owl\records\EventRecord::findOne($event->id);
        $this->assert(
            $record !== null && str_starts_with((string)$record->startDate, '2025-03-07 14:00'),
            'Event start stored as UTC (09:00 America/New_York -> 14:00 UTC)',
        );

        return $event;
    }

    private function testTimezoneDisplay(Event $event): void
    {
        // The editor renders the date pickers via {{ value|date('short', eventTz) }}. Reload the
        // event from the database and confirm its stored UTC instant formats to the event-local
        // wall-clock time (09:00), not the system timezone — this is what the editor now shows.
        $reloaded = Owl::getInstance()->events->getEventById((int)$event->id);
        $local = $reloaded?->startDate?->setTimezone(new DateTimeZone((string)$reloaded->timezone))->format('H:i');

        $this->assert($local === '09:00', "Editor displays event-local time, not system tz (got {$local})");
    }

    private function testOccurrencesMaterialised(Event $event): void
    {
        $count = (int)OccurrenceRecord::find()->where(['eventId' => $event->id])->count();
        $this->assert($count === 6, "Recurring event materialised 6 occurrences (got {$count})");
    }

    private function testDstAcrossSpringForward(Event $event): void
    {
        // Daily 09:00 America/New_York across the 2025-03-09 transition:
        // 03-08 is EST (-05:00) -> 14:00 UTC; 03-10 is EDT (-04:00) -> 13:00 UTC.
        $before = OccurrenceRecord::find()
            ->select(['startDate'])
            ->where(['eventId' => $event->id])
            ->andWhere(['like', 'startDate', '2025-03-08%', false])
            ->scalar();
        $after = OccurrenceRecord::find()
            ->select(['startDate'])
            ->where(['eventId' => $event->id])
            ->andWhere(['like', 'startDate', '2025-03-10%', false])
            ->scalar();

        $this->assert(str_contains((string)$before, '14:00'), 'Pre-DST occurrence is 14:00 UTC');
        $this->assert(str_contains((string)$after, '13:00'), 'Post-DST occurrence is 13:00 UTC (offset shifted, wall-clock held)');
    }

    private function testRuleEditRegenerates(Event $event): void
    {
        $event->rrule = 'FREQ=DAILY;COUNT=3';
        Craft::$app->getElements()->saveElement($event);

        $count = (int)OccurrenceRecord::find()->where(['eventId' => $event->id])->count();
        $this->assert($count === 3, "Editing the rule regenerates occurrences (6 -> 3, got {$count})");
    }

    private function testExceptionRemovesOccurrence(Event $event): void
    {
        $exception = new ExceptionRecord();
        $exception->eventId = (int)$event->id;
        // Exclude the 2nd occurrence (2025-03-08 14:00 UTC).
        $exception->date = '2025-03-08 14:00:00';
        $exception->save(false);

        Owl::getInstance()->occurrences->regenerate($event);

        $count = (int)OccurrenceRecord::find()->where(['eventId' => $event->id])->count();
        $this->assert($count === 2, "EXDATE exception removes an occurrence (3 -> 2, got {$count})");

        // Reset for later checks.
        ExceptionRecord::deleteAll(['eventId' => $event->id]);
        Owl::getInstance()->occurrences->regenerate($event);
    }

    private function testEventQueryRange(Calendar $calendar): void
    {
        $inRange = (int)Event::find()
            ->calendarId($calendar->id)
            ->status(null)
            ->startsAfter('2025-01-01')
            ->startsBefore('2025-12-31')
            ->count();
        $outOfRange = (int)Event::find()
            ->calendarId($calendar->id)
            ->status(null)
            ->startsAfter('2030-01-01')
            ->count();

        $this->assert($inRange === 1, "EventQuery startsAfter/startsBefore matches in-range event (got {$inRange})");
        $this->assert($outOfRange === 0, "EventQuery excludes out-of-range event (got {$outOfRange})");
    }

    private function testOccurrencesInRange(Calendar $calendar, Event $event): void
    {
        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange(
            new DateTime('2025-03-01', new DateTimeZone('UTC')),
            new DateTime('2025-03-31', new DateTimeZone('UTC')),
            $event->siteId,
            [$calendar->id],
        );

        $this->assert(count($rows) > 0, 'getOccurrencesInRange returns occurrences in window');
        $this->assert(
            ($rows[0]['title'] ?? null) === 'TZ + DST Test' && ($rows[0]['color'] ?? null) === '#FF8800',
            'Occurrence rows carry event title and calendar color',
        );
    }

    private function testDisabledEventExcludedFromFeed(Event $event): void
    {
        $event->enabled = false;
        Craft::$app->getElements()->saveElement($event);

        $rows = Owl::getInstance()->occurrences->getOccurrencesInRange(
            new DateTime('2025-03-01', new DateTimeZone('UTC')),
            new DateTime('2025-03-31', new DateTimeZone('UTC')),
            $event->siteId,
        );
        $this->assert($rows === [], 'Disabled event is excluded from the front-end feed');

        $event->enabled = true;
        Craft::$app->getElements()->saveElement($event);
    }

    private function testGraphql(): void
    {
        $schema = new \craft\models\GqlSchema([
            'name' => 'Owl Test',
            'scope' => ['owl.events:read'],
        ]);

        // Real GraphQL requests set the active schema from the token; do the same here so the
        // resolver's schema-scope check sees our granted scope.
        Craft::$app->getGql()->setActiveSchema($schema);

        $query = '{ owlEventCount(calendar: "owlTest") owlEvents(calendar: "owlTest") { title allDay rrule calendarHandle } }';
        $result = Craft::$app->getGql()->executeQuery($schema, $query);

        $noErrors = empty($result['errors']);
        $this->assert($noErrors, 'GraphQL query executes without errors' . ($noErrors ? '' : ': ' . json_encode($result['errors'])));

        $found = false;
        foreach ($result['data']['owlEvents'] ?? [] as $row) {
            if (($row['title'] ?? null) === 'TZ + DST Test' && ($row['calendarHandle'] ?? null) === 'owlTest') {
                $found = true;
            }
        }
        $this->assert($found, 'GraphQL owlEvents (filtered by calendar) returns the event with its calendar handle');
        $this->assert((int)($result['data']['owlEventCount'] ?? 0) >= 1, 'GraphQL owlEventCount returns at least 1');
    }

    private function testIcsFeed(Event $event): void
    {
        $ics = Owl::getInstance()->ics->eventFeed($event);
        $this->assert(str_contains($ics, 'BEGIN:VCALENDAR'), 'ICS feed is a VCALENDAR');
        $this->assert(substr_count($ics, 'BEGIN:VEVENT') === 3, 'ICS feed has one VEVENT per occurrence');
    }

    private function testCommerceTicketing(Event $event): void
    {
        if (!Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            $this->stdout("  - Commerce not installed; skipping ticketing checks.\n");
            return;
        }

        $ticket = Owl::getInstance()->tickets->createTicket($event, 'General Admission', 25.0, 2);
        $this->assert($ticket->id !== null, 'Ticket saves as a Commerce purchasable');

        $purchasables = \craft\commerce\Plugin::getInstance()->getPurchasables();
        $this->assert(
            in_array(\justinholtweb\owl\elements\Ticket::class, $purchasables->getAllPurchasableElementTypes(), true),
            'Ticket is a registered Commerce purchasable type',
        );
        $this->assert(
            $purchasables->getPurchasableById((int)$ticket->id) instanceof \justinholtweb\owl\elements\Ticket,
            'Commerce resolves the ticket by id',
        );

        $price = (float)$ticket->getPrice();
        $this->assert(abs($price - 25.0) < 0.001, "Ticket price is 25 (got {$price})");
        $this->assert($ticket->getSku() !== '', 'Ticket has a SKU');
        $this->assert($ticket->getIsAvailable(), 'New ticket with capacity is available');
        $this->assert($ticket->getRemaining() === 2, 'Ticket reports 2 remaining (got ' . var_export($ticket->getRemaining(), true) . ')');

        // Simulate completing an order for both tickets.
        $lineItem = new \craft\commerce\models\LineItem();
        $lineItem->qty = 2;
        $ticket->afterOrderComplete(new \craft\commerce\elements\Order(), $lineItem);

        $reloaded = Owl::getInstance()->tickets->getTicketById((int)$ticket->id);
        $this->assert($reloaded !== null && $reloaded->sold === 2, 'Sold count increments to 2 after order completion');
        $this->assert($reloaded !== null && $reloaded->getRemaining() === 0, 'Sold-out ticket reports 0 remaining');
        $this->assert($reloaded !== null && !$reloaded->getIsAvailable(), 'Sold-out ticket is no longer available');
    }

    private function assert(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            $this->stdout('  ✓ ', Console::FG_GREEN);
        } else {
            $this->failed++;
            $this->stdout('  ✗ ', Console::FG_RED);
        }
        $this->stdout($label . "\n");
    }

    private function cleanup(): void
    {
        $record = CalendarRecord::findOne(['handle' => 'owlTest']);
        if ($record === null) {
            return;
        }

        $commerce = Craft::$app->getPlugins()->isPluginInstalled('commerce');

        foreach (Event::find()->calendarId($record->id)->status(null)->all() as $event) {
            if ($commerce) {
                foreach (Owl::getInstance()->tickets->getTicketsForEvent((int)$event->id) as $ticket) {
                    Craft::$app->getElements()->deleteElement($ticket, true);
                }
            }
            Craft::$app->getElements()->deleteElement($event, true);
        }

        Owl::getInstance()->calendars->deleteCalendarById((int)$record->id);
        Owl::getInstance()->calendars->refresh();
    }
}
