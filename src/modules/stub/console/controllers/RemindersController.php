<?php

namespace justinholtweb\stub\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\services\Reminders;
use yii\console\ExitCode;

/**
 * Sends the reminder emails that are due.
 *
 * Craft has no scheduler, so this is the piece a site puts on a cron — hourly is plenty
 * for a 24-hour lead time:
 *
 *     0 * * * * /path/to/craft stub/reminders/send
 *
 * The sweep is interval-agnostic and safe to re-run: it only picks up confirmed bookings
 * starting inside the lead-time window, and claims each one before composing its email.
 */
class RemindersController extends Controller
{
    /**
     * List what would be sent without sending anything.
     */
    public bool $dryRun = false;

    /**
     * The most bookings to send for in one run.
     */
    public int $limit = Reminders::DEFAULT_LIMIT;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'limit']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['d' => 'dryRun']);
    }

    /**
     * Send reminders for every booking that's due one.
     */
    public function actionSend(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->reminders->isEnabled()) {
            $this->stdout("Reminder emails are turned off in Stub's settings; nothing to do." . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($this->limit < 1) {
            $this->stderr('--limit must be at least 1.' . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        $due = $plugin->reminders->getDueBookings(null, $this->limit);

        if (!$due) {
            $this->stdout("No bookings are due a reminder." . PHP_EOL, Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            '%d booking%s starting within %d hour%s:' . PHP_EOL,
            count($due),
            count($due) === 1 ? '' : 's',
            $settings->reminderLeadTime,
            $settings->reminderLeadTime === 1 ? '' : 's',
        ));

        foreach ($due as $booking) {
            $start = $booking->getLocalStartDateTime();
            $customer = $booking->getCustomer();

            $this->stdout(sprintf(
                '  %s  %s  %s' . PHP_EOL,
                $booking->referenceNumber,
                $start ? $start->format('Y-m-d H:i') . ' ' . $booking->timezone : '—',
                $customer ? $customer->email : '—',
            ));
        }

        if ($this->dryRun) {
            $this->stdout('Dry run — nothing was sent.' . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $result = $plugin->reminders->sendDue(null, $this->limit, $due);

        $this->stdout(sprintf(
            'Sent %d message%s for %d booking%s.' . PHP_EOL,
            $result['sent'],
            $result['sent'] === 1 ? '' : 's',
            $result['bookings'],
            $result['bookings'] === 1 ? '' : 's',
        ), Console::FG_GREEN);

        if ($result['failed'] > 0) {
            // Not a failed run — the sweep did its job. These are bookings whose mail the
            // mailer refused, which is a site problem to look at in the logs.
            $this->stdout(sprintf(
                '%d booking%s produced no email. See the logs.' . PHP_EOL,
                $result['failed'],
                $result['failed'] === 1 ? '' : 's',
            ), Console::FG_RED);
        }

        return ExitCode::OK;
    }
}
