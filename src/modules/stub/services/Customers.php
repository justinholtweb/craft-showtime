<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use justinholtweb\stub\models\Customer;
use justinholtweb\stub\Plugin;
use justinholtweb\stub\records\CustomerRecord;
use yii\base\Component;

class Customers extends Component
{
    public function getCustomerById(int $id): ?Customer
    {
        $row = $this->_createQuery()
            ->where(['id' => $id])
            ->one();

        return $row ? $this->_createCustomerFromRow($row) : null;
    }

    public function getCustomerByEmail(string $email): ?Customer
    {
        $row = $this->_createQuery()
            ->where(['email' => $email])
            ->one();

        return $row ? $this->_createCustomerFromRow($row) : null;
    }

    public function getAllCustomers(): array
    {
        return array_map(
            fn($row) => $this->_createCustomerFromRow($row),
            $this->_createQuery()->orderBy(['dateCreated' => SORT_DESC])->all()
        );
    }

    /**
     * The Craft user behind a customer, if there is one.
     *
     * A Stub customer is its own record and only optionally linked to a user, so this falls
     * back to matching on email: someone who booked as a guest using the address their
     * account uses is still that person. The fallback is read-only — it works out who they
     * are without writing a link, which stays the site's call (`linkCustomersToUsers`).
     */
    public function resolveUser(Customer $customer): ?User
    {
        if ($customer->userId) {
            $user = Craft::$app->getUsers()->getUserById($customer->userId);

            if ($user !== null) {
                return $user;
            }
        }

        return $customer->email
            ? Craft::$app->getUsers()->getUserByUsernameOrEmail($customer->email)
            : null;
    }

    /**
     * The customer record for a Craft user, by link or by email.
     */
    public function getCustomerForUser(User $user): ?Customer
    {
        $row = $this->_createQuery()->where(['userId' => $user->id])->one();

        if ($row) {
            return $this->_createCustomerFromRow($row);
        }

        return $user->email ? $this->getCustomerByEmail($user->email) : null;
    }

    public function findOrCreate(string $email, string $firstName, string $lastName, ?string $phone = null): Customer
    {
        $existing = $this->getCustomerByEmail($email);
        if ($existing) {
            $changed = false;

            // Update name if changed
            if ($existing->firstName !== $firstName || $existing->lastName !== $lastName) {
                $existing->firstName = $firstName;
                $existing->lastName = $lastName;
                if ($phone) {
                    $existing->phone = $phone;
                }
                $changed = true;
            }

            // Someone who registered an account after their first booking should stop being
            // two separate people the next time they book.
            if (!$existing->userId && $this->_attachUser($existing)) {
                $changed = true;
            }

            if ($changed) {
                $this->saveCustomer($existing);
            }

            return $existing;
        }

        $customer = new Customer([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phone,
        ]);

        $this->_attachUser($customer);

        $this->saveCustomer($customer);
        return $customer;
    }

    /**
     * Link a customer to the Craft user with the same email address.
     *
     * @return bool whether a link was made
     */
    private function _attachUser(Customer $customer): bool
    {
        if (!Plugin::getInstance()->getSettings()->linkCustomersToUsers || !$customer->email) {
            return false;
        }

        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($customer->email);

        if ($user === null) {
            return false;
        }

        $customer->userId = $user->id;

        return true;
    }

    public function saveCustomer(Customer $customer): bool
    {
        if (!$customer->validate()) {
            return false;
        }

        if ($customer->id) {
            $record = CustomerRecord::findOne($customer->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new CustomerRecord();
        }

        $record->userId = $customer->userId;
        $record->email = $customer->email;
        $record->firstName = $customer->firstName;
        $record->lastName = $customer->lastName;
        $record->phone = $customer->phone;
        $record->notes = $customer->notes;

        if (!$record->save()) {
            $customer->addErrors($record->getErrors());
            return false;
        }

        $customer->id = $record->id;
        $customer->uid = $record->uid;
        $customer->dateCreated = $record->dateCreated;
        $customer->dateUpdated = $record->dateUpdated;

        return true;
    }

    public function getBookingsForCustomer(int $customerId): array
    {
        return \justinholtweb\stub\elements\Booking::find()
            ->customerId($customerId)
            ->orderBy(['startDateTime' => SORT_DESC])
            ->all();
    }

    public function searchCustomers(string $query): array
    {
        return array_map(
            fn($row) => $this->_createCustomerFromRow($row),
            $this->_createQuery()
                ->where(['or',
                    ['like', 'email', $query],
                    ['like', 'firstName', $query],
                    ['like', 'lastName', $query],
                    ['like', 'phone', $query],
                ])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(50)
                ->all()
        );
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select(['id', 'userId', 'email', 'firstName', 'lastName', 'phone', 'notes', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_customers}}');
    }

    private function _createCustomerFromRow(array $row): Customer
    {
        return new Customer([
            'id' => (int)$row['id'],
            'userId' => $row['userId'] ? (int)$row['userId'] : null,
            'email' => $row['email'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'phone' => $row['phone'],
            'notes' => $row['notes'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]);
    }
}
