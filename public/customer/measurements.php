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

require_once dirname(__DIR__, 2) . '/includes/measurement-input.php';

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
    $rowInput = measurement_rows_to_lines($_POST['measurement_names'] ?? null, $_POST['measurement_values'] ?? null, $_POST['unit'] ?? 'cm');
    $formValues['measurements'] = $rowInput['lines'];
    if ($rowInput['error'] !== null) {
        $errors['measurements'] = $rowInput['error'];
    }

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
    if (!isset($errors['measurements']) && $parsedMeasurements['error'] !== null) {
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
                . ($submittedAction === 'create_profile' ? 'profile' : 'version') . '&profile=' . $profileId,
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

// Resolve selections only from this customer's already-authorized profile list.
$selectedProfile = null;
$wantedProfile = $submittedProfileId ?: ($_GET['profile'] ?? '');
if (!is_scalar($wantedProfile)) $wantedProfile = '';
foreach ($profiles as $profile) {
    if ((string) $profile['profile_id'] === (string) $wantedProfile) $selectedProfile = $profile;
}
if ($selectedProfile === null && $profiles !== []) $selectedProfile = $profiles[0];
$isCreating = isset($_GET['new']) || $profiles === [] || ($_SERVER['REQUEST_METHOD'] === 'POST' && $submittedAction === 'create_profile');
$selectedVersions = $selectedProfile ? ($versionsByProfile[$selectedProfile['profile_id']] ?? []) : [];
$selectedVersionId = array_key_first($selectedVersions);
if (is_string($_GET['version'] ?? null) && isset($selectedVersions[$_GET['version']])) $selectedVersionId = (int) $_GET['version'];
$selectedVersion = $selectedVersionId !== null ? $selectedVersions[$selectedVersionId] : null;
$inputRows = $selectedVersion['values'] ?? [['name'=>'Chest','value_cm'=>''],['name'=>'Waist','value_cm'=>''],['name'=>'Sleeve','value_cm'=>'']];
if ($isCreating) $inputRows = [['name'=>'Chest','value_cm'=>''],['name'=>'Waist','value_cm'=>''],['name'=>'Sleeve','value_cm'=>'']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRows = [];
    $names = is_array($_POST['measurement_names'] ?? null) ? array_values($_POST['measurement_names']) : [];
    $amounts = is_array($_POST['measurement_values'] ?? null) ? array_values($_POST['measurement_values']) : [];
    foreach (array_slice($names, 0, 30) as $index=>$name) {
        $inputRows[] = ['name'=>is_string($name) ? $name : '', 'value_cm'=>is_string($amounts[$index] ?? null) ? $amounts[$index] : ''];
    }
}
$displayUnit = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['unit'] ?? '') === 'inch' ? 'inch' : 'cm';
$permissions = [];
if ($selectedProfile && !$isCreating) {
    $sharing = $connection->prepare('SELECT DISTINCT tp.shop_name FROM measurement_permissions mp JOIN tailor_profiles tp ON tp.tailor_id=mp.tailor_id WHERE mp.profile_id=? AND mp.revoked_at IS NULL');
    $sharing->execute([$selectedProfile['profile_id']]);
    $permissions = $sharing->fetchAll();
}
require dirname(__DIR__, 2) . '/includes/measurement-passport-view.php';
