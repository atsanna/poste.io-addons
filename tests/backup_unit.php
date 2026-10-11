<?php
/*
 * Unit checks of the backup add-on's schedule and settings validation, run by
 * tests/test_backup.py inside the test container (php backup_unit.php).
 * Prints PASS/FAIL lines like the other tests; exit code = number of failures.
 */
require '/usr/local/lib/poste-backup/lib/Config.php';
require '/usr/local/lib/poste-backup/lib/Schedule.php';
require '/usr/local/lib/poste-backup/lib/Store.php';
require '/usr/local/lib/poste-backup/lib/Engine.php';
require '/usr/local/lib/poste-backup/lib/Messages.php';

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

// --- time zone of the schedules
$saved = getenv('TZ');
putenv('TZ=Europe/Rome');
check('time zone: the TZ of the container', Schedule::zone()->getName() === 'Europe/Rome', Schedule::zone()->getName());
putenv('TZ=Mars/Olympus');
check('time zone: an unknown TZ is not used (the next source, or UTC)', Schedule::zone()->getName() !== 'Mars/Olympus', Schedule::zone()->getName());
$saved === false ? putenv('TZ') : putenv("TZ=$saved");
check('time zone: the one chosen in the admin wins over the container\'s', Schedule::zone('Asia/Tokyo')->getName() === 'Asia/Tokyo', Schedule::zone('Asia/Tokyo')->getName());
check('time zone: a chosen zone that does not exist is not used', Schedule::zone('Mars/Olympus')->getName() === Schedule::containerZone()->getName(), Schedule::zone('Mars/Olympus')->getName());
[$c, $e] = Config::validate(['settings' => ['timezone' => 'Europe/Rome'], 'destinations' => [], 'jobs' => []]);
check('settings: a valid time zone is kept', $c['settings']['timezone'] === 'Europe/Rome' && !$e, [$c['settings'], $e]);
[$c, $e] = Config::validate(['settings' => ['timezone' => '../../etc/passwd'], 'destinations' => [], 'jobs' => []]);
check('settings: an invalid time zone is refused (the container\'s is used)', $c['settings']['timezone'] === '' && $e === ['time zone: not valid'], [$c['settings'], $e]);
[$c, $e] = Config::validate(['destinations' => [], 'jobs' => []]);
check('settings: none saved yet = the container\'s time zone', $c['settings']['timezone'] === '' && !$e, $c['settings']);
check('settings: none saved yet = logs 90 days, history 400 days', $c['settings']['log_days'] === 90 && $c['settings']['history_days'] === 400, $c['settings']);
[$c, $e] = Config::validate(['settings' => ['log_days' => 0, 'history_days' => '30'], 'destinations' => [], 'jobs' => []]);
check('settings: days out of range or not a number are refused (the defaults stay)',
      $c['settings']['log_days'] === 90 && $c['settings']['history_days'] === 400
      && $e === ['log days: a whole number from 1 to 3650', 'history days: a whole number from 1 to 3650'], [$c['settings'], $e]);

// --- restic's file errors and the result of a backup
// the line restic wrote on a mail server in use (a message moved to another
// folder while the backup ran), and one for a file it may not read
$vanishedLine = '{"message_type":"error","error":{"Op":"lstat","Path":"/data/domains/example.com/alice/Maildir/cur/1791727871.M663620P2102.mail.example.com,S=2026,W=2085:2,i","Err":2},"during":"archival","item":"/data/domains/example.com/alice/Maildir/cur/1791727871.M663620P2102.mail.example.com,S=2026,W=2085:2,i"}';
$deniedLine = '{"message_type":"error","error":{"Op":"open","Path":"/data/var/rspamd/x.map","Err":13},"during":"archival","item":"/data/var/rspamd/x.map"}';
check('restic error: a file that vanished', PosteBackup\Engine::fileError($vanishedLine) === 'vanished', PosteBackup\Engine::fileError($vanishedLine));
check('restic error: a file that could not be read', PosteBackup\Engine::fileError($deniedLine) === 'unreadable', PosteBackup\Engine::fileError($deniedLine));
check('restic: a progress line is not a file error', PosteBackup\Engine::fileError('{"message_type":"status","percent_done":0.5}') === null, 'null');
check('restic: a plain text line is not a file error', PosteBackup\Engine::fileError('Warning: at least one source file could not be read') === null, 'null');
$o = PosteBackup\Engine::outcome(3, 1, 0);
check('only vanished files: ok, with a note', $o['status'] === 'ok' && $o['error'] === null && strpos((string) $o['note'], '1 file(s) moved or deleted') === 0, $o);
$o = PosteBackup\Engine::outcome(3, 2, 1);
check('a file that could not be read: warning (and the note for the vanished ones)', $o['status'] === 'warning' && $o['error'] === '1 file(s) could not be read (see the log)' && $o['note'] !== null, $o);
$o = PosteBackup\Engine::outcome(3, 0, 0);
check('exit 3 with no file error understood: warning, never guessed fine', $o['status'] === 'warning' && $o['error'] === 'some files could not be read (see the log)', $o);
$o = PosteBackup\Engine::outcome(0, 0, 0);
check('exit 0: ok, no note', $o['status'] === 'ok' && $o['error'] === null && $o['note'] === null, $o);

