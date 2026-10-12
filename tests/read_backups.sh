#!/bin/bash
# Reading the backups without the server: the commands of docs/backup.md
# ("Reading the backups without the server"), run as written on a machine
# with only restic, rclone and ssh (tests/backup-reader, Debian 12), for each
# kind of destination. Only difference: the passwords come from restore.env
# (the destinations as they were entered in the admin) instead of being typed
# with "read -rsp". /work holds key.txt (downloaded from the admin), restore.env,
# local/ (a copy of the local destination's folder, as a share mounted on this
# machine would be) and sftp-key (a key the SFTP server accepts).
# Prints PASS or FAIL for each check; the exit code is the number of failures.
set -u
cd /work
. ./restore.env
FAILS=0

check() {   # name, 0 = ok, what was found
    if [ "$2" = 0 ]; then echo "PASS  $1  ($3)"; else echo "FAIL  $1  ($3)"; FAILS=$((FAILS + 1)); fi
}

forget_remote() {   # one destination at a time: nothing of the previous one left
    for v in $(env | sed -n 's/^\(RCLONE_CONFIG_BK_[A-Z_]*\)=.*/\1/p'); do unset "$v"; done
    unset RESTIC_REPOSITORY
}

read_one() {   # name, latest snapshot of the job there
    local name=$1 snap=$2 out got
    out=$(restic snapshots --group-by tags --latest 1 2>&1)
    case "$out" in
        *"${snap:0:8}"*) check "$name: restic lists the snapshots, the latest of the job among them" 0 "${snap:0:8}" ;;
        *) check "$name: restic lists the snapshots, the latest of the job among them" 1 "$(echo "$out" | tail -n 2)" ;;
    esac
    rm -rf /tmp/restore
    out=$(restic restore "$snap" --target /tmp/restore --include "$MSG" 2>&1)
    got=$(md5sum "/tmp/restore$MSG" 2>/dev/null | cut -d' ' -f1)
    [ -n "$MSG_MD5" ] && [ "$got" = "$MSG_MD5" ]
    check "$name: a message restored, identical to the original" $? "${got:-$(echo "$out" | tail -n 1)}"
}

# the key: the last line of the file downloaded from the admin
tail -n 1 key.txt > restic.key
chmod 600 restic.key
export RESTIC_PASSWORD_FILE=$PWD/restic.key

# --- SMB / Samba
export RCLONE_CONFIG_BK_TYPE=smb RCLONE_CONFIG_BK_HOST=$SMB_HOST RCLONE_CONFIG_BK_USER=$SMB_USER
P=$SMB_PASSWORD
export RCLONE_CONFIG_BK_PASS=$(printf '%s\n' "$P" | rclone obscure -) && unset P
export RESTIC_REPOSITORY=rclone:BK:$SMB_SHARE/$SMB_FOLDER
read_one "SMB" "$SNAP_SMB"
out=$(restic check 2>&1)
case "$out" in
    *"exclusive lock"*"no errors were found"*) check "SMB: restic check, the repository is intact (it takes an exclusive lock)" 0 "no errors" ;;
    *) check "SMB: restic check, the repository is intact (it takes an exclusive lock)" 1 "$(echo "$out" | tail -n 2)" ;;
esac
head -c 48 /dev/urandom | base64 > wrong.key
out=$(RESTIC_PASSWORD_FILE=$PWD/wrong.key restic snapshots 2>&1)
case "$out" in
    *"wrong password"*) check "with another key nothing can be read" 0 "wrong password" ;;
    *) check "with another key nothing can be read" 1 "$(echo "$out" | tail -n 1)" ;;
esac
out=$(RESTIC_PASSWORD_FILE=$PWD/key.txt restic snapshots 2>&1)
case "$out" in
    *"wrong password"*) check "the whole downloaded file is not the key (its last line is)" 0 "wrong password" ;;
    *) check "the whole downloaded file is not the key (its last line is)" 1 "$(echo "$out" | tail -n 1)" ;;
esac
forget_remote

# --- SFTP, with the account's password; the server's key checked first
ssh-keyscan -p "$SFTP_PORT" "$SFTP_HOST" > known_hosts 2>/dev/null
ssh-keygen -lf known_hosts > fingerprints
grep -q 'SHA256:' fingerprints
check "SFTP: the fingerprints of the server's keys, to compare" $? "$(wc -l < fingerprints) key(s)"
export RCLONE_CONFIG_BK_TYPE=sftp RCLONE_CONFIG_BK_HOST=$SFTP_HOST RCLONE_CONFIG_BK_PORT=$SFTP_PORT \
       RCLONE_CONFIG_BK_USER=$SFTP_USER RCLONE_CONFIG_BK_KNOWN_HOSTS_FILE=$PWD/known_hosts
