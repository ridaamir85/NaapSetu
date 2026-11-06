<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = require_role('customer');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Customer dashboard | NaapSetu</title>
</head>
<body>
    <main>
        <h1>Customer dashboard</h1>
        <p>Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>.</p>
        <p>Your measurement profiles, styles, requests, and orders will appear here.</p>
        <p><a href="/customer/measurements.php">Measurement profiles</a></p>
        <p><a href="/customer/tailors.php">Browse tailors</a></p>
        <p><a href="/customer/requests.php">My requests and quotations</a></p>
        <p><a href="/customer/orders.php">My orders</a></p>
        <p><a href="/customer/feedback.php">My feedback and alterations</a></p>

        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>

