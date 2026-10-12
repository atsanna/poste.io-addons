<?php
/*
 * poste.io-addons, backup add-on: the settings and their validation.
 *
 * The settings (/data/var/backup/settings/config.json) are written by the
 * admin and read by the backup engine, both as the user "mail". The same
 * rules are applied on both sides: the admin refuses what is not valid before
 * saving, and the engine validates the file again before using anything in it
 * (the file may have been changed behind the admin's back). Every value that
 * ends up in a command line or in the environment of a command is checked
 * here against a strict pattern; commands are never run through a shell.
 */

namespace PosteBackup;

final class Config
{
    public const TYPES = ['local', 'sftp', 'smb', 's3', 'rest'];
    public const EVERY = ['hour', 'day', 'week', 'month'];
    public const VERIFY_EVERY = ['never', 'day', 'week', 'month'];
    /** A verification of a destination when none is set: every Sunday at 04:00, a quarter of the data each time. */
    public const VERIFY_DEFAULT = ['every' => 'week', 'weekday' => 7, 'monthday' => 1, 'hour' => 4, 'minute' => 0, 'parts' => 4];
    public const MAX_PARTS = 52;
    /** Used space from which the bars turn yellow and red (and, later, the warnings), in percent. */
    public const SPACE_WARN = 80;
    public const SPACE_FULL = 90;
    public const KEEP = ['hourly', 'daily', 'weekly', 'monthly'];
    public const MAIL = ['none', 'all', 'selected'];

    // where a local destination (a volume mounted by the host: NFS, a disk...) may be
    public const LOCAL_ROOTS = ['backup', 'mnt', 'srv', 'media'];

    public const MAX_DESTINATIONS = 20;
    public const MAX_JOBS = 50;
    public const MAX_MAILBOXES = 1000;
    public const MAX_KEEP = 1000;
    /** How long the logs of the runs and the history (statistics) are kept, in days. */
    public const LOG_DAYS = 90;
    public const HISTORY_DAYS = 400;
    public const MAX_DAYS = 3650;

    /** An empty configuration. */
    public static function empty(): array
    {
        return ['version' => 1, 'settings' => ['timezone' => '', 'log_days' => self::LOG_DAYS, 'history_days' => self::HISTORY_DAYS,
                                               'space_warn' => self::SPACE_WARN, 'space_full' => self::SPACE_FULL],
                'destinations' => [], 'jobs' => []];
    }

    public static function isId($id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{12}$/', $id) === 1;
    }

    // An id is also an array key, and PHP turns a key made of digits only
    // ("123456789012": about one id in 300) into an integer, which isId()
    // refuses: the destination or job with that id was not saved ("an invalid
    // id left out"). So an id always has a letter.
    public static function newId(): string
    {
        do {
            $id = bin2hex(random_bytes(6));
        } while (preg_match('/[a-f]/', $id) !== 1);
        return $id;
    }

    /**
     * Validates a whole configuration.
     * Returns [valid configuration, errors]: an invalid destination or job is
     * left out (with its error), so one bad entry does not stop the others.
     */
    public static function validate($raw): array
    {
        $errors = [];
        $out = self::empty();
        if (!is_array($raw)) {
            return [$out, ['the settings file is not a JSON object']];
        }

        // the time zone of the schedules: '' = the container's (Schedule::zone)
        $tz = $raw['settings']['timezone'] ?? '';
        if ($tz !== '' && !Schedule::isZone($tz)) {
            $errors[] = 'time zone: not valid';
            $tz = '';
        }
        $out['settings']['timezone'] = $tz;
        // how long the logs and the history are kept (a missing value is the default)
        foreach (['log_days' => ['log days', self::LOG_DAYS], 'history_days' => ['history days', self::HISTORY_DAYS]] as $k => [$what, $default]) {
            if (!array_key_exists($k, (array) ($raw['settings'] ?? []))) {
                continue;
            }
            $e = [];
            $v = self::int($raw['settings'][$k], 1, self::MAX_DAYS, $what, $e);
            if ($e) {
                $errors = array_merge($errors, $e);
                continue;
            }
            $out['settings'][$k] = $v;
        }
        // the space warnings: both or none (a missing pair is the default)
        if (array_key_exists('space_warn', (array) ($raw['settings'] ?? [])) || array_key_exists('space_full', (array) ($raw['settings'] ?? []))) {
            [$warn, $full, $e] = self::spaceWarnings($raw['settings']['space_warn'] ?? null, $raw['settings']['space_full'] ?? null);
            if ($e) {
                $errors = array_merge($errors, $e);
            } else {
                $out['settings']['space_warn'] = $warn;
                $out['settings']['space_full'] = $full;
            }
        }

        $dests = is_array($raw['destinations'] ?? null) ? $raw['destinations'] : [];
        foreach ($dests as $id => $d) {
            if (count($out['destinations']) >= self::MAX_DESTINATIONS) {
                $errors[] = 'too many destinations';
                break;
            }
            if (!self::isId($id)) {
                $errors[] = 'destination with an invalid id left out';
                continue;
            }
            [$dest, $err] = self::destination($d);
            if ($err) {
                $errors[] = "destination $id: " . implode('; ', $err);
                continue;
            }
            $out['destinations'][$id] = $dest;
        }

        $jobs = is_array($raw['jobs'] ?? null) ? $raw['jobs'] : [];
        foreach ($jobs as $id => $j) {
            if (count($out['jobs']) >= self::MAX_JOBS) {
                $errors[] = 'too many jobs';
                break;
            }
            if (!self::isId($id)) {
                $errors[] = 'job with an invalid id left out';
                continue;
            }
            [$job, $err] = self::job($j, $out['destinations']);
            if ($err) {
                $errors[] = "job $id: " . implode('; ', $err);
                continue;
            }
            $out['jobs'][$id] = $job;
        }

        return [$out, $errors];
    }

