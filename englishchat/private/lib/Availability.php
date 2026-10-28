<?php
declare(strict_types=1);

/**
 * Time-slot generation, matching how the Bookly form behaved:
 *  - slots start every `slot_step_minutes` inside the weekly working hours
 *  - a slot must fit completely inside a working interval
 *  - earliest start = now + `min_hours_before`
 *  - nothing beyond `max_days_ahead` days from today
 *  - days off and existing bookings (incl. unexpired card-payment holds) block slots
 * All instants are UNIX timestamps (UTC); working hours are Europe/London.
 */
final class Availability
{
    public static function services(): array
    {
        $out = [];
        foreach (config('booking.services', []) as $id => $s) {
            $out[] = [
                'id' => (int)$id,
                'name' => $s['name'],
                'duration' => (int)$s['duration'],
                'price' => (float)$s['price'],
            ];
        }
        return $out;
    }

    public static function service(int $id): ?array
    {
        foreach (self::services() as $s) if ($s['id'] === $id) return $s;
        return null;
    }

    public static function earliestUtc(?int $now = null): int
    {
        return ($now ?? time()) + (int)config('booking.min_hours_before', 24) * 3600;
    }

    /** Last bookable local date (Y-m-d). */
    public static function maxDate(?int $now = null): string
    {
        $today = local_dt($now ?? time())->setTime(0, 0);
        return $today->modify('+' . (int)config('booking.max_days_ahead', 365) . ' days')->format('Y-m-d');
    }

    public static function minDate(?int $now = null): string
    {
        return local_dt(self::earliestUtc($now))->format('Y-m-d');
    }

    public static function daysOff(): array
    {
        return db()->query('SELECT day FROM days_off')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Busy intervals [start, end) overlapping the given range. */
    private static function busy(int $fromUtc, int $toUtc, ?int $ignoreId = null): array
    {
        $st = db()->prepare(
            "SELECT id, start_utc, end_utc FROM appointments
             WHERE start_utc < :to AND end_utc > :from
               AND (status IN ('pending', 'approved')
                    OR (status = 'awaiting_payment' AND hold_expires_utc > :now))"
        );
        $st->execute([':from' => $fromUtc, ':to' => $toUtc, ':now' => time()]);
        $rows = [];
        foreach ($st as $r) {
            if ($ignoreId !== null && (int)$r['id'] === $ignoreId) continue;
            $rows[] = [(int)$r['start_utc'], (int)$r['end_utc']];
        }
        return $rows;
    }

    /**
     * All available start times (UTC) for a service in [fromUtc, toUtc).
     * @return int[]
     */
    public static function slots(int $serviceId, int $fromUtc, int $toUtc, ?int $now = null, ?int $ignoreAppointmentId = null): array
    {
        $service = self::service($serviceId);
        if (!$service || $toUtc <= $fromUtc) return [];
        $now ??= time();
        $duration = $service['duration'] * 60;
        $step = max(5, (int)config('booking.slot_step_minutes', 15)) * 60;
        $earliest = self::earliestUtc($now);
        $minDate = self::minDate($now);
        $maxDate = self::maxDate($now);
        $daysOff = array_flip(self::daysOff());
        $schedule = config('booking.schedule', []);
        $busy = self::busy($fromUtc - 86400, $toUtc + 86400, $ignoreAppointmentId);

        $slots = [];
        $day = local_dt($fromUtc)->setTime(0, 0)->modify('-1 day');
        $lastDay = local_dt($toUtc)->setTime(0, 0)->modify('+1 day');
        for (; $day <= $lastDay; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($date < $minDate || $date > $maxDate || isset($daysOff[$date])) continue;
            foreach ($schedule[(int)$day->format('N')] ?? [] as [$open, $close]) {
                [$oh, $om] = array_map('intval', explode(':', $open));
                [$ch, $cm] = array_map('intval', explode(':', $close));
                $start = $day->setTime($oh, $om)->getTimestamp();
                $end = $day->setTime($ch, $cm)->getTimestamp();
                for ($t = $start; $t + $duration <= $end; $t += $step) {
                    if ($t < $earliest || $t < $fromUtc || $t >= $toUtc) continue;
                    foreach ($busy as [$bs, $be]) {
                        if ($t < $be && $t + $duration > $bs) continue 2;
                    }
                    $slots[] = $t;
                }
            }
        }
        sort($slots);
        return array_values(array_unique($slots));
    }

    public static function isAvailable(int $serviceId, int $startUtc, ?int $ignoreAppointmentId = null): bool
    {
        return in_array($startUtc, self::slots($serviceId, $startUtc, $startUtc + 1, null, $ignoreAppointmentId), true);
    }
}
