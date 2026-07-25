<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = ['stripe', 'paypal'];
    public $enableCsrfValidation = false;

    public function actionStripe(): Response
    {
        $this->requirePostRequest();

        $payload = Craft::$app->getRequest()->getRawBody();
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        // When mounted in a host bundle, hand off so this endpoint and the bundle's behave
        // identically — same verification, same routing — for sites already pointing Stripe
        // here.
        $router = Headcount::getInstance()->stripeWebhookRouter;

        if ($router !== null) {
            if (!call_user_func($router, $payload, $sigHeader)) {
                return $this->asJson(['error' => 'Webhook could not be processed'])->setStatusCode(400);
            }

            return $this->asJson(['received' => true]);
        }

        try {
            $event = Headcount::getInstance()->stripe->verifyWebhookSignature($payload, $sigHeader);
        } catch (\Exception $e) {
            Craft::error('Stripe webhook signature verification failed: ' . $e->getMessage(), 'headcount');
            return $this->asJson(['error' => 'Invalid signature'])->setStatusCode(400);
        }

        // Process via webhook service
        $result = Headcount::getInstance()->webhooks->processStripeEvent($event);

        return $this->asJson(['received' => true, 'result' => $result]);
    }

    public function actionPaypal(): Response
    {
        $this->requirePostRequest();

        $payload = Craft::$app->getRequest()->getRawBody();
        $headers = Craft::$app->getRequest()->getHeaders();

        // Verify PayPal webhook
        $verified = Headcount::getInstance()->paypal->verifyWebhook($payload, $headers);

        if (!$verified) {
            Craft::error('PayPal webhook verification failed', 'headcount');
            return $this->asJson(['error' => 'Verification failed'])->setStatusCode(400);
        }

        $data = json_decode($payload, true);
        $result = Headcount::getInstance()->webhooks->processPayPalEvent($data);

        return $this->asJson(['received' => true, 'result' => $result]);
    }
}
