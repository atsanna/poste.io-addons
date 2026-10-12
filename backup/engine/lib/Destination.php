<?php
/*
 * poste.io-addons, backup add-on: a destination as restic and rclone see it.
 *
 * local  a folder that is a volume mounted by the host (NFS, a second disk,
 *        ...): restic writes there directly
 * rest   restic's own REST server (rest-server, started with --append-only on
 *        the destination's side so that this server can only add): over
 *        HTTPS, with the certificate confirmed in the admin; the address with
 *        the user and password goes in the environment (RESTIC_REPOSITORY),
 *        never on a command line
 * sftp, smb, s3  through rclone; restic starts "rclone serve restic" itself
 *        and passes it its environment, where the remote is defined
 *        (RCLONE_CONFIG_<NAME>_...). No rclone configuration file holds a
 *        secret; passwords are "obscured" as rclone requires, through stdin.
 *
 * Used by the engine and by the admin's connection test (both as mail): a
 * caller passes the folder for the temporary files it may write.
 */

namespace PosteBackup;

final class Destination
{
    /** rclone remote name of a destination: only letters and digits. */
    public static function remote(string $id): string
    {
        return 'D' . strtoupper($id);
    }

    /** Private key of an SFTP destination that logs in with a key. */
    public static function sshKey(string $id): string
    {
        return Store::SSH . '/' . $id;
    }

    /**
     * Base environment of rclone and restic for a destination.
     * $tmp: a private folder (0700) for the known_hosts file of SFTP.
     * Returns [env, error].
     */
    public static function env(string $id, array $d, string $tmp, string $cacheHome): array
    {
        $env = [
            'HOME' => $cacheHome,
            'XDG_CACHE_HOME' => $cacheHome,
            'XDG_CONFIG_HOME' => $cacheHome,
            'RCLONE_CONFIG' => Store::RCLONE_CONF,
        ];
        if ($d['type'] === 'local') {
            return [$env, null];
        }
        if ($d['type'] === 'rest') {
            if ($d['cert'] === '') {
                return [null, 'the server\'s certificate has not been confirmed yet (Test the destination in the admin)'];
            }
            $ca = $tmp . '/cacert-' . $id . '.pem';
            if (@file_put_contents($ca, $d['cert']) === false) {
                return [null, 'cannot write a temporary file'];
            }
            @chmod($ca, 0600);
            $env['RESTIC_REPOSITORY'] = 'rest:' . self::restUrl($d, true);
            $env[self::CACERT] = $ca;
            return [$env, null];
        }
        $r = 'RCLONE_CONFIG_' . self::remote($id) . '_';
        switch ($d['type']) {
            case 'sftp':
                $env[$r . 'TYPE'] = 'sftp';
                $env[$r . 'HOST'] = $d['host'];
                $env[$r . 'PORT'] = (string) $d['port'];
                $env[$r . 'USER'] = $d['user'];
                if ($d['auth'] === 'password') {
                    [$ob, $err] = self::obscure($d['password']);
                    if ($err) {
                        return [null, $err];
                    }
                    $env[$r . 'PASS'] = $ob;
                } else {
                    $key = self::sshKey($id);
                    if (!is_file($key) || is_link($key)) {
                        return [null, 'the SSH key of this destination is missing (create it again in the admin)'];
                    }
                    $env[$r . 'KEY_FILE'] = $key;
                }
                // only the host keys confirmed in the admin are accepted
                if (!$d['hostkeys']) {
                    return [null, 'the server\'s SSH key has not been confirmed yet (Test the destination in the admin)'];
                }
                $kh = $tmp . '/known_hosts-' . $id;
                $host = $d['port'] === 22 ? $d['host'] : '[' . $d['host'] . ']:' . $d['port'];
                $lines = '';
                foreach ($d['hostkeys'] as $k) {
                    $lines .= $host . ' ' . $k . "\n";
                }
                if (@file_put_contents($kh, $lines) === false) {
                    return [null, 'cannot write a temporary file'];
                }
                @chmod($kh, 0600);
                $env[$r . 'KNOWN_HOSTS_FILE'] = $kh;
                break;
            case 'smb':
                [$ob, $err] = self::obscure($d['password']);
                if ($err) {
                    return [null, $err];
                }
                $env[$r . 'TYPE'] = 'smb';
                $env[$r . 'HOST'] = $d['host'];
                $env[$r . 'USER'] = $d['user'];
                $env[$r . 'PASS'] = $ob;
                if ($d['domain'] !== '') {
                    $env[$r . 'DOMAIN'] = $d['domain'];
                }
                break;
            case 's3':
                $env[$r . 'TYPE'] = 's3';
                $env[$r . 'PROVIDER'] = 'Other';
                $env[$r . 'ENDPOINT'] = $d['endpoint'];
                $env[$r . 'ACCESS_KEY_ID'] = $d['access_key'];
                $env[$r . 'SECRET_ACCESS_KEY'] = $d['secret_key'];
                if ($d['region'] !== '') {
                    $env[$r . 'REGION'] = $d['region'];
                }
                break;
        }
        return [$env, null];
    }

