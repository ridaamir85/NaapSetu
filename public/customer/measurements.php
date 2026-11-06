<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_measurement(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** @return array{values: array<string, string>, error: string|null} */
function parse_measurement_lines(string $input): array
{
    $measurements = [];
    $normalizedNames = [];

    foreach (preg_split('/\R/u', $input) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\A(.{1,50}?)\s*=\s*([0-9]{1,3}(?:\.[0-9]{1,2})?)\z/u', $line, $matches) !== 1) {
            return ['values' => [], 'error' => 'Use one measurement per line in the format Chest = 92.'];
        }

        $name = trim($matches[1]);
        $amount = $matches[2];
        if (preg_match('/\A[\p{L}\p{N}][\p{L}\p{N} _-]{0,49}\z/u', $name) !== 1) {
            return ['values' => [], 'error' => 'Measurement names may use letters, numbers, spaces, underscores, and hyphens.'];
        }
        if ((float) $amount <= 0) {
            return ['values' => [], 'error' => 'Measurement values must be greater than 0 cm.'];
        }

        $normalizedName = mb_strtolower($name, 'UTF-8');
        if (isset($normalizedNames[$normalizedName])) {
            return ['values' => [], 'error' => 'Each measurement name must be unique within a version.'];
        }

        $normalizedNames[$normalizedName] = true;
        $measurements[$name] = $amount;
        if (count($measurements) > 30) {
            return ['values' => [], 'error' => 'Add no more than 30 measurements to one version.'];
        }
    }

    if ($measurements === []) {
        return ['values' => [], 'error' => 'Add at least one measurement.'];
    }

    return ['values' => $measurements, 'error' => null];
}

