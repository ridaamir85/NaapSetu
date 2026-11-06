<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_customer_order(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$ordersStatement = $connection->prepare(
    'SELECT o.order_id, o.status, o.confirmed_at, p.shop_name, s.garment_type '
    . 'FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = r.tailor_id '
    . 'INNER JOIN tailor_services AS s '
    . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
    . 'WHERE r.customer_id = :customer_id '
    . 'ORDER BY o.confirmed_at DESC, o.order_id DESC'
);
$ordersStatement->execute(['customer_id' => $user['user_id']]);
$orders = $ordersStatement->fetchAll();

$historyStatement = $connection->prepare(
    'SELECT status, changed_at FROM order_status_history '
    . 'WHERE order_id = :order_id ORDER BY history_id ASC'
);
foreach ($orders as &$order) {
    $historyStatement->execute(['order_id' => $order['order_id']]);
    $order['history'] = $historyStatement->fetchAll();
}
unset($order);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My orders | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>My orders</h1>

        <?php if ($orders === []): ?>
            <p>You have no confirmed orders yet. An order appears here after you accept a quotation.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <section>
                    <h2>Order #<?= (int) $order['order_id'] ?> — <?= escape_customer_order($order['garment_type']) ?></h2>
                    <p>Tailor: <?= escape_customer_order($order['shop_name']) ?></p>
                    <p>Status: <?= escape_customer_order($order['status']) ?></p>
                    <h3>Progress history</h3>
                    <?php if ($order['history'] === []): ?>
                        <p>No status updates have been recorded yet.</p>
                    <?php else: ?>
                        <ol>
                            <?php foreach ($order['history'] as $entry): ?>
                                <li>
                                    <?= escape_customer_order($entry['status']) ?> —
                                    <time datetime="<?= escape_customer_order($entry['changed_at']) ?>"><?= escape_customer_order($entry['changed_at']) ?></time>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
