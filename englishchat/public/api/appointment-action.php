<?php
declare(strict_types=1);
// One-click Approve / Reject links from Louise's "new booking" e-mail.
// Lands on /confirm-appointment/ or /reject-appointment/ like Bookly did.
//
// The change itself is made by a POST that this page submits automatically,
// so e-mail link scanners (Outlook Safe Links etc.) that pre-fetch links with
// GET can't approve or reject bookings by accident.
require __DIR__ . '/_init.php';

$id = (int)($_REQUEST['id'] ?? 0);
$action = (string)($_REQUEST['a'] ?? '');
$sig = (string)($_REQUEST['s'] ?? '');
$a = Bookings::find($id);
if (!$a || !in_array($action, ['approve', 'reject'], true) || !hash_equals(sign("$id|$action|{$a['token']}"), $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This link is invalid or has expired.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $label = $action === 'approve' ? 'Approve' : 'Reject';
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . $label . ' booking – englishchat.co.uk</title></head><body style="font-family:Poppins,Arial,sans-serif;text-align:center;padding:60px 20px;color:#011031">'
        . '<form method="post" id="f"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="a" value="' . h($action) . '"><input type="hidden" name="s" value="' . h($sig) . '">'
        . '<p>' . h($a['service_name'] . ' – ' . trim($a['first_name'] . ' ' . $a['last_name']) . ', ' . fmt_date((int)$a['start_utc']) . ' ' . fmt_time((int)$a['start_utc'])) . '</p>'
        . '<button type="submit" style="background:#011031;color:#fff;border:0;padding:13px 38px;font-weight:700;text-transform:uppercase;cursor:pointer">' . $label . '</button></form>'
        . '<script>document.getElementById("f").submit();</script></body></html>';
    exit;
}

$a = Bookings::setStatus($a, $action === 'approve' ? 'approved' : 'rejected');
$summary = $a['service_name'] . ' – ' . trim($a['first_name'] . ' ' . $a['last_name']) . ', ' . fmt_date((int)$a['start_utc']) . ' ' . fmt_time((int)$a['start_utc']);
header('Location: ' . ($action === 'approve' ? '/confirm-appointment/' : '/reject-appointment/') . '?appointment=' . rawurlencode($summary), true, 303);