P=$SFTP_PASSWORD
export RCLONE_CONFIG_BK_PASS=$(printf '%s\n' "$P" | rclone obscure -) && unset P
export RESTIC_REPOSITORY=rclone:BK:$SFTP_FOLDER
read_one "SFTP" "$SNAP_SFTP"
# a server that does not show the confirmed key is refused
ssh-keygen -q -t ed25519 -N '' -f other-host-key
host=$SFTP_HOST
[ "$SFTP_PORT" = 22 ] || host="[$SFTP_HOST]:$SFTP_PORT"
echo "$host $(cut -d' ' -f1,2 other-host-key.pub)" > other_known_hosts
out=$(RCLONE_CONFIG_BK_KNOWN_HOSTS_FILE=$PWD/other_known_hosts restic snapshots 2>&1)
[ $? -ne 0 ]
check "SFTP: a server key other than the one in known_hosts is refused" $? "$(echo "$out" | grep -o 'knownhosts: key mismatch\|key mismatch\|handshake failed' | head -n 1)"
forget_remote

# --- SFTP, with a key of yours (the key pair of the admin stayed on the lost server)
chmod 600 sftp-key
export RCLONE_CONFIG_BK_TYPE=sftp RCLONE_CONFIG_BK_HOST=$SFTP_HOST RCLONE_CONFIG_BK_PORT=$SFTP_PORT \
       RCLONE_CONFIG_BK_USER=$SFTP_USER RCLONE_CONFIG_BK_KNOWN_HOSTS_FILE=$PWD/known_hosts \
       RCLONE_CONFIG_BK_KEY_FILE=$PWD/sftp-key
export RESTIC_REPOSITORY=rclone:BK:$SFTPKEY_FOLDER
read_one "SFTP with a key" "$SNAP_SFTPKEY"
forget_remote

# --- S3
export RCLONE_CONFIG_BK_TYPE=s3 RCLONE_CONFIG_BK_PROVIDER=Other RCLONE_CONFIG_BK_ENDPOINT=$S3_ENDPOINT \
       RCLONE_CONFIG_BK_ACCESS_KEY_ID=$S3_ACCESS
P=$S3_SECRET
export RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=$P && unset P
export RESTIC_REPOSITORY=rclone:BK:$S3_BUCKET/$S3_FOLDER
read_one "S3" "$SNAP_S3"
forget_remote

# --- a REST server (append-only): its certificate, every command with --cacert
if [ -n "${SNAP_REST:-}" ]; then
    P=$REST_PASSWORD
    export RESTIC_REPOSITORY="rest:https://$REST_USER:$P@$REST_HOST:$REST_PORT/$REST_FOLDER" && unset P
    out=$(restic --cacert server.pem snapshots --group-by tags --latest 1 2>&1)
    case "$out" in
        *"${SNAP_REST:0:8}"*) check "REST: restic lists the snapshots, the latest of the job among them" 0 "${SNAP_REST:0:8}" ;;
        *) check "REST: restic lists the snapshots, the latest of the job among them" 1 "$(echo "$out" | tail -n 2)" ;;
    esac
    rm -rf /tmp/restore
    out=$(restic --cacert server.pem restore "$SNAP_REST" --target /tmp/restore --include /data/var/backup/engine/staging/users.db 2>&1)
    [ -s /tmp/restore/data/var/backup/engine/staging/users.db ]
    check "REST: the accounts database restored from it" $? "$(echo "$out" | tail -n 1)"
    out=$(restic snapshots 2>&1)
    case "$out" in
        *"certificate signed by unknown authority"*) check "REST: without its certificate restic refuses the server" 0 "unknown authority" ;;
        *) check "REST: without its certificate restic refuses the server" 1 "$(echo "$out" | tail -n 1)" ;;
    esac
    forget_remote
else
    check "REST: a snapshot of the job to the REST server" 1 "none in restore.env"
fi

# --- a folder (the share or disk mounted on this machine)
export RESTIC_REPOSITORY=/work/local
read_one "folder" "$SNAP_LOCAL"
forget_remote

echo "FAILURES: $FAILS"
exit "$FAILS"
