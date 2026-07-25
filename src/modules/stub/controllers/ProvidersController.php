<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use justinholtweb\stub\models\Provider;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class ProvidersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('stub:manageProviders');
        return true;
    }

    public function actionIndex(): Response
    {
        $providers = Plugin::getInstance()->providers->getAllProviders(true);

        return $this->renderTemplate('stub/providers/_index', [
            'providers' => $providers,
            'selectedSubnavItem' => 'providers',
        ]);
    }

    public function actionEdit(?int $providerId = null, ?Provider $provider = null): Response
    {
        if (!$provider) {
            if ($providerId) {
                $provider = Plugin::getInstance()->providers->getProviderById($providerId);
                if (!$provider) {
                    throw new \yii\web\NotFoundHttpException('Provider not found.');
                }
            } else {
                $provider = new Provider();
                $provider->timezone = Plugin::getInstance()->getSettings()->defaultTimezone;
            }
        }

        $isNew = !$provider->id;
        $allServices = Plugin::getInstance()->services->getAllServices();

        return $this->renderTemplate('stub/providers/_edit', [
            'provider' => $provider,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('stub', 'New Provider') : $provider->name,
            'allServices' => $allServices,
            'selectedSubnavItem' => 'providers',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $providerId = $request->getBodyParam('providerId');

        if ($providerId) {
            $provider = Plugin::getInstance()->providers->getProviderById($providerId);
            if (!$provider) {
                throw new \yii\web\NotFoundHttpException('Provider not found.');
            }
        } else {
            $provider = new Provider();
        }

        $provider->name = $request->getBodyParam('name', $provider->name);
        $provider->handle = $request->getBodyParam('handle', $provider->handle);
        $provider->email = $request->getBodyParam('email', $provider->email);
        $provider->phone = $request->getBodyParam('phone', $provider->phone);
        $provider->bio = $request->getBodyParam('bio', $provider->bio);
        $provider->color = $request->getBodyParam('color', $provider->color);
        $provider->timezone = $request->getBodyParam('timezone', $provider->timezone);
        $provider->enabled = (bool)$request->getBodyParam('enabled', $provider->enabled);
        $provider->userId = $request->getBodyParam('userId') ?: null;
        $provider->serviceIds = $request->getBodyParam('serviceIds', []) ?: [];

        if (!$provider->handle) {
            $provider->handle = StringHelper::toHandle($provider->name);
        }

        if (!Plugin::getInstance()->providers->saveProvider($provider)) {
            Craft::$app->getSession()->setError(Craft::t('stub', 'Couldn\'t save provider.'));
            Craft::$app->getUrlManager()->setRouteParams(['provider' => $provider]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Provider saved.'));
        return $this->redirectToPostedUrl($provider);
    }

    public function actionSchedule(int $providerId): Response
    {
        $provider = Plugin::getInstance()->providers->getProviderById($providerId);
        if (!$provider) {
            throw new \yii\web\NotFoundHttpException('Provider not found.');
        }

        return $this->renderTemplate('stub/providers/_schedule', [
            'provider' => $provider,
            'title' => $provider->name . ' — ' . Craft::t('stub', 'Schedule'),
            'selectedSubnavItem' => 'providers',
        ]);
    }

    public function actionSaveSchedule(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $providerId = (int)$request->getRequiredBodyParam('providerId');

        $provider = Plugin::getInstance()->providers->getProviderById($providerId);
        if (!$provider) {
            throw new \yii\web\NotFoundHttpException('Provider not found.');
        }

        $schedulesData = $request->getBodyParam('schedules', []);
        $breaksData = $request->getBodyParam('breaks', []);

        // Filter out empty break entries
        $breaksData = array_filter($breaksData, fn($b) => !empty($b['startTime']) && !empty($b['endTime']));

        Plugin::getInstance()->providers->saveSchedules($providerId, $schedulesData);
        Plugin::getInstance()->providers->saveBreaks($providerId, array_values($breaksData));

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Schedule saved.'));
        return $this->redirect("stub/providers/{$providerId}/schedule");
    }

    public function actionAddBlockedDate(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $providerId = (int)$request->getRequiredBodyParam('providerId');
        $startDate = $request->getRequiredBodyParam('startDate');
        $endDate = $request->getBodyParam('endDate', $startDate);
        $reason = $request->getBodyParam('reason');

        Plugin::getInstance()->providers->addBlockedDate($providerId, $startDate, $endDate, $reason);

        Craft::$app->getSession()->setNotice(Craft::t('stub', 'Blocked date added.'));
        return $this->redirect("stub/providers/{$providerId}/schedule");
    }

    public function actionDeleteBlockedDate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->providers->deleteBlockedDate($id);

        return $this->asJson(['success' => true]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $providerId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Plugin::getInstance()->providers->deleteProvider($providerId);

        return $this->asJson(['success' => true]);
    }
}
