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
    redirect_to(dashboard_path_for_role($existingUser['role']));
}

$email = '';
$error = null;

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
                'SELECT user_id, name, email, password_hash, role '
                . 'FROM users WHERE email = :email LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user === false || !password_verify($password, $user['password_hash'])) {
                $error = 'Invalid email address or password.';
            } else {
                sign_in_user($user);
                unset($_SESSION['csrf_token']);
                redirect_to(dashboard_path_for_role($user['role']));
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
</head>
<body>
    <main>
        <header>
            <a href="index.php">NaapSetu</a>
            <h1>Sign in</h1>
            <p>Access your NaapSetu account.</p>
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
                    required
                    value="<?= escape_login($email) ?>"
                >
            </div>

            <div>
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <button type="submit">Sign in</button>
        </form>

        <p>Do not have an account? <a href="register.php">Register here</a>.</p>
    </main>
</body>
</html>

