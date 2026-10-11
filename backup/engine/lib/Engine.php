<?php
/*
 * poste.io-addons, backup add-on: the engine (runs as the user "mail", from
 * cron: the same user as the admin, so nothing in the settings can make it do
 * more than the admin can already do).
 *
 * tick (every minute): settings validated again, requests of the admin, jobs
 * that are due; one at a time (a lock), so a long backup simply makes the next
 * ticks wait. A job:
 *   1. collects what to back up: consistent copies of the databases (checked
 *      with an integrity check), the configuration files, the mail;
 *   2. for each destination: checks it, creates the repository only where
 *      there is none (an empty folder), backs up, removes the old snapshots
 *      of this job according to its "keep" rules;
 *   3. records the result (state.json for the admin, one history line per
 *      destination for the statistics).
 * A destination that fails does not stop the others.
 */

namespace PosteBackup;

final class Engine
{
    public const HOST = 'poste';             // restic --host: fixed, the container name may change
    public const MAX_LIST = 16777216;        // restic prints a list of snapshots as one line: up to 16 MB
    public const MAX_FOLDERS = 2000;         // folders of a mailbox chosen for a restore
    private const TAG = 'poste-backup';

    private array $cfg;
    private array $state;
    private \DateTimeZone $tz;
    private int $progressSaved = 0;

    public function __construct()
    {
        $this->tz = Schedule::zone();
        date_default_timezone_set($this->tz->getName());
    }

    // ---------------------------------------------------------------- tick

