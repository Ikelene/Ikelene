<?php
declare(strict_types=1);
/**
 * Contact form endpoint (replaces Contact Form 7). Same validation rules and
 * messages as the old form; responses mirror CF7's statuses:
 *   mail_sent | validation_failed | mail_failed | spam
 */
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['status' => 'error'], 405);
$in = json_in();

const MSG = [
    'mail_sent' => 'Thank you for your message. It has been sent.',
    'mail_failed' => 'There was an error trying to send your message. Please try again later.',
    'validation_failed' => 'One or more fields have an error. Please check and try again.',
    'spam' => 'There was an error trying to send your message. Please try again later.',
];

$name = trim((string)($in['your-name'] ?? ''));
$email = trim((string)($in['your-email'] ?? ''));
$message = trim((string)($in['your-message'] ?? ''));

$invalid = [];
foreach (['your-name' => [$name, 400], 'your-email' => [$email, 400], 'your-message' => [$message, 2000]] as $field => [$value, $max]) {
    if ($value === '') $invalid[] = ['field' => $field, 'message' => 'The field is required.'];
    elseif (mb_strlen($value) > $max) $invalid[] = ['field' => $field, 'message' => 'The field is too long.'];
    elseif ($field === 'your-email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) $invalid[] = ['field' => $field, 'message' => 'The e-mail address entered is invalid.'];
}
if ($invalid) json_out(['status' => 'validation_failed', 'message' => MSG['validation_failed'], 'invalid_fields' => $invalid]);

// Spam checks: honeypot, rate limit, reCAPTCHA v3 (when configured).
if (trim((string)($in['website'] ?? '')) !== '' || !rate_limit('contact', 5, 600)) {
    json_out(['status' => 'spam', 'message' => MSG['spam']]);
}
$secret = (string)config('recaptcha.secret_key');
if ($secret !== '') {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POSTFIELDS => http_build_query([
        'secret' => $secret, 'response' => (string)($in['recaptcha'] ?? ''), 'remoteip' => client_ip(),
    ])]);
    $res = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    if (empty($res['success']) || ($res['score'] ?? 0) < (float)config('recaptcha.min_score', 0.5)) {
        json_out(['status' => 'spam', 'message' => MSG['spam']]);
    }
}

$text = "From: $name <$email>\nSubject: Message from englishchat.co.uk\n\nMessage Body:\n$message\n\n-- \nThis e-mail was sent from the contact form on englishchat.co.uk (" . site_url('/') . ')';
$sent = Mailer::send((string)config('contact_to'), 'englishchat.co.uk "Message from ' . $name . '"', $text, null, $email);
db()->prepare('INSERT INTO messages(name, email, message, mail_status, ip, created_utc) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$name, $email, $message, $sent ? 'sent' : 'failed', client_ip(), time()]);

json_out($sent ? ['status' => 'mail_sent', 'message' => MSG['mail_sent']] : ['status' => 'mail_failed', 'message' => MSG['mail_failed']]);
