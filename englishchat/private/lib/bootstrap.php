<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the API endpoints and the admin panel.
 * Loads config, opens the SQLite database (creating the schema on first run)
 * and provides small helpers.
 */

define('EC_PRIVATE', dirname(__DIR__));
define('EC_DATA', EC_PRIVATE . '/data');

function config(?string $path = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = is_file(EC_PRIVATE . '/config.php') ? EC_PRIVATE . '/config.php' : EC_PRIVATE . '/config.sample.php';
        $config = require $file;
    }
    if ($path === null) return $config;
    $value = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

date_default_timezone_set(config('timezone', 'Europe/London'));

function is_dev(): bool
{
    return config('env') === 'development';
}

function tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(config('timezone', 'Europe/London'));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(EC_DATA)) mkdir(EC_DATA, 0770, true);
    $pdo = new PDO('sqlite:' . EC_DATA . '/englishchat.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version >= 1) return;
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            service_id INTEGER NOT NULL,
            service_name TEXT NOT NULL,
            start_utc INTEGER NOT NULL,
            end_utc INTEGER NOT NULL,
            price REAL NOT NULL DEFAULT 0,
            status TEXT NOT NULL,               -- awaiting_payment | pending | approved | rejected | cancelled
            payment_method TEXT NOT NULL,       -- free | local | card
            payment_status TEXT NOT NULL,       -- none | unpaid | paid | failed | refunded
            payment_ref TEXT,
            hold_expires_utc INTEGER,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            phone TEXT NOT NULL,
            email TEXT NOT NULL,
            notes TEXT NOT NULL DEFAULT '',
            client_tz TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            created_utc INTEGER NOT NULL,
            updated_utc INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_appointments_start ON appointments(start_utc);
        CREATE TABLE IF NOT EXISTS days_off (
            day TEXT PRIMARY KEY,               -- Y-m-d (Europe/London)
            note TEXT NOT NULL DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            message TEXT NOT NULL,
            mail_status TEXT NOT NULL,
            ip TEXT NOT NULL DEFAULT '',
            created_utc INTEGER NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS hits (
            bucket TEXT NOT NULL,
            at_utc INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_hits ON hits(bucket, at_utc);
        SQL);
    $ins = $pdo->prepare('INSERT OR IGNORE INTO days_off(day, note) VALUES (?, ?)');
    foreach (config('booking.seed_days_off', []) as $day) $ins->execute([$day, '']);
    $pdo->exec('PRAGMA user_version = 1');
}

/* ---------- HTTP helpers ---------- */

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_in(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Simple sliding-window rate limit. Returns false when the limit is exceeded. */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    if (is_dev()) $max *= 50;   // keep repeated local test runs from tripping it
    $key = $bucket . ':' . client_ip();
    $now = time();
    $pdo = db();
    $pdo->prepare('DELETE FROM hits WHERE at_utc < ?')->execute([$now - 86400]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE bucket = ? AND at_utc > ?');
    $st->execute([$key, $now - $windowSeconds]);
    if ((int)$st->fetchColumn() >= $max) return false;
    $pdo->prepare('INSERT INTO hits(bucket, at_utc) VALUES (?, ?)')->execute([$key, $now]);
    return true;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_url(string $path = '/'): string
{
    return rtrim((string)config('site_url'), '/') . $path;
}

function money(float $amount): string
{
    return config('booking.currency_symbol', '£') . number_format($amount, 2);
}

function local_dt(int $utc): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . $utc))->setTimezone(tz());
}

/** "October 29, 2026" / "4:15 pm" – the formats the Bookly form used. */
function fmt_date(int $utc): string { return local_dt($utc)->format('F j, Y'); }
function fmt_time(int $utc): string { return local_dt($utc)->format('g:i a'); }

function sign(string $data): string
{
    return hash_hmac('sha256', $data, (string)config('secret'));
}

require_once __DIR__ . '/Availability.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/Stripe.php';
require_once __DIR__ . '/Bookings.php';
