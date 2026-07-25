<?php

namespace justinholtweb\stub\services;

use craft\db\Query;
use justinholtweb\stub\models\Provider;
use justinholtweb\stub\models\ProviderBlockedDate;
use justinholtweb\stub\models\ProviderBreak;
use justinholtweb\stub\models\ProviderSchedule;
use justinholtweb\stub\records\ProviderBlockedDateRecord;
use justinholtweb\stub\records\ProviderBreakRecord;
use justinholtweb\stub\records\ProviderRecord;
use justinholtweb\stub\records\ProviderScheduleRecord;
use justinholtweb\stub\records\ProviderServiceRecord;
use yii\base\Component;

class Providers extends Component
{
    public function getAllProviders(bool $includeDisabled = false): array
    {
        $query = $this->_createQuery();

        if (!$includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        $query->andWhere(['dateDeleted' => null])
            ->orderBy(['sortOrder' => SORT_ASC]);

        return array_map(fn($row) => $this->_createProviderFromRow($row), $query->all());
    }

    public function getProviderById(int $id): ?Provider
    {
        $row = $this->_createQuery()
            ->where(['id' => $id])
            ->andWhere(['dateDeleted' => null])
            ->one();

        if (!$row) {
            return null;
        }

        $provider = $this->_createProviderFromRow($row);
        $provider->schedules = $this->getSchedulesForProvider($id);
        $provider->breaks = $this->getBreaksForProvider($id);
        $provider->blockedDates = $this->getBlockedDatesForProvider($id);
        $provider->serviceIds = $this->getServiceIdsForProvider($id);

        return $provider;
    }

    public function getProvidersByServiceId(int $serviceId): array
    {
        $providerIds = (new Query())
            ->select(['providerId'])
            ->from('{{%stub_provider_services}}')
            ->where(['serviceId' => $serviceId])
            ->column();

        if (empty($providerIds)) {
            return [];
        }

        return array_map(
            fn($row) => $this->_createProviderFromRow($row),
            $this->_createQuery()
                ->where(['id' => $providerIds])
                ->andWhere(['enabled' => true, 'dateDeleted' => null])
                ->orderBy(['sortOrder' => SORT_ASC])
                ->all()
        );
    }

    public function saveProvider(Provider $provider): bool
    {
        if (!$provider->validate()) {
            return false;
        }

        $isNew = !$provider->id;

        if (!$isNew) {
            $record = ProviderRecord::findOne($provider->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new ProviderRecord();
        }

        $record->userId = $provider->userId;
        $record->name = $provider->name;
        $record->handle = $provider->handle;
        $record->email = $provider->email;
        $record->phone = $provider->phone;
        $record->bio = $provider->bio;
        $record->color = $provider->color;
        $record->timezone = $provider->timezone;
        $record->enabled = $provider->enabled;
        $record->sortOrder = $provider->sortOrder;

        if (!$record->save()) {
            $provider->addErrors($record->getErrors());
            return false;
        }

        $provider->id = $record->id;
        $provider->uid = $record->uid;

        // Save service assignments
        $this->_saveProviderServices($provider->id, $provider->serviceIds);

        return true;
    }

    public function deleteProvider(int $id): bool
    {
        $record = ProviderRecord::findOne($id);
        if (!$record) {
            return false;
        }
        $record->softDelete();
        return true;
    }

    // Schedule management

    public function getSchedulesForProvider(int $providerId): array
    {
        $rows = (new Query())
            ->select(['id', 'providerId', 'dayOfWeek', 'startTime', 'endTime', 'enabled', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_provider_schedules}}')
            ->where(['providerId' => $providerId])
            ->orderBy(['dayOfWeek' => SORT_ASC])
            ->all();

        return array_map(fn($row) => new ProviderSchedule([
            'id' => (int)$row['id'],
            'providerId' => (int)$row['providerId'],
            'dayOfWeek' => (int)$row['dayOfWeek'],
            'startTime' => $row['startTime'],
            'endTime' => $row['endTime'],
            'enabled' => (bool)$row['enabled'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]), $rows);
    }

    public function getScheduleForDay(int $providerId, int $dayOfWeek): ?ProviderSchedule
    {
        $row = (new Query())
            ->select(['id', 'providerId', 'dayOfWeek', 'startTime', 'endTime', 'enabled', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_provider_schedules}}')
            ->where(['providerId' => $providerId, 'dayOfWeek' => $dayOfWeek])
            ->one();

        if (!$row) {
            return null;
        }

        return new ProviderSchedule([
            'id' => (int)$row['id'],
            'providerId' => (int)$row['providerId'],
            'dayOfWeek' => (int)$row['dayOfWeek'],
            'startTime' => $row['startTime'],
            'endTime' => $row['endTime'],
            'enabled' => (bool)$row['enabled'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]);
    }

    public function saveSchedules(int $providerId, array $schedulesData): bool
    {
        // Delete existing schedules
        ProviderScheduleRecord::deleteAll(['providerId' => $providerId]);

        foreach ($schedulesData as $data) {
            $record = new ProviderScheduleRecord();
            $record->providerId = $providerId;
            $record->dayOfWeek = (int)$data['dayOfWeek'];
            $record->startTime = $data['startTime'];
            $record->endTime = $data['endTime'];
            $record->enabled = (bool)($data['enabled'] ?? true);

            if (!$record->save()) {
                return false;
            }
        }

        return true;
    }

    // Break management

    public function getBreaksForProvider(int $providerId): array
    {
        $rows = (new Query())
            ->select(['id', 'providerId', 'dayOfWeek', 'startTime', 'endTime', 'label', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_provider_breaks}}')
            ->where(['providerId' => $providerId])
            ->orderBy(['dayOfWeek' => SORT_ASC, 'startTime' => SORT_ASC])
            ->all();

        return array_map(fn($row) => new ProviderBreak([
            'id' => (int)$row['id'],
            'providerId' => (int)$row['providerId'],
            'dayOfWeek' => (int)$row['dayOfWeek'],
            'startTime' => $row['startTime'],
            'endTime' => $row['endTime'],
            'label' => $row['label'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]), $rows);
    }

    public function getBreaksForDay(int $providerId, int $dayOfWeek): array
    {
        $rows = (new Query())
            ->select(['id', 'providerId', 'dayOfWeek', 'startTime', 'endTime', 'label'])
            ->from('{{%stub_provider_breaks}}')
            ->where(['providerId' => $providerId, 'dayOfWeek' => $dayOfWeek])
            ->orderBy(['startTime' => SORT_ASC])
            ->all();

        return array_map(fn($row) => new ProviderBreak([
            'id' => (int)$row['id'],
            'providerId' => (int)$row['providerId'],
            'dayOfWeek' => (int)$row['dayOfWeek'],
            'startTime' => $row['startTime'],
            'endTime' => $row['endTime'],
            'label' => $row['label'],
        ]), $rows);
    }

    public function saveBreaks(int $providerId, array $breaksData): bool
    {
        ProviderBreakRecord::deleteAll(['providerId' => $providerId]);

        foreach ($breaksData as $data) {
            $record = new ProviderBreakRecord();
            $record->providerId = $providerId;
            $record->dayOfWeek = (int)$data['dayOfWeek'];
            $record->startTime = $data['startTime'];
            $record->endTime = $data['endTime'];
            $record->label = $data['label'] ?? null;

            if (!$record->save()) {
                return false;
            }
        }

        return true;
    }

    // Blocked dates

    public function getBlockedDatesForProvider(int $providerId): array
    {
        $rows = (new Query())
            ->select(['id', 'providerId', 'startDate', 'endDate', 'reason', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_provider_blocked_dates}}')
            ->where(['providerId' => $providerId])
            ->orderBy(['startDate' => SORT_ASC])
            ->all();

        return array_map(fn($row) => new ProviderBlockedDate([
            'id' => (int)$row['id'],
            'providerId' => (int)$row['providerId'],
            'startDate' => $row['startDate'],
            'endDate' => $row['endDate'],
            'reason' => $row['reason'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]), $rows);
    }

    public function isDateBlocked(int $providerId, string $date): bool
    {
        return (new Query())
            ->from('{{%stub_provider_blocked_dates}}')
            ->where(['providerId' => $providerId])
            ->andWhere(['<=', 'startDate', $date])
            ->andWhere(['>=', 'endDate', $date])
            ->exists();
    }

    public function saveBlockedDates(int $providerId, array $blockedDatesData): bool
    {
        ProviderBlockedDateRecord::deleteAll(['providerId' => $providerId]);

        foreach ($blockedDatesData as $data) {
            $record = new ProviderBlockedDateRecord();
            $record->providerId = $providerId;
            $record->startDate = $data['startDate'];
            $record->endDate = $data['endDate'];
            $record->reason = $data['reason'] ?? null;

            if (!$record->save()) {
                return false;
            }
        }

        return true;
    }

    public function addBlockedDate(int $providerId, string $startDate, string $endDate, ?string $reason = null): bool
    {
        $record = new ProviderBlockedDateRecord();
        $record->providerId = $providerId;
        $record->startDate = $startDate;
        $record->endDate = $endDate;
        $record->reason = $reason;
        return $record->save();
    }

    public function deleteBlockedDate(int $id): bool
    {
        return ProviderBlockedDateRecord::deleteAll(['id' => $id]) > 0;
    }

    // Service assignments

    public function getServiceIdsForProvider(int $providerId): array
    {
        return (new Query())
            ->select(['serviceId'])
            ->from('{{%stub_provider_services}}')
            ->where(['providerId' => $providerId])
            ->column();
    }

    private function _saveProviderServices(int $providerId, array $serviceIds): void
    {
        ProviderServiceRecord::deleteAll(['providerId' => $providerId]);

        foreach ($serviceIds as $serviceId) {
            $record = new ProviderServiceRecord();
            $record->providerId = $providerId;
            $record->serviceId = (int)$serviceId;
            $record->save();
        }
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'userId', 'name', 'handle', 'email', 'phone', 'bio',
                'color', 'timezone', 'enabled', 'sortOrder',
                'dateCreated', 'dateUpdated', 'dateDeleted', 'uid',
            ])
            ->from('{{%stub_providers}}');
    }

    private function _createProviderFromRow(array $row): Provider
    {
        return new Provider([
            'id' => (int)$row['id'],
            'userId' => $row['userId'] ? (int)$row['userId'] : null,
            'name' => $row['name'],
            'handle' => $row['handle'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'bio' => $row['bio'],
            'color' => $row['color'],
            'timezone' => $row['timezone'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'dateDeleted' => $row['dateDeleted'],
            'uid' => $row['uid'],
        ]);
    }
}
