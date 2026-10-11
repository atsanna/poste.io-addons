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
    /** The server's time zone (Poste.io sets /etc/localtime from TZ). */
    public static function zone(): \DateTimeZone
    {
        $tz = getenv('TZ');
        if (is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            return new \DateTimeZone($tz);
        }
        if (is_file('/etc/timezone')) {
            $name = trim((string) file_get_contents('/etc/timezone'));
            if (in_array($name, \DateTimeZone::listIdentifiers(), true)) {
                return new \DateTimeZone($name);
            }
        }
        return new \DateTimeZone('UTC');
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
