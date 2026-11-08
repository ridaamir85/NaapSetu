<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';

$user = require_role('tailor');
$connection = database();
$tailorProfile = tailor_profile_for_user($connection, $user['user_id']);
if ($tailorProfile === null) {
    redirect_to('/tailor/profile.php');
}

function escape_shared_measurement(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$orderId = null;
if (isset($_GET['order_id'])) {
    $orderId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    if ($orderId === false) {
        http_response_code(404);
        exit('Order measurements not found.');
    }
}

$profileId = null;
if ($orderId === null) {
    $profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    if ($profileId === false) {
        http_response_code(404);
        exit('Measurement profile not found.');
    }
}

if ($orderId !== null) {
    $statement = $connection->prepare(
        'SELECT p.profile_id, p.profile_name, p.gender, v.version_no, v.created_at, '
        . 'mv.measurement_name, mv.value_cm, o.order_id, s.garment_type, u.name AS customer_name '
        . 'FROM orders AS o '
        . 'INNER JOIN measurement_versions AS v ON v.version_id = o.measurement_version_id '
        . 'INNER JOIN measurement_profiles AS p ON p.profile_id = v.profile_id '
        . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
        . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
        . 'INNER JOIN tailor_services AS s ON s.service_id = r.service_id AND s.tailor_id = r.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = r.customer_id '
        . 'INNER JOIN measurement_permissions AS permission ON permission.profile_id = p.profile_id '
        . 'AND permission.tailor_id = r.tailor_id AND permission.revoked_at IS NULL '
        . 'LEFT JOIN measurement_values AS mv ON mv.version_id = v.version_id '
        . 'WHERE o.order_id = :order_id AND r.tailor_id = :tailor_id '
        . 'AND p.customer_id = r.customer_id ORDER BY mv.measurement_name'
    );
    $statement->execute(['order_id' => $orderId, 'tailor_id' => $tailorProfile['tailor_id']]);
} else {
    $statement = $connection->prepare(
        'SELECT p.profile_id, p.profile_name, p.gender, v.version_no, v.created_at, '
        . 'mv.measurement_name, mv.value_cm, NULL AS order_id, NULL AS garment_type, u.name AS customer_name '
        . 'FROM measurement_permissions AS permission '
        . 'INNER JOIN measurement_profiles AS p ON p.profile_id = permission.profile_id '
        . 'INNER JOIN users AS u ON u.user_id = p.customer_id '
        . 'LEFT JOIN measurement_versions AS v ON v.profile_id = p.profile_id '
        . 'AND v.version_no = (SELECT MAX(v2.version_no) FROM measurement_versions AS v2 WHERE v2.profile_id = p.profile_id) '
        . 'LEFT JOIN measurement_values AS mv ON mv.version_id = v.version_id '
        . 'WHERE permission.profile_id = :profile_id AND permission.tailor_id = :tailor_id '
        . 'AND permission.revoked_at IS NULL ORDER BY mv.measurement_name'
    );
    $statement->execute([
        'profile_id' => $profileId,
        'tailor_id' => $tailorProfile['tailor_id'],
    ]);
}
$rows = $statement->fetchAll();
if ($rows === []) {
    http_response_code(404);
    exit('Measurement profile not found or access has been revoked.');
}

$measurements = [];
foreach ($rows as $row) {
    if ($row['measurement_name'] !== null) {
        $measurements[] = [
            'name' => (string) $row['measurement_name'],
            'value_cm' => (string) $row['value_cm'],
        ];
    }
}
$profileName = (string) $rows[0]['profile_name'];
$gender = (string) $rows[0]['gender'];
$versionNo = $rows[0]['version_no'] === null ? null : (int) $rows[0]['version_no'];
$versionDate = $rows[0]['created_at'] === null ? null : (string) $rows[0]['created_at'];
$orderContext = $orderId !== null;
$backHref = $orderContext ? '/tailor/orders.php?order_id=' . (int) $orderId : '/tailor/requests.php';
$backLabel = $orderContext ? 'Back to order' : 'Back to requests';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $orderContext ? 'Order measurements' : 'Shared measurements' ?> | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-navigation.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
    <link rel="stylesheet" href="/assets/css/tailor-measurements.css">
</head>
<body class="tailor-measurements-page">
    <a class="skip-link" href="#main">Skip to shared measurements</a>
    <div class="tailor-dashboard-shell">
        <?php $tailorSection = $orderContext ? 'orders' : 'requests'; require dirname(__DIR__, 2) . '/includes/tailor-navigation.php'; ?>
        <main id="main" class="tailor-measurements-main">
            <a class="tailor-back-link" href="<?= escape_shared_measurement($backHref) ?>"><?= escape_shared_measurement($backLabel) ?></a>
            <header class="measurements-page-heading">
                <p class="measurements-eyebrow"><?= $orderContext ? 'Saved with this order' : 'Customer permission active' ?></p>
                <h1><?= $orderContext ? 'Order measurements' : 'Shared measurements' ?></h1>
                <p><?= $orderContext ? 'These are the measurements saved when this order was accepted.' : 'Review the latest version of a measurement profile shared with your shop.' ?></p>
            </header>

            <section class="measurements-profile-card" aria-labelledby="profile-name">
                <div class="measurements-profile-heading">
                    <span class="measurements-profile-icon" aria-hidden="true">⌁</span>
                    <div><p class="measurements-eyebrow">Measurement profile</p><h2 id="profile-name"><?= escape_shared_measurement($profileName) ?></h2></div>
                    <?php if ($versionNo !== null): ?><span class="measurement-version-badge">Version <?= $versionNo ?></span><?php endif; ?>
                </div>
                <dl class="measurements-summary">
                    <div><dt>Customer</dt><dd><?= $rows[0]['customer_name'] === null ? 'Shared customer profile' : escape_shared_measurement($rows[0]['customer_name']) ?></dd></div>
                    <div><dt>Garment</dt><dd><?= $rows[0]['garment_type'] === null ? 'Profile measurements' : escape_shared_measurement($rows[0]['garment_type']) ?></dd></div>
                    <div><dt>Fit profile</dt><dd><?= escape_shared_measurement(ucfirst($gender)) ?></dd></div>
                    <div><dt>Version saved</dt><dd><?php if ($versionDate !== null): ?><time datetime="<?= escape_shared_measurement($versionDate) ?>"><?= escape_shared_measurement(date('M j, Y', strtotime($versionDate))) ?></time><?php else: ?>No version saved<?php endif; ?></dd></div>
                    <?php if ($orderContext): ?><div><dt>Order</dt><dd>ORD-<?= str_pad((string) $rows[0]['order_id'], 4, '0', STR_PAD_LEFT) ?></dd></div><?php endif; ?>
                </dl>
            </section>

            <section class="measurements-values-card" aria-labelledby="measurements-title">
                <header><div><p class="measurements-eyebrow">Saved values</p><h2 id="measurements-title">Measurements</h2></div><span class="measurements-unit">Centimeters</span></header>
                <?php if ($versionNo === null): ?>
                    <p class="measurements-empty">This profile has no saved measurement version yet.</p>
                <?php elseif ($measurements === []): ?>
                    <p class="measurements-empty">No measurement values are saved in this version.</p>
                <?php else: ?>
                    <dl class="measurements-value-grid">
                        <?php foreach ($measurements as $measurement): ?>
                            <div><dt><?= escape_shared_measurement($measurement['name']) ?></dt><dd><?= escape_shared_measurement($measurement['value_cm']) ?><span>cm</span></dd></div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </section>
            <p class="measurements-permission-note"><span aria-hidden="true">✓</span> The customer has granted your shop access to this profile. Access is checked each time you open it.</p>
        </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