    public function tick(int $now): string
    {
        $lock = @fopen(Store::LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'busy: another run is going on';
        }
        try {
            $this->load();
            $this->state['tick'] = $now;
            // a restore folder left by a run that died: emptied (nothing runs meanwhile, we hold the lock)
            if (is_dir(Store::RESTORE) && !is_link(Store::RESTORE)) {
                $this->emptyRestoreFolder();
                @rmdir(Store::RESTORE);
            }
            // logs and history are trimmed after each run, and once a day for a
            // server where nothing runs for a while
            if ($now - (int) ($this->state['housekeeping'] ?? 0) >= 86400) {
                $this->housekeeping($now);
                $this->state['housekeeping'] = $now;
            }
            $this->saveState();

            $runs = [];
            $tasks = [];
            // requests of the admin first: run now, restore a mailbox, remove the
            // backups of a job (in the order they were made)
            $queue = is_link(Store::QUEUE) ? [] : (@scandir(Store::QUEUE) ?: []);
            sort($queue);
            foreach ($queue as $f) {
                $path = Store::QUEUE . '/' . $f;
                if (preg_match('/^run-([a-f0-9]{12})-[a-f0-9]{8}\.json$/', $f, $m)) {
                    if (is_link($path) || !is_file($path)) {
                        continue;
                    }
                    @unlink($path);
                    if (isset($this->cfg['jobs'][$m[1]]) && !isset($runs[$m[1]])) {
                        $runs[$m[1]] = 'manual';
                    }
                } elseif (preg_match('/^\d{10}-(restore|forget)-([a-f0-9]{12})\.json$/', $f, $m)) {
                    $raw = Store::readJson($path);          // never a link, never too big
                    @unlink($path);
                    $tasks[$m[2]] = self::task($m[1], $raw);
                }
            }
            foreach ($tasks as $id => $t) {
                $this->runTask($id, $t);
            }
            foreach ($this->cfg['jobs'] as $id => $job) {
                $last = (int) ($this->state['jobs'][$id]['last_slot'] ?? 0);
                if (Schedule::isDue($job, $last, $now, $this->tz)) {
                    $runs[$id] = 'schedule';     // a scheduled run also covers a pending manual one
                }
            }
            foreach ($runs as $id => $why) {
                $this->runJob($id, $why, $why === 'schedule' ? Schedule::lastSlot($this->cfg['jobs'][$id]['schedule'], $now, $this->tz) : null);
            }
            return count($runs) . ' job(s) run' . ($tasks ? ', ' . count($tasks) . ' request(s) done' : '');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Runs one job now (command line), with the same lock as tick. */
    public function runNow(string $id): string
    {
        $lock = @fopen(Store::LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return 'busy: another run is going on';
        }
        try {
            $this->load();
            if (!isset($this->cfg['jobs'][$id])) {
                return 'no such job (or the job is not valid: see the admin)';
            }
            $r = $this->runJob($id, 'manual', null);
            return 'job ' . $id . ': ' . $r;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): void
    {
        $raw = Store::readJson(Store::CONFIG);
        [$this->cfg, $errors] = $raw === null ? [Config::empty(), []] : Config::validate($raw);
        // the time zone chosen in the admin, if any
        $this->tz = Schedule::zone($this->cfg['settings']['timezone']);
        date_default_timezone_set($this->tz->getName());
        $this->state = Store::readJson(Store::STATE) ?? [];
        $this->state += ['jobs' => [], 'running' => null, 'destinations' => [], 'tasks' => []];
        $this->state['config_errors'] = $errors;
        // forget jobs and destinations that no longer exist
        $this->state['jobs'] = array_intersect_key($this->state['jobs'], $this->cfg['jobs']);
        $this->state['destinations'] = array_intersect_key((array) $this->state['destinations'], $this->cfg['destinations']);
    }

    private function saveState(): void
    {
        if (!Store::writeJson(Store::STATE, $this->state, 0640)) {
            // the admin would keep showing old results: say it where it can be read
            fwrite(STDERR, "poste-backup: cannot write " . Store::STATE . "\n");
            if (is_dir(Store::LOGS)) {
                @file_put_contents(Store::LOGS . '/engine-errors.log', date('Y-m-d H:i:s P') . ' cannot write ' . Store::STATE . "\n", FILE_APPEND);
            }
        }
    }

    // ---------------------------------------------------------------- one job

    private function runJob(string $id, string $why, ?int $slot): string
    {
        $job = $this->cfg['jobs'][$id];
        $start = time();
        $log = $this->openLog($id, $start);
        $this->logLine($log, "job \"{$job['name']}\" ($id), started by " . ($why === 'manual' ? 'a request' : 'its schedule'));
        $this->state['running'] = ['job' => $id, 'since' => $start, 'dest' => null, 'phase' => 'prepare', 'progress' => null];
        $this->saveState();

        [$paths, $warnings, $error] = $this->collect($job, $log);
        foreach ($warnings as $w) {
            $this->logLine($log, "warning: $w");
        }

        $results = [];
        foreach ($job['destinations'] as $did) {
            $d = $this->cfg['destinations'][$did];
            $t0 = time();
            if ($error !== null) {
                $r = ['status' => 'error', 'error' => $error];
            } else {
                $this->logLine($log, "--- destination \"{$d['name']}\" ($did)");
                $this->phase('backup', $did);
                $r = $this->backupTo($did, $d, $id, $job, $paths, $log);
                if (isset($r['space'])) {
                    $this->state['destinations'][$did] = $r['space'] + ['t' => time()];
                    unset($r['space']);
                }
                if ($r['status'] === 'ok' && $warnings) {
                    $r['status'] = 'warning';
                    $r['error'] = implode('; ', $warnings);
                }
            }
            $r += ['added' => 0, 'files' => 0, 'bytes' => 0, 'snapshot' => null, 'error' => null, 'note' => null];
            $r['duration'] = time() - $t0;
            $this->logLine($log, "result: {$r['status']}" . ($r['error'] ? " - {$r['error']}" : '') . ($r['note'] ? " ({$r['note']})" : ''));
            $results[$did] = $r;
            Store::appendJsonLine(Store::HISTORY, [
                't' => time(), 'start' => $t0, 'job' => $id, 'dest' => $did, 'reason' => $why,
                'status' => $r['status'], 'added' => $r['added'], 'files' => $r['files'], 'bytes' => $r['bytes'],
                'duration' => $r['duration'], 'snapshot' => $r['snapshot'], 'error' => $r['error'], 'note' => $r['note'],
                'log' => basename($this->logPath($id, $start)),
            ]);
        }
        $this->cleanStaging();

        $worst = 'ok';
        foreach ($results as $r) {
            if ($r['status'] === 'error') {
                $worst = 'error';
            } elseif ($r['status'] === 'warning' && $worst === 'ok') {
                $worst = 'warning';
            }
        }
        $prev = $this->state['jobs'][$id] ?? [];
        $this->state['jobs'][$id] = [
            'last_slot' => $slot ?? (int) ($prev['last_slot'] ?? 0),
            'last_start' => $start,
            'last_end' => time(),
            'last_reason' => $why,
            'status' => $worst,
            'destinations' => $results,
            'log' => basename($this->logPath($id, $start)),
        ];
        if ($worst === 'ok') {
            $this->state['jobs'][$id]['last_ok'] = time();
        } elseif (isset($prev['last_ok'])) {
            $this->state['jobs'][$id]['last_ok'] = $prev['last_ok'];
        }
        $this->state['running'] = null;
        $this->saveState();
        $this->logLine($log, "job finished: $worst");
        if ($log) {
            fclose($log);
        }
        $this->housekeeping(time());
        return $worst;
    }

    // ---------------------------------------------------------------- requests of the admin

    /** A snapshot id as restic prints it in full. */
    public static function isSnapshotId($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{64}$/', $id) === 1;
    }

    /**
     * A request of the admin, validated again (the queue folder belongs to mail,
     * like the admin): ['type' => restore|forget, ...] with only valid values,
     * or ['type' => ..., 'invalid' => true].
     */
    public static function task(string $type, ?array $raw): array
    {
        $raw = $raw ?? [];
        $dest = $raw['dest'] ?? null;
        if ($type === 'restore') {
            [$opt, $errors] = self::restoreOptions($raw);
            $ok = Config::isId($dest) && self::isSnapshotId($raw['snapshot'] ?? null)
                && Config::isAddress($raw['source'] ?? null) && Config::isAddress($raw['target'] ?? null) && !$errors;
            return $ok ? ['type' => 'restore', 'dest' => $dest, 'snapshot' => $raw['snapshot'],
                          'source' => $raw['source'], 'target' => strtolower($raw['target'])] + $opt
                       : ['type' => 'restore', 'invalid' => true];
        }
        $ok = Config::isId($dest) && Config::isId($raw['job'] ?? null);
        return $ok ? ['type' => 'forget', 'dest' => $dest, 'job' => $raw['job']] : ['type' => 'forget', 'invalid' => true];
    }

    /**
     * The choices of a mailbox restore, checked the same way by the admin's
     * form and by the engine: the folders of the snapshot's mailbox ('' is its
     * inbox, '.Name' a folder as Dovecot names it on disk), the name of the new
     * folder and the existing folder it goes into ('' = at the top), and an
     * optional filter (received from/to, sender, subject). Returns [options, errors].
     */
    public static function restoreOptions(array $raw): array
    {
        $e = [];
        $o = ['folders' => [], 'name' => '', 'parent' => '', 'since' => '', 'until' => '', 'from' => '', 'subject' => ''];
        $folders = $raw['folders'] ?? null;
        if (!is_array($folders) || !$folders) {
            $e[] = 'choose at least one folder';
        } elseif (count($folders) > self::MAX_FOLDERS) {
            $e[] = 'too many folders';
        } else {
            foreach ($folders as $f) {
                // a folder of the snapshot, never a path: no "/" and never "." or ".."
                if (!is_string($f) || ($f !== '' && ($f === '..' || preg_match('/^\.[^\/\x00-\x1f\x7f]{1,255}$/', $f) !== 1))) {
                    $e[] = 'folder: not valid';
                    break;
                }
                $o['folders'][] = $f;
            }
            $o['folders'] = array_values(array_unique($o['folders']));
        }
        // the folder made for the restore: "." is Dovecot's separator, * and % are IMAP wildcards
        $name = $raw['name'] ?? null;
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || $name !== trim($name) || $name === ''
            || mb_strlen($name) > 100 || preg_match('/[.\/\\\\*%\x00-\x1f\x7f]/', $name)) {
            $e[] = 'folder name: 1 to 100 characters, without dots, slashes, * or %';
        } elseif (strtoupper($name) === 'INBOX') {
            $e[] = 'folder name: INBOX is the inbox itself';
        } else {
            $o['name'] = $name;
        }
        $parent = $raw['parent'] ?? '';
        if (!is_string($parent) || !mb_check_encoding($parent, 'UTF-8') || strlen($parent) > 500
            || preg_match('/[*%\x00-\x1f\x7f]/', $parent) || ($parent !== '' && ($parent[0] === '.' || substr($parent, -1) === '.'))) {
            $e[] = 'parent folder: not valid';
        } else {
            $o['parent'] = $parent;
        }
        foreach (['since', 'until'] as $k) {
            $v = $raw[$k] ?? '';
            if ($v === '' || $v === null) {
                continue;
            }
            if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $e[] = 'date: YYYY-MM-DD';
                continue;
            }
            $o[$k] = $v;
        }
        if ($o['since'] !== '' && $o['until'] !== '' && $o['since'] > $o['until']) {
            $e[] = 'dates: the first one is after the second';
        }
        foreach (['from' => 'sender', 'subject' => 'subject'] as $k => $what) {
            $v = $raw[$k] ?? '';
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > 100 || preg_match('/[\x00-\x1f\x7f]/', $v)) {
                $e[] = "$what: up to 100 characters";
                continue;
            }
            $o[$k] = trim($v);
        }
        return [$o, $e];
    }

