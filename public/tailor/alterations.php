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

function escape_tailor_alteration(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function alteration_for_tailor(PDO $connection, int $alterationId, int $tailorId): array|false
{
    $statement = $connection->prepare(
        'SELECT a.alteration_id, a.order_id, a.issue, a.status, '
        . 's.garment_type, u.name AS customer_name '
        . 'FROM alteration_requests AS a '
        . 'INNER JOIN orders AS o ON o.order_id = a.order_id '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'WHERE a.alteration_id = :alteration_id AND r.tailor_id = :tailor_id'
    );
    $statement->execute(['alteration_id' => $alterationId, 'tailor_id' => $tailorId]);

    return $statement->fetch();
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$alterationIdInput = $requestMethod === 'POST'
    ? ($_POST['alteration_id'] ?? null)
    : ($_GET['alteration_id'] ?? null);
$selectedAlterationId = null;
if ($alterationIdInput !== null) {
    $selectedAlterationId = is_string($alterationIdInput) || is_int($alterationIdInput)
        ? filter_var($alterationIdInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])
        : false;
    if ($selectedAlterationId === false) {
        http_response_code(404);
        exit('Alteration not found.');
    }
}

$selectedAlteration = null;
if ($selectedAlterationId !== null) {
    $selectedAlteration = alteration_for_tailor($connection, $selectedAlterationId, (int) $profile['tailor_id']);
    if ($selectedAlteration === false) {
        http_response_code(404);
        exit('Alteration not found.');
    }
}

$errors = [];
$successMessage = match ($_GET['saved'] ?? '') {
    'status' => 'Alteration status updated.',
    'unchanged' => 'The alteration was already at that status. No change was made.',
    default => null,
};

if ($requestMethod === 'POST') {
    $targetStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    $validStatuses = ['open', 'in_progress', 'done'];
    if (!in_array($targetStatus, $validStatuses, true)) {
        $errors['status'] = 'Choose a valid alteration status.';
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            $lockAlteration = $connection->prepare(
                'SELECT a.alteration_id, a.status FROM alteration_requests AS a '
                . 'INNER JOIN orders AS o ON o.order_id = a.order_id '
                . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
                . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
                . 'WHERE a.alteration_id = :alteration_id AND r.tailor_id = :tailor_id FOR UPDATE'
            );
            $lockAlteration->execute([
                'alteration_id' => $selectedAlterationId,
                'tailor_id' => $profile['tailor_id'],
            ]);
            $lockedAlteration = $lockAlteration->fetch();
            if ($lockedAlteration === false) {
                http_response_code(404);
                throw new RuntimeException('Alteration ownership changed during submission.');
            }

            if ($targetStatus === $lockedAlteration['status']) {
                $connection->rollBack();
                header('Location: /tailor/alterations.php?saved=unchanged', true, 303);
                exit;
            }

            $nextStatus = [
                'open' => 'in_progress',
                'in_progress' => 'done',
                'done' => null,
            ];
            if (($nextStatus[$lockedAlteration['status']] ?? null) !== $targetStatus) {
                $errors['status'] = 'Move the alteration forward by one step at a time.';
            } else {
                $update = $connection->prepare(
                    'UPDATE alteration_requests SET status = :new_status '
                    . 'WHERE alteration_id = :alteration_id AND status = :old_status'
                );
                $update->execute([
                    'new_status' => $targetStatus,
                    'alteration_id' => $selectedAlterationId,
                    'old_status' => $lockedAlteration['status'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Alteration status was not updated.');
                }
            }

            if ($errors === []) {
                $connection->commit();
                header('Location: /tailor/alterations.php?saved=status', true, 303);
                exit;
            }

            $connection->rollBack();
            $selectedAlteration = alteration_for_tailor(
                $connection,
                $selectedAlterationId,
                (int) $profile['tailor_id']
            );
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if (http_response_code() !== 404) {
                error_log($exception->getMessage());
                $errors['form'] = 'The alteration status could not be updated. Please try again.';
            }
            $selectedAlteration = alteration_for_tailor(
                $connection,
                $selectedAlterationId,
                (int) $profile['tailor_id']
            );
        }
    }
}

if ($selectedAlterationId === null) {
    $statement = $connection->prepare(
        'SELECT a.alteration_id, a.order_id, a.issue, a.status, '
        . 's.garment_type, u.name AS customer_name '
        . 'FROM alteration_requests AS a '
        . 'INNER JOIN orders AS o ON o.order_id = a.order_id '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN tailor_services AS s '
        . 'ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'WHERE r.tailor_id = :tailor_id ORDER BY a.alteration_id DESC'
    );
    $statement->execute(['tailor_id' => $profile['tailor_id']]);
    $alterations = $statement->fetchAll();
} elseif ($selectedAlteration !== null && $selectedAlteration !== false) {
    $alterations = [$selectedAlteration];
} else {
    $alterations = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage alterations | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-navigation.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
    <link rel="stylesheet" href="/assets/css/tailor-alterations.css">
</head>
<body class="tailor-alterations-page">
    <a class="skip-link" href="#main">Skip to alteration requests</a>
    <div class="tailor-dashboard-shell">
        <?php $tailorSection = 'alterations'; require dirname(__DIR__, 2) . '/includes/tailor-navigation.php'; ?>
        <main id="main" class="tailor-alterations-main">
            <?php if ($selectedAlterationId !== null): ?><a class="tailor-back-link" href="/tailor/alterations.php">All alteration requests</a><?php endif; ?>
            <header class="alterations-heading"><div><p class="alterations-eyebrow">Customer care</p><h1>Manage alterations</h1><p>Review fit requests and keep customers updated as adjustments are completed.</p></div><span class="alteration-count"><?= count($alterations) ?> <?= count($alterations) === 1 ? 'request' : 'requests' ?></span></header>
        <?php if ($successMessage !== null): ?>
            <p class="alterations-alert alterations-success" role="status"><?= escape_tailor_alteration($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p class="alterations-alert alterations-error" role="alert"><?= escape_tailor_alteration($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($alterations === []): ?>
            <section class="alterations-empty"><span aria-hidden="true">✓</span><h2>No alteration requests yet</h2><p>When a customer requests an adjustment for a delivered order, it will appear here.</p><a class="alterations-secondary-link" href="/tailor/orders.php">View orders <span aria-hidden="true">→</span></a></section>
        <?php else: ?>
            <section class="alteration-list" aria-label="Customer alteration requests">
            <?php foreach ($alterations as $alteration): ?>
                <article class="alteration-card">
                    <header class="alteration-card-heading">
                        <div class="alteration-card-icon" aria-hidden="true">✂</div>
                        <div><p class="alterations-eyebrow">Alteration #<?= (int) $alteration['alteration_id'] ?></p><h2><?= escape_tailor_alteration($alteration['customer_name']) ?></h2><p><?= escape_tailor_alteration($alteration['garment_type']) ?> <span aria-hidden="true">·</span> <a href="/tailor/orders.php?order_id=<?= (int) $alteration['order_id'] ?>">Order ORD-<?= str_pad((string) $alteration['order_id'], 4, '0', STR_PAD_LEFT) ?></a></p></div>
                        <span class="alteration-status status-<?= escape_tailor_alteration($alteration['status']) ?>"><?= escape_tailor_alteration(ucwords(str_replace('_', ' ', $alteration['status']))) ?></span>
<?php $newTailorAlteration = naapsetu_find_notice($tailorUnread, 'alteration:' . (int) $alteration['alteration_id']); ?>
<?php if ($newTailorAlteration !== null): ?><span class="item-unread-pill">New request</span><?php endif; ?>
                    </header>
<div class="alteration-card-body"><h3>Requested adjustment</h3><p><?= nl2br(escape_tailor_alteration($alteration['issue']), false) ?></p></div>

                    <?php if ($alteration['status'] !== 'done'): ?>
                        <form class="alteration-update-form" method="post" action="/tailor/alterations.php">
                            <input type="hidden" name="csrf_token" value="<?= escape_tailor_alteration(csrf_token()) ?>">
                            <input type="hidden" name="alteration_id" value="<?= (int) $alteration['alteration_id'] ?>">
                            <label for="status-<?= (int) $alteration['alteration_id'] ?>">Update status</label>
                            <select id="status-<?= (int) $alteration['alteration_id'] ?>" name="status" required>
                                <option value="<?= escape_tailor_alteration($alteration['status']) ?>" selected><?= escape_tailor_alteration($alteration['status']) ?> (no change)</option>
                                <?php if ($alteration['status'] === 'open'): ?>
                                    <option value="in_progress">in progress</option>
                                <?php elseif ($alteration['status'] === 'in_progress'): ?>
                                    <option value="done">done</option>
                                <?php endif; ?>
                            </select>
                            <?php if (isset($errors['status'])): ?>
                                <p role="alert"><?= escape_tailor_alteration($errors['status']) ?></p>
                            <?php endif; ?>
                            <p class="alteration-status-hint"><?php if ($alteration['status'] === 'open'): ?>Move to in progress when work begins.<?php else: ?>Mark done when the alteration is complete.<?php endif; ?></p>
                            <button class="alteration-save-button" type="submit">Save status <span aria-hidden="true">→</span></button>
                        </form>
                    <?php else: ?>
                        <p class="alteration-complete-note"><span aria-hidden="true">✓</span> This alteration is complete.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            </section>
        <?php endif; ?>
        </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
