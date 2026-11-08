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
$showStyleForm = $errors !== [] || isset($_GET['new']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Style profiles | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/customer.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body class="customer-page styles-page">
    <a class="skip-link" href="#main">Skip to style profiles</a>
    <div class="customer-shell">
        <?php $customerSection = 'styles'; require dirname(__DIR__, 2) . '/includes/customer-navigation.php'; ?>
        <main id="main" class="customer-main styles-main">
            <header class="styles-heading">
                <div>
                    <p class="eyebrow">Your signature details</p>
                    <h1>Style Profiles</h1>
                    <p>Save the details you love and reuse them with your next stitching request.</p>
                </div>
                <details class="style-create" id="create-style" <?= $showStyleForm ? 'open' : '' ?>>
                    <summary class="button"><span aria-hidden="true">＋</span> Add style</summary>
                    <div class="style-form-panel">
                        <h2>Create a style profile</h2>
                        <?php if (isset($errors['form'])): ?>
                            <p class="style-alert" role="alert"><?= escape_style($errors['form']) ?></p>
                        <?php endif; ?>
                        <?php if (isset($errors['preferences'])): ?>
                            <p class="style-alert" role="alert"><?= escape_style($errors['preferences']) ?></p>
                        <?php endif; ?>
                        <form method="post" action="/customer/styles.php" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape_style(csrf_token()) ?>">
                            <div class="style-field style-field-wide">
                                <label for="name">Profile name</label>
                                <input id="name" name="name" type="text" maxlength="80" required value="<?= escape_style($formValues['name']) ?>" placeholder="e.g. Everyday Kurta">
                                <?php if (isset($errors['name'])): ?><p class="style-alert" role="alert"><?= escape_style($errors['name']) ?></p><?php endif; ?>
                            </div>
                            <?php foreach ($choices as $field => $options): ?>
                                <div class="style-field">
                                    <label for="<?= escape_style($field) ?>"><?= escape_style(ucwords(str_replace('_', ' ', $field))) ?></label>
                                    <select id="<?= escape_style($field) ?>" name="<?= escape_style($field) ?>">
                                        <option value="">No preference</option>
                                        <?php foreach ($options as $option): ?>
                                            <option value="<?= escape_style($option) ?>"<?= $formValues[$field] === $option ? ' selected' : '' ?>><?= escape_style($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($errors[$field])): ?><p class="style-alert" role="alert"><?= escape_style($errors[$field]) ?></p><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="style-field">
                                <label for="embroidery">Embroidery preference</label>
                                <input id="embroidery" name="embroidery" type="text" maxlength="120" value="<?= escape_style($formValues['embroidery']) ?>" placeholder="e.g. Simple cuffs">
                                <?php if (isset($errors['embroidery'])): ?><p class="style-alert" role="alert"><?= escape_style($errors['embroidery']) ?></p><?php endif; ?>
                            </div>
                            <div class="style-field style-field-wide">
                                <label for="other_notes">Additional details <span>(optional)</span></label>
                                <textarea id="other_notes" name="other_notes" maxlength="1000" rows="3" placeholder="Any other details for the tailor"><?= escape_style($formValues['other_notes']) ?></textarea>
                                <?php if (isset($errors['other_notes'])): ?><p class="style-alert" role="alert"><?= escape_style($errors['other_notes']) ?></p><?php endif; ?>
                            </div>
                            <button type="submit">Save style profile</button>
                        </form>
                    </div>
                </details>
            </header>

            <?php if ($successMessage !== null): ?>
                <p class="style-success" role="status"><?= escape_style($successMessage) ?></p>
            <?php endif; ?>

            <section class="style-gallery" aria-label="Saved style profiles">
                <?php if ($styleProfiles === []): ?>
                    <div class="style-empty">
                        <span class="style-empty-mark" aria-hidden="true">✂</span>
                        <h2>Your style collection starts here</h2>
                        <p>Create a profile to save neckline, sleeve, fit, and embroidery preferences for future requests.</p>
                    </div>
                <?php else: ?>
                    <?php $styleImages = ['/assets/images/fabric-kurtas.jpg', '/assets/images/fabric-suits.jpg', '/assets/images/fabric-shirts.jpg']; ?>
                    <?php foreach ($styleProfiles as $styleProfile): ?>
                        <?php
                        $imageIndex = ((int) $styleProfile['style_id'] - 1) % count($styleImages);
                        $preferences = json_decode((string) $styleProfile['preferences'], true);
                        $preferences = is_array($preferences)
                            ? array_filter($preferences, static fn ($value): bool => is_scalar($value) && (string) $value !== '')
                            : [];
                        ?>
                        <article class="style-card">
                            <img class="style-card-image" src="<?= $styleImages[$imageIndex] ?>" alt="Fabric detail for <?= escape_style($styleProfile['name']) ?>" loading="lazy" width="720" height="480">
                            <div class="style-card-content">
                                <h2><?= escape_style($styleProfile['name']) ?></h2>
                                <?php if ($preferences !== []): ?>
                                    <ul class="style-preferences">
                                        <?php foreach ($preferences as $preference => $value): ?>
                                            <li>
                                                <span class="preference-label"><?= escape_style(ucwords(str_replace('_', ' ', (string) $preference))) ?>:</span>
                                                <?= escape_style((string) $value) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="style-card-empty">No preferences saved.</p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