    /** doveadm's search query for the filter of a restore ("all" without one). */
    public static function restoreQuery(array $o): array
    {
        $q = [];
        if ($o['since'] !== '') {
            array_push($q, 'SINCE', $o['since']);
        }
        if ($o['until'] !== '') {
            // BEFORE leaves its own day out: the day after, so that "until" includes its day
            array_push($q, 'BEFORE', (new \DateTimeImmutable($o['until'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d'));
        }
        if ($o['from'] !== '') {
            array_push($q, 'FROM', $o['from']);
        }
        if ($o['subject'] !== '') {
            array_push($q, 'SUBJECT', $o['subject']);
        }
        return $q ?: ['all'];
    }

    /** A path as a restic --include pattern: its glob characters taken literally. */
    public static function globEscape(string $path): string
    {
        return preg_replace('/([\\\\*?\[\]])/', '\\\\$1', $path);
    }

    /** Runs a request of the admin and records its result (state.json, last 20). */
    private function runTask(string $id, array $t): void
    {
        $start = time();
        $log = $this->openLog($id, $start);
        $did = $t['dest'] ?? null;
        $d = $did !== null ? ($this->cfg['destinations'][$did] ?? null) : null;
        $this->logLine($log, $t['type'] === 'restore'
            ? 'restore of a mailbox' . (empty($t['invalid']) ? " ({$t['source']} from snapshot {$t['snapshot']} into {$t['target']}, "
                . count($t['folders']) . ' folder(s), new folder "' . ($t['parent'] !== '' ? $t['parent'] . '.' : '') . $t['name'] . '")' : '')
            : 'removal of the backups of a job' . (empty($t['invalid']) ? " ({$t['job']})" : ''));
        $this->state['running'] = ['task' => $id, 'type' => $t['type'], 'since' => $start, 'dest' => $did, 'phase' => $t['type'],
                                   'source' => $t['source'] ?? null, 'target' => $t['target'] ?? null, 'job' => null, 'progress' => null];
        $this->saveState();
        if (!empty($t['invalid'])) {
            $r = ['status' => 'error', 'error' => 'the request is not valid'];
        } elseif ($d === null) {
            $r = ['status' => 'error', 'error' => 'unknown destination'];
        } else {
            $this->logLine($log, "--- destination \"{$d['name']}\" ($did)");
            $r = $t['type'] === 'restore' ? $this->restoreMailbox($did, $d, $t, $log) : $this->forgetJob($did, $d, $t, $log);
        }
        $r += ['error' => null, 'note' => null];
        $this->logLine($log, "result: {$r['status']}" . ($r['error'] ? " - {$r['error']}" : '') . ($r['note'] ? " ({$r['note']})" : ''));
        $this->state['tasks'][] = [
            'id' => $id, 'type' => $t['type'], 'dest' => $did, 'snapshot' => $t['snapshot'] ?? null, 'source' => $t['source'] ?? null,
            'target' => $t['target'] ?? null, 'job' => $t['job'] ?? null, 'start' => $start, 'end' => time(),
            'status' => $r['status'], 'error' => $r['error'], 'note' => $r['note'], 'log' => basename($this->logPath($id, $start)),
        ];
        $this->state['tasks'] = array_slice($this->state['tasks'], -20);
        $this->state['running'] = null;
        $this->saveState();
        if ($log) {
            fclose($log);
        }
    }

    /** restic's environment for a destination, with the key. Returns [env, error]. */
    private function resticEnv(string $did, array $d, string $tmp): array
    {
        if (!is_file(Store::KEY) || is_link(Store::KEY) || filesize(Store::KEY) < 32) {
            return [null, 'the encryption key is missing (' . Store::KEY . ')'];
        }
        [$env, $err] = Destination::env($did, $d, $tmp, Store::CACHE);
        if ($err) {
            return [null, $err];
        }
        $env['RESTIC_PASSWORD_FILE'] = Store::KEY;
        $env['RESTIC_CACHE_DIR'] = Store::CACHE . '/restic';
        return [$env, null];
    }

    /**
     * A mailbox of a snapshot back into a mailbox of this server, in a new
     * folder "Restored <time of the snapshot>" (with its subfolders, flags and
     * subscriptions): nothing already in the mailbox is touched. The snapshot's
     * mailbox is first restored into a work folder of the engine, then imported
     * by Dovecot (doveadm import, as mail like the mailboxes), then removed.
     */
    private function restoreMailbox(string $did, array $d, array $t, $log): array
    {
        $home = $this->home($t['target']);
        if ($home === null) {
            return ['status' => 'error', 'error' => "mailbox {$t['target']} not found"];
        }
        [$local, $domain] = explode('@', $t['source'], 2);
        // an address that passed Config::isAddress has no "/" and is never "." or ".."
        $src = '/data/domains/' . $domain . '/' . $local;
        $tmp = $this->privateTmp();
        if ($tmp === null) {
            return ['status' => 'error', 'error' => 'cannot create a temporary folder'];
        }
        try {
            [$env, $err] = $this->resticEnv($did, $d, $tmp);
            if ($err) {
                return ['status' => 'error', 'error' => $err];
            }
            $repo = Destination::repository($did, $d);

            // the snapshot (its time names the folder) and the mailbox in it
            $time = null;
            $found = false;
            $ls = $this->restic($repo, ['ls', '--json', $t['snapshot'], '/data/domains/' . $domain], $env, $log, 600,
                function (string $line) use (&$time, &$found, $src) {
                    $j = json_decode($line, true);
                    if (!is_array($j)) {
                        return;
                    }
                    if (($j['struct_type'] ?? '') === 'snapshot' && is_string($j['time'] ?? null)) {
                        $time = $j['time'];
                    } elseif (($j['type'] ?? '') === 'dir' && ($j['path'] ?? '') === $src) {
                        $found = true;
                    }
                });
            if ($ls['exit'] !== 0 || $time === null) {
                return ['status' => 'error', 'error' => 'cannot read the snapshot: ' . self::lastLine($ls['err'])];
            }
            if (!$found) {
                return ['status' => 'error', 'error' => "{$t['source']} is not in this snapshot"];
            }
            // the folders chosen, as the snapshot has them, and their size: the mailbox is
            // written twice on /data (restored, then imported), so is there room for both?
            $present = [];
            $size = 0;
            $chosen = array_flip($t['folders']);
            $maildir = $src . '/Maildir';
            $sz = $this->restic($repo, ['ls', '--json', '--recursive', $t['snapshot'], $src], $env, $log, 0,
                function (string $line) use (&$present, &$size, $chosen, $maildir) {
                    $j = json_decode($line, true);
                    if (!is_array($j) || !is_string($j['path'] ?? null)) {
                        return;
                    }
                    $rel = substr($j['path'], strlen($maildir));
                    if (strpos($j['path'], $maildir . '/') !== 0) {
                        return;
                    }
                    // "/cur/x" or "/new/x" is the inbox, "/.Name/cur/x" a folder
                    if (preg_match('#^(?:/(\.[^/]+))?/(cur|new)(/|$)#', $rel, $m) === 1) {
                        $f = $m[1] ?? '';
                        $present[$f] = true;
                        if (($j['type'] ?? '') === 'file' && isset($chosen[$f])) {
                            $size += (int) ($j['size'] ?? 0);
                        }
                    }
                });
            if ($sz['exit'] !== 0) {
                return ['status' => 'error', 'error' => 'cannot read the snapshot: ' . self::lastLine($sz['err'])];
            }
            foreach ($t['folders'] as $f) {
                if (!isset($present[$f])) {
                    return ['status' => 'error', 'error' => 'a chosen folder is not in this snapshot'];
                }
            }
            $free = @disk_free_space('/data');
            if ($free !== false && !self::enoughSpace($size, (int) $free)) {
                return ['status' => 'error', 'error' => 'not enough free space on /data: the restore needs about '
                    . self::size(self::spaceNeeded($size)) . ', ' . self::size((int) $free) . ' are free'];
            }
            $this->logLine($log, count($t['folders']) . " folder(s) chosen, $size bytes; " . (int) $free . ' bytes free on /data');

            if (!$this->emptyRestoreFolder()) {
                return ['status' => 'error', 'error' => 'cannot prepare the restore folder'];
            }
            $this->state['running']['phase'] = 'restore';
            $this->saveState();
            // only the folders chosen (the inbox is the Maildir's own cur and new)
            $args = ['restore', $t['snapshot'], '--target', Store::RESTORE];
            foreach ($t['folders'] as $f) {
                foreach ($f === '' ? ['/cur', '/new'] : ['/' . $f] as $part) {
                    array_push($args, '--include', self::globEscape($maildir . $part));
                }
            }
            $r = $this->restic($repo, $args, $env, $log, 0);
            if ($r['exit'] !== 0) {
                return ['status' => 'error', 'error' => 'restore failed: ' . self::lastLine($r['err'])];
            }
            // under a fixed name: the address may hold characters Dovecot reads in a location
            $box = Store::RESTORE . '/mailbox';
            if (!is_dir(Store::RESTORE . $src . '/Maildir') || is_link(Store::RESTORE . $src) || is_link(Store::RESTORE . $src . '/Maildir')
                || !@rename(Store::RESTORE . $src, $box)) {
                return ['status' => 'error', 'error' => "{$t['source']} has no mail in this snapshot"];
            }
            // Dovecot would follow a link to mail elsewhere on this server: links in the
            // Maildir (what is imported) are removed first, never followed
            $links = self::removeLinks($box . '/Maildir');
            if ($links) {
                $this->logLine($log, "$links symbolic link(s) in the restored Maildir removed (never followed)");
            }

            // the folder: the name chosen (by default "Restored <time>"), at the top or
            // inside an existing folder of the mailbox; " (2)" if it exists already
            $dov = ['HOME' => Store::CACHE];
            // read line by line: Proc keeps only the end of a long output
            $existing = [];
            $list = Proc::run(['doveadm', 'mailbox', 'list', '-u', $t['target']], $dov, null, 120, function (string $line) use (&$existing) {
                if (trim($line) !== '') {
                    $existing[] = trim($line);
                }
            }, $log);
            if ($list['exit'] !== 0) {
                return ['status' => 'error', 'error' => 'import failed: ' . self::lastLine($list['err'])];
            }
            if ($t['parent'] !== '' && !in_array($t['parent'], $existing, true)) {
                return ['status' => 'error', 'error' => "the folder {$t['parent']} is not in {$t['target']}"];
            }
            $base = ($t['parent'] !== '' ? $t['parent'] . '.' : '') . $t['name'];
            $folder = $base;
            for ($n = 2; $this->folderTaken($existing, $folder); $n++) {
                $folder = "$base ($n)";
            }
            $query = self::restoreQuery($t);
            $this->state['running']['phase'] = 'import';
            $this->saveState();
            $this->logLine($log, "import into the folder \"$folder\" of {$t['target']}, messages: " . implode(' ', $query));
            // "--": a value of the filter that starts with "-" is never read as an option
            $imp = Proc::run(array_merge(['doveadm', 'import', '-u', $t['target'], '-s', '--', 'maildir:' . $box . '/Maildir', $folder], $query),
                $dov, null, 0, null, $log);
            if ($imp['exit'] !== 0) {
                return ['status' => 'error', 'error' => 'import failed: ' . self::lastLine($imp['err'])];
            }
            // the messages are in its subfolders ("<folder>.INBOX", ...); "<folder>*" would also count a "<folder> (2)"
            $n = 0;
            Proc::run(['doveadm', 'search', '-u', $t['target'], '--', 'mailbox', $folder . '.*', 'all'], $dov, null, 600,
                function (string $line) use (&$n) {
                    if (trim($line) !== '') {
                        $n++;
                    }
                }, $log);
            if ($n === 0) {
                return ['status' => 'ok', 'note' => 'no message matched the choice: nothing restored'];
            }
            $shown = str_replace('.', ' / ', $folder);
            return ['status' => 'ok', 'note' => "$n message(s) restored into the folder \"$shown\" of {$t['target']}"];
        } finally {
            $this->emptyRestoreFolder();
            @rmdir(Store::RESTORE);
            $this->removeTmp($tmp);
        }
    }

    /** Bytes /data needs for a restore of $size bytes: the copy, the import, and some room left. */
    public static function spaceNeeded(int $size): int
    {
        return 2 * $size + 268435456;
    }

    public static function enoughSpace(int $size, int $free): bool
    {
        return $free >= self::spaceNeeded($size);
    }

    /** Removes every symbolic link in this folder and below (never followed); returns how many. */
    private static function removeLinks(string $dir): int
    {
        $n = 0;
        foreach (@scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            if (is_link($p)) {
                $n += @unlink($p) ? 1 : 0;
            } elseif (is_dir($p)) {
                $n += self::removeLinks($p);
            }
        }
        return $n;
    }

    /** "1.5 GB": 1024 units, one decimal (the same as the admin's). */
    public static function size(int $bytes): string
    {
        foreach (['TB' => 1 << 40, 'GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $u => $n) {
            if ($bytes >= $n) {
                return round($bytes / $n, 1) . " $u";
            }
        }
        return "$bytes B";
    }

    /** Is this folder name (or a folder under it) in the list? */
    private function folderTaken(array $existing, string $folder): bool
    {
        foreach ($existing as $name) {
            if ($name === $folder || strpos($name, $folder . '.') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Empties the restore folder (made 0700 if missing). Links are removed,
     * never followed; folders restored without write permission get it first.
     */
    private function emptyRestoreFolder(): bool
    {
        $dir = Store::RESTORE;
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
            return false;
        }
        if (!is_dir($dir)) {
            return @mkdir($dir, 0700);
        }
        @chmod($dir, 0700);
        $walk = function (string $path) use (&$walk) {
            foreach (@scandir($path) ?: [] as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $p = "$path/$e";
                if (is_dir($p) && !is_link($p)) {
                    @chmod($p, 0700);
                    $walk($p);
                    @rmdir($p);
                } else {
                    @unlink($p);
                }
            }
        };
        $walk($dir);
        return (@scandir($dir) ?: ['x', 'y', 'z']) === ['.', '..'];
    }

    /** Removes every snapshot of a job from a destination (restic forget + prune). */
    private function forgetJob(string $did, array $d, array $t, $log): array
    {
        $tmp = $this->privateTmp();
        if ($tmp === null) {
            return ['status' => 'error', 'error' => 'cannot create a temporary folder'];
        }
        try {
            [$env, $err] = $this->resticEnv($did, $d, $tmp);
            if ($err) {
                return ['status' => 'error', 'error' => $err];
            }
            $repo = Destination::repository($did, $d);
            $json = '';
            $l = $this->restic($repo, ['snapshots', '--json', '--host', self::HOST, '--tag', 'job:' . $t['job']], $env, $log, 600,
                function (string $line) use (&$json) {
                    $json .= $line;
                }, null, self::MAX_LIST);
            $list = json_decode($json, true);
            if ($l['exit'] !== 0 || !is_array($list)) {
                return ['status' => 'error', 'error' => 'cannot read the snapshots: ' . self::lastLine($l['err'])];
            }
            $ids = [];
            foreach ($list as $snap) {
                if (self::isSnapshotId($snap['id'] ?? null)) {
                    $ids[] = $snap['id'];
                }
            }
            if (!$ids) {
                return ['status' => 'ok', 'note' => 'there were no backups of this job on this destination'];
            }
            $this->logLine($log, count($ids) . ' snapshot(s) of the job to remove');
            $f = $this->restic($repo, array_merge(['forget', '--prune'], $ids), $env, $log, 0);
            if ($f['exit'] !== 0) {
                return ['status' => 'error', 'error' => 'removing the backups failed: ' . self::lastLine($f['err'])];
            }
            $this->state['destinations'][$did] = $this->space($did, $d, $env, $tmp, $log) + ['t' => time()];
            return ['status' => 'ok', 'note' => count($ids) . ' backup(s) of the job removed'];
        } finally {
            $this->removeTmp($tmp);
        }
    }

    // ---------------------------------------------------------------- what

    /** Returns [paths, warnings, error]. */
    private function collect(array $job, $log): array
    {
        $paths = [];
        $warnings = [];
        if ($job['config']) {
            if (!$this->stage($log, $err)) {
                return [[], [], $err];
            }
            $paths[] = Store::STAGING;
            foreach (['/data/server.ini', '/data/instance-id', '/data/ssl', '/data/nginx', '/data/redis/dump.rdb',
                      '/data/zpush'] as $p) {
                if (file_exists($p) && !is_link($p)) {
                    $paths[] = $p;
                }
            }
            // DKIM keys of every domain (only plain paths: no link anywhere in them)
            foreach (glob('/data/domains/*/{private,public,selector}', GLOB_BRACE) ?: [] as $p) {
                if (is_file($p) && realpath($p) === $p) {
                    $paths[] = $p;
                }
            }
            // settings of the add-ons and of Poste.io under /data/var; not the virus
            // database (downloaded again) and not this add-on's own folder (key!)
            foreach (@scandir('/data/var') ?: [] as $name) {
                if (in_array($name, ['.', '..', 'clamav', 'backup'], true) || is_link("/data/var/$name")) {
                    continue;
                }
                $paths[] = "/data/var/$name";
            }
        }
        // what "mail" cannot read in the configuration (e.g. rspamd's cached maps)
        // is left out on purpose, not reported by restic as a failure every run
        if ($paths) {
            $paths = $this->readable($paths, $log);
        }
        if ($job['mail'] === 'all') {
            $paths[] = '/data/domains';
            // messages held by the spam filter, waiting for a decision
            if (is_dir('/data/quarantine') && !is_link('/data/quarantine')) {
                $paths[] = '/data/quarantine';
            }
        } elseif ($job['mail'] === 'selected') {
            $found = 0;
            foreach ($job['mailboxes'] as $address) {
                $home = $this->home($address);
                if ($home === null) {
                    $warnings[] = "mailbox $address not found";
                    continue;
                }
                $paths[] = $home;
                $found++;
            }
            if ($found === 0) {
                return [[], $warnings, 'none of the selected mailboxes exists'];
            }
        }
        return [array_values(array_unique($paths)), $warnings, null];
    }

    /**
     * The paths, without what this user cannot read: an unreadable file is
     * dropped, a folder with unreadable entries is replaced by its readable
     * entries (one level at a time). Configuration folders are small.
     */
    private function readable(array $paths, $log): array
    {
        $out = [];
        $skipped = 0;
        $walk = function (string $p) use (&$walk, &$out, &$skipped) {
            if (is_link($p)) {
                $out[] = $p;                     // restic stores the link itself
                return;
            }
            if (!is_readable($p) || (is_dir($p) && !is_executable($p))) {
                $skipped++;
                return;
            }
            if (!is_dir($p)) {
                $out[] = $p;
                return;
            }
            $entries = array_diff(@scandir($p) ?: [], ['.', '..']);
            $bad = false;
            foreach ($entries as $e) {
                $q = "$p/$e";
                if (!is_link($q) && (!is_readable($q) || (is_dir($q) && !is_executable($q)) || (is_dir($q) && $this->hasUnreadable($q)))) {
                    $bad = true;
                    break;
                }
            }
            if (!$bad) {
                $out[] = $p;
                return;
            }
            foreach ($entries as $e) {
                $walk("$p/$e");
            }
        };
        foreach ($paths as $p) {
            $walk($p);
        }
        if ($skipped) {
            $this->logLine($log, "$skipped file(s) or folder(s) of the configuration not readable by mail: left out");
        }
        return $out;
    }

    private function hasUnreadable(string $dir): bool
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            if (!$f->isLink() && (!$f->isReadable() || ($f->isDir() && !$f->isExecutable()))) {
                return true;
            }
        }
        return false;
    }

    /** Home folder of a mailbox, from Poste.io's own database (read only). */
    private function home(string $address): ?string
    {
        try {
            $db = new \SQLite3('/data/users.db', SQLITE3_OPEN_READONLY);
            $db->busyTimeout(10000);
            $st = $db->prepare('SELECT home FROM users WHERE address = :a');
            $st->bindValue(':a', $address, SQLITE3_TEXT);
            $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
            $db->close();
        } catch (\Throwable $e) {
            return null;
        }
        // users.db can be written by "mail": the path must be exactly a folder two
        // levels under /data/domains, with no "." or ".." part and no link anywhere
        // in it (realpath gives back the same path)
        $home = is_array($row) ? (string) $row['home'] : '';
        if (preg_match('#^/data/domains/([^/]+)/([^/]+)$#', $home, $m) !== 1
            || in_array($m[1], ['.', '..'], true) || in_array($m[2], ['.', '..'], true)
            || !is_dir($home) || realpath($home) !== $home) {
            return null;
        }
        return $home;
    }

    /**
     * Consistent copies of the SQLite databases (they are in use): sqlite3's
     * .backup, then an integrity check of each copy.
     */
    private function stage($log, ?string &$err): bool
    {
        $this->cleanStaging();
        if (!is_dir(Store::STAGING) && !@mkdir(Store::STAGING, 0700)) {
            $err = 'cannot create the staging folder';
            return false;
        }
        @chmod(Store::STAGING, 0700);
        foreach (['users.db' => '/data/users.db', 'dav.db' => '/data/dav.db', 'roundcube.db' => '/data/roundcube/roundcube.db'] as $name => $src) {
            if (!is_file($src)) {
                continue;
            }
            $dst = Store::STAGING . '/' . $name;
            if (fileowner($src) === posix_geteuid()) {
                // ours: SQLite's own online backup, read only (it never writes to the database)
                $r = Proc::run(['sqlite3', '-bail', '-readonly', $src, '.backup ' . $dst], [], null, 600, null, $log);
                $ok = $r['exit'] === 0;
            } else {
                // another user's (the webmail's, www-data): in WAL mode a reader must be able to
                // create the -shm file next to it, which we cannot. The files are copied instead
                // (database and -wal, again if they changed meanwhile), then the copy is opened
                // here, which applies the WAL, and backed up from there.
                $ok = $this->copyForeignDb($src, $dst, $log);
            }
            $chk = Proc::run(['sqlite3', '-bail', $dst, 'PRAGMA integrity_check;'], [], null, 600, null, $log);
            if (!$ok || trim($chk['out']) !== 'ok') {
                $err = "copy of $src failed or is damaged";
                return false;
            }
            $this->logLine($log, "copied $src (integrity check: ok)");
        }
        return true;
    }

    private function copyForeignDb(string $src, string $dst, $log): bool
    {
        $raw = Store::STAGING . '/raw-' . basename($dst);
        $sig = function () use ($src) {
            clearstatcache();
            $s = [];
            foreach (['', '-wal'] as $suffix) {
                $st = @stat($src . $suffix);
                $s[] = $st ? $st['size'] . ':' . $st['mtime'] : '-';
            }
            return implode('/', $s);
        };
        for ($try = 1; $try <= 5; $try++) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($raw . $suffix);
            }
            $before = $sig();
            $ok = @copy($src, $raw) && (!is_file($src . '-wal') || @copy($src . '-wal', $raw . '-wal'));
            if ($ok && $sig() === $before) {
                $r = Proc::run(['sqlite3', '-bail', $raw, '.backup ' . $dst], [], null, 600, null, $log);
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($raw . $suffix);
                }
                return $r['exit'] === 0;
            }
            $this->logLine($log, "$src changed while being copied: again ($try)");
            sleep(2);
        }
        return false;
    }

    private function cleanStaging(): void
    {
        foreach (['users.db', 'dav.db', 'roundcube.db'] as $name) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink(Store::STAGING . '/' . $name . $suffix);
                @unlink(Store::STAGING . '/raw-' . $name . $suffix);
            }
        }
    }

