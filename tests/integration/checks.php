<?php
/**
 * Showtime integration checks — the bundled Stub's deposits and pay-in-person, and the dashboard's
 * monthly revenue (bookings + event tickets + MRR).
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-showtime/tests/integration/checks.php
 *
 * The bundle's broader suite is `php craft showtime/test/run`; this file covers what 5.4.0 added.
 * Needs the harness's Stub fixtures (a provider), at least one Owl event, and Craft Commerce. No
 * network, and no order is put through Commerce's completion — the orders here are made the way a
 * checkout leaves them (completed, paid, with a paid date) so no sibling plugin's
 * order-completion handler runs.
 *
 * Idempotent and self-cleaning: every booking, service, ticket and order it creates it deletes
 * again, whether the run passes or not.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\owl\elements\Event as OwlEvent;
use justinholtweb\showtime\Plugin;
use justinholtweb\stub\models\Service;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

$showtime = Plugin::getInstance();

if ($showtime === null) {
    echo "Showtime isn't installed.\n";
    exit(1);
}

/** @var \justinholtweb\stub\Plugin|null $stub */
$stub = $showtime->getModuleByHandle('stub');
/** @var \justinholtweb\owl\Owl|null $owl */
$owl = $showtime->getModuleByHandle('owl');

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$emailPrefix = 'showtime-checks-';
$createdServiceIds = [];
$createdOrderIds = [];
$createdTicketIds = [];

register_shutdown_function(function() use ($emailPrefix, &$createdServiceIds, &$createdOrderIds, &$createdTicketIds) {
    $elements = Craft::$app->getElements();

    // Trashed ones too: one check trashes an order, and a by-ID delete can't find it then.
    foreach ($createdOrderIds as $orderId) {
        $order = Order::find()->id($orderId)->status(null)->trashed(null)->one();
        if ($order) {
            $elements->deleteElement($order, true);
        }
    }

    // Anything an interrupted earlier run left behind.
    foreach (Order::find()->email($emailPrefix . '*')->status(null)->trashed(null)->isCompleted(null)->all() as $order) {
        $elements->deleteElement($order, true);
    }

    foreach ($createdTicketIds as $ticketId) {
        $elements->deleteElementById($ticketId, null, null, true);
    }

    foreach ((new Query())->select(['id'])->from('{{%stub_customers}}')->where(['like', 'email', $emailPrefix])->column() as $customerId) {
        foreach ((new Query())->select(['id'])->from('{{%stub_bookings}}')->where(['customerId' => $customerId])->column() as $bookingId) {
            $elements->deleteElementById((int)$bookingId, null, null, true);
        }
        Craft::$app->getDb()->createCommand()->delete('{{%stub_customers}}', ['id' => $customerId])->execute();
    }

    foreach ($createdServiceIds as $serviceId) {
        Craft::$app->getDb()->createCommand()->delete('{{%stub_services}}', ['id' => $serviceId])->execute();
    }

    echo "  cleaned\n";
});

// -------------------------------------------------------------------------------------------
section('The bundled Stub');

check('Stub is mounted', fn() => $stub !== null ?: 'not mounted');

check('the mounted Stub is the same code as the craft-stub repo', function() {
    $canonical = '/var/www/craft-stub/src';
    $mounted = dirname(__DIR__, 2) . '/src/modules/stub';

    if (!is_dir($canonical)) {
        return "$canonical isn't mounted in this container";
    }

    $hashes = static function(string $dir): array {
        $out = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getFilename() !== '.DS_Store') {
                $out[substr($file->getPathname(), strlen($dir))] = sha1_file($file->getPathname());
            }
        }
        ksort($out);
        return $out;
    };

    $a = $hashes($canonical);
    $b = $hashes($mounted);
    $differ = array_keys(array_diff_assoc($a, $b) + array_diff_assoc($b, $a));

    return $differ === [] ?: 'differs: ' . implode(', ', array_slice($differ, 0, 8));
});

check('both sync migrations ran — the host’s and the module’s', function() {
    $rows = (new Query())->select(['track', 'name'])->from('{{%migrations}}')
        ->where(['name' => ['m261008_000000_sync_stub_payment_modes', 'm261008_000000_payment_modes']])
        ->all();
    $tracks = array_column($rows, 'track', 'name');

    return ($tracks['m261008_000000_sync_stub_payment_modes'] ?? null) === 'plugin:showtime'
        && ($tracks['m261008_000000_payment_modes'] ?? null) === 'plugin:stub'
        ?: json_encode($rows);
});

