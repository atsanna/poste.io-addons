#!/bin/bash
# Rebuilding the whole server: the commands of docs/en/docs/backup.md ("Rebuilding
# the whole server", step 3 to 5), run as written, as root, in a container of
# the image with the new server's /data and the folder /restore, while the
# new server is stopped. /restore holds key.txt (downloaded from the old
# server's admin); here also restore.env, the SMB destination as it was
# entered in the admin, used instead of typing the password, and the
# snapshot to restore (an administrator picks it from "restic snapshots").
set -eu
cd /restore
. ./restore.env

tail -n 1 key.txt > restic.key
chmod 600 restic.key
export RESTIC_PASSWORD_FILE=$PWD/restic.key
export RCLONE_CONFIG_BK_TYPE=smb RCLONE_CONFIG_BK_HOST=$SMB_HOST RCLONE_CONFIG_BK_USER=$SMB_USER
P=$SMB_PASSWORD
export RCLONE_CONFIG_BK_PASS=$(printf '%s\n' "$P" | rclone obscure -) && unset P
export RESTIC_REPOSITORY=rclone:BK:$SMB_SHARE/$SMB_FOLDER

restic snapshots --group-by tags --latest 1
restic restore "$SNAP_SMB" --target /

S=/data/var/backup/engine/staging
cp "$S/users.db" /data/users.db
cp "$S/dav.db" /data/dav.db
cp "$S/roundcube.db" /data/roundcube/roundcube.db
if [ -f "$S/index.db" ]; then install -o mail -g mail -m 600 "$S/index.db" /data/var/backup/engine/index.db; fi
rm -f /data/users.db-wal /data/users.db-shm /data/dav.db-wal /data/dav.db-shm \
      /data/roundcube/roundcube.db-wal /data/roundcube/roundcube.db-shm "$S"/*.db

install -o mail -g mail -m 600 restic.key /data/var/backup/settings/restic.key
echo "rebuild: done"
