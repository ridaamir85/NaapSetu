<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

$user = require_role('customer');
$connection = database();

function escape_tailor_listing(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function tailor_initials(string $shopName): string
{
    $words = preg_split('/\s+/u', trim($shopName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return $initials !== '' ? $initials : 'NS';
}

$statement = $connection->query(
    'SELECT p.tailor_id, p.shop_name, p.city, p.address, '
    . 's.service_id, s.garment_type, s.base_price, s.avg_days '
    . 'FROM tailor_profiles AS p '
    . 'INNER JOIN users AS u ON u.user_id = p.user_id AND u.is_active = 1 '
    . 'INNER JOIN tailor_services AS s ON s.tailor_id = p.tailor_id '
    . 'ORDER BY p.shop_name, s.garment_type, s.service_id'
);

$shops = [];
$allCities = [];
$allServices = [];
foreach ($statement->fetchAll() as $row) {
    $tailorId = (int) $row['tailor_id'];
    if (!isset($shops[$tailorId])) {
        $shops[$tailorId] = [
            'tailor_id' => $tailorId,
            'shop_name' => (string) $row['shop_name'],
            'city' => (string) $row['city'],
            'address' => (string) $row['address'],
            'services' => [],
        ];
    }
    $shops[$tailorId]['services'][] = [
        'service_id' => (int) $row['service_id'],
        'garment_type' => (string) $row['garment_type'],
        'base_price' => (float) $row['base_price'],
        'avg_days' => (int) $row['avg_days'],
    ];
    $allCities[(string) $row['city']] = true;
    $allServices[(string) $row['garment_type']] = true;
}

$cities = array_keys($allCities);
$serviceTypes = array_keys($allServices);
natcasesort($cities);
natcasesort($serviceTypes);
$cities = array_values($cities);
$serviceTypes = array_values($serviceTypes);

$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$selectedCity = is_string($_GET['city'] ?? null) && in_array($_GET['city'], $cities, true)
    ? $_GET['city']
    : '';
$selectedService = is_string($_GET['service'] ?? null) && in_array($_GET['service'], $serviceTypes, true)
    ? $_GET['service']
    : '';
$sortOptions = ['relevance', 'name', 'price', 'speed'];
$sort = is_string($_GET['sort'] ?? null) && in_array($_GET['sort'], $sortOptions, true)
    ? $_GET['sort']
    : 'relevance';

$filteredShops = array_filter($shops, static function (array $shop) use ($query, $selectedCity, $selectedService): bool {
    if ($selectedCity !== '' && mb_strtolower($shop['city'], 'UTF-8') !== mb_strtolower($selectedCity, 'UTF-8')) {
        return false;
    }

    $matchingServices = array_filter(
        $shop['services'],
        static fn (array $service): bool => $selectedService === ''
            || mb_strtolower($service['garment_type'], 'UTF-8') === mb_strtolower($selectedService, 'UTF-8')
    );
    if ($matchingServices === []) {
        return false;
    }

    if ($query === '') {
        return true;
    }

    $searchable = $shop['shop_name'] . ' ' . $shop['city'] . ' ' . $shop['address'] . ' '
        . implode(' ', array_column($matchingServices, 'garment_type'));

    return mb_stripos($searchable, $query, 0, 'UTF-8') !== false;
});

if ($sort === 'name') {
    uasort($filteredShops, static fn (array $a, array $b): int => strnatcasecmp($a['shop_name'], $b['shop_name']));
} elseif ($sort === 'price') {
    uasort($filteredShops, static function (array $a, array $b): int {
        $pricesA = array_filter(array_column($a['services'], 'base_price'), static fn (float $price): bool => $price > 0);
        $pricesB = array_filter(array_column($b['services'], 'base_price'), static fn (float $price): bool => $price > 0);
        $minA = $pricesA === [] ? PHP_FLOAT_MAX : min($pricesA);
        $minB = $pricesB === [] ? PHP_FLOAT_MAX : min($pricesB);
        return ($minA <=> $minB) ?: strnatcasecmp($a['shop_name'], $b['shop_name']);
    });
} elseif ($sort === 'speed') {
    uasort($filteredShops, static function (array $a, array $b): int {
        $minA = min(array_column($a['services'], 'avg_days'));
        $minB = min(array_column($b['services'], 'avg_days'));
        return ($minA <=> $minB) ?: strnatcasecmp($a['shop_name'], $b['shop_name']);
    });
} elseif ($query !== '') {
    uasort($filteredShops, static function (array $a, array $b) use ($query): int {
        $score = static function (array $shop) use ($query): int {
            if (mb_stripos($shop['shop_name'], $query, 0, 'UTF-8') !== false) {
                return 0;
            }
            foreach ($shop['services'] as $service) {
                if (mb_stripos($service['garment_type'], $query, 0, 'UTF-8') !== false) {
                    return 1;
                }
            }
            return 2;
        };
        return ($score($a) <=> $score($b)) ?: strnatcasecmp($a['shop_name'], $b['shop_name']);
    });
}

$customerSection = 'tailors';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browse Tailors &amp; Services | NaapSetu</title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/customer.css">
    <link rel="stylesheet" href="/assets/css/tailors.css">
</head>
<body class="customer-page tailors-page">
    <a class="skip-link" href="#main">Skip to tailors</a>
    <div class="customer-shell">
        <?php require dirname(__DIR__, 2) . '/includes/customer-navigation.php'; ?>
        <main id="main" class="customer-main tailors-main">
            <header class="tailors-heading">
                <p class="eyebrow">Find your stitching partner</p>
                <h1>Browse Tailors &amp; Services</h1>
                <p>Find a trusted tailor, explore available services, and start a stitching request.</p>
            </header>

            <form class="tailor-search" method="get" action="/customer/tailors.php" role="search">
                <div class="tailor-search-field">
                    <label class="visually-hidden" for="tailor-query">Search tailors, services, or cities</label>
                    <span aria-hidden="true">⌕</span>
                    <input id="tailor-query" type="search" name="q" value="<?= escape_tailor_listing($query) ?>" placeholder="Search tailor, service, or city">
                </div>
                <div class="tailor-filter-field">
                    <label class="visually-hidden" for="tailor-service">Filter by service</label>
                    <select id="tailor-service" name="service">
                        <option value="">All services</option>
                        <?php foreach ($serviceTypes as $serviceType): ?>
                            <option value="<?= escape_tailor_listing($serviceType) ?>"<?= $selectedService === $serviceType ? ' selected' : '' ?>><?= escape_tailor_listing($serviceType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tailor-filter-field">
                    <label class="visually-hidden" for="tailor-city">Filter by city</label>
                    <select id="tailor-city" name="city">
                        <option value="">All cities</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= escape_tailor_listing($city) ?>"<?= $selectedCity === $city ? ' selected' : '' ?>><?= escape_tailor_listing($city) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tailor-sort-field">
                    <label for="tailor-sort">Sort by</label>
                    <select id="tailor-sort" name="sort">
                        <option value="relevance"<?= $sort === 'relevance' ? ' selected' : '' ?>>Relevance</option>
                        <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Name</option>
                        <option value="price"<?= $sort === 'price' ? ' selected' : '' ?>>Lowest starting price</option>
                        <option value="speed"<?= $sort === 'speed' ? ' selected' : '' ?>>Fastest service</option>
                    </select>
                </div>
                <button class="button tailor-search-submit" type="submit">Search</button>
            </form>

            <div class="tailors-results-heading">
                <h2><?= count($filteredShops) ?> <?= count($filteredShops) === 1 ? 'tailor' : 'tailors' ?> found</h2>
                <?php if ($query !== '' || $selectedCity !== '' || $selectedService !== ''): ?>
                    <a class="tailor-clear-filters" href="/customer/tailors.php">Clear filters</a>
                <?php endif; ?>
            </div>

            <?php if ($filteredShops === []): ?>
                <section class="tailors-empty" aria-labelledby="tailors-empty-title">
                    <span aria-hidden="true">⌕</span>
                    <h2 id="tailors-empty-title">No matching tailors yet</h2>
                    <p>Try another search or clear a filter to see more shops and services.</p>
                    <a class="outline-button" href="/customer/tailors.php">Show all tailors</a>
                </section>
            <?php else: ?>
                <section class="tailor-grid" aria-label="Tailors and services">
                    <?php foreach ($filteredShops as $shop): ?>
                        <?php
                        $matchingServices = array_filter(
                            $shop['services'],
                            static fn (array $service): bool => $selectedService === ''
                                || mb_strtolower($service['garment_type'], 'UTF-8') === mb_strtolower($selectedService, 'UTF-8')
                        );
                        $pricedServices = array_filter($matchingServices, static fn (array $service): bool => $service['base_price'] > 0);
                        $startingPrice = $pricedServices === [] ? null : min(array_column($pricedServices, 'base_price'));
                        $fastestDays = min(array_column($matchingServices, 'avg_days'));
                        $firstService = reset($matchingServices);
                        ?>
                        <article class="tailor-card">
                            <div class="tailor-card-brand">
                                <span class="tailor-monogram" aria-hidden="true"><?= escape_tailor_listing(tailor_initials($shop['shop_name'])) ?></span>
                                <span class="tailor-card-location"><?= escape_tailor_listing($shop['city']) ?></span>
                            </div>
                            <div class="tailor-card-body">
                                <h3><?= escape_tailor_listing($shop['shop_name']) ?></h3>
                                <p class="tailor-address"><?= escape_tailor_listing($shop['address']) ?></p>
                                <ul class="tailor-service-tags" aria-label="Available services">
                                    <?php foreach ($matchingServices as $service): ?>
                                        <li><a href="/customer/request.php?service_id=<?= (int) $service['service_id'] ?>"><?= escape_tailor_listing($service['garment_type']) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="tailor-card-meta">
                                    <span><?= $startingPrice === null ? 'Request a quote' : 'From PKR ' . escape_tailor_listing(number_format((float) $startingPrice, 0)) ?></span>
                                    <span>From <?= (int) $fastestDays ?> <?= (int) $fastestDays === 1 ? 'day' : 'days' ?></span>
                                </div>
                                <a class="button tailor-card-action" href="/customer/request.php<?= $firstService !== false ? '?service_id=' . (int) $firstService['service_id'] : '' ?>">Start a request <span aria-hidden="true">→</span></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
