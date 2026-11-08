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

function customer_order_label(string $value): string
{
    return ucwords(str_replace('_', ' ', $value));
}

function customer_order_date(?string $value, string $format = 'M j, Y'): string
{
    if ($value === null || $value === '') {
        return 'Not recorded';
    }

    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable) {
        return 'Not recorded';
    }
}

$ordersStatement = $connection->prepare(
    'SELECT o.order_id, o.status, o.confirmed_at, o.style_snapshot, '
    . 'q.amount AS quoted_amount, q.deadline AS quotation_deadline, '
    . 'p.shop_name, s.garment_type, s.avg_days, '
    . 'r.customer_notes, r.request_id, r.stitching_details, r.fabric_type, r.fabric_color, r.fabric_preferences, r.reference_image_path, r.fabric_source AS requested_fabric_source, '
    . 'mp.profile_name, mv.version_no, '
    . 'f.source AS fabric_record_source, f.received_at AS fabric_received_at '
    . 'FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = r.tailor_id '
    . 'INNER JOIN tailor_services AS s '
    . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
    . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
    . 'INNER JOIN measurement_profiles AS mp '
    . 'ON mp.profile_id = mv.profile_id AND mp.customer_id = r.customer_id '
    . 'LEFT JOIN fabric_records AS f ON f.order_id = o.order_id '
    . 'WHERE r.customer_id = :customer_id '
    . 'ORDER BY o.confirmed_at DESC, o.order_id DESC'
);
$ordersStatement->execute(['customer_id' => $user['user_id']]);
$orders = $ordersStatement->fetchAll();

$selectedOrderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
$selectedOrder = null;
foreach ($orders as $order) {
    if ($selectedOrderId !== false && $selectedOrderId !== null && (int) $order['order_id'] === $selectedOrderId) {
        $selectedOrder = $order;
        break;
    }
}
if ($selectedOrder === null && $orders !== []) {
    $selectedOrder = $orders[0];
}

$history = [];
$styleSnapshot = [];
$stylePreferences = [];
$historyByStatus = [];
$displayTimeline = [];
$currentStage = 0;
$trackingStages = [
    'confirmed' => 'Confirmed',
    'fabric' => 'Fabric arranged',
    'cutting' => 'Cutting',
    'stitching' => 'Stitching',
    'ready' => 'Ready',
    'delivered' => 'Delivered',
];

if ($selectedOrder !== null) {
    $historyStatement = $connection->prepare(
        'SELECT status, changed_at FROM order_status_history '
        . 'WHERE order_id = :order_id ORDER BY history_id ASC'
    );
    $historyStatement->execute(['order_id' => $selectedOrder['order_id']]);
    $history = $historyStatement->fetchAll();
    foreach ($history as $entry) {
        $historyByStatus[(string) $entry['status']] = $entry['changed_at'];
        $displayTimeline[] = ['status' => (string) $entry['status'], 'changed_at' => (string) $entry['changed_at']];
    }
    if ($selectedOrder['fabric_received_at'] !== null) {
        $displayTimeline[] = ['status' => 'fabric_arranged', 'changed_at' => (string) $selectedOrder['fabric_received_at']];
    }
    usort($displayTimeline, static fn (array $a, array $b): int => strcmp($b['changed_at'], $a['changed_at']));

    $decodedStyle = json_decode((string) $selectedOrder['style_snapshot'], true);
    if (is_array($decodedStyle)) {
        $styleSnapshot = $decodedStyle;
        if (isset($styleSnapshot['preferences']) && is_array($styleSnapshot['preferences'])) {
            $stylePreferences = array_filter(
                $styleSnapshot['preferences'],
                static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''
            );
        }
    }

    $statusStage = [
        'confirmed' => 0,
        'cutting' => 2,
        'stitching' => 3,
        'ready' => 4,
        'delivered' => 5,
    ];
    $currentStage = $statusStage[$selectedOrder['status']] ?? 0;
    if ($selectedOrder['status'] === 'confirmed' && $selectedOrder['fabric_received_at'] !== null) {
        $currentStage = 1;
    }
}

