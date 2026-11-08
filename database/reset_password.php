<?php
declare(strict_types=1);

// Owner-only CLI recovery: verify account ownership OUTSIDE this script first.
// The generated password is shown only once and the user must change it after login.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/database.php';
if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
    fwrite(STDERR, "Run this interactively in your Ubuntu terminal.\n");
    exit(1);
}
try {
    fwrite(STDOUT, "Only proceed after independently verifying account ownership.\n");
    fwrite(STDOUT, "Registered account email: ");
    $emailInput = fgets(STDIN);
    $email = $emailInput === false ? '' : strtolower(trim($emailInput));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Enter a valid account email.');
    }

    $db = database();
    $find = $db->prepare('SELECT user_id, name, role, is_active FROM users WHERE email = ?');
    $find->execute([$email]);
    $account = $find->fetch();
    if ($account === false) {
        throw new RuntimeException('Account not found. No changes made.');
    }
    if ((int) $account['is_active'] !== 1) {
        throw new RuntimeException('Account is inactive. Ask an administrator to review access first.');
    }
    fwrite(STDOUT, "Account: " . $account['name'] . " (" . $account['role'] . ")\n");
    fwrite(STDOUT, "Ownership verified independently? Type YES to reset: ");
    if (trim((string) fgets(STDIN)) !== 'YES') {
        throw new RuntimeException('Cancelled. No changes made.');
    }

    // Random 24-character secret; never store or log the plaintext.
    $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $hash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    $update = $db->prepare(
        'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE user_id = :id'
    );
    $update->execute(['hash' => $hash, 'id' => (int) $account['user_id']]);
    fwrite(STDOUT, "Temporary password (give privately to verified user): " . $temporaryPassword . "\n");
    fwrite(STDOUT, "The user must set a private password at their next sign-in.\n");
    unset($temporaryPassword, $hash);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
