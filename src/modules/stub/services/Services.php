<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\records\ServiceRecord;
use yii\base\Component;

class Services extends Component
{
    public function getAllServices(bool $includeDisabled = false): array
    {
        $query = $this->_createQuery();

        if (!$includeDisabled) {
            $query->where(['enabled' => true]);
        }

        $query->andWhere(['dateDeleted' => null])
            ->orderBy(['sortOrder' => SORT_ASC]);

        return array_map(fn($row) => $this->_createServiceFromRow($row), $query->all());
    }

    public function getServiceById(int $id): ?Service
    {
        $row = $this->_createQuery()
            ->where(['id' => $id])
            ->andWhere(['dateDeleted' => null])
            ->one();

        return $row ? $this->_createServiceFromRow($row) : null;
    }

    public function getServiceByHandle(string $handle): ?Service
    {
        $row = $this->_createQuery()
            ->where(['handle' => $handle])
            ->andWhere(['dateDeleted' => null])
            ->one();

        return $row ? $this->_createServiceFromRow($row) : null;
    }

    public function saveService(Service $service): bool
    {
        if (!$service->validate()) {
            return false;
        }

        if ($service->id) {
            $record = ServiceRecord::findOne($service->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new ServiceRecord();
        }

        $record->name = $service->name;
        $record->handle = $service->handle;
        $record->description = $service->description;
        $record->duration = $service->duration;
        $record->price = $service->price;
        $record->currency = $service->currency;
        $record->bufferTimeBefore = $service->bufferTimeBefore;
        $record->bufferTimeAfter = $service->bufferTimeAfter;
        $record->capacity = $service->capacity;
        $record->color = $service->color;
        $record->enabled = $service->enabled;
        $record->sortOrder = $service->sortOrder;

        if (!$record->save()) {
            $service->addErrors($record->getErrors());
            return false;
        }

        $service->id = $record->id;
        $service->uid = $record->uid;
        $service->dateCreated = $record->dateCreated;
        $service->dateUpdated = $record->dateUpdated;

        return true;
    }

    public function deleteService(int $id): bool
    {
        $record = ServiceRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $record->softDelete();
        return true;
    }

    public function reorderServices(array $ids): bool
    {
        foreach ($ids as $order => $id) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%stub_services}}', ['sortOrder' => $order], ['id' => $id])
                ->execute();
        }
        return true;
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select([
                'id', 'name', 'handle', 'description', 'duration', 'price', 'currency',
                'bufferTimeBefore', 'bufferTimeAfter', 'capacity', 'color', 'enabled',
                'sortOrder', 'dateCreated', 'dateUpdated', 'dateDeleted', 'uid',
            ])
            ->from('{{%stub_services}}');
    }

    private function _createServiceFromRow(array $row): Service
    {
        return new Service([
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'handle' => $row['handle'],
            'description' => $row['description'],
            'duration' => (int)$row['duration'],
            'price' => (float)$row['price'],
            'currency' => $row['currency'],
            'bufferTimeBefore' => (int)$row['bufferTimeBefore'],
            'bufferTimeAfter' => (int)$row['bufferTimeAfter'],
            'capacity' => (int)$row['capacity'],
            'color' => $row['color'],
            'enabled' => (bool)$row['enabled'],
            'sortOrder' => (int)$row['sortOrder'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'dateDeleted' => $row['dateDeleted'],
            'uid' => $row['uid'],
        ]);
    }
}
