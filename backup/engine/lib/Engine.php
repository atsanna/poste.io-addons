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
            // logs and history are trimmed after each run, and once a day for a
            // server where nothing runs for a while
            if ($now - (int) ($this->state['housekeeping'] ?? 0) >= 86400) {
                $this->housekeeping($now);
                $this->state['housekeeping'] = $now;
            }
            $this->saveState();

            $runs = [];
            // requests of the admin first ("run now")
            foreach ((is_link(Store::QUEUE) ? [] : (@scandir(Store::QUEUE) ?: [])) as $f) {
                if (!preg_match('/^run-([a-f0-9]{12})-[a-f0-9]{8}\.json$/', $f, $m)) {
                    continue;
                }
                if (is_link(Store::QUEUE . '/' . $f) || !is_file(Store::QUEUE . '/' . $f)) {
                    continue;
                }
                @unlink(Store::QUEUE . '/' . $f);
                if (isset($this->cfg['jobs'][$m[1]]) && !isset($runs[$m[1]])) {
                    $runs[$m[1]] = 'manual';
                }
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
            return count($runs) . ' job(s) run';
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
        $this->state += ['jobs' => [], 'running' => null, 'destinations' => []];
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
                            ?callable $onErrLine = null): array
    {
        $cmd = array_merge(['restic', '-r', $repo], $args);
        $r = Proc::run($cmd, $env, null, $timeout, $onLine, $log, $onErrLine);
        if ($r['exit'] !== 0 && stripos($r['err'], 'repository is already locked') !== false) {
            $this->logLine($log, 'repository locked: removing stale locks and retrying once');
            Proc::run(['restic', '-r', $repo, 'unlock'], $env, null, 300, null, $log);
            $r = Proc::run($cmd, $env, null, $timeout, $onLine, $log, $onErrLine);
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