    /**
     * Free and total space of an SMB share: ['free' => bytes, 'total' => bytes]
     * or null. Not from rclone: rclone 1.60 (go-smb2 1.1.0) multiplies the
     * allocation units by the bytes per sector only, without the sectors per
     * unit, so its numbers are divided by a factor that depends on the server
     * (2 on Samba). smbclient computes them whole. The password goes in a file
     * (0600, in a private folder), never on a command line.
     */
    public static function smbSpace(array $d, string $tmp): ?array
    {
        $auth = $tmp . '/smb-auth';
        $f = @fopen($auth, 'x');
        if (!$f) {
            return null;
        }
        @chmod($auth, 0600);
        fwrite($f, "username = {$d['user']}\npassword = {$d['password']}\n" . ($d['domain'] !== '' ? "domain = {$d['domain']}\n" : ''));
        fclose($f);
        try {
            $r = Proc::run(['smbclient', '//' . $d['host'] . '/' . $d['share'], '-s', '/dev/null', '-A', $auth, '-c', 'du'],
                ['HOME' => $tmp], null, 60);
        } finally {
            @unlink($auth);
        }
        if ($r['exit'] === 0 && preg_match('/(\d+) blocks of size (\d+)\. (\d+) blocks available/', $r['out'], $m) === 1) {
            return ['total' => (int) $m[1] * (int) $m[2], 'free' => (int) $m[3] * (int) $m[2]];
        }
        return null;
    }

    /** The rclone path of the repository (remote:path), for remote destinations. */
    public static function rclonePath(string $id, array $d): string
    {
        switch ($d['type']) {
            case 'sftp':
                return self::remote($id) . ':' . $d['folder'];
            case 'smb':
                return self::remote($id) . ':' . $d['share'] . ($d['folder'] !== '' ? '/' . $d['folder'] : '');
            case 's3':
                return self::remote($id) . ':' . $d['bucket'] . ($d['folder'] !== '' ? '/' . $d['folder'] : '');
        }
        throw new \InvalidArgumentException('not a remote destination');
    }

    /** The restic repository of a destination ('' for REST: it is in the environment, with its password). */
    public static function repository(string $id, array $d): string
    {
        if ($d['type'] === 'rest') {
            return '';
        }
        return $d['type'] === 'local' ? $d['path'] : 'rclone:' . self::rclonePath($id, $d);
    }

    /** The environment key that holds the certificate file of a REST server (given to restic as --cacert). */
    public const CACERT = 'POSTE_BACKUP_CACERT';

    /** restic and its options for a repository: -r unless it is in the environment, --cacert for a REST server. */
    public static function resticCommand(string $repo, array $env): array
    {
        $cmd = ['restic'];
        if ($repo !== '') {
            array_push($cmd, '-r', $repo);
        }
        if (isset($env[self::CACERT])) {
            array_push($cmd, '--cacert', $env[self::CACERT]);
        }
        return $cmd;
    }