$errors = [];
$formValues = ['profile_name' => '', 'gender' => '', 'measurements' => ''];
$submittedAction = '';
$submittedProfileId = null;
$successMessage = match ($_GET['saved'] ?? '') {
    'profile' => 'Measurement profile created. Your first version is saved.',
    'version' => 'New measurement version saved. Earlier versions are unchanged.',
    default => null,
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $formValues['profile_name'] = is_string($_POST['profile_name'] ?? null) ? trim($_POST['profile_name']) : '';
    $formValues['gender'] = is_string($_POST['gender'] ?? null) ? $_POST['gender'] : '';
    $formValues['measurements'] = is_string($_POST['measurements'] ?? null) ? trim($_POST['measurements']) : '';

    $rawProfileId = $_POST['profile_id'] ?? null;
    if ($submittedAction === 'add_version') {
        $submittedProfileId = is_string($rawProfileId) || is_int($rawProfileId)
            ? filter_var($rawProfileId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 2147483647],
            ])
            : false;
        if ($submittedProfileId === false) {
            http_response_code(404);
            exit('Measurement profile not found.');
        }

        $ownerCheck = $connection->prepare(
            'SELECT profile_id FROM measurement_profiles '
            . 'WHERE profile_id = :profile_id AND customer_id = :customer_id'
        );
        $ownerCheck->execute([
            'profile_id' => $submittedProfileId,
            'customer_id' => $user['user_id'],
        ]);
        if ($ownerCheck->fetch() === false) {
            http_response_code(404);
            exit('Measurement profile not found.');
        }
    } elseif ($submittedAction !== 'create_profile') {
        http_response_code(400);
        exit('Invalid measurement action.');
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if ($submittedAction === 'create_profile') {
        $nameLength = mb_strlen($formValues['profile_name'], 'UTF-8');
        if ($nameLength < 2 || $nameLength > 80) {
            $errors['profile_name'] = 'Profile name must be between 2 and 80 characters.';
        }
        if (!in_array($formValues['gender'], ['male', 'female'], true)) {
            $errors['gender'] = 'Select a profile category.';
        }
    }

    $parsedMeasurements = parse_measurement_lines($formValues['measurements']);
    if ($parsedMeasurements['error'] !== null) {
        $errors['measurements'] = $parsedMeasurements['error'];
    }

    if ($errors === []) {
        try {
            $connection->beginTransaction();

            if ($submittedAction === 'create_profile') {
                $profileInsert = $connection->prepare(
                    'INSERT INTO measurement_profiles (customer_id, profile_name, gender) '
                    . 'VALUES (:customer_id, :profile_name, :gender)'
                );
                $profileInsert->execute([
                    'customer_id' => $user['user_id'],
                    'profile_name' => $formValues['profile_name'],
                    'gender' => $formValues['gender'],
                ]);
                $profileId = (int) $connection->lastInsertId();
                $versionNo = 1;
            } else {
                $lockProfile = $connection->prepare(
                    'SELECT profile_id FROM measurement_profiles '
                    . 'WHERE profile_id = :profile_id AND customer_id = :customer_id FOR UPDATE'
                );
                $lockProfile->execute([
                    'profile_id' => $submittedProfileId,
                    'customer_id' => $user['user_id'],
                ]);
                if ($lockProfile->fetch() === false) {
                    throw new RuntimeException('Measurement profile ownership changed during submission.');
                }

                $profileId = (int) $submittedProfileId;
                $nextVersion = $connection->prepare(
                    'SELECT COALESCE(MAX(version_no), 0) + 1 AS version_no '
                    . 'FROM measurement_versions WHERE profile_id = :profile_id'
                );
                $nextVersion->execute(['profile_id' => $profileId]);
                $versionNo = (int) $nextVersion->fetchColumn();
            }

            $versionInsert = $connection->prepare(
                'INSERT INTO measurement_versions (profile_id, version_no) '
                . 'VALUES (:profile_id, :version_no)'
            );
            $versionInsert->execute(['profile_id' => $profileId, 'version_no' => $versionNo]);
            $versionId = (int) $connection->lastInsertId();

            $valueInsert = $connection->prepare(
                'INSERT INTO measurement_values (version_id, measurement_name, value_cm) '
                . 'VALUES (:version_id, :measurement_name, :value_cm)'
            );
            foreach ($parsedMeasurements['values'] as $measurementName => $valueCm) {
                $valueInsert->execute([
                    'version_id' => $versionId,
                    'measurement_name' => $measurementName,
                    'value_cm' => $valueCm,
                ]);
            }

            $connection->commit();
            header(
                'Location: /customer/measurements.php?saved='
                . ($submittedAction === 'create_profile' ? 'profile' : 'version'),
                true,
                303
            );
            exit;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log($exception->getMessage());
            $errors['form'] = 'Measurements could not be saved. Please try again.';
        }
    }
}

$profileStatement = $connection->prepare(
    'SELECT profile_id, profile_name, gender '
    . 'FROM measurement_profiles WHERE customer_id = :customer_id '
    . 'ORDER BY profile_id DESC'
);
$profileStatement->execute(['customer_id' => $user['user_id']]);
$profiles = $profileStatement->fetchAll();

