<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_stitching_request(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function service_for_stitching_request(PDO $connection, int $serviceId): array|false
{
    $statement = $connection->prepare(
        'SELECT s.service_id, s.garment_type, s.base_price, s.avg_days, '
        . 'p.tailor_id, p.shop_name, p.city '
        . 'FROM tailor_services AS s '
        . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = s.tailor_id '
        . 'WHERE s.service_id = :service_id'
    );
    $statement->execute(['service_id' => $serviceId]);

    return $statement->fetch();
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$serviceIdInput = $requestMethod === 'POST'
    ? ($_POST['service_id'] ?? null)
    : ($_GET['service_id'] ?? null);
$serviceId = is_string($serviceIdInput) || is_int($serviceIdInput)
    ? filter_var($serviceIdInput, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ])
    : false;

if ($serviceId === false) {
    http_response_code(404);
    exit('Service not found.');
}

$service = service_for_stitching_request($connection, $serviceId);
if ($service === false) {
    http_response_code(404);
    exit('Service not found.');
}

$profileStatement = $connection->prepare(
    'SELECT profile_id, profile_name, gender FROM measurement_profiles '
    . 'WHERE customer_id = :customer_id ORDER BY profile_name, profile_id'
);
$profileStatement->execute(['customer_id' => $user['user_id']]);
$profiles = $profileStatement->fetchAll();

$errors = [];
$selectedProfileId = '';
$successMessage = ($_GET['created'] ?? '') === '1'
    ? 'Stitching request submitted. It is now pending a quotation.'
    : null;

if ($requestMethod === 'POST') {
    $rawProfileId = $_POST['profile_id'] ?? null;
    $validatedProfileId = is_string($rawProfileId) || is_int($rawProfileId)
        ? filter_var($rawProfileId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ])
        : false;

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    if ($validatedProfileId === false) {
        $errors['profile_id'] = 'Choose one of your measurement profiles.';
    } else {
        $selectedProfileId = (string) $validatedProfileId;
        $ownerCheck = $connection->prepare(
            'SELECT profile_id FROM measurement_profiles '
            . 'WHERE profile_id = :profile_id AND customer_id = :customer_id'
        );
        $ownerCheck->execute([
            'profile_id' => $validatedProfileId,
            'customer_id' => $user['user_id'],
        ]);
        if ($ownerCheck->fetch() === false) {
            http_response_code(404);
            exit('Measurement profile not found.');
        }
    }

    if ($errors === []) {
        try {
            $insert = $connection->prepare(
                'INSERT INTO order_requests '
                . '(customer_id, tailor_id, service_id, profile_id, status) '
                . 'VALUES (:customer_id, :tailor_id, :service_id, :profile_id, :status)'
            );
            $insert->execute([
                'customer_id' => $user['user_id'],
                'tailor_id' => $service['tailor_id'],
                'service_id' => $service['service_id'],
                'profile_id' => $validatedProfileId,
                'status' => 'pending',
            ]);

            header(
                'Location: /customer/request.php?service_id='
                . (int) $service['service_id'] . '&created=1',
                true,
                303
            );
            exit;
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $errors['form'] = 'The stitching request could not be submitted. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request stitching | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/tailors.php">Back to browse tailors</a>
        <h1>Request stitching</h1>

        <?php if ($successMessage !== null): ?>
            <p role="status"><?= escape_stitching_request($successMessage) ?></p>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <p role="alert"><?= escape_stitching_request($errors['form']) ?></p>
        <?php endif; ?>

        <section>
            <h2><?= escape_stitching_request($service['shop_name']) ?></h2>
            <p><?= escape_stitching_request($service['city']) ?></p>
            <p>Service: <?= escape_stitching_request($service['garment_type']) ?></p>
            <p>
                <?php if ((float) $service['base_price'] === 0.0): ?>
                    Request a quote
                <?php else: ?>
                    From PKR <?= escape_stitching_request(number_format((float) $service['base_price'], 2)) ?>
                <?php endif; ?>
            </p>
            <p>Estimated completion: <?= escape_stitching_request((string) $service['avg_days']) ?> days</p>
        </section>

        <?php if ($profiles === []): ?>
            <p>Create a measurement profile before requesting this service.</p>
            <p><a href="/customer/measurements.php">Create measurement profile</a></p>
        <?php else: ?>
            <p>Choose the measurement profile to associate with this request. The request will be sent to the selected shop for a quotation.</p>
            <form method="post" action="/customer/request.php?service_id=<?= (int) $service['service_id'] ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= escape_stitching_request(csrf_token()) ?>">
                <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                <div>
                    <label for="profile_id">Measurement profile</label>
                    <select id="profile_id" name="profile_id" required>
                        <option value="">Choose a profile</option>
                        <?php foreach ($profiles as $profile): ?>
                            <option value="<?= (int) $profile['profile_id'] ?>"<?= $selectedProfileId === (string) $profile['profile_id'] ? ' selected' : '' ?>>
                                <?= escape_stitching_request($profile['profile_name']) ?> (<?= escape_stitching_request(ucfirst($profile['gender'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['profile_id'])): ?>
                        <p role="alert"><?= escape_stitching_request($errors['profile_id']) ?></p>
                    <?php endif; ?>
                </div>
                <p>The selected profile is linked to the request. This does not create an order or share measurement values with the tailor.</p>
                <button type="submit">Submit stitching request</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
