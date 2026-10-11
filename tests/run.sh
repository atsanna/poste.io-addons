#!/bin/sh
# End-to-end test of an image built from this repository.
#
#   sh tests/run.sh <image>        e.g. sh tests/run.sh poste.io-addons-test
#
# Starts a throw-away container (no published ports, its own volume), creates
# a test domain and three mailboxes with random passwords, then runs
# tests/test_addons.py and tests/test_activesync.py inside the container
# (add-ons missing from the image are skipped). Exit code 0 = every check
# passed. The container and the volume are removed at the end.
set -eu

IMAGE=${1:?usage: sh tests/run.sh <image>}
NAME=poste-addons-test-$$
DIR=$(cd "$(dirname "$0")" && pwd)

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker volume rm "$NAME-data" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --name "$NAME" --hostname mail.example.test -v "$NAME-data:/data" "$IMAGE" >/dev/null

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
for f in panel.py eas.py test_addons.py test_activesync.py; do
    docker cp "$DIR/$f" "$NAME:/tests/$f"
done

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
echo "== External Mailboxes, ActiveSync Logs, dark theme, upstream fixes"
docker exec "$NAME" python3 /tests/test_addons.py || status=$((status + $?))
echo "== calendars and contacts over ActiveSync"
docker exec "$NAME" python3 /tests/test_activesync.py || status=$((status + $?))
echo "== failed checks: $status"
if [ "$status" -ne 0 ]; then
    echo "== last log lines (getmail, mail, Z-Push)"
    docker exec "$NAME" sh -c 'tail -n 15 /data/log/getmail/getmail.log; tail -n 15 /data/log/mail.log;
        tail -n 30 /var/log/z-push/z-push-error.log 2>/dev/null' || true
fi
exit "$status"
