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

function escape_request(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function owned_request(PDO $connection, int $requestId, int $tailorId): array|false
{
    $statement = $connection->prepare(
        'SELECT r.request_id, r.status, r.created_at, u.name AS customer_name, '
        . 's.garment_type '
        . 'FROM order_requests AS r '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'WHERE r.request_id = :request_id AND r.tailor_id = :tailor_id '
        . 'AND (r.status = :pending OR (r.status = :quoted AND '
        . '(SELECT q.status FROM quotations AS q WHERE q.request_id = r.request_id '
        . 'ORDER BY q.quotation_id DESC LIMIT 1) = :revision_requested))'
    );
    $statement->execute([
        'request_id' => $requestId,
        'tailor_id' => $tailorId,
        'pending' => 'pending',
        'quoted' => 'quoted',
        'revision_requested' => 'revision_requested',
    ]);

    return $statement->fetch();
}

$errors = [];
$values = ['amount' => '', 'deadline' => ''];
$requestIdInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['request_id'] ?? null)
    : ($_GET['request_id'] ?? null);
$selectedRequestId = null;

if ($requestIdInput !== null) {
    $selectedRequestId = is_string($requestIdInput) || is_int($requestIdInput)
        ? filter_var($requestIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])
        : false;
    if ($selectedRequestId === false) {
        http_response_code(404);
        exit('Request not found.');
    }
}

$selectedRequest = null;
if ($selectedRequestId !== null) {
    $selectedRequest = owned_request($connection, $selectedRequestId, (int) $profile['tailor_id']);
    if ($selectedRequest === false) {
        http_response_code(404);
        exit('Request not found.');
    }
}

$successMessage = isset($_GET['sent']) && $_GET['sent'] === '1'
    ? 'Quotation sent successfully.'
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['amount'] = is_string($_POST['amount'] ?? null) ? trim($_POST['amount']) : '';
    $values['deadline'] = is_string($_POST['deadline'] ?? null) ? trim($_POST['deadline']) : '';

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if (preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $values['amount']) !== 1
        || (float) $values['amount'] <= 0) {
        $errors['amount'] = 'Enter a positive price up to PKR 99,999,999.99.';
    }

    $deadlineDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['deadline']);
    $deadlineErrors = DateTimeImmutable::getLastErrors();
    if ($deadlineDate === false
        || ($deadlineErrors !== false && ($deadlineErrors['warning_count'] > 0 || $deadlineErrors['error_count'] > 0))
        || $deadlineDate->format('Y-m-d') !== $values['deadline']
        || $values['deadline'] < date('Y-m-d')) {
        $errors['deadline'] = 'Enter a valid deadline that is today or later.';
    }

    if (!in_array($selectedRequest['status'], ['pending', 'quoted'], true)) {
        $errors['form'] = 'This request is no longer available for a quotation.';
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $lockStatement = $connection->prepare(
                'SELECT status FROM order_requests '
                . 'WHERE request_id = :request_id AND tailor_id = :tailor_id FOR UPDATE'
            );
            $lockStatement->execute([
                'request_id' => $selectedRequestId,
                'tailor_id' => $profile['tailor_id'],
            ]);
            $lockedRequest = $lockStatement->fetch();

            if ($lockedRequest === false || !in_array($lockedRequest['status'], ['pending', 'quoted'], true)) {
                $errors['form'] = 'This request is no longer available for a quotation.';
            } else {
                $latestQuotation = $connection->prepare(
                    'SELECT quotation_id, status FROM quotations '
                    . 'WHERE request_id = :request_id ORDER BY quotation_id DESC LIMIT 1 FOR UPDATE'
                );
                $latestQuotation->execute(['request_id' => $selectedRequestId]);
                $latest = $latestQuotation->fetch();
                $canSendInitial = $lockedRequest['status'] === 'pending' && $latest === false;
                $canSendRevision = $lockedRequest['status'] === 'quoted'
                    && $latest !== false
                    && $latest['status'] === 'revision_requested';

                if (!$canSendInitial && !$canSendRevision) {
                    $errors['form'] = 'Only a new request or a requested revision can receive a quotation.';
                } else {
                    $insert = $connection->prepare(
                        'INSERT INTO quotations (request_id, amount, deadline, status) '
                        . 'VALUES (:request_id, :amount, :deadline, :status)'
                    );
                    $insert->execute([
                        'request_id' => $selectedRequestId,
                        'amount' => $values['amount'],
                        'deadline' => $values['deadline'],
                        'status' => 'sent',
                    ]);

                    if ($canSendInitial) {
                        $update = $connection->prepare(
                            'UPDATE order_requests SET status = :quoted '
                            . 'WHERE request_id = :request_id AND tailor_id = :tailor_id AND status = :pending'
                        );
                        $update->execute([
                            'quoted' => 'quoted',
                            'request_id' => $selectedRequestId,
                            'tailor_id' => $profile['tailor_id'],
                            'pending' => 'pending',
                        ]);

                        if ($update->rowCount() !== 1) {
                            throw new RuntimeException('The request status could not be updated.');
                        }
                    }
                }
            }

            if ($errors === []) {
                $connection->commit();
                header('Location: /tailor/requests.php?sent=1', true, 303);
                exit;
            }

            $connection->rollBack();
            $selectedRequest = owned_request($connection, $selectedRequestId, (int) $profile['tailor_id']);
            if ($selectedRequest === false) {
                $selectedRequest = null;
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $errors['form'] = 'The quotation could not be sent. Please try again.';
            $selectedRequest = owned_request($connection, $selectedRequestId, (int) $profile['tailor_id']);
            if ($selectedRequest === false) {
                $selectedRequest = null;
            }
        }
    }
}