check('“Record payments” is listed under the Showtime permission heading', function() {
    foreach (Craft::$app->getUserPermissions()->getAllPermissions() as $group) {
        if ($group['heading'] === 'Showtime') {
            return array_key_exists('stub:recordPayments', $group['permissions']) ?: 'missing from the Showtime heading';
        }
    }
    return 'no Showtime heading';
});

// Fixtures for the bookings half.
$customer = $stub?->customers->findOrCreate("{$emailPrefix}{$run}@example.com", 'Showtime', 'Checks', null);
$providerId = (int)(new Query())->select('id')->from('{{%stub_providers}}')->where(['enabled' => true, 'dateDeleted' => null])->scalar();

$makeService = function(string $mode, float $price, float $deposit = 0) use ($stub, $run, &$createdServiceIds): ?Service {
    $service = new Service([
        'name' => "Showtime checks $mode $run",
        'handle' => "showtimeChecks{$mode}{$run}",
        'duration' => 30, 'price' => $price, 'currency' => 'USD',
        'paymentMode' => $mode, 'depositType' => 'percent', 'depositValue' => $deposit,
        'enabled' => false,
    ]);

    if (!$stub?->services->saveService($service)) {
        return null;
    }
    $createdServiceIds[] = $service->id;

    return $service;
};

$dayOffset = 320;
$book = function(Service $service) use ($stub, $customer, $providerId, &$dayOffset) {
    $start = new DateTime('+' . ($dayOffset++) . ' days 16:00', new DateTimeZone('UTC'));

    return $stub->bookings->createBooking([
        'serviceId' => $service->id, 'providerId' => $providerId, 'customerId' => $customer->id,
        'startDateTime' => $start->format('Y-m-d H:i:s'),
        'endDateTime' => (clone $start)->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
    ]);
};

$depositService = $makeService('deposit', 200, 25);
$inPersonService = $makeService('inPerson', 40);

check('a deposit service books through the bundle with only the deposit due online', function() use ($book, $depositService) {
    if (!$depositService) {
        return 'fixture service didn’t save';
    }
    $booking = $book($depositService);

    return $booking->bookingStatus === 'pending' && (float)$booking->depositAmount === 50.0 && $booking->getAmountDueOnline() === 50.0
        ?: json_encode([$booking->getErrors(), $booking->bookingStatus, $booking->depositAmount]);
});

check('a pay-in-person service books confirmed and unpaid', function() use ($book, $inPersonService) {
    $booking = $book($inPersonService);

    return $booking->bookingStatus === 'confirmed' && $booking->paymentStatus === 'unpaid' ?: json_encode([$booking->bookingStatus, $booking->paymentStatus]);
});

// -------------------------------------------------------------------------------------------
section('Dashboard: bookings revenue is cash received');

$dashboard = $showtime->dashboard;

check('a deposit paid last month counts there; its balance, paid now, counts this month', function() use ($dashboard, $stub, $book, $depositService) {
    $before = $dashboard->getStats();
    $booking = $book($depositService);

    $stub->payments->recordManualPayment($booking, 50.0);
    $lastMonth = (new DateTime('first day of last month 12:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    Craft::$app->getDb()->createCommand()->update('{{%stub_payments}}', ['paidAt' => $lastMonth], ['bookingId' => $booking->id])->execute();

    $stub->payments->recordManualPayment($stub->bookings->getBookingById($booking->id));
    $after = $dashboard->getStats();

    $bookingDelta = round($after['bookings']['monthlyRevenue'] - $before['bookings']['monthlyRevenue'], 2);
    $combinedDelta = round($after['combinedMonthly'] - $before['combinedMonthly'], 2);

    return $bookingDelta === 150.0 && $combinedDelta === 150.0 ?: json_encode([$bookingDelta, $combinedDelta]);
});

// -------------------------------------------------------------------------------------------
section('Dashboard: event ticket revenue');

$commerceReady = $owl !== null && $owl->commerceAvailable();

check('Owl ticketing is running (Owl Pro + Commerce)', fn() => $commerceReady ?: 'Owl not mounted, or no Commerce — ticket revenue is null by design');

if (!$commerceReady) {
    check('with no ticketing, the dashboard reports no ticket figures', fn() => $dashboard->getStats()['tickets'] === null);
    echo "\n$passed passed, $failed failed\n";
    exit($failed ? 1 : 0);
}

/** @var OwlEvent|null $event */
$event = OwlEvent::find()->status(null)->one();
$ticket = null;

if ($event) {
    $ticket = $owl->tickets->createTicket($event, "Checks $run", 25.0);
    if ($ticket->id) {
        $createdTicketIds[] = $ticket->id;
    }
}

/**
 * An order for $qty of the test ticket, left the way a completed checkout leaves it.
 */
$order = function(int $qty, string $paidStatus, ?DateTime $paidAt) use ($ticket, $emailPrefix, $run, &$createdOrderIds): Order {
    $order = new Order();
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->email = "{$emailPrefix}order-{$run}@example.com";
    $order->addLineItem(Commerce::getInstance()->getLineItems()->create($order, ['purchasableId' => $ticket->id, 'qty' => $qty]));

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('order didn’t save: ' . json_encode($order->getErrors()));
    }
    $createdOrderIds[] = $order->id;

    $utc = new DateTimeZone('UTC');
    Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', [
        'isCompleted' => true,
        'paidStatus' => $paidStatus,
        'dateOrdered' => (new DateTime('now', $utc))->format('Y-m-d H:i:s'),
        'datePaid' => $paidAt?->setTimezone($utc)->format('Y-m-d H:i:s'),
    ], ['id' => $order->id])->execute();

    return $order;
};

