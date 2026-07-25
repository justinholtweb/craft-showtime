<?php

namespace justinholtweb\stub\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\stub\Plugin;
use yii\web\Response;

class CustomersController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requirePermission('stub:manageCustomers');
        return true;
    }

    public function actionIndex(): Response
    {
        $search = Craft::$app->getRequest()->getParam('q');
        $customers = $search
            ? Plugin::getInstance()->customers->searchCustomers($search)
            : Plugin::getInstance()->customers->getAllCustomers();

        return $this->renderTemplate('stub/customers/_index', [
            'customers' => $customers,
            'search' => $search,
            'selectedSubnavItem' => 'customers',
        ]);
    }

    public function actionDetail(int $customerId): Response
    {
        $customer = Plugin::getInstance()->customers->getCustomerById($customerId);
        if (!$customer) {
            throw new \yii\web\NotFoundHttpException('Customer not found.');
        }

        $bookings = Plugin::getInstance()->customers->getBookingsForCustomer($customerId);

        return $this->renderTemplate('stub/customers/_detail', [
            'customer' => $customer,
            'bookings' => $bookings,
            'title' => $customer->getFullName(),
            'selectedSubnavItem' => 'customers',
        ]);
    }
}
