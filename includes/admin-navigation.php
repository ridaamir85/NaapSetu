<?php
$adminSection = $adminSection ?? 'overview';


$adminNavigation = [
    'overview' => ['Overview', '/admin/dashboard.php', '⌂'],
    'users' => ['Users', '/admin/dashboard.php?view=users', '♙'],
    'tailors' => ['Tailors', '/admin/dashboard.php?view=tailors', '♧'],
    'services' => ['Services', '/admin/dashboard.php?view=services', '✂'],
    'security' => ['Change password', '/change-password.php', '♙'],
];
?>
<aside class="admin-sidebar">
    <a class="admin-wordmark" href="/admin/dashboard.php">NaapSetu</a>
    <nav aria-label="Admin navigation">
        <?php foreach ($adminNavigation as $key => [$label, $url, $icon]): ?>
            <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"<?= $adminSection === $key ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout.php" class="admin-signout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"><span aria-hidden="true">↪</span>Sign out</button>
    </form>
</aside>

