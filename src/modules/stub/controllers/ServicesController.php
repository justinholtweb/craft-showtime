<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\stub\models\Service;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class ServicesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('stub:manageServices');
        return true;
    }

    public function actionIndex(): Response
    {
        $services = Plugin::getInstance()->services->getAllServices(true);

        return $this->renderTemplate('stub/services/_index', [
            'services' => $services,
            'selectedSubnavItem' => 'services',
        ]);
    }

    public function actionEdit(?int $serviceId = null, ?Service $service = null): Response
    {
        if (!$service) {
            if ($serviceId) {
                $service = Plugin::getInstance()->services->getServiceById($serviceId);
                if (!$service) {
                    throw new \yii\web\NotFoundHttpException('Service not found.');
                }
            } else {
                $service = new Service();
                $service->currency = Plugin::getInstance()->getSettings()->defaultCurrency;
            }
        }

        $isNew = !$service->id;

        return $this->renderTemplate('stub/services/_edit', [
            'service' => $service,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('stub', 'New Service') : $service->name,
            'selectedSubnavItem' => 'services',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $serviceId = $request->getBodyParam('serviceId');

        if ($serviceId) {
            $service = Plugin::getInstance()->services->getServiceById($serviceId);
            if (!$service) {
                throw new \yii\web\NotFoundHttpException('Service not found.');
            }
        } else {
            $service = new Service();
        }

        $service->name = $request->getBodyParam('name', $service->name);
        $service->handle = $request->getBodyParam('handle', $service->handle);
        $service->description = $request->getBodyParam('description', $service->description);
        $service->duration = (int)$request->getBodyParam('duration', $service->duration);
        $service->price = (float)$request->getBodyParam('price', $service->price);
        $service->currency = $request->getBodyParam('currency', $service->currency);
        $service->bufferTimeBefore = (int)$request->getBodyParam('bufferTimeBefore', $service->bufferTimeBefore);
        $service->bufferTimeAfter = (int)$request->getBodyParam('bufferTimeAfter', $service->bufferTimeAfter);
        $service->capacity = (int)$request->getBodyParam('capacity', $service->capacity);
        $service->color = $request->getBodyParam('color', $service->color);
        $service->enabled = (bool)$request->getBodyParam('enabled', $service->enabled);

        if (!$service->handle) {
            $service->handle = StringHelper::toHandle($service->name);
        }

        if (!Plugin::getInstance()->services->saveService($service)) {
            Craft::$app->getSession()->setError(Craft::t('stub', 'Couldn\'t save service.'));
            Craft::$app->getUrlManager()->setRouteParams(['service' => $service]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Service saved.'));
        return $this->redirectToPostedUrl($service);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $serviceId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->services->deleteService($serviceId);

        return $this->asJson(['success' => true]);
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        Plugin::getInstance()->services->reorderServices($ids);

        return $this->asJson(['success' => true]);
    }
}
