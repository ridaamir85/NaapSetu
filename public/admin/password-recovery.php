<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$user = require_role('admin');
$db = database();

function escape_admin_recovery(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
$error = null;
$success = ($_GET['updated'] ?? '') === '1';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = filter_var($_POST['recovery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = $_POST['action'] ?? null;
    if (!is_string($_POST['csrf_token'] ?? null) || !csrf_token_is_valid($_POST['csrf_token'])) {
        $error = 'Session expired. Refresh and try again.';
    } elseif ($id === false || !in_array($action, ['resolved', 'dismissed'], true)) {
        $error = 'Choose a valid request and action.';
    } else {
        try {
            $statement = $db->prepare(
                'UPDATE password_recovery_requests SET status = :status, '
                . 'handled_at = NOW(), handled_by = :admin_id '
                . 'WHERE recovery_id = :id AND status = \'pending\''
            );
            $statement->execute(['status' => $action, 'admin_id' => (int) $user['user_id'], 'id' => $id]);
            if ($statement->rowCount() === 1) {
                header('Location: /admin/password-recovery.php?updated=1', true, 303);
                exit;
            }
            $error = 'This request is already handled or no longer available.';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Request could not be updated.';
        }
    }
}
$statement = $db->query(
    'SELECT r.recovery_id, r.requested_at, u.name, u.email, u.role, u.is_active '
    . 'FROM password_recovery_requests AS r '
    . 'JOIN users AS u ON u.user_id = r.user_id '
    . 'WHERE r.status = \'pending\' ORDER BY r.requested_at ASC, r.recovery_id ASC'
);
$requests = $statement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password recovery requests | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
    <link rel="stylesheet" href="/assets/css/admin-recovery.css">
</head>
<body class="admin-page">
<a class="skip-link" href="#main">Skip to recovery requests</a>
<div class="admin-shell">
    <?php $adminSection = 'recovery'; require dirname(__DIR__, 2) . '/includes/admin-navigation.php'; ?>
    <main id="main" class="admin-main">
        <header class="admin-heading">
            <div><p class="admin-eyebrow">Account security</p><h1>Password recovery requests</h1>
            <p>Review pending requests and verify ownership before issuing temporary passwords.</p></div>
        </header>
        <?php if ($success): ?><p class="admin-alert admin-alert-success" role="status">Recovery request updated.</p><?php endif; ?>
        <?php if ($error !== null): ?><p class="admin-alert admin-alert-error" role="alert"><?= escape_admin_recovery($error) ?></p><?php endif; ?>
        <section class="admin-recovery-guide" aria-label="Recovery instructions">
            <h2>Administrator process</h2>
            <p>Contact the account holder through a separately verified channel. Only after verification, run <code>php database/reset_password.php</code> in the Ubuntu project terminal and deliver the temporary password privately. Then mark the request resolved.</p>
            <p>The website does not email temporary passwords or verify account ownership automatically.</p>
        </section>
        <section class="admin-list-panel" aria-labelledby="recovery-list-title">
            <header class="admin-panel-heading"><div><p class="admin-eyebrow">Pending</p><h2 id="recovery-list-title"><?= count($requests) ?> recovery request<?= count($requests) === 1 ? '' : 's' ?></h2></div><a href="/admin/dashboard.php">Admin overview</a></header>
            <?php if ($requests === []): ?>
                <div class="admin-empty"><span aria-hidden="true">✓</span><h3>No pending requests</h3><p>New password recovery requests will appear here.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap"><table class="admin-table recovery-table">
                    <thead><tr><th scope="col">Name</th><th scope="col">Registered email</th><th scope="col">Role</th><th scope="col">Requested</th><th scope="col">Action</th></tr></thead>
                    <tbody><?php foreach ($requests as $item): ?>
                        <tr>
                            <td><strong><?= escape_admin_recovery((string) $item['name']) ?></strong></td>
                            <td><?= escape_admin_recovery((string) $item['email']) ?></td>
                            <td><?= escape_admin_recovery(ucfirst((string) $item['role'])) ?></td>
                            <td><?= escape_admin_recovery((string) $item['requested_at']) ?></td>
                            <td><form method="post" action="/admin/password-recovery.php" class="recovery-actions">
                                <input type="hidden" name="csrf_token" value="<?= escape_admin_recovery(csrf_token()) ?>">
                                <input type="hidden" name="recovery_id" value="<?= (int) $item['recovery_id'] ?>">
                                <button type="submit" name="action" value="resolved">Mark resolved</button>
                                <button type="submit" name="action" value="dismissed" class="recovery-dismiss">Dismiss</button>
                            </form></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="/assets/js/usability.js" defer></script>
</body></html>
