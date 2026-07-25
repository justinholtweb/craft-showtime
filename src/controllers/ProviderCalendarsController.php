<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\Plugin;
use yii\web\Response;

/**
 * One screen: which event calendars each booking provider runs.
 */
class ProviderCalendarsController extends Controller
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
        $showtime = Plugin::getInstance();
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = $showtime->getModuleByHandle('stub');
        /** @var \justinholtweb\owl\Owl|null $owl */
        $owl = $showtime->getModuleByHandle('owl');

        return $this->renderTemplate('showtime/provider-calendars/_index', [
            'providers' => $stub?->providers->getAllProviders() ?? [],
            'calendars' => $owl?->calendars->getAllCalendars() ?? [],
            'links' => $showtime->providerCalendars->allLinks(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $posted = Craft::$app->getRequest()->getBodyParam('calendars', []);
        $service = Plugin::getInstance()->providerCalendars;

        foreach ($posted as $providerId => $calendarIds) {
            $service->setCalendarsForProvider((int)$providerId, is_array($calendarIds) ? $calendarIds : []);
        }

        Craft::$app->getSession()->setNotice(Craft::t('showtime', 'Provider calendars saved.'));

        return $this->redirect('showtime/provider-calendars');
    }
}
