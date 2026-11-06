<?php

declare(strict_types=1);

function tailor_profile_for_user(PDO $connection, int $userId): ?array
{
    $statement = $connection->prepare(
        'SELECT tailor_id, user_id, shop_name, city, address, max_active_orders '
        . 'FROM tailor_profiles WHERE user_id = :user_id LIMIT 1'
    );
    $statement->execute(['user_id' => $userId]);
    $profile = $statement->fetch();

    return $profile === false ? null : $profile;
}
