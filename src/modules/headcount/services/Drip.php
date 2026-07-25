<?php

namespace justinholtweb\headcount\services;

use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use DateTime;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\helpers\Json;
use justinholtweb\headcount\models\DripSchedule;
use justinholtweb\headcount\records\DripScheduleRecord;
use yii\base\Component;

class Drip extends Component
{
    private ?array $_schedules = null;

    /**
     * Check if content is unlocked for a user.
     * Returns true if unlocked, false if still dripping, null if no drip schedule applies.
     */
    public function isUnlocked(Entry $entry, ?User $user): ?bool
    {
        $schedule = $this->getMatchingSchedule($entry);

        if (!$schedule) {
            return null; // No drip schedule applies
        }

        if (!$user) {
            return false; // Not logged in = locked
        }

        // Find the user's subscription to one of the applicable plans
        $planIds = $schedule->planIds ?? [];
        if (empty($planIds)) {
            return null;
        }

        $subscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->planId, $planIds) && $subscription->startDate) {
                $unlockDate = (clone $subscription->startDate)->modify("+{$schedule->delayDays} days");
                if (new DateTime() >= $unlockDate) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get days until content unlocks for a user.
     * Returns 0 if unlocked, positive number if dripping, null if no drip schedule.
     */
    public function daysUntilUnlocked(Entry $entry, ?User $user): ?int
    {
        $schedule = $this->getMatchingSchedule($entry);

        if (!$schedule || !$user) {
            return null;
        }

        $planIds = $schedule->planIds ?? [];
        $subscriptions = Headcount::getInstance()->subscriptions->getActiveSubscriptionsForUser($user->id);

        $minDays = null;
        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->planId, $planIds) && $subscription->startDate) {
                $unlockDate = (clone $subscription->startDate)->modify("+{$schedule->delayDays} days");
                $now = new DateTime();
                $diff = $now->diff($unlockDate);

                if (!$diff->invert) {
                    $days = $diff->days;
                    if ($minDays === null || $days < $minDays) {
                        $minDays = $days;
                    }
                } else {
                    return 0; // Already unlocked
                }
            }
        }

        return $minDays;
    }

    public function getMatchingSchedule(Entry $entry): ?DripSchedule
    {
        $schedules = $this->getAllSchedules(true);

        foreach ($schedules as $schedule) {
            $matches = match ($schedule->type) {
                'entry' => $schedule->targetId === $entry->id,
                'entryType' => $entry->typeId === $schedule->targetId,
                'section' => $entry->sectionId === $schedule->targetId,
                default => false,
            };

            if ($matches) {
                return $schedule;
            }
        }

        return null;
    }

    public function getAllSchedules(bool $enabledOnly = false): array
    {
        if ($this->_schedules === null) {
            $this->_loadSchedules();
        }

        if ($enabledOnly) {
            return array_filter($this->_schedules, fn(DripSchedule $s) => $s->enabled);
        }

        return $this->_schedules;
    }

    public function getScheduleById(int $id): ?DripSchedule
    {
        $row = (new Query())
            ->select('*')
            ->from('{{%headcount_drip_schedules}}')
            ->where(['id' => $id])
            ->one();

        return $row ? $this->_createScheduleFromRow($row) : null;
    }

    public function saveSchedule(DripSchedule $schedule): bool
    {
        if (!$schedule->validate()) {
            return false;
        }

        $isNew = !$schedule->id;

        if ($isNew) {
            $record = new DripScheduleRecord();
        } else {
            $record = DripScheduleRecord::findOne($schedule->id);
            if (!$record) {
                return false;
            }
        }

        $record->name = $schedule->name;
        $record->planIds = $schedule->planIds ? json_encode($schedule->planIds) : null;
        $record->type = $schedule->type;
        $record->targetId = $schedule->targetId;
        $record->targetUid = $schedule->targetUid;
        $record->delayDays = $schedule->delayDays;
        $record->enabled = $schedule->enabled;

        if (!$record->save()) {
            $schedule->addErrors($record->getErrors());
            return false;
        }

        if ($isNew) {
            $schedule->id = $record->id;
        }

        $this->_schedules = null;
        return true;
    }

    public function deleteScheduleById(int $id): bool
    {
        $record = DripScheduleRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->delete();
        $this->_schedules = null;

        return true;
    }

    private function _loadSchedules(): void
    {
        $this->_schedules = [];

        $rows = (new Query())
            ->select('*')
            ->from('{{%headcount_drip_schedules}}')
            ->orderBy('delayDays')
            ->all();

        foreach ($rows as $row) {
            $this->_schedules[] = $this->_createScheduleFromRow($row);
        }
    }

    private function _createScheduleFromRow(array $row): DripSchedule
    {
        $schedule = new DripSchedule();
        $schedule->id = (int)$row['id'];
        $schedule->name = $row['name'];
        $schedule->planIds = Json::decodeColumn($row['planIds']);
        $schedule->type = $row['type'];
        $schedule->targetId = $row['targetId'] ? (int)$row['targetId'] : null;
        $schedule->targetUid = $row['targetUid'];
        $schedule->delayDays = (int)$row['delayDays'];
        $schedule->enabled = (bool)$row['enabled'];
        $schedule->dateCreated = $row['dateCreated'];
        $schedule->dateUpdated = $row['dateUpdated'];
        $schedule->uid = $row['uid'];

        return $schedule;
    }
}
