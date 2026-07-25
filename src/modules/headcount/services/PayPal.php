<?php

namespace justinholtweb\headcount\services;

use Craft;
use GuzzleHttp\Client;
use justinholtweb\headcount\Headcount;
use justinholtweb\headcount\models\Plan;
use yii\base\Component;

class PayPal extends Component
{
    private ?Client $_client = null;
    private ?string $_accessToken = null;

    private function getBaseUrl(): string
    {
        $settings = Headcount::getInstance()->getSettings();
        return $settings->paypalSandbox
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function getClient(): Client
    {
        if ($this->_client === null) {
            $this->_client = new Client([
                'base_uri' => $this->getBaseUrl(),
                'timeout' => 30,
            ]);
        }

        return $this->_client;
    }

    private function getAccessToken(): string
    {
        if ($this->_accessToken !== null) {
            return $this->_accessToken;
        }

        $settings = Headcount::getInstance()->getSettings();
        $clientId = Craft::parseEnv($settings->paypalClientId);
        $clientSecret = Craft::parseEnv($settings->paypalClientSecret);

        $response = $this->getClient()->post('/v1/oauth2/token', [
            'auth' => [$clientId, $clientSecret],
            'form_params' => [
                'grant_type' => 'client_credentials',
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $this->_accessToken = $data['access_token'];

        return $this->_accessToken;
    }

    private function request(string $method, string $uri, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ]);

        $response = $this->getClient()->request($method, $uri, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function createProduct(Plan $plan): array
    {
        return $this->request('POST', '/v1/catalogs/products', [
            'json' => [
                'name' => $plan->name,
                'description' => $plan->description ?? $plan->name,
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ],
        ]);
    }

    public function createPlan(Plan $plan, string $productId): array
    {
        $intervalUnit = match ($plan->billingInterval) {
            'day' => 'DAY',
            'week' => 'WEEK',
            'month' => 'MONTH',
            'year' => 'YEAR',
            default => 'MONTH',
        };

        $billingCycles = [
            [
                'frequency' => [
                    'interval_unit' => $intervalUnit,
                    'interval_count' => $plan->billingIntervalCount,
                ],
                'tenure_type' => 'REGULAR',
                'sequence' => 1,
                'total_cycles' => 0, // infinite
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => number_format($plan->price, 2, '.', ''),
                        'currency_code' => strtoupper($plan->currency),
                    ],
                ],
            ],
        ];

        if ($plan->trialDays > 0) {
            array_unshift($billingCycles, [
                'frequency' => [
                    'interval_unit' => 'DAY',
                    'interval_count' => $plan->trialDays,
                ],
                'tenure_type' => 'TRIAL',
                'sequence' => 1,
                'total_cycles' => 1,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'value' => '0.00',
                        'currency_code' => strtoupper($plan->currency),
                    ],
                ],
            ]);
            $billingCycles[1]['sequence'] = 2;
        }

        return $this->request('POST', '/v1/billing/plans', [
            'json' => [
                'product_id' => $productId,
                'name' => $plan->name,
                'description' => $plan->description ?? $plan->name,
                'billing_cycles' => $billingCycles,
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 3,
                ],
            ],
        ]);
    }

    public function createSubscription(Plan $plan, int $userId): array
    {
        $settings = Headcount::getInstance()->getSettings();
        $user = Craft::$app->getUsers()->getUserById($userId);
        $baseUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

        $params = [
            'plan_id' => $plan->paypalPlanId,
            'application_context' => [
                'brand_name' => Craft::$app->getSystemName(),
                'return_url' => $baseUrl . ltrim($settings->checkoutSuccessUrl, '/') . '?gateway=paypal',
                'cancel_url' => $baseUrl . ltrim($settings->checkoutCancelUrl, '/'),
                'user_action' => 'SUBSCRIBE_NOW',
            ],
            'custom_id' => json_encode([
                'craft_user_id' => $userId,
                'plan_id' => $plan->id,
            ]),
        ];

        if ($user && $user->email) {
            $params['subscriber'] = [
                'email_address' => $user->email,
                'name' => [
                    'given_name' => $user->firstName ?? '',
                    'surname' => $user->lastName ?? '',
                ],
            ];
        }

        $result = $this->request('POST', '/v1/billing/subscriptions', [
            'json' => $params,
        ]);

        $approvalUrl = '';
        foreach ($result['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
                break;
            }
        }

        return [
            'subscriptionId' => $result['id'] ?? null,
            'approvalUrl' => $approvalUrl,
        ];
    }

    public function cancelSubscription(string $subscriptionId, string $reason = 'Cancelled by user'): void
    {
        $this->request('POST', "/v1/billing/subscriptions/{$subscriptionId}/cancel", [
            'json' => [
                'reason' => $reason,
            ],
        ]);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/v1/billing/subscriptions/{$subscriptionId}");
    }

    public function syncPlan(Plan $plan): void
    {
        if (!$plan->paypalPlanId) {
            $product = $this->createProduct($plan);
            $paypalPlan = $this->createPlan($plan, $product['id']);
            $plan->paypalPlanId = $paypalPlan['id'];
            Headcount::getInstance()->plans->savePlan($plan);
        }
    }

    public function verifyWebhook(string $payload, $headers): bool
    {
        $settings = Headcount::getInstance()->getSettings();
        $webhookId = Craft::parseEnv($settings->paypalWebhookId);

        if (!$webhookId) {
            // If no webhook ID configured, accept all (not recommended for production)
            Craft::warning('PayPal webhook verification skipped: no webhook ID configured', 'headcount');
            return true;
        }

        try {
            $result = $this->request('POST', '/v1/notifications/verify-webhook-signature', [
                'json' => [
                    'webhook_id' => $webhookId,
                    'transmission_id' => $headers->get('paypal-transmission-id') ?? '',
                    'transmission_time' => $headers->get('paypal-transmission-time') ?? '',
                    'cert_url' => $headers->get('paypal-cert-url') ?? '',
                    'auth_algo' => $headers->get('paypal-auth-algo') ?? '',
                    'transmission_sig' => $headers->get('paypal-transmission-sig') ?? '',
                    'webhook_event' => json_decode($payload, true),
                ],
            ]);

            return ($result['verification_status'] ?? '') === 'SUCCESS';
        } catch (\Throwable $e) {
            Craft::error('PayPal webhook verification error: ' . $e->getMessage(), 'headcount');
            return false;
        }
    }
}