    // ---------------------------------------------------------------- where

    private function backupTo(string $did, array $d, string $jobId, array $job, array $paths, $log): array
    {
        $tmp = $this->privateTmp();
        if ($tmp === null) {
            return ['status' => 'error', 'error' => 'cannot create a temporary folder'];
        }
        try {
            // settings/ belongs to mail: a symbolic link there is never followed
            if (!is_file(Store::KEY) || is_link(Store::KEY) || filesize(Store::KEY) < 32) {
                return ['status' => 'error', 'error' => 'the encryption key is missing (' . Store::KEY . ')'];
            }
            [$env, $err] = Destination::env($did, $d, $tmp, Store::CACHE);
            if ($err) {
                return ['status' => 'error', 'error' => $err];
            }
            $env['RESTIC_PASSWORD_FILE'] = Store::KEY;
            $env['RESTIC_CACHE_DIR'] = Store::CACHE . '/restic';
            $env['RESTIC_PROGRESS_FPS'] = '1';          // one progress line a second (--json)
            $repo = Destination::repository($did, $d);

            [$exists, $err] = $this->repositoryExists($did, $d, $env, $log);
            if ($err) {
                return ['status' => 'error', 'error' => $err];
            }
            if (!$exists) {
                if ($d['type'] === 's3') {
                    // the bucket may not exist yet; with limited credentials this may fail and that is fine
                    Proc::run(['rclone', 'mkdir', Destination::remote($did) . ':' . $d['bucket']], $env, null, 120, null, $log);
                }
                $r = $this->restic($repo, ['init'], $env, $log, 600);
                if ($r['exit'] !== 0) {
                    return ['status' => 'error', 'error' => 'cannot create the repository: ' . self::lastLine($r['err'])];
                }
                $this->logLine($log, 'repository created');
            }

            $summary = null;
            $vanished = $unreadable = 0;
            $args = array_merge(['backup', '--json', '--host', self::HOST, '--tag', self::TAG, '--tag', 'job:' . $jobId], $paths);
            $r = $this->restic($repo, $args, $env, $log, 0, function (string $line) use (&$summary) {
                if (strpos($line, '"message_type":"status"') !== false) {
                    $j = json_decode($line, true);
                    if (is_array($j)) {
                        $this->progress($j);
                    }
                } elseif (strpos($line, '"summary"') !== false) {
                    $j = json_decode($line, true);
                    if (is_array($j) && ($j['message_type'] ?? '') === 'summary') {
                        $summary = $j;
                    }
                }
            }, function (string $line) use (&$vanished, &$unreadable) {
                $kind = self::fileError($line);
                if ($kind === 'vanished') {
                    $vanished++;
                } elseif ($kind === 'unreadable') {
                    $unreadable++;
                }
            });
            // restic: 0 = done, 3 = snapshot made but some files could not be read
            if (($r['exit'] !== 0 && $r['exit'] !== 3) || $summary === null) {
                return ['status' => 'error', 'error' => 'backup failed: ' . self::lastLine($r['err'])];
            }
            $res = self::outcome($r['exit'], $vanished, $unreadable) + [
                'added' => (int) ($summary['data_added'] ?? 0),
                'files' => (int) ($summary['total_files_processed'] ?? 0),
                'bytes' => (int) ($summary['total_bytes_processed'] ?? 0),
                'snapshot' => is_string($summary['snapshot_id'] ?? null) ? substr($summary['snapshot_id'], 0, 64) : null,
            ];
            $this->logLine($log, "snapshot {$res['snapshot']}: {$res['files']} files, " . $res['added'] . ' bytes added');

            // keep rules: only this job's snapshots, grouped by host and tags (a
            // different list of mailboxes must not start a separate group)
            $forget = ['forget', '--host', self::HOST, '--tag', 'job:' . $jobId, '--group-by', 'host,tags', '--prune'];
            foreach ($job['keep'] as $k => $n) {
                if ($n > 0) {
                    array_push($forget, '--keep-' . $k, (string) $n);
                }
            }
            $this->phase('prune', $did);
            $f = $this->restic($repo, $forget, $env, $log, 0);
            if ($f['exit'] !== 0) {
                $res['status'] = 'warning';
                $res['error'] = 'backup done, but the old snapshots were not removed: ' . self::lastLine($f['err']);
            }
            $this->phase('space', $did);
            $res['space'] = $this->space($did, $d, $env, $tmp, $log);
            return $res;
        } finally {
            $this->removeTmp($tmp);
        }
    }

