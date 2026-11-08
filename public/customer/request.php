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

/** @return array<int, array<string, mixed>> */
function available_stitching_services(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT s.service_id, s.tailor_id, s.garment_type, s.base_price, s.avg_days, '
        . 'p.shop_name, p.city, p.address '
        . 'FROM tailor_services AS s '
        . 'INNER JOIN tailor_profiles AS p ON p.tailor_id = s.tailor_id '
        . 'INNER JOIN users AS u ON u.user_id = p.user_id AND u.is_active = 1 '
        . 'WHERE s.is_enabled=1 AND s.specialization IS NOT NULL '
        . 'ORDER BY p.shop_name, s.garment_type, s.service_id'
    );

    return $statement->fetchAll();
}

function positive_request_id(mixed $value): int|false
{
    if (!is_string($value) && !is_int($value)) {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
}

$services = available_stitching_services($connection);
$serviceById = [];
$shops = [];
foreach ($services as $serviceRow) {
    $serviceId = (int) $serviceRow['service_id'];
    $tailorId = (int) $serviceRow['tailor_id'];
    $serviceById[$serviceId] = $serviceRow;
    $shops[$tailorId] = [
        'tailor_id' => $tailorId,
        'shop_name' => (string) $serviceRow['shop_name'],
        'city' => (string) $serviceRow['city'],
        'address' => (string) $serviceRow['address'],
    ];
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$errors = [];
$selectedServiceId = null;
$selectedTailorId = null;
$service = null;
$formValues = [
    'profile_id' => '',
    'style_id' => '',
    'fabric_source' => 'tailor',
    'stitching_details' => '',
    'fabric_type' => '',
    'fabric_color' => '',
    'fabric_preferences' => '',
    'customer_notes' => '',
];
$shareMeasurements = false;
$successMessage = ($_GET['created'] ?? '') === '1'
    ? 'Stitching request submitted. It is now pending a quotation.'
    : null;

if ($requestMethod === 'POST') {
    $formValues['profile_id'] = is_string($_POST['profile_id'] ?? null) ? $_POST['profile_id'] : '';
    $formValues['style_id'] = is_string($_POST['style_id'] ?? null) ? $_POST['style_id'] : '';
    $formValues['fabric_source'] = 'tailor';
    foreach (['stitching_details', 'fabric_type', 'fabric_color', 'fabric_preferences'] as $field) {
        $formValues[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $formValues['customer_notes'] = is_string($_POST['customer_notes'] ?? null)
        ? trim($_POST['customer_notes'])
        : '';
    $shareMeasurements = ($_POST['share_measurements'] ?? null) === '1';

    $selectedServiceId = positive_request_id($_POST['service_id'] ?? null);
    $selectedTailorId = positive_request_id($_POST['tailor_id'] ?? null);
    if ($selectedServiceId === false || !isset($serviceById[$selectedServiceId])) {
        $selectedServiceId = null;
        $errors['service_id'] = 'Choose a garment service from the selected shop.';
    } else {
        $service = $serviceById[$selectedServiceId];
        if ($selectedTailorId === false || $selectedTailorId !== (int) $service['tailor_id']) {
            $errors['service_id'] = 'Choose a service that belongs to the selected shop.';
        } else {
            $selectedTailorId = (int) $service['tailor_id'];
        }
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !csrf_token_is_valid($token)) {
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    }

    $validatedProfileId = positive_request_id($formValues['profile_id']);
    if ($validatedProfileId === false) {
        $errors['profile_id'] = 'Choose one of your measurement profiles.';
    } else {
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

    $rawStyleId = $formValues['style_id'];
    $validatedStyleId = $rawStyleId === '' ? null : positive_request_id($rawStyleId);
    if ($validatedStyleId === false) {
        $errors['style_id'] = 'Choose one of your style profiles or leave it empty.';
    } elseif ($validatedStyleId !== null) {
        $styleOwnerCheck = $connection->prepare(
            'SELECT style_id FROM style_profiles '
            . 'WHERE style_id = :style_id AND customer_id = :customer_id'
        );
        $styleOwnerCheck->execute([
            'style_id' => $validatedStyleId,
            'customer_id' => $user['user_id'],
        ]);
        if ($styleOwnerCheck->fetch() === false) {
            http_response_code(404);
            exit('Style profile not found.');
        }
    }

    if (($length = mb_strlen($formValues['stitching_details'], 'UTF-8')) < 10 || $length > 4000) {
        $errors['stitching_details'] = 'Describe your requested garment(s) in 10 to 4,000 characters.';
    }
    if (mb_strlen($formValues['fabric_type'], 'UTF-8') > 80
        || mb_strlen($formValues['fabric_color'], 'UTF-8') > 80
        || mb_strlen($formValues['fabric_preferences'], 'UTF-8') > 2000) {
        $errors['fabric_preferences'] = 'Fabric preferences are too long.';
    }
    $imageUpload = $_FILES['reference_image'] ?? null;
    $imageBytes = null;
    $imageExtension = null;
    if (is_array($imageUpload) && ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($imageUpload['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string)($imageUpload['tmp_name'] ?? ''))
            || (int)($imageUpload['size'] ?? 0) < 1
            || (int)($imageUpload['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors['reference_image'] = 'Choose a valid JPG, PNG or WebP image up to 5 MB.';
        } else {
            $temp = (string)$imageUpload['tmp_name'];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temp);
            $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
            $dimensions = @getimagesize($temp);
            if (!isset($allowed[$mime]) || $dimensions === false
                || $dimensions[0] > 5000 || $dimensions[1] > 5000
                || $dimensions[0] < 1 || $dimensions[1] < 1
                || $dimensions['mime'] !== $mime) {
                $errors['reference_image'] = 'The uploaded file must be a valid JPG, PNG or WebP image (max 5,000 × 5,000 pixels).';
            } else {
                $imageExtension = $allowed[$mime];
            }
        }
    }
    if (mb_strlen($formValues['customer_notes'], 'UTF-8') > 1000) {
        $errors['customer_notes'] = 'Notes must be 1,000 characters or fewer.';
    }

    if ($errors === []) {
        $savedImagePath = null;
        try {
            $connection->beginTransaction();

            if ($shareMeasurements) {
                $lockProfile = $connection->prepare(
                    'SELECT profile_id FROM measurement_profiles '
                    . 'WHERE profile_id = :profile_id AND customer_id = :customer_id FOR UPDATE'
                );
                $lockProfile->execute([
                    'profile_id' => $validatedProfileId,
                    'customer_id' => $user['user_id'],
                ]);
                if ($lockProfile->fetchColumn() === false) {
                    $connection->rollBack();
                    http_response_code(404);
                    exit('Measurement profile not found.');
                }

                $permission = [
                    'profile_id' => $validatedProfileId,
                    'tailor_id' => $service['tailor_id'],
                ];
                $activeCheck = $connection->prepare(
                    'SELECT permission_id FROM measurement_permissions '
                    . 'WHERE profile_id = :profile_id AND tailor_id = :tailor_id '
                    . 'AND revoked_at IS NULL LIMIT 1 FOR UPDATE'
                );
                $activeCheck->execute($permission);
                if ($activeCheck->fetchColumn() === false) {
                    $grant = $connection->prepare(
                        'INSERT INTO measurement_permissions (profile_id, tailor_id) '
                        . 'VALUES (:profile_id, :tailor_id)'
                    );
                    $grant->execute($permission);
                }
            }

            if ($imageExtension !== null) {
                $imageFolder = dirname(__DIR__, 2) . '/storage/request-images';
                if (!is_dir($imageFolder) || !is_writable($imageFolder)) {
                    throw new RuntimeException('Private image storage is not configured.');
                }
                $imageName = bin2hex(random_bytes(20)) . '.' . $imageExtension;
                $savedImagePath = 'request-images/' . $imageName;
                if (!move_uploaded_file((string)$imageUpload['tmp_name'], $imageFolder . '/' . $imageName)) {
                    throw new RuntimeException('Unable to save the reference image.');
                }
                chmod($imageFolder . '/' . $imageName, 0640);
            }
            $insert = $connection->prepare(
                'INSERT INTO order_requests '
                . '(customer_id, tailor_id, service_id, profile_id, style_id, fabric_source, customer_notes, status, '
                . 'stitching_details, fabric_type, fabric_color, fabric_preferences, reference_image_path) '
                . 'VALUES (:customer_id, :tailor_id, :service_id, :profile_id, :style_id, '
                . ':fabric_source, :customer_notes, :status, :stitching_details, :fabric_type, '
                . ':fabric_color, :fabric_preferences, :reference_image_path)'
            );
            $insert->execute([
                'customer_id' => $user['user_id'],
                'tailor_id' => $service['tailor_id'],
                'service_id' => $service['service_id'],
                'profile_id' => $validatedProfileId,
                'style_id' => $validatedStyleId,
                'fabric_source' => $formValues['fabric_source'],
                'customer_notes' => $formValues['customer_notes'] === '' ? null : $formValues['customer_notes'],
                'status' => 'pending',
                'stitching_details' => $formValues['stitching_details'],
                'fabric_type' => $formValues['fabric_type'] === '' ? null : $formValues['fabric_type'],
                'fabric_color' => $formValues['fabric_color'] === '' ? null : $formValues['fabric_color'],
                'fabric_preferences' => $formValues['fabric_preferences'] === '' ? null : $formValues['fabric_preferences'],
                'reference_image_path' => $savedImagePath,
            ]);
            $connection->commit();

            header(
                'Location: /customer/request.php?service_id='
                . (int) $service['service_id'] . '&created=1',
                true,
                303
            );
            exit;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($savedImagePath !== null) @unlink(dirname(__DIR__, 2) . '/storage/' . $savedImagePath);
            error_log($exception->getMessage());
            $errors['form'] = 'The stitching request could not be submitted. Please try again.';
        }
    }
} else {
    $queryServiceId = $_GET['service_id'] ?? null;
    if ($queryServiceId !== null) {
        $selectedServiceId = positive_request_id($queryServiceId);
        if ($selectedServiceId === false || !isset($serviceById[$selectedServiceId])) {
            http_response_code(404);
            exit('Service not found.');
        }
        $service = $serviceById[$selectedServiceId];
        $selectedTailorId = (int) $service['tailor_id'];
    } else {
        $queryTailorId = $_GET['tailor_id'] ?? null;
        if ($queryTailorId !== null) {
            $selectedTailorId = positive_request_id($queryTailorId);
            if ($selectedTailorId === false || !isset($shops[$selectedTailorId])) {
                http_response_code(404);
                exit('Shop not found.');
            }
        }
    }
}

$profileStatement = $connection->prepare(
    'SELECT mp.profile_id, mp.profile_name, mp.gender, latest.version_no, latest.created_at AS version_created_at '
    . 'FROM measurement_profiles AS mp '
    . 'LEFT JOIN (SELECT mv.profile_id, mv.version_no, mv.created_at '
    . 'FROM measurement_versions AS mv INNER JOIN '
    . '(SELECT profile_id, MAX(version_no) AS latest_version FROM measurement_versions GROUP BY profile_id) AS newest '
    . 'ON newest.profile_id = mv.profile_id AND newest.latest_version = mv.version_no) AS latest '
    . 'ON latest.profile_id = mp.profile_id '
    . 'WHERE mp.customer_id = :customer_id ORDER BY mp.profile_name, mp.profile_id'
);
$profileStatement->execute(['customer_id' => $user['user_id']]);
$profiles = $profileStatement->fetchAll();

$styleProfileStatement = $connection->prepare(
    'SELECT style_id, name FROM style_profiles '
    . 'WHERE customer_id = :customer_id ORDER BY name, style_id'
);
$styleProfileStatement->execute(['customer_id' => $user['user_id']]);
$styleProfiles = $styleProfileStatement->fetchAll();

$customerSection = 'requests';
$pageTitle = 'New stitching request';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New stitching request | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/customer.css">
    <link rel="stylesheet" href="/assets/css/request.css">
    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="customer-page request-page">
    <div class="customer-shell">
        <?php require dirname(__DIR__, 2) . '/includes/customer-navigation.php'; ?>
        <main class="customer-main request-main">
            <header class="request-heading">
                <div>
                    <p class="eyebrow">Made for you</p>
                    <h1>New stitching request</h1>
                    <p>Share your requirements with a tailor and receive a quotation.</p>
                </div>
                <a class="request-back" href="/customer/requests.php">← Requests &amp; quotes</a>
            </header>

            <?php if ($successMessage !== null): ?>
                <p class="request-success" role="status"><?= escape_stitching_request($successMessage) ?></p>
            <?php endif; ?>
            <?php if (isset($errors['form'])): ?>
                <p class="request-error-banner" role="alert"><?= escape_stitching_request($errors['form']) ?></p>
            <?php endif; ?>

            <?php if ($services === []): ?>
                <section class="request-empty">
                    <h2>No tailoring services are available right now</h2>
                    <p>Browse the shops again later to see their latest services.</p>
                    <a class="button" href="/customer/tailors.php">Browse tailors</a>
                </section>
            <?php elseif ($profiles === []): ?>
                <section class="request-empty">
                    <h2>Add measurements before requesting a quote</h2>
                    <p>Your tailor needs a measurement profile to prepare an accurate quotation.</p>
                    <a class="button" href="/customer/measurements.php">Create measurement profile</a>
                </section>
            <?php else: ?>
                <div class="request-layout">
                    <section class="request-card request-form-card" aria-labelledby="request-form-title">
                        <div class="request-card-heading">
                            <div class="request-step">01</div>
                            <div>
                                <h2 id="request-form-title">Your stitching details</h2>
                                <p>Choose a shop and service, then add your preferences.</p>
                            </div>
                        </div>

                        <?php if (isset($errors['service_id'])): ?>
                            <p class="request-inline-error" role="alert"><?= escape_stitching_request($errors['service_id']) ?></p>
                        <?php endif; ?>

                        <form method="post" action="/customer/request.php" class="request-form" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= escape_stitching_request(csrf_token()) ?>">
                            <div class="request-field">
                                <label for="tailor_id">Choose tailor</label>
                                <select id="tailor_id" name="tailor_id" required>
                                    <option value="">Choose a shop</option>
                                    <?php foreach ($shops as $shop): ?>
                                        <option value="<?= (int) $shop['tailor_id'] ?>"
                                            data-shop-name="<?= escape_stitching_request($shop['shop_name']) ?>"
                                            data-city="<?= escape_stitching_request($shop['city']) ?>"
                                            data-address="<?= escape_stitching_request($shop['address']) ?>"
                                            <?= $selectedTailorId === (int) $shop['tailor_id'] ? 'selected' : '' ?>>
                                            <?= escape_stitching_request($shop['shop_name']) ?> · <?= escape_stitching_request($shop['city']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="request-field">
                                <label for="service_id">Tailoring specialization</label>
                                <select id="service_id" name="service_id" required>
                                    <option value="">Choose tailoring specialization</option>
                                    <?php foreach ($shops as $shop): ?>
                                        <optgroup label="<?= escape_stitching_request($shop['shop_name']) ?> · <?= escape_stitching_request($shop['city']) ?>" data-tailor-id="<?= (int) $shop['tailor_id'] ?>">
                                            <?php foreach ($services as $serviceOption): ?>
                                                <?php if ((int) $serviceOption['tailor_id'] !== (int) $shop['tailor_id']) { continue; } ?>
                                                <option value="<?= (int) $serviceOption['service_id'] ?>"
                                                    data-tailor-id="<?= (int) $serviceOption['tailor_id'] ?>"
                                                    data-shop-name="<?= escape_stitching_request($serviceOption['shop_name']) ?>"
                                                    data-city="<?= escape_stitching_request($serviceOption['city']) ?>"
                                                    data-address="<?= escape_stitching_request($serviceOption['address']) ?>"
                                                    data-garment="<?= escape_stitching_request($serviceOption['garment_type']) ?>"
                                                    data-price="<?= (float) $serviceOption['base_price'] ?>"
                                                    data-days="<?= (int) $serviceOption['avg_days'] ?>"
                                                    <?= $selectedServiceId === (int) $serviceOption['service_id'] ? 'selected' : '' ?>>
                                                    <?= escape_stitching_request($serviceOption['garment_type']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['service_id'])): ?>
                                    <p class="request-inline-error" role="alert"><?= escape_stitching_request($errors['service_id']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="request-field">
                                <label for="profile_id">Measurement version</label>
                                <select id="profile_id" name="profile_id" required>
                                    <option value="">Choose a profile version</option>
                                    <?php foreach ($profiles as $profile): ?>
                                        <option value="<?= (int) $profile['profile_id'] ?>"<?= $formValues['profile_id'] === (string) $profile['profile_id'] ? ' selected' : '' ?>>
                                            <?= escape_stitching_request($profile['profile_name']) ?> · v<?= (int) ($profile['version_no'] ?? 1) ?><?= $profile['version_created_at'] !== null ? ' (' . escape_stitching_request(date('M j, Y', strtotime((string) $profile['version_created_at']))) . ')' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['profile_id'])): ?>
                                    <p class="request-inline-error" role="alert"><?= escape_stitching_request($errors['profile_id']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="request-field">
                                <label for="style_id">Style profile <span>(optional)</span></label>
                                <select id="style_id" name="style_id">
                                    <option value="">No style profile</option>
                                    <?php foreach ($styleProfiles as $styleProfile): ?>
                                        <option value="<?= (int) $styleProfile['style_id'] ?>"<?= $formValues['style_id'] === (string) $styleProfile['style_id'] ? ' selected' : '' ?>><?= escape_stitching_request($styleProfile['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($styleProfiles === []): ?>
                                    <p class="request-field-hint"><a href="/customer/styles.php">Create a style profile</a> to reuse your preferences.</p>
                                <?php endif; ?>
                                <?php if (isset($errors['style_id'])): ?>
                                    <p class="request-inline-error" role="alert"><?= escape_stitching_request($errors['style_id']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="request-field request-field-wide">
                                <label for="stitching_details">What would you like stitched?</label>
                                <textarea name="stitching_details" id="stitching_details" minlength="10" maxlength="4000" rows="4" required placeholder="Describe the outfit, number of pieces, stitching details, sleeve and neckline choices..."><?= escape_stitching_request($formValues['stitching_details']) ?></textarea>
                                <p class="request-field-hint">You can request a complete suit, one garment, or several pieces. No fixed garment list.</p>
                                <?php if (isset($errors['stitching_details'])): ?><p role="alert" class="request-inline-error"><?= escape_stitching_request($errors['stitching_details']) ?></p><?php endif; ?>
                            </div>
                            <div class="request-field request-field-wide request-fabric-note">
                                <strong>Fabric will be arranged by the tailor.</strong>
                                <p class="request-field-hint">Describe your preferences. The tailor will check availability and include material cost in the quotation.</p>
                            </div>
                            <div class="request-field">
                                <label for="fabric_type">Preferred fabric type (optional)</label>
                                <input type="text" id="fabric_type" name="fabric_type" maxlength="80" value="<?= escape_stitching_request($formValues['fabric_type']) ?>" placeholder="e.g. Lawn, cotton, silk">
                            </div>
                            <div class="request-field">
                                <label for="fabric_color">Preferred colour (optional)</label>
                                <input type="text" id="fabric_color" name="fabric_color" maxlength="80" value="<?= escape_stitching_request($formValues['fabric_color']) ?>" placeholder="e.g. Navy blue">
                            </div>
                            <div class="request-field request-field-wide">
                                <label for="fabric_preferences">Additional fabric preferences (optional)</label>
                                <textarea id="fabric_preferences" name="fabric_preferences" maxlength="2000" rows="2" placeholder="e.g. Lightweight fabric suitable for summer"><?= escape_stitching_request($formValues['fabric_preferences']) ?></textarea>
                                <?php if (isset($errors['fabric_preferences'])): ?><p role="alert" class="request-inline-error"><?= escape_stitching_request($errors['fabric_preferences']) ?></p><?php endif; ?>
                            </div>
                            <div class="request-field request-field-wide">
                                <label for="reference_image">Upload a styling reference (optional)</label>
                                <input type="file" id="reference_image" name="reference_image" accept="image/jpeg,image/png,image/webp">
                                <p class="request-field-hint">JPG, PNG, or WebP, up to 5 MB. Shared privately with the selected tailor.</p>
                                <?php if (isset($errors['reference_image'])): ?><p role="alert" class="request-inline-error"><?= escape_stitching_request($errors['reference_image']) ?></p><?php endif; ?>
                            </div>
                            <div class="request-field request-field-wide">
                                <label for="customer_notes">Notes <span>(optional)</span></label>
                                <textarea id="customer_notes" name="customer_notes" maxlength="1000" rows="3" placeholder="For example: add side pockets, keep a 1-inch margin…"><?= escape_stitching_request($formValues['customer_notes']) ?></textarea>
                                <p class="request-field-hint">Up to 1,000 characters. These notes will be shared with the selected tailor.</p>
                                <?php if (isset($errors['customer_notes'])): ?>
                                    <p class="request-inline-error" role="alert"><?= escape_stitching_request($errors['customer_notes']) ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="request-field request-field-wide request-sharing">
                                <label class="request-sharing-label">
                                    <input type="checkbox" name="share_measurements" value="1"<?= $shareMeasurements ? ' checked' : '' ?>>
                                    <span>Allow this shop to view the selected measurements</span>
                                </label>
                                <p class="request-field-hint">You can revoke access later from <a href="/customer/permissions.php">Measurement sharing permissions</a>.</p>
                            </div>

                            <button class="request-submit" type="submit">Send request <span aria-hidden="true">→</span></button>
                        </form>
                    </section>

                    <aside class="request-summary" aria-labelledby="request-summary-title" aria-live="polite">
                        <img src="/assets/images/fabric-kurtas.jpg" alt="Dark tailoring fabric and measuring tape" width="960" height="640">
                        <div class="request-summary-body">
                            <p class="eyebrow">Your request</p>
                            <h2 id="request-summary-title">Request summary</h2>
                            <dl>
                                <div><dt>Shop</dt><dd id="summary-shop-name"><?= $service !== null ? escape_stitching_request($service['shop_name']) : 'Choose a tailor' ?></dd></div>
                                <div><dt>Location</dt><dd id="summary-shop-location"><?= $service !== null ? escape_stitching_request($service['city']) : '—' ?></dd></div>
                                <div><dt>Address</dt><dd id="summary-shop-address"><?= $service !== null ? escape_stitching_request($service['address']) : '—' ?></dd></div>
                                <div><dt>Service</dt><dd id="summary-garment"><?= $service !== null ? escape_stitching_request($service['garment_type']) : 'Choose a service' ?></dd></div>
                                <div><dt>Pricing</dt><dd id="summary-price">Custom quotation</dd></div>
                                <div><dt>Completion</dt><dd id="summary-days">Specified in quotation</dd></div>
                            </dl>
                            <p class="request-summary-note"><span aria-hidden="true">ⓘ</span> Sending a request does not create an order. You can review the tailor’s quotation before accepting it.</p>
                        </div>
                    </aside>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="/assets/js/request.js" defer></script>
<script src="/assets/js/usability.js" defer></script>
</body>
</html>
