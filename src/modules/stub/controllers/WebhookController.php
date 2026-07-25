<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = ['handle'];
    public $enableCsrfValidation = false;

    public function actionHandle(): Response
    {
        $this->requirePostRequest();

        $payload = Craft::$app->getRequest()->getRawBody();
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (!$sigHeader) {
            return $this->asJson(['error' => 'Missing signature.'])->setStatusCode(400);
        }

        // When mounted in a host bundle, hand off so this endpoint and the bundle's behave
        // identically — same verification, same routing — for sites already pointing Stripe
        // here.
        $router = Plugin::getInstance()->stripeWebhookRouter;

        $success = $router !== null
            ? (bool)call_user_func($router, $payload, $sigHeader)
            : Plugin::getInstance()->payments->handleWebhookEvent($payload, $sigHeader);

        if (!$success) {
            return $this->asJson(['error' => 'Webhook handling failed.'])->setStatusCode(400);
        }

        return $this->asJson(['received' => true]);
    }
}
