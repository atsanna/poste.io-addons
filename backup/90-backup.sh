#!/bin/sh
# Backup add-on: at every container start, as root, after Poste.io's own start
# scripts and before any service. /data is a volume, so folders made in the
# image would be hidden on an existing installation: they are made here.
#
# The backups run as the user "mail" (like the admin). Root does only this:
# the lock, a temporary folder and restic's cache outside the volume (in
# folders owned by root), and /data/var/backup, given to mail when it is
# created. /data belongs to mail in Poste.io, so /data/var is checked first:
# a real folder, owned by root, writable by nobody else. Root never changes
# the owner of something that already exists. The rest (folders inside, the
# encryption key, made once and never replaced) is done as mail.
#
# If something is wrong the reason goes to /run/poste-backup.start-error,
# shown by the admin, and nothing is done on the volume.
set -u
V=/data/var
D=$V/backup
ERR=/run/poste-backup.start-error

# outside the volume: parents owned by root, nothing mail can swap
rm -f "$ERR"
touch /run/poste-backup.lock
chown mail:mail /run/poste-backup.lock
chmod 0644 /run/poste-backup.lock
mkdir -p /run/poste-backup /var/cache/poste-backup
chown mail:mail /run/poste-backup /var/cache/poste-backup
chmod 0700 /run/poste-backup /var/cache/poste-backup

refuse() {
    echo "backup: $1: nothing done, backups cannot run" >&2
    echo "$1" > "$ERR"
    chmod 0644 "$ERR"
    exit 0
}

if [ -L "$V" ] || [ ! -d "$V" ] || [ "$(stat -c %u "$V")" != "0" ] || [ -n "$(find "$V" -maxdepth 0 -perm /022)" ]; then
    refuse "$V is not a folder owned by root and writable only by root"
fi
if [ -e "$D" ] || [ -L "$D" ]; then
    if [ -L "$D" ] || [ ! -d "$D" ] || [ "$(stat -c %u "$D")" != "$(id -u mail)" ]; then
        refuse "$D exists but is not a folder of the user mail"
    fi
else
    mkdir -m 0750 "$D" && chown mail:mail "$D" || refuse "cannot create $D"
fi

runuser -u mail -- sh -c '
    umask 027
    D=/data/var/backup
    mkdir -p "$D/settings/queue" "$D/settings/ssh" "$D/engine/logs"
    chmod 0750 "$D" "$D/settings" "$D/settings/queue" "$D/engine" "$D/engine/logs"
    chmod 0700 "$D/settings/ssh"
    if [ ! -e "$D/settings/restic.key" ]; then
        umask 077
        head -c 48 /dev/urandom | base64 -w 0 > "$D/settings/restic.key.new" &&
            mv "$D/settings/restic.key.new" "$D/settings/restic.key"
    fi
' || refuse "cannot prepare $D as the user mail"
