<?php
/*
 * Unit checks of the backup add-on's schedule and settings validation, run by
 * tests/test_backup.py inside the test container (php backup_unit.php).
 * Prints PASS/FAIL lines like the other tests; exit code = number of failures.
 */
require '/usr/local/lib/poste-backup/lib/Config.php';
require '/usr/local/lib/poste-backup/lib/Schedule.php';
require '/usr/local/lib/poste-backup/lib/Store.php';

use PosteBackup\Config;
use PosteBackup\Schedule;

$fails = 0;
function check(string $name, bool $ok, $found): void
{
    global $fails;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name  (" . (is_string($found) ? $found : json_encode($found)) . ")\n";
    if (!$ok) {
        $fails++;
    }
}

$utc = new DateTimeZone('UTC');
$rome = new DateTimeZone('Europe/Rome');
$at = function (string $s, DateTimeZone $tz): int {
    return (new DateTimeImmutable($s, $tz))->getTimestamp();
};
$fmt = function (int $t, DateTimeZone $tz): string {
    return (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d H:i D');
};
$sched = function (string $every, int $minute, int $hour = 0, int $weekday = 1, int $monthday = 1): array {
    return compact('every', 'minute', 'hour', 'weekday', 'monthday');
};

// --- last slot
$cases = [
    ['hour, before the minute', $sched('hour', 30), '2026-10-11 10:10', '2026-10-11 09:30'],
    ['hour, after the minute', $sched('hour', 30), '2026-10-11 10:40', '2026-10-11 10:30'],
    ['hour, exactly at the minute', $sched('hour', 30), '2026-10-11 10:30', '2026-10-11 10:30'],
    ['day, before the time', $sched('day', 0, 2), '2026-10-11 01:59', '2026-10-10 02:00'],
    ['day, after the time', $sched('day', 0, 2), '2026-10-11 02:01', '2026-10-11 02:00'],
    ['week (Monday), on Sunday', $sched('week', 0, 3, 1), '2026-10-11 12:00', '2026-10-05 03:00'],
    ['week (Monday), Monday before the time', $sched('week', 0, 3, 1), '2026-10-12 02:00', '2026-10-05 03:00'],
    ['week (Monday), Monday after the time', $sched('week', 0, 3, 1), '2026-10-12 03:00', '2026-10-12 03:00'],
    ['week (Sunday = 7)', $sched('week', 15, 4, 7), '2026-10-11 12:00', '2026-10-11 04:15'],
    ['month, before the day', $sched('month', 0, 1, 1, 15), '2026-10-11 12:00', '2026-09-15 01:00'],
    ['month, after the day', $sched('month', 0, 1, 1, 5), '2026-10-11 12:00', '2026-10-05 01:00'],
    ['month, January to December of the year before', $sched('month', 0, 1, 1, 28), '2026-01-10 00:00', '2025-12-28 01:00'],
    ['month, end of January (day 28)', $sched('month', 0, 1, 1, 28), '2026-01-31 23:00', '2026-01-28 01:00'],
    ['month, 1 March, day 28 of February', $sched('month', 0, 1, 1, 28), '2026-03-01 00:30', '2026-02-28 01:00'],
];
foreach ($cases as [$name, $s, $now, $want]) {
    $got = $fmt(Schedule::lastSlot($s, $at($now, $utc), $utc), $utc);
    check("last slot: $name", substr($got, 0, 16) === $want, $got);
}

// --- daylight saving time (Europe/Rome: 29 Mar 2026 02:00 -> 03:00, 25 Oct 2026 03:00 -> 02:00)
$got = $fmt(Schedule::lastSlot($sched('day', 0, 2), $at('2026-03-29 12:00', $rome), $rome), $rome);
check('DST start: a daily 02:00 still has a slot that day (02:00 does not exist: 03:00)', substr($got, 0, 10) === '2026-03-29', $got);
$got = $fmt(Schedule::lastSlot($sched('day', 30, 2), $at('2026-10-25 12:00', $rome), $rome), $rome);
check('DST end: a daily 02:30 has one slot that day', substr($got, 0, 16) === '2026-10-25 02:30', $got);

// --- due
$job = ['enabled' => true, 'schedule' => $sched('day', 0, 2), 'created' => $at('2026-10-01 00:00', $utc)];
$now = $at('2026-10-11 09:00', $utc);
$slot = Schedule::lastSlot($job['schedule'], $now, $utc);
check('due: never run', Schedule::isDue($job, 0, $now, $utc), $slot);
check('due: not again for the same slot', !Schedule::isDue($job, $slot, $now, $utc), $slot);
check('due: a missed slot runs once when back', Schedule::isDue($job, $slot - 86400 * 3, $now, $utc), $slot);
check('due: disabled job never', !Schedule::isDue(['enabled' => false] + $job, 0, $now, $utc), 'disabled');
$fresh = ['created' => $at('2026-10-11 08:00', $utc)] + $job;
check('due: a job created after the slot waits for the next one', !Schedule::isDue($fresh, 0, $now, $utc), 'created 08:00, slot 02:00');

// --- next slot
foreach ([
    ['hour', $sched('hour', 30), '2026-10-11 10:40', '2026-10-11 11:30'],
    ['day', $sched('day', 0, 2), '2026-10-11 02:00', '2026-10-12 02:00'],
    ['week', $sched('week', 0, 3, 1), '2026-10-11 12:00', '2026-10-12 03:00'],
    ['month', $sched('month', 0, 1, 1, 28), '2026-01-31 23:00', '2026-02-28 01:00'],
] as [$name, $s, $now, $want]) {
    $got = $fmt(Schedule::nextSlot($s, $at($now, $utc), $utc), $utc);
    check("next slot: $name", substr($got, 0, 16) === $want, $got);
}

// --- settings: what is accepted
$destOk = [
    'local' => ['name' => 'NAS export', 'type' => 'local', 'path' => '/backup/nfs'],
    'sftp' => ['name' => 'Server', 'type' => 'sftp', 'host' => 'backup.example.net', 'port' => 22, 'user' => 'poste',
               'auth' => 'password', 'password' => 'p a s s', 'folder' => 'srv/poste', 'hostkeys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMq']],
    'sftp-key' => ['name' => 'Server', 'type' => 'sftp', 'host' => '192.0.2.10', 'port' => 2222, 'user' => 'poste', 'auth' => 'key', 'folder' => 'backup', 'hostkeys' => []],
    'smb' => ['name' => 'NAS', 'type' => 'smb', 'host' => 'nas.example.lan', 'share' => 'backup', 'folder' => 'mail', 'user' => 'u', 'password' => 'x', 'domain' => ''],
    's3' => ['name' => 'Off-site', 'type' => 's3', 'endpoint' => 'https://s3.example.com', 'region' => '', 'bucket' => 'mail-backup',
             'folder' => 'poste', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'abc/def+ghi'],
];
foreach ($destOk as $what => $d) {
    [$v, $e] = Config::destination($d);
    check("destination accepted: $what", $v !== null && !$e, $e);
}

