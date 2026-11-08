<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('admin');
$connection = database();

function escape_admin_dashboard(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$views = ['overview', 'users', 'tailors', 'services'];
$view = is_string($_GET['view'] ?? null) && in_array($_GET['view'], $views, true)
    ? $_GET['view']
    : 'overview';
$roleFilter = is_string($_GET['role'] ?? null) && in_array($_GET['role'], ['all', 'customer', 'tailor'], true)
    ? $_GET['role']
    : 'all';
$statusFilter = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['all', 'active', 'inactive'], true)
    ? $_GET['status']
    : 'all';
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$search = mb_substr($search, 0, 150, 'UTF-8');

$countStatement = $connection->query(
    "SELECT COUNT(*) AS total_users, "
    . "SUM(CASE WHEN role = 'tailor' THEN 1 ELSE 0 END) AS total_tailors "
    . "FROM users WHERE role IN ('customer', 'tailor')"
);
$counts = $countStatement->fetch() ?: ['total_users' => 0, 'total_tailors' => 0];
$serviceCount = (int) $connection->query('SELECT COUNT(*) FROM tailor_services')->fetchColumn();

$rows = [];
if ($view === 'tailors' || $view === 'services') {
    $serviceOnly = $view === 'services';
    if ($serviceOnly) {
        $statement = $connection->prepare(
            'SELECT s.service_id, s.garment_type, s.base_price, s.avg_days, '
            . 'p.shop_name, p.city, u.name AS tailor_name '
            . 'FROM tailor_services AS s '
            . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = s.tailor_id '
            . 'INNER JOIN users AS u ON u.user_id = p.user_id '
            . 'WHERE (:q = \'\' OR u.name LIKE :search_name OR p.shop_name LIKE :search_shop '
            . 'OR s.garment_type LIKE :search_garment) '
            . 'ORDER BY p.shop_name, s.garment_type, s.service_id'
        );
        $like = '%' . $search . '%';
        $statement->execute([
            'q' => $search,
            'search_name' => $like,
            'search_shop' => $like,
            'search_garment' => $like,
        ]);
    } else {
        $statement = $connection->prepare(
            'SELECT u.user_id, u.name, u.email, u.is_active, u.created_at, '
            . 'p.shop_name, p.city, COUNT(s.service_id) AS service_count '
            . 'FROM users AS u LEFT JOIN tailor_profiles AS p ON p.user_id = u.user_id '
            . 'LEFT JOIN tailor_services AS s ON s.tailor_id = p.tailor_id '
            . 'WHERE u.role = :role AND (:status = \'all\' OR u.is_active = :is_active) '
            . 'AND (:q = \'\' OR u.name LIKE :search_name OR u.email LIKE :search_email OR p.shop_name LIKE :search_shop) '
            . 'GROUP BY u.user_id, u.name, u.email, u.is_active, u.created_at, p.shop_name, p.city '
            . 'ORDER BY u.name, u.user_id'
        );
        $like = '%' . $search . '%';
        $statement->execute([
            'role' => 'tailor',
            'status' => $statusFilter,
            'is_active' => $statusFilter === 'active' ? 1 : 0,
            'q' => $search,
            'search_name' => $like,
            'search_email' => $like,
            'search_shop' => $like,
        ]);
    }
    $rows = $statement->fetchAll();
} else {
    $statement = $connection->prepare(
        'SELECT user_id, name, email, role, is_active, created_at FROM users '
        . 'WHERE role IN (:customer, :tailor) '
        . 'AND (:role_filter = \'all\' OR role = :selected_role) '
        . 'AND (:status = \'all\' OR is_active = :is_active) '
        . 'AND (:q = \'\' OR name LIKE :search_name OR email LIKE :search_email) '
        . 'ORDER BY created_at DESC, user_id DESC'
        . ($view === 'overview' ? ' LIMIT 8' : '')
    );
    $like = '%' . $search . '%';
    $statement->execute([
        'customer' => 'customer',
        'tailor' => 'tailor',
        'role_filter' => $roleFilter,
        'selected_role' => $roleFilter === 'all' ? 'customer' : $roleFilter,
        'status' => $statusFilter,
        'is_active' => $statusFilter === 'active' ? 1 : 0,
        'q' => $search,
        'search_name' => $like,
        'search_email' => $like,
    ]);
    $rows = $statement->fetchAll();
}

