<?php
/*
 * poste.io-addons, backup add-on: the list of the messages of the backups.
 *
 * To restore single messages, the admin shows the messages of a snapshot
 * with their sender, subject and date. A snapshot holds files only: those
 * details come from this index, which the engine brings up to date before
 * every run with mail, from the mailboxes' files as they are then (the
 * header of each new file is read once). A message is known by its Maildir
 * file name without the flags: the same in every snapshot, and after a move
 * to another folder (Dovecot's own message id is not: an imported message
 * keeps the id of its origin in a new file). A message that is no longer in its mailbox stays in the
 * index (older snapshots still hold it) until no snapshot kept on any
 * destination can hold it.
 *
 *   /data/var/backup/engine/index.db    the index (SQLite, mail 0600); in the
 *                                       backup of the configuration as a
 *                                       consistent copy, like the databases
 *   /var/cache/poste-backup/listing.db  the files of the last snapshots shown
 *                                       in the admin (rebuilt when needed)
 */

namespace PosteBackup;

final class Index
{
    public const PATH = Store::ENGINE . '/index.db';
    public const LISTING = Store::CACHE . '/listing.db';
    public const PAGE = 50;                 // messages per page in the admin
    public const MAX_TEXT = 300;            // characters kept of a subject or a sender
    private const LISTINGS = 5;             // snapshots' mailboxes kept in the cache
    private const HEADER_SECONDS = 1200;    // reading headers in one run (the first one reads every message): the rest next run

    // ------------------------------------------------------------- the index (engine)