    /**
     * One line of restic's standard error (--json): "vanished" for a file that
     * no longer exists (ENOENT: moved or deleted while the backup ran, normal
     * on a mail server in use, where a message moved to another folder or with
     * a new flag gets a new name), "unreadable" for any other file restic could
     * not read, null for a line that is not about a file.
     */
    public static function fileError(string $line): ?string
    {
        if (strpos($line, '"message_type":"error"') === false) {
            return null;
        }
        $j = json_decode($line, true);
        if (!is_array($j) || ($j['message_type'] ?? null) !== 'error') {
            return null;
        }
        return ($j['error']['Err'] ?? null) === 2 ? 'vanished' : 'unreadable';
    }

    /**
     * The result of a backup that made its snapshot (restic exit 0 or 3): only
     * files that vanished meanwhile is ok, with a note; a file that could not
     * be read is a warning; exit 3 with no file error understood is a warning
     * too (never guessed to be fine).
     */
    public static function outcome(int $exit, int $vanished, int $unreadable): array
    {
        $ok = $exit === 0 || ($vanished > 0 && $unreadable === 0);
        return [
            'status' => $ok ? 'ok' : 'warning',
            'error' => $ok ? null
                : ($unreadable > 0 ? "$unreadable file(s) could not be read (see the log)" : 'some files could not be read (see the log)'),
            'note' => $vanished > 0 ? "$vanished file(s) moved or deleted while the backup ran: the next backup has them" : null,
        ];
    }

