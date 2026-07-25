<?php

namespace justinholtweb\headcount\console\controllers;

use craft\console\Controller;
use justinholtweb\headcount\Headcount;
use yii\console\ExitCode;

class SyncController extends Controller
{
    /**
     * Sync plans with payment providers (Stripe and PayPal).
     *
     * Usage: php craft headcount/sync/plans
     */
    public function actionPlans(): int
    {
        $this->stdout("Syncing plans with payment providers...\n");

        $plans = Headcount::getInstance()->plans->getAllPlans(true);
        $settings = Headcount::getInstance()->getSettings();

        foreach ($plans as $plan) {
            $this->stdout("  {$plan->name} ({$plan->handle})...");

            if ($settings->stripeEnabled && !$plan->stripePriceId) {
                try {
                    Headcount::getInstance()->stripe->syncPlan($plan);
                    $this->stdout(" Stripe OK");
                } catch (\Throwable $e) {
                    $this->stderr(" Stripe FAILED: {$e->getMessage()}");
                }
            } elseif ($plan->stripePriceId) {
                $this->stdout(" Stripe: already synced");
            }

            if ($settings->paypalEnabled && !$plan->paypalPlanId) {
                try {
                    Headcount::getInstance()->paypal->syncPlan($plan);
                    $this->stdout(" PayPal OK");
                } catch (\Throwable $e) {
                    $this->stderr(" PayPal FAILED: {$e->getMessage()}");
                }
            } elseif ($plan->paypalPlanId) {
                $this->stdout(" PayPal: already synced");
            }

            $this->stdout("\n");
        }

        $this->stdout("Plan sync complete.\n");
        return ExitCode::OK;
    }

    /**
     * Check sync health status.
     *
     * Usage: php craft headcount/sync/status
     */
    public function actionStatus(): int
    {
        $settings = Headcount::getInstance()->getSettings();
        $plans = Headcount::getInstance()->plans->getAllPlans();

        $this->stdout("=== Headcount Sync Status ===\n\n");

        // Gateway status
        $this->stdout("Gateways:\n");
        $this->stdout("  Stripe: " . ($settings->stripeEnabled ? 'Enabled' : 'Disabled') . "\n");
        $this->stdout("  PayPal: " . ($settings->paypalEnabled ? 'Enabled' : 'Disabled') . "\n\n");

        // Plan sync status
        $this->stdout("Plans:\n");
        foreach ($plans as $plan) {
            $stripeStatus = $plan->stripePriceId ? 'synced' : 'NOT SYNCED';
            $paypalStatus = $plan->paypalPlanId ? 'synced' : 'NOT SYNCED';

            $this->stdout("  {$plan->name}: Stripe={$stripeStatus}, PayPal={$paypalStatus}\n");
        }

        // Stats
        $this->stdout("\nStats:\n");
        $stats = Headcount::getInstance()->reporting->getDashboardStats();
        $this->stdout("  Active members: {$stats['activeMembers']}\n");
        $this->stdout("  MRR: \${$stats['mrr']}\n");
        $this->stdout("  Churn rate: {$stats['churnRate']}%\n");
        $this->stdout("  Trial conversion: {$stats['trialConversionRate']}%\n");

        return ExitCode::OK;
    }
}