if ($selectedRequestId === null) {
    $statement = $connection->prepare(
        'SELECT r.request_id, r.status, r.created_at, u.name AS customer_name, s.garment_type '
        . 'FROM order_requests AS r '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'WHERE r.tailor_id = :tailor_id AND (r.status = :pending OR '
        . '(r.status = :quoted AND (SELECT q.status FROM quotations AS q '
        . 'WHERE q.request_id = r.request_id ORDER BY q.quotation_id DESC LIMIT 1) = :revision_requested)) '
        . 'ORDER BY r.created_at DESC, r.request_id DESC'
    );
    $statement->execute([
        'tailor_id' => $profile['tailor_id'],
        'pending' => 'pending',
        'quoted' => 'quoted',
        'revision_requested' => 'revision_requested',
    ]);
    $requests = $statement->fetchAll();
} elseif ($selectedRequest !== null) {
    $requests = [$selectedRequest];
} else {
    $requests = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Incoming requests | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/tailor/dashboard.php">Back to dashboard</a>
        <h1>Incoming requests</h1>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_request($successMessage) ?></p>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_request($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($requests === []): ?>
            <p>You have no requests awaiting a quotation.</p>
        <?php else: ?>
            <?php foreach ($requests as $request): ?>
                <section>
                    <h2>Request #<?= (int) $request['request_id'] ?></h2>
                    <p>Customer: <?= escape_request($request['customer_name']) ?></p>
                    <p>Garment: <?= escape_request($request['garment_type']) ?></p>
                    <p>Status: <?= escape_request($request['status']) ?></p>
                    <p>Date: <time datetime="<?= escape_request($request['created_at']) ?>"><?= escape_request($request['created_at']) ?></time></p>

                    <?php if ($request['status'] === 'pending' || $request['status'] === 'quoted'): ?>
                        <h3><?= $request['status'] === 'pending' ? 'Send quotation' : 'Send revised quotation' ?></h3>
                        <form method="post" action="/tailor/requests.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_request(csrf_token()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                            <div>
                                <label for="amount-<?= (int) $request['request_id'] ?>">Price (PKR)</label>
                                <input id="amount-<?= (int) $request['request_id'] ?>" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" required value="<?= escape_request($values['amount']) ?>">
                                <?php if (isset($errors['amount'])): ?>
                                    <p role="alert"><?= escape_request($errors['amount']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label for="deadline-<?= (int) $request['request_id'] ?>">Completion deadline</label>
                                <input id="deadline-<?= (int) $request['request_id'] ?>" name="deadline" type="date" min="<?= escape_request(date('Y-m-d')) ?>" required value="<?= escape_request($values['deadline']) ?>">
                                <?php if (isset($errors['deadline'])): ?>
                                    <p role="alert"><?= escape_request($errors['deadline']) ?></p>
                                <?php endif; ?>
                            </div>
                            <button type="submit"><?= $request['status'] === 'pending' ? 'Send quotation' : 'Send revised quotation' ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