    /**
     * Space on a destination after a backup: bytes used by the repository and,
     * where the server reports it, free and total. Nothing is an error here.
     */
    private function space(string $did, array $d, array $env, string $tmp, $log): array
    {
        $path = $d['type'] === 'local' ? $d['path'] : Destination::rclonePath($did, $d);
        $out = ['used' => null, 'free' => null, 'total' => null];
        $s = Proc::run(['rclone', 'size', '--json', $path], $env, null, 600, null, $log);
        $j = json_decode($s['out'], true);
        if ($s['exit'] === 0 && is_array($j) && isset($j['bytes'])) {
            $out['used'] = (int) $j['bytes'];
        }
        if ($d['type'] === 'smb') {
            // not rclone's numbers for SMB: see Destination::smbSpace
            $sp = Destination::smbSpace($d, $tmp);
            $out['free'] = $sp['free'] ?? null;
            $out['total'] = $sp['total'] ?? null;
        } elseif ($d['type'] !== 's3') {
            $a = Proc::run(['rclone', 'about', '--json', $path], $env, null, 120, null, $log);
            $j = json_decode($a['out'], true);
            if ($a['exit'] === 0 && is_array($j)) {
                $out['free'] = isset($j['free']) ? (int) $j['free'] : null;
                $out['total'] = isset($j['total']) ? (int) $j['total'] : null;
            }
        }
        $this->logLine($log, 'space: ' . ($out['used'] ?? '?') . ' bytes used by the backups'
            . ($out['free'] !== null ? ', ' . $out['free'] . ' free' : ''));
        return $out;
    }

