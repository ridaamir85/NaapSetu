<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';

$user = require_role('tailor');
$connection = database();
$profile = tailor_profile_for_user($connection, $user['user_id']);

if ($profile === null) {
    redirect_to('/tailor/profile.php');
}

function escape_service(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$categories = [
    'women' => ['label' => 'Women’s', 'garments' => ['Kameez', 'Shalwar Kameez', 'Kurti', 'Abaya', 'Other']],
    'men' => ['label' => 'Men’s', 'garments' => ['Kurta', 'Shalwar Kameez', 'Waistcoat', 'Trousers', 'Other']],
];
$values = ['category' => '', 'garment_type' => '', 'custom_garment' => '', 'base_price' => '', 'avg_days' => ''];
$errors = [];
$successMessage = $_SESSION['service_success'] ?? null;
unset($_SESSION['service_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $field => $_) {
        $input = $_POST[$field] ?? '';
        $values[$field] = is_string($input) ? trim($input) : '';
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    $category = $categories[$values['category']] ?? null;
    $garment = $values['garment_type'];
    if ($category === null) {
        $errors['category'] = 'Select a service category.';
    } elseif (!in_array($garment, $category['garments'], true)) {
        $errors['garment_type'] = 'Select a garment type for this category.';
    } elseif ($garment === 'Other') {
        $garment = $values['custom_garment'];
        $length = mb_strlen($garment, 'UTF-8');
        if ($length < 2 || $length > 52 || preg_match('/\p{L}/u', $garment) !== 1) {
            $errors['custom_garment'] = 'Enter a garment name of 2 to 52 characters that includes a letter.';
        }
    }

    $price = $values['base_price'];
    if (preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $price) !== 1) {
        $errors['base_price'] = 'Enter a price from 0 to 99,999,999.99 with up to two decimal places.';
    }

    $averageDays = filter_var($values['avg_days'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 90],
    ]);
    if ($averageDays === false) {
        $errors['avg_days'] = 'Enter a whole number from 1 to 90 days.';
    }

    if ($errors === []) {
        try {
            $statement = $connection->prepare(
                'INSERT INTO tailor_services (tailor_id, garment_type, base_price, avg_days) '
                . 'VALUES (:tailor_id, :garment_type, :base_price, :avg_days)'
            );
            $statement->execute([
                'tailor_id' => $profile['tailor_id'],
                'garment_type' => $category['label'] . ' ' . $garment,
                'base_price' => $price,
                'avg_days' => $averageDays,
            ]);

            $_SESSION['service_success'] = 'Service added successfully.';
            header('Location: /tailor/services.php', true, 303);
            exit;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors['form'] = 'The service could not be added. Please try again.';
        }
    }
}

$servicesStatement = $connection->prepare(
    'SELECT service_id, garment_type, base_price, avg_days '
    . 'FROM tailor_services WHERE tailor_id = :tailor_id ORDER BY service_id DESC'
);
$servicesStatement->execute(['tailor_id' => $profile['tailor_id']]);
$services = $servicesStatement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Services | NaapSetu</title>
</head>
<body>
    <main>
        <a href="dashboard.php">Back to dashboard</a>
        <h1>Tailor services</h1>
        <p>Add the garment services customers can select.</p>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_service($successMessage) ?></p>
        <?php endif; ?>

        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_service($errors['form']) ?></p>
        <?php endif; ?>

        <form method="post" action="services.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape_service(csrf_token()) ?>">

            <div>
                <label for="category">Service category</label>
                <select id="category" name="category" required>
                    <option value="">Choose a category</option>
                    <?php foreach ($categories as $key => $category): ?>
                        <option value="<?= escape_service($key) ?>"<?= $values['category'] === $key ? ' selected' : '' ?>>
                            <?= escape_service($category['label']) ?> stitching
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['category'])): ?>
                    <p role="alert"><?= escape_service($errors['category']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="garment_type">Garment type</label>
                <select id="garment_type" name="garment_type" required>
                    <option value="">Choose a garment</option>
                    <?php foreach ($categories as $key => $category): ?>
                        <optgroup label="<?= escape_service($category['label']) ?> stitching" data-category="<?= escape_service($key) ?>">
                            <?php foreach ($category['garments'] as $garment): ?>
                                <option value="<?= escape_service($garment) ?>"<?= $values['category'] === $key && $values['garment_type'] === $garment ? ' selected' : '' ?>>
                                    <?= escape_service($garment) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['garment_type'])): ?>
                    <p role="alert"><?= escape_service($errors['garment_type']) ?></p>
                <?php endif; ?>
            </div>

            <div id="custom_garment_field">
                <label for="custom_garment">Garment name (for Other)</label>
                <input id="custom_garment" name="custom_garment" type="text" maxlength="52" value="<?= escape_service($values['custom_garment']) ?>">
                <?php if (isset($errors['custom_garment'])): ?>
                    <p role="alert"><?= escape_service($errors['custom_garment']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="base_price">Base price</label>
                <input id="base_price" name="base_price" type="number" min="0" step="0.01" required value="<?= escape_service($values['base_price']) ?>">
                <?php if (isset($errors['base_price'])): ?>
                    <p role="alert"><?= escape_service($errors['base_price']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="avg_days">Average completion days</label>
                <input id="avg_days" name="avg_days" type="number" min="1" max="90" step="1" required value="<?= escape_service($values['avg_days']) ?>">
                <small>Enter an estimate from 1 to 90 days.</small>
                <?php if (isset($errors['avg_days'])): ?>
                    <p role="alert"><?= escape_service($errors['avg_days']) ?></p>
                <?php endif; ?>
            </div>

            <button type="submit">Add service</button>
        </form>

        <h2>Published services</h2>
        <?php if ($services === []): ?>
            <p>No services have been added yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Garment type</th>
                        <th scope="col">Base price</th>
                        <th scope="col">Average days</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?= escape_service($service['garment_type']) ?></td>
                            <td><?= escape_service(number_format((float) $service['base_price'], 2)) ?></td>
                            <td><?= escape_service((string) $service['avg_days']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
    <script src="/assets/js/service-garments.js" defer></script>
</body>
</html>
