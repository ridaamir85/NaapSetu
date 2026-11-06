<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/database.php';

const DEMO_ADMIN_EMAIL = 'demo.admin@naapsetu.test';
const DEMO_CUSTOMER_EMAIL = 'demo.customer@naapsetu.test';
const DEMO_TAILOR_EMAIL = 'demo.tailor@naapsetu.test';

function seed_demo_user(PDO $connection, string $name, string $email, string $role, string $password): int
{
    $statement = $connection->prepare(
        'INSERT INTO users (name, email, phone, password_hash, role, is_active) '
        . 'VALUES (:name, :email, :phone, :password_hash, :role, 1) '
        . 'ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone), '
        . 'password_hash = VALUES(password_hash), role = VALUES(role), is_active = 1'
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'phone' => null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);

    $lookup = $connection->prepare('SELECT user_id FROM users WHERE email = :email FOR UPDATE');
    $lookup->execute(['email' => $email]);
    $userId = $lookup->fetchColumn();
    if ($userId === false) {
        throw new RuntimeException('Unable to find a seeded demo account.');
    }

    return (int) $userId;
}

function seed_demo_measurements(PDO $connection, int $profileId): void
{
    $versionLookup = $connection->prepare(
        'SELECT version_id FROM measurement_versions '
        . 'WHERE profile_id = :profile_id AND version_no = 1 FOR UPDATE'
    );
    $versionLookup->execute(['profile_id' => $profileId]);
    $versionId = $versionLookup->fetchColumn();

    if ($versionId === false) {
        $versionInsert = $connection->prepare(
            'INSERT INTO measurement_versions (profile_id, version_no) VALUES (:profile_id, 1)'
        );
        $versionInsert->execute(['profile_id' => $profileId]);
        $versionId = (int) $connection->lastInsertId();
    }

    $valueInsert = $connection->prepare(
        'INSERT INTO measurement_values (version_id, measurement_name, value_cm) '
        . 'VALUES (:version_id, :measurement_name, :value_cm) '
        . 'ON DUPLICATE KEY UPDATE value_cm = VALUES(value_cm)'
    );
    foreach (['Chest' => '102.00', 'Waist' => '88.00', 'Sleeve Length' => '62.00'] as $name => $value) {
        $valueInsert->execute([
            'version_id' => $versionId,
            'measurement_name' => $name,
            'value_cm' => $value,
        ]);
    }
}

