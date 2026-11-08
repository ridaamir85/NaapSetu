<?php
$adminSection = $adminSection ?? 'overview';
require_once __DIR__ . '/notifications.php';
$adminNotificationCounts = naapsetu_notification_counts(naapsetu_notifications(database(), $user ?? signed_in_user()));
$adminNavigation = [
    'overview' => ['Overview', '/admin/dashboard.php', '⌂'],
    'users' => ['Users', '/admin/dashboard.php?view=users', '♙'],
    'tailors' => ['Tailors', '/admin/dashboard.php?view=tailors', '♧'],
    'services' => ['Services', '/admin/dashboard.php?view=services', '✂'],
    'recovery' => ['Password recovery', '/admin/password-recovery.php', '♧'],
    'notifications' => ['Notifications', '/notifications.php', '♢'],
    'security' => ['Change password', '/change-password.php', '♙'],
];
?>
<aside class="admin-sidebar">
    <a class="admin-wordmark" href="/admin/dashboard.php">NaapSetu</a>
    <nav aria-label="Admin navigation">
        <?php foreach ($adminNavigation as $key => [$label, $url, $icon]): ?>
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"<?= $adminSection === $key ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?><?php if (($adminNotificationCounts[$key] ?? 0) > 0): ?><span class="nav-unread" aria-label="<?= (int) $adminNotificationCounts[$key] ?> unread"><?= (int) min(99, $adminNotificationCounts[$key]) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout.php" class="admin-signout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"><span aria-hidden="true">↪</span>Sign out</button>
    </form>
</aside>
<link rel="stylesheet" href="/assets/css/notifications.css">
