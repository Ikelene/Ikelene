<?php
declare(strict_types=1);

/** Creating bookings and changing their status. */
final class Bookings
{
    public const STATUSES = ['awaiting_payment', 'pending', 'approved', 'rejected', 'cancelled'];

    /** Validates the details step. Returns [field => message] (empty when valid). */
    public static function validateDetails(array $in): array
    {
        $e = [];
        if (trim((string)($in['first_name'] ?? '')) === '') $e['first_name'] = 'Please tell us your first name';
        if (trim((string)($in['last_name'] ?? '')) === '') $e['last_name'] = 'Please tell us your last name';
        $phone = trim((string)($in['phone'] ?? ''));
        if ($phone === '') $e['phone'] = 'Please tell us your phone';
        elseif (!preg_match('/^\+?[0-9 ()\-.]{6,25}$/', $phone)) $e['phone'] = 'Invalid phone number';
        $email = trim((string)($in['email'] ?? ''));
        if ($email === '') $e['email'] = 'Please tell us your email';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e['email'] = 'Invalid email';
        if (empty($in['terms'])) $e['terms'] = 'You must accept our terms';
        foreach (['first_name' => 100, 'last_name' => 100, 'email' => 255, 'notes' => 2000] as $f => $max) {
            if (mb_strlen((string)($in[$f] ?? '')) > $max) $e[$f] = 'This field is too long';
        }
        return $e;
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM appointments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findByToken(int $id, string $token): ?array
    {
        $a = self::find($id);
        return $a && hash_equals($a['token'], $token) ? $a : null;
    }

    /**
     * Creates an appointment. $paymentMethod: free | local | card.
     * Card bookings start as 'awaiting_payment' (holding the slot) until paid.
     * Returns the appointment row, or throws DomainException with a user-facing message.
     */
    public static function create(array $in, string $paymentMethod): array
    {
        $service = Availability::service((int)($in['service_id'] ?? 0));
        if (!$service) throw new DomainException('Please select a service');
        $start = (int)($in['start'] ?? 0);
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');   // serialises concurrent bookings of the same slot
        try {
            if (!Availability::isAvailable($service['id'], $start)) {
                throw new DomainException('The selected time is not available anymore. Please, choose another time slot.');
            }
            $now = time();
            $price = (float)$service['price'];
            if ($price <= 0) $paymentMethod = 'free';
            $status = $paymentMethod === 'card' ? 'awaiting_payment' : (string)config('booking.default_status', 'pending');
            $row = [
                'token' => bin2hex(random_bytes(16)),
                'service_id' => $service['id'],
                'service_name' => $service['name'],
                'start_utc' => $start,
                'end_utc' => $start + $service['duration'] * 60,
                'price' => $price,
                'status' => $status,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentMethod === 'free' ? 'none' : 'unpaid',
                'hold_expires_utc' => $paymentMethod === 'card' ? $now + (int)config('booking.hold_minutes', 15) * 60 : null,
                'first_name' => trim((string)$in['first_name']),
                'last_name' => trim((string)$in['last_name']),
                'phone' => trim((string)$in['phone']),
                'email' => trim((string)$in['email']),
                'notes' => trim((string)($in['notes'] ?? '')),
                'client_tz' => substr(preg_replace('#[^A-Za-z0-9_/+\-]#', '', (string)($in['client_tz'] ?? '')), 0, 64),
                'ip' => client_ip(),
                'created_utc' => $now,
                'updated_utc' => $now,
            ];
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO appointments (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')->execute($row);
            $row['id'] = (int)$pdo->lastInsertId();
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        if ($paymentMethod !== 'card') Notifications::newBooking($row);
        return $row;
    }

    /** Marks a card booking as paid (idempotent) and sends the notifications. */
    public static function markPaid(array $a, string $ref): array
    {
        if ($a['payment_status'] === 'paid') return $a;
        $status = $a['status'] === 'awaiting_payment' ? (string)config('booking.default_status', 'pending') : $a['status'];
        db()->prepare('UPDATE appointments SET status = ?, payment_status = ?, payment_ref = ?, hold_expires_utc = NULL, updated_utc = ? WHERE id = ?')
            ->execute([$status, 'paid', $ref, time(), $a['id']]);
        $a = self::find((int)$a['id']);
        Notifications::newBooking($a);
        return $a;
    }

    public static function setStatus(array $a, string $status, bool $notify = true): array
    {
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Bad status');
        if ($a['status'] === $status) return $a;
        db()->prepare('UPDATE appointments SET status = ?, hold_expires_utc = NULL, updated_utc = ? WHERE id = ?')->execute([$status, time(), $a['id']]);
        $a = self::find((int)$a['id']);
        if ($notify) Notifications::statusChanged($a);
        return $a;
    }

    public static function setPaymentStatus(array $a, string $paymentStatus): array
    {
        db()->prepare('UPDATE appointments SET payment_status = ?, updated_utc = ? WHERE id = ?')->execute([$paymentStatus, time(), $a['id']]);
        return self::find((int)$a['id']);
    }

    /** Releases an unpaid card hold (customer went back or the payment failed for good). */
    public static function release(array $a): void
    {
        if ($a['status'] === 'awaiting_payment') {
            db()->prepare("UPDATE appointments SET status = 'cancelled', hold_expires_utc = NULL, updated_utc = ? WHERE id = ?")->execute([time(), $a['id']]);
        }
    }
}
