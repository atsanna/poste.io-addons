<?php
/*
 * poste.io-addons, backup add-on: where things are kept, and safe file writes.
 *
 * Everything runs as the user "mail": the admin (php-fpm) and the engine
 * (cron). Nothing the admin writes can make the engine do more than "mail"
 * can already do. Root only makes /data/var/backup at start and gives it to
 * mail (90-backup.sh).
 *
 * /data/var/backup (on the volume), all mail:mail:
 *   settings/  written by the admin
 *     config.json      the settings (0600)
 *     restic.key       repository password, made at the first start (0600);
 *                      never part of a backup, downloaded from the admin
 *     key-downloaded   when the key was last downloaded
 *     queue/           requests of the admin ("run now")
 *     ssh/<id>, .pub   key pair of an SFTP destination
 *   engine/    written by the engine
 *     state.json       last runs (0640)
 *     history.jsonl    one line per job run and destination
 *     logs/            output of each run
 *     staging/         consistent copies of the databases during a run (0700)
 */

namespace PosteBackup;

final class Store
{
    public const DIR = '/data/var/backup';
    public const SETTINGS = self::DIR . '/settings';
    public const CONFIG = self::SETTINGS . '/config.json';
    public const KEY = self::SETTINGS . '/restic.key';
    public const KEY_DOWNLOADED = self::SETTINGS . '/key-downloaded';
    public const SSH = self::SETTINGS . '/ssh';
    public const QUEUE = self::SETTINGS . '/queue';
    public const ENGINE = self::DIR . '/engine';
    public const STATE = self::ENGINE . '/state.json';
    public const HISTORY = self::ENGINE . '/history.jsonl';
    public const LOGS = self::ENGINE . '/logs';
    public const STAGING = self::ENGINE . '/staging';

    // outside the volume: rebuilt when needed, never backed up
    public const CACHE = '/var/cache/poste-backup';
    public const LOCK = '/run/poste-backup.lock';
    public const TMP = '/run/poste-backup';           // made by 90-backup.sh for mail (0700)
    public const START_ERROR = '/run/poste-backup.start-error';
    public const RCLONE_CONF = '/usr/local/lib/poste-backup/rclone.conf';

    public const MAX_JSON = 2 * 1024 * 1024;
    public const MAX_LOGS = 5000;                     // a safety limit; the days to keep are a setting

    /** Reads a JSON file; null if missing, too big or not valid JSON. */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_JSON) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Writes a file atomically: a temporary file in the same folder, its mode,
     * then rename. Readers see the old file or the new one, never half of it.
     */
    public static function writeAtomic(string $path, string $data, int $mode, ?string $group = null): bool
    {
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $data) !== strlen($data)) {
            @unlink($tmp);
            return false;
        }
        if (!@chmod($tmp, $mode) || ($group !== null && !@chgrp($tmp, $group)) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public static function writeJson(string $path, array $data, int $mode, ?string $group = null): bool
    {
        // a message from a remote server may not be valid UTF-8: replaced, never a lost write
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $json !== false && self::writeAtomic($path, $json . "\n", $mode, $group);
    }

    /** Appends one JSON line (one write call: lines never mix). */
    public static function appendJsonLine(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }
        $new = !file_exists($path);
        @file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            @chmod($path, 0640);
        }
    }

    /** The last $n lines of a JSON-lines file, newest last. */
    public static function tailJsonLines(string $path, int $n): array
    {
        if (!is_file($path)) {
            return [];
        }
        $f = @fopen($path, 'r');
        if (!$f) {
            return [];
        }
        // read backwards in blocks until there are enough lines
        $size = filesize($path);
        $buf = '';
        $pos = $size;
        while ($pos > 0 && substr_count($buf, "\n") <= $n) {
            $read = min(65536, $pos);
            $pos -= $read;
            fseek($f, $pos);
            $buf = fread($f, $read) . $buf;
        }
        fclose($f);
        $lines = array_slice(array_filter(explode("\n", $buf), 'strlen'), -$n);
        $out = [];
        foreach ($lines as $l) {
            $d = json_decode($l, true);
            if (is_array($d)) {
                $out[] = $d;
            }
        }
        return $out;
    }
}
