#!/bin/sh
# End-to-end test of an image built from this repository.
#
#   sh tests/run.sh <image>        e.g. sh tests/run.sh poste.io-addons-test
#
# Starts a throw-away container (no published ports, its own volume), creates
# a test domain and three mailboxes with random passwords, then runs
# tests/test_addons.py, tests/test_activesync.py and tests/test_backup.py
# inside the container (add-ons missing from the image are skipped). With the
# backup add-on, two more throw-away containers are test destinations on the
# same private network: Samba + SFTP (tests/backup-dest), S3 (rclone's own
# S3 server) and restic's REST server in append-only mode over HTTPS (its
# official image; a certificate made here with openssl); a tmpfs at
# /backup/local plays a volume mounted by the host.
# Then tests/test_restore.sh reads the backups from another machine and
# rebuilds the whole server from them on a new installation.
# Exit code 0 = every check passed. Containers, volume and network are
# removed at the end.
set -eu

IMAGE=${1:?usage: sh tests/run.sh <image>}
NAME=poste-addons-test-$$
DIR=$(cd "$(dirname "$0")" && pwd)

# S3 test server: rclone's official image, pinned (1.68.2)
S3_IMAGE=rclone/rclone:1.68.2@sha256:74c51b8817e5431bd6d7ed27cb2a50d8ee78d77f6807b72a41ef6f898845942b
# REST server: restic's official rest-server, pinned (0.12.1)
REST_IMAGE=restic/rest-server:0.12.1@sha256:19ff50daca27a473bfa38ebf9d8156e2dcba74c494839dd7b09e90fb32cb0efe

