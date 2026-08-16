<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class SettingsController extends Controller
{
    /**
     * The actions that only display settings. Everything else is assumed to change them.
     */
    private const READ_ONLY_ACTIONS = ['index', 'stripe', 'paypal', 'wallet', 'api', 'emails'];

    /**
     * Every action here either displays or writes gateway credentials — Stripe and PayPal
     * secrets, the webhook signing secret, and the REST API key — so the whole controller
     * is admin-only, matching Craft's own plugin settings screens. Without this, any user
     * who can log into the control panel (an author, say) could read the Stripe secret key
     * or POST a new one.
     *
     * Viewing stays available when `allowAdminChanges` is off; anything that writes does not.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Listed by what may be *read*, so anything added later is treated as a write until
        // it says otherwise. Naming the one write action instead would silently let the next
        // one through in an environment that forbids administrative changes.
        $this->requireAdmin(!in_array($action->id, self::READ_ONLY_ACTIONS, true));

        return true;
    }

    /**
     * Whether this environment forbids administrative changes.
     *
     * `beforeAction()` already refuses to *save* in that case. This is what lets each screen
     * say so up front rather than accepting input it will then reject.
     */
    private function isReadOnly(): bool
    {
        return !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function screen(string $template, array $variables = []): Response
    {
        return $this->renderTemplate($template, $variables + [
            'settings' => Headcount::getInstance()->getSettings(),
            'readOnly' => $this->isReadOnly(),
        ]);
    }

    public function actionIndex(): Response
    {
        return $this->screen('headcount/settings/index');
    }

    public function actionStripe(): Response
    {
        return $this->screen('headcount/settings/stripe');
    }

    public function actionPaypal(): Response
    {
        return $this->screen('headcount/settings/paypal');
    }

    public function actionWallet(): Response
    {
        return $this->screen('headcount/settings/wallet');
    }

    public function actionApi(): Response
    {
        return $this->screen('headcount/settings/api');
    }

    public function actionEmails(): Response
    {
        return $this->screen('headcount/settings/emails');
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Headcount::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        $settings->stripeSecretKey = $request->getBodyParam('stripeSecretKey', $settings->stripeSecretKey);
        $settings->stripePublishableKey = $request->getBodyParam('stripePublishableKey', $settings->stripePublishableKey);
        $settings->stripeWebhookSecret = $request->getBodyParam('stripeWebhookSecret', $settings->stripeWebhookSecret);
        $settings->stripeEnabled = (bool)$request->getBodyParam('stripeEnabled', $settings->stripeEnabled);

        $settings->paypalClientId = $request->getBodyParam('paypalClientId', $settings->paypalClientId);
        $settings->paypalClientSecret = $request->getBodyParam('paypalClientSecret', $settings->paypalClientSecret);
        $settings->paypalWebhookId = $request->getBodyParam('paypalWebhookId', $settings->paypalWebhookId);
        $settings->paypalSandbox = (bool)$request->getBodyParam('paypalSandbox', $settings->paypalSandbox);
        $settings->paypalEnabled = (bool)$request->getBodyParam('paypalEnabled', $settings->paypalEnabled);

        $settings->defaultCurrency = $request->getBodyParam('defaultCurrency', $settings->defaultCurrency);
        $settings->checkoutSuccessUrl = $request->getBodyParam('checkoutSuccessUrl', $settings->checkoutSuccessUrl);
        $settings->checkoutCancelUrl = $request->getBodyParam('checkoutCancelUrl', $settings->checkoutCancelUrl);
        $settings->loginUrl = $request->getBodyParam('loginUrl', $settings->loginUrl);
        $settings->pricingUrl = $request->getBodyParam('pricingUrl', $settings->pricingUrl);
        $settings->enforceAccessRules = (bool)$request->getBodyParam('enforceAccessRules', $settings->enforceAccessRules);

        $settings->sendWelcomeEmail = (bool)$request->getBodyParam('sendWelcomeEmail', $settings->sendWelcomeEmail);
        $settings->sendPaymentReceiptEmail = (bool)$request->getBodyParam('sendPaymentReceiptEmail', $settings->sendPaymentReceiptEmail);
        $settings->sendPaymentFailedEmail = (bool)$request->getBodyParam('sendPaymentFailedEmail', $settings->sendPaymentFailedEmail);
        $settings->sendExpirationReminderEmail = (bool)$request->getBodyParam('sendExpirationReminderEmail', $settings->sendExpirationReminderEmail);
        $settings->sendTrialEndingEmail = (bool)$request->getBodyParam('sendTrialEndingEmail', $settings->sendTrialEndingEmail);
        $settings->sendCancellationEmail = (bool)$request->getBodyParam('sendCancellationEmail', $settings->sendCancellationEmail);
        $settings->sendDripUnlockedEmail = (bool)$request->getBodyParam('sendDripUnlockedEmail', $settings->sendDripUnlockedEmail);
        $settings->expirationReminderDays = (int)$request->getBodyParam('expirationReminderDays', $settings->expirationReminderDays);

        $settings->walletEnabled = (bool)$request->getBodyParam('walletEnabled', $settings->walletEnabled);
        $settings->walletOrganizationName = $request->getBodyParam('walletOrganizationName', $settings->walletOrganizationName);
        $settings->walletDescription = $request->getBodyParam('walletDescription', $settings->walletDescription);
        $settings->walletBackgroundColor = $request->getBodyParam('walletBackgroundColor', $settings->walletBackgroundColor);
        $settings->walletForegroundColor = $request->getBodyParam('walletForegroundColor', $settings->walletForegroundColor);
        $settings->walletLabelColor = $request->getBodyParam('walletLabelColor', $settings->walletLabelColor);
        $settings->walletImagePath = $request->getBodyParam('walletImagePath', $settings->walletImagePath);

        $settings->appleWalletEnabled = (bool)$request->getBodyParam('appleWalletEnabled', $settings->appleWalletEnabled);
        $settings->applePassTypeIdentifier = $request->getBodyParam('applePassTypeIdentifier', $settings->applePassTypeIdentifier);
        $settings->appleTeamIdentifier = $request->getBodyParam('appleTeamIdentifier', $settings->appleTeamIdentifier);
        $settings->appleCertificatePath = $request->getBodyParam('appleCertificatePath', $settings->appleCertificatePath);
        $settings->appleCertificatePassword = $request->getBodyParam('appleCertificatePassword', $settings->appleCertificatePassword);
        $settings->appleWwdrCertificatePath = $request->getBodyParam('appleWwdrCertificatePath', $settings->appleWwdrCertificatePath);
        $settings->applePassUpdatesEnabled = (bool)$request->getBodyParam('applePassUpdatesEnabled', $settings->applePassUpdatesEnabled);

        $settings->googleWalletEnabled = (bool)$request->getBodyParam('googleWalletEnabled', $settings->googleWalletEnabled);
        $settings->googleWalletIssuerId = $request->getBodyParam('googleWalletIssuerId', $settings->googleWalletIssuerId);
        $settings->googleWalletServiceAccountPath = $request->getBodyParam('googleWalletServiceAccountPath', $settings->googleWalletServiceAccountPath);

        $settings->outgoingWebhookUrl = $request->getBodyParam('outgoingWebhookUrl', $settings->outgoingWebhookUrl);
        $settings->outgoingWebhookSecret = $request->getBodyParam('outgoingWebhookSecret', $settings->outgoingWebhookSecret);
        $settings->apiKey = $request->getBodyParam('apiKey', $settings->apiKey);

        // Routed through the plugin so it works both standalone (Craft's Plugins service)
        // and mounted inside a host bundle, where the host owns settings storage.
        if (!$plugin->saveSettings($settings->toArray())) {
            return $this->asFailure('Couldn\'t save settings.');
        }

        return $this->asSuccess('Settings saved.');
    }
}