// --- settings: what is refused
$bad = [
    'local path outside the allowed roots' => ['type' => 'local', 'path' => '/data/backup'],
    'local path with ..' => ['type' => 'local', 'path' => '/backup/../data'],
    'local path relative' => ['type' => 'local', 'path' => 'backup'],
    'local path with a space' => ['type' => 'local', 'path' => '/backup/my disk'],
    'sftp host with a semicolon' => ['type' => 'sftp', 'host' => 'a;touch /tmp/x', 'port' => 22, 'user' => 'u', 'auth' => 'password', 'password' => 'x', 'folder' => '', 'hostkeys' => []],
    'sftp folder that looks like an option' => ['type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'auth' => 'password', 'password' => 'x', 'folder' => '-o/x', 'hostkeys' => []],
    'sftp folder with ..' => ['type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'auth' => 'password', 'password' => 'x', 'folder' => 'a/../..', 'hostkeys' => []],
    'sftp port as text' => ['type' => 'sftp', 'host' => 'h', 'port' => '22', 'user' => 'u', 'auth' => 'password', 'password' => 'x', 'folder' => '', 'hostkeys' => []],
    'sftp fake host key' => ['type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'auth' => 'key', 'folder' => '', 'hostkeys' => ["ssh-ed25519 AAAA\nevil"]],
    'sftp without a folder' => ['type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'auth' => 'password', 'password' => 'x', 'folder' => '', 'hostkeys' => []],
    'sftp unknown login' => ['type' => 'sftp', 'host' => 'h', 'port' => 22, 'user' => 'u', 'auth' => 'none', 'folder' => '', 'hostkeys' => []],
    'smb password with a newline' => ['type' => 'smb', 'host' => 'h', 'share' => 's', 'folder' => '', 'user' => 'u', 'password' => "a\nb", 'domain' => ''],
    'smb user with a space' => ['type' => 'smb', 'host' => 'h', 'share' => 's', 'folder' => '', 'user' => 'a b', 'password' => 'x', 'domain' => ''],
    's3 endpoint with a path' => ['type' => 's3', 'endpoint' => 'https://h/x?y', 'region' => '', 'bucket' => 'bkt', 'folder' => '', 'access_key' => 'a', 'secret_key' => 'b'],
    's3 bucket with capitals' => ['type' => 's3', 'endpoint' => 'https://h', 'region' => '', 'bucket' => 'Bucket', 'folder' => '', 'access_key' => 'a', 'secret_key' => 'b'],
    'unknown type' => ['type' => 'ftp'],
];
foreach ($bad as $what => $d) {
    [$v, $e] = Config::destination(['name' => 'x'] + $d);
    check("destination refused: $what", $v === null && $e, $e);
}

$dests = ['aaaaaaaaaaaa' => ['type' => 'local']];
$jobOk = ['name' => 'Whole server', 'enabled' => true, 'config' => true, 'mail' => 'all', 'mailboxes' => [],
          'schedule' => $sched('day', 0, 2), 'destinations' => ['aaaaaaaaaaaa'],
          'keep' => ['hourly' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 6], 'notify_success' => false, 'created' => 1];
[$v, $e] = Config::job($jobOk, $dests);
check('job accepted', $v !== null && !$e, $e);
foreach ([
    'nothing to back up' => ['config' => false, 'mail' => 'none'],
    'keep all zero' => ['keep' => ['hourly' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0]],
    'unknown destination' => ['destinations' => ['bbbbbbbbbbbb']],
    'destination id with a path' => ['destinations' => ['../../etc']],
    'mailbox that is not an address' => ['mail' => 'selected', 'mailboxes' => ['../../etc/passwd']],
    'minute out of range' => ['schedule' => $sched('hour', 60)],
    'day of the month 31' => ['schedule' => $sched('month', 0, 1, 1, 31)],
    'enabled as text' => ['enabled' => 'yes'],
] as $what => $change) {
    [$v, $e] = Config::job($change + $jobOk, $dests);
    check("job refused: $what", $v === null && $e, $e);
}

[$cfg, $err] = Config::validate(['destinations' => ['../evil' => $destOk['local'], 'cccccccccccc' => $destOk['local']], 'jobs' => []]);
check('whole settings: an entry with a bad id is left out, the good one stays', count($cfg['destinations']) === 1 && isset($cfg['destinations']['cccccccccccc']) && $err, $err);
[$cfg, $err] = Config::validate('not an object');
check('whole settings: not an object gives empty settings and an error', $cfg['destinations'] === [] && $err, $err);

// a message from a remote server that is not valid UTF-8 must not lose the state
$tmp = sys_get_temp_dir() . '/bu-' . bin2hex(random_bytes(4)) . '.json';
$ok = PosteBackup\Store::writeJson($tmp, ['error' => "bad \xC3\x28 bytes"], 0600);
$back = json_decode((string) @file_get_contents($tmp), true);
check('state with invalid UTF-8 in a message: still written, as valid JSON', $ok && is_array($back) && strpos($back['error'], 'bad') === 0, $back);
@unlink($tmp);

echo "FAILURES: $fails\n";
exit($fails);