    /** The index, made if missing. */
    public static function open(): \PDO
    {
        if (is_link(self::PATH)) {
            throw new \RuntimeException(self::PATH . ' is a symbolic link');
        }
        $new = !file_exists(self::PATH);
        $db = new \PDO('sqlite:' . self::PATH, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 60]);
        if ($new) {
            @chmod(self::PATH, 0600);
        }
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS msg (mailbox TEXT NOT NULL, guid TEXT NOT NULL, received INTEGER, size INTEGER,
                   subject TEXT, sender TEXT, gone INTEGER, PRIMARY KEY (mailbox, guid)) WITHOUT ROWID');
        $db->exec('CREATE INDEX IF NOT EXISTS msg_gone ON msg (gone) WHERE gone IS NOT NULL');
        return $db;
    }

    /** The mailboxes of the server (not the redirects), from Poste.io's database; null if it cannot be read. */
    public static function mailboxes(): ?array
    {
        try {
            $db = new \SQLite3('/data/users.db', SQLITE3_OPEN_READONLY);
            $db->busyTimeout(10000);
            $res = $db->query('SELECT address FROM users WHERE redirectOnly IS NOT 1');
            $out = [];
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                if (Config::isAddress($row['address'])) {
                    $out[] = strtolower($row['address']);
                }
            }
            $db->close();
            sort($out);
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Brings the index up to date for these mailboxes. With $server (every
     * mailbox of the server), the messages of mailboxes no longer there are
     * marked as gone too. A mailbox that cannot be read is left as it was.
     * Returns [new messages, messages no longer there, problems and notes (one line each)].
     */
    public static function update(array $boxes, ?array $server): array
    {
        $problems = [];
        // a damaged index is set aside and made again: it must never stop a backup
        try {
            $db = self::open();
            $ok = $db->query('PRAGMA quick_check')->fetchColumn();
        } catch (\Throwable $e) {
            $ok = $e->getMessage();
        }
        if ($ok !== 'ok') {
            $db = null;
            foreach (['', '-wal', '-shm'] as $x) {
                @rename(self::PATH . $x, self::PATH . '.damaged' . $x);
            }
            $problems[] = 'it was damaged (' . self::text($ok) . '): set aside as ' . basename(self::PATH) . '.damaged and made again';
            $db = self::open();
        }
        $now = time();
        $deadline = $now + self::HEADER_SECONDS;
        $new = $gone = $left = 0;
        foreach ($boxes as $box) {
            try {
                [$n, $g, $l] = self::updateMailbox($db, $box, $now, $deadline);
                $new += $n;
                $gone += $g;
                $left += $l;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $problems[] = "not updated for $box: " . $e->getMessage();
            }
        }
        if ($left > 0) {
            $problems[] = "$left message(s) left for the next run (the headers are read for " . self::HEADER_SECONDS . ' seconds at most)';
        }
        if ($server !== null) {
            $known = $db->query('SELECT DISTINCT mailbox FROM msg WHERE gone IS NULL')->fetchAll(\PDO::FETCH_COLUMN);
            $st = $db->prepare('UPDATE msg SET gone = ? WHERE mailbox = ? AND gone IS NULL');
            foreach (array_diff($known, $server) as $m) {
                $st->execute([$now, $m]);
                $gone += $st->rowCount();
            }
        }
        return [$new, $gone, $problems];
    }

    /**
     * One mailbox, from its files (the same names the snapshots hold): what is
     * in it now, the headers of the files not seen before, what is no longer
     * there. Links are never followed. Returns [new, gone, left for the next run].
     */
    private static function updateMailbox(\PDO $db, string $box, int $now, int $deadline): array
    {
        $home = Engine::home($box);
        $maildir = $home . '/Maildir';
        if ($home === null || !is_dir($maildir) || is_link($maildir)) {
            throw new \RuntimeException('its Maildir is not found');
        }
        // its folders: the inbox (cur, new) and every ".Name" folder
        $top = @scandir($maildir);
        if ($top === false) {
            // an incomplete list would mark messages as gone: nothing changes for this mailbox
            throw new \RuntimeException('cannot read its Maildir');
        }
        $subs = ['cur', 'new'];
        foreach ($top as $e) {
            if ($e !== '.' && $e !== '..' && $e[0] === '.' && is_dir("$maildir/$e") && !is_link("$maildir/$e")) {
                array_push($subs, "$e/cur", "$e/new");
            }
        }
        $db->exec('CREATE TEMP TABLE IF NOT EXISTS cur (guid TEXT PRIMARY KEY, path TEXT NOT NULL) WITHOUT ROWID');
        $db->exec('DELETE FROM cur');
        $ins = $db->prepare('INSERT OR IGNORE INTO cur VALUES (?, ?)');
        $db->beginTransaction();
        foreach ($subs as $sub) {
            $folder = dirname($sub);
            if ($folder !== '.' && (!is_dir("$maildir/$folder") || is_link("$maildir/$folder"))) {
                throw new \RuntimeException("$folder changed while it was read");
            }
            $dir = "$maildir/$sub";
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            // entry by entry: a folder may hold a great many messages
            $h = @opendir($dir);
            if ($h === false) {
                throw new \RuntimeException("cannot read $sub");
            }
            while (($n = readdir($h)) !== false) {
                if ($n !== '' && $n[0] !== '.') {
                    $ins->execute([self::guid($n), "$sub/$n"]);
                }
            }
            closedir($h);
        }
        $db->commit();

        // the headers of the files the index does not know yet, a thousand at a time
        $st = $db->prepare('SELECT guid, path FROM cur c WHERE guid > ? AND NOT EXISTS (SELECT 1 FROM msg m WHERE m.mailbox = ? AND m.guid = c.guid)
                            ORDER BY guid LIMIT 1000');
        $up = $db->prepare('INSERT INTO msg (mailbox, guid, received, size, subject, sender, gone) VALUES (?, ?, ?, ?, ?, ?, NULL)
                            ON CONFLICT (mailbox, guid) DO UPDATE SET gone = NULL');
        $new = $left = 0;
        $last = '';
        while (time() < $deadline) {
            $st->execute([$last, $box]);
            $rows = $st->fetchAll(\PDO::FETCH_NUM);
            if (!$rows) {
                break;
            }
            $db->beginTransaction();
            foreach ($rows as [$guid, $path]) {
                $last = $guid;
                $f = "$maildir/$path";
                clearstatcache(true, $f);
                if (is_link($f) || !is_file($f)) {
                    continue;      // moved or deleted meanwhile: the next run sees it where it is
                }
                $hd = self::headers($f);
                if ($hd === null) {
                    continue;      // not readable now: read again next run
                }
                $up->execute([$box, $guid, (int) @filemtime($f), (int) @filesize($f), $hd[0], $hd[1]]);
                $new++;
            }
            $db->commit();
        }
        if (time() >= $deadline) {
            $c = $db->prepare('SELECT count(*) FROM cur c WHERE NOT EXISTS (SELECT 1 FROM msg m WHERE m.mailbox = ? AND m.guid = c.guid)');
            $c->execute([$box]);
            $left = (int) $c->fetchColumn();
        }

        // no longer in the mailbox (moved out, deleted), or back again
        $st = $db->prepare('UPDATE msg SET gone = ? WHERE mailbox = ? AND gone IS NULL AND guid NOT IN (SELECT guid FROM cur)');
        $st->execute([$now, $box]);
        $gone = $st->rowCount();
        $db->prepare('UPDATE msg SET gone = NULL WHERE mailbox = ? AND gone IS NOT NULL AND guid IN (SELECT guid FROM cur)')->execute([$box]);
        $db->exec('DELETE FROM cur');
        return [$new, $gone, $left];
    }

    /** A message's key: its Maildir file name up to the flags (":2,..."), as in every snapshot. */
    public static function guid(string $name): string
    {
        $i = strpos($name, ':');
        return $i === false ? $name : substr($name, 0, $i);
    }

    /**
     * Subject and sender of a message file, decoded (encoded words in any
     * charset, folded lines): only its header is read (at most 64 KB). Null
     * when the file cannot be opened.
     */
    public static function headers(string $file): ?array
    {
        $h = @fopen($file, 'rb');
        if (!$h) {
            return null;
        }
        $head = '';
        while (!feof($h) && strlen($head) < 65536) {
            $head .= (string) fread($h, 8192);
            if (preg_match('/\r?\n\r?\n/', $head, $m, PREG_OFFSET_CAPTURE) === 1) {
                $head = substr($head, 0, $m[0][1]);
                break;
            }
        }
        fclose($h);
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);
        $out = ['subject' => null, 'from' => null];
        foreach (preg_split('/\r?\n/', $head) as $line) {
            if (preg_match('/^(subject|from)[ \t]*:[ \t]*(.*)$/i', $line, $m) === 1 && $out[strtolower($m[1])] === null) {
                $out[strtolower($m[1])] = $m[2];
            }
        }
        $decode = function (?string $v): string {
            if ($v === null) {
                return '';
            }
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            return self::text($d === false ? $v : $d);
        };
        return [$decode($out['subject']), $decode($out['from'])];
    }

    /**
     * Removes the messages that were no longer in their mailbox before $before
     * (the oldest snapshot with mail kept on any destination): no backup holds
     * them any more. Returns how many.
     */
    public static function prune(int $before): int
    {
        if (!is_file(self::PATH) || is_link(self::PATH)) {
            return 0;
        }
        $st = self::open()->prepare('DELETE FROM msg WHERE gone IS NOT NULL AND gone < ?');
        $st->execute([$before]);
        return $st->rowCount();
    }

    /** A header as it is shown: valid UTF-8, one line, at most MAX_TEXT characters. */
    public static function text($s): string
    {
        $s = mb_scrub(is_string($s) ? $s : '', 'UTF-8');
        // no format characters (right-to-left overrides, zero-width): a subject must not look like another one
        $s = preg_replace('/\p{Cf}+/u', '', $s);
        return mb_substr(trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $s)), 0, self::MAX_TEXT);
    }

    // ------------------------------------------------------------- a snapshot's messages (admin)

    /**
     * A message chosen for a restore: its path in the snapshot's Maildir, as
     * "cur/<name>" (the inbox) or ".<Folder>/cur/<name>" (also "new").
     */
    public static function isMessagePath($p): bool
    {
        return is_string($p) && preg_match('#^(?:(\.[^/\x00-\x1f\x7f]{1,255})/)?(?:cur|new)/([^/\x00-\x1f\x7f]{1,255})$#D', $p, $m) === 1
            && ($m[1] ?? '') !== '..' && $m[2] !== '.' && $m[2] !== '..';
    }

    /**
     * One node of "restic ls --json --recursive" under a Maildir: a message
     * file as [path in the Maildir, folder ('' or '.Name'), guid, time, size],
     * or null for anything else.
     */
    public static function listingNode(string $maildir, array $j): ?array
    {
        $path = $j['path'] ?? null;
        if (($j['type'] ?? '') !== 'file' || !is_string($path) || strpos($path, $maildir . '/') !== 0) {
            return null;
        }
        $rel = substr($path, strlen($maildir) + 1);
        if (!self::isMessagePath($rel)) {
            return null;
        }
        $folder = strpos($rel, '/') !== false && $rel[0] === '.' ? substr($rel, 0, strpos($rel, '/')) : '';
        $name = substr($rel, strrpos($rel, '/') + 1);
        $guid = self::guid($name);
        $time = 0;
        if (is_string($j['mtime'] ?? null)) {
            try {
                // restic gives nanoseconds: PHP reads up to microseconds
                $time = (new \DateTimeImmutable(preg_replace('/(\.\d{1,6})\d*/', '$1', $j['mtime'])))->getTimestamp();
            } catch (\Exception $e) {
                $time = 0;
            }
        }
        return [$rel, $folder, $guid, $time, (int) ($j['size'] ?? 0)];
    }

    /** The cache of the snapshots' files (the admin's), made if missing. */
    public static function cache(): \PDO
    {
        if (is_link(self::LISTING)) {
            throw new \RuntimeException(self::LISTING . ' is a symbolic link');
        }
        $db = new \PDO('sqlite:' . self::LISTING, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 120]);
        @chmod(self::LISTING, 0600);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS lists (k TEXT PRIMARY KEY, t INTEGER NOT NULL) WITHOUT ROWID');
        $db->exec('CREATE TABLE IF NOT EXISTS files (k TEXT NOT NULL, path TEXT NOT NULL, folder TEXT NOT NULL, guid TEXT NOT NULL,
                   mtime INTEGER NOT NULL, size INTEGER NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS files_k ON files (k, mtime)');
        return $db;
    }

    /**
     * Makes sure the cache holds the files of $key: if not, $read(callable $add)
     * reads them ($add gets each node of restic's listing) and returns an error
     * or null. The listing is read into a table of this connection only, then
     * copied in one short write: a slow snapshot never holds up the other
     * pages. The oldest lists are dropped. Returns an error or null.
     */
    public static function fill(\PDO $db, string $key, string $maildir, callable $read): ?string
    {
        $has = $db->prepare('SELECT 1 FROM lists WHERE k = ?');
        $has->execute([$key]);
        $found = $has->fetchColumn();
        $has->closeCursor();
        if ($found) {
            try {
                $db->prepare('UPDATE lists SET t = ? WHERE k = ?')->execute([time(), $key]);
            } catch (\PDOException $e) {
                // only the order in which old lists are dropped: never worth an error
            }
            return null;
        }
        $db->exec('CREATE TEMP TABLE IF NOT EXISTS fill (path TEXT NOT NULL, folder TEXT NOT NULL, guid TEXT NOT NULL,
                   mtime INTEGER NOT NULL, size INTEGER NOT NULL)');
        $db->exec('DELETE FROM fill');
        $ins = $db->prepare('INSERT INTO fill VALUES (?, ?, ?, ?, ?)');
        $db->beginTransaction();
        try {
            $err = $read(function (array $j) use ($ins, $maildir) {
                $n = self::listingNode($maildir, $j);
                if ($n !== null) {
                    $ins->execute($n);
                }
            });
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        if ($err !== null) {
            $db->rollBack();
            return $err;
        }
        $db->commit();
        $db->exec('BEGIN IMMEDIATE');
        try {
            // another request may have stored it meanwhile
            $has->execute([$key]);
            $found = $has->fetchColumn();
            $has->closeCursor();
            if (!$found) {
                $db->prepare('DELETE FROM files WHERE k = ?')->execute([$key]);
                $db->prepare('INSERT INTO files SELECT ?, path, folder, guid, mtime, size FROM fill')->execute([$key]);
                $db->prepare('INSERT INTO lists VALUES (?, ?)')->execute([$key, time()]);
                $db->exec('DELETE FROM files WHERE k IN (SELECT k FROM lists ORDER BY t DESC LIMIT -1 OFFSET ' . self::LISTINGS . ')');
                $db->exec('DELETE FROM lists WHERE k IN (SELECT k FROM lists ORDER BY t DESC LIMIT -1 OFFSET ' . self::LISTINGS . ')');
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
        $db->exec('DELETE FROM fill');
        return null;
    }

    /**
     * One page of the messages of $key, newest first: in one folder ('' the
     * inbox) or in all (null), those whose sender or subject contains $q (case
     * does not matter, accented letters too). The index gives sender and
     * subject (null for a message it does not know). Returns
     * ['total' => n, 'rows' => [[path, folder, time, size, sender, subject], ...]].
     */
    public static function page(\PDO $db, string $key, string $mailbox, ?string $folder, string $q, int $page): array
    {
        $index = is_file(self::PATH) && !is_link(self::PATH);
        if ($index && !in_array('idx', $db->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_COLUMN, 1), true)) {
            $db->exec('ATTACH DATABASE ' . $db->quote(self::PATH) . ' AS idx');
        }
        // the admin only reads here (the engine's index above all)
        $db->exec('PRAGMA query_only = 1');
        $db->sqliteCreateFunction('bk_has', function ($text, $q) {
            return is_string($text) && mb_stripos($text, $q, 0, 'UTF-8') !== false ? 1 : 0;
        }, 2);
        $from = $index ? 'files f LEFT JOIN idx.msg m ON m.mailbox = :box AND m.guid = f.guid'
                       : 'files f LEFT JOIN (SELECT NULL AS guid, NULL AS subject, NULL AS sender) m ON 0';
        $where = 'f.k = :k';
        $args = [':k' => $key];
        if ($index) {
            $args[':box'] = $mailbox;
        }
        if ($folder !== null) {
            $where .= ' AND f.folder = :folder';
            $args[':folder'] = $folder;
        }
        if ($q !== '') {
            $where .= ' AND (bk_has(m.subject, :q1) OR bk_has(m.sender, :q2))';
            $args[':q1'] = $args[':q2'] = $q;
        }
        $c = $db->prepare("SELECT count(*) FROM $from WHERE $where");
        $c->execute($args);
        $total = (int) $c->fetchColumn();
        $c->closeCursor();
        $st = $db->prepare("SELECT f.path, f.folder, f.mtime, f.size, m.sender, m.subject FROM $from WHERE $where
                            ORDER BY f.mtime DESC, f.path LIMIT " . self::PAGE . ' OFFSET ' . (max(0, $page) * self::PAGE));
        $st->execute($args);
        return ['total' => $total, 'rows' => $st->fetchAll(\PDO::FETCH_NUM)];
    }
}
