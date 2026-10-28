<?php
/**
 * englishchat.co.uk configuration.
 *
 * Copy this file to config.php (same folder) and fill in the secrets.
 * config.php is git-ignored; if it is missing this sample is used, which is
 * only suitable for local development.
 *
 * Every value below was taken from the live WordPress/Bookly setup.
 */
return [
    // 'development' shows the test card form and writes e-mails to the
    // outbox (private/data/mail/) instead of sending them.
    'env' => 'development',

    // Public URL of the site, no trailing slash. Used in e-mail links.
    'site_url' => 'http://127.0.0.1:8080',
    'site_name' => 'englishchat.co.uk',
    'timezone' => 'Europe/London',

    // Long random string – signs the approve/reject links sent to Louise.
    // Generate with:  php -r "echo bin2hex(random_bytes(32));"
    'secret' => 'dev-only-secret-change-me',

    // Admin panel (/admin/). Generate a hash with:
    //   php -r "echo password_hash('your password', PASSWORD_DEFAULT);"
    // Dev default password is: englishchat
    'admin_password_hash' => '$2y$10$TB4aotLNl86ewExDvgABGuNipZoqNR/ybnCdHX4cTIVSOE0zhm5Ae',

    'staff' => [
        'name' => 'Louise',
        'email' => 'Louise@englishchat.co.uk',
        'phone' => '+44 7470 373623',
    ],

    'booking' => [
        'currency' => 'GBP',
        'currency_symbol' => '£',
        'slot_step_minutes' => 15,        // Bookly "time slot length"
        'min_hours_before' => 24,         // minimum time requirement prior to booking
        'max_days_ahead' => 365,          // how far ahead bookings are allowed
        'hold_minutes' => 15,             // how long a slot is held while paying by card
        // Status of a new booking. 'pending' = Louise approves/rejects it from
        // the e-mail (which lands on /confirm-appointment/ or /reject-appointment/).
        'default_status' => 'pending',
        // Working hours (Europe/London). 1 = Monday … 7 = Sunday.
        'schedule' => [
            1 => [['16:00', '21:00']],
            2 => [['16:00', '21:00']],
            3 => [['16:00', '21:00']],
            4 => [['16:00', '21:00']],
            5 => [['09:00', '17:00']],
            6 => [],
            7 => [],
        ],
        // Days off seeded into the database on first run (Y-m-d). Manage them in /admin/.
        'seed_days_off' => [],
        'services' => [
            // id => name, duration (minutes), price
            2 => ['name' => 'Individual lesson - online', 'duration' => 60, 'price' => 30],
            4 => ['name' => 'Lesson for two - online', 'duration' => 60, 'price' => 30],
            5 => ['name' => 'Block of 6 - 1 hour lessons', 'duration' => 60, 'price' => 150],
            6 => ['name' => 'Free consultation - online', 'duration' => 30, 'price' => 0],
            7 => ['name' => 'Phonics - primary age', 'duration' => 60, 'price' => 20],
            8 => ['name' => 'English lesson - Bark', 'duration' => 60, 'price' => 25],
        ],
    ],

    'payments' => [
        // "I will pay locally" option on the payment step.
        'local' => false,
        // Card payments. driver: 'stripe' (real Stripe, test or live keys),
        // 'mock' (development only: fake card form, 4242 4242 4242 4242 succeeds),
        // or 'off'.
        'card' => 'mock',
        'stripe_publishable_key' => '',   // pk_live_… / pk_test_…
        'stripe_secret_key' => '',        // sk_live_… / sk_test_…
        'stripe_webhook_secret' => '',    // whsec_… (optional, recommended)
    ],

    'mail' => [
        // 'log' = write to private/data/mail (dev), 'mail' = PHP mail(),
        // 'smtp' = send through an SMTP server (recommended in production).
        'driver' => 'log',
        'from_email' => 'noreply@englishchat.co.uk',
        'from_name' => 'englishchat.co.uk',
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',        // 'tls' (STARTTLS), 'ssl' or ''
            'username' => '',
            'password' => '',
        ],
    ],

    // Google reCAPTCHA v3 used by the contact form (same keys as the old
    // Contact Form 7 integration). Leave empty to disable.
    'recaptcha' => [
        'site_key' => '',
        'secret_key' => '',
        'min_score' => 0.5,
    ],

    // Where contact form messages are sent.
    'contact_to' => 'Louise@englishchat.co.uk',
];
