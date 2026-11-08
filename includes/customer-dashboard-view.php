<?php
function dashboard_escape(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
$customerSection = 'home';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Customer dashboard | NaapSetu</title><link rel="stylesheet" href="/assets/css/site.css"><link rel="stylesheet" href="/assets/css/customer.css">    <link rel="stylesheet" href="/assets/css/usability.css">
</head>
<body class="customer-page"><a class="skip-link" href="#main">Skip to dashboard</a><div class="customer-shell">
<?php require __DIR__ . '/customer-navigation.php'; ?>
<main id="main" class="customer-main">
<header class="dashboard-welcome"><div><h1>Welcome, <?= dashboard_escape($user['name']) ?></h1><p>Manage your measurements, styles, requests <br>and orders — all in one place.</p><a class="button" href="/customer/tailors.php">Request stitching <span aria-hidden="true">→</span></a></div><img src="/assets/images/dashboard-measuring-tape.jpg" alt="Folded navy fabric and a coiled cream measuring tape" width="960" height="640"></header>
<section class="dashboard-stats" aria-label="Your stitching summary">
<?php foreach ([['requests',$requestCount,'Requests','requests.php'],['quotes',$quotationCount,'Quotes to review','requests.php'],['orders',$activeCount,'Active orders','orders.php'],['check',$completedCount,'Completed orders','orders.php']] as [$icon,$count,$label,$file]): ?>
<a class="summary-card" href="/customer/<?= $file ?>"><?= customer_icon($icon) ?><div><strong><?= (int) $count ?></strong><span><?= $label ?></span></div></a>
<?php endforeach; ?>
</section>
<section class="dashboard-orders" aria-label="Active orders">
<?php if ($orders === []): ?>
<div class="empty-orders"><?= customer_icon('orders') ?><div><h2>Your next garment starts here.</h2><p>No active orders yet. Choose a tailor and request a quote.</p></div><a class="outline-button" href="/customer/tailors.php">Browse tailors →</a></div>
<?php else: foreach ($orders as $order):
$stages = ['confirmed'=>'Confirmed','fabric'=>'Fabric arranged','cutting'=>'Cutting','stitching'=>'Stitching','ready'=>'Ready'];
$currentPosition = ['confirmed'=>0,'cutting'=>2,'stitching'=>3,'ready'=>4][$order['status']] ?? 0;
if ($order['status'] === 'confirmed' && $order['received_at'] !== null) {
    $currentPosition = 1;
}
?>
<article class="active-order"><div class="order-heading"><?= customer_icon('orders') ?><div><h2>Active order<br><strong>ORD-<?= str_pad((string) $order['order_id'],4,'0',STR_PAD_LEFT) ?></strong></h2><p><?= dashboard_escape($order['garment_type']) ?> · <?= dashboard_escape($order['shop_name']) ?></p></div></div>
<ol class="order-tracker" aria-label="Order progress">
<?php $i=0; foreach ($stages as $stage=>$label):
$done = $stage === 'fabric'
    ? ($order['received_at'] !== null && $i < $currentPosition)
    : $i < $currentPosition;
$current = $i === $currentPosition;
?>
<li class="<?= $done ? 'is-done' : ($current ? 'is-current' : '') ?>" <?= $current ? 'aria-current="step"' : '' ?>><span class="tracker-dot" aria-hidden="true"><?= $done ? '✓' : '' ?></span><span><?= $label ?></span><span class="visually-hidden"> — <?= $done ? 'complete' : ($current ? 'current stage' : 'not recorded yet') ?></span></li>
<?php $i++; endforeach; ?>
</ol><a class="outline-button" href="/customer/orders.php">View orders</a></article>
<?php endforeach; endif; ?>
</section></main></div><script src="/assets/js/usability.js" defer></script>
</body></html>