$titles = [
    'overview' => 'Admin Overview',
    'users' => 'Users',
    'tailors' => 'Tailors',
    'services' => 'Services',
];
$title = $titles[$view];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape_admin_dashboard($title) ?> | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/admin-dashboard.css">
</head>
<body class="admin-page">
    <a class="skip-link" href="#main">Skip to admin content</a>
    <div class="admin-shell">
        <?php $adminSection = $view; require dirname(__DIR__, 2) . '/includes/admin-navigation.php'; ?>

        <main id="main" class="admin-main">
            <header class="admin-heading">
                <div><p class="admin-eyebrow">NaapSetu administration</p><h1><?= escape_admin_dashboard($title) ?></h1><p>Welcome, <?= escape_admin_dashboard($user['name']) ?>. Review platform activity and account access.</p></div>
            </header>

            <section class="admin-stats" aria-label="Platform totals">
                <article class="admin-stat"><span class="admin-stat-icon" aria-hidden="true">♙</span><span><small>Total users</small><strong><?= (int) $counts['total_users'] ?></strong></span></article>
                <article class="admin-stat"><span class="admin-stat-icon" aria-hidden="true">♧</span><span><small>Total tailors</small><strong><?= (int) $counts['total_tailors'] ?></strong></span></article>
                <article class="admin-stat"><span class="admin-stat-icon" aria-hidden="true">✂</span><span><small>Total services</small><strong><?= $serviceCount ?></strong></span></article>
            </section>

            <section class="admin-list-panel" aria-labelledby="admin-list-title">
                <header class="admin-panel-heading"><div><p class="admin-eyebrow">Platform directory</p><h2 id="admin-list-title"><?= $view === 'overview' ? 'Users' : escape_admin_dashboard($title) ?></h2></div><a href="/admin/users.php">Manage account access <span aria-hidden="true">→</span></a></header>
                <form class="admin-filters" method="get" action="/admin/dashboard.php">
                    <input type="hidden" name="view" value="<?= escape_admin_dashboard($view) ?>">
                    <?php if ($view === 'overview' || $view === 'users'): ?>
                        <label><span class="visually-hidden">Filter by role</span><select name="role"><option value="all"<?= $roleFilter === 'all' ? ' selected' : '' ?>>All roles</option><option value="customer"<?= $roleFilter === 'customer' ? ' selected' : '' ?>>Customer</option><option value="tailor"<?= $roleFilter === 'tailor' ? ' selected' : '' ?>>Tailor</option></select></label>
                    <?php endif; ?>
                    <?php if ($view !== 'services'): ?>
                        <label><span class="visually-hidden">Filter by status</span><select name="status"><option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option><option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></label>
                    <?php endif; ?>
                    <label class="admin-search"><span class="visually-hidden">Search</span><input type="search" name="q" value="<?= escape_admin_dashboard($search) ?>" placeholder="Search by name or email" maxlength="150"></label>
                    <button class="admin-filter-button" type="submit">Search</button>
                    <?php if ($view === 'overview'): ?><a class="admin-view-all" href="/admin/dashboard.php?view=users">View all users</a><?php endif; ?>
                </form>

                <?php if ($rows === []): ?>
                    <div class="admin-empty"><span aria-hidden="true">⌕</span><h3>No matching records</h3><p>Try changing the filters or search terms.</p></div>
                <?php elseif ($view === 'services'): ?>
                    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">Tailor / shop</th><th scope="col">Service</th><th scope="col">City</th><th scope="col">Base price</th><th scope="col">Avg. days</th></tr></thead><tbody>
                    <?php foreach ($rows as $row): ?><tr><td><strong><?= escape_admin_dashboard($row['shop_name']) ?></strong><small><?= escape_admin_dashboard($row['tailor_name']) ?></small></td><td><?= escape_admin_dashboard($row['garment_type']) ?></td><td><?= escape_admin_dashboard($row['city']) ?></td><td><?= (float) $row['base_price'] > 0 ? 'PKR ' . number_format((float) $row['base_price'], 2) : 'Quote required' ?></td><td><?= (int) $row['avg_days'] ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php elseif ($view === 'tailors'): ?>
                    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">Tailor / shop</th><th scope="col">Email</th><th scope="col">City</th><th scope="col">Services</th><th scope="col">Status</th><th scope="col">Joined</th></tr></thead><tbody>
                    <?php foreach ($rows as $row): ?><tr><td><strong><?= escape_admin_dashboard($row['name']) ?></strong><small><?= escape_admin_dashboard($row['shop_name'] ?? 'Profile not created') ?></small></td><td><?= escape_admin_dashboard($row['email']) ?></td><td><?= escape_admin_dashboard($row['city'] ?? '—') ?></td><td><?= (int) $row['service_count'] ?></td><td><span class="admin-status <?= (int) $row['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><time datetime="<?= escape_admin_dashboard($row['created_at']) ?>"><?= escape_admin_dashboard(date('M j, Y', strtotime($row['created_at']))) ?></time></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php else: ?>
                    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Joined</th></tr></thead><tbody>
                    <?php foreach ($rows as $row): ?><tr><td><strong><?= escape_admin_dashboard($row['name']) ?></strong></td><td><?= escape_admin_dashboard($row['email']) ?></td><td><span class="admin-role"><?= escape_admin_dashboard(ucfirst($row['role'])) ?></span></td><td><span class="admin-status <?= (int) $row['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>"><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><time datetime="<?= escape_admin_dashboard($row['created_at']) ?>"><?= escape_admin_dashboard(date('M j, Y', strtotime($row['created_at']))) ?></time></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
            <p class="admin-access-note">Account activation and deactivation controls are available in <a href="/admin/users.php">Manage account access</a>.</p>
        </main>
    </div>
</body>
</html>
