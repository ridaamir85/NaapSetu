<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';

$user = require_role('tailor');
$connection = database();
$profile = tailor_profile_for_user($connection, $user['user_id']);

if ($profile === null) {
    redirect_to('/tailor/profile.php');
}

function escape_tailor_order(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function order_for_tailor(PDO $connection, int $orderId, int $tailorId): array|false
{
    $statement = $connection->prepare(
        'SELECT o.order_id, o.status, o.confirmed_at, r.request_id, u.name AS customer_name, '
        . 's.garment_type, mv.profile_id, q.amount AS quoted_amount, q.deadline AS deadline, '
        . 'r.fabric_source AS requested_fabric_source, r.customer_notes, r.stitching_details, '
        . 'r.fabric_type, r.fabric_color, r.fabric_preferences, r.reference_image_path, '
        . 'EXISTS(SELECT 1 FROM measurement_permissions AS mp '
        . 'WHERE mp.profile_id = mv.profile_id AND mp.tailor_id = r.tailor_id '
        . 'AND mp.revoked_at IS NULL) AS has_measurement_permission, '
        . 'f.fabric_id, f.source AS fabric_source, '
        . 'f.quantity AS fabric_quantity, f.received_at AS fabric_received_at, '
        . 'ff.rating AS fit_rating, ff.notes AS fit_notes '
        . 'FROM orders AS o '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'LEFT JOIN fabric_records AS f ON f.order_id = o.order_id '
        . 'LEFT JOIN fit_feedback AS ff ON ff.order_id = o.order_id '
        . 'WHERE o.order_id = :order_id AND r.tailor_id = :tailor_id'
    );
    $statement->execute(['order_id' => $orderId, 'tailor_id' => $tailorId]);

    return $statement->fetch();
}

$errors = [];
$formValues = ['source' => '', 'quantity' => '', 'received_at' => ''];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$orderIdInput = $requestMethod === 'POST'
    ? ($_POST['order_id'] ?? null)
    : ($_GET['order_id'] ?? null);
$selectedOrderId = null;

if ($orderIdInput !== null) {
    $selectedOrderId = is_string($orderIdInput) || is_int($orderIdInput)
        ? filter_var($orderIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])
        : false;
    if ($selectedOrderId === false) {
        http_response_code(404);
        exit('Order not found.');
    }
}

$views = ['all', 'in_progress', 'completed'];
$view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], $views, true)
    ? $_GET['view']
    : 'all';
$validOrderStatuses = ['confirmed', 'cutting', 'stitching', 'ready', 'delivered'];
$statusFilter = is_string($_GET['status'] ?? null)
    && ($_GET['status'] === 'all' || in_array($_GET['status'], $validOrderStatuses, true))
    ? $_GET['status']
    : 'all';

$selectedOrder = null;
if ($selectedOrderId !== null) {
    $selectedOrder = order_for_tailor($connection, $selectedOrderId, (int) $profile['tailor_id']);
    if ($selectedOrder === false) {
        http_response_code(404);
        exit('Order not found.');
    }
}

$successMessage = match ($_GET['saved'] ?? '') {
    'fabric' => 'Fabric details saved.',
    'status' => 'Order status updated.',
    'unchanged' => 'The order was already at that status. No change was made.',
    default => null,
};

