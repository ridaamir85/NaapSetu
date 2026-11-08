<?php
declare(strict_types=1);
// Owner-only local maintenance tool. Never expose password reset through a public form.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/database.php';
if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || PHP_OS_FAMILY !== 'Linux' || !function_exists('shell_exec')) {
    fwrite(STDERR,"Run this interactively in your Ubuntu terminal.\n"); exit(1);
}
function private_password(string $prompt): string {
    $state=trim((string)shell_exec('stty -g'));
    if($state==='') throw new RuntimeException('Cannot hide terminal input.');
    fwrite(STDOUT,$prompt);
    shell_exec('stty -echo');
    try { $line=fgets(STDIN); if($line===false) throw new RuntimeException('Input cancelled.'); return rtrim($line,"\r\n"); }
    finally { shell_exec('stty '.escapeshellarg($state)); fwrite(STDOUT,PHP_EOL); }
}
try {
    fwrite(STDOUT,"Registered account email: "); $line=fgets(STDIN);
    $email=$line===false ? '' : trim($line);
    if(filter_var($email,FILTER_VALIDATE_EMAIL)===false) throw new RuntimeException('Enter a valid registered email.');
    $db=database(); $find=$db->prepare('SELECT user_id, name, role FROM users WHERE email=?'); $find->execute([$email]); $account=$find->fetch();
    if($account===false) throw new RuntimeException('Account not found. No changes made.');
    fwrite(STDOUT,'Account: '.$account['name'].' ('.$account['role'].')'.PHP_EOL);
    $password=private_password('New password (8–72 characters): ');
    if(strlen($password)<8 || strlen($password)>72) throw new RuntimeException('Use 8–72 bytes for the password. No changes made.');
    $confirmation=private_password('Confirm new password: ');
    if(!hash_equals($password,$confirmation)) throw new RuntimeException('Passwords do not match. No changes made.');
    $hash=password_hash($password,PASSWORD_DEFAULT); unset($password,$confirmation);
    $update=$db->prepare('UPDATE users SET password_hash=? WHERE user_id=?'); $update->execute([$hash,$account['user_id']]);
    fwrite(STDOUT,"Password updated. Log in with the same email and the new password.\n");
} catch(Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL); exit(1); }