// --- requests of the admin to the engine, validated again by the engine
$snap = str_repeat('ab', 32);
$choice = ['folders' => ['', '.Sent', '.A[1]*?'], 'name' => 'Restored 2026-10-11 02:00', 'parent' => 'Archive.2024',
           'since' => '2026-10-01', 'until' => '2026-10-07', 'from' => 'customer.com', 'subject' => ' invoice '];
$t = PosteBackup\Engine::task('restore', ['dest' => '0a1b2c3d4e5f', 'snapshot' => $snap, 'source' => 'alice@example.com', 'target' => 'Bob@Example.com'] + $choice);
check('restore request: valid, the target in lower case, the choices kept (texts trimmed)', $t === ['type' => 'restore', 'dest' => '0a1b2c3d4e5f',
      'snapshot' => $snap, 'source' => 'alice@example.com', 'target' => 'bob@example.com', 'folders' => ['', '.Sent', '.A[1]*?'],
      'name' => 'Restored 2026-10-11 02:00', 'parent' => 'Archive.2024', 'since' => '2026-10-01', 'until' => '2026-10-07',
      'from' => 'customer.com', 'subject' => 'invoice'], $t);
foreach ([['snapshot' => substr($snap, 0, 8)], ['snapshot' => strtoupper($snap)], ['source' => '../../etc@example.com'], ['source' => 'a/b@example.com'],
          ['source' => 'alice@../..'], ['target' => 'x'], ['dest' => '../x'], ['source' => ['alice@example.com']],
          ['folders' => []], ['folders' => ['..']], ['folders' => ['../x']], ['folders' => ['.a/b']], ['folders' => ['Sent']], ['folders' => '.Sent'],
          ['name' => 'a.b'], ['name' => 'a/b'], ['name' => 'a\\b'], ['name' => 'a*'], ['name' => 'a%'], ['name' => ' a'], ['name' => ''],
          ['name' => str_repeat('x', 101)], ['name' => 'inbox'], ['parent' => '.x'], ['parent' => 'x.'], ['parent' => 'a*'],
          ['since' => '2026-02-30'], ['until' => '10/07/2026'], ['since' => '2026-10-08', 'until' => '2026-10-07'],
          ['from' => str_repeat('x', 101)], ['subject' => "a\nb"]] as $bad) {
    $t = PosteBackup\Engine::task('restore', $bad + ['dest' => '0a1b2c3d4e5f', 'snapshot' => $snap, 'source' => 'alice@example.com', 'target' => 'bob@example.com'] + $choice);
    check('restore request refused: ' . json_encode($bad), !empty($t['invalid']) && count($t) === 2, $t);
}
$q = function (array $o) use ($choice) {
    return PosteBackup\Engine::restoreQuery(array_merge(PosteBackup\Engine::restoreOptions($choice)[0], $o));
};
check('filter: "until" includes its day (BEFORE the next one), the end of a month too',
      $q([]) === ['SINCE', '2026-10-01', 'BEFORE', '2026-10-08', 'FROM', 'customer.com', 'SUBJECT', 'invoice']
      && $q(['since' => '', 'until' => '2026-10-31', 'from' => '', 'subject' => '']) === ['BEFORE', '2026-11-01'], $q([]));
check('no filter: every message', $q(['since' => '', 'until' => '', 'from' => '', 'subject' => '']) === ['all'], 'all');
check('a folder name with glob characters is taken literally by restic', PosteBackup\Engine::globEscape('/d/.A[1]*?\\x')
      === '/d/.A\\[1\\]\\*\\?\\\\x', PosteBackup\Engine::globEscape('/d/.A[1]*?\\x'));
check('removal request: valid', PosteBackup\Engine::task('forget', ['dest' => '0a1b2c3d4e5f', 'job' => 'aaaaaaaaaaaa'])
      === ['type' => 'forget', 'dest' => '0a1b2c3d4e5f', 'job' => 'aaaaaaaaaaaa'], 'forget');
check('removal request refused: an invalid job id', !empty(PosteBackup\Engine::task('forget', ['dest' => '0a1b2c3d4e5f', 'job' => 'job:x'])['invalid']), 'invalid');
check('restore space: twice the mailbox plus 256 MB', PosteBackup\Engine::spaceNeeded(1073741824) === 2415919104
      && PosteBackup\Engine::enoughSpace(1073741824, 2415919104) && !PosteBackup\Engine::enoughSpace(1073741824, 2415919103), 'limits');
