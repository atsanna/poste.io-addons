<?php
/*
 * poste.io-addons, backup add-on: a destination as restic and rclone see it.
 *
 * local  a folder that is a volume mounted by the host (NFS, a second disk,
 *        ...): restic writes there directly
 * sftp, smb, s3  through rclone; restic starts "rclone serve restic" itself
 *        and passes it its environment, where the remote is defined
 *        (RCLONE_CONFIG_<NAME>_...). No rclone configuration file holds a
 *        secret; passwords are "obscured" as rclone requires, through stdin.
 *
 * Used by the engine (root) and by the admin's connection test (mail): a
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

    /** The restic repository of a destination. */
    public static function repository(string $id, array $d): string
    {
        return $d['type'] === 'local' ? $d['path'] : 'rclone:' . self::rclonePath($id, $d);
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
