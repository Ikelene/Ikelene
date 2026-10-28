<?php
declare(strict_types=1);
// Stripe webhook (payment_intent.succeeded / payment_failed). Point a webhook
// at https://englishchat.co.uk/api/stripe-webhook.php and put its signing
// secret in config.php -> payments.stripe_webhook_secret.
require __DIR__ . '/_init.php';

$secret = (string)config('payments.stripe_webhook_secret');
$payload = file_get_contents('php://input') ?: '';
$event = $secret !== '' ? Stripe::verifyWebhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret) : null;
if (!$event) json_out(['error' => 'Invalid signature'], 400);

$intent = $event['data']['object'] ?? [];
$a = Bookings::find((int)($intent['metadata']['appointment_id'] ?? 0));
if ($a && ($a['payment_ref'] ?? '') === ($intent['id'] ?? null)) {
    if ($event['type'] === 'payment_intent.succeeded') {
        Bookings::markPaid($a, $intent['id']);
    } elseif ($event['type'] === 'payment_intent.payment_failed' && $a['payment_status'] !== 'paid') {
        Bookings::setPaymentStatus($a, 'failed');
    }
}
json_out(['received' => true]);
