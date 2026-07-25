<?php

declare(strict_types=1);

namespace justinholtweb\owl\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\owl\elements\Event;
use justinholtweb\owl\models\Calendar;
use justinholtweb\owl\Owl;
use justinholtweb\owl\records\OccurrenceRecord;
use yii\console\ExitCode;

/**
 * Seeds sample Owl data for local development and as a smoke test of the
 * save → materialise-occurrences pipeline.
 *
 *   php craft owl/demo/seed
 */
class DemoController extends Controller
{
    /**
     * Creates a demo calendar and a weekly recurring event, then reports how many occurrences
     * were materialised.
     */
    public function actionSeed(): int
    {
        $calendar = $this->ensureCalendar('demo', 'Demo Calendar');
        $this->stdout("Calendar #{$calendar->id} ({$calendar->handle}) ready.\n", Console::FG_GREEN);

        $tz = new DateTimeZone('America/New_York');

        $event = new Event();
        $event->calendarId = (int)$calendar->id;
        $event->title = 'Weekly Stand-up ' . StringHelper::randomString(4);
        $event->timezone = $tz->getName();
        $event->startDate = new DateTime('next monday 09:00', $tz);
        $event->endDate = new DateTime('next monday 09:30', $tz);
        $event->rrule = 'FREQ=WEEKLY;BYDAY=MO;COUNT=12';

        if (!Craft::$app->getElements()->saveElement($event)) {
            $this->stderr("Failed to save event: " . print_r($event->getErrors(), true) . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Saved event #{$event->id}: {$event->title}\n", Console::FG_GREEN);

        $count = OccurrenceRecord::find()->where(['eventId' => $event->id])->count();
        $this->stdout("Materialised {$count} occurrences.\n", Console::FG_YELLOW);

        $first = OccurrenceRecord::find()
            ->where(['eventId' => $event->id])
            ->orderBy(['startDate' => SORT_ASC])
            ->one();

        if ($first !== null) {
            /** @var OccurrenceRecord $first */
            $this->stdout("First occurrence (UTC): {$first->startDate} → {$first->endDate}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Exercises the Event element query (the join + custom params) and prints what it finds.
     *
     *   php craft owl/demo/list
     */
    public function actionList(): int
    {
        $total = Event::find()->count();
        $repeating = Event::find()->repeating(true)->count();
        $this->stdout("Events: {$total} total, {$repeating} repeating.\n", Console::FG_GREEN);

        $events = Event::find()->orderBy(['owl_events.startDate' => SORT_ASC])->limit(5)->all();
        foreach ($events as $event) {
            /** @var Event $event */
            $start = $event->startDate?->format('Y-m-d H:i') ?? '—';
            $cal = $event->getCalendar()?->name ?? '—';
            $this->stdout("  #{$event->id}  {$event->title}  [{$cal}]  start={$start}  rrule={$event->rrule}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Copies Owl's demo front-end templates into the project's templates/owl/ directory.
     *
     *   php craft owl/demo/install-templates
     */
    public function actionInstallTemplates(): int
    {
        $source = Owl::getInstance()->getBasePath() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . '_demo';
        $dest = Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . 'owl';

        FileHelper::copyDirectory($source, $dest);

        $this->stdout("Installed demo templates to {$dest}\n", Console::FG_GREEN);
        $this->stdout("View them at /owl/calendar and /owl/upcoming.\n");

        return ExitCode::OK;
    }

    private function ensureCalendar(string $handle, string $name): Calendar
    {
        $calendar = Owl::getInstance()->calendars->getCalendarByHandle($handle);

        if ($calendar === null) {
            $calendar = new Calendar();
            $calendar->name = $name;
            $calendar->handle = $handle;
            $calendar->color = '#7C5CFF';
        }

        // Give the demo calendar front-end URLs so event detail pages render.
        $calendar->uriFormat = 'events/{slug}';
        $calendar->template = 'owl/_event';
        Owl::getInstance()->calendars->save($calendar);
        Owl::getInstance()->calendars->refresh();

        return $calendar;
    }
}
