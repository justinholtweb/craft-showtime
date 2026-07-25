<?php

namespace justinholtweb\showtime\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\showtime\Plugin;
use yii\web\Response;

/**
 * The bundle's single Stripe webhook endpoint: /actions/showtime/webhook/stripe
 */
class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = ['stripe'];
    public $enableCsrfValidation = false;

    public function actionStripe(): Response
    {
        $this->requirePostRequest();

        $payload = Craft::$app->getRequest()->getRawBody();
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        [$handled, $module, $message] = Plugin::getInstance()->stripeWebhooks->handle($payload, $sigHeader);

        if (!$handled) {
            // Stripe retries on a non-2xx, which is what we want for a real failure — and an
            // unverified caller gets nothing useful either way.
            return $this->asJson(['error' => $message])->setStatusCode(400);
        }

        return $this->asJson(['received' => true, 'module' => $module, 'result' => $message]);
    }
}
