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
    'role' => 'customer',
];
$errors = [];
$successMessage = null;

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
</head>
<body>
    <main>
        <header>
            <a href="index.php">NaapSetu</a>
            <h1>Create an account</h1>
            <p>Register as a customer or tailor.</p>
        </header>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape($successMessage) ?></p>
            <p><a href="login.php">Go to sign in</a></p>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape($errors['form']) ?></p>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape(csrf_token()) ?>">

            <div>
                <label for="name">Full name</label>
                <input
                    id="name"
                    name="name"
                    type="text"
                    maxlength="100"
                    autocomplete="name"
                    required
                    value="<?= escape($values['name']) ?>"
                    aria-describedby="name-error"
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
                    required
                    value="<?= escape($values['email']) ?>"
                    aria-describedby="email-error"
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
                    aria-describedby="phone-error"
                >
                <?php if (isset($errors['phone'])): ?>
                    <p id="phone-error" role="alert"><?= escape($errors['phone']) ?></p>
                <?php endif; ?>
            </div>

            <fieldset>
                <legend>Account type</legend>
                <label>
                    <input
                        type="radio"
                        name="role"
                        value="customer"
                        <?= $values['role'] === 'customer' ? 'checked' : '' ?>
                    >
                    Customer
                </label>
                <label>
                    <input
                        type="radio"
                        name="role"
                        value="tailor"
                        <?= $values['role'] === 'tailor' ? 'checked' : '' ?>
                    >
                    Tailor
                </label>
                <?php if (isset($errors['role'])): ?>
                    <p role="alert"><?= escape($errors['role']) ?></p>
                <?php endif; ?>
            </fieldset>

            <div>
                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="8"
                    autocomplete="new-password"
                    required
                    aria-describedby="password-help password-error"
                >
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
                    aria-describedby="password-confirmation-error"
                >
                <?php if (isset($errors['password_confirmation'])): ?>
                    <p id="password-confirmation-error" role="alert">
                        <?= escape($errors['password_confirmation']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <button type="submit">Create account</button>
        </form>
    </main>
</body>
</html>