if ($requestMethod === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $formValues['source'] = is_string($_POST['source'] ?? null) && $_POST['source'] === 'customer' ? 'customer' : 'tailor';
    $formValues['quantity'] = is_string($_POST['quantity'] ?? null) ? trim($_POST['quantity']) : '';
    $formValues['received_at'] = is_string($_POST['received_at'] ?? null) ? trim($_POST['received_at']) : '';
    $targetStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    $receivedAt = null;
    if ($action === 'save_fabric') {
        if ($formValues['source'] !== 'tailor' && $formValues['source'] !== 'customer') {
            $errors['source'] = 'Choose who supplied the fabric.';
        }
        if (preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,2})?\z/', $formValues['quantity']) !== 1
            || (float) $formValues['quantity'] <= 0) {
            $errors['quantity'] = 'Enter a quantity greater than 0 and no more than 999.99 metres.';
        }
        if ($formValues['received_at'] === '') $receivedAt = date('Y-m-d H:i:s');
        if ($formValues['received_at'] !== '') {
            $receivedAtDate = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $formValues['received_at']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($receivedAtDate === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $receivedAtDate->format('Y-m-d\TH:i') !== $formValues['received_at']) {
                $errors['received_at'] = 'Enter a valid fabric receipt date and time, or leave it blank.';
            } else {
                $receivedAt = $receivedAtDate->format('Y-m-d H:i:s');
            }
        }
    } elseif ($action === 'update_status') {
        $validStatuses = ['confirmed', 'cutting', 'stitching', 'ready', 'delivered'];
        if (!in_array($targetStatus, $validStatuses, true)) {
            $errors['status'] = 'Choose a valid order status.';
        }
    } else {
        $errors['form'] = 'Choose a valid order action.';
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $lockOrder = $connection->prepare(
                'SELECT o.order_id, o.status, r.stitching_details, r.fabric_source FROM orders AS o '
                . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
                . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
                . 'WHERE o.order_id = :order_id AND r.tailor_id = :tailor_id FOR UPDATE'
            );
            $lockOrder->execute([
                'order_id' => $selectedOrderId,
                'tailor_id' => $profile['tailor_id'],
            ]);
            $lockedOrder = $lockOrder->fetch();
            if ($lockedOrder === false) {
                http_response_code(404);
                throw new RuntimeException('Order ownership changed during submission.');
            }

            if ($action === 'save_fabric') {
                if ($lockedOrder['stitching_details'] !== null && $formValues['source'] !== 'tailor') {
                    $errors['form'] = 'Fabric for new requests must be arranged by the tailor.';
                }
                $existingFabric = $connection->prepare(
                    'SELECT fabric_id FROM fabric_records WHERE order_id = :order_id LIMIT 1'
                );
                $existingFabric->execute(['order_id' => $selectedOrderId]);
                if ($existingFabric->fetch() !== false) {
                    $errors['form'] = 'Fabric details have already been recorded for this order.';
                } elseif ($errors === []) {
                    $fabricInsert = $connection->prepare(
                        'INSERT INTO fabric_records (order_id, source, quantity, received_at) '
                        . 'VALUES (:order_id, :source, :quantity, :received_at)'
                    );
                    $fabricInsert->execute([
                        'order_id' => $selectedOrderId,
                        'source' => $formValues['source'],
                        'quantity' => $formValues['quantity'],
                        'received_at' => $receivedAt,
                    ]);
                }
            } else {
                $nextStatus = [
                    'confirmed' => 'cutting',
                    'cutting' => 'stitching',
                    'stitching' => 'ready',
                    'ready' => 'delivered',
                    'delivered' => null,
                ];
                if ($targetStatus === $lockedOrder['status']) {
                    $connection->rollBack();
            header('Location: /tailor/orders.php?saved=unchanged&order_id=' . (int) $selectedOrderId, true, 303);
                    exit;
                }
                if (($nextStatus[$lockedOrder['status']] ?? null) !== $targetStatus) {
                    $errors['form'] = 'Move the order forward by one step at a time.';
                } elseif ($targetStatus === 'cutting' && $lockedOrder['stitching_details'] !== null) {
                    $fabricCheck = $connection->prepare("SELECT fabric_id FROM fabric_records WHERE order_id = :id AND source='tailor' AND received_at IS NOT NULL");
                    $fabricCheck->execute(['id' => $selectedOrderId]);
                    if ($fabricCheck->fetchColumn() === false) {
                        $errors['form'] = 'Arrange and record fabric before cutting.';
                    }
                }
                if ($errors === []) {
                    $statusUpdate = $connection->prepare(
                        'UPDATE orders SET status = :new_status '
                        . 'WHERE order_id = :order_id AND status = :old_status'
                    );
                    $statusUpdate->execute([
                        'new_status' => $targetStatus,
                        'order_id' => $selectedOrderId,
                        'old_status' => $lockedOrder['status'],
                    ]);
                    if ($statusUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Order status was not updated.');
                    }

                    $historyInsert = $connection->prepare(
                        'INSERT INTO order_status_history (order_id, status) VALUES (:order_id, :status)'
                    );
                    $historyInsert->execute(['order_id' => $selectedOrderId, 'status' => $targetStatus]);
                }
            }

            if ($errors === []) {
                $connection->commit();
                header(
                    'Location: /tailor/orders.php?saved=' . ($action === 'save_fabric' ? 'fabric' : 'status')
                    . '&order_id=' . (int) $selectedOrderId,
                    true,
                    303
                );
                exit;
            }

            $connection->rollBack();
            $selectedOrder = order_for_tailor($connection, $selectedOrderId, (int) $profile['tailor_id']);
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $errors['form'] = $action === 'save_fabric'
                ? 'Fabric details could not be saved. They may already have been recorded.'
                : 'The order status could not be updated. Please try again.';
            $selectedOrder = order_for_tailor($connection, $selectedOrderId, (int) $profile['tailor_id']);
        }
    }
}

