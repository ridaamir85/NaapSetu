<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

start_app_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
    sign_out_user();
}

redirect_to('/index.php');

