#!/bin/sh
# the password of the test user comes from the environment (random, per run)
set -e
echo "bkuser:$DEST_PASSWORD" | chpasswd
printf '%s\n%s\n' "$DEST_PASSWORD" "$DEST_PASSWORD" | smbpasswd -s -a bkuser
smbd --foreground --no-process-group &
exec /usr/sbin/sshd -D -e
