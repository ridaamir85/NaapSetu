<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/database.php';

function dashboard_path_for_role(string $role): string
{
    return match ($role) {
        'customer' => '/customer/dashboard.php',
        'tailor' => '/tailor/dashboard.php',
        'admin' => '/admin/dashboard.php',
        default => '/index.php',
    };
}

function signed_in_user(): ?array
{
    $user = $_SESSION['user'] ?? null;

    return is_array($user) ? $user : null;
}

function sign_in_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'user_id' => (int) $user['user_id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}

function sign_out_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $parameters['path'],
            $parameters['domain'],
            $parameters['secure'],
            $parameters['httponly']
        );
    }

    session_destroy();
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function require_role(string $role): array
{
    start_app_session();
    $user = signed_in_user();

    if ($user === null) {
        redirect_to('/login.php');
    }

    $activeCheck = database()->prepare(
        'SELECT role, is_active, must_change_password FROM users WHERE user_id = :user_id LIMIT 1'
    );
    $activeCheck->execute(['user_id' => $user['user_id']]);
    $currentUser = $activeCheck->fetch();
    if ($currentUser === false || (int) $currentUser['is_active'] !== 1) {
        sign_out_user();
        redirect_to('/login.php?account=deactivated');
    }

    if ($currentUser['role'] !== $user['role']) {
        $user['role'] = (string) $currentUser['role'];
        $_SESSION['user']['role'] = $user['role'];
    }

    if ((int) $currentUser['must_change_password'] === 1) {
        redirect_to('/change-password.php');
    }

    if ($user['role'] !== $role) {
        redirect_to(dashboard_path_for_role($user['role']));
    }

    return $user;
}

