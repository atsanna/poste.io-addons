<?php
/*
 * poste.io-addons, backup add-on: what the admin does (it runs as "mail").
 *
 * Kept out of the Symfony controller so that it can be tested on its own:
 * reading and saving the settings (under a lock, validated), the connection
 * test of a destination, the SSH host keys and key pair of SFTP, the "run
 * now" requests, the list of mailboxes, the state written by the engine.
 * Commands run without a shell and with a time limit (Proc).
 */

namespace PosteBackup;

final class Panel
{
    private const LOCK = Store::SETTINGS . '/.lock';
    private const TESTS = Store::SETTINGS . '/tests.json';

    // ------------------------------------------------------------- settings

    /** The settings as saved, validated (invalid entries left out). */
    public static function config(): array
    {
        $raw = Store::readJson(Store::CONFIG);
        return $raw === null ? Config::empty() : Config::validate($raw)[0];
    }

    /**
     * Changes the settings under a lock: $change gets the current settings and
     * returns the new ones, or a string (an error, nothing saved). The result
     * is validated as a whole before it is written.
     */
    public static function update(callable $change): ?string
    {
        $lock = @fopen(self::LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return 'cannot lock the settings';
        }
        try {
            $new = $change(self::config());
            if (is_string($new)) {
                return $new;
            }
            [$valid, $errors] = Config::validate($new);
            if ($errors) {
                return implode('; ', $errors);
            }
            return Store::writeJson(Store::CONFIG, $valid, 0600) ? null : 'cannot write the settings';
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------- engine side

    public static function state(): array
    {
        return (Store::readJson(Store::STATE) ?? []) + ['jobs' => [], 'running' => null, 'config_errors' => [], 'destinations' => [], 'tasks' => []];
    }

    /** Is the engine working right now? (its lock is taken) */
    public static function engineBusy(): bool
    {
        $f = @fopen(Store::LOCK, 'r');
        if (!$f) {
            return false;
        }
        $busy = !flock($f, LOCK_SH | LOCK_NB);
        if (!$busy) {
            flock($f, LOCK_UN);
        }
        fclose($f);
        return $busy;
    }

    /** Asks the engine to run a job (at its next minute). */
    public static function requestRun(string $jobId): bool
    {
        if (!Config::isId($jobId)) {
            return false;
        }
        $path = Store::QUEUE . '/run-' . $jobId . '-' . bin2hex(random_bytes(4)) . '.json';
        return Store::writeAtomic($path, json_encode(['job' => $jobId, 'at' => time()]) . "\n", 0640);
    }

    public static function pendingRuns(): array
    {
        $out = [];
        foreach (@scandir(Store::QUEUE) ?: [] as $f) {
            if (preg_match('/^run-([a-f0-9]{12})-[a-f0-9]{8}\.json$/', $f, $m)) {
                $out[$m[1]] = true;
            }
        }
        return $out;
    }

    /**
     * Asks the engine to restore a mailbox or to remove the backups of a job
     * (at its next minute). $fields are checked by the engine again. Returns the
     * id of the request (its log and its result have it), or null.
     */
    public static function requestTask(string $type, array $fields): ?string
    {
        if (!in_array($type, ['restore', 'forget'], true) || !empty(Engine::task($type, $fields)['invalid'])) {
            return null;
        }
        $id = Config::newId();
        // the time first: the engine runs the requests in the order they were made
        $path = Store::QUEUE . '/' . time() . '-' . $type . '-' . $id . '.json';
        return Store::writeAtomic($path, json_encode($fields + ['at' => time()]) . "\n", 0640) ? $id : null;
    }

    /** Requests waiting for the engine: id => fields + type. */
    public static function pendingTasks(): array
    {
        $out = [];
        foreach (@scandir(Store::QUEUE) ?: [] as $f) {
            if (preg_match('/^\d{10}-(restore|forget)-([a-f0-9]{12})\.json$/', $f, $m)) {
                $out[$m[2]] = (Store::readJson(Store::QUEUE . '/' . $f) ?? []) + ['type' => $m[1]];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------- snapshots

    /**
     * restic on a destination for the admin's pages: read only (--no-lock: a
     * backup running meanwhile is not disturbed), with a cache of its own and a
     * time limit. $onLine gets each line of the output. Returns [Proc result, error].
     */
    private static function resticRead(string $did, array $d, array $args, callable $onLine): array
    {
        if (self::key() === null) {
            return [null, 'the encryption key is missing (' . Store::KEY . ')'];
        }
        $tmp = sys_get_temp_dir() . '/poste-backup-read-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700)) {
            return [null, 'cannot create a temporary folder'];
        }
        try {
            [$env, $err] = Destination::env($did, $d, $tmp, $tmp);
            if ($err) {
                return [null, $err];
            }
            $env['RESTIC_PASSWORD_FILE'] = Store::KEY;
            $env['RESTIC_CACHE_DIR'] = Store::CACHE . '/restic-admin';
            $r = Proc::run(array_merge(['restic', '-r', Destination::repository($did, $d), '--no-lock'], $args), $env, null, 180, $onLine,
                null, null, Engine::MAX_LIST);
            if ($r['exit'] !== 0) {
                if (preg_match('/Is there a repository at the following location|unable to open config file|repository does not exist/i', $r['err'])) {
                    return [null, 'there are no backups on this destination yet'];
                }
                return [null, 'cannot read the snapshots: ' . Engine::lastLine($r['err'])];
            }
            return [$r, null];
        } finally {
            foreach (@scandir($tmp) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink("$tmp/$f");
                }
            }
            @rmdir($tmp);
        }
    }

    /**
     * The snapshots of a destination, newest first: [[id, short, time, job,
     * paths], ...] or an error. job: the id in the tag "job:<id>" (null if none).
     */
    public static function snapshots(string $did, array $d): array
    {
        $json = '';
        [$r, $err] = self::resticRead($did, $d, ['snapshots', '--json'], function (string $line) use (&$json) {
            $json .= $line;
        });
        if ($err) {
            return [null, $err];
        }
        $list = json_decode($json, true);
        if (!is_array($list)) {
            return [null, 'cannot read the snapshots: ' . Engine::lastLine($r['err'] ?: 'not valid JSON')];
        }
        $out = [];
        foreach ($list as $s) {
            if (!is_array($s) || !Engine::isSnapshotId($s['id'] ?? null) || !is_string($s['time'] ?? null)) {
                continue;
            }
            try {
                $t = (new \DateTimeImmutable($s['time']))->getTimestamp();
            } catch (\Exception $e) {
                continue;
            }
            $job = null;
            foreach ((array) ($s['tags'] ?? []) as $tag) {
                if (is_string($tag) && preg_match('/^job:([a-f0-9]{12})$/', $tag, $m)) {
                    $job = $m[1];
                }
            }
            $paths = array_values(array_filter((array) ($s['paths'] ?? []), 'is_string'));
            $out[] = ['id' => $s['id'], 'short' => substr($s['id'], 0, 8), 'time' => $t, 'job' => $job, 'paths' => $paths];
        }
        usort($out, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        return [$out, null];
    }

    /**
     * What a snapshot holds under $root (/data/domains, or one mailbox's folder):
     * for each mailbox (the folders two levels under /data/domains) its messages,
     * and for each of its folders ('' = the inbox, '.Name' as Dovecot names it on
     * disk) its messages. One read of the snapshot. Returns
     * [['time' => unix time, 'boxes' => [address => ['messages' => n, 'folders' => [key => n]]]], error].
     */
    public static function snapshotContents(string $did, array $d, string $snap, string $root = '/data/domains'): array
    {
        if (!Engine::isSnapshotId($snap)) {
            return [null, 'invalid id'];
        }
        $time = null;
        $boxes = [];
        [$r, $err] = self::resticRead($did, $d, ['ls', '--json', '--recursive', $snap, $root], function (string $line) use (&$time, &$boxes) {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                return;
            }
            if (($j['struct_type'] ?? '') === 'snapshot' && is_string($j['time'] ?? null)) {
                try {
                    $time = (new \DateTimeImmutable($j['time']))->getTimestamp();
                } catch (\Exception $e) {
                }
                return;
            }
            $path = (string) ($j['path'] ?? '');
            if (preg_match('#^/data/domains/([^/]+)/([^/]+)(/.*)?$#', $path, $m) !== 1) {
                return;
            }
            $a = $m[2] . '@' . $m[1];
            if (!Config::isAddress($a)) {
                return;
            }
            $boxes[$a] = $boxes[$a] ?? ['messages' => 0, 'folders' => []];
            // the inbox is Maildir/cur and Maildir/new, a folder Maildir/.Name/cur and /new
            if (preg_match('#^/Maildir(?:/(\.[^/]+))?/(cur|new)(/[^/]+)?$#', $m[3] ?? '', $f) === 1) {
                $key = $f[1] ?? '';
                $boxes[$a]['folders'][$key] = $boxes[$a]['folders'][$key] ?? 0;
                if (($j['type'] ?? '') === 'file' && ($f[3] ?? '') !== '') {
                    $boxes[$a]['folders'][$key]++;
                    $boxes[$a]['messages']++;
                }
            }
        });
        if ($err) {
            return [null, $err];
        }
        if ($time === null) {
            return [null, 'cannot read the snapshot: ' . Engine::lastLine($r['err'] ?: 'no snapshot')];
        }
        ksort($boxes);
        return [['time' => $time, 'boxes' => $boxes], null];
    }

    /**
     * One page of the messages of a mailbox in a snapshot, to choose single
     * messages: the snapshot's files (read once, then from a cache) with the
     * sender and subject the index knows. $folder: null for every folder.
     * Returns [['total' => n, 'rows' => [[path, folder, time, size, sender, subject], ...]], error].
     */
    public static function snapshotMessages(string $did, array $d, string $snap, string $address, ?string $folder, string $q, int $page): array
    {
        if (!Engine::isSnapshotId($snap) || !Config::isAddress($address)) {
            return [null, 'invalid id'];
        }
        [$local, $domain] = explode('@', $address, 2);
        $maildir = '/data/domains/' . $domain . '/' . $local . '/Maildir';
        $key = $did . '/' . $snap . '/' . $address;
        try {
            $db = Index::cache();
            $err = Index::fill($db, $key, $maildir, function (callable $add) use ($did, $d, $snap, $maildir) {
                $seen = false;
                [$r, $err] = self::resticRead($did, $d, ['ls', '--json', '--recursive', $snap, $maildir], function (string $line) use ($add, &$seen) {
                    $j = json_decode($line, true);
                    if (!is_array($j)) {
                        return;
                    }
                    if (($j['struct_type'] ?? '') === 'snapshot') {
                        $seen = true;
                    }
                    $add($j);
                });
                // a snapshot that is not there: restic only warns, and the list would be empty
                return $err ?? ($seen ? null : 'cannot read the snapshot: ' . Engine::lastLine($r['err'] ?: 'no snapshot'));
            });
            if ($err !== null) {
                return [null, $err];
            }
            return [Index::page($db, $key, strtolower($address), $folder, $q, $page), null];
        } catch (\Throwable $e) {
            return [null, 'cannot read the list of the messages: ' . $e->getMessage()];
        }
    }

    /** A folder of a Maildir as people read it: '' the inbox (null), '.Clients.2024' "Clients / 2024". */
    public static function folderName(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        // Dovecot keeps non-ASCII names in modified UTF-7, and "." between levels
        $name = @mb_convert_encoding(substr($key, 1), 'UTF-8', 'UTF7-IMAP');
        return str_replace('.', ' / ', is_string($name) && $name !== '' ? $name : substr($key, 1));
    }

    // ------------------------------------------------------------- mailboxes

    /** Mailboxes of the server (not the redirects), from Poste.io's database. */
    public static function mailboxes(): array
    {
        try {
            $db = new \SQLite3('/data/users.db', SQLITE3_OPEN_READONLY);
            $db->busyTimeout(10000);
            $res = $db->query('SELECT address FROM users WHERE redirectOnly IS NOT 1 ORDER BY domainName, address');
            $out = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (Config::isAddress($row['address'])) {
                    $out[] = strtolower($row['address']);
                }
            }
            $db->close();
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Every address of the server: address => mailbox | redirect, from Poste.io's database. */
    public static function addresses(): array
    {
        try {
            $db = new \SQLite3('/data/users.db', SQLITE3_OPEN_READONLY);
            $db->busyTimeout(10000);
            $res = $db->query('SELECT address, redirectOnly FROM users');
            $out = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (Config::isAddress($row['address'])) {
                    $out[strtolower($row['address'])] = $row['redirectOnly'] ? 'redirect' : 'mailbox';
                }
            }
            $db->close();
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** The folders of a mailbox of this server, as Dovecot names them ("." between levels), or null. */
    public static function mailboxFolders(string $address): ?array
    {
        if (!in_array(strtolower($address), self::mailboxes(), true)) {
            return null;
        }
        $out = [];
        $r = Proc::run(['doveadm', 'mailbox', 'list', '-u', strtolower($address)], ['HOME' => Store::CACHE], null, 60,
            function (string $line) use (&$out) {
                if (trim($line) !== '') {
                    $out[] = trim($line);
                }
            });
        if ($r['exit'] !== 0) {
            return null;
        }
        sort($out);
        return $out;
    }

    // ------------------------------------------------------------- the key

    public static function key(): ?string
    {
        if (!is_file(Store::KEY) || is_link(Store::KEY)) {
            return null;
        }
        $k = trim((string) file_get_contents(Store::KEY));
        return strlen($k) >= 32 ? $k : null;
    }

    public static function keyDownloaded(): ?int
    {
        $t = is_file(Store::KEY_DOWNLOADED) ? trim((string) file_get_contents(Store::KEY_DOWNLOADED)) : '';
        return ctype_digit($t) ? (int) $t : null;
    }

    public static function markKeyDownloaded(): void
    {
        Store::writeAtomic(Store::KEY_DOWNLOADED, time() . "\n", 0600);
    }

    // ------------------------------------------------------------- SFTP keys

    /**
     * The SSH host keys a server offers now: [[key, fingerprint], ...] or an
     * error. "key" is "type base64" (what is stored once confirmed).
     */
    public static function scanHostKeys(string $host, int $port): array
    {
        $e = [];
        Config::host($host, $e);
        if ($e || $port < 1 || $port > 65535) {
            return [null, 'invalid server or port'];
        }
        $r = Proc::run(['ssh-keyscan', '-T', '10', '-p', (string) $port, $host], ['HOME' => '/tmp'], null, 30);
        $keys = [];
        foreach (explode("\n", $r['out']) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 3 || $f[0][0] === '#') {
                continue;
            }
            $k = $f[1] . ' ' . $f[2];
            $err = [];
            if (Config::hostKeys([$k], $err) && !$err) {
                $fp = self::fingerprint($k);
                if ($fp !== null) {
                    $keys[$k] = [$k, $fp];
                }
            }
        }
        if (!$keys) {
            return [null, 'the server did not answer with an SSH key (' . Engine::lastLine($r['err']) . ')'];
        }
        ksort($keys);
        return [array_values($keys), null];
    }

    /** "ED25519 SHA256:..." of a key "type base64". */
    public static function fingerprint(string $key): ?string
    {
        $r = Proc::run(['ssh-keygen', '-l', '-E', 'sha256', '-f', '-'], ['HOME' => '/tmp'], "host $key\n", 10);
        // "256 SHA256:abc host (ED25519)"
        if ($r['exit'] === 0 && preg_match('/^\d+ (SHA256:[A-Za-z0-9+\/]+) .* \(([A-Z0-9-]+)\)$/', trim($r['out']), $m)) {
            return $m[2] . ' ' . $m[1];
        }
        return null;
    }

    /** Creates the key pair of an SFTP destination (ed25519, no passphrase). */
    public static function makeKeyPair(string $id): ?string
    {
        if (!Config::isId($id)) {
            return 'invalid id';
        }
        $key = Destination::sshKey($id);
        foreach ([$key, "$key.pub"] as $f) {
            if (file_exists($f) || is_link($f)) {
                @unlink($f);
            }
        }
        $r = Proc::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'poste-backup', '-f', $key], ['HOME' => '/tmp'], null, 30);
        if ($r['exit'] !== 0 || !is_file($key)) {
            return 'ssh-keygen failed: ' . Engine::lastLine($r['err']);
        }
        @chmod($key, 0600);
        return null;
    }

    public static function publicKey(string $id): ?string
    {
        $pub = Destination::sshKey($id) . '.pub';
        return Config::isId($id) && is_file($pub) && !is_link($pub) ? trim((string) file_get_contents($pub)) : null;
    }

    // ------------------------------------------------------------- the test

    /**
     * Tests a destination as the engine will use it: reachable, the folder
     * free or already a repository, space left, a test file written and
     * removed. Returns ['status' => ok|warning|error, 'lines' => [...]];
     * remembered for the admin's pages.
     */
    public static function test(string $id, array $d): array
    {
        [$status, $lines] = self::probe($id, $d);
        return self::remember($id, $status, $lines);
    }

    /**
     * The same test for values not saved yet (the form's Test button): nothing
     * is remembered. $id may be a new id that is in no settings file.
     */
    public static function check(string $id, array $d): array
    {
        [$status, $lines] = self::probe($id, $d);
        return ['status' => $status, 'lines' => $lines];
    }

    /** @return array [status, lines] */
    private static function probe(string $id, array $d): array
    {
        $lines = [];
        $status = 'ok';
        $tmp = sys_get_temp_dir() . '/poste-backup-test-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700)) {
            return ['error', ['cannot create a temporary folder']];
        }
        try {
            if ($d['type'] === 'local') {
                $err = Destination::checkLocal($d['path']);
                if ($err) {
                    return ['error', [$err]];
                }
                $lines[] = 'mounted volume, not on the disk of /data';
                $free = @disk_free_space($d['path']);
                if ($free !== false) {
                    $lines[] = 'free space: ' . self::size((int) $free);
                }
                $lines[] = is_file($d['path'] . '/config') ? 'it already holds a backup repository' : 'no repository yet: it is created at the first run';
                return ['ok', $lines];
            }

            [$env, $err] = Destination::env($id, $d, $tmp, $tmp);
            if ($err) {
                return ['error', [$err]];
            }
            $path = Destination::rclonePath($id, $d);
            $ls = Proc::run(['rclone', 'lsf', '--max-depth', '1', $path], $env, null, 60);
            if ($ls['exit'] === 0) {
                $names = array_filter(array_map('trim', explode("\n", $ls['out'])), 'strlen');
                if (in_array('config', $names, true)) {
                    $lines[] = 'reachable; it already holds a backup repository';
                } elseif ($names) {
                    return ['error', ['reachable, but the folder is not empty and is not a backup repository: choose an empty folder']];
                } else {
                    $lines[] = 'reachable; empty folder, the repository is created at the first run';
                }
            } elseif (stripos($ls['err'], 'directory not found') !== false) {
                $lines[] = 'reachable; the folder does not exist yet, it is created at the first run';
            } else {
                return ['error', ['not reachable: ' . Engine::lastLine($ls['err'])]];
            }

            if ($d['type'] === 's3') {
                $lines[] = 'free space: not reported by S3';
            } elseif ($d['type'] === 'smb') {
                $sp = Destination::smbSpace($d, $tmp);
                $lines[] = $sp ? 'free space: ' . self::size($sp['free']) . ' of ' . self::size($sp['total']) : 'free space: not reported by the server';
            } else {
                $about = Proc::run(['rclone', 'about', '--json', $path], $env, null, 60);
                if ($about['exit'] !== 0) {
                    // the folder may not exist yet: ask the share (SMB) or the home (SFTP)
                    $base = Destination::remote($id) . ':' . ($d['type'] === 'smb' ? $d['share'] : '');
                    $about = Proc::run(['rclone', 'about', '--json', $base], $env, null, 60);
                }
                $a = json_decode($about['out'], true);
                if ($about['exit'] === 0 && is_array($a) && isset($a['free'])) {
                    $lines[] = 'free space: ' . self::size((int) $a['free']) . (isset($a['total']) ? ' of ' . self::size((int) $a['total']) : '');
                } else {
                    $lines[] = 'free space: not reported by the server';
                }
            }

            // write and remove a small file where the repository goes
            $probe = rtrim($path, '/') . '/.poste-backup-write-test';
            if ($d['type'] === 's3') {
                Proc::run(['rclone', 'mkdir', Destination::remote($id) . ':' . $d['bucket']], $env, null, 60);
            }
            $w = Proc::run(['rclone', 'rcat', $probe], $env, "poste-backup write test\n", 60);
            if ($w['exit'] !== 0) {
                return ['error', array_merge($lines, ['cannot write: ' . Engine::lastLine($w['err'])])];
            }
            $rm = Proc::run(['rclone', 'deletefile', $probe], $env, null, 60);
            $lines[] = $rm['exit'] === 0 ? 'write test: ok' : 'write test: written, but the test file could not be removed';
            if ($rm['exit'] !== 0) {
                $status = 'warning';
            }
            return [$status, $lines];
        } finally {
            foreach (@scandir($tmp) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink("$tmp/$f");
                }
            }
            @rmdir($tmp);
        }
    }

    /** Last test of each destination: id => [status, lines, time]. */
    public static function tests(): array
    {
        return Store::readJson(self::TESTS) ?? [];
    }

    private static function remember(string $id, string $status, array $lines): array
    {
        $all = self::tests();
        $all[$id] = ['status' => $status, 'lines' => $lines, 't' => time()];
        Store::writeJson(self::TESTS, $all, 0600);
        return ['status' => $status, 'lines' => $lines];
    }

    public static function forgetTest(string $id): void
    {
        $all = self::tests();
        unset($all[$id]);
        Store::writeJson(self::TESTS, $all, 0600);
    }

    public static function size(int $bytes): string
    {
        return Engine::size($bytes);
    }
}
