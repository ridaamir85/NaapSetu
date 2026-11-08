<?php
declare(strict_types=1);

/**
 * Event activity is derived from the existing, permission-scoped business tables.
 * This avoids missed notifications when actions originate from different screens.
 * The lightweight notification_reads table stores only a viewer's read state.
 */
function naapsetu_notifications(PDO $db, array $viewer): array
{
    $id = (int) $viewer['user_id'];
    $role = (string) $viewer['role'];
    $queries = [];

    if ($role === 'customer') {
        $queries = [
            ["SELECT CONCAT('quote:', q.quotation_id) AS event_key, 'requests' AS section,
                CONCAT('New quotation #', q.quotation_id) AS title,
                'Your tailor sent a quotation. Review its price and deadline.' AS detail,
                CONCAT('/customer/requests.php#quotation-', q.quotation_id) AS destination,
                r.created_at AS event_time
                FROM quotations q JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND q.status='sent'"],
            ["SELECT CONCAT('order-status:', h.history_id), 'orders',
                CONCAT('Order #', o.order_id, ': ', REPLACE(h.status, '_', ' ')),
                'Your order progress was updated.',
                CONCAT('/customer/orders.php?order_id=', o.order_id), h.changed_at
                FROM order_status_history h
                JOIN orders o ON o.order_id=h.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND h.status<>'confirmed'"],
            ["SELECT CONCAT('alteration-status:', a.alteration_id, ':', a.status), 'feedback',
                CONCAT('Alteration #', a.alteration_id, ': ', REPLACE(a.status,'_',' ')),
                'Your tailor updated the alteration request.',
                CONCAT('/customer/feedback.php?order_id=', a.order_id),
                o.confirmed_at
                FROM alteration_requests a JOIN orders o ON o.order_id=a.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND a.status IN ('in_progress','done')"]
        ];
    } elseif ($role === 'tailor') {
        $queries = [
            ["SELECT CONCAT('request:', r.request_id), 'requests',
                CONCAT('New stitching request #', r.request_id),
                'A customer sent your boutique a stitching request.',
                CONCAT('/tailor/requests.php?request_id=', r.request_id), r.created_at
                FROM order_requests r JOIN tailor_profiles p ON p.tailor_id=r.tailor_id
                WHERE p.user_id=?"],
            ["SELECT CONCAT('revision:', q.quotation_id), 'requests',
                CONCAT('Quotation #', q.quotation_id, ': revision requested'),
                'The customer has requested a revised quotation.',
                CONCAT('/tailor/requests.php?request_id=', r.request_id), r.created_at
                FROM quotations q JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id
                WHERE p.user_id=? AND q.status='revision_requested'"],
            ["SELECT CONCAT('order:', o.order_id), 'orders',
                CONCAT('New confirmed order #', o.order_id),
                'A customer accepted your quotation.',
                CONCAT('/tailor/orders.php?order_id=', o.order_id), o.confirmed_at
                FROM orders o JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id WHERE p.user_id=?"],
            ["SELECT CONCAT('feedback:', f.feedback_id), 'orders',
                CONCAT('Fit feedback on order #', o.order_id),
                'A customer submitted fitting feedback.',
                CONCAT('/tailor/orders.php?order_id=', o.order_id), o.confirmed_at
                FROM fit_feedback f JOIN orders o ON o.order_id=f.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id WHERE p.user_id=?"],
            ["SELECT CONCAT('alteration:', a.alteration_id), 'alterations',
                CONCAT('New alteration #', a.alteration_id),
                'A customer requested an adjustment.',
                '/tailor/alterations.php' AS destination, o.confirmed_at
                FROM alteration_requests a JOIN orders o ON o.order_id=a.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id WHERE p.user_id=?"]
        ];
    } elseif ($role === 'admin') {
        $queries = [
            ["SELECT CONCAT('recovery:', r.recovery_id), 'recovery',
                CONCAT('Recovery request #', r.recovery_id),
                'A registered user requested help accessing their account.',
                '/admin/password-recovery.php', r.requested_at
                FROM password_recovery_requests r WHERE r.status='pending'"]
        ];
    }
    $items = [];
    foreach ($queries as $sql) {
        $statement = $db->prepare($sql[0]);
        if ($role === 'admin') {
            $statement->execute();
        } else {
            $statement->execute([$id]);
        }
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            [$key, $section, $title, $detail, $url, $time] = $row;
            $items[] = [
                'key' => (string) $key,
                'section' => (string) $section,
                'title' => (string) $title,
                'detail' => (string) $detail,
                'url' => (string) $url,
                'time' => (string) ($time ?? ''),
                'unread' => true,
            ];
        }
    }
    if ($items !== []) {
        $statement = $db->prepare('SELECT event_key FROM notification_reads WHERE user_id=?');
        $statement->execute([$id]);
        $seen = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($items as &$item) {
            $item['unread'] = !isset($seen[$item['key']]);
        }
        unset($item);
    }
    usort($items, static fn(array $a, array $b): int =>
        strcmp($b['time'], $a['time']) ?: strcmp($b['key'], $a['key']));
    return $items;
}

function naapsetu_notification_counts(array $items): array
{
    $counts = ['notifications' => 0];
    foreach ($items as $item) {
        if (!$item['unread']) continue;
        ++$counts['notifications'];
        $counts[$item['section']] = ($counts[$item['section']] ?? 0) + 1;
    }
    return $counts;
}
