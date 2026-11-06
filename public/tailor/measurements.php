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

$profileId = filter_var($_GET['profile_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
if ($profileId === false) {
    http_response_code(404);
    exit('Measurement profile not found.');
}

$statement = $connection->prepare(
    'SELECT p.profile_name, p.gender, v.version_no, v.created_at, '
    . 'mv.measurement_name, mv.value_cm '
    . 'FROM measurement_permissions AS permission '
    . 'INNER JOIN measurement_profiles AS p ON p.profile_id = permission.profile_id '
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shared measurements | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/tailor/dashboard.php">Back to dashboard</a>
        <h1>Shared measurements</h1>
        <h2><?= escape_shared_measurement($profileName) ?></h2>
        <p>Gender: <?= escape_shared_measurement(ucfirst($gender)) ?></p>

        <?php if ($versionNo === null): ?>
            <p>This profile has no saved measurement version yet.</p>
        <?php else: ?>
            <h3>Latest version: <?= $versionNo ?></h3>
            <?php if ($versionDate !== null): ?>
                <p><time datetime="<?= escape_shared_measurement($versionDate) ?>"><?= escape_shared_measurement($versionDate) ?></time></p>
            <?php endif; ?>
            <?php if ($measurements === []): ?>
                <p>No measurement values are saved in this version.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($measurements as $measurement): ?>
                        <li><?= escape_shared_measurement($measurement['name']) ?>: <?= escape_shared_measurement($measurement['value_cm']) ?> cm</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
