<?php

namespace justinholtweb\headcount\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\headcount\Headcount;
use yii\web\Response;

class SettingsController extends Controller
{
    /**
     * Every action here either displays or writes gateway credentials — Stripe and PayPal
     * secrets, the webhook signing secret, and the REST API key — so the whole controller
     * is admin-only, matching Craft's own plugin settings screens. Without this, any user
     * who can log into the control panel (an author, say) could read the Stripe secret key
     * or POST a new one.
     *
     * Viewing stays available when `allowAdminChanges` is off; saving does not.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin($action->id === 'save');

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('headcount/settings/index', [
            'settings' => Headcount::getInstance()->getSettings(),
        ]);
    }

    public function actionStripe(): Response
    {
        return $this->renderTemplate('headcount/settings/stripe', [
            'settings' => Headcount::getInstance()->getSettings(),
        ]);
    }

    public function actionPaypal(): Response
    {
        return $this->renderTemplate('headcount/settings/paypal', [
            'settings' => Headcount::getInstance()->getSettings(),
        ]);
    }

    public function actionWallet(): Response
    {
        return $this->renderTemplate('headcount/settings/wallet', [
            'settings' => Headcount::getInstance()->getSettings(),
        ]);
    }

    public function actionEmails(): Response
    {
        return $this->renderTemplate('headcount/settings/emails', [
            'settings' => Headcount::getInstance()->getSettings(),
        ]);
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
