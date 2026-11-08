<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/tailor.php';
$user = require_role('tailor');
$db = database();
$profile = tailor_profile_for_user($db, (int)$user['user_id']);
if ($profile === null) redirect_to('/tailor/profile.php');
function esc_service(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$categories = ['women' => 'Women\'s Tailoring', 'men' => 'Men\'s Tailoring'];
$statement = $db->prepare("SELECT service_id, specialization, is_enabled FROM tailor_services WHERE tailor_id=? AND specialization IS NOT NULL");
$statement->execute([(int)$profile['tailor_id']]);
$existing = [];
foreach ($statement->fetchAll() as $row) $existing[$row['specialization']] = $row;
$selected = array_keys(array_filter($existing, static fn(array $s): bool => (int)$s['is_enabled']===1));
$error = null;
$saved = ($_GET['saved'] ?? '') === '1';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $choices = $_POST['specializations'] ?? [];
    if (!is_array($choices)) $choices = [];
    $choices = array_values(array_unique(array_filter($choices, 'is_string')));
    if (!csrf_token_is_valid(is_string($_POST['csrf_token'] ?? null)?$_POST['csrf_token']:null)) {
        $error = 'Session expired. Refresh and try again.';
    } elseif (array_diff($choices, array_keys($categories)) || count($choices) === 0) {
        $error = 'Select men\'s tailoring, women\'s tailoring, or both.';
    } else {
        try {
            $db->beginTransaction();
            // Existing legacy services are retained for historical orders.
            $off = $db->prepare('UPDATE tailor_services SET is_enabled=0 WHERE tailor_id=? AND specialization IS NOT NULL');
            $off->execute([(int)$profile['tailor_id']]);
            $upsert = $db->prepare(
                'INSERT INTO tailor_services (tailor_id,garment_type,base_price,avg_days,specialization,is_enabled) '
                . 'VALUES (:tailor_id,:label,0,1,:category,1) '
                . 'ON DUPLICATE KEY UPDATE is_enabled=1,garment_type=VALUES(garment_type)'
            );
            foreach ($choices as $category) {
                $upsert->execute([
                    'tailor_id'=>(int)$profile['tailor_id'],
                    'label'=>$categories[$category],
                    'category'=>$category,
                ]);
            }
            $db->commit();
            redirect_to('/tailor/services.php?saved=1');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log($e->getMessage());
            $error = 'Specializations could not be saved. Please try again.';
        }
    }
    $selected=$choices;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tailoring specializations | NaapSetu</title>
<link rel="stylesheet" href="/assets/css/site.css">
<link rel="stylesheet" href="/assets/css/tailor-navigation.css">
<link rel="stylesheet" href="/assets/css/usability.css">
<link rel="stylesheet" href="/assets/css/tailor-services.css"></head>
<body class="tailor-services-page"><a class="skip-link" href="#main">Skip to specializations</a>
<div class="tailor-dashboard-shell">
<?php $tailorSection='services';require dirname(__DIR__,2).'/includes/tailor-navigation.php'; ?>
<main class="tailor-services-main" id="main">
<p class="tailor-eyebrow">Your boutique</p><h1>Tailoring specializations</h1>
<p>Choose which clothing categories you stitch. Customers can request a complete outfit, individual garments, or any combination within your specializations.</p>
<?php if ($saved): ?><p role="status">Your tailoring specializations were saved.</p><?php endif; ?>
<?php if ($error): ?><p role="alert"><?= esc_service($error) ?></p><?php endif; ?>
<form method="post" action="/tailor/services.php" class="specialization-form">
<input type="hidden" name="csrf_token" value="<?= esc_service(csrf_token()) ?>">
<?php foreach ($categories as $key=>$label): ?>
<label class="specialization-choice">
<input type="checkbox" name="specializations[]" value="<?= esc_service($key) ?>"<?= in_array($key,$selected,true)?' checked':'' ?>>
<span><strong><?= esc_service($label) ?></strong><small>Custom stitching, from a single garment to a complete outfit.</small></span>
</label>
<?php endforeach; ?>
<p class="specialization-hint">Prices and completion dates are provided through individual quotations after you review each customer's design, measurements, and fabric preferences.</p>
<button type="submit">Save specializations →</button>
</form>
<section class="specialization-history"><h2>Previous service records</h2>
<p>Earlier garment-specific services are preserved in your existing requests and orders, but they are no longer offered for new stitching requests.</p></section>
</main></div><script src="/assets/js/usability.js" defer></script></body></html>