$ordersStatement = $connection->prepare(
    'SELECT o.order_id, o.status, o.confirmed_at, r.request_id, u.name AS customer_name, '
    . 's.garment_type, mv.profile_id, q.amount AS quoted_amount, q.deadline AS deadline, '
    . 'r.fabric_source AS requested_fabric_source, r.customer_notes, r.stitching_details, '
        . 'r.fabric_type, r.fabric_color, r.fabric_preferences, r.reference_image_path, '
    . 'EXISTS(SELECT 1 FROM measurement_permissions AS mp '
    . 'WHERE mp.profile_id = mv.profile_id AND mp.tailor_id = r.tailor_id '
    . 'AND mp.revoked_at IS NULL) AS has_measurement_permission, '
    . 'f.fabric_id, f.source AS fabric_source, '
    . 'f.quantity AS fabric_quantity, f.received_at AS fabric_received_at, '
    . 'ff.rating AS fit_rating, ff.notes AS fit_notes '
    . 'FROM orders AS o '
    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
    . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
    . 'INNER JOIN tailor_services AS s '
    . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
    . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
    . 'LEFT JOIN fabric_records AS f ON f.order_id = o.order_id '
    . 'LEFT JOIN fit_feedback AS ff ON ff.order_id = o.order_id '
    . 'WHERE r.tailor_id = :tailor_id ORDER BY o.confirmed_at DESC, o.order_id DESC'
);
$ordersStatement->execute(['tailor_id' => $profile['tailor_id']]);
$allOrders = $ordersStatement->fetchAll();
$orders = array_values(array_filter($allOrders, static function (array $order) use ($view, $statusFilter): bool {
    if ($statusFilter !== 'all' && $order['status'] !== $statusFilter) {
        return false;
    }
    if ($view === 'in_progress' && $order['status'] === 'delivered') {
        return false;
    }
    if ($view === 'completed' && $order['status'] !== 'delivered') {
        return false;
    }
    return true;
}));

$requestedOrder = $selectedOrder;
$selectedOrder = null;
if ($selectedOrderId !== null && $requestedOrder !== false) {
    foreach ($orders as $order) {
        if ((int) $order['order_id'] === (int) $selectedOrderId) {
            $selectedOrder = $order;
            break;
        }
    }
}
if ($selectedOrder === null && $orders !== []) {
    $selectedOrder = $orders[0];
    $selectedOrderId = (int) $selectedOrder['order_id'];
}

