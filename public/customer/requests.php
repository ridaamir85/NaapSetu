<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_customer_request(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function owned_customer_request(PDO $connection, int $requestId, int $customerId): array|false
{
    $statement = $connection->prepare(
        'SELECT request_id, customer_id, profile_id, style_id, status '
        . 'FROM order_requests WHERE request_id = :request_id AND customer_id = :customer_id'
    );
    $statement->execute(['request_id' => $requestId, 'customer_id' => $customerId]);

    return $statement->fetch();
}

$errors = [];
$successMessage = match ($_GET['updated'] ?? '') {
    'accepted' => 'Quotation accepted. Your order has been created.',
    'rejected' => 'Quotation rejected.',
    'revision_requested' => 'Revision requested. The tailor can send a revised quotation.',
    default => null,
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    $quotationId = filter_var($_POST['quotation_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($requestId === false || $quotationId === false) {
        http_response_code(404);
        exit('Request or quotation not found.');
    }

    $request = owned_customer_request($connection, $requestId, (int) $user['user_id']);
    if ($request === false) {
        http_response_code(404);
        exit('Request not found.');
    }

    $quotationCheck = $connection->prepare(
        'SELECT quotation_id, status FROM quotations '
        . 'WHERE quotation_id = :quotation_id AND request_id = :request_id'
    );
    $quotationCheck->execute(['quotation_id' => $quotationId, 'request_id' => $requestId]);
    $quotation = $quotationCheck->fetch();
    if ($quotation === false) {
        http_response_code(404);
        exit('Quotation not found.');
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['accepted', 'rejected', 'revision_requested'], true)) {
        $errors['form'] = 'Choose a valid quotation action.';
    } elseif ($quotation['status'] !== 'sent') {
        $errors['form'] = 'This quotation is no longer available for a response.';
    } elseif ($request['status'] !== 'quoted') {
        $errors['form'] = 'This request is no longer awaiting a quotation response.';
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $lockRequest = $connection->prepare(
                'SELECT request_id, profile_id, style_id, status FROM order_requests '
                . 'WHERE request_id = :request_id AND customer_id = :customer_id FOR UPDATE'
            );
            $lockRequest->execute([
                'request_id' => $requestId,
                'customer_id' => $user['user_id'],
            ]);
            $lockedRequest = $lockRequest->fetch();

            $lockQuotation = $connection->prepare(
                'SELECT quotation_id, status FROM quotations '
                . 'WHERE quotation_id = :quotation_id AND request_id = :request_id FOR UPDATE'
            );
            $lockQuotation->execute(['quotation_id' => $quotationId, 'request_id' => $requestId]);
            $lockedQuotation = $lockQuotation->fetch();

            if ($lockedRequest === false || $lockedQuotation === false) {
                http_response_code(404);
                throw new RuntimeException('Request or quotation ownership changed during submission.');
            }
            if ($lockedRequest['status'] !== 'quoted' || $lockedQuotation['status'] !== 'sent') {
                $errors['form'] = 'This quotation has already been handled.';
            }

            if ($errors === [] && $action === 'accepted') {
                $existingOrder = $connection->prepare(
                    'SELECT o.order_id FROM orders AS o '
                    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
                    . 'WHERE q.request_id = :request_id LIMIT 1'
                );
                $existingOrder->execute(['request_id' => $requestId]);
                if ($existingOrder->fetch() !== false) {
                    $errors['form'] = 'An order has already been created for this request.';
                }

                $versionStatement = $connection->prepare(
                    'SELECT v.version_id FROM measurement_versions AS v '
                    . 'INNER JOIN measurement_profiles AS p ON p.profile_id = v.profile_id '
                    . 'WHERE v.profile_id = :profile_id AND p.customer_id = :customer_id '
                    . 'ORDER BY v.version_no DESC LIMIT 1'
                );
                $versionStatement->execute([
                    'profile_id' => $lockedRequest['profile_id'],
                    'customer_id' => $user['user_id'],
                ]);
                $measurementVersionId = $versionStatement->fetchColumn();
                if ($measurementVersionId === false) {
                    $errors['form'] = 'This request has no saved measurement version, so an order cannot be created.';
                }

                $styleSnapshot = '{}';
                if ($lockedRequest['style_id'] !== null) {
                    $styleStatement = $connection->prepare(
                        'SELECT name, preferences FROM style_profiles '
                        . 'WHERE style_id = :style_id AND customer_id = :customer_id'
                    );
                    $styleStatement->execute([
                        'style_id' => $lockedRequest['style_id'],
                        'customer_id' => $user['user_id'],
                    ]);
                    $style = $styleStatement->fetch();
                    if ($style === false) {
                        $errors['form'] = 'The style profile for this request could not be verified.';
                    } else {
                        $styleSnapshot = json_encode([
                            'name' => $style['name'],
                            'preferences' => json_decode((string) $style['preferences'], true, 512, JSON_THROW_ON_ERROR),
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    }
                }
            }

            if ($errors === []) {
                $quotationUpdate = $connection->prepare(
                    'UPDATE quotations SET status = :status '
                    . 'WHERE quotation_id = :quotation_id AND request_id = :request_id AND status = :sent'
                );
                $quotationUpdate->execute([
                    'status' => $action,
                    'quotation_id' => $quotationId,
                    'request_id' => $requestId,
                    'sent' => 'sent',
                ]);
                if ($quotationUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Quotation response was not saved.');
                }

                if ($action === 'accepted') {
                    $orderInsert = $connection->prepare(
                        'INSERT INTO orders (quotation_id, measurement_version_id, style_snapshot) '
                        . 'VALUES (:quotation_id, :measurement_version_id, :style_snapshot)'
                    );
                    $orderInsert->execute([
                        'quotation_id' => $quotationId,
                        'measurement_version_id' => $measurementVersionId,
                        'style_snapshot' => $styleSnapshot,
                    ]);
                    $orderId = (int) $connection->lastInsertId();
                    $initialHistory = $connection->prepare(
                        'INSERT INTO order_status_history (order_id, status) '
                        . 'VALUES (:order_id, :status)'
                    );
                    $initialHistory->execute(['order_id' => $orderId, 'status' => 'confirmed']);
                } elseif ($action === 'rejected') {
                    $requestUpdate = $connection->prepare(
                        'UPDATE order_requests SET status = :declined '
                        . 'WHERE request_id = :request_id AND customer_id = :customer_id AND status = :quoted'
                    );
                    $requestUpdate->execute([
                        'declined' => 'declined',
                        'request_id' => $requestId,
                        'customer_id' => $user['user_id'],
                        'quoted' => 'quoted',
                    ]);
                    if ($requestUpdate->rowCount() !== 1) {
                        throw new RuntimeException('Request rejection status was not saved.');
                    }
                }

                $connection->commit();
                header('Location: /customer/requests.php?updated=' . $action, true, 303);
                exit;
            }

            $connection->rollBack();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if (http_response_code() !== 404) {
                error_log($exception->getMessage());
                $errors['form'] = 'Your response could not be saved. Please try again.';
            }
        }
    }
}

$requestsStatement = $connection->prepare(
    'SELECT r.request_id, r.status AS request_status, r.created_at, '
    . 'p.shop_name, s.garment_type '
    . 'FROM order_requests AS r '
    . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = r.tailor_id '
    . 'INNER JOIN tailor_services AS s '
    . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
    . 'WHERE r.customer_id = :customer_id ORDER BY r.created_at DESC, r.request_id DESC'
);
$requestsStatement->execute(['customer_id' => $user['user_id']]);
$requests = $requestsStatement->fetchAll();

$quotationStatement = $connection->prepare(
    'SELECT quotation_id, amount, deadline, status FROM quotations '
    . 'WHERE request_id = :request_id ORDER BY quotation_id ASC'
);
foreach ($requests as &$request) {
    $quotationStatement->execute(['request_id' => $request['request_id']]);
    $request['quotations'] = $quotationStatement->fetchAll();
}
unset($request);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My requests and quotations | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>My requests and quotations</h1>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_customer_request($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_customer_request($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($requests === []): ?>
            <p>You have not submitted any stitching requests yet.</p>
            <p><a href="/customer/tailors.php">Browse tailors</a></p>
        <?php else: ?>
            <?php foreach ($requests as $request): ?>
                <section>
                    <h2><?= escape_customer_request($request['shop_name']) ?> — <?= escape_customer_request($request['garment_type']) ?></h2>
                    <p>Status: <?= escape_customer_request($request['request_status']) ?></p>
                    <p>Date: <time datetime="<?= escape_customer_request($request['created_at']) ?>"><?= escape_customer_request($request['created_at']) ?></time></p>

                    <?php if ($request['quotations'] === []): ?>
                        <p>No quotation yet.</p>
                    <?php else: ?>
                        <h3>Quotations</h3>
                        <?php foreach ($request['quotations'] as $quotation): ?>
                            <article>
                                <h4>Quotation #<?= (int) $quotation['quotation_id'] ?></h4>
                                <p>Price: PKR <?= escape_customer_request(number_format((float) $quotation['amount'], 2)) ?></p>
                                <p>Completion deadline: <time datetime="<?= escape_customer_request($quotation['deadline']) ?>"><?= escape_customer_request($quotation['deadline']) ?></time></p>
                                <p>Status: <?= escape_customer_request($quotation['status']) ?></p>

                                <?php if ($quotation['status'] === 'sent' && $request['request_status'] === 'quoted'): ?>
                                    <form method="post" action="/customer/requests.php">
                                        <input type="hidden" name="csrf_token" value="<?= escape_customer_request(csrf_token()) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                        <input type="hidden" name="quotation_id" value="<?= (int) $quotation['quotation_id'] ?>">
                                        <button type="submit" name="action" value="accepted">Accept quotation</button>
                                        <button type="submit" name="action" value="rejected">Reject quotation</button>
                                        <button type="submit" name="action" value="revision_requested">Request revision</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