$versionsByProfile = [];
if ($profiles !== []) {
    $versionStatement = $connection->prepare(
        'SELECT v.version_id, v.profile_id, v.version_no, v.created_at, '
        . 'mv.measurement_name, mv.value_cm '
        . 'FROM measurement_versions AS v '
        . 'LEFT JOIN measurement_values AS mv ON mv.version_id = v.version_id '
        . 'WHERE v.profile_id = :profile_id ORDER BY v.version_no DESC, mv.measurement_name'
    );
    foreach ($profiles as $profile) {
        $versionStatement->execute(['profile_id' => $profile['profile_id']]);
        foreach ($versionStatement->fetchAll() as $row) {
            $versionId = (int) $row['version_id'];
            if (!isset($versionsByProfile[$profile['profile_id']][$versionId])) {
                $versionsByProfile[$profile['profile_id']][$versionId] = [
                    'version_no' => (int) $row['version_no'],
                    'created_at' => (string) $row['created_at'],
                    'values' => [],
                ];
            }
            if ($row['measurement_name'] !== null) {
                $versionsByProfile[$profile['profile_id']][$versionId]['values'][] = [
                    'name' => (string) $row['measurement_name'],
                    'value_cm' => (string) $row['value_cm'],
                ];
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Measurement profiles | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>Measurement Passport</h1>
        <p>Create profiles and keep each measurement update as a separate version. Measurements are in centimetres.</p>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_measurement($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_measurement($errors['form']) ?></p>
        <?php endif; ?>

        <section>
            <h2>Create a measurement profile</h2>
            <form method="post" action="/customer/measurements.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape_measurement(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_profile">
                <div>
                    <label for="profile_name">Profile name</label>
                    <input id="profile_name" name="profile_name" type="text" maxlength="80" required value="<?= escape_measurement($formValues['profile_name']) ?>">
                    <?php if (isset($errors['profile_name'])): ?>
                        <p role="alert"><?= escape_measurement($errors['profile_name']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender" required>
                        <option value="">Choose one</option>
                        <option value="female"<?= $formValues['gender'] === 'female' ? ' selected' : '' ?>>Female</option>
                        <option value="male"<?= $formValues['gender'] === 'male' ? ' selected' : '' ?>>Male</option>
                    </select>
                    <?php if (isset($errors['gender'])): ?>
                        <p role="alert"><?= escape_measurement($errors['gender']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label for="new_measurements">Measurements (cm)</label>
                    <textarea id="new_measurements" name="measurements" rows="8" required placeholder="Chest = 92&#10;Waist = 74&#10;Sleeve = 58"><?= escape_measurement($submittedAction === 'create_profile' ? $formValues['measurements'] : '') ?></textarea>
                    <small>Enter one measurement per line as Name = value. Use up to 30 measurements.</small>
                    <?php if ($submittedAction === 'create_profile' && isset($errors['measurements'])): ?>
                        <p role="alert"><?= escape_measurement($errors['measurements']) ?></p>
                    <?php endif; ?>
                </div>
                <button type="submit">Create profile and save version 1</button>
            </form>
        </section>

        <h2>Your profiles</h2>
        <?php if ($profiles === []): ?>
            <p>You have no measurement profiles yet.</p>
        <?php else: ?>
            <?php foreach ($profiles as $profile): ?>
                <section>
                    <h3><?= escape_measurement($profile['profile_name']) ?></h3>
                    <p>Category: <?= escape_measurement(ucfirst($profile['gender'])) ?></p>

                    <?php foreach ($versionsByProfile[$profile['profile_id']] ?? [] as $version): ?>
                        <article>
                            <h4>Version <?= (int) $version['version_no'] ?></h4>
                            <p><time datetime="<?= escape_measurement($version['created_at']) ?>"><?= escape_measurement($version['created_at']) ?></time></p>
                            <ul>
                                <?php foreach ($version['values'] as $measurement): ?>
                                    <li><?= escape_measurement($measurement['name']) ?>: <?= escape_measurement($measurement['value_cm']) ?> cm</li>
                                <?php endforeach; ?>
                            </ul>
                        </article>
                    <?php endforeach; ?>

                    <h4>Add a new version</h4>
                    <form method="post" action="/customer/measurements.php" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= escape_measurement(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_version">
                        <input type="hidden" name="profile_id" value="<?= (int) $profile['profile_id'] ?>">
                        <div>
                            <label for="version_measurements_<?= (int) $profile['profile_id'] ?>">Updated measurements (cm)</label>
                            <textarea id="version_measurements_<?= (int) $profile['profile_id'] ?>" name="measurements" rows="6" required placeholder="Chest = 93&#10;Waist = 75"><?= escape_measurement($submittedAction === 'add_version' && $submittedProfileId === (int) $profile['profile_id'] ? $formValues['measurements'] : '') ?></textarea>
                            <small>Enter a complete new set. This creates a new version and preserves the earlier one.</small>
                            <?php if ($submittedAction === 'add_version' && $submittedProfileId === (int) $profile['profile_id'] && isset($errors['measurements'])): ?>
                                <p role="alert"><?= escape_measurement($errors['measurements']) ?></p>
                            <?php endif; ?>
                        </div>
                        <button type="submit">Save new version</button>
                    </form>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
