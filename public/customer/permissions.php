<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_permission(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$selectedProfileId = '';
$selectedTailorId = '';
$successMessage = match ($_GET['saved'] ?? '') {
    'granted' => 'Measurement access granted to the tailor.',
    'revoked' => 'Measurement access revoked.',
    default => null,
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['grant', 'revoke'], true)) {
        $errors['form'] = 'Choose a valid permission action.';
    }

    if ($action === 'grant') {
        $profileId = filter_var($_POST['profile_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        $tailorId = filter_var($_POST['tailor_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        if ($profileId === false) {
            $errors['profile_id'] = 'Choose one of your measurement profiles.';
        } else {
            $selectedProfileId = (string) $profileId;
        }
        if ($tailorId === false) {
            $errors['tailor_id'] = 'Choose a tailor.';
        } else {
            $selectedTailorId = (string) $tailorId;
        }

        if ($errors === []) {
            $profileCheck = $connection->prepare(
                'SELECT profile_id FROM measurement_profiles '
                . 'WHERE profile_id = :profile_id AND customer_id = :customer_id'
            );
            $profileCheck->execute(['profile_id' => $profileId, 'customer_id' => $user['user_id']]);
            $tailorCheck = $connection->prepare(
                'SELECT tailor_id FROM tailor_profiles WHERE tailor_id = :tailor_id'
            );
            $tailorCheck->execute(['tailor_id' => $tailorId]);
            if ($profileCheck->fetch() === false) {
                http_response_code(404);
                exit('Measurement profile not found.');
            }
            if ($tailorCheck->fetch() === false) {
                http_response_code(404);
                exit('Tailor not found.');
            }
        }
    } elseif ($action === 'revoke') {
        $permissionId = filter_var($_POST['permission_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        if ($permissionId === false) {
            http_response_code(404);
            exit('Permission not found.');
        }
        $permissionCheck = $connection->prepare(
            'SELECT mp.permission_id FROM measurement_permissions AS mp '
            . 'INNER JOIN measurement_profiles AS p ON p.profile_id = mp.profile_id '
            . 'WHERE mp.permission_id = :permission_id AND p.customer_id = :customer_id '
            . 'AND mp.revoked_at IS NULL'
        );
        $permissionCheck->execute([
            'permission_id' => $permissionId,
            'customer_id' => $user['user_id'],
        ]);
        if ($permissionCheck->fetch() === false) {
            http_response_code(404);
            exit('Permission not found.');
        }
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            if ($action === 'grant') {
                $lockProfile = $connection->prepare(
                    'SELECT profile_id FROM measurement_profiles '
                    . 'WHERE profile_id = :profile_id AND customer_id = :customer_id FOR UPDATE'
                );
                $lockProfile->execute(['profile_id' => $profileId, 'customer_id' => $user['user_id']]);
                if ($lockProfile->fetch() === false) {
                    http_response_code(404);
                    throw new RuntimeException('Measurement profile ownership changed during submission.');
                }

                $activeCheck = $connection->prepare(
                    'SELECT permission_id FROM measurement_permissions '
                    . 'WHERE profile_id = :profile_id AND tailor_id = :tailor_id '
                    . 'AND revoked_at IS NULL LIMIT 1 FOR UPDATE'
                );
                $activeCheck->execute(['profile_id' => $profileId, 'tailor_id' => $tailorId]);
                if ($activeCheck->fetch() !== false) {
                    $errors['form'] = 'This tailor already has access to that profile.';
                } else {
                    $grant = $connection->prepare(
                        'INSERT INTO measurement_permissions (profile_id, tailor_id) '
                        . 'VALUES (:profile_id, :tailor_id)'
                    );
                    $grant->execute(['profile_id' => $profileId, 'tailor_id' => $tailorId]);
                }
            } else {
                $lockPermission = $connection->prepare(
                    'SELECT mp.profile_id, mp.tailor_id FROM measurement_permissions AS mp '
                    . 'INNER JOIN measurement_profiles AS p ON p.profile_id = mp.profile_id '
                    . 'WHERE mp.permission_id = :permission_id AND p.customer_id = :customer_id '
                    . 'AND mp.revoked_at IS NULL FOR UPDATE'
                );
                $lockPermission->execute([
                    'permission_id' => $permissionId,
                    'customer_id' => $user['user_id'],
                ]);
                $permission = $lockPermission->fetch();
                if ($permission === false) {
                    http_response_code(404);
                    throw new RuntimeException('Permission ownership changed during submission.');
                }

                // Quotation acceptance locks the same shop before creating an order.
                $lockTailor = $connection->prepare(
                    'SELECT tailor_id FROM tailor_profiles WHERE tailor_id = :tailor_id FOR UPDATE'
                );
                $lockTailor->execute(['tailor_id' => $permission['tailor_id']]);
                if ($lockTailor->fetchColumn() === false) {
                    throw new RuntimeException('Tailor profile not found during revocation.');
                }

                $activeOrder = $connection->prepare(
                    'SELECT o.order_id FROM orders AS o '
                    . 'INNER JOIN measurement_versions AS mv ON mv.version_id = o.measurement_version_id '
                    . 'INNER JOIN quotations AS q ON q.quotation_id = o.quotation_id '
                    . 'INNER JOIN order_requests AS r ON r.request_id = q.request_id '
                    . 'WHERE mv.profile_id = :profile_id AND r.tailor_id = :tailor_id '
                    . "AND o.status IN ('confirmed', 'cutting', 'stitching', 'ready') "
                    . 'ORDER BY o.order_id LIMIT 1'
                );
                $activeOrder->execute([
                    'profile_id' => $permission['profile_id'],
                    'tailor_id' => $permission['tailor_id'],
                ]);
                $blockingOrderId = $activeOrder->fetchColumn();
                if ($blockingOrderId !== false) {
                    $errors['form'] = 'This tailor still needs this profile for Order #'
                        . (int) $blockingOrderId
                        . '. You can revoke access once all active orders using it are delivered.';
                } else {
                    $revoke = $connection->prepare(
                        'UPDATE measurement_permissions SET revoked_at = CURRENT_TIMESTAMP '
                        . 'WHERE permission_id = :permission_id AND revoked_at IS NULL'
                    );
                    $revoke->execute(['permission_id' => $permissionId]);
                    if ($revoke->rowCount() !== 1) {
                        throw new RuntimeException('Permission could not be revoked.');
                    }
                }
            }

            if ($errors === []) {
                $connection->commit();
                header('Location: /customer/permissions.php?saved=' . ($action === 'grant' ? 'granted' : 'revoked'), true, 303);
                exit;
            }

            $connection->rollBack();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if (http_response_code() !== 404) {
                error_log($exception->getMessage());
                $errors['form'] = 'The permission could not be saved. Please try again.';
            }
        }
    }
}

$profilesStatement = $connection->prepare(
    'SELECT profile_id, profile_name FROM measurement_profiles '
    . 'WHERE customer_id = :customer_id ORDER BY profile_name, profile_id'
);
$profilesStatement->execute(['customer_id' => $user['user_id']]);
$profiles = $profilesStatement->fetchAll();

$tailors = $connection->query(
    'SELECT tailor_id, shop_name, city FROM tailor_profiles ORDER BY shop_name, tailor_id'
)->fetchAll();

$activeStatement = $connection->prepare(
    'SELECT mp.permission_id, mp.profile_id, mp.granted_at, '
    . 'p.profile_name, tp.shop_name, tp.city '
    . 'FROM measurement_permissions AS mp '
    . 'INNER JOIN measurement_profiles AS p ON p.profile_id = mp.profile_id '
    . 'INNER JOIN tailor_profiles AS tp ON tp.tailor_id = mp.tailor_id '
    . 'WHERE p.customer_id = :customer_id AND mp.revoked_at IS NULL '
    . 'ORDER BY p.profile_name, tp.shop_name, mp.permission_id DESC'
);
$activeStatement->execute(['customer_id' => $user['user_id']]);
$activePermissions = $activeStatement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Measurement sharing permissions | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/customer.css">
    <link rel="stylesheet" href="/assets/css/permissions.css">
</head>
<body class="customer-page permissions-page">
    <a class="skip-link" href="#main">Skip to sharing permissions</a>
    <div class="customer-shell">
        <?php $customerSection = 'permissions'; require dirname(__DIR__, 2) . '/includes/customer-navigation.php'; ?>
        <main id="main" class="customer-main permissions-main">
            <header class="permissions-heading">
                <div>
                    <p class="eyebrow">Your measurements, your choice</p>
                    <h1>Sharing Permissions</h1>
                    <p>Choose which tailor can view a measurement profile. You can revoke access when it’s no longer needed.</p>
                </div>
                <a class="button permissions-profile-link" href="/customer/measurements.php">Manage measurements <span aria-hidden="true">→</span></a>
            </header>

        <?php if ($successMessage !== null): ?>
            <p class="permissions-alert permissions-success" role="status"><?= escape_permission($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p class="permissions-alert permissions-error" role="alert"><?= escape_permission($errors['form']) ?></p>
        <?php endif; ?>

            <div class="permissions-layout">
                <section class="grant-permission-card" aria-labelledby="grant-title">
                    <div class="permission-card-heading">
                        <span class="permission-icon" aria-hidden="true">♧</span>
                        <div><p class="permissions-eyebrow">Secure sharing</p><h2 id="grant-title">Grant tailor access</h2><p>Select a profile and the shop that may view it.</p></div>
                    </div>
                    <?php if ($profiles === []): ?>
                        <div class="permission-empty-state"><h3>Create a measurement profile first</h3><p>You need a saved profile before you can share measurements with a tailor.</p><a class="button" href="/customer/measurements.php">Create a measurement profile <span aria-hidden="true">→</span></a></div>
                    <?php elseif ($tailors === []): ?>
                        <div class="permission-empty-state"><h3>No tailor shops are available yet</h3><p>Once a shop is available, you can grant it access to a measurement profile.</p><a class="outline-button" href="/customer/tailors.php">Browse tailors</a></div>
                    <?php else: ?>
                        <form class="permission-grant-form" method="post" action="/customer/permissions.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape_permission(csrf_token()) ?>">
                            <input type="hidden" name="action" value="grant">
                            <div class="permission-field">
                                <label for="profile_id">Measurement profile</label>
                                <select id="profile_id" name="profile_id" required>
                                    <option value="">Choose a profile</option>
                                    <?php foreach ($profiles as $profile): ?>
                                        <option value="<?= (int) $profile['profile_id'] ?>"<?= $selectedProfileId === (string) $profile['profile_id'] ? ' selected' : '' ?>><?= escape_permission($profile['profile_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['profile_id'])): ?><p class="permission-field-error" role="alert"><?= escape_permission($errors['profile_id']) ?></p><?php endif; ?>
                            </div>
                            <div class="permission-field">
                                <label for="tailor_id">Tailor shop</label>
                                <select id="tailor_id" name="tailor_id" required>
                                    <option value="">Choose a tailor</option>
                                    <?php foreach ($tailors as $tailor): ?>
                                        <option value="<?= (int) $tailor['tailor_id'] ?>"<?= $selectedTailorId === (string) $tailor['tailor_id'] ? ' selected' : '' ?>><?= escape_permission($tailor['shop_name']) ?> · <?= escape_permission($tailor['city']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['tailor_id'])): ?><p class="permission-field-error" role="alert"><?= escape_permission($errors['tailor_id']) ?></p><?php endif; ?>
                            </div>
                            <p class="permission-form-note"><span aria-hidden="true">ⓘ</span> The tailor can view the profile only while access is active. You can revoke it later, unless an active order still needs the measurements.</p>
                            <button class="button permission-submit" type="submit">Grant access <span aria-hidden="true">→</span></button>
                        </form>
                    <?php endif; ?>
                </section>

                <section class="active-permissions-section" aria-labelledby="active-permissions-title">
                    <header class="active-permissions-heading"><div><p class="permissions-eyebrow">Currently shared</p><h2 id="active-permissions-title">Active access</h2></div><span class="permission-count"><?= count($activePermissions) ?> <?= count($activePermissions) === 1 ? 'shop' : 'shops' ?></span></header>
                    <?php if ($activePermissions === []): ?>
                        <div class="permissions-empty-card"><span class="permissions-empty-icon" aria-hidden="true">♢</span><h3>No active sharing yet</h3><p>No tailor can currently view your measurement profiles. Grant access to a shop when you’re ready to share them.</p></div>
                    <?php else: ?>
                        <div class="active-permission-list">
                            <?php foreach ($activePermissions as $permission): ?>
                                <article class="active-permission-card">
                                    <span class="shared-shop-mark" aria-hidden="true">✂</span>
                                    <div class="active-permission-info"><p class="shared-profile-name"><?= escape_permission($permission['profile_name']) ?></p><h3><?= escape_permission($permission['shop_name']) ?></h3><p><?= escape_permission($permission['city']) ?> <span aria-hidden="true">·</span> Shared <?= escape_permission(date('M j, Y', strtotime($permission['granted_at']))) ?></p></div>
                                    <form method="post" action="/customer/permissions.php" class="revoke-form">
                                        <input type="hidden" name="csrf_token" value="<?= escape_permission(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="permission_id" value="<?= (int) $permission['permission_id'] ?>">
                                        <button class="revoke-button" type="submit">Revoke access</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
