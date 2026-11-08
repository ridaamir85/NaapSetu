<?php
function customer_icon(string $name): string {
    $paths = [
        'home'=>'<path d="m3 10 9-7 9 7v11h-7v-7h-4v7H3z"/>',
        'measurements'=>'<path d="m4 16 12-12 4 4L8 20H4zM12 8l4 4M9 11l2 2M6 14l2 2"/>',
        'styles'=>'<path d="m3 8 9-5 9 5-9 5zM3 12l9 5 9-5M3 16l9 5 9-5"/>',
        'requests'=>'<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h7"/>',
        'quotes'=>'<path d="M3 12V4h8l10 10-8 8z"/><circle cx="8" cy="8" r="1"/>',
        'orders'=>'<path d="m3 7 9-4 9 4v12l-9 3-9-3zM3 7l9 4 9-4M12 11v11M7 5l10 4v5"/>',
        'permissions'=>'<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6"/>',
        'tailors'=>'<path d="M3 10h18L19 4H5zM5 10v11h14V10M9 21v-7h6v7"/>',
        'feedback'=>'<path d="M4 4h16v13H9l-5 4zM8 8h8M8 12h5"/>',
        'logout'=>'<path d="M9 3H4v18h5M8 12h13m-5-5 5 5-5 5"/>',
        'notifications'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'security'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'check'=>'<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">'.($paths[$name] ?? $paths['home']).'</svg>';
}
$customerSection = $customerSection ?? 'home';
require_once __DIR__ . '/notifications.php';
$customerNotificationCounts = naapsetu_notification_counts(naapsetu_notifications(database(), $user ?? signed_in_user()));
$links = [['home','Home','dashboard.php'],['measurements','Measurements','measurements.php'],['styles','Styles','styles.php'],['requests','Requests & quotes','requests.php'],['orders','Orders','orders.php'],['permissions','Sharing permissions','permissions.php'],['tailors','Browse tailors','tailors.php'],['feedback','Feedback & alterations','feedback.php'],['notifications','Notifications','../notifications.php'],['security','Change password','../change-password.php']];
?>
<aside class="customer-sidebar">
<a class="wordmark" href="/customer/dashboard.php">NaapSetu</a>
<nav aria-label="Customer navigation">
<?php foreach ($links as [$key,$label,$file]): ?>
<a href="<?= in_array($key, ['security','notifications'], true) ? ($key === 'security' ? '/change-password.php' : '/notifications.php') : '/customer/' . $file ?>" <?= $customerSection === $key ? 'aria-current="page"' : '' ?>><?= customer_icon($key) ?><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><?php if (($customerNotificationCounts[$key] ?? 0) > 0): ?><span class="nav-unread" aria-label="<?= (int) $customerNotificationCounts[$key] ?> unread"><?= (int) min(99, $customerNotificationCounts[$key]) ?></span><?php endif; ?></a>
<?php endforeach; ?>
</nav>
<form method="post" action="/logout.php" class="customer-signout"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button type="submit"><?= customer_icon('logout') ?>Sign out</button></form>
</aside>
<link rel="stylesheet" href="/assets/css/notifications.css">
