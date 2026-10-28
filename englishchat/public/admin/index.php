<?php
declare(strict_types=1);
/**
 * Bookings admin for Louise (replaces the Bookly / WordPress dashboard).
 * Password is set in private/config.php -> admin_password_hash.
 */
require __DIR__ . '/../api/_init.php';

session_name('ec_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$view = $_GET['view'] ?? 'bookings';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirect_back(string $msg = ''): never
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin/'));
    exit;
}

/* ---------- auth ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: /admin/'); exit; }
if (empty($_SESSION['admin'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!rate_limit('admin_login', 10, 900)) $error = 'Too many attempts – please wait 15 minutes.';
        elseif (password_verify((string)($_POST['password'] ?? ''), (string)config('admin_password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: /admin/');
            exit;
        } else $error = 'Wrong password.';
    }
    page_start('Log in');
    echo '<form method="post" class="login"><h1>englishchat.co.uk</h1><p>Bookings admin</p>'
        . ($error ? '<p class="err">' . h($error) . '</p>' : '')
        . '<label>Password<input type="password" name="password" autofocus required autocomplete="current-password"></label><button class="btn">Log in</button></form>';
    page_end();
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) redirect_back('Session expired, please try again.');
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'status' || $do === 'paid' || $do === 'delete') {
        $a = Bookings::find((int)$_POST['id']);
        if (!$a) redirect_back('Booking not found.');
        if ($do === 'status') {
            $status = (string)$_POST['status'];
            $reviving = in_array($status, ['pending', 'approved'], true) && !in_array($a['status'], ['pending', 'approved'], true);
            if ($reviving && $a['end_utc'] > time() && !Availability::isAvailable((int)$a['service_id'], (int)$a['start_utc'], (int)$a['id'])) {
                // isAvailable() also enforces the 24h notice, so only block real clashes.
                $clash = db()->prepare("SELECT COUNT(*) FROM appointments WHERE id != ? AND start_utc < ? AND end_utc > ? AND status IN ('pending','approved')");
                $clash->execute([$a['id'], $a['end_utc'], $a['start_utc']]);
                if ((int)$clash->fetchColumn() > 0) redirect_back('That time is now taken by another booking.');
            }
            Bookings::setStatus($a, $status, !empty($_POST['notify']));
            redirect_back('Booking #' . $a['id'] . ' is now ' . str_replace('_', ' ', $status) . '.');
        }
        if ($do === 'paid') { Bookings::setPaymentStatus($a, $a['payment_status'] === 'paid' ? 'unpaid' : 'paid'); redirect_back('Payment status updated.'); }
        db()->prepare('DELETE FROM appointments WHERE id = ?')->execute([$a['id']]);
        redirect_back('Booking #' . $a['id'] . ' deleted.');
    }
    if ($do === 'dayoff_add') {
        $from = (string)$_POST['from'];
        $to = (string)($_POST['to'] ?: $from);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) redirect_back('Please pick valid dates.');
        $st = db()->prepare('INSERT OR REPLACE INTO days_off(day, note) VALUES (?, ?)');
        $n = 0;
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to && $n < 400; $d = $d->modify('+1 day'), $n++) $st->execute([$d->format('Y-m-d'), trim((string)$_POST['note'])]);
        redirect_back("$n day(s) off added.");
    }
    if ($do === 'dayoff_del') { db()->prepare('DELETE FROM days_off WHERE day = ?')->execute([(string)$_POST['day']]); redirect_back('Day off removed.'); }
    if ($do === 'msg_read') { db()->prepare('UPDATE messages SET is_read = 1 - is_read WHERE id = ?')->execute([(int)$_POST['id']]); redirect_back(); }
    if ($do === 'msg_del') { db()->prepare('DELETE FROM messages WHERE id = ?')->execute([(int)$_POST['id']]); redirect_back('Message deleted.'); }
    redirect_back();
}

/* ---------- views ---------- */
$unread = (int)db()->query('SELECT COUNT(*) FROM messages WHERE is_read = 0')->fetchColumn();
$pending = (int)db()->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending' AND end_utc > " . time())->fetchColumn();
page_start('Admin');
echo '<header class="top"><a href="/admin/" class="brand">englishchat.co.uk <span>admin</span></a><nav>'
    . nav('bookings', 'Bookings' . ($pending ? " <b>$pending</b>" : ''), $view)
    . nav('daysoff', 'Days off', $view)
    . nav('messages', 'Messages' . ($unread ? " <b>$unread</b>" : ''), $view)
    . (config('mail.driver') === 'log' ? nav('outbox', 'Outbox (dev)', $view) : '')
    . '<a href="/" target="_blank">View site</a><a href="?logout=1">Log out</a></nav></header><main>';
