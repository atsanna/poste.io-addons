#!/bin/sh
# The two restores that matter when the mail server is lost, run as
# docs/en/docs/backup.md describes them. Called by tests/run.sh after test_backup.py,
# with the image and the name of its containers (the server of the tests,
# its destinations and its network are running):
#
#   sh tests/test_restore.sh <image> <name>
#
# 1. Reading the backups on another machine (tests/backup-reader: Debian 12
#    with only restic, rclone and ssh) with the key file downloaded from the
#    admin, from each kind of destination (tests/read_backups.sh).
# 2. A whole server rebuilt from a backup: a new installation of the same
#    image, started once and stopped, the backup restored as root in a
#    container with no service running (tests/rebuild_server.sh), started
#    again, then accounts, messages, settings, databases and owners compared
#    with the old server (tests/test_restore.py).
#
# Secrets (the key, the destinations' passwords) go from container to
# container through pipes: nothing is written on this machine. The containers
# and volumes made here ($NAME-reader, $NAME-new, $NAME-new-data,
# $NAME-restore) are removed by run.sh. Exit code: the number of failures.
set -u
IMAGE=${1:?usage: sh tests/test_restore.sh <image> <name>}
NAME=${2:?usage: sh tests/test_restore.sh <image> <name>}
DIR=$(cd "$(dirname "$0")" && pwd)
fails=0

check() {   # name, 0 = ok, what was found
    if [ "$2" = 0 ]; then echo "PASS  $1  ($3)"; else echo "FAIL  $1  ($3)"; fails=$((fails + 1)); fi
}

wait_admin() {
    i=0
    until docker exec "$1" curl -skf -o /dev/null https://localhost/admin/login 2>/dev/null; do
        i=$((i + 1))
        [ "$i" -lt 60 ] || return 1
        sleep 5
    done
}

echo "-- restore: a last backup, what must come back, the key"
docker exec "$NAME" python3 /tests/test_restore.py prepare
r=$?
fails=$((fails + r))
if [ "$r" -ne 0 ]; then
    echo "-- restore: nothing to restore from, the other restore checks are not run"
    exit "$fails"
fi

echo "-- restore: reading the backups on another machine"
docker build -q -t poste-addons-test-reader "$DIR/backup-reader" >/dev/null
docker run -d --name "$NAME-reader" --network "$NAME-net" poste-addons-test-reader >/dev/null
docker exec "$NAME-reader" mkdir -m 700 /work
for f in key.txt restore.env; do
    docker exec "$NAME" cat "/tests/$f" | docker exec -i "$NAME-reader" sh -c 'umask 077; cat > "/work/$1"' sh "$f"
done
# a key the SFTP server accepts: the one the old server's admin made
docker exec "$NAME" sh -c '. /tests/restore.env; cat "/data/var/backup/settings/ssh/$SFTPKEY_ID"' |
    docker exec -i "$NAME-reader" sh -c 'umask 077; cat > /work/sftp-key'
# the REST server's certificate (cert.pem made on that server)
docker exec "$NAME" cat /tests/rest-cert.pem | docker exec -i "$NAME-reader" sh -c 'cat > /work/server.pem'
# the local destination's folder, as a share mounted on that machine
docker exec "$NAME" tar -C /backup -cf - local | docker exec -i "$NAME-reader" tar -C /work -xf -
docker cp "$DIR/read_backups.sh" "$NAME-reader:/work/read_backups.sh" >/dev/null
docker exec "$NAME-reader" bash /work/read_backups.sh
fails=$((fails + $?))

echo "-- restore: the whole server rebuilt on a new installation"
docker volume create "$NAME-new-data" >/dev/null
docker volume create "$NAME-restore" >/dev/null
docker run -d --name "$NAME-new" --network "$NAME-net" --hostname mail.example.test -v "$NAME-new-data:/data" "$IMAGE" >/dev/null
wait_admin "$NAME-new"
check "new installation started (admin answering), then stopped" $? "$NAME-new"
docker stop "$NAME-new" >/dev/null

# the folder of the host with the key file (here a volume, filled through pipes)
put() {   # file name in /restore, content on stdin
    docker run --rm -i -v "$NAME-restore:/restore" --entrypoint sh "$IMAGE" -c 'umask 077; cat > "/restore/$1"' sh "$1"
}
docker exec "$NAME" cat /tests/key.txt | put key.txt
docker exec "$NAME" cat /tests/restore.env | put restore.env
put rebuild_server.sh < "$DIR/rebuild_server.sh"
out=$(docker run --rm --network "$NAME-net" -v "$NAME-new-data:/data" -v "$NAME-restore:/restore" \
      --entrypoint bash "$IMAGE" /restore/rebuild_server.sh 2>&1)
r=$?
[ "$r" -eq 0 ] && echo "$out" | grep -q '^rebuild: done$'
check "the backup restored into the new server's data, as root, with no service running" $? "exit $r: $(echo "$out" | tail -n 1)"
[ "$r" -eq 0 ] || echo "$out" | tail -n 20

docker start "$NAME-new" >/dev/null
wait_admin "$NAME-new"
check "the rebuilt server starts (admin answering)" $? "$NAME-new"
if ! docker exec "$NAME-new" sh -c 'command -v python3' >/dev/null; then
    docker exec "$NAME-new" sh -c 'apt-get update -qq && apt-get install -y -qq --no-install-recommends python3 >/dev/null'
fi
i=0
until docker exec "$NAME-new" python3 -c 'import socket; socket.create_connection(("localhost", 993), 5)' 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -lt 60 ] || break
    sleep 5
done
docker exec "$NAME-new" mkdir -m 700 /tests
for f in test.env restore.env restore-before.json; do
    docker exec "$NAME" cat "/tests/$f" | docker exec -i "$NAME-new" sh -c 'umask 077; cat > "/tests/$1"' sh "$f"
done
for f in panel.py test_restore.py; do
    docker cp "$DIR/$f" "$NAME-new:/tests/$f" >/dev/null
done
docker exec "$NAME-new" python3 /tests/test_restore.py verify
fails=$((fails + $?))

echo "-- restore: failed checks: $fails"
exit "$fails"
