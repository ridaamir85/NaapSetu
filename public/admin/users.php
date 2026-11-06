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
</head>
<body>
    <main>
        <a href="/admin/dashboard.php">Back to dashboard</a>
        <h1>Customer and tailor accounts</h1>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_admin_user($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_admin_user($errors['form']) ?></p>
        <?php endif; ?>

        <?php if ($accounts === []): ?>
            <p>No customer or tailor accounts are registered yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Created</th>
                        <th scope="col">Access</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?= escape_admin_user($account['name']) ?></td>
                            <td><?= escape_admin_user($account['email']) ?></td>
                            <td><?= escape_admin_user($account['role']) ?></td>
                            <td><?= (int) $account['is_active'] === 1 ? 'Active' : 'Deactivated' ?></td>
                            <td><time datetime="<?= escape_admin_user($account['created_at']) ?>"><?= escape_admin_user($account['created_at']) ?></time></td>
                            <td>
                                <form method="post" action="/admin/users.php">
                                    <input type="hidden" name="csrf_token" value="<?= escape_admin_user(csrf_token()) ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $account['user_id'] ?>">
                                    <?php if ((int) $account['is_active'] === 1): ?>
                                        <button type="submit" name="action" value="deactivate">Deactivate</button>
                                    <?php else: ?>
                                        <button type="submit" name="action" value="activate">Activate</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