if ($flash) echo '<p class="flash">' . h($flash) . '</p>';

if ($view === 'bookings') {
    $filter = $_GET['f'] ?? 'upcoming';
    $where = match ($filter) {
        'past' => 'end_utc <= ' . time(),
        'all' => '1',
        default => 'end_utc > ' . time(),
    };
    $hideHolds = " AND NOT (status = 'awaiting_payment' AND hold_expires_utc < " . time() . ')';
    $rows = db()->query("SELECT * FROM appointments WHERE $where $hideHolds ORDER BY start_utc " . ($filter === 'upcoming' ? 'ASC' : 'DESC') . ' LIMIT 500')->fetchAll();
    echo '<h2>Bookings</h2><p class="tabs">';
    foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $k => $l) echo '<a href="?view=bookings&f=' . $k . '"' . ($filter === $k ? ' class="on"' : '') . ">$l</a>";
    echo '</p>';
    if (!$rows) echo '<p class="muted">No bookings.</p>';
    foreach ($rows as $a) {
        $when = local_dt((int)$a['start_utc'])->format('D j M Y, g:i a') . ' – ' . fmt_time((int)$a['end_utc']);
        echo '<article class="card s-' . h($a['status']) . '"><div class="card__head"><strong>' . h($when) . '</strong><span class="pill">' . h(str_replace('_', ' ', $a['status'])) . '</span></div>'
            . '<div class="grid"><div><span>Service</span>' . h($a['service_name']) . ' · ' . money((float)$a['price']) . '</div>'
            . '<div><span>Client</span>' . h($a['first_name'] . ' ' . $a['last_name']) . '</div>'
            . '<div><span>Phone</span><a href="tel:' . h($a['phone']) . '">' . h($a['phone']) . '</a> · <a href="https://wa.me/' . h(preg_replace('/\D/', '', $a['phone'])) . '" target="_blank">WhatsApp</a></div>'
            . '<div><span>Email</span><a href="mailto:' . h($a['email']) . '">' . h($a['email']) . '</a></div>'
            . '<div><span>Payment</span>' . h($a['payment_method']) . ' · ' . h($a['payment_status']) . ($a['payment_ref'] ? ' <small>' . h($a['payment_ref']) . '</small>' : '') . '</div>'
            . '<div><span>Booked</span>' . h(local_dt((int)$a['created_utc'])->format('j M Y H:i')) . ($a['client_tz'] ? ' · ' . h($a['client_tz']) : '') . '</div></div>'
            . ($a['notes'] !== '' ? '<p class="notes">' . nl2br(h($a['notes'])) . '</p>' : '')
            . '<div class="actions">';
        foreach (['approved' => 'Approve', 'rejected' => 'Reject', 'cancelled' => 'Cancel', 'pending' => 'Set pending'] as $s => $label) {
            if ($a['status'] === $s) continue;
            echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="' . $a['id'] . '"><input type="hidden" name="status" value="' . $s . '"><input type="hidden" name="notify" value="1"><button class="btn btn--sm' . ($s === 'approved' ? '' : ' btn--ghost') . '" title="Changes the status and e-mails the client">' . $label . '</button></form>';
        }
        if ($a['payment_method'] !== 'free') echo '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="paid"><input type="hidden" name="id" value="' . $a['id'] . '"><button class="btn btn--sm btn--ghost">Mark ' . ($a['payment_status'] === 'paid' ? 'unpaid' : 'paid') . '</button></form>';
        echo '<form method="post" onsubmit="return confirm(\'Delete this booking permanently?\')"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . $a['id'] . '"><button class="btn btn--sm btn--danger">Delete</button></form>';
        echo '</div></article>';
    }
}

