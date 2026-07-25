<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\mail\Message;
use justinholtweb\showtime\Plugin;
use yii\mail\MailEvent;

/**
 * One outgoing identity, and one place to see every email the bundle sends.
 *
 * Both halves of §7.3 of the plan. Deliberately *not* a rendering layer: every bundled
 * plugin's emails are Craft system messages, so their copy is already editable in the
 * control panel and their HTML already comes from whatever email template the site has
 * configured under Settings → Email. Adding a second wrapper on top would give bundle
 * emails a different look from the rest of the site's — the opposite of the point.
 *
 * What was actually missing was that the from-name and from-address had to be right in
 * three places, and that the on/off switches lived on three different screens.
 */
class Notifications extends Component
{
    /**
     * Stamp the bundle's shared from-identity onto an outgoing message.
     *
     * Hooked to the mailer rather than injected into each module: it catches every send
     * path, including ones added later, and none of the three plugins learns that a host
     * exists. Craft only applies its own default sender when the message has none
     * ({@see \craft\mail\Mailer::send()}), so setting it here wins without fighting.
     *
     * A message that already carries an explicit From is left alone — that's someone being
     * deliberate, and overriding it would be the bundle overreaching.
     */
    public function applyFromIdentity(MailEvent $event): void
    {
        $message = $event->message;

        if (!$message instanceof Message || $message->key === null) {
            return;
        }

        if (!array_key_exists($message->key, $this->definitions()) || $message->getFrom()) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $email = App::parseEnv($settings->emailFromEmail);
        $name = App::parseEnv($settings->emailFromName);

        if (!$email) {
            // Nothing configured — Craft's system from-address is the right fallback, and
            // sending from a blank address would just bounce.
            return;
        }

        $message->setFrom($name ? [$email => $name] : $email);
    }

    /**
     * Every email the bundle can send, in module order.
     *
     * @return array<string, array{module: string, moduleLabel: string, heading: string, description: string, setting: string, enabled: bool, variables: string[], subject: string, body: string}>
     */
    public function definitions(): array
    {
        $labels = [
            'stub' => Craft::t('showtime', 'Bookings'),
            'headcount' => Craft::t('showtime', 'Memberships'),
            'owl' => Craft::t('showtime', 'Events'),
        ];

        $all = [];

        // Iterated over MODULES rather than the mounted instances so the class name is a
        // literal — same shape as Plugin::registerPermissions(), and static analysis can
        // actually see the method.
        foreach (Plugin::MODULES as $handle => $class) {
            $module = Plugin::getInstance()->getModuleByHandle($handle);

            if ($module === null || !method_exists($class, 'emailDefinitions')) {
                continue;
            }

            $settings = $module->getSettings();

            foreach ($class::emailDefinitions() as $key => $definition) {
                $all[$key] = [
                    'module' => $handle,
                    'moduleLabel' => $labels[$handle],
                    'heading' => $definition['heading'],
                    'description' => $definition['description'],
                    'setting' => $definition['setting'],
                    'enabled' => (bool)($settings->{$definition['setting']} ?? false),
                    'variables' => $definition['variables'],
                    'subject' => $definition['subject'],
                    'body' => $definition['body'],
                ];
            }
        }

        return $all;
    }

    /**
     * Turn messages on and off from one screen.
     *
     * Writes each module's whole resolved settings back through the host's saver rather than
     * just the changed keys — `saveModuleSettings()` is built for exactly that, and
     * `Settings::withoutShared()` strips anything inherited before storing, so a round-trip
     * here can't freeze a copy of the shared Stripe key into a module's overrides.
     *
     * @param array<string, bool> $enabled message key => on/off
     */
    public function save(array $enabled): bool
    {
        $definitions = $this->definitions();
        $byModule = [];

        foreach ($definitions as $key => $definition) {
            $byModule[$definition['module']][$definition['setting']] = (bool)($enabled[$key] ?? false);
        }

        $showtime = Plugin::getInstance();

        foreach ($byModule as $handle => $changes) {
            $module = $showtime->getModuleByHandle($handle);

            if ($module === null) {
                continue;
            }

            if (!$showtime->saveModuleSettings($handle, array_merge($module->getSettings()->toArray(), $changes))) {
                return false;
            }
        }

        return true;
    }
}
