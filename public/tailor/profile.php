<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';

$user = require_role('tailor');
$connection = database();
$profile = tailor_profile_for_user($connection, $user['user_id']);
$cities = [
    'Faisalabad', 'Gujranwala', 'Hyderabad', 'Islamabad', 'Karachi',
    'Lahore', 'Multan', 'Peshawar', 'Quetta', 'Rawalpindi', 'Sialkot',
];
$savedCity = trim($profile['city'] ?? '');
$selectedCity = '';
foreach ($cities as $city) {
    if (strcasecmp($savedCity, $city) === 0) {
        $selectedCity = $city;
        break;
    }
}

function escape_profile(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$values = [
    'shop_name' => $profile['shop_name'] ?? '',
    'city' => $selectedCity,
    'address' => $profile['address'] ?? '',
    'max_active_orders' => (string) ($profile['max_active_orders'] ?? 10),
];
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $_) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if (strlen($values['shop_name']) < 2 || strlen($values['shop_name']) > 120) {
        $errors['shop_name'] = 'Shop name must be between 2 and 120 characters.';
    }

    if (!in_array($values['city'], $cities, true)) {
        $errors['city'] = 'Select a city from the list.';
    }

    if (strlen($values['address']) < 5 || strlen($values['address']) > 255) {
        $errors['address'] = 'Address must be between 5 and 255 characters.';
    } elseif (preg_match('/\p{L}/u', $values['address']) !== 1) {
        $errors['address'] = 'Enter a shop address that includes a street, area, or landmark.';
    }

    $capacity = filter_var($values['max_active_orders'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10],
    ]);
    if ($capacity === false) {
        $errors['max_active_orders'] = 'Enter a whole number from 1 to 10.';
    }

    if ($errors === []) {
        try {
            if ($profile === null) {
                $statement = $connection->prepare(
                    'INSERT INTO tailor_profiles '
                    . '(user_id, shop_name, city, address, max_active_orders) '
                    . 'VALUES (:user_id, :shop_name, :city, :address, :max_active_orders)'
                );
                $statement->execute([
                    'user_id' => $user['user_id'],
                    'shop_name' => $values['shop_name'],
                    'city' => $values['city'],
                    'address' => $values['address'],
                    'max_active_orders' => $capacity,
                ]);
                $successMessage = 'Shop profile created successfully.';
            } else {
                $statement = $connection->prepare(
                    'UPDATE tailor_profiles '
                    . 'SET shop_name = :shop_name, city = :city, address = :address, '
                    . 'max_active_orders = :max_active_orders '
                    . 'WHERE tailor_id = :tailor_id AND user_id = :user_id'
                );
                $statement->execute([
                    'shop_name' => $values['shop_name'],
                    'city' => $values['city'],
                    'address' => $values['address'],
                    'max_active_orders' => $capacity,
                    'tailor_id' => $profile['tailor_id'],
                    'user_id' => $user['user_id'],
                ]);
                $successMessage = 'Shop profile updated successfully.';
            }

            $profile = tailor_profile_for_user($connection, $user['user_id']);
            unset($_SESSION['csrf_token']);
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors['form'] = 'The shop profile could not be saved. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $profile === null ? 'Create shop profile' : 'Edit shop profile' ?> | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/tailor-navigation.css">
    <link rel="stylesheet" href="/assets/css/tailor-profile.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="tailor-profile-page">
    <a class="skip-link" href="#main">Skip to shop profile</a>
    <div class="tailor-dashboard-shell">
    <?php $tailorSection = 'profile'; require dirname(__DIR__, 2) . '/includes/tailor-navigation.php'; ?>
    <main id="main" class="profile-main">
        <header class="profile-heading">
            <p class="profile-eyebrow">Your shop on NaapSetu</p>
            <h1><?= $profile === null ? 'Create your shop profile' : 'Shop profile' ?></h1>
            <p>Set the details customers see and the number of active orders your shop can handle.</p>
        </header>

        <?php if ($successMessage !== null): ?>
            <p class="profile-message profile-success" role="status"><?= escape_profile($successMessage) ?></p>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <p class="profile-message profile-error" role="alert"><?= escape_profile($errors['form']) ?></p>
        <?php endif; ?>

        <div class="profile-layout">
            <section class="profile-form-card" aria-labelledby="profile-form-title">
                <div class="profile-card-heading">
                    <span class="profile-heading-icon" aria-hidden="true">✂</span>
                    <div><h2 id="profile-form-title">Shop details</h2><p>Keep your public shop information up to date.</p></div>
                </div>
                <form method="post" action="/tailor/profile.php" class="shop-profile-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= escape_profile(csrf_token()) ?>">

                    <div class="profile-field profile-field-wide">
                        <label for="shop_name">Shop name</label>
                        <input id="shop_name" name="shop_name" type="text" maxlength="120" required value="<?= escape_profile($values['shop_name']) ?>" placeholder="e.g. Thread &amp; Needle Studio" autocomplete="organization">
                        <?php if (isset($errors['shop_name'])): ?><p class="profile-field-error" role="alert"><?= escape_profile($errors['shop_name']) ?></p><?php endif; ?>
                    </div>

                    <div class="profile-field">
                        <label for="city">City</label>
                        <select id="city" name="city" required>
                            <option value="">Select a city</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?= escape_profile($city) ?>"<?= $values['city'] === $city ? ' selected' : '' ?>><?= escape_profile($city) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($savedCity !== '' && $selectedCity === ''): ?><p class="profile-field-hint">Previously saved city: <?= escape_profile($savedCity) ?>. Select a supported city to save changes.</p><?php endif; ?>
                        <?php if (isset($errors['city'])): ?><p class="profile-field-error" role="alert"><?= escape_profile($errors['city']) ?></p><?php endif; ?>
                    </div>

                    <div class="profile-field profile-field-wide">
                        <label for="address">Shop address</label>
                        <textarea id="address" name="address" maxlength="255" rows="3" required placeholder="Street, area, and a nearby landmark"><?= escape_profile($values['address']) ?></textarea>
                        <?php if (isset($errors['address'])): ?><p class="profile-field-error" role="alert"><?= escape_profile($errors['address']) ?></p><?php endif; ?>
                    </div>

                    <div class="profile-field profile-capacity-field">
                        <label for="max_active_orders">Maximum active orders</label>
                        <input id="max_active_orders" name="max_active_orders" type="number" min="1" max="10" step="1" required value="<?= escape_profile($values['max_active_orders']) ?>">
                        <p class="profile-field-hint">Set how many confirmed, unfinished orders your shop can handle at once (1–10).</p>
                        <?php if ((int) ($profile['max_active_orders'] ?? 10) > 10): ?><p class="profile-field-hint">Your previous limit was <?= (int) $profile['max_active_orders'] ?>. Choose 10 or less to save changes.</p><?php endif; ?>
                        <?php if (isset($errors['max_active_orders'])): ?><p class="profile-field-error" role="alert"><?= escape_profile($errors['max_active_orders']) ?></p><?php endif; ?>
                    </div>

                    <div class="profile-form-actions"><button class="button" type="submit"><?= $profile === null ? 'Create shop profile' : 'Save profile changes' ?><span aria-hidden="true">→</span></button></div>
                </form>
            </section>

            <aside class="profile-preview-card" aria-labelledby="profile-preview-title">
                <p class="profile-eyebrow">Customer view</p>
                <h2 id="profile-preview-title">Your shop at a glance</h2>
                <div class="profile-preview-mark" aria-hidden="true">✂</div>
                <p class="profile-preview-name"><?= $values['shop_name'] !== '' ? escape_profile($values['shop_name']) : 'Your shop name' ?></p>
                <p class="profile-preview-city"><?= $values['city'] !== '' ? escape_profile($values['city']) : 'Choose your city' ?></p>
                <p class="profile-preview-address"><?= $values['address'] !== '' ? escape_profile($values['address']) : 'Your shop address will appear here.' ?></p>
                <div class="profile-capacity-note"><strong><?= escape_profile($values['max_active_orders']) ?></strong><span>active orders at a time</span></div>
                <p class="profile-preview-hint">Your shop appears to customers when it has at least one service available.</p>
            </aside>
        </div>
    </main>
    </div>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