if ($view === 'daysoff') {
    $days = db()->query('SELECT * FROM days_off WHERE day >= date("now", "-1 day") ORDER BY day')->fetchAll();
    echo '<h2>Days off</h2><p class="muted">No slots are offered on these days. Working hours: '
        . h(implode(', ', array_filter(array_map(fn($d, $iv) => $iv ? ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$d] . ' ' . implode(' & ', array_map(fn($x) => $x[0] . '–' . $x[1], $iv)) : '', array_keys(config('booking.schedule')), config('booking.schedule')))))
        . ' (change in config.php).</p>'
        . '<form method="post" class="row"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="dayoff_add">'
        . '<label>From<input type="date" name="from" required></label><label>To (optional)<input type="date" name="to"></label><label>Note<input type="text" name="note" placeholder="Holiday"></label><button class="btn">Add</button></form>';
    if (!$days) echo '<p class="muted">No upcoming days off.</p>';
    else {
        echo '<table><tr><th>Date</th><th>Note</th><th></th></tr>';
        foreach ($days as $d) echo '<tr><td>' . h((new DateTimeImmutable($d['day']))->format('D j M Y')) . '</td><td>' . h($d['note']) . '</td><td><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="dayoff_del"><input type="hidden" name="day" value="' . h($d['day']) . '"><button class="btn btn--sm btn--ghost">Remove</button></form></td></tr>';
        echo '</table>';
    }
}

if ($view === 'messages') {
    $rows = db()->query('SELECT * FROM messages ORDER BY created_utc DESC LIMIT 300')->fetchAll();
    echo '<h2>Contact form messages</h2>';
    if (!$rows) echo '<p class="muted">No messages yet.</p>';
    foreach ($rows as $m) {
        echo '<article class="card' . ($m['is_read'] ? ' read' : '') . '"><div class="card__head"><strong>' . h($m['name']) . '</strong> <a href="mailto:' . h($m['email']) . '">' . h($m['email']) . '</a><span class="pill">' . h(local_dt((int)$m['created_utc'])->format('j M Y H:i')) . '</span></div>'
            . '<p class="notes">' . nl2br(h($m['message'])) . '</p>'
            . ($m['mail_status'] !== 'sent' ? '<p class="err">E-mail delivery failed – this message only exists here.</p>' : '')
            . '<div class="actions"><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="msg_read"><input type="hidden" name="id" value="' . $m['id'] . '"><button class="btn btn--sm btn--ghost">Mark ' . ($m['is_read'] ? 'unread' : 'read') . '</button></form>'
            . '<form method="post" onsubmit="return confirm(\'Delete this message?\')"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="msg_del"><input type="hidden" name="id" value="' . $m['id'] . '"><button class="btn btn--sm btn--danger">Delete</button></form></div></article>';
    }
}

if ($view === 'outbox' && config('mail.driver') === 'log') {
    echo '<h2>Outbox <small class="muted">(development – e-mails are saved here instead of being sent)</small></h2>';
    $files = glob(EC_DATA . '/mail/*.json') ?: [];
    rsort($files);
    if (!$files) echo '<p class="muted">Nothing sent yet.</p>';
    foreach (array_slice($files, 0, 100) as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        echo '<details class="card"><summary><strong>' . h($m['subject']) . '</strong> → ' . h($m['to']) . ' <span class="pill">' . h(date('j M H:i:s', (int)$m['at'])) . '</span></summary>'
            . '<iframe class="mail" sandbox="allow-popups allow-top-navigation-by-user-activation" srcdoc="' . h('<base target="_top">' . $m['html']) . '"></iframe></details>';
    }
}
echo '</main>';
page_end();

