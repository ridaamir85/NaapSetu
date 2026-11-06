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
        'SELECT o.order_id, o.status, o.confirmed_at, u.name AS customer_name, '
        . 's.garment_type, mv.profile_id, '
        . 'EXISTS(SELECT 1 FROM measurement_permissions AS mp '
        . 'WHERE mp.profile_id = mv.profile_id AND mp.tailor_id = r.tailor_id '
        . 'AND mp.revoked_at IS NULL) AS has_measurement_permission, '
        . 'f.fabric_id, f.source AS fabric_source, '
        . 'f.quantity AS fabric_quantity, f.received_at AS fabric_received_at '
        . 'FROM orders AS o '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'LEFT JOIN fabric_records AS f ON f.order_id = o.order_id '
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
    $formValues['source'] = is_string($_POST['source'] ?? null) ? $_POST['source'] : '';
    $formValues['quantity'] = is_string($_POST['quantity'] ?? null) ? trim($_POST['quantity']) : '';
    $formValues['received_at'] = is_string($_POST['received_at'] ?? null) ? trim($_POST['received_at']) : '';
    $targetStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    $receivedAt = null;
    if ($action === 'save_fabric') {
        if (!in_array($formValues['source'], ['customer', 'tailor'], true)) {
            $errors['source'] = 'Choose who supplied the fabric.';
        }
        if (preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,2})?\z/', $formValues['quantity']) !== 1
            || (float) $formValues['quantity'] <= 0) {
            $errors['quantity'] = 'Enter a quantity greater than 0 and no more than 999.99 metres.';
        }
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
                'SELECT o.order_id, o.status FROM orders AS o '
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
                $existingFabric = $connection->prepare(
                    'SELECT fabric_id FROM fabric_records WHERE order_id = :order_id LIMIT 1'
                );
                $existingFabric->execute(['order_id' => $selectedOrderId]);
                if ($existingFabric->fetch() !== false) {
                    $errors['form'] = 'Fabric details have already been recorded for this order.';
                } else {
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
                    header('Location: /tailor/orders.php?saved=unchanged', true, 303);
                    exit;
                }
                if (($nextStatus[$lockedOrder['status']] ?? null) !== $targetStatus) {
                    $errors['form'] = 'Move the order forward by one step at a time.';
                } else {
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
                    'Location: /tailor/orders.php?saved=' . ($action === 'save_fabric' ? 'fabric' : 'status'),
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

if ($selectedOrderId === null) {
    $statement = $connection->prepare(
        'SELECT o.order_id, o.status, o.confirmed_at, u.name AS customer_name, '
        . 's.garment_type, mv.profile_id, '
        . 'EXISTS(SELECT 1 FROM measurement_permissions AS mp '
        . 'WHERE mp.profile_id = mv.profile_id AND mp.tailor_id = r.tailor_id '
        . 'AND mp.revoked_at IS NULL) AS has_measurement_permission, '
        . 'f.fabric_id, f.source AS fabric_source, '
        . 'f.quantity AS fabric_quantity, f.received_at AS fabric_received_at '
        . 'FROM orders AS o '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'LEFT JOIN fabric_records AS f ON f.order_id = o.order_id '
        . 'WHERE r.tailor_id = :tailor_id ORDER BY o.confirmed_at DESC, o.order_id DESC'
    );
    $statement->execute(['tailor_id' => $profile['tailor_id']]);
    $orders = $statement->fetchAll();
} elseif ($selectedOrder !== null && $selectedOrder !== false) {
    $orders = [$selectedOrder];
} else {
    $orders = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage orders | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/tailor/dashboard.php">Back to dashboard</a>
        <h1>Manage orders</h1>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_tailor_order($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_tailor_order($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($orders === []): ?>
            <p>Your shop has no confirmed orders yet.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <section>
                    <h2>Order #<?= (int) $order['order_id'] ?> — <?= escape_tailor_order($order['garment_type']) ?></h2>
                    <p>Customer: <?= escape_tailor_order($order['customer_name']) ?></p>
                    <p>Status: <?= escape_tailor_order($order['status']) ?></p>
                    <?php if ((int) $order['has_measurement_permission'] === 1): ?>
                        <p><a href="/tailor/measurements.php?profile_id=<?= (int) $order['profile_id'] ?>">View shared measurements</a></p>
                    <?php else: ?>
                        <p>The customer has not shared this measurement profile with your shop.</p>
                    <?php endif; ?>

                    <?php if ($order['fabric_id'] !== null): ?>
                        <h3>Fabric record</h3>
                        <p>Source: <?= escape_tailor_order($order['fabric_source']) ?></p>
                        <p>Quantity: <?= escape_tailor_order((string) $order['fabric_quantity']) ?> metres</p>
                        <p>Received: <?= $order['fabric_received_at'] === null ? 'Not recorded' : escape_tailor_order($order['fabric_received_at']) ?></p>
                    <?php else: ?>
                        <h3>Record fabric</h3>
                        <form method="post" action="/tailor/orders.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_tailor_order(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                            <input type="hidden" name="action" value="save_fabric">
                            <div>
                                <label for="source-<?= (int) $order['order_id'] ?>">Fabric supplied by</label>
                                <select id="source-<?= (int) $order['order_id'] ?>" name="source" required>
                                    <option value="">Choose one</option>
                                    <option value="customer"<?= $formValues['source'] === 'customer' ? ' selected' : '' ?>>Customer</option>
                                    <option value="tailor"<?= $formValues['source'] === 'tailor' ? ' selected' : '' ?>>Tailor</option>
                                </select>
                                <?php if (isset($errors['source'])): ?>
                                    <p role="alert"><?= escape_tailor_order($errors['source']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="quantity-<?= (int) $order['order_id'] ?>">Quantity (metres)</label>
                                <input id="quantity-<?= (int) $order['order_id'] ?>" name="quantity" type="number" min="0.01" max="999.99" step="0.01" required value="<?= escape_tailor_order($formValues['quantity']) ?>">
                                <?php if (isset($errors['quantity'])): ?>
                                    <p role="alert"><?= escape_tailor_order($errors['quantity']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="received_at-<?= (int) $order['order_id'] ?>">Receipt date and time (optional)</label>
                                <input id="received_at-<?= (int) $order['order_id'] ?>" name="received_at" type="datetime-local" value="<?= escape_tailor_order($formValues['received_at']) ?>">
                                <?php if (isset($errors['received_at'])): ?>
                                    <p role="alert"><?= escape_tailor_order($errors['received_at']) ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="submit">Save fabric record</button>
                        </form>
                    <?php endif; ?>

                    <?php $nextStatusOptions = [
                        'confirmed' => 'cutting',
                        'cutting' => 'stitching',
                        'stitching' => 'ready',
                        'ready' => 'delivered',
                        'delivered' => null,
                    ]; ?>
                    <?php if ($order['status'] !== 'delivered'): ?>
                        <h3>Update order progress</h3>
                        <form method="post" action="/tailor/orders.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_tailor_order(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                            <input type="hidden" name="action" value="update_status">
                            <label for="status-<?= (int) $order['order_id'] ?>">Order status</label>
                            <select id="status-<?= (int) $order['order_id'] ?>" name="status" required>
                                <option value="<?= escape_tailor_order($order['status']) ?>" selected><?= escape_tailor_order($order['status']) ?> (no change)</option>
                                <?php if ($nextStatusOptions[$order['status']] !== null): ?>
                                    <option value="<?= escape_tailor_order($nextStatusOptions[$order['status']]) ?>"><?= escape_tailor_order($nextStatusOptions[$order['status']]) ?></option>
                                <?php endif; ?>
                            </select>
                            <?php if (isset($errors['status'])): ?>
                                <p role="alert"><?= escape_tailor_order($errors['status']) ?></p>
                            <?php endif; ?>
                            <button type="submit">Save status</button>
                        </form>
                    <?php else: ?>
                        <p>This order is delivered.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
