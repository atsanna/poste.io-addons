#!/bin/sh
# activesync-dav-install.sh <z-push dir> <add-on dir>
#
# Since 2.5.24 Poste.io itself serves calendars and contacts over ActiveSync:
# BackendCombined with IMAP for mail and Z-Push's own BackendCalDAV and
# BackendCardDAV on the Poste.io DAV server (cont-init.d/20-apply-server-config
# switches BACKEND_PROVIDER to BackendCombined at every start while DAV is
# enabled). This add-on keeps that layout and only swaps the two DAV backends
# for BackendPosteCalDAV (postecaldav.php) and BackendPosteCardDAV
# (postecarddav.php), which fix what the stock ones still get wrong
# (docs/en/docs/activesync-dav.md, "Z-Push issues worked around"):
#
#   - an event refused by the server (read-only shared calendar) is reported
#     to the phone as an error instead of "saved";
#   - the DAV server switched off at runtime does not take ActiveSync down.
#
# postedav.php is their shared PROPFIND helper. They use the server, port and
# paths that Poste.io writes in backend/caldav and backend/carddav/config.php.
#
# Every line to change must be found exactly once, as it is in Poste.io
# 2.5.24; otherwise the script STOPS before touching anything (in a Docker
# build: the build fails).
set -eu

Z=${1:?z-push dir}
A=${2:?add-on dir}

stop() { echo "activesync-dav: STOP - $*" >&2; exit 1; }

[ -f "$Z/config.php" ] || stop "$Z/config.php not found"
for f in postecaldav.php postecarddav.php postedav.php; do
    [ -f "$A/$f" ] || stop "$A/$f not found"
done
[ -f /usr/share/awl/inc/XMLDocument.php ] || stop "libawl-php missing (/usr/share/awl/inc/XMLDocument.php)"
[ ! -e "$Z/backend/postecaldav" ] || stop "already installed ($Z/backend/postecaldav exists)"
[ ! -e "$Z/backend/postecarddav" ] || stop "already installed ($Z/backend/postecarddav exists)"

# count <file> <line>: how many lines are exactly <line>
count() { grep -cxF -- "$2" "$1" || true; }

# check <file> <old> <new>: <old> once, <new> never
check() {
    [ "$(count "$1" "$2")" = 1 ] || stop "$1: expected once: $2"
    [ "$(count "$1" "$3")" = 0 ] || stop "$1: already present: $3"
}

# swap <file> <old> <new>: replace the line, keep owner and mode
swap() {
    t=$(mktemp)
    awk -v o="$2" -v n="$3" '$0 == o { print n; next } { print }' "$1" > "$t"
    cat "$t" > "$1"
    rm -f "$t"
    [ "$(count "$1" "$3")" = 1 ] || stop "$1: not replaced: $3"
}

# same <file> <line>: the line Poste.io must already have, untouched
same() { [ "$(count "$1" "$2")" = 1 ] || stop "$1: expected once: $2"; }

C=$Z/backend/combined/config.php
CAL=$Z/backend/caldav/config.php
CARD=$Z/backend/carddav/config.php
F='                SYNC_FOLDER_TYPE_'

# what Poste.io 2.5.24 already sets, and the backends rely on
same "$CAL"  "define('CALDAV_SERVER', '127.0.0.1');"
same "$CAL"  "define('CALDAV_PATH', '/dav/calendars/%u/');"
same "$CARD" "define('CARDDAV_SERVER', '127.0.0.1');"
same "$CARD" "define('CARDDAV_PATH', '/dav/addressbooks/%u/');"
for t in "APPOINTMENT => 'c'," "TASK => 'c'," "CONTACT => 'd'," "USER_APPOINTMENT => 'c'," "USER_CONTACT => 'd'," "USER_TASK => 'c',"; do
    same "$C" "$F$t"
done
grep -q "^define('CARDDAV_GAL_PATH'" "$CARD" && stop "$CARD: a global address list is configured"

edits() {
    "$1" "$C" "                'c' => array('name' => 'BackendCalDAV'),"  "                'c' => array('name' => 'BackendPosteCalDAV'),"
    "$1" "$C" "                'd' => array('name' => 'BackendCardDAV')," "                'd' => array('name' => 'BackendPosteCardDAV'),"
    # no fixed default address book in Poste.io: new contacts go to the
    # first address book of the user (the stock path would create "default")
    "$1" "$CARD" "define('CARDDAV_DEFAULT_PATH', '/dav/addressbooks/%u/default/');" "define('CARDDAV_DEFAULT_PATH', '/dav/addressbooks/%u/');"
}

edits check
edits swap

mkdir "$Z/backend/postecaldav" "$Z/backend/postecarddav"
cp "$A/postecaldav.php" "$A/postedav.php" "$Z/backend/postecaldav/"
cp "$A/postecarddav.php" "$Z/backend/postecarddav/"
chmod 0755 "$Z/backend/postecaldav" "$Z/backend/postecarddav"
chmod 0644 "$Z/backend/postecaldav/postecaldav.php" "$Z/backend/postecaldav/postedav.php" "$Z/backend/postecarddav/postecarddav.php"

for f in "$C" "$CAL" "$CARD" "$Z/backend/postecaldav/postecaldav.php" \
         "$Z/backend/postecaldav/postedav.php" "$Z/backend/postecarddav/postecarddav.php"; do
    php -l "$f" > /dev/null || stop "$f: PHP syntax error"
done

echo "activesync-dav: installed"
