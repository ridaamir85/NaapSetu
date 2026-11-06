<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_customer_feedback(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function delivered_order_for_customer(PDO $connection, int $orderId, int $customerId): array|false
{
    $statement = $connection->prepare(
        'SELECT o.order_id, o.status, o.confirmed_at, s.garment_type, p.shop_name, '
        . 'f.feedback_id, f.rating, f.notes '
        . 'FROM orders AS o '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = r.tailor_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'LEFT JOIN fit_feedback AS f ON f.order_id = o.order_id '
        . 'WHERE o.order_id = :order_id AND r.customer_id = :customer_id AND o.status = :delivered'
    );
    $statement->execute([
        'order_id' => $orderId,
        'customer_id' => $customerId,
        'delivered' => 'delivered',
    ]);

    return $statement->fetch();
}

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
    $selectedOrder = delivered_order_for_customer($connection, $selectedOrderId, (int) $user['user_id']);
    if ($selectedOrder === false) {
        http_response_code(404);
        exit('Delivered order not found.');
    }
}

$errors = [];
$formValues = ['rating' => '', 'notes' => '', 'issue' => ''];
$successMessage = match ($_GET['saved'] ?? '') {
    'feedback' => 'Fit feedback saved.',
    'alteration' => 'Alteration request submitted.',
    default => null,
};

if ($requestMethod === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $formValues['rating'] = is_string($_POST['rating'] ?? null) ? trim($_POST['rating']) : '';
    $formValues['notes'] = is_string($_POST['notes'] ?? null) ? trim($_POST['notes']) : '';
    $formValues['issue'] = is_string($_POST['issue'] ?? null) ? trim($_POST['issue']) : '';

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['save_feedback', 'create_alteration'], true)) {
        $errors['form'] = 'Choose a valid action.';
    }

    if ($action === 'save_feedback') {
        $rating = filter_var($formValues['rating'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5],
        ]);
        if ($rating === false) {
            $errors['rating'] = 'Choose a whole-number rating from 1 to 5.';
        }
        if (mb_strlen($formValues['notes'], 'UTF-8') > 5000) {
            $errors['notes'] = 'Notes must be 5,000 characters or fewer.';
        }
    } elseif ($action === 'create_alteration') {
        $issueLength = mb_strlen($formValues['issue'], 'UTF-8');
        if ($issueLength < 5 || $issueLength > 2000) {
            $errors['issue'] = 'Describe the alteration in 5 to 2,000 characters.';
        }
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $lockOrder = $connection->prepare(
                'SELECT o.order_id FROM orders AS o '
                . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
                . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
                . 'WHERE o.order_id = :order_id AND r.customer_id = :customer_id '
                . 'AND o.status = :delivered FOR UPDATE'
            );
            $lockOrder->execute([
                'order_id' => $selectedOrderId,
                'customer_id' => $user['user_id'],
                'delivered' => 'delivered',
            ]);
            if ($lockOrder->fetch() === false) {
                http_response_code(404);
                throw new RuntimeException('Delivered order ownership changed during submission.');
            }

            if ($action === 'save_feedback') {
                $existingFeedback = $connection->prepare(
                    'SELECT feedback_id FROM fit_feedback WHERE order_id = :order_id LIMIT 1'
                );
                $existingFeedback->execute(['order_id' => $selectedOrderId]);
                if ($existingFeedback->fetch() !== false) {
                    $errors['form'] = 'Fit feedback has already been submitted for this order.';
                } else {
                    $feedbackInsert = $connection->prepare(
                        'INSERT INTO fit_feedback (order_id, rating, notes) '
                        . 'VALUES (:order_id, :rating, :notes)'
                    );
                    $feedbackInsert->execute([
                        'order_id' => $selectedOrderId,
                        'rating' => $rating,
                        'notes' => $formValues['notes'] === '' ? null : $formValues['notes'],
                    ]);
                }
            } else {
                $alterationInsert = $connection->prepare(
                    'INSERT INTO alteration_requests (order_id, issue, status) '
                    . 'VALUES (:order_id, :issue, :status)'
                );
                $alterationInsert->execute([
                    'order_id' => $selectedOrderId,
                    'issue' => $formValues['issue'],
                    'status' => 'open',
                ]);
            }

            if ($errors === []) {
                $connection->commit();
                header(
                    'Location: /customer/feedback.php?saved='
                    . ($action === 'save_feedback' ? 'feedback' : 'alteration'),
                    true,
                    303
                );
                exit;
            }

            $connection->rollBack();
            $selectedOrder = delivered_order_for_customer($connection, $selectedOrderId, (int) $user['user_id']);
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if (http_response_code() !== 404) {
                error_log($exception->getMessage());
                $errors['form'] = 'Your submission could not be saved. Please try again.';
            }
            $selectedOrder = delivered_order_for_customer($connection, $selectedOrderId, (int) $user['user_id']);
        }
    }
}

