#!/bin/bash
# Runs as the "mail" user (mail's crontab, not root's): the .cfg files are
# written by the admin (php-fpm, user mail) and getmail must never run as root.
# Folders are 0750 and files 0600, owned by mail:mail.

mkdir -p /data/log/getmail

startCron() {
    for directory in *; do
        if [ -d "$directory" ] && [ "$directory" == "$1" ]; then
            for file in "$directory"/*.cfg; do
                if [ -e "$file" ]; then
                    /usr/bin/flock -n -E 0 "/data/var/getmail/${directory}/$(basename "$file")" -c "/usr/bin/getmail --getmaildir /data/var/getmail/${directory}/ --rcfile /data/var/getmail/${directory}/$(basename "$file") --quiet 2>&1 | logger -p mail.err -t getmail"
                fi
            done
        fi
    done
}

startCron "$1"