    /**
     * Validates one destination. Returns [destination, errors].
     */
    public static function destination($d): array
    {
        $e = [];
        if (!is_array($d)) {
            return [null, ['not an object']];
        }
        $out = [];
        $out['name'] = self::name($d['name'] ?? null, $e);
        $type = $d['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            $e[] = 'unknown type';
            return [null, $e];
        }
        $out['type'] = $type;

        switch ($type) {
            case 'local':
                $out['path'] = self::localPath($d['path'] ?? null, $e);
                break;
            case 'sftp':
                $out['host'] = self::host($d['host'] ?? null, $e);
                $out['port'] = self::int($d['port'] ?? 22, 1, 65535, 'port', $e);
                $out['user'] = self::user($d['user'] ?? null, $e);
                // password, or a key pair made by the admin (the public key goes on the server)
                $out['auth'] = in_array($d['auth'] ?? null, ['password', 'key'], true) ? $d['auth'] : null;
                if ($out['auth'] === null) {
                    $e[] = 'login: password or key';
                } elseif ($out['auth'] === 'password') {
                    $out['password'] = self::secret($d['password'] ?? null, 'password', $e);
                }
                // required: the repository must not land in the user's home among other files
                $out['folder'] = self::folder($d['folder'] ?? '', $e);
                if ($out['folder'] === '' && !$e) {
                    $e[] = 'folder: required for SFTP';
                }
                $out['hostkeys'] = self::hostKeys($d['hostkeys'] ?? [], $e);
                break;
            case 'smb':
                $out['host'] = self::host($d['host'] ?? null, $e);
                $out['share'] = self::match($d['share'] ?? null, '/^[A-Za-z0-9._$-]{1,80}$/', 'share', $e);
                $out['folder'] = self::folder($d['folder'] ?? '', $e);
                $out['user'] = self::user($d['user'] ?? null, $e);
                $out['password'] = self::secret($d['password'] ?? null, 'password', $e);
                $out['domain'] = self::match($d['domain'] ?? '', '/^[A-Za-z0-9._-]{0,64}$/', 'domain', $e);
                break;
            case 'rest':
                // restic's REST server (rest-server), over HTTPS only, with its certificate confirmed in the admin
                $out['host'] = self::host($d['host'] ?? null, $e);
                $out['port'] = self::int($d['port'] ?? 8000, 1, 65535, 'port', $e);
                $out['folder'] = self::folder($d['folder'] ?? '', $e);
                if ($out['folder'] === '' && !$e) {
                    $e[] = 'repository: required';
                }
                $out['user'] = self::user($d['user'] ?? null, $e);
                $out['password'] = self::secret($d['password'] ?? null, 'password', $e);
                $out['append_only'] = self::bool($d['append_only'] ?? true, 'append-only', $e);
                $out['cert'] = self::certificate($d['cert'] ?? '', $e);
                break;
            case 's3':
                $out['endpoint'] = self::endpoint($d['endpoint'] ?? null, $e);
                $out['region'] = self::match($d['region'] ?? '', '/^[a-z0-9-]{0,32}$/', 'region', $e);
                $out['bucket'] = self::match($d['bucket'] ?? null, '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', 'bucket', $e);
                $out['folder'] = self::folder($d['folder'] ?? '', $e);
                $out['access_key'] = self::match($d['access_key'] ?? null, '/^[A-Za-z0-9+\/=._-]{1,128}$/', 'access key', $e);
                $out['secret_key'] = self::secret($d['secret_key'] ?? null, 'secret key', $e);
                break;
        }
        $out['verify'] = self::verify($d['verify'] ?? null, $e);

        return [$e ? null : $out, $e];
    }

