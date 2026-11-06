<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_style(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$choices = [
    'neckline' => ['Round', 'V-neck', 'Boat', 'Collar', 'Other'],
    'sleeve_length' => ['Sleeveless', 'Short', 'Elbow', 'Three-quarter', 'Full'],
    'fit' => ['Slim', 'Regular', 'Relaxed'],
];
$formValues = [
    'name' => '',
    'neckline' => '',
    'sleeve_length' => '',
    'fit' => '',
    'embroidery' => '',
    'other_notes' => '',
];
$errors = [];
$successMessage = ($_GET['saved'] ?? '') === '1' ? 'Style profile saved.' : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($formValues as $field => $_) {
        $formValues[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    $nameLength = mb_strlen($formValues['name'], 'UTF-8');
    if ($nameLength < 2 || $nameLength > 80) {
        $errors['name'] = 'Profile name must be between 2 and 80 characters.';
    }

    foreach ($choices as $field => $allowedValues) {
        if ($formValues[$field] !== '' && !in_array($formValues[$field], $allowedValues, true)) {
            $errors[$field] = 'Choose a valid option.';
        }
    }
    if (mb_strlen($formValues['embroidery'], 'UTF-8') > 120) {
        $errors['embroidery'] = 'Embroidery preference must be 120 characters or fewer.';
    }
    if (mb_strlen($formValues['other_notes'], 'UTF-8') > 1000) {
        $errors['other_notes'] = 'Additional details must be 1,000 characters or fewer.';
    }

    $preferences = [
        'neckline' => $formValues['neckline'],
        'sleeve_length' => $formValues['sleeve_length'],
        'fit' => $formValues['fit'],
        'embroidery' => $formValues['embroidery'],
        'other_notes' => $formValues['other_notes'],
    ];
    $preferences = array_filter($preferences, static fn (string $value): bool => $value !== '');
    if ($preferences === []) {
        $errors['preferences'] = 'Choose or describe at least one style preference.';
    }

    if ($errors === []) {
        try {
            $encodedPreferences = json_encode($preferences, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $statement = $connection->prepare(
                'INSERT INTO style_profiles (customer_id, name, preferences) '
                . 'VALUES (:customer_id, :name, :preferences)'
            );
            $statement->execute([
                'customer_id' => $user['user_id'],
                'name' => $formValues['name'],
                'preferences' => $encodedPreferences,
            ]);
            header('Location: /customer/styles.php?saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log($exception->getMessage());
            $errors['form'] = 'Style profile could not be saved. Please try again.';
        }
    }
}

$profilesStatement = $connection->prepare(
    'SELECT style_id, name, preferences FROM style_profiles '
    . 'WHERE customer_id = :customer_id ORDER BY style_id DESC'
);
$profilesStatement->execute(['customer_id' => $user['user_id']]);
$styleProfiles = $profilesStatement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My style profiles | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>My style profiles</h1>
        <p>Save reusable garment preferences and choose one when requesting stitching.</p>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_style($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_style($errors['form']) ?></p>
        <?php endif; ?>

        <h2>Create a style profile</h2>
        <form method="post" action="/customer/styles.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= escape_style(csrf_token()) ?>">
            <div>
                <label for="name">Profile name</label>
                <input id="name" name="name" type="text" maxlength="80" required value="<?= escape_style($formValues['name']) ?>">
                <?php if (isset($errors['name'])): ?>
                    <p role="alert"><?= escape_style($errors['name']) ?></p>
                <?php endif; ?>
            </div>
            <?php foreach ($choices as $field => $options): ?>
                <div>
                    <label for="<?= escape_style($field) ?>"><?= escape_style(ucwords(str_replace('_', ' ', $field))) ?></label>
                    <select id="<?= escape_style($field) ?>" name="<?= escape_style($field) ?>">
                        <option value="">No preference</option>
                        <?php foreach ($options as $option): ?>
                            <option value="<?= escape_style($option) ?>"<?= $formValues[$field] === $option ? ' selected' : '' ?>><?= escape_style($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors[$field])): ?>
                        <p role="alert"><?= escape_style($errors[$field]) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div>
                <label for="embroidery">Embroidery preference</label>
                <input id="embroidery" name="embroidery" type="text" maxlength="120" value="<?= escape_style($formValues['embroidery']) ?>">
                <?php if (isset($errors['embroidery'])): ?>
                    <p role="alert"><?= escape_style($errors['embroidery']) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label for="other_notes">Additional style details</label>
                <textarea id="other_notes" name="other_notes" maxlength="1000" rows="4"><?= escape_style($formValues['other_notes']) ?></textarea>
                <?php if (isset($errors['other_notes'])): ?>
                    <p role="alert"><?= escape_style($errors['other_notes']) ?></p>
                <?php endif; ?>
                <?php if (isset($errors['preferences'])): ?>
                    <p role="alert"><?= escape_style($errors['preferences']) ?></p>
                <?php endif; ?>
            </div>
            <button type="submit">Save style profile</button>
        </form>

        <h2>Saved profiles</h2>
        <?php if ($styleProfiles === []): ?>
            <p>You have no saved style profiles yet.</p>
        <?php else: ?>
            <?php foreach ($styleProfiles as $styleProfile): ?>
                <section>
                    <h3><?= escape_style($styleProfile['name']) ?></h3>
                    <?php $preferences = json_decode((string) $styleProfile['preferences'], true); ?>
                    <?php if (is_array($preferences) && $preferences !== []): ?>
                        <dl>
                            <?php foreach ($preferences as $preference => $value): ?>
                                <dt><?= escape_style(ucwords(str_replace('_', ' ', (string) $preference))) ?></dt>
                                <dd><?= escape_style((string) $value) ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php else: ?>
                        <p>No style preferences are saved.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