    // ---------------------------------------------------------------- progress

    /** What the running job is doing now, for the admin (saved at once). */
    private function phase(string $phase, ?string $did): void
    {
        $this->state['running']['phase'] = $phase;
        $this->state['running']['dest'] = $did;
        $this->state['running']['progress'] = null;
        $this->state['running']['progressShown'] = false;
        $this->saveState();
        $this->progressSaved = time();
    }

    /** restic's progress (a status line), saved at most every 3 seconds. */
    private function progress(array $j): void
    {
        $this->state['running']['progress'] = [
            'percent' => round(100 * (float) ($j['percent_done'] ?? 0), 1),
            'bytes_done' => (int) ($j['bytes_done'] ?? 0),
            'total_bytes' => (int) ($j['total_bytes'] ?? 0),
            'files_done' => (int) ($j['files_done'] ?? 0),
            'total_files' => (int) ($j['total_files'] ?? 0),
        ];
        // the first one at once (a short backup shows it too), then every 3 seconds
        if (empty($this->state['running']['progressShown']) || time() - $this->progressSaved >= 3) {
            $this->state['running']['progressShown'] = true;
            $this->saveState();
            $this->progressSaved = time();
        }
    }

    /** Returns [exists, error]: a repository is there only if its "config" file is. */
    private function repositoryExists(string $did, array $d, array $env, $log): array
    {
        if ($d['type'] === 'local') {
            $err = Destination::checkLocal($d['path']);
            if ($err) {
                return [false, $err];
            }
            if (is_file($d['path'] . '/config')) {
                return [true, null];
            }
            $other = array_diff(@scandir($d['path']) ?: [], ['.', '..', 'lost+found']);
            if ($other) {
                return [false, "{$d['path']} is not empty and is not a backup repository: nothing written there"];
            }
            return [false, null];
        }
        $r = Proc::run(['rclone', 'lsf', '--max-depth', '1', Destination::rclonePath($did, $d)], $env, null, 180, null, $log);
        if ($r['exit'] === 0) {
            $names = array_filter(array_map('trim', explode("\n", $r['out'])), 'strlen');
            if (in_array('config', $names, true)) {
                return [true, null];
            }
            if ($names) {
                return [false, 'the folder is not empty and is not a backup repository: nothing written there'];
            }
            return [false, null];
        }
        if (stripos($r['err'], 'directory not found') !== false) {
            return [false, null];
        }
        return [false, 'not reachable: ' . self::lastLine($r['err'])];
    }

