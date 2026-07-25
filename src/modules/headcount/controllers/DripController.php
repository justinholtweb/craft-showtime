<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\DripSchedule;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DripController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('headcount-manageDrip');
        return true;
    }

    public function actionIndex(): Response
    {
        $schedules = Headcount::getInstance()->drip->getAllSchedules();

        return $this->renderTemplate('headcount/drip/index', [
            'schedules' => $schedules,
        ]);
    }

    public function actionEdit(?int $scheduleId = null, ?DripSchedule $schedule = null): Response
    {
        if ($schedule === null) {
            if ($scheduleId !== null) {
                $schedule = Headcount::getInstance()->drip->getScheduleById($scheduleId);
                if (!$schedule) {
                    throw new NotFoundHttpException('Drip schedule not found');
                }
            } else {
                $schedule = new DripSchedule();
            }
        }

        $isNew = !$schedule->id;

        $plans = Headcount::getInstance()->plans->getAllPlans();
        $planOptions = [];
        foreach ($plans as $plan) {
            $planOptions[] = ['label' => $plan->name, 'value' => $plan->id];
        }

        $sections = Craft::$app->getEntries()->getAllSections();
        $sectionOptions = [];
        foreach ($sections as $section) {
            $sectionOptions[] = ['label' => $section->name, 'value' => $section->id];
        }

        $entryTypeOptions = [];
        foreach ($sections as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $entryTypeOptions[] = ['label' => $section->name . ' - ' . $entryType->name, 'value' => $entryType->id];
            }
        }

        return $this->renderTemplate('headcount/drip/edit', [
            'schedule' => $schedule,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('headcount', 'New Drip Schedule') : $schedule->name,
            'planOptions' => $planOptions,
            'sectionOptions' => $sectionOptions,
            'entryTypeOptions' => $entryTypeOptions,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $scheduleId = $request->getBodyParam('scheduleId');

        if ($scheduleId) {
            $schedule = Headcount::getInstance()->drip->getScheduleById($scheduleId);
            if (!$schedule) {
                throw new NotFoundHttpException('Drip schedule not found');
            }
        } else {
            $schedule = new DripSchedule();
        }

        $schedule->name = $request->getBodyParam('name', $schedule->name);
        $schedule->planIds = $request->getBodyParam('planIds') ?: null;
        $schedule->type = $request->getBodyParam('type', $schedule->type);
        $schedule->targetId = $request->getBodyParam('targetId') ?: null;
        $schedule->delayDays = (int)$request->getBodyParam('delayDays', $schedule->delayDays);
        $schedule->enabled = (bool)$request->getBodyParam('enabled', $schedule->enabled);

        if (!Headcount::getInstance()->drip->saveSchedule($schedule)) {
            return $this->asFailure(
                Craft::t('headcount', 'Couldn\'t save drip schedule.'),
                ['schedule' => $schedule]
            );
        }

        return $this->asSuccess(
            Craft::t('headcount', 'Drip schedule saved.'),
            ['schedule' => $schedule],
            'headcount/drip/' . $schedule->id
        );
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $scheduleId = Craft::$app->getRequest()->getRequiredBodyParam('id');
        Headcount::getInstance()->drip->deleteScheduleById($scheduleId);

        return $this->asSuccess(Craft::t('headcount', 'Drip schedule deleted.'));
    }
}