check('a ticket was created on an existing event', fn() => $ticket?->id ? true : 'no Owl event, or the ticket didn’t save: ' . json_encode($ticket?->getErrors()));

$baseline = $dashboard->ticketStats();
$baseCombined = $dashboard->getStats()['combinedMonthly'];

check('a paid ticket order this month counts: revenue, tickets and registrations', function() use ($order, $dashboard, $baseline) {
    $placed = $order(2, 'paid', new DateTime('now'));
    $total = (float)(new Query())->from('{{%commerce_lineitems}}')->where(['orderId' => $placed->id])->sum('total');
    $stats = $dashboard->ticketStats();

    return $total === 50.0
        && round($stats['monthlyRevenue'] - $baseline['monthlyRevenue'], 2) === 50.0
        && $stats['monthlyTickets'] - $baseline['monthlyTickets'] === 2
        && $stats['monthlyRegistrations'] - $baseline['monthlyRegistrations'] === 1
        ?: json_encode(['lineTotal' => $total, 'before' => $baseline, 'after' => $stats]);
});

check('the combined monthly figure includes ticket revenue', function() use ($dashboard, $baseCombined) {
    $stats = $dashboard->getStats();
    $sum = (float)($stats['bookings']['monthlyRevenue'] ?? 0) + (float)($stats['tickets']['monthlyRevenue'] ?? 0) + (float)($stats['memberships']['mrr'] ?? 0);

    return abs($stats['combinedMonthly'] - $sum) < 0.001 && round($stats['combinedMonthly'] - $baseCombined, 2) === 50.0
        ?: json_encode([$stats['combinedMonthly'], $sum, $baseCombined]);
});

check('an order paid last month doesn’t count this month', function() use ($order, $dashboard) {
    $before = $dashboard->ticketStats();
    $order(1, 'paid', new DateTime('first day of last month 12:00', new DateTimeZone('UTC')));

    return $dashboard->ticketStats() == $before ?: json_encode($dashboard->ticketStats());
});

check('a completed order that hasn’t been paid isn’t cash received', function() use ($order, $dashboard) {
    $before = $dashboard->ticketStats();
    $order(3, 'unpaid', null);

    return $dashboard->ticketStats() == $before ?: json_encode($dashboard->ticketStats());
});

check('a trashed order drops out', function() use ($dashboard, $baseline, &$createdOrderIds) {
    Craft::$app->getElements()->deleteElementById($createdOrderIds[0], Order::class);
    $stats = $dashboard->ticketStats();

    return round($stats['monthlyRevenue'] - $baseline['monthlyRevenue'], 2) === 0.0 ?: json_encode($stats);
});

check('the dashboard shows the ticket line to someone who can see revenue', function() use ($order) {
    $order(1, 'paid', new DateTime('now'));

    $admin = User::find()->id(1)->status(null)->one();
    $admin->newPassword = 'claudepassword';
    Craft::$app->getElements()->saveElement($admin, false);

    $client = new Client(['base_uri' => 'http://127.0.0.1/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = (string)(json_decode((string)$client->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = json_decode((string)$client->post('index.php?p=actions/users/login', [
        'headers' => $json,
        'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $csrf],
    ])->getBody(), true);

    if (!isset($login['user'])) {
        return 'couldn’t sign admin in';
    }

    $response = $client->get('admin/showtime');
    $html = (string)$response->getBody();

    return $response->getStatusCode() === 200 && str_contains($html, 'Event tickets this month') && str_contains($html, 'Tickets sold this month')
        ?: 'status ' . $response->getStatusCode();
});

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
