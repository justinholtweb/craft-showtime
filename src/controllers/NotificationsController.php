<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\Plugin;
use yii\web\Response;

/**
 * One screen for every email the bundle sends, instead of one per bundled plugin.
 */
class NotificationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The from-address is a site-wide identity and the toggles reach into three modules'
        // settings, so this is admin territory — the same bar the settings screen sets.
        $this->requireAdmin();

        return true;
    }

    public function actionIndex(): Response
    {
        $showtime = Plugin::getInstance();

        $grouped = [];
        foreach ($showtime->notifications->definitions() as $key => $definition) {
            $grouped[$definition['moduleLabel']][$key] = $definition;
        }

        return $this->renderTemplate('showtime/notifications/_index', [
            'grouped' => $grouped,
            'settings' => $showtime->getSettings(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $showtime = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $settings = $showtime->getSettings();
        $settings->emailFromName = (string)$request->getBodyParam('emailFromName', $settings->emailFromName);
        $settings->emailFromEmail = (string)$request->getBodyParam('emailFromEmail', $settings->emailFromEmail);

        $savedIdentity = Craft::$app->getPlugins()->savePluginSettings($showtime, $settings->toArray());
        $savedToggles = $showtime->notifications->save($request->getBodyParam('messages', []));

        if (!$savedIdentity || !$savedToggles) {
            Craft::$app->getSession()->setError(Craft::t('showtime', 'Couldn’t save notifications.'));

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('showtime', 'Notifications saved.'));

        return $this->redirect('showtime/notifications');
    }
}
