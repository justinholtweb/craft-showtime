<?php

namespace justinholtweb\headcount\jobs;

use Craft;
use craft\queue\BaseJob;

/**
 * Deliver one member lifecycle email.
 *
 * The message is a Craft system message, so the mailer renders its subject and body from
 * whatever copy the site has set, through whatever HTML email template the site has
 * configured. The job carries only the key and a scalar variable set — which is all a
 * serialized queue payload should hold.
 */
class SendMemberEmail extends BaseJob
{
    /** Craft system-message key, e.g. `headcount_welcome`. */
    public string $key = '';

    public string $to = '';

    /** @var array<string, mixed> */
    public array $variables = [];

    public function execute($queue): void
    {
        $message = Craft::$app->getMailer()
            ->composeFromKey($this->key, $this->variables)
            ->setTo($this->to);

        if (!$message->send()) {
            Craft::error("Failed to send Headcount email '$this->key' to $this->to", 'headcount');
        }
    }

    protected function defaultDescription(): ?string
    {
        return "Sending Headcount email to $this->to";
    }
}
