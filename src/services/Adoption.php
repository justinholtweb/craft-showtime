<?php

namespace justinholtweb\showtime\services;

use Craft;
use craft\base\Component;
use craft\db\Table;
use craft\helpers\Db;
use justinholtweb\showtime\Plugin;
use yii\base\Exception;

/**
 * Adoption — turning an existing standalone install into a mounted module, in place.
 *
 * This is the path a customer takes when they already own Owl, Stub, or Headcount and buy
 * the bundle. It is the single most dangerous operation in the product, because the obvious
 * move — `php craft plugin/uninstall stub` — runs Stub's Install migration **down** and
 * drops every booking they have.
 *
 * The insight that makes adoption safe and cheap: a mounted module reuses the standalone's
 * table names AND its `plugin:<handle>` migration track. So the data and the migration
 * history are already exactly where the mounted module expects them. All that has to change
 * is the row in the `plugins` table — that row is what makes Craft treat the plugin as
 * installed and separately licensable. Delete the row (and its project-config node), keep
 * everything else, and the standalone becomes a mounted module with no data movement at all.
 *
 * Adoption runs automatically from Showtime's Install migration, so `plugin/install showtime`
 * just works on a site that already runs the standalones.
 */
class Adoption extends Component
{
    /** @var array<string,array> handle => the settings captured before detaching */
    private array $captured = [];

    /** @var string[] handles whose project-config node still needs removing */
    private array $pendingConfigRemovals = [];

    /**
     * Is there a standalone install of this handle for us to adopt?
     */
    public function isInstalledStandalone(string $handle): bool
    {
        return Craft::$app->getPlugins()->isPluginInstalled($handle);
    }

    /**
     * Detach a standalone plugin from Craft without touching a byte of its data.
     *
     * Deliberately NOT done, in contrast to Craft's own uninstallPlugin():
     *   - the plugin's uninstall() (which would run its Install migration down → data gone)
     *   - deleting its `plugin:<handle>` migration history (the mounted module reuses it)
     *
     * Project-config removal and settings hand-over are deferred to finalize(), because
     * this runs inside installPlugin()'s DB transaction and Craft writes the host's own
     * `plugins.showtime` node *after* that transaction commits — writing settings here
     * would just be overwritten.
     *
     * @throws Exception if the installed schema is newer than the vendored copy
     */
    public function adopt(string $handle, string $vendoredSchemaVersion): void
    {
        $info = Craft::$app->getPlugins()->getStoredPluginInfo($handle);
        $installedSchemaVersion = $info['schemaVersion'] ?? '0.0.0';

        if (version_compare($installedSchemaVersion, $vendoredSchemaVersion, '>')) {
            throw new Exception(
                "Can't adopt “{$handle}”: the installed version's schema ($installedSchemaVersion) is " .
                "newer than the copy bundled with Showtime ($vendoredSchemaVersion). " .
                'Update Showtime first, then install it.'
            );
        }

        $this->captured[$handle] = Craft::$app->getProjectConfig()->get("plugins.$handle.settings") ?? [];

        Db::delete(Table::PLUGINS, ['handle' => $handle]);

        $this->pendingConfigRemovals[] = $handle;
    }

    /**
     * @return string[] handles adopted during this request
     */
    public function getAdoptedHandles(): array
    {
        return array_keys($this->captured);
    }

    public function hasPendingWork(): bool
    {
        return $this->captured !== [] || $this->pendingConfigRemovals !== [];
    }

    /**
     * Finish adoption once Showtime itself is installed: drop the adopted plugins'
     * project-config nodes and re-home their settings under the host.
     *
     * Called from the host's Plugins::EVENT_AFTER_INSTALL_PLUGIN listener — i.e. after
     * installPlugin() has committed and written `plugins.showtime`.
     */
    public function finalize(): void
    {
        if (!$this->hasPendingWork()) {
            return;
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $readOnly = $projectConfig->readOnly;
        $projectConfig->readOnly = false;

        foreach ($this->pendingConfigRemovals as $handle) {
            if ($projectConfig->get("plugins.$handle", true)) {
                // Braces are load-bearing: PHP treats the bytes of a curly quote as valid
                // identifier characters, so "“$handle”" parses as a variable named `handle”`.
                $projectConfig->remove("plugins.$handle", "Adopted “{$handle}” into Showtime");
            }
        }

        $projectConfig->readOnly = $readOnly;
        $this->pendingConfigRemovals = [];

        $this->rehomeSettings();
    }

    /**
     * Move each adopted plugin's stored settings into Showtime's per-module slice, so the
     * customer's Stripe keys, URLs and email toggles survive the switch.
     */
    private function rehomeSettings(): void
    {
        if ($this->captured === []) {
            return;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        foreach ($this->captured as $handle => $adopted) {
            if ($adopted === []) {
                continue;
            }

            // Anything already configured on the host wins — adoption fills gaps, it
            // doesn't clobber a deliberate bundle-level setting.
            $settings->$handle = array_merge($adopted, $settings->$handle ?? []);

            // Keep the live module consistent for the rest of this request.
            $plugin->getModuleByHandle($handle)?->setSettings($settings->$handle);
        }

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

        $this->captured = [];
    }
}
