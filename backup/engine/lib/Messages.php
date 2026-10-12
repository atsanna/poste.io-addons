<?php
/*
 * poste.io-addons, backup add-on: the messages of the engine and of the
 * validation, recognised by the admin to be shown in the user's language.
 *
 * The engine writes its messages in English (they go to the logs, to the
 * command line and to the state file, where they stay readable as they are).
 * The admin shows them through the translations: each message below is a
 * pattern with the parts that change (a path, a size, the last line of a
 * tool's output) as parameters. A message not listed here is shown as it is,
 * in English: nothing is ever lost, at worst it is not translated.
 * tests/backup_unit.php checks that every message written in the code is
 * recognised.
 */

namespace PosteBackup;

final class Messages
{
    /** Field names in messages ("port: not valid") => key under poste_backup.fieldname. */
    public const FIELDS = [
        'port' => 'port', 'password' => 'password', 'share' => 'share', 'domain' => 'domain', 'region' => 'region',
        'bucket' => 'bucket', 'access key' => 'access_key', 'secret key' => 'secret_key', 'user' => 'user',
        'enabled' => 'enabled', 'configuration' => 'configuration', 'minute' => 'minute', 'hour' => 'hour',
        'weekday' => 'weekday', 'day of the month' => 'monthday', 'keep hourly' => 'keep_hourly',
        'keep daily' => 'keep_daily', 'keep weekly' => 'keep_weekly', 'keep monthly' => 'keep_monthly',
        'notify on success' => 'notify_success', 'created' => 'created', 'log days' => 'log_days',
        'history days' => 'history_days', 'parts of the data' => 'verify_parts', 'yellow from' => 'space_warn',
        'red from' => 'space_full',
    ];

