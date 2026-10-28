<?php
declare(strict_types=1);
/**
 * Booking API used by the booking form on the home page.
 *
 *   GET  ?action=init                         services, limits, payment options
 *   GET  ?action=slots&service=&from=&to=     available start times (UTC seconds)
 *   POST ?action=validate                     details-step validation
 *   POST ?action=book                         create the booking (free / local / card)
 *   POST ?action=pay_mock                     development-only fake card payment
 *   POST ?action=finalize                     confirm a Stripe card payment
 *   POST ?action=release                      give up an unpaid card hold
 */
require __DIR__ . '/_init.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && $action === 'init') {
        $services = array_map(function ($s) {
            $d = $s['duration'];
            $s['label'] = $s['name'] . ' ( ' . ($d % 60 === 0 ? ($d / 60) . ' h' : ($d > 60 ? intdiv($d, 60) . ' h ' . ($d % 60) . ' min' : $d . ' min')) . ' )';
            $s['price_text'] = money($s['price']);
            return $s;
        }, Availability::services());
        json_out([
            'services' => $services,
            'staff' => config('staff.name'),
            'date_min' => Availability::minDate(),
            'date_max' => Availability::maxDate(),
            'timezone' => config('timezone'),
            'currency_symbol' => config('booking.currency_symbol'),
            'payments' => [
                'local' => (bool)config('payments.local'),
                'card' => Stripe::enabled() ? (Stripe::isMock() ? 'mock' : 'stripe') : null,
                'stripe_publishable_key' => Stripe::enabled() && !Stripe::isMock() ? config('payments.stripe_publishable_key') : null,
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'slots') {
        $service = (int)($_GET['service'] ?? 0);
        $from = (int)($_GET['from'] ?? 0);
        $to = (int)($_GET['to'] ?? 0);
        if (!Availability::service($service)) json_out(['error' => 'Please select a service'], 400);
        if ($to - $from > 46 * 86400 || $to <= $from) json_out(['error' => 'Bad range'], 400);
        $slots = Availability::slots($service, $from, $to);
        // Is there anything bookable after this range? (lets the calendar jump ahead)
        $next = null;
        if (!$slots) {
            $probe = $to;
            $limit = (new DateTimeImmutable(Availability::maxDate(), tz()))->modify('+1 day')->getTimestamp();
            while ($probe < $limit && $next === null) {
                $found = Availability::slots($service, $probe, min($limit, $probe + 31 * 86400));
                if ($found) $next = $found[0];
                $probe += 31 * 86400;
            }
        }
        json_out(['slots' => $slots, 'next' => $next]);
    }

    if ($method !== 'POST') json_out(['error' => 'Not found'], 404);
    $in = json_in();

    if ($action === 'validate') {
        $errors = Bookings::validateDetails($in);
        if ($errors) json_out(['ok' => false, 'errors' => $errors]);
        if (!Availability::isAvailable((int)($in['service_id'] ?? 0), (int)($in['start'] ?? 0))) {
            json_out(['ok' => false, 'slot_unavailable' => true, 'message' => 'The selected time is not available anymore. Please, choose another time slot.']);
        }
        json_out(['ok' => true]);
    }

    if ($action === 'book') {
        if (!rate_limit('book', 20, 3600)) json_out(['ok' => false, 'message' => 'Too many attempts. Please try again later.'], 429);
        $errors = Bookings::validateDetails($in);
        if ($errors) json_out(['ok' => false, 'errors' => $errors]);
        $service = Availability::service((int)($in['service_id'] ?? 0));
        $pm = (string)($in['payment'] ?? '');
        if ($service && $service['price'] > 0) {
            if ($pm === 'local' && !config('payments.local')) json_out(['ok' => false, 'message' => 'Pay locally is not available.']);
            if ($pm === 'card' && !Stripe::enabled()) json_out(['ok' => false, 'message' => 'Invalid gateway.']);
            if (!in_array($pm, ['local', 'card'], true)) json_out(['ok' => false, 'message' => 'Please select a payment method.']);
        }
        try {
            $a = Bookings::create($in, $pm);
        } catch (DomainException $e) {
            json_out(['ok' => false, 'slot_unavailable' => true, 'message' => $e->getMessage()]);
        }
        if ($a['payment_method'] !== 'card') json_out(['ok' => true, 'done' => true, 'status' => $a['status']]);
        $res = ['ok' => true, 'done' => false, 'id' => $a['id'], 'token' => $a['token'], 'amount' => money((float)$a['price'])];
        if (Stripe::isMock()) {
            $res['mock'] = true;
        } else {
            try {
                $intent = Stripe::createIntent($a);
            } catch (Throwable $e) {
                Bookings::release($a);
                error_log('Stripe: ' . $e->getMessage());
                json_out(['ok' => false, 'message' => 'Error.']);
            }
            db()->prepare('UPDATE appointments SET payment_ref = ? WHERE id = ?')->execute([$intent['id'], $a['id']]);
            $res['client_secret'] = $intent['client_secret'];
        }
        json_out($res);
    }

    $a = Bookings::findByToken((int)($in['id'] ?? 0), (string)($in['token'] ?? ''));
    if (!$a) json_out(['ok' => false, 'message' => 'Session error.'], 404);

    if ($action === 'pay_mock') {
        if (!Stripe::isMock()) json_out(['ok' => false, 'message' => 'Invalid gateway.'], 400);
        $number = preg_replace('/\D/', '', (string)($in['card_number'] ?? ''));
        if ($a['status'] !== 'awaiting_payment' || (int)$a['hold_expires_utc'] < time()) {
            json_out(['ok' => false, 'message' => 'The selected time is not available anymore. Please, choose another time slot.']);
        }
        if ($number === '4000000000000002') json_out(['ok' => false, 'message' => 'Your card was declined.']);
        if ($number !== '4242424242424242') json_out(['ok' => false, 'message' => 'Your card number is invalid.']);
        Bookings::markPaid($a, 'mock_' . bin2hex(random_bytes(6)));
        json_out(['ok' => true, 'done' => true]);
    }

    if ($action === 'finalize') {
        $pi = (string)($a['payment_ref'] ?? '');
        if ($pi === '' || Stripe::isMock()) json_out(['ok' => false, 'message' => 'Invalid gateway.'], 400);
        $intent = Stripe::retrieveIntent($pi);
        $expected = (int)round((float)$a['price'] * 100);
        if (($intent['status'] ?? '') === 'succeeded' && (int)$intent['amount_received'] >= $expected) {
            Bookings::markPaid($a, $pi);
            json_out(['ok' => true, 'done' => true]);
        }
        if (($intent['status'] ?? '') === 'processing') json_out(['ok' => true, 'done' => true, 'processing' => true]);
        json_out(['ok' => false, 'message' => 'Error.']);
    }

    if ($action === 'release') {
        Bookings::release($a);
        json_out(['ok' => true]);
    }

    json_out(['error' => 'Not found'], 404);
} catch (Throwable $e) {
    error_log('booking.php: ' . $e);
    json_out(['ok' => false, 'message' => 'Error.'], 500);
}
