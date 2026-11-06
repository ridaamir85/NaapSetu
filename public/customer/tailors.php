<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

require_role('customer');
$connection = database();
$cities = [
    'Faisalabad', 'Gujranwala', 'Hyderabad', 'Islamabad', 'Karachi',
    'Lahore', 'Multan', 'Peshawar', 'Quetta', 'Rawalpindi', 'Sialkot',
];

$requestedCity = $_GET['city'] ?? '';
$selectedCity = is_string($requestedCity) && in_array($requestedCity, $cities, true)
    ? $requestedCity
    : '';

if ($selectedCity === '') {
    $statement = $connection->query(
        'SELECT p.tailor_id, p.shop_name, p.city, p.address, '
        . 's.service_id, s.garment_type, s.base_price, s.avg_days '
        . 'FROM tailor_profiles AS p '
        . 'INNER JOIN tailor_services AS s ON s.tailor_id = p.tailor_id '
        . 'ORDER BY p.shop_name, s.garment_type, s.service_id'
    );
} else {
    $statement = $connection->prepare(
        'SELECT p.tailor_id, p.shop_name, p.city, p.address, '
        . 's.service_id, s.garment_type, s.base_price, s.avg_days '
        . 'FROM tailor_profiles AS p '
        . 'INNER JOIN tailor_services AS s ON s.tailor_id = p.tailor_id '
        . 'WHERE LOWER(TRIM(p.city)) = LOWER(:city) '
        . 'ORDER BY p.shop_name, s.garment_type, s.service_id'
    );
    $statement->execute(['city' => $selectedCity]);
}

$shops = [];
foreach ($statement->fetchAll() as $row) {
    $tailorId = (int) $row['tailor_id'];
    if (!isset($shops[$tailorId])) {
        $shops[$tailorId] = [
            'shop_name' => (string) $row['shop_name'],
            'city' => (string) $row['city'],
            'address' => (string) $row['address'],
            'services' => [],
        ];
    }
    $shops[$tailorId]['services'][] = $row;
}

function escape_tailor_listing(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browse tailors | NaapSetu</title>
</head>
<body>
    <main>
        <a href="/customer/dashboard.php">Back to dashboard</a>
        <h1>Browse tailors</h1>

        <form method="get" action="/customer/tailors.php">
            <label for="city">Filter by city</label>
            <select id="city" name="city">
                <option value=""<?= $selectedCity === '' ? ' selected' : '' ?>>All cities</option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= escape_tailor_listing($city) ?>"<?= $selectedCity === $city ? ' selected' : '' ?>>
                        <?= escape_tailor_listing($city) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Apply filter</button>
        </form>

        <?php if ($shops === []): ?>
            <p>No shops match this city yet.</p>
        <?php else: ?>
            <?php foreach ($shops as $shop): ?>
                <section>
                    <h2><?= escape_tailor_listing($shop['shop_name']) ?></h2>
                    <p><?= escape_tailor_listing($shop['city']) ?></p>
                    <p><?= escape_tailor_listing($shop['address']) ?></p>
                    <h3>Garment services</h3>
                    <ul>
                        <?php foreach ($shop['services'] as $service): ?>
                            <li>
                                <strong><?= escape_tailor_listing((string) $service['garment_type']) ?></strong>:
                                <?php if ((float) $service['base_price'] === 0.0): ?>
                                    Request a quote
                                <?php else: ?>
                                    From PKR <?= escape_tailor_listing(number_format((float) $service['base_price'], 2)) ?>
                                <?php endif; ?>
                                — estimated completion <?= escape_tailor_listing((string) $service['avg_days']) ?>
                                <?= (int) $service['avg_days'] === 1 ? 'day' : 'days' ?>
                                <a href="/customer/request.php?service_id=<?= (int) $service['service_id'] ?>">Request stitching</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
