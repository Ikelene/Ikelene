<?php
declare(strict_types=1);

/** Tiny Stripe REST client (PaymentIntents + webhook signature check). */
final class Stripe
{
    public static function enabled(): bool
    {
        $driver = config('payments.card', 'off');
        if ($driver === 'mock') return is_dev();
        return $driver === 'stripe' && config('payments.stripe_secret_key') && config('payments.stripe_publishable_key');
    }

    public static function isMock(): bool
    {
        return config('payments.card') === 'mock' && is_dev();
    }

    public static function request(string $method, string $path, array $params = []): array
    {
        $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/') . ($method === 'GET' && $params ? '?' . http_build_query($params) : ''));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => config('payments.stripe_secret_key') . ':',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
        ]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Stripe request failed: ' . $err);
        $data = json_decode((string)$raw, true) ?: [];
        if ($code >= 400) throw new RuntimeException($data['error']['message'] ?? "Stripe HTTP $code");
        return $data;
    }

    public static function createIntent(array $a): array
    {
        return self::request('POST', 'payment_intents', [
            'amount' => (int)round((float)$a['price'] * 100),
            'currency' => strtolower((string)config('booking.currency', 'GBP')),
            'description' => $a['service_name'] . ' – ' . fmt_date((int)$a['start_utc']) . ' ' . fmt_time((int)$a['start_utc']),
            'receipt_email' => $a['email'],
            'payment_method_types[]' => 'card',
            'metadata[appointment_id]' => (string)$a['id'],
        ]);
    }

    public static function retrieveIntent(string $id): array
    {
        return self::request('GET', 'payment_intents/' . rawurlencode($id));
    }

    /** Verifies the Stripe-Signature header; returns the decoded event or null. */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300): ?array
    {
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int)($parts['t'][0] ?? 0);
        if (!$t || abs(time() - $t) > $tolerance) return null;
        $expected = hash_hmac('sha256', "$t.$payload", $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) return json_decode($payload, true);
        }
        return null;
    }
}