check('a request that is not JSON: refused', !empty(PosteBackup\Engine::task('restore', null)['invalid']), 'invalid');

// --- the messages shown in the admin: recognised, and translated in both languages
require_once '/opt/admin/vendor/autoload.php';
$tr = [];
foreach (['en', 'cs'] as $l) {
    $all = Symfony\Component\Yaml\Yaml::parseFile("/opt/admin/translations/Base/messages+intl-icu.$l.yml");
    $tr[$l] = $all['poste_backup'] ?? [];
}
$keys = array_column(PosteBackup\Messages::patterns(), 0);
foreach (['en', 'cs'] as $l) {
    $missing = array_diff($keys, array_keys($tr[$l]['msg'] ?? []));
    check("every message has a translation ($l)", !$missing, array_values($missing));
    $missing = array_diff(array_values(PosteBackup\Messages::FIELDS), array_keys($tr[$l]['fieldname'] ?? []));
    check("every field name has a translation ($l)", !$missing, array_values($missing));
}
// the English translation, filled in, must give back the message exactly as the
// code writes it: the admin in English shows what the logs say
$en = function (string $m) use (&$en, $tr): ?string {
    $hit = PosteBackup\Messages::match($m);
    if ($hit === null) {
        return strpos($m, '; ') !== false ? implode('; ', array_map($en, explode('; ', $m))) : null;
    }
    [$key, $params] = $hit;
    foreach ($params as $n => $v) {
        if ($n === 'list') {
            $params[$n] = $en($v);
        } elseif ($n === 'field') {
            $params[$n] = $tr['en']['fieldname'][PosteBackup\Messages::FIELDS[$v]];
        }
    }
    return MessageFormatter::formatMessage('en', $tr['en']['msg'][$key], $params);
};
$samples = [
    // Config
    'not an object', 'the settings file is not a JSON object', 'too many destinations', 'destination with an invalid id left out',
    'destination 0a1b2c3d4e5f: server: a host name or an IP address; port: a whole number from 1 to 65535',
    'too many jobs', 'job with an invalid id left out', 'job 0a1b2c3d4e5f: nothing to back up', 'unknown type',
    'login: password or key', 'folder: required for SFTP', 'mail: unknown choice', 'no mailbox selected', 'too many mailboxes',
    'invalid mailbox address', 'nothing to back up', 'schedule: unknown frequency', 'no destination', 'unknown destination',
    'keep: at least one value must be above 0 (otherwise nothing would ever be removed, or everything)',
    'name: 1 to 60 characters, no control characters', 'server: a host name or an IP address', 'folder: letters, digits, . _ - and /',
    'folder: no "." or ".." parts', 'folder: an absolute path under /' . implode(', /', Config::LOCAL_ROOTS), 'endpoint: https://host[:port]',
    'host keys: not valid', 'enabled: not true/false', 'day of the month: a whole number from 1 to 28', 'keep daily: a whole number from 0 to 1000',
    'access key: not valid', 'secret key: 1 to 256 characters, no control characters', 'password: 1 to 256 characters, no control characters',
    'time zone: not valid', 'log days: a whole number from 1 to 3650', 'history days: a whole number from 1 to 3650',
    // destinations and the test
    'the SSH key of this destination is missing (create it again in the admin)',
    "the server's SSH key has not been confirmed yet (Test the destination in the admin)", 'cannot write a temporary file',
    'cannot create a temporary folder', 'rclone obscure failed', '/backup/nfs does not exist in the container: mount a volume there',
    '/backup/nfs is not a mounted volume: the backup would stay inside the container',
    '/backup/nfs is on the same disk as /data: a backup there does not survive the loss of that disk',
    '/backup/nfs is not writable by the user mail (uid 8, gid 8): give the volume to that user', 'cannot lock the settings',
    'invalid server or port', 'the server did not answer with an SSH key (connection refused)', 'invalid id', 'ssh-keygen failed: no space',
    'the destination changed meanwhile', 'mounted volume, not on the disk of /data', 'free space: 2.2 TB of 18.9 TB', 'free space: 341.1 GB',
    'free space: not reported by S3', 'free space: not reported by the server', 'it already holds a backup repository',
    'no repository yet: it is created at the first run', 'reachable; it already holds a backup repository',
    'reachable, but the folder is not empty and is not a backup repository: choose an empty folder',
    'reachable; empty folder, the repository is created at the first run', 'reachable; the folder does not exist yet, it is created at the first run',
    'not reachable: couldn\'t connect SMB: response error: The attempted logon is invalid.', 'cannot write: permission denied',
    'write test: ok', 'write test: written, but the test file could not be removed',
    // a run
    'mailbox bob@example.com not found', 'none of the selected mailboxes exists', 'cannot create the staging folder',
    'copy of /data/users.db failed or is damaged', 'the encryption key is missing (/data/var/backup/settings/restic.key)',
    'cannot create the repository: Fatal: wrong password', 'backup failed: Fatal: unable to open repository',
    '3 file(s) could not be read (see the log)', 'some files could not be read (see the log)',
    '1 file(s) moved or deleted while the backup ran: the next backup has them',
    'backup done, but the old snapshots were not removed: repository is already locked',
    '/backup/nfs is not empty and is not a backup repository: nothing written there',
    'the folder is not empty and is not a backup repository: nothing written there',
    // snapshots and restore
    'the request is not valid', 'bob@example.com is not in this snapshot', 'cannot read the snapshot: Fatal: no matching ID found',
    'cannot read the snapshots: Fatal: wrong password or no key found', 'there are no backups on this destination yet',
    'cannot prepare the restore folder', 'restore failed: Fatal: There were 2 errors', 'bob@example.com has no mail in this snapshot',
    'import failed: Error: Mailbox INBOX: Permission denied', '3 message(s) restored into the folder "Restored 2026-10-11 02:00 (2)" of bob@example.com',
    'there were no backups of this job on this destination', '4 backup(s) of the job removed',
    'removing the backups failed: Fatal: repository is already locked',
    'not enough free space on /data: the restore needs about 2.3 GB, 1.1 GB are free',
    'choose at least one folder', 'too many folders', 'folder: not valid', 'folder name: 1 to 100 characters, without dots, slashes, * or %',
    'folder name: INBOX is the inbox itself', 'parent folder: not valid', 'date: YYYY-MM-DD', 'dates: the first one is after the second',
    'sender: up to 100 characters', 'subject: up to 100 characters', 'a chosen folder is not in this snapshot',
    'the folder Archive.2024 is not in bob@example.com', 'no message matched the choice: nothing restored', 'choose the mailbox to restore into',
    '7 message(s) restored into the folder "Archive / Restored 2026-10-11 02:00" of bob@example.com',
    // what the code writes, read from the code itself
    PosteBackup\Engine::outcome(3, 2, 1)['error'], PosteBackup\Engine::outcome(3, 2, 1)['note'],
];
foreach ([array_merge($destOk['local'], ['path' => '/data/x']), ['type' => 'sftp', 'host' => 'a;b', 'port' => 0, 'user' => 'a b', 'auth' => 'password', 'password' => "\n", 'folder' => '..', 'hostkeys' => ['x']],
          ['type' => 's3', 'endpoint' => 'ftp://x', 'region' => 'X', 'bucket' => 'B', 'folder' => '-x', 'access_key' => ' ', 'secret_key' => '']] as $d) {
    $samples = array_merge($samples, Config::destination(['name' => ''] + $d)[1]);
}
$samples = array_merge($samples, Config::job(['name' => 'x', 'enabled' => 'yes', 'config' => 1, 'mail' => 'x', 'schedule' => ['every' => 'x'],
    'destinations' => ['bbbbbbbbbbbb'], 'keep' => ['hourly' => -1], 'notify_success' => 'no', 'created' => 'x'], [])[1]);
