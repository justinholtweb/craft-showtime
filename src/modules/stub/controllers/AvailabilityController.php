<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class AvailabilityController extends Controller
{
    protected array|int|bool $allowAnonymous = ['get-providers', 'get-dates', 'get-slots'];

    public function actionGetProviders(): Response
    {
        $this->requireAcceptsJson();

        $serviceId = (int)Craft::$app->getRequest()->getRequiredParam('serviceId');
        $providers = Plugin::getInstance()->providers->getProvidersByServiceId($serviceId);

        $data = array_map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'bio' => $p->bio,
            'color' => $p->color,
        ], $providers);

        return $this->asJson(['providers' => $data]);
    }

    public function actionGetDates(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $serviceId = (int)$request->getRequiredParam('serviceId');
        $providerId = (int)$request->getRequiredParam('providerId');
        $year = (int)$request->getRequiredParam('year');
        $month = (int)$request->getRequiredParam('month');
        $timezone = $request->getParam('timezone', 'UTC');

        $dates = Plugin::getInstance()->availability->getAvailableDates(
            $serviceId,
            $providerId,
            $year,
            $month,
            $timezone,
        );

        return $this->asJson(['dates' => $dates]);
    }

    public function actionGetSlots(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $serviceId = (int)$request->getRequiredParam('serviceId');
        $providerId = (int)$request->getRequiredParam('providerId');
        $date = $request->getRequiredParam('date');
        $timezone = $request->getParam('timezone', 'UTC');

        $slots = Plugin::getInstance()->availability->getAvailableSlots(
            $serviceId,
            $providerId,
            $date,
            $timezone,
        );

        return $this->asJson(['slots' => $slots]);
    }
}
