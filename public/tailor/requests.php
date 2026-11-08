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

/** @return array<string, string> */
function style_preferences_for_request(array $request): array
{
    if (!isset($request['style_preferences'])) {
        return [];
    }

    $decoded = json_decode((string) $request['style_preferences'], true);
    if (!is_array($decoded)) {
        return [];
    }

    $preferences = [];
    foreach ($decoded as $key => $value) {
        if (is_scalar($value)) {
            $preferences[(string) $key] = (string) $value;
        }
    }

    return $preferences;
}

/** @return array<int, array<string, mixed>> */
function shared_request_measurements(PDO $connection, array $request, int $tailorId): array
{
    $statement = $connection->prepare(
        'SELECT p.profile_name, p.gender, v.version_no, v.created_at AS version_created_at, '
        . 'mv.measurement_name, mv.value_cm '
        . 'FROM measurement_permissions AS permission '
        . 'INNER JOIN measurement_profiles AS p '
        . 'ON p.profile_id = permission.profile_id AND p.customer_id = :customer_id '
        . 'LEFT JOIN measurement_versions AS v ON v.profile_id = p.profile_id '
        . 'AND v.version_no = (SELECT MAX(v2.version_no) FROM measurement_versions AS v2 WHERE v2.profile_id = p.profile_id) '
        . 'LEFT JOIN measurement_values AS mv ON mv.version_id = v.version_id '
        . 'WHERE permission.profile_id = :profile_id AND permission.tailor_id = :tailor_id '
        . 'AND permission.revoked_at IS NULL ORDER BY mv.measurement_name'
    );
    $statement->execute([
        'customer_id' => $request['customer_id'],
        'profile_id' => $request['profile_id'],
        'tailor_id' => $tailorId,
    ]);

    return $statement->fetchAll();
}

function owned_request(PDO $connection, int $requestId, int $tailorId): array|false
{
    $statement = $connection->prepare(
        'SELECT r.request_id, r.customer_id, r.profile_id, r.style_id, r.status, r.created_at, '
        . 'r.fabric_source, r.customer_notes, '
        . 'sp.name AS style_name, sp.preferences AS style_preferences, u.name AS customer_name, '
        . 's.garment_type, (SELECT mp.permission_id FROM measurement_permissions AS mp '
        . 'WHERE mp.profile_id = r.profile_id AND mp.tailor_id = r.tailor_id '
        . 'AND mp.revoked_at IS NULL LIMIT 1) AS active_permission_id '
        . 'FROM order_requests AS r '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'LEFT JOIN style_profiles AS sp '
        . 'ON sp.style_id = r.style_id AND sp.customer_id = r.customer_id '
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
                http_response_code(404);
                exit('Request not found.');
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $errors['form'] = 'The quotation could not be sent. Please try again.';
            $selectedRequest = owned_request($connection, $selectedRequestId, (int) $profile['tailor_id']);
            if ($selectedRequest === false) {
                http_response_code(404);
                exit('Request not found.');
            }
        }
    }
}

