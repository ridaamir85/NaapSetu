<?php
declare(strict_types=1);

/**
 * Small sidebar indicators computed directly from workflow status.
 * No notification inbox, read receipts, or extra database table.
 * Counts represent pending actions, not individually unread events.
 */
function naapsetu_activity_badges(PDO $db, array $viewer): array
{
    $id = (int) $viewer['user_id'];
    $counts = [];
    $queries = [];
    if ($viewer['role'] === 'customer') {
        $queries = [
            'requests' => "SELECT COUNT(DISTINCT q.quotation_id)
                FROM quotations q JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND r.status='quoted' AND q.status='sent'",
            'orders' => "SELECT COUNT(DISTINCT o.order_id)
                FROM orders o JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND o.status='ready'",
            'feedback' => "SELECT COUNT(DISTINCT a.alteration_id)
                FROM alteration_requests a JOIN orders o ON o.order_id=a.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                WHERE r.customer_id=? AND a.status='in_progress'",
        ];
    } elseif ($viewer['role'] === 'tailor') {
        $queries = [
            'requests' => "SELECT COUNT(DISTINCT r.request_id)
                FROM order_requests r JOIN tailor_profiles p ON p.tailor_id=r.tailor_id
                WHERE p.user_id=? AND (
                    r.status='pending'
                    OR (r.status='quoted' AND EXISTS (
                        SELECT 1 FROM quotations q
                        WHERE q.request_id=r.request_id AND q.status='revision_requested'
                    ))
                )",
            'orders' => "SELECT COUNT(DISTINCT o.order_id)
                FROM orders o JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id
                WHERE p.user_id=? AND o.status='confirmed'",
            'alterations' => "SELECT COUNT(DISTINCT a.alteration_id)
                FROM alteration_requests a JOIN orders o ON o.order_id=a.order_id
                JOIN quotations q ON q.quotation_id=o.quotation_id
                JOIN order_requests r ON r.request_id=q.request_id
                JOIN tailor_profiles p ON p.tailor_id=r.tailor_id
                WHERE p.user_id=? AND a.status='open'",
        ];
    }
    foreach ($queries as $section=>$query) {
        $statement=$db->prepare($query);
        $statement->execute([$id]);
        $counts[$section]=(int)$statement->fetchColumn();
    }
    return $counts;
}
