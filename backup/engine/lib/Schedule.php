<?php
/*
 * poste.io-addons, backup add-on: when a job is due.
 *
 * A schedule has a frequency (every hour, day, week, month) and a time. Its
 * "slot" is the most recent scheduled time that is not in the future. A job is
 * due when its slot is newer than the slot of its last run: so a run missed
 * because the container was stopped happens as soon as it is back (once, not
 * once per missed slot), and a job never runs twice for the same slot.
 */

namespace PosteBackup;

final class Schedule
{
    /**
     * The time zone of the schedules: the one chosen in the admin (settings of
     * the add-on), otherwise the container's TZ (docker run -e TZ=...), read
     * where s6 keeps the container's environment (cron starts the engine with
     * a minimal environment and the admin runs in php-fpm, so both read it from
     * the same file), then /etc/timezone, otherwise UTC.
     */
    public static function zone(string $chosen = ''): \DateTimeZone
    {
        if ($chosen !== '' && self::isZone($chosen)) {
            return new \DateTimeZone($chosen);
        }
        return self::containerZone();
    }

    /** The container's time zone (TZ, then /etc/timezone), UTC otherwise. */
    public static function containerZone(): \DateTimeZone
    {
        $candidates = [getenv('TZ')];
        foreach (['/var/run/s6/container_environment/TZ', '/etc/timezone'] as $f) {
            if (is_file($f) && !is_link($f) && filesize($f) < 100) {
                $candidates[] = trim((string) file_get_contents($f));
            }
        }
        foreach ($candidates as $name) {
            if (is_string($name) && $name !== '' && self::isZone($name)) {
                return new \DateTimeZone($name);
            }
        }
        return new \DateTimeZone('UTC');
    }

    /** A time zone name PHP knows (also the old ones, like Etc/UTC). */
    public static function isZone($name): bool
    {
        return is_string($name) && in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /** Most recent scheduled time not after $now (a Unix time). */
    public static function lastSlot(array $s, int $now, \DateTimeZone $tz): int
    {
        $t = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        switch ($s['every']) {
            case 'hour':
                $slot = $t->setTime((int) $t->format('G'), $s['minute']);
                if ($slot->getTimestamp() > $now) {
                    $slot = $slot->modify('-1 hour');
                }
                return $slot->getTimestamp();
            case 'day':
                $slot = $t->setTime($s['hour'], $s['minute']);
                if ($slot->getTimestamp() > $now) {
                    $slot = $slot->modify('-1 day')->setTime($s['hour'], $s['minute']);
                }
                return $slot->getTimestamp();
            case 'week':
                // ISO weekday: 1 = Monday ... 7 = Sunday
                $back = ((int) $t->format('N') - $s['weekday'] + 7) % 7;
                $slot = $t->modify("-$back days")->setTime($s['hour'], $s['minute']);
                if ($slot->getTimestamp() > $now) {
                    $slot = $slot->modify('-7 days')->setTime($s['hour'], $s['minute']);
                }
                return $slot->getTimestamp();
            case 'month':
                // days 1-28 only: every month has them
                $slot = $t->setDate((int) $t->format('Y'), (int) $t->format('n'), $s['monthday'])->setTime($s['hour'], $s['minute']);
                if ($slot->getTimestamp() > $now) {
                    $prev = $t->modify('first day of last month');
                    $slot = $prev->setDate((int) $prev->format('Y'), (int) $prev->format('n'), $s['monthday'])->setTime($s['hour'], $s['minute']);
                }
                return $slot->getTimestamp();
        }
        throw new \InvalidArgumentException('unknown frequency');
    }

    /**
     * Is the job due? $lastSlot: the slot of its last run (0 = never run).
     * A new job does not run for a slot older than its creation.
     */
    public static function isDue(array $job, int $lastSlot, int $now, \DateTimeZone $tz): bool
    {
        if (!$job['enabled']) {
            return false;
        }
        $slot = self::lastSlot($job['schedule'], $now, $tz);
        return $slot > $lastSlot && $slot >= $job['created'];
    }

    /** The next scheduled time after $now. */
    public static function nextSlot(array $s, int $now, \DateTimeZone $tz): int
    {
        // the slot of the moment one period later is the next slot; a DST change can
        // make it the current one again, hence a few steps
        $step = ['hour' => 3600, 'day' => 86400, 'week' => 7 * 86400, 'month' => 31 * 86400][$s['every']];
        for ($i = 1; $i <= 3; $i++) {
            $slot = self::lastSlot($s, $now + $i * $step, $tz);
            if ($slot > $now) {
                return $slot;
            }
        }
        throw new \RuntimeException('no next slot');
    }
}