$bad = [];
foreach (array_unique($samples) as $m) {
    $back = $en($m);
    if ($back !== $m) {
        $bad[] = [$m, $back];
    }
}
check('every message of the code is recognised and its English translation gives it back exactly (' . count(array_unique($samples)) . ' messages)', !$bad, $bad);
$cs = PosteBackup\Messages::match('free space: 2.2 TB of 18.9 TB');
check('a message is recognised with its parameters', $cs === ['test_free_total', ['free' => '2.2 TB', 'total' => '18.9 TB']], $cs);
check('a message that is not known is not recognised (shown as it is)', PosteBackup\Messages::match('something new') === null, 'null');
// every text of the add-on, in both languages, is a valid ICU message (a broken
// one would make the page fail): formatted with example values
$broken = [];
$walk = function (array $t, string $at, string $l) use (&$walk, &$broken) {
    foreach ($t as $k => $v) {
        if (is_array($v)) {
            $walk($v, "$at.$k", $l);
        } elseif (MessageFormatter::formatMessage($l, (string) $v, ['count' => 2, 'n' => 2]) === false) {
            $broken[] = "$l $at.$k";
        }
    }
};
foreach (['en', 'cs'] as $l) {
    $walk($tr[$l], 'poste_backup', $l);
}
check('every text of the add-on is a valid ICU message, in English and in Czech', !$broken, $broken);

echo "FAILURES: $fails\n";
exit($fails);
