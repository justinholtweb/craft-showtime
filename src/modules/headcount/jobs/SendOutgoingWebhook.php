<?php

namespace justinholtweb\headcount\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\headcount\Headcount;

/**
 * Delivers a signed outgoing webhook to the configured endpoint.
 *
 * Payload shape: { "event": ..., "data": {...}, "timestamp": <unix> }
 * Signed with HMAC-SHA256 over the raw body in the X-Headcount-Signature header
 * when an outgoing webhook secret is configured.
 */
class SendOutgoingWebhook extends BaseJob
{
    public string $event = '';
    public array $data = [];

    public function execute($queue): void
    {
        $settings = Headcount::getInstance()->getSettings();

        if (!$settings->outgoingWebhookUrl) {
            return;
        }

        $payload = json_encode([
            'event' => $this->event,
            'data' => $this->data,
            'timestamp' => time(),
        ]);

        $signature = '';
        if ($settings->outgoingWebhookSecret) {
            $signature = hash_hmac('sha256', $payload, $settings->outgoingWebhookSecret);
        }

        try {
            $client = Craft::createGuzzleClient();
            $client->post($settings->outgoingWebhookUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Headcount-Signature' => $signature,
                    'X-Headcount-Event' => $this->event,
                ],
                'body' => $payload,
                'timeout' => 10,
            ]);
        } catch (\Throwable $e) {
            Craft::error("Outgoing webhook failed for event {$this->event}: " . $e->getMessage(), 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Sending Headcount webhook: {$this->event}";
    }
}
