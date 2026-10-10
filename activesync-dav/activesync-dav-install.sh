#!/bin/sh
# activesync-dav-install.sh <z-push dir> <add-on dir>
#
# Turns Z-Push (ActiveSync) from mail only into mail + calendar + contacts:
# BackendCombined with IMAP for mail, BackendPosteCalDAV (postecaldav.php)
# for calendars and BackendPosteCardDAV (postecarddav.php) for contacts,
# both on the Poste.io DAV server at https://localhost/dav/ (postedav.php
# is their shared PROPFIND helper). Calendars and address books are still
# created from the admin (DAV section of the mailbox): a user without them
# keeps mail only.
#
# Every line to change must be found exactly once, as it is in Z-Push
# 2.7.5 shipped with Poste.io 2.5.17; otherwise the script STOPS before
# touching anything (in a Docker build: the build fails).
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

C=$Z/backend/combined/config.php
CAL=$Z/backend/caldav/config.php
CARD=$Z/backend/carddav/config.php
F='                SYNC_FOLDER_TYPE_'

edits() {
    "$1" "$Z/config.php" "define('BACKEND_PROVIDER', 'BackendIMAP');" "define('BACKEND_PROVIDER', 'BackendCombined');"

    "$1" "$C" "                    'name' => 'BackendCardDAV'," "                    'name' => 'BackendPosteCardDAV',"
    "$1" "$C" "                    'name' => 'BackendCalDAV'," "                    'name' => 'BackendPosteCalDAV',"
    "$1" "$C" "${F}TASK => 'z',"             "${F}TASK => 'c',"
    "$1" "$C" "${F}APPOINTMENT => 'z',"      "${F}APPOINTMENT => 'c',"
    "$1" "$C" "${F}CONTACT => 'z',"          "${F}CONTACT => 'd',"
    "$1" "$C" "${F}NOTE => 'z',"             "${F}NOTE => 'i',"
    "$1" "$C" "${F}JOURNAL => 'z',"          "${F}JOURNAL => 'i',"
    "$1" "$C" "${F}USER_APPOINTMENT => 'z'," "${F}USER_APPOINTMENT => 'c',"
    "$1" "$C" "${F}USER_CONTACT => 'z',"     "${F}USER_CONTACT => 'd',"
    "$1" "$C" "${F}USER_TASK => 'z',"        "${F}USER_TASK => 'c',"
    "$1" "$C" "${F}USER_JOURNAL => 'z',"     "${F}USER_JOURNAL => 'i',"
    "$1" "$C" "${F}USER_NOTE => 'z',"        "${F}USER_NOTE => 'i',"
    "$1" "$C" "${F}UNKNOWN => 'z',"          "${F}UNKNOWN => 'i',"

    "$1" "$CAL" "define('CALDAV_SERVER', 'caldavserver.domain.com');" "define('CALDAV_SERVER', 'localhost');"
    "$1" "$CAL" "define('CALDAV_PATH', '/caldav.php/%u/');"           "define('CALDAV_PATH', '/dav/calendars/%u/');"

    "$1" "$CARD" "define('CARDDAV_PATH', '/caldav.php/%u/');"                   "define('CARDDAV_PATH', '/dav/addressbooks/%u/');"
    # no fixed default address book in Poste.io: new contacts go to the
    # first address book found (backend/carddav/carddav.php, ChangeMessage)
    "$1" "$CARD" "define('CARDDAV_DEFAULT_PATH', '/caldav.php/%u/addresses/');" "define('CARDDAV_DEFAULT_PATH', '/dav/addressbooks/%u/');"
    # no global address list in Poste.io
    "$1" "$CARD" "define('CARDDAV_GAL_PATH', '/caldav.php/%d/GAL/');"           "// define('CARDDAV_GAL_PATH', '/caldav.php/%d/GAL/');"
}

edits check
edits swap

# nothing may still point to the Kopano backend, which is not installed
[ "$(grep -c "=> 'z'," "$C" || true)" = 0 ] || stop "$C: a folder type still maps to 'z'"

mkdir "$Z/backend/postecaldav" "$Z/backend/postecarddav"
cp "$A/postecaldav.php" "$A/postedav.php" "$Z/backend/postecaldav/"
cp "$A/postecarddav.php" "$Z/backend/postecarddav/"
chmod 0755 "$Z/backend/postecaldav" "$Z/backend/postecarddav"
chmod 0644 "$Z/backend/postecaldav/postecaldav.php" "$Z/backend/postecaldav/postedav.php" "$Z/backend/postecarddav/postecarddav.php"

for f in "$Z/config.php" "$C" "$CAL" "$CARD" "$Z/backend/postecaldav/postecaldav.php" \
         "$Z/backend/postecaldav/postedav.php" "$Z/backend/postecarddav/postecarddav.php"; do
    php -l "$f" > /dev/null || stop "$f: PHP syntax error"
done

echo "activesync-dav: installed"