    /** When a destination is verified and how much of its data is read each time; missing = VERIFY_DEFAULT. */
    public static function verify($v, array &$e): array
    {
        if ($v === null) {
            return self::VERIFY_DEFAULT;
        }
        if (!is_array($v) || !in_array($v['every'] ?? null, self::VERIFY_EVERY, true)) {
            $e[] = 'verification: unknown frequency';
            return self::VERIFY_DEFAULT;
        }
        return [
            'every' => $v['every'],
            'weekday' => self::int($v['weekday'] ?? 7, 1, 7, 'weekday', $e),
            'monthday' => self::int($v['monthday'] ?? 1, 1, 28, 'day of the month', $e),
            'hour' => self::int($v['hour'] ?? 4, 0, 23, 'hour', $e),
            'minute' => self::int($v['minute'] ?? 0, 0, 59, 'minute', $e),
            'parts' => self::int($v['parts'] ?? 4, 1, self::MAX_PARTS, 'parts of the data', $e),
        ];
    }

    /** The two space warnings, checked together. Returns [yellow, red, errors]. */
    public static function spaceWarnings($warn, $full): array
    {
        $e = [];
        $w = self::int($warn, 1, 99, 'yellow from', $e);
        $f = self::int($full, 1, 99, 'red from', $e);
        if (!$e && $w >= $f) {
            $e[] = 'space warnings: yellow must start below red';
        }
        return [$w, $f, $e];
    }

    /**
     * Validates one job against the valid destinations. Returns [job, errors].
     */
    public static function job($j, array $destinations): array
    {
        $e = [];
        if (!is_array($j)) {
            return [null, ['not an object']];
        }
        $out = [];
        $out['name'] = self::name($j['name'] ?? null, $e);
        $out['enabled'] = self::bool($j['enabled'] ?? null, 'enabled', $e);
        $out['config'] = self::bool($j['config'] ?? null, 'configuration', $e);

        $mail = $j['mail'] ?? null;
        if (!in_array($mail, self::MAIL, true)) {
            $e[] = 'mail: unknown choice';
            $mail = 'none';
        }
        $out['mail'] = $mail;
        $out['mailboxes'] = [];
        if ($mail === 'selected') {
            $boxes = $j['mailboxes'] ?? null;
            if (!is_array($boxes) || !$boxes) {
                $e[] = 'no mailbox selected';
            } elseif (count($boxes) > self::MAX_MAILBOXES) {
                $e[] = 'too many mailboxes';
            } else {
                foreach ($boxes as $b) {
                    if (!self::isAddress($b)) {
                        $e[] = 'invalid mailbox address';
                        break;
                    }
                    $out['mailboxes'][] = strtolower($b);
                }
                $out['mailboxes'] = array_values(array_unique($out['mailboxes']));
            }
        }
        if (!$out['config'] && $mail === 'none') {
            $e[] = 'nothing to back up';
        }

        $s = $j['schedule'] ?? null;
        if (!is_array($s) || !in_array($s['every'] ?? null, self::EVERY, true)) {
            $e[] = 'schedule: unknown frequency';
        } else {
            $out['schedule'] = [
                'every' => $s['every'],
                'minute' => self::int($s['minute'] ?? null, 0, 59, 'minute', $e),
                'hour' => self::int($s['hour'] ?? 0, 0, 23, 'hour', $e),
                'weekday' => self::int($s['weekday'] ?? 1, 1, 7, 'weekday', $e),
                'monthday' => self::int($s['monthday'] ?? 1, 1, 28, 'day of the month', $e),
            ];
        }

        $ds = $j['destinations'] ?? null;
        if (!is_array($ds) || !$ds) {
            $e[] = 'no destination';
        } else {
            $out['destinations'] = [];
            foreach ($ds as $id) {
                if (!self::isId($id) || !isset($destinations[$id])) {
                    $e[] = 'unknown destination';
                    break;
                }
                $out['destinations'][] = $id;
            }
            $out['destinations'] = array_values(array_unique($out['destinations']));
        }

        $k = $j['keep'] ?? null;
        $out['keep'] = [];
        $any = false;
        foreach (self::KEEP as $key) {
            $v = self::int(is_array($k) ? ($k[$key] ?? 0) : null, 0, self::MAX_KEEP, "keep $key", $e);
            $out['keep'][$key] = $v;
            $any = $any || $v > 0;
        }
        if (!$any) {
            $e[] = 'keep: at least one value must be above 0 (otherwise nothing would ever be removed, or everything)';
        }

        $out['notify_success'] = self::bool($j['notify_success'] ?? false, 'notify on success', $e);
        $out['created'] = self::int($j['created'] ?? 0, 0, PHP_INT_MAX, 'created', $e);

        return [$e ? null : $out, $e];
    }

