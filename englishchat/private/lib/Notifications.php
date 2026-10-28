<?php
declare(strict_types=1);

/**
 * Booking e-mails (replacing Bookly's notifications).
 *  - customer: booking received (pending) / approved / rejected
 *  - Louise:   new booking, with one-click Approve / Reject links that land on
 *              /confirm-appointment/ and /reject-appointment/
 */
final class Notifications
{
    public static function actionUrl(array $a, string $action): string
    {
        $id = (int)$a['id'];
        return site_url('/api/appointment-action.php?' . http_build_query([
            'id' => $id,
            'a' => $action,
            's' => sign("$id|$action|{$a['token']}"),
        ]));
    }

    private static function details(array $a): string
    {
        $lines = [
            'Service: ' . $a['service_name'],
            'Date: ' . fmt_date((int)$a['start_utc']),
            'Time: ' . fmt_time((int)$a['start_utc']) . ' – ' . fmt_time((int)$a['end_utc']) . ' (UK time)',
            'Price: ' . money((float)$a['price']),
        ];
        return implode("\n", $lines);
    }

    private static function payment(array $a): string
    {
        return match ($a['payment_method']) {
            'card' => 'Paid by card' . ($a['payment_status'] === 'paid' ? '' : ' (' . $a['payment_status'] . ')'),
            'local' => 'To be paid locally',
            default => 'Free',
        };
    }

    private static function sign_off(): string
    {
        return "\n\nThank you for choosing englishchat.co.uk.\n\nLouise\n" . config('staff.phone') . "\n" . site_url('/');
    }

    public static function newBooking(array $a): void
    {
        $name = trim($a['first_name'] . ' ' . $a['last_name']);
        $pending = $a['status'] === 'pending';

        // Customer
        $text = "Dear {$a['first_name']},\n\n"
            . ($pending
                ? "Thank you for your booking. It is now pending – I will confirm it by e-mail as soon as possible."
                : "This is a confirmation that you have booked {$a['service_name']}.")
            . "\n\n" . self::details($a) . "\nPayment: " . self::payment($a)
            . "\n\nIf you need to change or cancel, please reply to this e-mail or message me on WhatsApp at least 24 hours before your lesson."
            . self::sign_off();
        Mailer::send($a['email'], $pending ? 'Your booking is pending – englishchat.co.uk' : 'Your appointment information – englishchat.co.uk', $text, null, config('staff.email'));

        // Louise
        $text = "Hello.\n\nYou have a new booking.\n\n" . self::details($a)
            . "\nPayment: " . self::payment($a)
            . "\n\nClient: $name\nPhone: {$a['phone']}\nEmail: {$a['email']}"
            . ($a['notes'] !== '' ? "\nNotes: {$a['notes']}" : '')
            . ($a['client_tz'] !== '' ? "\nClient time zone: {$a['client_tz']}" : '');
        if ($pending) {
            $text .= "\n\nApprove: " . self::actionUrl($a, 'approve') . "\nReject: " . self::actionUrl($a, 'reject');
        }
        $text .= "\n\nAll bookings: " . site_url('/admin/');
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.5;color:#011031">'
            . nl2br(h(preg_replace('/\n\n(Approve|All bookings).*/s', '', $text)))
            . ($pending ? '<p style="margin:24px 0"><a href="' . h(self::actionUrl($a, 'approve')) . '" style="background:#011031;color:#fff;padding:12px 28px;text-decoration:none;font-weight:bold;display:inline-block;margin-right:10px">APPROVE</a>'
                . '<a href="' . h(self::actionUrl($a, 'reject')) . '" style="background:#5c6c9f;color:#fff;padding:12px 28px;text-decoration:none;font-weight:bold;display:inline-block">REJECT</a></p>' : '')
            . '<p><a href="' . h(site_url('/admin/')) . '">Open the bookings admin</a></p></div>';
        Mailer::send((string)config('staff.email'), "New booking: {$a['service_name']} – " . fmt_date((int)$a['start_utc']) . ' ' . fmt_time((int)$a['start_utc']), $text, $html, $a['email']);
    }

    public static function statusChanged(array $a): void
    {
        if ($a['status'] === 'approved') {
            $text = "Dear {$a['first_name']},\n\nGood news – your booking has been confirmed.\n\n" . self::details($a) . self::sign_off();
            Mailer::send($a['email'], 'Your booking is confirmed – englishchat.co.uk', $text, null, config('staff.email'));
        } elseif ($a['status'] === 'rejected' || $a['status'] === 'cancelled') {
            $text = "Dear {$a['first_name']},\n\nUnfortunately your booking below could not be accepted"
                . ($a['payment_status'] === 'paid' ? ' – your payment will be refunded' : '') . ".\n\n" . self::details($a)
                . "\n\nPlease choose another time at " . site_url('/#booking') . " or get in touch and I will help you find a time that suits you." . self::sign_off();
            Mailer::send($a['email'], 'Your booking could not be accepted – englishchat.co.uk', $text, null, config('staff.email'));
        }
    }
}
