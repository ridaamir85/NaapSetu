<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/database.php';

start_app_session();

function escape_login(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$existingUser = signed_in_user();

if ($existingUser !== null) {
    $check = database()->prepare('SELECT must_change_password, is_active FROM users WHERE user_id = ?');
    $check->execute([$existingUser['user_id']]);
    $account = $check->fetch();
    if ($account === false || (int) $account['is_active'] !== 1) {
        sign_out_user();
        redirect_to('/login.php?account=deactivated');
    }
    redirect_to((int) $account['must_change_password'] === 1
        ? '/change-password.php' : dashboard_path_for_role($existingUser['role']));
}

$email = '';
$error = ($_GET['account'] ?? '') === 'deactivated'
    ? 'Your account is deactivated. Contact an administrator for help.'
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter both your email address and password.';
    } else {
        try {
            $statement = database()->prepare(
                'SELECT user_id, name, email, password_hash, role, is_active, must_change_password '
                . 'FROM users WHERE email = :email LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user === false || !password_verify($password, $user['password_hash'])) {
                $error = 'Invalid email address or password.';
            } elseif ((int) $user['is_active'] !== 1) {
                $error = 'Your account is deactivated. Contact an administrator for help.';
            } else {
                sign_in_user($user);
                unset($_SESSION['csrf_token']);
                redirect_to((int) $user['must_change_password'] === 1
                    ? '/change-password.php' : dashboard_path_for_role($user['role']));
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Sign-in could not be completed. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/account.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="auth-page">
    <a class="skip-link" href="#main">Skip to form</a>
    <div class="auth-shell">
    <?php require dirname(__DIR__) . '/includes/account-aside.php'; ?>
    <main id="main" class="auth-main">
        <header>
            <h1>Log in to NaapSetu</h1>
            <p>Access your account to request quotes,<br>manage orders and more.</p>
        </header>

        <?php if ($error !== null): ?>
            <p role="alert"><?= escape_login($error) ?></p>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape_login(csrf_token()) ?>">

            <div>
                <label for="email">Email address</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    maxlength="150"
                    autocomplete="email"
                    placeholder="Enter your email"
                    required
                    aria-invalid="<?= $error !== null ? 'true' : 'false' ?>"
                    value="<?= escape_login($email) ?>"
                >
            </div>

            <div>
                <label for="password">Password</label>
                <div class="password-field">
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="Enter your password"
                    required
                >
                <button class="password-toggle" type="button" data-toggle-password="password" aria-controls="password" aria-pressed="false" hidden>Show password</button>
                </div>
            </div>

            <p class="auth-help"><a href="/password-recovery.php">Forgot password?</a></p>
            <button type="submit">Log in</button>
        </form>

        <p class="account-switch">New to NaapSetu? <a href="register.php">Sign up</a></p>
    </main>
    </div>
    <script src="/assets/js/account.js" defer></script>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>