$orderHistory = [];
$orderTimeline = [];
if ($selectedOrder !== null) {
    $historyStatement = $connection->prepare(
        'SELECT status, changed_at FROM order_status_history '
        . 'WHERE order_id = :order_id ORDER BY history_id ASC'
    );
    $historyStatement->execute(['order_id' => $selectedOrder['order_id']]);
    $orderHistory = $historyStatement->fetchAll();
    foreach ($orderHistory as $entry) {
        $orderTimeline[] = ['status' => (string) $entry['status'], 'changed_at' => (string) $entry['changed_at']];
    }
    if ($selectedOrder['fabric_received_at'] !== null) {
        $orderTimeline[] = ['status' => 'fabric_arranged', 'changed_at' => (string) $selectedOrder['fabric_received_at']];
    }
    usort($orderTimeline, static fn (array $a, array $b): int => strcmp($a['changed_at'], $b['changed_at']));
}
$viewCounts = ['all' => count($allOrders), 'in_progress' => 0, 'completed' => 0];
foreach ($allOrders as $order) {
    if ($order['status'] === 'delivered') {
        $viewCounts['completed']++;
    } else {
        $viewCounts['in_progress']++;
    }
}
$selectedOrderStages = ['confirmed', 'cutting', 'stitching', 'ready', 'delivered'];
$currentOrderStage = $selectedOrder === null ? 0 : array_search($selectedOrder['status'], $selectedOrderStages, true);
if ($currentOrderStage === false) {
    $currentOrderStage = 0;
}
$statusNames = [
    'confirmed' => 'Confirmed',
    'cutting' => 'Cutting',
    'stitching' => 'Stitching',
    'ready' => 'Ready',
    'delivered' => 'Delivered',
];
$fabricFormSource = $selectedOrder === null
    ? ''
    : ($formValues['source'] !== '' ? $formValues['source'] : (string) $selectedOrder['requested_fabric_source']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orders &amp; Fabric | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-navigation.css">
    <link rel="stylesheet" href="/assets/css/tailor-orders.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="tailor-orders-page">
    <a class="skip-link" href="#main">Skip to orders</a>
    <div class="tailor-dashboard-shell">
    <?php $tailorSection = 'orders'; require dirname(__DIR__, 2) . '/includes/tailor-navigation.php'; ?>
    <main id="main" class="tailor-orders-main">
        <header class="orders-page-heading">
            <div><p class="orders-eyebrow">Tailor workspace</p><h1>Orders &amp; Fabric</h1><p>Track stitching progress and keep each order’s fabric record in one place.</p></div>
        </header>

        <?php if ($successMessage !== null): ?>
            <p class="orders-alert orders-success" role="status"><?= escape_tailor_order($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p class="orders-alert orders-error" role="alert"><?= escape_tailor_order($errors['form']) ?></p>
        <?php endif; ?>

        <section class="orders-browser" aria-label="Shop orders">
            <nav class="orders-view-tabs" aria-label="Order views">
                <?php foreach (['all' => 'All orders', 'in_progress' => 'In progress', 'completed' => 'Completed'] as $viewKey => $viewLabel): ?>
                    <a href="/tailor/orders.php?view=<?= escape_tailor_order($viewKey) ?>&amp;status=<?= escape_tailor_order($statusFilter) ?>" <?= $view === $viewKey ? 'aria-current="page"' : '' ?>><?= escape_tailor_order($viewLabel) ?><span><?= (int) $viewCounts[$viewKey] ?></span></a>
                <?php endforeach; ?>
            </nav>
            <form class="orders-filter" method="get" action="/tailor/orders.php">
                <input type="hidden" name="view" value="<?= escape_tailor_order($view) ?>">
                <label for="status-filter">Status</label>
                <select id="status-filter" name="status">
                    <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option>
                    <?php foreach ($statusNames as $statusKey => $statusLabel): ?>
                        <option value="<?= escape_tailor_order($statusKey) ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= escape_tailor_order($statusLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button filter-button" type="submit">Apply</button>
            </form>

            <?php if ($orders === []): ?>
                <div class="orders-empty"><span aria-hidden="true">□</span><h2>No orders in this view</h2><p>Accepted customer quotations will appear here, with fabric tracking and progress controls.</p><a class="ui-secondary-action" href="/tailor/requests.php">View incoming requests</a></div>
            <?php else: ?>
                <div class="orders-table-wrap">
                    <table class="tailor-orders-table">
                        <caption class="visually-hidden">Orders belonging to <?= escape_tailor_order($profile['shop_name']) ?></caption>
                        <thead><tr><th scope="col">Order ID</th><th scope="col">Customer</th><th scope="col">Service</th><th scope="col">Due date</th><th scope="col">Status</th><th scope="col">Fabric</th></tr></thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr class="<?= (int) $selectedOrder['order_id'] === (int) $order['order_id'] ? 'is-selected' : '' ?>">
                                    <td data-label="Order ID"><a href="/tailor/orders.php?order_id=<?= (int) $order['order_id'] ?>&amp;view=<?= escape_tailor_order($view) ?>&amp;status=<?= escape_tailor_order($statusFilter) ?>">ORD-<?= str_pad((string) $order['order_id'], 4, '0', STR_PAD_LEFT) ?></a></td>
                                    <td data-label="Customer"><?= escape_tailor_order($order['customer_name']) ?></td>
                                    <td data-label="Service"><?= escape_tailor_order($order['garment_type']) ?></td>
                                    <td data-label="Due date"><?= $order['deadline'] === null ? '<span class="deadline-missing">Not set</span>' : escape_tailor_order(date('M j, Y', strtotime($order['deadline']))) ?></td>
                                    <td data-label="Status"><span class="order-status status-<?= escape_tailor_order($order['status']) ?>"><?= escape_tailor_order(ucwords(str_replace('_', ' ', $order['status']))) ?></span></td>
                                    <td data-label="Fabric"><span class="fabric-state <?= $order['fabric_id'] === null ? 'fabric-pending' : 'fabric-recorded' ?>"><?= $order['fabric_id'] === null ? 'Not recorded' : 'Recorded' ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($selectedOrder !== null): ?>
            <article class="selected-order-detail" aria-labelledby="selected-order-title">
                <header class="selected-order-heading">
                    <div><p class="orders-eyebrow">Selected order</p><h2 id="selected-order-title">ORD-<?= str_pad((string) $selectedOrder['order_id'], 4, '0', STR_PAD_LEFT) ?></h2><p><?= escape_tailor_order($selectedOrder['garment_type']) ?> <span aria-hidden="true">·</span> <?= escape_tailor_order($selectedOrder['customer_name']) ?></p></div>
                    <span class="order-status status-<?= escape_tailor_order($selectedOrder['status']) ?>"><?= escape_tailor_order(ucwords(str_replace('_', ' ', $selectedOrder['status']))) ?></span>
                </header>
                <div class="selected-order-grid">
                    <section class="order-detail-card" aria-labelledby="fabric-details-title">
                        <h3 id="fabric-details-title">Fabric details</h3>
                        <?php if ($selectedOrder['fabric_id'] !== null): ?>
                            <dl class="order-facts"><div><dt>Source</dt><dd><?= escape_tailor_order($selectedOrder['fabric_source'] === 'customer' ? 'Customer provided' : 'Tailor provided') ?></dd></div><div><dt>Quantity</dt><dd><?= escape_tailor_order(number_format((float) $selectedOrder['fabric_quantity'], 2)) ?> m</dd></div><div><dt>Arranged on</dt><dd><?= $selectedOrder['fabric_received_at'] === null ? 'Not recorded' : escape_tailor_order(date('M j, Y · g:i a', strtotime($selectedOrder['fabric_received_at']))) ?></dd></div></dl>
                        <?php else: ?>
                            <p class="order-detail-hint">Record the fabric arranged by the tailor before cutting.</p>
                            <form class="fabric-form" method="post" action="/tailor/orders.php?order_id=<?= (int) $selectedOrder['order_id'] ?>&amp;view=<?= escape_tailor_order($view) ?>&amp;status=<?= escape_tailor_order($statusFilter) ?>" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= escape_tailor_order(csrf_token()) ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $selectedOrder['order_id'] ?>">
                                <input type="hidden" name="action" value="save_fabric">
                                <?php if ($selectedOrder['stitching_details'] === null): ?>
                                <div class="order-form-field"><label for="source">Fabric source (legacy order)</label><select id="source" name="source"><option value="customer"<?= $fabricFormSource === 'customer' ? ' selected' : '' ?>>Customer</option><option value="tailor"<?= $fabricFormSource === 'tailor' ? ' selected' : '' ?>>Tailor</option></select></div>
                                <?php else: ?><p class="order-detail-hint">For new orders, fabric is supplied by the tailor.</p><?php endif; ?>
                                <div class="order-form-field"><label for="quantity">Quantity (metres)</label><input id="quantity" name="quantity" type="number" min="0.01" max="999.99" step="0.01" required value="<?= escape_tailor_order($formValues['quantity']) ?>" placeholder="e.g. 2.5"><?php if (isset($errors['quantity'])): ?><p class="field-error" role="alert"><?= escape_tailor_order($errors['quantity']) ?></p><?php endif; ?></div>
                                <div class="order-form-field"><label for="received_at">Fabric arranged on (optional; blank means now)</label><input id="received_at" name="received_at" type="datetime-local" value="<?= escape_tailor_order($formValues['received_at']) ?>"><?php if (isset($errors['received_at'])): ?><p class="field-error" role="alert"><?= escape_tailor_order($errors['received_at']) ?></p><?php endif; ?></div>
                                <button class="button order-action-button" type="submit">Confirm fabric arranged</button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="order-detail-card" aria-labelledby="progress-history-title">
                        <h3 id="progress-history-title">Progress history</h3>
                        <ol class="order-progress-track" aria-label="Order status progress">
                            <?php foreach ($selectedOrderStages as $index => $stage): $done = $index < $currentOrderStage; $current = $index === $currentOrderStage; ?>
                                <li class="<?= $done ? 'is-done' : ($current ? 'is-current' : 'is-upcoming') ?>" <?= $current ? 'aria-current="step"' : '' ?>><span class="progress-dot" aria-hidden="true"><?= $done ? '✓' : '' ?></span><span><?= escape_tailor_order($statusNames[$stage]) ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if ($orderTimeline !== []): ?>
                            <ol class="order-history-list">
                                <?php foreach ($orderTimeline as $entry): ?>
                                    <li><strong><?= escape_tailor_order(ucwords(str_replace('_', ' ', $entry['status']))) ?></strong><time datetime="<?= escape_tailor_order($entry['changed_at']) ?>"><?= escape_tailor_order(date('M j, Y · g:i a', strtotime($entry['changed_at']))) ?></time></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?><p class="order-detail-hint">No progress history has been recorded yet.</p><?php endif; ?>
                    </section>

                    <section class="order-detail-card order-summary-card" aria-labelledby="order-summary-title">
                        <h3 id="order-summary-title">Order summary</h3>
                        <dl class="order-facts"><div><dt>Customer</dt><dd><?= escape_tailor_order($selectedOrder['customer_name']) ?></dd></div><div><dt>Service</dt><dd><?= escape_tailor_order($selectedOrder['garment_type']) ?></dd></div><div><dt>Quoted cost</dt><dd>PKR <?= escape_tailor_order(number_format((float) $selectedOrder['quoted_amount'], 2)) ?></dd></div><div><dt>Completion deadline</dt><dd><?= $selectedOrder['deadline'] === null ? 'Not set' : escape_tailor_order(date('M j, Y', strtotime($selectedOrder['deadline']))) ?></dd></div></dl>
                        <?php if ((int) $selectedOrder['has_measurement_permission'] === 1): ?><a class="order-detail-link" href="/tailor/measurements.php?order_id=<?= (int) $selectedOrder['order_id'] ?>">View order measurements <span aria-hidden="true">→</span></a><?php else: ?><p class="order-detail-hint">The customer has not shared this measurement profile with your shop.</p><?php endif; ?>
                        <?php if ($selectedOrder['stitching_details']): ?><div class="order-customer-notes"><strong>Stitching requirements</strong><p><?= nl2br(escape_tailor_order($selectedOrder['stitching_details'])) ?></p></div><?php endif; ?>
                        <?php if ($selectedOrder['fabric_type'] || $selectedOrder['fabric_color'] || $selectedOrder['fabric_preferences']): ?><div class="order-customer-notes"><strong>Fabric preferences</strong><p><?= escape_tailor_order($selectedOrder['fabric_type'] ?: 'Not specified') ?> · <?= escape_tailor_order($selectedOrder['fabric_color'] ?: 'Not specified') ?></p><p><?= nl2br(escape_tailor_order($selectedOrder['fabric_preferences'] ?: '')) ?></p></div><?php endif; ?>
                        <?php if ($selectedOrder['reference_image_path']): ?><div class="order-customer-notes"><strong>Styling reference</strong><p><a href="/reference-image.php?request_id=<?= (int)$selectedOrder['request_id'] ?>" target="_blank" rel="noopener">View image</a></p></div><?php endif; ?>
                        <?php if (is_string($selectedOrder['customer_notes']) && trim($selectedOrder['customer_notes']) !== ''): ?><div class="order-customer-notes"><strong>Customer notes</strong><p><?= nl2br(escape_tailor_order($selectedOrder['customer_notes'])) ?></p></div><?php endif; ?>
                        <?php if ($selectedOrder['fit_rating'] !== null): ?><div class="order-customer-notes"><strong>Fit feedback · <?= (int) $selectedOrder['fit_rating'] ?>/5</strong><?php if (is_string($selectedOrder['fit_notes']) && trim($selectedOrder['fit_notes']) !== ''): ?><p><?= nl2br(escape_tailor_order($selectedOrder['fit_notes'])) ?></p><?php endif; ?></div><?php endif; ?>
                    </section>

                    <section class="order-detail-card order-status-card" aria-labelledby="update-status-title">
                        <h3 id="update-status-title">Update order status</h3>
                        <?php if ($selectedOrder['status'] !== 'delivered'): ?>
                            <?php $nextStatusOptions = ['confirmed' => 'cutting', 'cutting' => 'stitching', 'stitching' => 'ready', 'ready' => 'delivered', 'delivered' => null]; ?>
                            <form class="status-form" method="post" action="/tailor/orders.php?order_id=<?= (int) $selectedOrder['order_id'] ?>&amp;view=<?= escape_tailor_order($view) ?>&amp;status=<?= escape_tailor_order($statusFilter) ?>">
                                <input type="hidden" name="csrf_token" value="<?= escape_tailor_order(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int) $selectedOrder['order_id'] ?>"><input type="hidden" name="action" value="update_status">
                                <label for="status">Move from <?= escape_tailor_order(ucwords(str_replace('_', ' ', $selectedOrder['status']))) ?> to</label>
                                <select id="status" name="status" required><option value="<?= escape_tailor_order($selectedOrder['status']) ?>"><?= escape_tailor_order(ucwords(str_replace('_', ' ', $selectedOrder['status']))) ?> (no change)</option><?php if ($nextStatusOptions[$selectedOrder['status']] !== null): ?><option value="<?= escape_tailor_order($nextStatusOptions[$selectedOrder['status']]) ?>"><?= escape_tailor_order(ucwords(str_replace('_', ' ', $nextStatusOptions[$selectedOrder['status']]))) ?></option><?php endif; ?></select>
                                <?php if (isset($errors['status'])): ?><p class="field-error" role="alert"><?= escape_tailor_order($errors['status']) ?></p><?php endif; ?>
                                <button class="button order-action-button" type="submit">Save progress</button>
                            </form>
                        <?php else: ?><p class="delivered-note">This order has been delivered.</p><?php endif; ?>
                    </section>
                </div>
            </article>
        <?php endif; ?>
    </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