    /** https://[user:password@]host:port/repository of a REST server (an IPv6 address in brackets). */
    public static function restUrl(array $d, bool $credentials): string
    {
        $host = filter_var($d['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $d['host'] . ']' : $d['host'];
        $auth = $credentials ? rawurlencode($d['user']) . ':' . rawurlencode($d['password']) . '@' : '';
        return 'https://' . $auth . $host . ':' . $d['port'] . '/' . $d['folder'];
    }

    /**
     * Is there a repository on a REST server? A request for its "config" with
     * the confirmed certificate and the user: [true|false, null] or [null, error].
     */
    public static function restCheck(array $d, string $tmp): array
    {
        if ($d['cert'] === '') {
            return [null, 'the server\'s certificate has not been confirmed yet (Test the destination in the admin)'];
        }
        // the confirmed certificate is the only one trusted: CAINFO alone would still let curl
        // accept a certificate of a public authority through its default CApath (measured), and
        // the user and the password would go to whoever holds one for that name
        $ca = $tmp . '/cacert-check.pem';
        $noCa = $tmp . '/no-ca';
        if (@file_put_contents($ca, $d['cert']) === false || !@mkdir($noCa, 0700)) {   // $tmp is private (0700, made now)
            @unlink($ca);
            return [null, 'cannot write a temporary file'];
        }
        $c = curl_init(self::restUrl($d, false) . '/config');
        curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CAINFO => $ca, CURLOPT_CAPATH => $noCa,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $d['user'] . ':' . $d['password'], CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $err = curl_error($c);
        curl_close($c);
        @unlink($ca);
        @rmdir($noCa);
        if ($code === 200) {
            return [true, null];
        }
        if ($code === 404) {
            return [false, null];
        }
        if ($code === 401 || $code === 403) {
            return [null, 'the server refused the user or the password'];
        }
        return [null, 'not reachable: ' . ($err !== '' ? $err : "HTTP $code")];
    }

    /**
     * The certificate a REST server presents now, read without trusting it, to
     * be confirmed in the admin: [['pem', 'fingerprint', 'names', 'until'], null]
     * or [null, error]. It must name the host used in the address (restic checks it).
     */
    public static function serverCertificate(string $host, int $port): array
    {
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false,
                                                'SNI_enabled' => true, 'peer_name' => $host]]);
        $addr = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[$host]" : $host;
        $s = @stream_socket_client("ssl://$addr:$port", $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            return [null, 'not reachable: ' . ($es !== '' ? $es : 'no TLS answer')];
        }
        $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
        fclose($s);
        if (!$cert || !openssl_x509_export($cert, $pem)) {
            return [null, 'the server sent no certificate'];
        }
        $info = openssl_x509_parse($cert) ?: [];
        $fp = strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($cert, 'sha256'), 2)));
        [$ok, $names] = self::certCovers($host, (string) ($info['extensions']['subjectAltName'] ?? ''));
        if (!$ok) {
            return [null, 'the certificate is for ' . ($names ? implode(', ', $names) : 'no name') . ", not for $host: make it with that name (see the documentation)"];
        }
        $until = (int) ($info['validTo_time_t'] ?? 0);
        if ($until < time()) {
            return [null, 'the certificate expired on ' . gmdate('Y-m-d', $until) . ': renew it on the server'];
        }
        return [['pem' => $pem, 'fingerprint' => 'SHA256 ' . $fp, 'names' => $names, 'until' => $until], null];
    }

    /**
     * Does a certificate's subjectAltName (as openssl_x509_parse writes it) name the host?
     * [covered, the names as shown]. An address is compared as an address: OpenSSL writes
     * an IPv6 one in full (2001:DB8:0:0:0:0:0:1), the host may be written short (2001:db8::1).
     * A wildcard covers one level (*.example.lan for nas.example.lan), and only names.
     */
    public static function certCovers(string $host, string $san): array
    {
        $hostIp = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : null;
        $h = strtolower($host);
        $ok = false;
        $names = [];
        foreach (explode(',', $san) as $n) {
            $n = trim($n);
            if (preg_match('/^IP Address:(.+)$/', $n, $m) === 1) {
                $ip = @inet_pton(trim($m[1]));
                $names[] = $ip !== false ? (string) inet_ntop($ip) : strtolower(trim($m[1]));
                $ok = $ok || ($hostIp !== null && $ip !== false && $ip === $hostIp);
            } elseif (preg_match('/^DNS:(.+)$/', $n, $m) === 1) {
                $d = strtolower(trim($m[1]));
                $names[] = $d;
                $ok = $ok || ($hostIp === null && ($d === $h || (strpos($d, '*.') === 0 && strpos($h, '.') !== false
                    && substr_count($h, '.') === substr_count($d, '.') && substr($h, strpos($h, '.')) === substr($d, 1))));
            }
        }
        return [$ok, $names];
    }

    /** Are two certificates (PEM) the same one? */
    public static function sameCertificate(string $a, string $b): bool
    {
        $fa = @openssl_x509_fingerprint($a, 'sha256');
        return $fa !== false && $fa === @openssl_x509_fingerprint($b, 'sha256');
    }

    /** rclone obscure, with the password on stdin (never on a command line). */
    public static function obscure(string $secret): array
    {
        $r = Proc::run(['rclone', 'obscure', '-'], ['RCLONE_CONFIG' => Store::RCLONE_CONF, 'HOME' => '/tmp'], $secret . "\n", 20);
        $ob = trim($r['out']);
        if ($r['exit'] !== 0 || $ob === '') {
            return [null, 'rclone obscure failed'];
        }
        return [$ob, null];
    }

    /**
     * A local destination must be a mount point (a volume of its own: otherwise
     * the backup would silently land inside the container) and must not be on
     * the same device as /data. Returns an error, or null.
     */
    public static function checkLocal(string $path): ?string
    {
        if (!is_dir($path) || is_link($path)) {
            return "$path does not exist in the container: mount a volume there";
        }
        $mounted = false;
        foreach (@file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $f = explode(' ', $line);
            // field 5: mount point, with spaces etc. as \040 (our paths have none)
            if (isset($f[4]) && $f[4] === $path) {
                $mounted = true;
                break;
            }
        }
        if (!$mounted) {
            return "$path is not a mounted volume: the backup would stay inside the container";
        }
        $a = @stat($path);
        $b = @stat('/data');
        if ($a && $b && $a['dev'] === $b['dev']) {
            return "$path is on the same disk as /data: a backup there does not survive the loss of that disk";
        }
        // the engine runs as "mail" (uid 8): it must be able to write there
        if (!is_writable($path)) {
            return "$path is not writable by the user mail (uid 8, gid 8): give the volume to that user";
        }
        return null;
    }
}
