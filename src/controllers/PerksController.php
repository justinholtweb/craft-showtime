<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\models\Perk;
use justinholtweb\showtime\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Member perks: what a Headcount plan does to something another module sells.
 */
class PerksController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Perks are membership configuration, so they ride on the plan permission.
        $this->requirePermission('headcount-managePlans');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('showtime/perks/_index', [
            'perks' => Plugin::getInstance()->perks->getAllPerks(),
            'plans' => $this->planOptions(),
            'services' => $this->serviceNames() + $this->ticketNames(),
        ]);
    }

    public function actionEdit(?int $perkId = null): Response
    {
        $perk = $perkId !== null
            ? Plugin::getInstance()->perks->getPerkById($perkId)
            : new Perk();

        if ($perk === null) {
            throw new NotFoundHttpException('Perk not found');
        }

        return $this->renderTemplate('showtime/perks/_edit', [
            'perk' => $perk,
            'plans' => $this->planOptions(),
            'services' => $this->serviceOptions(),
            'tickets' => $this->ticketOptions(),
            'targetTypes' => array_map(
                fn(string $value, string $label) => ['label' => Craft::t('showtime', $label), 'value' => $value],
                array_keys(Perk::TARGET_LABELS),
                Perk::TARGET_LABELS,
            ),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $perks = Plugin::getInstance()->perks;

        $perkId = $request->getBodyParam('perkId');
        $perk = $perkId ? $perks->getPerkById((int)$perkId) : new Perk();

        if ($perk === null) {
            throw new NotFoundHttpException('Perk not found');
        }

        $perk->planId = (int)$request->getBodyParam('planId') ?: null;
        $targetType = (string)$request->getBodyParam('targetType', Perk::TARGET_STUB_SERVICE);
        $perk->targetType = isset(Perk::TARGET_LABELS[$targetType]) ? $targetType : Perk::TARGET_STUB_SERVICE;
        // One select per target type is rendered and the inactive one is only *hidden*, so
        // it still posts. Keying them by type means the toggle can't silently save the wrong
        // target.
        $targetIds = $request->getBodyParam('targetIds', []);
        $perk->targetId = (int)($targetIds[$perk->targetType] ?? 0) ?: null;
        $perk->membersOnly = (bool)$request->getBodyParam('membersOnly');
        $perk->enabled = (bool)$request->getBodyParam('enabled', true);

        // Blank means "no discount of this kind", which is different from zero.
        $percent = $request->getBodyParam('discountPercent');
        $amount = $request->getBodyParam('discountAmount');
        $perk->discountPercent = ($percent === '' || $percent === null) ? null : (float)$percent;
        $perk->discountAmount = ($amount === '' || $amount === null) ? null : (float)$amount;

        if (!$perks->savePerk($perk)) {
            Craft::$app->getSession()->setError(Craft::t('showtime', 'Couldn’t save perk.'));
            Craft::$app->getUrlManager()->setRouteParams(['perk' => $perk]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('showtime', 'Perk saved.'));

        return $this->redirectToPostedUrl($perk);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $perkId = (int)Craft::$app->getRequest()->getRequiredBodyParam('perkId');
        Plugin::getInstance()->perks->deletePerkById($perkId);

        Craft::$app->getSession()->setNotice(Craft::t('showtime', 'Perk deleted.'));

        return $this->redirect('showtime/perks');
    }

    /**
     * @return array<int, string> plan id => name
     */
    private function planOptions(): array
    {
        /** @var \justinholtweb\headcount\Headcount|null $headcount */
        $headcount = Plugin::getInstance()->getModuleByHandle('headcount');

        if ($headcount === null) {
            return [];
        }

        $options = [];

        foreach ($headcount->plans->getAllPlans() as $plan) {
            $options[(int)$plan->id] = $plan->name;
        }

        return $options;
    }

    /**
     * @return array<int, string> service id => name
     */
    private function serviceNames(): array
    {
        /** @var \justinholtweb\stub\Plugin|null $stub */
        $stub = Plugin::getInstance()->getModuleByHandle('stub');

        if ($stub === null) {
            return [];
        }

        $names = [];

        foreach ($stub->services->getAllServices(true) as $service) {
            $names[(int)$service->id] = $service->name;
        }

        return $names;
    }

    /**
     * @return array<int, string> ticket id => name
     */
    private function ticketNames(): array
    {
        $owl = Plugin::getInstance()->getModuleByHandle('owl');

        if ($owl === null || !Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
            return [];
        }

        $names = [];

        foreach (\justinholtweb\owl\elements\Ticket::find()->status(null)->all() as $ticket) {
            $names[(int)$ticket->id] = (string)$ticket->title;
        }

        return $names;
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function ticketOptions(): array
    {
        return array_values(array_map(
            fn(int $id, string $name) => ['label' => $name, 'value' => $id],
            array_keys($this->ticketNames()),
            $this->ticketNames(),
        ));
    }

    /**
     * @return array<int, array{label: string, value: int}>
     */
    private function serviceOptions(): array
    {
        return array_values(array_map(
            fn(int $id, string $name) => ['label' => $name, 'value' => $id],
            array_keys($this->serviceNames()),
            $this->serviceNames(),
        ));
    }
}
