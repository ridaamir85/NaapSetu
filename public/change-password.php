<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
start_app_session();
$user = signed_in_user();
if ($user === null) {
    redirect_to('/login.php');
}

$db = database();
$statement = $db->prepare(
    'SELECT user_id, name, role, password_hash, is_active, must_change_password FROM users WHERE user_id = ? LIMIT 1'
);
$statement->execute([(int) $user['user_id']]);
$account = $statement->fetch();
if ($account === false || (int) $account['is_active'] !== 1) {
    sign_out_user();
    redirect_to('/login.php?account=deactivated');
}
if ($account['role'] !== $user['role']) {
    $_SESSION['user']['role'] = (string) $account['role'];
    $user['role'] = (string) $account['role'];
}
$required = (int) $account['must_change_password'] === 1;
$errors = [];
$success = ($_GET['updated'] ?? '') === '1' && !$required;
function escape_password_page(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    if (!csrf_token_is_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }
    if (!password_verify($current, (string) $account['password_hash'])) {
        $errors[] = 'The current password is incorrect.';
    }
    if (strlen($new) < 12 || strlen($new) > 72) {
        $errors[] = 'Choose a new password of 12 to 72 characters.';
    }
    if (!hash_equals($new, $confirmation)) {
        $errors[] = 'The confirmation does not match your new password.';
    }
    if ($new !== '' && password_verify($new, (string) $account['password_hash'])) {
        $errors[] = 'Your new password must be different from your existing password.';
    }
    if ($errors === []) {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        // Compare the old hash so an intervening reset or password change cannot be overwritten.
        $update = $db->prepare(
            'UPDATE users SET password_hash = :new_hash, must_change_password = 0 '
            . 'WHERE user_id = :id AND password_hash = :old_hash AND is_active = 1'
        );
        $update->execute([
            'new_hash' => $hash,
            'id' => (int) $user['user_id'],
            'old_hash' => (string) $account['password_hash'],
        ]);
        if ($update->rowCount() === 1) {
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            redirect_to('/change-password.php?updated=1');
        }
        $errors[] = 'Your account changed during this request. Please sign in again.';
    }
    unset($current, $new, $confirmation);
}
$dashboard = dashboard_path_for_role((string) $user['role']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/account.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
    <link rel="stylesheet" href="/assets/css/password-management.css">
</head>
<body class="auth-page password-management-page">
<a class="skip-link" href="#main">Skip to password settings</a>
<div class="auth-shell">
    <?php require dirname(__DIR__) . '/includes/account-aside.php'; ?>
    <main id="main" class="auth-main">
        <header>
            <p class="password-eyebrow">Account security</p>
            <h1><?= $required ? 'Set your private password' : 'Change your password' ?></h1>
            <p><?= $required
                ? 'You need to update your password before continuing to your dashboard.'
                : 'Keep your NaapSetu account secure. You can update your password at any time.' ?></p>
        </header>
        <?php if ($success): ?>
            <section class="auth-success password-success" role="status">
                <h2>Password updated</h2>
                <p>Your password has been changed successfully. Only the new password will work for future sign-ins.</p>
                <a class="ui-primary-action" href="<?= escape_password_page($dashboard) ?>">Go to dashboard →</a>
            </section>
        <?php else: ?>
            <?php if ($required): ?><p class="password-notice" role="status">A password change is required to access your account.</p><?php endif; ?>
            <?php if ($errors !== []): ?>
                <div role="alert" class="ui-error-summary">
                    <h2>Please correct the following:</h2>
                    <ul><?php foreach ($errors as $message): ?><li><?= escape_password_page($message) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post" action="/change-password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape_password_page(csrf_token()) ?>">
                <div>
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required autofocus>
                </div>
                <div>
                    <label for="new_password">New password</label>
                    <input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="12" maxlength="72" aria-describedby="password-requirements" required>
                    <p id="password-requirements" class="password-help">Use 12–72 characters, preferably a unique passphrase.</p>
                </div>
                <div>
                    <label for="confirm_password">Confirm new password</label>
                    <input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="12" maxlength="72" required>
                </div>
                <button type="submit"><?= $required ? 'Set new password' : 'Update password' ?></button>
            </form>
            <?php if (!$required): ?>
                <p class="account-switch"><a href="<?= escape_password_page($dashboard) ?>">Back to dashboard</a></p>
            <?php else: ?>
                <form class="password-signout" method="post" action="/logout.php">
                    <input type="hidden" name="csrf_token" value="<?= escape_password_page(csrf_token()) ?>">
                    <button type="submit" class="password-logout">Sign out instead</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