cleanup() {
    docker rm -f "$NAME" "$NAME-dest" "$NAME-s3" "$NAME-reader" "$NAME-new" >/dev/null 2>&1 || true
    # with its anonymous volume (/data of the image), that one only
    docker rm -f -v "$NAME-rest" >/dev/null 2>&1 || true
    docker volume rm "$NAME-data" "$NAME-new-data" "$NAME-restore" >/dev/null 2>&1 || true
    docker network rm "$NAME-net" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$NAME-net" >/dev/null
docker run -d --name "$NAME" --network "$NAME-net" --hostname mail.example.test -v "$NAME-data:/data" \
    --tmpfs /backup/local:uid=8,gid=8,mode=0750 "$IMAGE" >/dev/null

echo "== waiting for the admin and ActiveSync"
i=0
until docker exec "$NAME" sh -c 'curl -skf -o /dev/null https://localhost/admin/login &&
        curl -sk -o /dev/null -w "%{http_code}" -X OPTIONS https://localhost/Microsoft-Server-ActiveSync | grep -qE "^(200|401)$"' 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -lt 60 ] || { echo "the container did not start in 5 minutes"; docker logs --tail 50 "$NAME"; exit 1; }
    sleep 5
done

# The tests are Python (standard library only). Poste.io's base image has no
# python3: getmail6 (External Mailboxes) brings it, so an image without that
# add-on gets it here, in the throw-away container only.
if ! docker exec "$NAME" sh -c 'command -v python3' >/dev/null; then
    echo "== installing python3 in the test container"
    docker exec "$NAME" sh -c 'apt-get update -qq && apt-get install -y -qq --no-install-recommends python3 >/dev/null'
fi

echo "== waiting for IMAP"
i=0
until docker exec "$NAME" python3 -c 'import socket; socket.create_connection(("localhost", 993), 5)' 2>/dev/null; do
    i=$((i + 1))
    [ "$i" -lt 60 ] || { echo "IMAP did not start in 5 minutes"; exit 1; }
    sleep 5
done

docker exec "$NAME" mkdir -p /tests
for f in panel.py eas.py test_addons.py test_activesync.py test_backup.py test_restore.py backup_unit.php; do
    docker cp "$DIR/$f" "$NAME:/tests/$f"
done

if docker exec "$NAME" test -x /usr/local/bin/poste-backup; then
    echo "== backup add-on: test destinations"
    DP=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')
    docker build -q -t poste-addons-test-dest "$DIR/backup-dest" >/dev/null
    docker run -d --name "$NAME-dest" --network "$NAME-net" -e DEST_PASSWORD="$DP" poste-addons-test-dest >/dev/null
    docker run -d --name "$NAME-s3" --network "$NAME-net" "$S3_IMAGE" serve s3 /data --addr :8080 --auth-key "testkey,$DP" >/dev/null
    # the REST server: HTTPS with a certificate for its name (and another one, for a server
    # that is not the confirmed one), a user, --append-only; the files go in before it starts
    RT=$(mktemp -d)
    for c in cert other; do
        openssl req -x509 -newkey ed25519 -nodes -keyout "$RT/$c-key.pem" -out "$RT/$c.pem" -days 30 \
            -subj "/CN=$NAME-rest" -addext "subjectAltName=DNS:$NAME-rest" 2>/dev/null
    done
    docker run --rm --entrypoint htpasswd "$REST_IMAGE" -B -b -n bk "$DP" | head -1 > "$RT/.htpasswd"
    docker create --name "$NAME-rest" --network "$NAME-net" \
        -e OPTIONS="--append-only --tls --tls-cert /data/cert.pem --tls-key /data/cert-key.pem" "$REST_IMAGE" >/dev/null
    for f in .htpasswd cert.pem cert-key.pem; do
        docker cp "$RT/$f" "$NAME-rest:/data/$f"
    done
    docker start "$NAME-rest" >/dev/null
    docker cp "$RT/cert.pem" "$NAME:/tests/rest-cert.pem"
    docker cp "$RT/other.pem" "$NAME:/tests/rest-other.pem"
    rm -rf "$RT"
    # the real size and free space of the Samba share, read on its server: what
    # the admin must show (rclone 1.60 reports SMB space wrong)
    SMB_DF=$(docker exec "$NAME-dest" df -B1 --output=size,avail /srv/smb | tail -1)
    docker exec "$NAME" sh -c 'umask 077; printf "DEST_HOST=%s\nS3_HOST=%s\nDEST_PASSWORD=%s\nDEST_SMB_SIZE=%s\nDEST_SMB_AVAIL=%s\nREST_HOST=%s\n" "$1" "$2" "$3" "$4" "$5" "$6" > /tests/backup.env' \
        sh "$NAME-dest" "$NAME-s3" "$DP" $SMB_DF "$NAME-rest"
fi

# test accounts: random passwords, kept in the container only
docker exec "$NAME" python3 -c '
import secrets
with open("/tests/test.env", "w") as f:
    for k, v in (("ALICE", "alice@example.test"), ("BOB", "bob@example.test"), ("CAROL", "carol@example.test")):
        f.write("%s=%s\nP%s=%s\n" % (k, v, k[0], secrets.token_hex(12)))'
docker exec "$NAME" sh -c 'chmod 600 /tests/test.env; . /tests/test.env; cd /opt/admin &&
    runuser -u mail -- php bin/console domain:create -q example.test &&
    runuser -u mail -- php bin/console email:create -q "$ALICE" "$PA" Alice &&
    runuser -u mail -- php bin/console email:create -q "$BOB" "$PB" Bob &&
    runuser -u mail -- php bin/console email:create -q "$CAROL" "$PC" Carol &&
    runuser -u mail -- php bin/console email:admin -q "$ALICE"'

# WBXML token tables, straight from the Z-Push in the image
docker exec "$NAME" php -r 'include "/opt/z-push/lib/wbxml/wbxmldefs.php";
    class D extends WBXMLDefs { function get() { return $this->dtd; } }
    $d = (new D())->get();
    file_put_contents("/tests/wbxmldefs.json", json_encode(["codes" => $d["codes"], "namespaces" => $d["namespaces"]]));'

status=0
echo "== External Mailboxes, ActiveSync Logs, dark theme, spam learning"
docker exec "$NAME" python3 /tests/test_addons.py || status=$((status + $?))
echo "== calendars and contacts over ActiveSync"
docker exec "$NAME" python3 /tests/test_activesync.py || status=$((status + $?))
echo "== backup"
docker exec "$NAME" python3 /tests/test_backup.py || status=$((status + $?))
if docker exec "$NAME" test -x /usr/local/bin/poste-backup; then
    echo "== backup: reading the backups without the server, a whole server rebuilt from them"
    sh "$DIR/test_restore.sh" "$IMAGE" "$NAME" || status=$((status + $?))
fi
echo "== failed checks: $status"
if [ "$status" -ne 0 ]; then
    echo "== last log lines (getmail, mail, Z-Push)"
    docker exec "$NAME" sh -c 'tail -n 15 /data/log/getmail/getmail.log; tail -n 15 /data/log/mail.log;
        tail -n 30 /var/log/z-push/z-push-error.log 2>/dev/null' || true
fi
exit "$status"
