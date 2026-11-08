<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';

$user = require_role('tailor');
$connection = database();
$profile = tailor_profile_for_user($connection, (int) $user['user_id']);

if ($profile === null) {
    redirect_to('/tailor/profile.php');
}

function escape_tailor_dashboard(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tailor_dashboard_date(?string $value): string
{
    if ($value === null || $value === '') {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable) {
        return 'Not set';
    }
}

$tailorId = (int) $profile['tailor_id'];
$activeStatuses = ['confirmed', 'cutting', 'stitching', 'ready'];
$statusPlaceholders = implode(', ', array_fill(0, count($activeStatuses), '?'));

$newRequestsStatement = $connection->prepare(
    'SELECT COUNT(*) FROM order_requests '
    . 'WHERE tailor_id = :tailor_id AND status = :status'
);
$newRequestsStatement->execute(['tailor_id' => $tailorId, 'status' => 'pending']);
$newRequestCount = (int) $newRequestsStatement->fetchColumn();

$activeOrdersStatement = $connection->prepare(
    'SELECT COUNT(*) FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'WHERE r.tailor_id = ? AND o.status IN (' . $statusPlaceholders . ')'
);
$activeOrdersStatement->execute([$tailorId, ...$activeStatuses]);
$activeOrderCount = (int) $activeOrdersStatement->fetchColumn();
$orderCapacity = (int) $profile['max_active_orders'];

$dueSoonCountStatement = $connection->prepare(
    'SELECT COUNT(*) FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'WHERE r.tailor_id = ? AND o.status IN (' . $statusPlaceholders . ') '
    . 'AND q.deadline IS NOT NULL '
    . 'AND q.deadline BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)'
);
$dueSoonCountStatement->execute([$tailorId, ...$activeStatuses]);
$dueSoonOrderCount = (int) $dueSoonCountStatement->fetchColumn();

$dueSoonOrdersStatement = $connection->prepare(
    'SELECT o.order_id, o.status, u.name AS customer_name, s.garment_type, q.deadline '
    . 'FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
    . 'INNER JOIN tailor_services AS s '
    . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
    . 'WHERE r.tailor_id = ? AND o.status IN (' . $statusPlaceholders . ') '
    . 'AND (q.deadline IS NULL OR q.deadline BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY)) '
    . 'ORDER BY q.deadline IS NULL, q.deadline ASC, o.confirmed_at ASC, o.order_id ASC'
);
$dueSoonOrdersStatement->execute([$tailorId, ...$activeStatuses]);
$dueSoonOrders = $dueSoonOrdersStatement->fetchAll();

$customerSection = 'home';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tailor Dashboard | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-navigation.css">
    <link rel="stylesheet" href="/assets/css/tailor-dashboard.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="tailor-dashboard-page">
    <a class="skip-link" href="#main">Skip to dashboard</a>
    <div class="tailor-dashboard-shell">
        <?php $tailorSection = 'dashboard'; require dirname(__DIR__, 2) . '/includes/tailor-navigation.php'; ?>

        <main id="main" class="tailor-dashboard-main">
            <header class="tailor-dashboard-header">
                <div>
                    <p class="dashboard-eyebrow">Tailor workspace</p>
                    <h1>Welcome, <?= escape_tailor_dashboard($user['name']) ?></h1>
                    <p class="dashboard-shop-name"><?= escape_tailor_dashboard($profile['shop_name']) ?> <span aria-hidden="true">·</span> <?= escape_tailor_dashboard($profile['city']) ?></p>
                </div>
                <a class="shop-profile-link" href="/tailor/profile.php">Manage shop profile <span aria-hidden="true">→</span></a>
            </header>

            <section class="tailor-dashboard-stats" aria-label="Shop overview">
                <a class="tailor-stat-card" href="/tailor/requests.php">
                    <span class="stat-icon stat-icon-requests" aria-hidden="true">▤</span>
                    <span class="stat-copy"><span class="stat-label">New requests</span><strong><?= $newRequestCount ?></strong><span class="stat-caption">Awaiting your quotation</span></span>
                    <span class="stat-arrow" aria-hidden="true">→</span>
                </a>
                <a class="tailor-stat-card" href="/tailor/orders.php">
                    <span class="stat-icon stat-icon-orders" aria-hidden="true">□</span>
                    <span class="stat-copy"><span class="stat-label">Active orders</span><strong><?= $activeOrderCount ?><span class="stat-capacity"> / <?= $orderCapacity ?></span></strong><span class="stat-caption">Current shop capacity</span></span>
                    <span class="stat-arrow" aria-hidden="true">→</span>
                </a>
                <a class="tailor-stat-card" href="#due-soon-orders">
                    <span class="stat-icon stat-icon-due" aria-hidden="true">◷</span>
                    <span class="stat-copy"><span class="stat-label">Due in the next 7 days</span><strong><?= $dueSoonOrderCount ?></strong><span class="stat-caption">Active orders approaching deadline</span></span>
                    <span class="stat-arrow" aria-hidden="true">→</span>
                </a>
            </section>

            <section id="due-soon-orders" class="due-soon-section" aria-labelledby="due-soon-title">
                <header class="due-soon-heading">
                    <div><p class="dashboard-eyebrow">Keep work moving</p><h2 id="due-soon-title">Due Soon Orders</h2></div>
                    <a href="/tailor/orders.php">View all orders <span aria-hidden="true">→</span></a>
                </header>
                <?php if ($dueSoonOrders === []): ?>
                    <div class="dashboard-empty">
                        <span class="empty-icon" aria-hidden="true">✓</span>
                        <div><h3>You’re all caught up</h3><p>No active orders are due in the next 7 days.</p></div>
                    </div>
                <?php else: ?>
                    <div class="due-soon-table-wrap">
                        <table class="due-soon-table">
                            <caption class="visually-hidden">Active orders due in the next seven days or missing a completion deadline</caption>
                            <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Service</th><th scope="col">Due date</th><th scope="col">Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($dueSoonOrders as $order): ?>
                                    <tr>
                                        <td data-label="Order"><a class="order-link" href="/tailor/orders.php?order_id=<?= (int) $order['order_id'] ?>">ORD-<?= str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT) ?></a></td>
                                        <td data-label="Customer"><?= escape_tailor_dashboard($order['customer_name']) ?></td>
                                        <td data-label="Service"><?= escape_tailor_dashboard($order['garment_type']) ?></td>
                                        <td data-label="Due date"><?php if ($order['deadline'] === null): ?><span class="deadline-missing">Not set</span><?php else: ?><?= escape_tailor_dashboard(tailor_dashboard_date($order['deadline'])) ?><?php endif; ?></td>
                                        <td data-label="Status"><span class="order-status status-<?= escape_tailor_dashboard((string) $order['status']) ?>"><?= escape_tailor_dashboard(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-quick-links" aria-label="Shop management">
                <a href="/tailor/requests.php"><span aria-hidden="true">▤</span><span><strong>Incoming requests</strong><small>Review customer requests and send quotations</small></span><span aria-hidden="true">→</span></a>
                <a href="/tailor/orders.php"><span aria-hidden="true">□</span><span><strong>Manage orders</strong><small>Record fabric and update stitching progress</small></span><span aria-hidden="true">→</span></a>
                <a href="/tailor/services.php"><span aria-hidden="true">✂</span><span><strong>Services</strong><small>Manage the garments and prices you offer</small></span><span aria-hidden="true">→</span></a>
                <a href="/tailor/profile.php"><span aria-hidden="true">◉</span><span><strong>Shop profile</strong><small>Update your shop details and order capacity</small></span><span aria-hidden="true">→</span></a>
            </section>
        </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
