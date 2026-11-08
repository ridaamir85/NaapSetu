<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('admin');
$connection = database();

function escape_admin_user(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$successMessage = match ($_GET['saved'] ?? '') {
    'activated' => 'Account activated.',
    'deactivated' => 'Account deactivated. Their existing sessions will be ended on their next request.',
    'unchanged' => 'The account already has that access status.',
    default => null,
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $token = $_POST['csrf_token'] ?? null;

    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['activate', 'deactivate'], true)) {
        $errors['form'] = 'Choose a valid account action.';
    }
    if ($userId === false) {
        http_response_code(404);
        exit('Account not found.');
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();
            $lockUser = $connection->prepare(
                'SELECT user_id, role, is_active FROM users '
                . 'WHERE user_id = :user_id AND role IN (:customer, :tailor) FOR UPDATE'
            );
            $lockUser->execute([
                'user_id' => $userId,
                'customer' => 'customer',
                'tailor' => 'tailor',
            ]);
            $targetUser = $lockUser->fetch();

            if ($targetUser === false) {
                http_response_code(404);
                throw new RuntimeException('Target account not found or is not manageable.');
            }

            $newStatus = $action === 'activate' ? 1 : 0;
            if ((int) $targetUser['is_active'] === $newStatus) {
                $connection->rollBack();
                header('Location: /admin/users.php?saved=unchanged', true, 303);
                exit;
            }

            $update = $connection->prepare(
                'UPDATE users SET is_active = :is_active '
                . 'WHERE user_id = :user_id AND role IN (:customer, :tailor)'
            );
            $update->execute([
                'is_active' => $newStatus,
                'user_id' => $userId,
                'customer' => 'customer',
                'tailor' => 'tailor',
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Account status was not updated.');
            }

            $connection->commit();
            header('Location: /admin/users.php?saved=' . ($newStatus === 1 ? 'activated' : 'deactivated'), true, 303);
            exit;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if (http_response_code() !== 404) {
                error_log($exception->getMessage());
                $errors['form'] = 'Account access could not be updated. Please try again.';
            }
        }
    }
}

$statement = $connection->query(
    'SELECT user_id, name, email, role, is_active, created_at '
    . 'FROM users WHERE role IN (\'customer\', \'tailor\') '
    . 'ORDER BY role, name, user_id'
);
$accounts = $statement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage accounts | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css">
</head>
<body class="admin-page">
    <a class="skip-link" href="#main">Skip to account list</a>
    <div class="admin-shell">
        <?php $adminSection = 'users'; require dirname(__DIR__, 2) . '/includes/admin-navigation.php'; ?>
        <main id="main" class="admin-main">
            <header class="admin-heading">
                <div><p class="admin-eyebrow">Account access</p><h1>Manage users</h1><p>Activate or deactivate customer and tailor accounts.</p></div>
            </header>

        <?php if ($successMessage !== null): ?>
            <p class="admin-alert admin-alert-success" role="status"><?= escape_admin_user($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p class="admin-alert admin-alert-error" role="alert"><?= escape_admin_user($errors['form']) ?></p>
        <?php endif; ?>

            <section class="admin-list-panel" aria-labelledby="accounts-title">
                <header class="admin-panel-heading"><div><p class="admin-eyebrow">Account directory</p><h2 id="accounts-title">Customer and tailor accounts</h2></div><a href="/admin/dashboard.php">← Admin overview</a></header>
                <?php if ($accounts === []): ?>
                    <div class="admin-empty"><span aria-hidden="true">⌕</span><h3>No accounts yet</h3><p>Customer and tailor accounts will appear here.</p></div>
                <?php else: ?>
                    <div class="admin-table-wrap"><table class="admin-table admin-users-table">
                        <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Joined</th><th scope="col">Access</th></tr></thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                                <tr>
                                    <td><strong><?= escape_admin_user($account['name']) ?></strong></td>
                                    <td><?= escape_admin_user($account['email']) ?></td>
                                    <td><span class="admin-role"><?= escape_admin_user(ucfirst($account['role'])) ?></span></td>
                                    <td><span class="admin-status <?= (int) $account['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int) $account['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                                    <td><time datetime="<?= escape_admin_user($account['created_at']) ?>"><?= escape_admin_user(date('M j, Y', strtotime($account['created_at']))) ?></time></td>
                                    <td><form method="post" action="/admin/users.php" class="admin-access-form">
                                        <input type="hidden" name="csrf_token" value="<?= escape_admin_user(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
                                        <?php if ((int) $account['is_active'] === 1): ?>
                                            <button class="admin-access-button deactivate-button" type="submit" name="action" value="deactivate">Deactivate</button>
                                        <?php else: ?>
                                            <button class="admin-access-button activate-button" type="submit" name="action" value="activate">Activate</button>
                                        <?php endif; ?>
                                    </form></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
