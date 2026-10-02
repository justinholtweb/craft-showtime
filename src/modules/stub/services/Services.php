<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\models\ServiceCriteria;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\records\ServiceRecord;
use yii\base\Component;

class Services extends Component
{
    public function getAllServices(bool $includeDisabled = false): array
    {
        return $this->getServices(['includeDisabled' => $includeDisabled]);
    }

    /**
     * Services matching a filter, in sort order.
     *
     * Accepts the loose hash templates pass to `craft.stub.services()` or a pre-built
     * criteria object. See {@see ServiceCriteria} for the keys and for why an empty filter
     * matches nothing rather than everything.
     *
     * This is a *display* filter. The booking endpoints are anonymous and take a service ID
     * from the request, so narrowing the list a visitor sees does not stop a crafted POST
     * from booking a service that was filtered out. Treat it as presentation, not access
     * control.
     *
     * @param ServiceCriteria|array<string, mixed> $criteria
     * @return Service[]
     */
    public function getServices(ServiceCriteria|array $criteria = []): array
    {
        if (is_array($criteria)) {
            $criteria = ServiceCriteria::fromArray($criteria);
        }

        if ($criteria->matchesNothing) {
            return [];
        }

        $query = $this->_createQuery()->where(['dateDeleted' => null]);

        if (!$criteria->includeDisabled) {
            $query->andWhere(['enabled' => true]);
        }

        if ($criteria->ids !== null) {
            $query->andWhere(['id' => $criteria->ids]);
        }

        if ($criteria->handles !== null) {
            $query->andWhere(['handle' => $criteria->handles]);
        }

        if ($criteria->hasProviderFilter()) {
            $serviceIds = $this->_serviceIdsForProviders($criteria);

            if (!$serviceIds) {
                return [];
            }

            $query->andWhere(['id' => $serviceIds]);
        }

        $query->orderBy(['sortOrder' => SORT_ASC]);

        return array_map(fn($row) => $this->_createServiceFromRow($row), $query->all());
    }

    /**
     * The service IDs offered by the providers a criteria narrows to.
     *
     * @return int[]
     */
    private function _serviceIdsForProviders(ServiceCriteria $criteria): array
    {
        $providerIds = Plugin::getInstance()->providers->getProviderIdsFor(
            $criteria->providerIds,
            $criteria->providerHandles,
            $criteria->userIds,
            $criteria->includeDisabled,
        );

        if (!$providerIds) {
            return [];
        }

        return array_map('intval', (new Query())
            ->select(['serviceId'])
            ->distinct()
            ->from('{{%stub_provider_services}}')
            ->where(['providerId' => $providerIds])
            ->column());
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
