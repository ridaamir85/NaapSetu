<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

start_app_session();
$viewer = signed_in_user();
if ($viewer === null) redirect_to('/login.php');
$db = database();
$check = $db->prepare('SELECT role, is_active, must_change_password FROM users WHERE user_id=?');
$check->execute([(int) $viewer['user_id']]);
$account = $check->fetch();
if ($account === false || (int) $account['is_active'] !== 1) {
    sign_out_user(); redirect_to('/login.php?account=deactivated');
}
if ((int) $account['must_change_password'] === 1) redirect_to('/change-password.php');
if ($viewer['role'] !== $account['role']) {
    $_SESSION['user']['role'] = (string) $account['role'];
    $viewer['role'] = (string) $account['role'];
}
$notifications = naapsetu_notifications($db, $viewer);
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_token_is_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $error = 'Your session expired. Refresh and try again.';
    } else {
        $key = is_string($_POST['event_key'] ?? null) ? $_POST['event_key'] : '';
        foreach ($notifications as $item) {
            if ($item['key'] !== $key) continue;
            $statement = $db->prepare(
                'INSERT IGNORE INTO notification_reads (user_id,event_key) VALUES (?,?)'
            );
            $statement->execute([(int) $viewer['user_id'], $key]);
            // The destination is generated server-side from an owned activity, never client input.
            redirect_to($item['url']);
        }
        $error = 'The notification is no longer available.';
    }
}
function escape_notice(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
$unread = count(array_filter($notifications, static fn(array $n): bool => $n['unread']));
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications | NaapSetu</title>
<link rel="stylesheet" href="/assets/css/site.css">
<?php if ($viewer['role'] === 'customer'): ?>
<link rel="stylesheet" href="/assets/css/customer.css">
<?php elseif ($viewer['role'] === 'tailor'): ?>
<link rel="stylesheet" href="/assets/css/tailor-navigation.css">
<?php else: ?>
<link rel="stylesheet" href="/assets/css/admin-dashboard.css">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/usability.css">
<link rel="stylesheet" href="/assets/css/notifications.css">
</head>
<body class="<?= $viewer['role'] === 'customer' ? 'customer-page' : ($viewer['role'] === 'tailor' ? 'tailor-dashboard-page' : 'admin-page') ?>">
<a class="skip-link" href="#main">Skip to notifications</a>
<div class="<?= $viewer['role'] === 'customer' ? 'customer-shell' : ($viewer['role'] === 'tailor' ? 'tailor-dashboard-shell' : 'admin-shell') ?>">
<?php
if ($viewer['role'] === 'customer') {
    $customerSection = 'notifications';
    require dirname(__DIR__) . '/includes/customer-navigation.php';
} elseif ($viewer['role'] === 'tailor') {
    $tailorSection = 'notifications';
    require dirname(__DIR__) . '/includes/tailor-navigation.php';
} else {
    $adminSection = 'notifications';
    require dirname(__DIR__) . '/includes/admin-navigation.php';
}
?>
<main id="main" class="<?= $viewer['role'] === 'customer' ? 'customer-main' : ($viewer['role'] === 'tailor' ? 'tailor-dashboard-main' : 'admin-main') ?> notification-main">
<header class="notification-header">
    <p class="notification-eyebrow">Your activity</p>
    <h1>Notifications</h1>
    <p>New requests, quotations and order updates appear here. Viewing an item marks only that notification as read.</p>
    <span class="notification-summary"><?= $unread ?> unread · <?= count($notifications) ?> total</span>
</header>
<?php if ($error !== null): ?><p role="alert"><?= escape_notice($error) ?></p><?php endif; ?>
<?php if ($notifications === []): ?>
<section class="notification-empty"><h2>You're all caught up</h2><p>New activity will appear here when something changes in your orders or requests.</p></section>
<?php else: ?>
<section class="notification-list" aria-label="Recent activity">
<?php foreach ($notifications as $notice): ?>
<article class="notification-item <?= $notice['unread'] ? 'is-unread' : 'is-read' ?>">
<div class="notification-copy">
    <span class="notification-section"><?= escape_notice(ucfirst($notice['section'])) ?></span>
    <?php if ($notice['unread']): ?><span class="notification-new">New</span><?php endif; ?>
    <h2><?= escape_notice($notice['title']) ?></h2>
    <p><?= escape_notice($notice['detail']) ?></p>
</div>
<form method="post" action="/notifications.php">
    <input type="hidden" name="csrf_token" value="<?= escape_notice(csrf_token()) ?>">
    <input type="hidden" name="event_key" value="<?= escape_notice($notice['key']) ?>">
    <button type="submit"><?= $notice['unread'] ? 'View update' : 'View again' ?> <span aria-hidden="true">→</span></button>
</form>
</article>
<?php endforeach; ?>
</section>
<?php endif; ?>
</main>
</div><script src="/assets/js/usability.js" defer></script></body></html>
