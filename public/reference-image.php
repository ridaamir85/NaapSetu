<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/auth.php';
start_app_session();
$user=signed_in_user();
if (!$user) {http_response_code(403);exit('Forbidden');}
$db=database();
$check=$db->prepare('SELECT role,is_active,must_change_password FROM users WHERE user_id=?');
$check->execute([(int)$user['user_id']]);
$account=$check->fetch();
if (!$account || (int)$account['is_active']!==1 || (int)$account['must_change_password']===1) {
    http_response_code(403);exit('Forbidden');
}
$id=filter_var($_GET['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$id) {http_response_code(404);exit('Not found');}
if ($account['role']==='customer') {
    $sql='SELECT reference_image_path FROM order_requests WHERE request_id=? AND customer_id=?';
} elseif ($account['role']==='tailor') {
    $sql='SELECT r.reference_image_path FROM order_requests r JOIN tailor_profiles p ON p.tailor_id=r.tailor_id WHERE r.request_id=? AND p.user_id=?';
} else {http_response_code(403);exit('Forbidden');}
$stmt=$db->prepare($sql);
$stmt->execute([(int)$id,(int)$user['user_id']]);
$row=$stmt->fetch();
$rel=(string)($row['reference_image_path']??'');
if (!preg_match('~^request-images/[a-f0-9]{40}\\.(jpg|png|webp)$~D',$rel)) {
    http_response_code(404);exit('Not found');
}
$path=dirname(__DIR__).'/storage/'.$rel;
if (!is_file($path)) {http_response_code(404);exit('Not found');}
$ext=pathinfo($path,PATHINFO_EXTENSION);
$mime=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext]??null;
if (!$mime) {http_response_code(404);exit('Not found');}
header('Content-Type: '.$mime);
header('Content-Disposition: inline; filename="reference.'.$ext.'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('Content-Length: '.filesize($path));
readfile($path);
