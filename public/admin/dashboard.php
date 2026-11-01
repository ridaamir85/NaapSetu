<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';

$user = require_role('admin');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administrator dashboard | NaapSetu</title>
</head>
<body>
    <main>
        <h1>Administrator dashboard</h1>
        <p>Welcome, <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>.</p>
        <p>User access and platform activity controls will appear here.</p>

        <form method="post" action="/logout.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>