/* ---------- layout ---------- */
function nav(string $key, string $label, string $view): string
{
    return '<a href="?view=' . $key . '"' . ($view === $key ? ' class="on"' : '') . '>' . $label . '</a>';
}
function page_start(string $title): void
{
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">'
        . '<title>' . h($title) . ' – englishchat.co.uk</title><link rel="icon" href="/assets/img/cropped-English-Chat-1-32x32.png"><link rel="stylesheet" href="/assets/fonts/fonts.css"><style>'
        . ':root{--navy:#011031;--accent:#5c6c9f;--cream:#f6f0ed}*{box-sizing:border-box}body{margin:0;font:15px/1.5 Poppins,Arial,sans-serif;color:var(--navy);background:var(--cream)}a{color:var(--accent)}'
        . '.top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:14px 24px;background:var(--navy)}.brand{color:#fff;text-decoration:none;font:700 18px Merriweather,serif}.brand span{font:400 13px Poppins;opacity:.7}'
        . '.top nav{display:flex;flex-wrap:wrap;gap:4px}.top nav a{color:#fff;text-decoration:none;padding:6px 12px;border-radius:4px}.top nav a.on,.top nav a:hover{background:rgba(255,255,255,.15)}.top nav b{background:#e0a100;color:var(--navy);border-radius:10px;padding:0 7px;font-size:12px}'
        . 'main{max-width:980px;margin:0 auto;padding:24px 16px 60px}h2{font:700 26px Merriweather,serif;margin:10px 0 14px}.muted{color:#6b7280}.flash{background:#e7f6e9;border:1px solid #46b450;padding:10px 14px;border-radius:4px}.err{color:#c0392b}'
        . '.tabs a{display:inline-block;margin-right:6px;padding:4px 12px;border-radius:14px;text-decoration:none;background:#fff}.tabs a.on{background:var(--navy);color:#fff}'
        . '.card{background:#fff;border-radius:6px;padding:14px 16px;margin:0 0 12px;border-left:4px solid var(--accent);box-shadow:0 1px 2px rgba(0,0,0,.05)}.card.read{opacity:.7}.card__head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px}.card summary{cursor:pointer}'
        . '.pill{margin-left:auto;font-size:12px;padding:2px 10px;border-radius:10px;background:#eef0f6;text-transform:capitalize}.s-pending{border-left-color:#e0a100}.s-approved{border-left-color:#46b450}.s-rejected,.s-cancelled{border-left-color:#bbb;opacity:.75}.s-awaiting_payment{border-left-color:#bbb}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 18px;font-size:14px}.grid span{display:inline-block;min-width:70px;color:#6b7280}.notes{background:var(--cream);padding:8px 12px;border-radius:4px;white-space:normal}'
        . '.actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.actions form{margin:0}.btn{display:inline-block;border:1px solid var(--navy);background:var(--navy);color:#fff;font:700 13px Poppins,Arial;text-transform:uppercase;padding:10px 22px;cursor:pointer}.btn:hover{background:var(--accent);border-color:var(--accent)}'
        . '.btn--sm{padding:5px 12px;font-size:12px}.btn--ghost{background:#fff;color:var(--navy)}.btn--danger{background:#fff;color:#c0392b;border-color:#e3b3ad}'
        . '.row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;padding:14px;border-radius:6px;margin-bottom:16px}label{display:flex;flex-direction:column;font-size:13px;gap:4px}input{font:inherit;padding:8px 10px;border:1px solid #ccc;border-radius:4px}'
        . 'table{width:100%;border-collapse:collapse;background:#fff;border-radius:6px;overflow:hidden}td,th{text-align:left;padding:8px 12px;border-bottom:1px solid #eee}.mail{width:100%;height:420px;border:1px solid #eee;margin-top:10px;background:#fff}'
        . '.login{max-width:340px;margin:12vh auto;background:#fff;padding:30px;border-radius:8px;display:flex;flex-direction:column;gap:14px}.login h1{font:700 22px Merriweather,serif;margin:0}.login p{margin:0}'
        . '</style></head><body>';
}
function page_end(): void
{
    echo '</body></html>';
}