if ($selectedOrderId === null) {
    $ordersStatement = $connection->prepare(
        'SELECT o.order_id, o.status, o.confirmed_at, s.garment_type, p.shop_name, '
        . 'f.feedback_id, f.rating, f.notes '
        . 'FROM orders AS o '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = r.tailor_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'LEFT JOIN fit_feedback AS f ON f.order_id = o.order_id '
        . 'WHERE r.customer_id = :customer_id AND o.status = :delivered '
        . 'ORDER BY o.confirmed_at DESC, o.order_id DESC'
    );
    $ordersStatement->execute(['customer_id' => $user['user_id'], 'delivered' => 'delivered']);
    $orders = $ordersStatement->fetchAll();
} elseif ($selectedOrder !== null && $selectedOrder !== false) {
    $orders = [$selectedOrder];
} else {
    $orders = [];
}

$alterationsByOrder = [];
if ($orders !== []) {
    $alterationStatement = $connection->prepare(
        'SELECT alteration_id, issue, status FROM alteration_requests '
        . 'WHERE order_id = :order_id ORDER BY alteration_id'
    );
    foreach ($orders as $order) {
        $alterationStatement->execute(['order_id' => $order['order_id']]);
        $alterationsByOrder[$order['order_id']] = $alterationStatement->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My feedback and alterations | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>My feedback and alterations</h1>
        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_customer_feedback($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_customer_feedback($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($orders === []): ?>
            <p>Delivered orders will appear here so you can share fit feedback or request an alteration.</p>
        <?php else: ?>
            <?php foreach ($orders as $order): ?>
                <section>
                    <h2>Order #<?= (int) $order['order_id'] ?> — <?= escape_customer_feedback($order['garment_type']) ?></h2>
                    <p>Tailor: <?= escape_customer_feedback($order['shop_name']) ?></p>
                    <p>Status: <?= escape_customer_feedback($order['status']) ?></p>

                    <h3>Fit feedback</h3>
                    <?php if ($order['feedback_id'] !== null): ?>
                        <p>Rating: <?= (int) $order['rating'] ?> out of 5</p>
                        <?php if ($order['notes'] !== null && $order['notes'] !== ''): ?>
                            <p><?= nl2br(escape_customer_feedback($order['notes']), false) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="post" action="/customer/feedback.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_customer_feedback(csrf_token()) ?>">
                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                            <input type="hidden" name="action" value="save_feedback">
                            <div>
                                <label for="rating-<?= (int) $order['order_id'] ?>">Fit rating</label>
                                <select id="rating-<?= (int) $order['order_id'] ?>" name="rating" required>
                                    <option value="">Choose 1 to 5</option>
                                    <?php for ($ratingOption = 1; $ratingOption <= 5; $ratingOption++): ?>
                                        <option value="<?= $ratingOption ?>"<?= $formValues['rating'] === (string) $ratingOption ? ' selected' : '' ?>><?= $ratingOption ?></option>
                                    <?php endfor; ?>
                                </select>
                                <?php if (isset($errors['rating'])): ?>
                                    <p role="alert"><?= escape_customer_feedback($errors['rating']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="notes-<?= (int) $order['order_id'] ?>">Notes (optional)</label>
                                <textarea id="notes-<?= (int) $order['order_id'] ?>" name="notes" maxlength="5000" rows="4"><?= escape_customer_feedback($formValues['notes']) ?></textarea>
                                <?php if (isset($errors['notes'])): ?>
                                    <p role="alert"><?= escape_customer_feedback($errors['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="submit">Submit fit feedback</button>
                        </form>
                    <?php endif; ?>

                    <h3>Alteration requests</h3>
                    <?php if (($alterationsByOrder[$order['order_id']] ?? []) === []): ?>
                        <p>No alteration requests yet.</p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($alterationsByOrder[$order['order_id']] as $alteration): ?>
                                <li>
                                    <strong>Status: <?= escape_customer_feedback($alteration['status']) ?></strong>
                                    — <?= nl2br(escape_customer_feedback($alteration['issue']), false) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <form method="post" action="/customer/feedback.php">
                        <input type="hidden" name="csrf_token" value="<?= escape_customer_feedback(csrf_token()) ?>">
                        <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                        <input type="hidden" name="action" value="create_alteration">
                        <div>
                            <label for="issue-<?= (int) $order['order_id'] ?>">Describe the alteration</label>
                            <textarea id="issue-<?= (int) $order['order_id'] ?>" name="issue" maxlength="2000" rows="4" required><?= escape_customer_feedback($formValues['issue']) ?></textarea>
                            <?php if (isset($errors['issue'])): ?>
                                <p role="alert"><?= escape_customer_feedback($errors['issue']) ?></p>
                            <?php endif; ?>
                        </div>
                        <button type="submit">Request alteration</button>
                    </form>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