    /**
     * pattern => [key under poste_backup.msg, parameter names in order of the groups].
     * A parameter named "list" holds messages joined by "; " (translated one by
     * one); "field" is a field name (FIELDS). Order matters: the first match wins.
     */
    public static function patterns(): array
    {
        $f = implode('|', array_map(function ($n) {
            return preg_quote($n, '/');
        }, array_keys(self::FIELDS)));
        return [
            // validation of the settings (Config)
            '/^not an object$/' => ['not_object', []],
            '/^the settings file is not a JSON object$/' => ['settings_not_object', []],
            '/^too many destinations$/' => ['too_many_destinations', []],
            '/^destination with an invalid id left out$/' => ['destination_bad_id', []],
            '/^destination ([a-f0-9]{12}): (.+)$/s' => ['destination_errors', ['id', 'list']],
            '/^too many jobs$/' => ['too_many_jobs', []],
            '/^job with an invalid id left out$/' => ['job_bad_id', []],
            '/^job ([a-f0-9]{12}): (.+)$/s' => ['job_errors', ['id', 'list']],
            '/^unknown type$/' => ['unknown_type', []],
            '/^login: password or key$/' => ['login', []],
            '/^folder: required for SFTP$/' => ['folder_required', []],
            '/^mail: unknown choice$/' => ['mail_choice', []],
            '/^no mailbox selected$/' => ['no_mailbox', []],
            '/^too many mailboxes$/' => ['too_many_mailboxes', []],
            '/^invalid mailbox address$/' => ['invalid_mailbox', []],
            '/^nothing to back up$/' => ['nothing', []],
            '/^schedule: unknown frequency$/' => ['frequency', []],
            '/^no destination$/' => ['no_destination', []],
            '/^unknown destination$/' => ['unknown_destination', []],
            '/^keep: at least one value must be above 0 /' => ['keep_zero', []],
            '/^name: 1 to 60 characters, no control characters$/' => ['name', []],
            '/^server: a host name or an IP address$/' => ['host', []],
            '/^folder: letters, digits, \. _ - and \/$/' => ['folder_chars', []],
            '/^folder: no "\." or "\.\." parts$/' => ['folder_dots', []],
            '/^folder: an absolute path under (.+)$/' => ['local_roots', ['roots']],
            '/^endpoint: https:\/\/host\[:port\]$/' => ['endpoint', []],
            '/^host keys: not valid$/' => ['hostkeys', []],
            '/^time zone: not valid$/' => ['timezone', []],
            '/^(' . $f . '): not true\/false$/' => ['field_bool', ['field']],
            '/^(' . $f . '): a whole number from (-?\d+) to (\d+)$/' => ['field_int', ['field', 'min', 'max']],
            '/^(' . $f . '): not valid$/' => ['field_invalid', ['field']],
            '/^(' . $f . '): 1 to 256 characters, no control characters$/' => ['field_secret', ['field']],

            // destinations (Destination, Panel)
            '/^the SSH key of this destination is missing /' => ['ssh_key_missing', []],
            "/^the server's SSH key has not been confirmed yet /" => ['hostkey_not_confirmed', []],
            '/^cannot write a temporary file$/' => ['tmp_file', []],
            '/^cannot create a temporary folder$/' => ['tmp_folder', []],
            '/^rclone obscure failed$/' => ['obscure', []],
            '/^(.+) does not exist in the container: mount a volume there$/' => ['local_missing', ['path']],
            '/^(.+) is not a mounted volume: the backup would stay inside the container$/' => ['local_not_mounted', ['path']],
            '/^(.+) is on the same disk as \/data: a backup there does not survive the loss of that disk$/' => ['local_same_disk', ['path']],
            '/^(.+) is not writable by the user mail \(uid 8, gid 8\): give the volume to that user$/' => ['local_not_writable', ['path']],
            '/^cannot lock the settings$/' => ['lock_settings', []],
            '/^invalid server or port$/' => ['server_port', []],
            '/^the server did not answer with an SSH key \((.*)\)$/s' => ['no_ssh_key', ['detail']],
            '/^invalid id$/' => ['invalid_id', []],
            '/^ssh-keygen failed: (.*)$/s' => ['keygen', ['detail']],
            '/^the destination changed meanwhile$/' => ['changed_meanwhile', []],

            // the connection test (Panel::probe)
            '/^mounted volume, not on the disk of \/data$/' => ['test_mounted', []],
            '/^free space: not reported by S3$/' => ['test_free_s3', []],
            '/^free space: not reported by the server$/' => ['test_free_unknown', []],
            '/^free space: (.+) of (.+)$/' => ['test_free_total', ['free', 'total']],
            '/^free space: (.+)$/' => ['test_free', ['free']],
            '/^it already holds a backup repository$/' => ['test_repository', []],
            '/^no repository yet: it is created at the first run$/' => ['test_no_repository', []],
            '/^reachable; it already holds a backup repository$/' => ['test_reachable_repository', []],
            '/^reachable, but the folder is not empty and is not a backup repository: choose an empty folder$/' => ['test_not_empty', []],
            '/^reachable; empty folder, the repository is created at the first run$/' => ['test_reachable_empty', []],
            '/^reachable; the folder does not exist yet, it is created at the first run$/' => ['test_reachable_new', []],
            '/^not reachable: (.*)$/s' => ['not_reachable', ['detail']],
            '/^cannot write: (.*)$/s' => ['test_cannot_write', ['detail']],
            '/^write test: ok$/' => ['test_write_ok', []],
            '/^write test: written, but the test file could not be removed$/' => ['test_write_left', []],

            // a run (Engine)
            '/^mailbox (.+) not found$/' => ['mailbox_not_found', ['address']],
            '/^none of the selected mailboxes exists$/' => ['no_selected_mailbox', []],
            '/^cannot create the staging folder$/' => ['staging', []],
            '/^copy of (.+) failed or is damaged$/' => ['copy_failed', ['path']],
            '/^the encryption key is missing \((.+)\)$/' => ['key_missing', ['path']],
            '/^cannot create the repository: (.*)$/s' => ['init_failed', ['detail']],
            '/^backup failed: (.*)$/s' => ['backup_failed', ['detail']],
            '/^(\d+) file\(s\) could not be read \(see the log\)$/' => ['unreadable', ['count']],
            '/^some files could not be read \(see the log\)$/' => ['unreadable_some', []],
            '/^(\d+) file\(s\) moved or deleted while the backup ran: the next backup has them$/' => ['vanished', ['count']],
            '/^backup done, but the old snapshots were not removed: (.*)$/s' => ['forget_failed', ['detail']],
            // before the next one, which would read "the folder" as a path
            '/^the folder is not empty and is not a backup repository: nothing written there$/' => ['folder_not_empty', []],
            '/^(.+) is not empty and is not a backup repository: nothing written there$/' => ['local_not_empty', ['path']],

            // snapshots and restore (Panel, Engine)
            '/^the request is not valid$/' => ['request_invalid', []],
            '/^(.+) is not in this snapshot$/' => ['not_in_snapshot', ['address']],
            '/^cannot read the snapshot: (.*)$/s' => ['snapshot_read', ['detail']],
            '/^cannot read the snapshots: (.*)$/s' => ['snapshots_read', ['detail']],
            '/^there are no backups on this destination yet$/' => ['no_backups_yet', []],
            '/^cannot prepare the restore folder$/' => ['restore_folder', []],
            '/^restore failed: (.*)$/s' => ['restore_failed', ['detail']],
            '/^(.+) has no mail in this snapshot$/' => ['no_mail_in_snapshot', ['address']],
            '/^import failed: (.*)$/s' => ['import_failed', ['detail']],
            '/^(\d+) message\(s\) restored into the folder "(.+)" of (.+)$/' => ['restored', ['count', 'folder', 'mailbox']],
            '/^there were no backups of this job on this destination$/' => ['forget_job_none', []],
            '/^(\d+) backup\(s\) of the job removed$/' => ['forget_job_done', ['count']],
            '/^removing the backups failed: (.*)$/s' => ['forget_job_failed', ['detail']],
            '/^not enough free space on \/data: the restore needs about (.+), (.+) are free$/' => ['restore_space', ['need', 'free']],
            // the choices of a restore (Engine::restoreOptions, the admin's form)
            '/^choose at least one folder$/' => ['choose_folder', []],
            '/^too many folders$/' => ['too_many_folders', []],
            '/^folder: not valid$/' => ['folder_invalid', []],
            '/^folder name: 1 to 100 characters, without dots, slashes, \* or %$/' => ['folder_name', []],
            '/^folder name: INBOX is the inbox itself$/' => ['folder_name_inbox', []],
            '/^parent folder: not valid$/' => ['parent_invalid', []],
            '/^date: YYYY-MM-DD$/' => ['date_format', []],
            '/^dates: the first one is after the second$/' => ['date_order', []],
            '/^sender: up to 100 characters$/' => ['sender_long', []],
            '/^subject: up to 100 characters$/' => ['subject_long', []],
            '/^a chosen folder is not in this snapshot$/' => ['folder_not_in_snapshot', []],
            '/^the folder (.+) is not in (.+)$/' => ['parent_missing', ['folder', 'mailbox']],
            '/^no message matched the choice: nothing restored$/' => ['nothing_matched', []],
            '/^choose the mailbox to restore into$/' => ['choose_target', []],
            // single messages chosen from a list
            '/^choose at least one message$/' => ['choose_message', []],
            '/^too many messages: up to (\d+) at a time$/' => ['too_many_messages', ['max']],
            '/^message: not valid$/' => ['message_invalid', []],
            '/^a chosen message is not in this snapshot$/' => ['message_not_in_snapshot', []],
            '/^cannot read the list of the messages: (.*)$/s' => ['list_read', ['detail']],
            // verification and space warnings
            '/^verification failed: (.*)$/s' => ['verify_failed', ['detail']],
            '/^the verification found errors: (.*)$/s' => ['verify_errors', ['detail']],
            '/^there are no backups on this destination yet: nothing to verify$/' => ['verify_nothing', []],
            '/^the structure and all the data: no errors$/' => ['verify_ok_all', []],
            '/^the structure and part (\d+) of (\d+) of the data: no errors$/' => ['verify_ok_part', ['part', 'parts']],
            '/^verification: unknown frequency$/' => ['verify_every', []],
            '/^space warnings: yellow must start below red$/' => ['space_order', []],
        ];
    }

    /**
     * The translation of one message: [key, parameters] (parameters already in
     * the {name} form), or null when it is not a known message. "list" and
     * "field" parameters are returned as they are: the caller translates them.
     */
    public static function match(string $message): ?array
    {
        foreach (self::patterns() as $re => [$key, $names]) {
            if (preg_match($re, $message, $m) === 1) {
                $params = [];
                foreach ($names as $i => $n) {
                    $params[$n] = $m[$i + 1] ?? '';
                }
                return [$key, $params];
            }
        }
        return null;
    }
}
