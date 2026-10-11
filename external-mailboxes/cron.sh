#!/bin/bash
# Runs as the "mail" user (mail's crontab, not root's): the .cfg files are
# written by the admin (php-fpm, user mail) and getmail must never run as root.
# Folders are 0750 and files 0600, owned by mail:mail.

# What getmail prints with --quiet (errors and warnings only) goes to syslog as
# before and also to a file of its own, shown by the "Getmail Errors" log type
# of the admin: the admin cannot read /data/log/mail.log (root:adm 0640), and
# some errors (configuration errors, crashes) never reach getmail.log
ERROR_LOG=/data/log/getmail/errors.log

mkdir -p /data/log/getmail
[ -e "$ERROR_LOG" ] || install -m 0640 /dev/null "$ERROR_LOG"

# $1: interval/file of the configuration, written before each line
logOutput() {
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        logger -p mail.err -t getmail -- "$line"
        printf '%s %s: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" "$line" >> "$ERROR_LOG"
    done
}

startCron() {
    for directory in *; do
        if [ -d "$directory" ] && [ "$directory" == "$1" ]; then
            for file in "$directory"/*.cfg; do
                if [ -e "$file" ]; then
                    /usr/bin/flock -n -E 0 "/data/var/getmail/${directory}/$(basename "$file")" \
                        /usr/bin/getmail --getmaildir "/data/var/getmail/${directory}/" \
                        --rcfile "/data/var/getmail/${directory}/$(basename "$file")" --quiet 2>&1 \
                        | logOutput "${directory}/$(basename "$file")"
                fi
            done
        fi
    done
}

startCron "$1"
