<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/session.php';
require_once dirname(__DIR__) . '/includes/validation.php';

start_app_session();

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$values = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => ($_GET['role'] ?? '') === 'tailor' ? 'tailor' : 'customer',
];
$errors = [];
$successMessage = $_SESSION['registration_success'] ?? null;
unset($_SESSION['registration_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = validate_registration($_POST);
    $values = $result['values'];
    $errors = $result['errors'];

    if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if ($errors === []) {
        try {
            $connection = database();

            $emailCheck = $connection->prepare(
                'SELECT user_id FROM users WHERE email = :email LIMIT 1'
            );
            $emailCheck->execute(['email' => $values['email']]);

            if ($emailCheck->fetch() !== false) {
                $errors['email'] = 'An account with this email already exists.';
            } else {
                $passwordHash = password_hash(
                    (string) $_POST['password'],
                    PASSWORD_DEFAULT
                );

                $statement = $connection->prepare(
                    'INSERT INTO users (name, email, phone, password_hash, role) '
                    . 'VALUES (:name, :email, :phone, :password_hash, :role)'
                );
                $statement->execute([
                    'name' => $values['name'],
                    'email' => $values['email'],
                    'phone' => $values['phone'] === '' ? null : $values['phone'],
                    'password_hash' => $passwordHash,
                    'role' => $values['role'],
                ]);

                $successMessage = 'Registration successful. You can now sign in.';
                $values = ['name' => '', 'email' => '', 'phone' => '', 'role' => 'customer'];
                unset($_SESSION['csrf_token']);
                $_SESSION['registration_success'] = $successMessage;
                header('Location: /register.php?created=1', true, 303);
                exit;
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());

            if ($exception->getCode() === '23000') {
                $errors['email'] = 'An account with this email already exists.';
            } else {
                $errors['form'] = 'Registration could not be completed. Please try again.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/account.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="auth-page register-page">
    <a class="skip-link" href="#main">Skip to form</a>
    <div class="auth-shell">
    <?php require dirname(__DIR__) . '/includes/account-aside.php'; ?>
    <main id="main" class="auth-main">
        <header>
            <h1><?= $successMessage !== null ? 'Account created' : 'Create your NaapSetu account' ?></h1>
            <p><?= $successMessage !== null ? 'You’re ready to log in and start using NaapSetu.' : 'Join as a customer to get custom stitching, or as a tailor to receive stitching requests.' ?></p>
        </header>

        <?php if ($successMessage !== null): ?>
            <section class="auth-success" aria-label="Account created"><p role="status"><?= escape($successMessage) ?></p><a class="ui-primary-action" href="/login.php">Log in to your account →</a><a href="/">Back to homepage</a></section>
        <?php endif; ?>

        <?php if ($successMessage === null): ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape($errors['form']) ?></p>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

            <fieldset class="role-options">
                <legend>I am a</legend>
                <label><input type="radio" name="role" value="customer" <?= $values['role'] === 'customer' ? 'checked' : '' ?>><span>Customer<small>Get custom stitching</small></span></label>
                <label><input type="radio" name="role" value="tailor" <?= $values['role'] === 'tailor' ? 'checked' : '' ?>><span>Tailor<small>Receive stitching requests</small></span></label>
                <?php if (isset($errors['role'])): ?><p role="alert"><?= escape($errors['role']) ?></p><?php endif; ?>
            </fieldset>

            <div>
                <label for="name">Full name</label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    maxlength="100"
                    autocomplete="name"
                    placeholder="Enter your full name"
                    required
                    value="<?= escape($values['name']) ?>"
                    aria-invalid="<?= isset($errors['name']) ? 'true' : 'false' ?>"
                    <?= isset($errors['name']) ? 'aria-describedby="name-error"' : '' ?>
                >
                <?php if (isset($errors['name'])): ?>
                    <p id="name-error" role="alert"><?= escape($errors['name']) ?></p>
                <?php endif; ?>
            </div>

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
                    value="<?= escape($values['email']) ?>"
                    aria-invalid="<?= isset($errors['email']) ? 'true' : 'false' ?>"
                    <?= isset($errors['email']) ? 'aria-describedby="email-error"' : '' ?>
                >
                <?php if (isset($errors['email'])): ?>
                    <p id="email-error" role="alert"><?= escape($errors['email']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="phone">Phone number (optional)</label>
                <input
                    id="phone"
                    name="phone"
                    type="tel"
                    maxlength="20"
                    autocomplete="tel"
                    value="<?= escape($values['phone']) ?>"
                    aria-invalid="<?= isset($errors['phone']) ? 'true' : 'false' ?>"
                    <?= isset($errors['phone']) ? 'aria-describedby="phone-error"' : '' ?>
                >
                <?php if (isset($errors['phone'])): ?>
                    <p id="phone-error" role="alert"><?= escape($errors['phone']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="password">Password</label>
                <div class="password-field">
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Create a password"
                    required
                    aria-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>"
                    aria-describedby="password-help<?= isset($errors['password']) ? ' password-error' : '' ?>"
                >
                <button class="password-toggle" type="button" data-toggle-password="password" aria-controls="password" aria-pressed="false" hidden>Show password</button>
                </div>
                <p id="password-help">Use at least 8 characters.</p>
                <?php if (isset($errors['password'])): ?>
                    <p id="password-error" role="alert"><?= escape($errors['password']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="password_confirmation">Confirm password</label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                    aria-invalid="<?= isset($errors['password_confirmation']) ? 'true' : 'false' ?>"
                    <?= isset($errors['password_confirmation']) ? 'aria-describedby="password-confirmation-error"' : '' ?>
                >
                <?php if (isset($errors['password_confirmation'])): ?>
                    <p id="password-confirmation-error" role="alert">
                        <?= escape($errors['password_confirmation']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <button type="submit">Create account</button>
        </form>
        <p class="account-switch">Already have an account? <a href="/login.php">Log in</a></p>
        <?php endif; ?>
    </main>
    </div>
    <script src="/assets/js/account.js" defer></script>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>