    /** restic, with one retry after removing stale locks (a run that died). */
    private function restic(string $repo, array $args, array $env, $log, int $timeout, ?callable $onLine = null,
                            ?callable $onErrLine = null, int $maxLine = 1048576): array
    {
        $cmd = array_merge(['restic', '-r', $repo], $args);
        $r = Proc::run($cmd, $env, null, $timeout, $onLine, $log, $onErrLine, $maxLine);
        if ($r['exit'] !== 0 && stripos($r['err'], 'repository is already locked') !== false) {
            $this->logLine($log, 'repository locked: removing stale locks and retrying once');
            Proc::run(['restic', '-r', $repo, 'unlock'], $env, null, 300, null, $log);
            $r = Proc::run($cmd, $env, null, $timeout, $onLine, $log, $onErrLine, $maxLine);
        }
        return $r;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The last line of a command's error output, made readable: without
     * rclone's date and log level, and without the internal remote name
     * (D + id) in front of paths.
     */
    public static function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
        $l = $lines ? end($lines) : 'no details';
        $l = mb_scrub($l, 'UTF-8');
        $l = preg_replace('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2} (?:(?:ERROR|NOTICE|INFO|DEBUG|CRITICAL) *: *)?#', '', $l);
        $l = preg_replace('/\bD[0-9A-F]{12}:/', '', $l);
        return mb_substr($l, 0, 300);
    }

    private function privateTmp(): ?string
    {
        $dir = Store::TMP . '/' . bin2hex(random_bytes(6));
        return @mkdir($dir, 0700) ? $dir : null;
    }

    private function removeTmp(string $dir): void
    {
        foreach (@scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink("$dir/$f");
            }
        }
        @rmdir($dir);
    }

    private function logPath(string $id, int $start): string
    {
        return Store::LOGS . '/' . date('Ymd-His', $start) . '-' . $id . '.log';
    }

    private function openLog(string $id, int $start)
    {
        if (!is_dir(Store::LOGS)) {
            @mkdir(Store::LOGS, 0750);
        }
        $path = $this->logPath($id, $start);
        $f = @fopen($path, 'a');
        if ($f) {
            @chmod($path, 0640);
            @chgrp($path, 'mail');
        }
        return $f ?: null;
    }

    private function logLine($log, string $line): void
    {
        if ($log) {
            // with the offset: the other logs of the container may be in UTC
            fwrite($log, date('Y-m-d H:i:s P') . ' ' . $line . "\n");
        }
    }

    /**
     * Removes the logs of the runs older than the days set in the admin (and
     * beyond a safety number of files), and the lines of the history older than
     * its own days; keeps the engine's own error log short. Run under the lock.
     */
    private function housekeeping(int $now): void
    {
        $limit = $now - 86400 * (int) $this->cfg['settings']['log_days'];
        $runLogs = [];
        foreach (glob(Store::LOGS . '/*.log') ?: [] as $f) {
            if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{12}\.log$/', basename($f)) === 1 && is_file($f) && !is_link($f)) {
                $runLogs[] = $f;
            }
        }
        sort($runLogs);
        foreach ($runLogs as $i => $f) {
            if ((int) @filemtime($f) < $limit) {
                @unlink($f);
                unset($runLogs[$i]);
            }
        }
        foreach (array_slice(array_values($runLogs), 0, max(0, count($runLogs) - Store::MAX_LOGS)) as $f) {
            @unlink($f);
        }

        // the history: rewritten only when its first line is too old
        $hlimit = $now - 86400 * (int) $this->cfg['settings']['history_days'];
        $h = @fopen(Store::HISTORY, 'r');
        if ($h) {
            $first = json_decode((string) fgets($h), true);
            fclose($h);
            if (is_array($first) && (int) ($first['t'] ?? 0) < $hlimit) {
                $keep = '';
                foreach (file(Store::HISTORY) ?: [] as $line) {
                    $d = json_decode($line, true);
                    if (is_array($d) && (int) ($d['t'] ?? 0) >= $hlimit) {
                        $keep .= rtrim($line, "\n") . "\n";
                    }
                }
                Store::writeAtomic(Store::HISTORY, $keep, 0640);
            }
        }

        // the engine's own error log: its last 200 lines
        $err = Store::LOGS . '/engine-errors.log';
        if (is_file($err) && !is_link($err) && filesize($err) > 65536) {
            $lines = file($err) ?: [];
            Store::writeAtomic($err, implode('', array_slice($lines, -200)), 0640);
        }
    }
}