$customerSection = 'orders';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Orders &amp; Tracking | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/customer.css">
    <link rel="stylesheet" href="/assets/css/orders.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="customer-page orders-page">
    <a class="skip-link" href="#main">Skip to orders</a>
    <div class="customer-shell">
        <?php require dirname(__DIR__, 2) . '/includes/customer-navigation.php'; ?>
        <main id="main" class="customer-main orders-main">
            <header class="orders-heading">
                <div>
                    <p class="eyebrow">From fitting to finish</p>
                    <h1>My Orders &amp; Tracking</h1>
                    <p>View confirmed orders and follow each garment through the workshop.</p>
                </div>
            </header>

            <?php if ($orders === []): ?>
                <section class="orders-empty" aria-labelledby="orders-empty-title">
                    <span class="orders-empty-icon" aria-hidden="true">□</span>
                    <h2 id="orders-empty-title">Your orders will appear here</h2>
                    <p>Once you accept a tailor’s quotation, you can follow its progress and see the order details here.</p>
                    <a class="outline-button" href="/customer/requests.php">View requests &amp; quotations <span aria-hidden="true">→</span></a>
                </section>
            <?php else: ?>
                <div class="orders-layout">
                    <section class="orders-list-panel" aria-labelledby="orders-list-title">
                        <div class="orders-panel-heading">
                            <h2 id="orders-list-title">Your orders</h2>
                            <span><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?></span>
                        </div>
                        <nav class="orders-list" aria-label="Select an order">
                            <?php foreach ($orders as $order): ?>
                                <?php $isSelected = (int) $order['order_id'] === (int) $selectedOrder['order_id']; ?>
                                <a class="order-list-item<?= $isSelected ? ' is-selected' : '' ?>" href="/customer/orders.php?order_id=<?= (int) $order['order_id'] ?>" <?= $isSelected ? 'aria-current="page"' : '' ?>>
                                    <span class="order-list-topline"><strong>ORD-<?= str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT) ?></strong><span class="order-status status-<?= escape_customer_order((string) $order['status']) ?>"><?= escape_customer_order(customer_order_label((string) $order['status'])) ?></span></span>
                                    <span class="order-list-garment"><?= escape_customer_order($order['garment_type']) ?></span>
                                    <span class="order-list-tailor"><?= escape_customer_order($order['shop_name']) ?></span>
                                    <span class="order-list-date">Confirmed <?= escape_customer_order(customer_order_date($order['confirmed_at'])) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    </section>

                    <article class="order-detail" aria-labelledby="selected-order-title">
                        <header class="order-detail-heading">
                            <div>
                                <p class="eyebrow">Order details</p>
                                <h2 id="selected-order-title">ORD-<?= str_pad((string) $selectedOrder['order_id'], 4, '0', STR_PAD_LEFT) ?></h2>
                                <p><?= escape_customer_order($selectedOrder['garment_type']) ?> <span aria-hidden="true">·</span> <?= escape_customer_order($selectedOrder['shop_name']) ?></p>
                            </div>
                            <span class="order-status order-status-large status-<?= escape_customer_order((string) $selectedOrder['status']) ?>"><?= escape_customer_order(customer_order_label((string) $selectedOrder['status'])) ?></span>
                        </header>

                        <section class="order-progress-section" aria-labelledby="progress-title">
                            <h3 id="progress-title">Order progress</h3>
                            <ol class="order-progress" aria-label="Order progress">
                                <?php $stageIndex = 0; foreach ($trackingStages as $stageKey => $stageLabel): ?>
                                    <?php
                                    $stageDone = $stageKey === 'fabric'
                                        ? $selectedOrder['fabric_received_at'] !== null
                                        : $stageIndex < $currentStage;
                                    $stageCurrent = $stageIndex === $currentStage;
                                    $stageDate = $stageKey === 'confirmed'
                                        ? $selectedOrder['confirmed_at']
                                        : ($stageKey === 'fabric'
                                            ? $selectedOrder['fabric_received_at']
                                            : ($historyByStatus[$stageKey] ?? null));
                                    ?>
                                    <li class="<?= $stageDone ? 'is-done' : ($stageCurrent ? 'is-current' : 'is-upcoming') ?>" <?= $stageCurrent ? 'aria-current="step"' : '' ?>>
                                        <span class="progress-marker" aria-hidden="true"><?= $stageDone ? '✓' : '' ?></span>
                                        <span class="progress-label"><?= escape_customer_order($stageLabel) ?></span>
                                    <span class="progress-date"><?= escape_customer_order($stageDate === null ? '—' : customer_order_date($stageDate, 'M j')) ?></span>
                                        <span class="visually-hidden"> — <?= $stageDone ? 'complete' : ($stageCurrent ? 'current stage' : 'upcoming') ?></span>
                                    </li>
                                    <?php $stageIndex++; ?>
                                <?php endforeach; ?>
                            </ol>
                        </section>

                        <div class="order-information-grid">
                            <section class="order-information-card" aria-labelledby="history-title">
                                <h3 id="history-title">Timeline history</h3>
                                <?php if ($displayTimeline === []): ?>
                                    <p class="order-muted">No status updates have been recorded yet.</p>
                                <?php else: ?>
                                    <ol class="order-history">
                                        <?php foreach ($displayTimeline as $entry): ?>
                                            <li><span class="history-dot" aria-hidden="true"></span><span><strong><?= escape_customer_order(($entry['status'] === 'fabric_arranged' ? 'Fabric arranged' : customer_order_label((string) $entry['status']))) ?></strong><time datetime="<?= escape_customer_order($entry['changed_at']) ?>"><?= escape_customer_order(customer_order_date($entry['changed_at'], 'M j, Y · g:i a')) ?></time></span></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </section>

                            <section class="order-information-card" aria-labelledby="fabric-title">
                                <h3 id="fabric-title">Fabric record</h3>
                                <?php $fabricSource = $selectedOrder['fabric_record_source'] ?? $selectedOrder['requested_fabric_source']; ?>
                                <dl class="order-facts">
                                    <div><dt>Supplied by</dt><dd><?= escape_customer_order($fabricSource === 'customer' ? 'Customer' : 'Tailor') ?></dd></div>
                                    <div><dt>Arranged on</dt><dd><?= escape_customer_order(customer_order_date($selectedOrder['fabric_received_at'], 'M j, Y · g:i a')) ?></dd></div>
                                </dl>
                            </section>

                            <section class="order-information-card" aria-labelledby="summary-title">
                                <h3 id="summary-title">Order summary</h3>
                                <dl class="order-facts">
                                    <div><dt>Service</dt><dd><?= escape_customer_order($selectedOrder['garment_type']) ?></dd></div>
                                    <div><dt>Measurement</dt><dd><?= escape_customer_order($selectedOrder['profile_name']) ?> · v<?= (int) $selectedOrder['version_no'] ?></dd></div>
                                    <div><dt>Quoted price</dt><dd>PKR <?= escape_customer_order(number_format((float) $selectedOrder['quoted_amount'], 2)) ?></dd></div>
                                    <div><dt>Estimated completion date</dt><dd><?= escape_customer_order(customer_order_date($selectedOrder['quotation_deadline'])) ?></dd></div>
                                </dl>
                            </section>

                            <section class="order-information-card" aria-labelledby="style-title">
                                <h3 id="style-title">Saved style</h3>
                                <?php if (isset($styleSnapshot['name']) && is_scalar($styleSnapshot['name']) && (string) $styleSnapshot['name'] !== ''): ?>
                                    <p class="order-style-name"><?= escape_customer_order((string) $styleSnapshot['name']) ?></p>
                                <?php endif; ?>
                                <?php if ($stylePreferences !== []): ?>
                                    <dl class="order-facts order-style-facts">
                                        <?php foreach ($stylePreferences as $preference => $value): ?>
                                            <div><dt><?= escape_customer_order(ucwords(str_replace('_', ' ', (string) $preference))) ?></dt><dd><?= escape_customer_order((string) $value) ?></dd></div>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <p class="order-muted">No style preferences were saved for this order.</p>
                                <?php endif; ?>
                            </section>
                        </div>

                        <?php if ($selectedOrder['stitching_details']): ?><section class="order-information-card"><h3>Requested stitching</h3><p><?= nl2br(escape_customer_order($selectedOrder['stitching_details'])) ?></p></section><?php endif; ?>
                        <?php if ($selectedOrder['fabric_type'] || $selectedOrder['fabric_color'] || $selectedOrder['fabric_preferences']): ?><section class="order-information-card"><h3>Fabric preferences</h3><p><?= escape_customer_order($selectedOrder['fabric_type'] ?: 'Not specified') ?> · <?= escape_customer_order($selectedOrder['fabric_color'] ?: 'Not specified') ?></p><p><?= nl2br(escape_customer_order($selectedOrder['fabric_preferences'] ?: '')) ?></p></section><?php endif; ?>
                        <?php if ($selectedOrder['reference_image_path']): ?><section class="order-information-card"><h3>Styling reference</h3><a href="/reference-image.php?request_id=<?= (int)$selectedOrder['request_id'] ?>" target="_blank" rel="noopener">View uploaded image</a></section><?php endif; ?>
                        <?php if (is_string($selectedOrder['customer_notes']) && trim($selectedOrder['customer_notes']) !== ''): ?>
                            <section class="order-notes" aria-labelledby="order-notes-title">
                                <h3 id="order-notes-title">Request notes</h3>
                                <p><?= nl2br(escape_customer_order($selectedOrder['customer_notes'])) ?></p>
                            </section>
                        <?php endif; ?>
                    </article>
                </div>
            <?php endif; ?>
        </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
