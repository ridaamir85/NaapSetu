<?php
$tailorSection = $tailorSection ?? 'dashboard';
require_once __DIR__ . '/activity-badges.php';
$tailorActivityCounts = naapsetu_activity_badges(database(), $user ?? signed_in_user());
$tailorNavigation = [
    'dashboard' => ['Dashboard', '/tailor/dashboard.php', '⌂'],
    'requests' => ['Requests', '/tailor/requests.php', '▤'],
    'orders' => ['Orders', '/tailor/orders.php', '□'],
    'alterations' => ['Alterations', '/tailor/alterations.php', '↻'],
    'services' => ['Services', '/tailor/services.php', '✂'],
    'profile' => ['Profile & services', '/tailor/profile.php', '◉'],
    'security' => ['Change password', '/change-password.php', '♙'],
];
?>
<aside class="tailor-sidebar">
    <a class="tailor-wordmark" href="/tailor/dashboard.php">NaapSetu</a>
    <nav aria-label="Tailor navigation">
        <?php foreach ($tailorNavigation as $key => [$label, $url, $icon]): ?>
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"<?= $tailorSection === $key ? ' aria-current="page"' : '' ?>>
                <span aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                <?php if (($tailorActivityCounts[$key] ?? 0) > 0): ?><span class="nav-unread" aria-label="<?= (int) $tailorActivityCounts[$key] ?> unread"><?= (int) min(99, $tailorActivityCounts[$key]) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout.php" class="tailor-signout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"><span aria-hidden="true">↪</span>Sign out</button>
    </form>
</aside>
<link rel="stylesheet" href="/assets/css/activity-badges.css">