    // ------------------------------------------------------------------ fields

    public static function isAddress($a): bool
    {
        return is_string($a) && strlen($a) <= 254
            && preg_match('/^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}$/', $a) === 1
            && filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function name($v, array &$e): string
    {
        if (!is_string($v) || trim($v) === '' || mb_strlen($v) > 60 || preg_match('/[\x00-\x1f\x7f]/', $v)) {
            $e[] = 'name: 1 to 60 characters, no control characters';
            return '';
        }
        return trim($v);
    }

    private static function bool($v, string $what, array &$e): bool
    {
        if (!is_bool($v)) {
            $e[] = "$what: not true/false";
            return false;
        }
        return $v;
    }

    private static function int($v, int $min, int $max, string $what, array &$e): int
    {
        if (!is_int($v) || $v < $min || $v > $max) {
            $e[] = "$what: a whole number from $min to $max";
            return $min;
        }
        return $v;
    }

    private static function match($v, string $re, string $what, array &$e): string
    {
        if (!is_string($v) || preg_match($re, $v) !== 1) {
            $e[] = "$what: not valid";
            return '';
        }
        return $v;
    }

    // a secret goes into the environment of rclone/restic: any printable text,
    // no control characters (a newline would cut it)
    private static function secret($v, string $what, array &$e): string
    {
        if (!is_string($v) || $v === '' || strlen($v) > 256 || preg_match('/[\x00-\x1f\x7f]/', $v)) {
            $e[] = "$what: 1 to 256 characters, no control characters";
            return '';
        }
        return $v;
    }

    private static function user($v, array &$e): string
    {
        return self::match($v, '/^[A-Za-z0-9._@-]{1,64}$/', 'user', $e);
    }

    public static function host($v, array &$e): string
    {
        if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false) {
            return $v;
        }
        if (!is_string($v) || strlen($v) > 253
            || preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $v) !== 1) {
            $e[] = 'server: a host name or an IP address';
            return '';
        }
        return $v;
    }

    // a folder on the destination: letters, digits . _ - and /, no "." or ".."
    // part, never starting with "-" (it would look like an option)
    public static function folder($v, array &$e): string
    {
        if (!is_string($v) || strlen($v) > 200 || preg_match('/^[A-Za-z0-9._\/-]*$/', $v) !== 1 || ($v !== '' && $v[0] === '-')) {
            $e[] = 'folder: letters, digits, . _ - and /';
            return '';
        }
        foreach (explode('/', $v) as $part) {
            if ($part === '.' || $part === '..') {
                $e[] = 'folder: no "." or ".." parts';
                return '';
            }
        }
        return $v;
    }

    public static function localPath($v, array &$e): string
    {
        $roots = implode('|', self::LOCAL_ROOTS);
        if (!is_string($v) || strlen($v) > 200 || preg_match('#^/(' . $roots . ')(/[A-Za-z0-9._-]+)*$#', $v) !== 1) {
            $e[] = 'folder: an absolute path under /' . implode(', /', self::LOCAL_ROOTS);
            return '';
        }
        foreach (explode('/', $v) as $part) {
            if ($part === '.' || $part === '..') {
                $e[] = 'folder: no "." or ".." parts';
                return '';
            }
        }
        return $v;
    }

    public static function endpoint($v, array &$e): string
    {
        if (!is_string($v) || strlen($v) > 200
            || preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$#', $v) !== 1) {
            $e[] = 'endpoint: https://host[:port]';
            return '';
        }
        return rtrim($v, '/');
    }

    /** The confirmed certificate of a REST server: one PEM certificate, as OpenSSL writes it back; '' = not confirmed yet. */
    public static function certificate($v, array &$e): string
    {
        if ($v === '') {
            return '';
        }
        $c = is_string($v) && strlen($v) <= 16384 && strpos($v, '-----BEGIN CERTIFICATE-----') === 0 ? @openssl_x509_read($v) : false;
        if ($c === false || !@openssl_x509_export($c, $pem)) {
            $e[] = 'certificate: not valid';
            return '';
        }
        return $pem;
    }

    private const KEY_TYPES = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    /** The trusted SSH host keys of an SFTP destination: "type base64" pairs. */
    public static function hostKeys($v, array &$e): array
    {
        if (!is_array($v) || count($v) > 10) {
            $e[] = 'host keys: not valid';
            return [];
        }
        $out = [];
        foreach ($v as $k) {
            if (!is_string($k) || preg_match('/^(' . implode('|', array_map('preg_quote', self::KEY_TYPES)) . ') [A-Za-z0-9+\/]+={0,3}$/', $k) !== 1
                || strlen($k) > 1200) {
                $e[] = 'host keys: not valid';
                return [];
            }
            $out[] = $k;
        }
        return array_values(array_unique($out));
    }
}