if ($selectedRequestId === null) {
    $statement = $connection->prepare(
        'SELECT r.request_id, r.profile_id, r.style_id, r.status, r.created_at, '
        . 'r.fabric_source, r.customer_notes, '
        . 'sp.name AS style_name, sp.preferences AS style_preferences, '
        . 'u.name AS customer_name, s.garment_type, '
        . '(SELECT mp.permission_id FROM measurement_permissions AS mp '
        . 'WHERE mp.profile_id = r.profile_id AND mp.tailor_id = r.tailor_id '
        . 'AND mp.revoked_at IS NULL LIMIT 1) AS active_permission_id '
        . 'FROM order_requests AS r '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'LEFT JOIN style_profiles AS sp '
        . 'ON sp.style_id = r.style_id AND sp.customer_id = r.customer_id '
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

$sharedMeasurements = [];
if ($selectedRequest !== null && $selectedRequest !== false && $selectedRequest['active_permission_id'] !== null) {
    $sharedMeasurements = shared_request_measurements(
        $connection,
        $selectedRequest,
        (int) $profile['tailor_id']
    );
}

$customerSection = 'requests';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $selectedRequest !== null && $selectedRequest !== false ? 'Request details' : 'Incoming requests' ?> | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-requests.css">
</head>
<body class="tailor-requests-page">
    <a class="skip-link" href="#main">Skip to requests</a>
    <header class="requests-topbar">
        <a class="requests-wordmark" href="/tailor/dashboard.php">NaapSetu</a>
        <nav aria-label="Tailor navigation">
            <a href="/tailor/dashboard.php">Dashboard</a>
            <a href="/tailor/requests.php" aria-current="page">Requests</a>
            <a href="/tailor/orders.php">Orders</a>
            <a href="/tailor/services.php">Services</a>
            <a href="/tailor/profile.php">Profile</a>
        </nav>
    </header>
    <main id="main" class="tailor-requests-main">
        <?php if ($selectedRequest !== null && $selectedRequest !== false): ?>
            <a class="request-back-link" href="/tailor/requests.php"><span aria-hidden="true">←</span> All incoming requests</a>
            <header class="request-page-heading">
                <div><p class="requests-eyebrow">Review and respond</p><h1>Request detail &amp; quotation</h1><p>Check the customer’s requirements before preparing a price and completion date.</p></div>
                <span class="request-state status-<?= escape_request((string) $selectedRequest['status']) ?>"><?= escape_request(ucwords(str_replace('_', ' ', (string) $selectedRequest['status']))) ?></span>
            </header>

        <?php if ($successMessage !== null): ?>
            <p class="request-alert request-success" role="status"><?= escape_request($successMessage) ?></p>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <p class="request-alert request-error" role="alert"><?= escape_request($errors['form']) ?></p>
        <?php endif; ?>

            <div class="request-detail-layout">
                <div class="request-detail-content">
                    <section class="request-detail-card" aria-labelledby="customer-summary-title">
                        <div class="request-card-title"><span class="request-card-icon" aria-hidden="true">▤</span><div><p class="requests-eyebrow">Request #<?= (int) $selectedRequest['request_id'] ?></p><h2 id="customer-summary-title">Customer request summary</h2></div></div>
                        <dl class="request-summary-facts">
                            <div><dt>Customer</dt><dd><?= escape_request($selectedRequest['customer_name']) ?></dd></div>
                            <div><dt>Service</dt><dd><?= escape_request($selectedRequest['garment_type']) ?></dd></div>
                            <div><dt>Requested on</dt><dd><time datetime="<?= escape_request($selectedRequest['created_at']) ?>"><?= escape_request(date('M j, Y', strtotime($selectedRequest['created_at']))) ?></time></dd></div>
                            <div><dt>Fabric</dt><dd><?= $selectedRequest['fabric_source'] === 'tailor' ? 'Tailor to arrange fabric' : 'Customer will provide fabric' ?></dd></div>
                        </dl>
                    </section>

                    <section class="request-detail-card" aria-labelledby="style-notes-title">
                        <div class="request-card-title"><span class="request-card-icon" aria-hidden="true">✂</span><div><p class="requests-eyebrow">Customer preferences</p><h2 id="style-notes-title">Style &amp; fabric notes</h2></div></div>
                        <?php if ($selectedRequest['style_id'] !== null && $selectedRequest['style_name'] !== null): ?>
                            <h3 class="request-style-name"><?= escape_request($selectedRequest['style_name']) ?></h3>
                            <?php $stylePreferences = style_preferences_for_request($selectedRequest); ?>
                            <?php if ($stylePreferences !== []): ?>
                                <dl class="request-style-facts">
                                    <?php foreach ($stylePreferences as $preference => $value): ?>
                                        <div><dt><?= escape_request(ucwords(str_replace('_', ' ', $preference))) ?></dt><dd><?= escape_request($value) ?></dd></div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php else: ?>
                                <p class="request-muted">No preferences were saved in this style profile.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="request-muted">The customer didn’t select a saved style profile.</p>
                        <?php endif; ?>
                        <div class="request-fabric-note"><strong>Fabric supply</strong><p><?= $selectedRequest['fabric_source'] === 'tailor' ? 'The tailor is asked to arrange fabric.' : 'The customer plans to provide fabric.' ?></p></div>
                        <?php if (is_string($selectedRequest['customer_notes']) && trim($selectedRequest['customer_notes']) !== ''): ?>
                            <div class="request-fabric-note"><strong>Customer notes</strong><p><?= nl2br(escape_request($selectedRequest['customer_notes'])) ?></p></div>
                        <?php else: ?>
                            <div class="request-fabric-note"><strong>Customer notes</strong><p class="request-muted">No additional notes were provided.</p></div>
                        <?php endif; ?>
                    </section>

                    <section class="request-detail-card" aria-labelledby="measurements-title">
                        <div class="request-card-title"><span class="request-card-icon" aria-hidden="true">⌁</span><div><p class="requests-eyebrow">Measurement access</p><h2 id="measurements-title">Permitted measurements</h2></div></div>
                        <?php if ($selectedRequest['active_permission_id'] !== null && $sharedMeasurements !== []): ?>
                            <?php $measurementProfile = $sharedMeasurements[0]; ?>
                            <p class="measurement-version"><?= escape_request($measurementProfile['profile_name']) ?><?php if ($measurementProfile['version_no'] !== null): ?> · Version <?= (int) $measurementProfile['version_no'] ?><?php endif; ?><?php if ($measurementProfile['version_created_at'] !== null): ?> · <?= escape_request(date('M j, Y', strtotime($measurementProfile['version_created_at']))) ?><?php endif; ?></p>
                            <?php $measurementValues = array_filter($sharedMeasurements, static fn (array $measurement): bool => $measurement['measurement_name'] !== null); ?>
                            <?php if ($measurementValues !== []): ?>
                                <dl class="measurement-grid">
                                    <?php foreach ($measurementValues as $measurement): ?>
                                        <div><dt><?= escape_request($measurement['measurement_name']) ?></dt><dd><?= escape_request((string) $measurement['value_cm']) ?> cm</dd></div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php else: ?>
                                <p class="request-muted">This profile has no saved measurement values yet.</p>
                            <?php endif; ?>
                            <a class="request-secondary-link" href="/tailor/measurements.php?profile_id=<?= (int) $selectedRequest['profile_id'] ?>">Open shared measurement profile <span aria-hidden="true">→</span></a>
                        <?php else: ?>
                            <p class="request-permission-note">The customer hasn’t granted your shop permission to view this measurement profile. You can still review the other request details.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <aside class="quotation-panel" aria-labelledby="quotation-title">
                    <p class="requests-eyebrow">Your response</p>
                    <h2 id="quotation-title"><?= $selectedRequest['status'] === 'pending' ? 'Quotation details' : 'Revised quotation' ?></h2>
                    <p class="quotation-intro">Set your price and the date you expect to complete this garment.</p>
                    <?php if ($selectedRequest['status'] === 'pending' || $selectedRequest['status'] === 'quoted'): ?>
                        <form class="quotation-form" method="post" action="/tailor/requests.php?request_id=<?= (int) $selectedRequest['request_id'] ?>" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape_request(csrf_token()) ?>">
                            <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['request_id'] ?>">
                            <div class="quotation-field">
                                <label for="amount">Price (PKR)</label>
                                <div class="price-input"><span aria-hidden="true">PKR</span><input id="amount" name="amount" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required value="<?= escape_request($values['amount']) ?>" placeholder="e.g. 4,500"></div>
                                <?php if (isset($errors['amount'])): ?><p class="quotation-field-error" role="alert"><?= escape_request($errors['amount']) ?></p><?php endif; ?>
                            </div>
                            <div class="quotation-field">
                                <label for="deadline">Completion date</label>
                                <input id="deadline" name="deadline" type="date" min="<?= escape_request(date('Y-m-d')) ?>" required value="<?= escape_request($values['deadline']) ?>">
                                <?php if (isset($errors['deadline'])): ?><p class="quotation-field-error" role="alert"><?= escape_request($errors['deadline']) ?></p><?php endif; ?>
                            </div>
                            <p class="quotation-footnote"><span aria-hidden="true">ⓘ</span> An order is created only after the customer accepts your quotation.</p>
                            <button class="button quotation-submit" type="submit"><?= $selectedRequest['status'] === 'pending' ? 'Send quotation' : 'Send revised quotation' ?><span aria-hidden="true">→</span></button>
                        </form>
                    <?php else: ?>
                        <p class="request-muted">This request is no longer awaiting a quotation.</p>
                    <?php endif; ?>
                </aside>
            </div>
        <?php else: ?>
            <a class="request-back-link" href="/tailor/dashboard.php"><span aria-hidden="true">←</span> Back to dashboard</a>
            <header class="request-page-heading">
                <div><p class="requests-eyebrow">Tailor workspace</p><h1>Incoming requests</h1><p>Review customer requirements and prepare your quotations.</p></div>
                <span class="incoming-count"><?= count($requests) ?> awaiting response</span>
            </header>
            <?php if ($successMessage !== null): ?><p class="request-alert request-success" role="status"><?= escape_request($successMessage) ?></p><?php endif; ?>
            <?php if (isset($errors['form'])): ?><p class="request-alert request-error" role="alert"><?= escape_request($errors['form']) ?></p><?php endif; ?>
            <?php if ($requests === []): ?>
                <section class="requests-empty" aria-labelledby="requests-empty-title"><span class="requests-empty-mark" aria-hidden="true">✓</span><h2 id="requests-empty-title">You’re all caught up</h2><p>No requests are currently awaiting a quotation or revision.</p><a class="button" href="/tailor/dashboard.php">Return to dashboard</a></section>
            <?php else: ?>
                <section class="incoming-request-list" aria-label="Incoming requests">
                    <?php foreach ($requests as $request): ?>
                        <article class="incoming-request-card">
                            <div class="incoming-request-main">
                                <div class="incoming-request-avatar" aria-hidden="true"><?= escape_request(mb_strtoupper(mb_substr($request['customer_name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                                <div><p class="requests-eyebrow">Request #<?= (int) $request['request_id'] ?></p><h2><?= escape_request($request['customer_name']) ?></h2><p class="incoming-request-service"><?= escape_request($request['garment_type']) ?></p></div>
                            </div>
                            <div class="incoming-request-meta"><span class="request-state status-<?= escape_request((string) $request['status']) ?>"><?= escape_request($request['status'] === 'pending' ? 'New request' : 'Revision requested') ?></span><span>Sent <?= escape_request(date('M j, Y', strtotime($request['created_at']))) ?></span><span><?= $request['fabric_source'] === 'tailor' ? 'Tailor-arranged fabric' : 'Customer-supplied fabric' ?></span></div>
                            <?php if (is_string($request['customer_notes']) && trim($request['customer_notes']) !== ''): ?><p class="incoming-request-note"><?= escape_request($request['customer_notes']) ?></p><?php endif; ?>
                            <a class="button incoming-request-action" href="/tailor/requests.php?request_id=<?= (int) $request['request_id'] ?>">Review request <span aria-hidden="true">→</span></a>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