function seed_demo_profile(PDO $connection, string $table, string $ownerColumn, int $ownerId, string $nameColumn, string $name): int
{
    $lookupSql = sprintf(
        'SELECT %1$s FROM %2$s WHERE %3$s = :owner_id AND %4$s = :name ORDER BY %1$s LIMIT 1 FOR UPDATE',
        $table === 'measurement_profiles' ? 'profile_id' : 'style_id',
        $table,
        $ownerColumn,
        $nameColumn
    );
    $lookup = $connection->prepare($lookupSql);
    $lookup->execute(['owner_id' => $ownerId, 'name' => $name]);
    $profileId = $lookup->fetchColumn();

    if ($profileId !== false) {
        if ($table === 'measurement_profiles') {
            $refresh = $connection->prepare(
                'UPDATE measurement_profiles SET gender = :gender '
                . 'WHERE profile_id = :profile_id AND customer_id = :owner_id'
            );
            $refresh->execute(['gender' => 'male', 'profile_id' => $profileId, 'owner_id' => $ownerId]);
        } else {
            $refresh = $connection->prepare(
                'UPDATE style_profiles SET preferences = :preferences '
                . 'WHERE style_id = :profile_id AND customer_id = :owner_id'
            );
            $refresh->execute([
                'preferences' => json_encode([
                    'neckline' => 'Collar',
                    'sleeve_length' => 'Full',
                    'fit' => 'Regular',
                    'embroidery' => 'Simple cuffs',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'profile_id' => $profileId,
                'owner_id' => $ownerId,
            ]);
        }

        return (int) $profileId;
    }

    if ($table === 'measurement_profiles') {
        $insert = $connection->prepare(
            'INSERT INTO measurement_profiles (customer_id, profile_name, gender) '
            . 'VALUES (:owner_id, :name, :gender)'
        );
        $insert->execute(['owner_id' => $ownerId, 'name' => $name, 'gender' => 'male']);
    } else {
        $insert = $connection->prepare(
            'INSERT INTO style_profiles (customer_id, name, preferences) '
            . 'VALUES (:owner_id, :name, :preferences)'
        );
        $insert->execute([
            'owner_id' => $ownerId,
            'name' => $name,
            'preferences' => json_encode([
                'neckline' => 'Collar',
                'sleeve_length' => 'Full',
                'fit' => 'Regular',
                'embroidery' => 'Simple cuffs',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    return (int) $connection->lastInsertId();
}

try {
    $connection = database();
    $connection->beginTransaction();

    seed_demo_user($connection, 'NaapSetu Demo Admin', DEMO_ADMIN_EMAIL, 'admin', 'DemoAdmin!2026');
    $customerId = seed_demo_user($connection, 'Ayesha Demo Customer', DEMO_CUSTOMER_EMAIL, 'customer', 'DemoCustomer!2026');
    $tailorUserId = seed_demo_user($connection, 'Usman Demo Tailor', DEMO_TAILOR_EMAIL, 'tailor', 'DemoTailor!2026');

    $tailorProfileLookup = $connection->prepare(
        'SELECT tailor_id FROM tailor_profiles WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
    );
    $tailorProfileLookup->execute(['user_id' => $tailorUserId]);
    $tailorId = $tailorProfileLookup->fetchColumn();
    if ($tailorId === false) {
        $tailorProfileInsert = $connection->prepare(
            'INSERT INTO tailor_profiles (user_id, shop_name, city, address, max_active_orders) '
            . 'VALUES (:user_id, :shop_name, :city, :address, :max_active_orders)'
        );
        $tailorProfileInsert->execute([
            'user_id' => $tailorUserId,
            'shop_name' => 'NaapSetu Demo Tailoring',
            'city' => 'Lahore',
            'address' => 'Demo Market, Gulberg III',
            'max_active_orders' => 3,
        ]);
        $tailorId = (int) $connection->lastInsertId();
    } else {
        $tailorProfileUpdate = $connection->prepare(
            'UPDATE tailor_profiles SET shop_name = :shop_name, city = :city, '
            . 'address = :address, max_active_orders = :max_active_orders '
            . 'WHERE tailor_id = :tailor_id AND user_id = :user_id'
        );
        $tailorProfileUpdate->execute([
            'shop_name' => 'NaapSetu Demo Tailoring',
            'city' => 'Lahore',
            'address' => 'Demo Market, Gulberg III',
            'max_active_orders' => 3,
            'tailor_id' => $tailorId,
            'user_id' => $tailorUserId,
        ]);
    }
    $tailorId = (int) $tailorId;

    $serviceLookup = $connection->prepare(
        'SELECT service_id FROM tailor_services '
        . 'WHERE tailor_id = :tailor_id AND garment_type = :garment_type '
        . 'ORDER BY service_id LIMIT 1 FOR UPDATE'
    );
    $serviceLookup->execute(['tailor_id' => $tailorId, 'garment_type' => 'Men’s Kurta']);
    $serviceId = $serviceLookup->fetchColumn();
    if ($serviceId === false) {
        $serviceInsert = $connection->prepare(
            'INSERT INTO tailor_services (tailor_id, garment_type, base_price, avg_days) '
            . 'VALUES (:tailor_id, :garment_type, :base_price, :avg_days)'
        );
        $serviceInsert->execute([
            'tailor_id' => $tailorId,
            'garment_type' => 'Men’s Kurta',
            'base_price' => '3500.00',
            'avg_days' => 7,
        ]);
        $serviceId = (int) $connection->lastInsertId();
    } else {
        $serviceUpdate = $connection->prepare(
            'UPDATE tailor_services SET base_price = :base_price, avg_days = :avg_days '
            . 'WHERE service_id = :service_id AND tailor_id = :tailor_id'
        );
        $serviceUpdate->execute([
            'base_price' => '3500.00',
            'avg_days' => 7,
            'service_id' => $serviceId,
            'tailor_id' => $tailorId,
        ]);
    }
    $serviceId = (int) $serviceId;

    $measurementProfileId = seed_demo_profile(
        $connection,
        'measurement_profiles',
        'customer_id',
        $customerId,
        'profile_name',
        'Demo Regular Fit'
    );
    seed_demo_measurements($connection, $measurementProfileId);

    $styleProfileId = seed_demo_profile(
        $connection,
        'style_profiles',
        'customer_id',
        $customerId,
        'name',
        'Demo Classic Kurta'
    );

    $permissionLookup = $connection->prepare(
        'SELECT permission_id FROM measurement_permissions '
        . 'WHERE profile_id = :profile_id AND tailor_id = :tailor_id '
        . 'ORDER BY permission_id DESC LIMIT 1 FOR UPDATE'
    );
    $permissionLookup->execute(['profile_id' => $measurementProfileId, 'tailor_id' => $tailorId]);
    $permissionId = $permissionLookup->fetchColumn();
    if ($permissionId === false) {
        $permissionInsert = $connection->prepare(
            'INSERT INTO measurement_permissions (profile_id, tailor_id) '
            . 'VALUES (:profile_id, :tailor_id)'
        );
        $permissionInsert->execute(['profile_id' => $measurementProfileId, 'tailor_id' => $tailorId]);
    } else {
        $permissionUpdate = $connection->prepare(
            'UPDATE measurement_permissions SET revoked_at = NULL '
            . 'WHERE permission_id = :permission_id'
        );
        $permissionUpdate->execute(['permission_id' => $permissionId]);
    }

    $requestLookup = $connection->prepare(
        'SELECT request_id, status FROM order_requests '
        . 'WHERE customer_id = :customer_id AND tailor_id = :tailor_id '
        . 'AND service_id = :service_id AND profile_id = :profile_id AND style_id = :style_id '
        . 'ORDER BY request_id LIMIT 1 FOR UPDATE'
    );
    $requestLookup->execute([
        'customer_id' => $customerId,
        'tailor_id' => $tailorId,
        'service_id' => $serviceId,
        'profile_id' => $measurementProfileId,
        'style_id' => $styleProfileId,
    ]);
    $request = $requestLookup->fetch();
    if ($request === false) {
        $requestInsert = $connection->prepare(
            'INSERT INTO order_requests '
            . '(customer_id, tailor_id, service_id, profile_id, style_id, status) '
            . 'VALUES (:customer_id, :tailor_id, :service_id, :profile_id, :style_id, :status)'
        );
        $requestInsert->execute([
            'customer_id' => $customerId,
            'tailor_id' => $tailorId,
            'service_id' => $serviceId,
            'profile_id' => $measurementProfileId,
            'style_id' => $styleProfileId,
            'status' => 'pending',
        ]);
        $request = ['request_id' => (int) $connection->lastInsertId(), 'status' => 'pending'];
    }
    $requestId = (int) $request['request_id'];

    $quotationLookup = $connection->prepare(
        'SELECT quotation_id FROM quotations WHERE request_id = :request_id '
        . 'ORDER BY quotation_id LIMIT 1 FOR UPDATE'
    );
    $quotationLookup->execute(['request_id' => $requestId]);
    if ($quotationLookup->fetchColumn() === false) {
        $quotationInsert = $connection->prepare(
            'INSERT INTO quotations (request_id, amount, deadline, status) '
            . 'VALUES (:request_id, :amount, :deadline, :status)'
        );
        $quotationInsert->execute([
            'request_id' => $requestId,
            'amount' => '3500.00',
            'deadline' => (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d'),
            'status' => 'sent',
        ]);
        $requestUpdate = $connection->prepare(
            'UPDATE order_requests SET status = :quoted WHERE request_id = :request_id'
        );
        $requestUpdate->execute([
            'quoted' => 'quoted',
            'request_id' => $requestId,
        ]);
    }

    $connection->commit();

    fwrite(STDOUT, "NaapSetu demo data is ready.\n");
    fwrite(STDOUT, 'Admin: ' . DEMO_ADMIN_EMAIL . "\n");
    fwrite(STDOUT, 'Customer: ' . DEMO_CUSTOMER_EMAIL . "\n");
    fwrite(STDOUT, 'Tailor: ' . DEMO_TAILOR_EMAIL . "\n");
    fwrite(STDOUT, "The seeded quotation is ready for customer acceptance; no order is created until it is accepted.\n");
} catch (Throwable $exception) {
    if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    fwrite(STDERR, 'Demo seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
