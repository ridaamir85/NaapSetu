<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';
$user = require_role('admin');
$db = database();

// A newly issued password must never be cached or retained in a session, URL or log.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

function escape_admin_recovery(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$error = null;
$issued = null;
$success = ($_GET['updated'] ?? '') === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = filter_var($_POST['recovery_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $token = $_POST['csrf_token'] ?? null;

    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($id === false || !in_array($action, ['issue', 'dismissed'], true)) {
        $error = 'Choose a valid recovery request and action.';
    } elseif ($action === 'dismissed') {
        $statement = $db->prepare(
            "UPDATE password_recovery_requests SET status = 'dismissed', "
            . 'handled_at = NOW(), handled_by = :admin '
            . "WHERE recovery_id = :id AND status = 'pending'"
        );
        $statement->execute(['admin' => (int) $user['user_id'], 'id' => $id]);
        if ($statement->rowCount() === 1) {
            redirect_to('/admin/password-recovery.php?updated=1');
        }
        $error = 'This request is no longer pending.';
    } else {
        $adminPassword = is_string($_POST['admin_password'] ?? null)
            ? $_POST['admin_password'] : '';
        $verified = ($_POST['verified_owner'] ?? '') === 'yes';
        if (!$verified) {
            $error = 'Verify the account owner through a trusted channel before issuing a password.';
        } elseif ($adminPassword === '') {
            $error = 'Enter your administrator password to authorize this reset.';
        } else {
            $adminCheck = $db->prepare(
                "SELECT password_hash FROM users "
                . "WHERE user_id = :id AND role = 'admin' AND is_active = 1 LIMIT 1"
            );
            $adminCheck->execute(['id' => (int) $user['user_id']]);
            $adminAccount = $adminCheck->fetch();
            if ($adminAccount === false || !password_verify($adminPassword, (string) $adminAccount['password_hash'])) {
                $error = 'Administrator password is incorrect.';
            } else {
                try {
                    $db->beginTransaction();
                    // Lock both the request and its target account against parallel resets.
                    $lock = $db->prepare(
                        'SELECT r.recovery_id, r.user_id, u.name, u.email, u.role, u.is_active '
                        . 'FROM password_recovery_requests r '
                        . 'INNER JOIN users u ON u.user_id = r.user_id '
                        . "WHERE r.recovery_id = :id AND r.status = 'pending' FOR UPDATE"
                    );
                    $lock->execute(['id' => $id]);
                    $target = $lock->fetch();
                    if ($target === false || (int) $target['is_active'] !== 1
                        || !in_array($target['role'], ['customer', 'tailor'], true)) {
                        throw new RuntimeException('The request is no longer eligible for a password reset.');
                    }

                    $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
                    $newHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                    $update = $db->prepare(
                        'UPDATE users SET password_hash = :hash, must_change_password = 1 '
                        . "WHERE user_id = :id AND is_active = 1 AND role IN ('customer','tailor')"
                    );
                    $update->execute(['hash' => $newHash, 'id' => (int) $target['user_id']]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('The account could not be updated.');
                    }

                    $resolve = $db->prepare(
                        "UPDATE password_recovery_requests SET status = 'resolved', "
                        . 'handled_at = NOW(), handled_by = :admin '
                        . "WHERE recovery_id = :id AND status = 'pending'"
                    );
                    $resolve->execute(['admin' => (int) $user['user_id'], 'id' => $id]);
                    if ($resolve->rowCount() !== 1) {
                        throw new RuntimeException('The recovery request could not be resolved.');
                    }
                    $db->commit();
                    // Do not store the password in the database, session, cookies or URLs.
                    $issued = [
                        'name' => (string) $target['name'],
                        'email' => (string) $target['email'],
                        'password' => $temporaryPassword,
                    ];
                    unset($temporaryPassword, $newHash);
                    unset($_SESSION['csrf_token']);
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    // Do not log a generated temporary password.
                    $error = 'Password recovery could not be completed. Please try again.';
                }
            }
        }
        unset($adminPassword);
    }
}

$statement = $db->query(
    'SELECT r.recovery_id, r.requested_at, u.name, u.email, u.role, u.is_active '
    . 'FROM password_recovery_requests AS r '
    . 'JOIN users AS u ON u.user_id = r.user_id '
    . "WHERE r.status = 'pending' "
    . 'ORDER BY r.requested_at ASC, r.recovery_id ASC'
);
$requests = $statement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            <p>Verify account ownership and issue secure temporary passwords directly from this dashboard.</p></div>
        </header>

        <?php if ($success): ?>
            <p class="admin-alert admin-alert-success" role="status">The recovery request was dismissed.</p>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <p class="admin-alert admin-alert-error" role="alert"><?= escape_admin_recovery($error) ?></p>
        <?php endif; ?>

        <?php if ($issued !== null): ?>
            <section class="admin-issued-password" role="status" aria-labelledby="issued-title">
                <p class="admin-eyebrow">Password reset completed</p>
                <h2 id="issued-title">Temporary password generated</h2>
                <p>For <strong><?= escape_admin_recovery($issued['name']) ?></strong>
                    (<?= escape_admin_recovery($issued['email']) ?>).</p>
                <label for="temporary-password">Temporary password — visible only on this screen</label>
                <input id="temporary-password" type="text" readonly autocomplete="off"
                    spellcheck="false" value="<?= escape_admin_recovery($issued['password']) ?>">
                <p>Copy this password and share it privately with the verified account owner.
                    It will not be shown again after you leave or refresh this page.</p>
                <p>The user must sign in and set their own new password before accessing their account.</p>
                <a class="admin-return-link" href="/admin/password-recovery.php">Done — return to requests</a>
            </section>
        <?php else: ?>
            <section class="admin-recovery-guide" aria-label="Recovery instructions">
                <h2>How recovery works</h2>
                <p>Confirm the account belongs to the requester using a trusted channel. Then verify
                    your administrator password and generate a temporary password here. Give it
                    privately to the verified user.</p>
                <p>NaapSetu does not automatically email passwords. Customers and tailors must
                    change the temporary password at their next sign-in.</p>
            </section>
        <?php endif; ?>

<?php naapsetu_render_section_updates($db, $user, 'recovery'); ?>
        <section class="admin-list-panel" aria-labelledby="recovery-list-title">
            <header class="admin-panel-heading"><div><p class="admin-eyebrow">Pending</p>
                <h2 id="recovery-list-title"><?= count($requests) ?> recovery request<?= count($requests) === 1 ? '' : 's' ?></h2>
                </div><a href="/admin/dashboard.php">Admin overview</a></header>
            <?php if ($requests === []): ?>
                <div class="admin-empty"><span aria-hidden="true">✓</span>
                    <h3>No pending requests</h3><p>New password recovery requests will appear here.</p></div>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table recovery-table">
                        <thead><tr><th scope="col">Name</th><th scope="col">Registered email</th>
                            <th scope="col">Role</th><th scope="col">Requested</th><th scope="col">Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($requests as $item): ?>
                            <tr>
                                <td><strong><?= escape_admin_recovery((string) $item['name']) ?></strong></td>
                                <td><?= escape_admin_recovery((string) $item['email']) ?></td>
                                <td><?= escape_admin_recovery(ucfirst((string) $item['role'])) ?></td>
                                <td><?= escape_admin_recovery((string) $item['requested_at']) ?></td>
                                <td>
                                    <?php if ((int) $item['is_active'] === 1 && in_array($item['role'], ['customer', 'tailor'], true)): ?>
                                        <details class="admin-recovery-details">
                                            <summary>Review request</summary>
                                            <form method="post" action="/admin/password-recovery.php" autocomplete="off">
                                                <input type="hidden" name="csrf_token" value="<?= escape_admin_recovery(csrf_token()) ?>">
                                                <input type="hidden" name="recovery_id" value="<?= (int) $item['recovery_id'] ?>">
                                                <label class="verified-owner">
                                                    <input type="checkbox" name="verified_owner" value="yes" required>
                                                    I have independently verified the account owner.
                                                </label>
                                                <label for="admin-password-<?= (int) $item['recovery_id'] ?>">Your admin password</label>
                                                <input id="admin-password-<?= (int) $item['recovery_id'] ?>"
                                                    type="password" name="admin_password" autocomplete="current-password" required>
                                                <button type="submit" name="action" value="issue">Generate temporary password</button>
                                            </form>
                                        </details>
                                    <?php else: ?>
                                        <span class="admin-recovery-ineligible">Not eligible for dashboard reset</span>
                                    <?php endif; ?>
                                    <form method="post" action="/admin/password-recovery.php" class="recovery-actions">
                                        <input type="hidden" name="csrf_token" value="<?= escape_admin_recovery(csrf_token()) ?>">
                                        <input type="hidden" name="recovery_id" value="<?= (int) $item['recovery_id'] ?>">
                                        <button type="submit" name="action" value="dismissed" class="recovery-dismiss">Dismiss</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
