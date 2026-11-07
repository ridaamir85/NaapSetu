<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
$user = require_role('customer');
$db = database();
$stmt = $db->prepare('SELECT COUNT(*) FROM order_requests WHERE customer_id = ?');
$stmt->execute([$user['user_id']]);
$requestCount = (int) $stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM quotations q JOIN order_requests r ON r.request_id=q.request_id WHERE r.customer_id=? AND q.status='sent'");
$stmt->execute([$user['user_id']]);
$quotationCount = (int) $stmt->fetchColumn();
$stmt = $db->prepare('SELECT o.order_id, o.status, s.garment_type, p.shop_name, f.received_at FROM orders o JOIN quotations q ON q.quotation_id=o.quotation_id JOIN order_requests r ON r.request_id=q.request_id JOIN tailor_services s ON s.service_id=r.service_id AND s.tailor_id=r.tailor_id JOIN tailor_profiles p ON p.tailor_id=r.tailor_id LEFT JOIN fabric_records f ON f.order_id=o.order_id WHERE r.customer_id=? ORDER BY o.confirmed_at DESC, o.order_id DESC');
$stmt->execute([$user['user_id']]);
$allOrders = $stmt->fetchAll();
$orders = array_values(array_filter($allOrders, fn($order) => $order['status'] !== 'delivered'));
$activeCount = count($orders);
$completedCount = count($allOrders) - $activeCount;
$orders = array_slice($orders, 0, 3);
require dirname(__DIR__, 2) . '/includes/customer-dashboard-view.php';
