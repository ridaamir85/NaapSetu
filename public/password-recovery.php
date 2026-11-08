<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/session.php';
start_app_session();

function escape_recovery(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
$email = '';
$error = null;
$submitted = ($_GET['submitted'] ?? '') === '1';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $error = 'Your form session expired. Refresh and try again.';
    } elseif (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            // Respond identically whether or not the email belongs to an account.
            // A unique generated-key index prevents multiple pending requests per user.
            $connection = database();
            $statement = $connection->prepare(
                'INSERT IGNORE INTO password_recovery_requests (user_id) '
                . 'SELECT user_id FROM users WHERE email = :email AND is_active = 1'
            );
            $statement->execute(['email' => $email]);
            unset($_SESSION['csrf_token']);
            header('Location: /password-recovery.php?submitted=1', true, 303);
            exit;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'The recovery request could not be processed. Please try again later.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot password | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/account.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
    <link rel="stylesheet" href="/assets/css/password-recovery.css">
</head>
<body class="auth-page recovery-page">
<a class="skip-link" href="#main">Skip to password recovery</a>
<div class="auth-shell">
    <?php require dirname(__DIR__) . '/includes/account-aside.php'; ?>
    <main id="main" class="auth-main">
        <?php if ($submitted): ?>
            <header><h1>Request received</h1><p>Check the next steps for recovering your account.</p></header>
            <div class="recovery-confirmation" role="status">
                <h2>Recovery request submitted</h2>
                <p>If an active account exists with that email, an administrator can review the request.</p>
            </div>
            <p class="recovery-instructions">The administrator must independently verify that you own the account before giving you a temporary password. No email is sent automatically.</p>
            <div class="ui-next-step"><a class="ui-primary-action" href="/login.php">Back to login</a><a href="/password-recovery.php">Submit another request</a></div>
        <?php else: ?>
            <header><h1>Forgot your password?</h1><p>Enter your registered email to request help recovering your account.</p></header>
            <?php if ($error !== null): ?><p role="alert"><?= escape_recovery($error) ?></p><?php endif; ?>
            <form method="post" action="/password-recovery.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape_recovery(csrf_token()) ?>">
                <div>
                    <label for="recovery-email">Registered email address</label>
                    <input type="email" id="recovery-email" name="email" autocomplete="email" maxlength="150"
                           placeholder="you@example.com" required aria-invalid="<?= $error !== null ? 'true' : 'false' ?>"
                           value="<?= escape_recovery($email) ?>">
                </div>
                <p class="recovery-instructions">An administrator will review your request. Your account, profiles and orders will remain unchanged.</p>
                <button type="submit">Request password recovery</button>
            </form>
            <p class="recovery-footnote">For security, submitting this form does not reset your password. The administrator must verify ownership separately and provide a temporary password.</p>
            <div class="ui-next-step"><a href="/login.php">Back to login</a><a href="/">Homepage</a></div>
        <?php endif; ?>
    </main>
</div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
