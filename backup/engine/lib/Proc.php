<?php
/*
 * poste.io-addons, backup add-on: runs a command, never through a shell.
 *
 * The command is an array (program + arguments), so no value can be read as
 * shell syntax. The environment is built from scratch (only what is passed),
 * so secrets go to the program that needs them and nowhere else. Output is
 * read while the program runs: a long backup prints a lot, and a full pipe
 * would block it. A time limit, when given, stops the program.
 */

namespace PosteBackup;

final class Proc
{
    public const PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /**
     * @param string[]      $cmd     program and arguments
     * @param array         $env     the whole environment of the program
     * @param string|null   $stdin   written to the program's input, then closed
     * @param int           $timeout seconds, 0 = none
     * @param callable|null $onLine  called with each line of standard output
     * @param resource|null $log     standard error is copied here as it comes
     * @param callable|null $onErrLine called with each line of standard error
     * @return array{exit:int, out:string, err:string, timeout:bool}
     *         out/err keep only the last 64 KB
     */
    public static function run(array $cmd, array $env, ?string $stdin = null, int $timeout = 0,
                               ?callable $onLine = null, $log = null, ?callable $onErrLine = null): array
    {
        $env += ['PATH' => self::PATH, 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'];
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // setsid: the command leads its own process group, so a time limit stops
        // its children too (restic starts rclone)
        $p = @proc_open(array_merge(['setsid'], $cmd), $spec, $pipes, null, $env);
        if (!is_resource($p)) {
            return ['exit' => 127, 'out' => '', 'err' => 'cannot start ' . $cmd[0], 'timeout' => false];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = $partial = $errPartial = '';
        $start = time();
        $timedOut = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $pid = proc_get_status($p)['pid'];
        $selectErrors = 0;
        while ($open) {
            $read = array_values($open);
            $w = $x = null;
            $n = @stream_select($read, $w, $x, 1);
            if ($n === false) {
                // interrupted by a signal: try again (but not forever)
                if (++$selectErrors > 100) {
                    break;
                }
                continue;
            }
            foreach ($read as $s) {
                $chunk = fread($s, 65536);
                $fd = $s === $pipes[1] ? 1 : 2;
                if ($chunk === '' || $chunk === false) {
                    if (feof($s)) {
                        unset($open[$fd]);
                    }
                    continue;
                }
                if ($fd === 1) {
                    $out = substr($out . $chunk, -65536);
                    if ($onLine !== null) {
                        $partial .= $chunk;
                        while (($pos = strpos($partial, "\n")) !== false) {
                            $onLine(substr($partial, 0, $pos));
                            $partial = substr($partial, $pos + 1);
                        }
                        // a line longer than 1 MB is not one we care about
                        if (strlen($partial) > 1048576) {
                            $partial = '';
                        }
                    }
                } else {
                    $err = substr($err . $chunk, -65536);
                    if ($log) {
                        fwrite($log, $chunk);
                    }
                    if ($onErrLine !== null) {
                        $errPartial .= $chunk;
                        while (($pos = strpos($errPartial, "\n")) !== false) {
                            $onErrLine(substr($errPartial, 0, $pos));
                            $errPartial = substr($errPartial, $pos + 1);
                        }
                        if (strlen($errPartial) > 1048576) {
                            $errPartial = '';
                        }
                    }
                }
            }
            if ($timeout > 0 && time() - $start > $timeout) {
                $timedOut = true;
                @posix_kill(-$pid, 15);
                sleep(2);
                @posix_kill(-$pid, 9);
                break;
            }
        }
        if ($onLine !== null && $partial !== '') {
            $onLine($partial);
        }
        if ($onErrLine !== null && $errPartial !== '') {
            $onErrLine($errPartial);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        if ($timedOut) {
            $exit = 124;
            $err .= "\n(stopped after {$timeout} seconds)";
        }
        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'timeout' => $timedOut];
    }
}
