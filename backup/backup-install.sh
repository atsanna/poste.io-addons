#!/bin/sh
# Backup add-on for Poste.io: installs the engine, the admin pages, their menu
# entry and translations, the cron entry and the start script. Used by the
# Dockerfile (after restic, rclone, openssh-client and smbclient are installed there) and
# for a manual install, so what is tested is what runs in the build.
#
#   sh backup-install.sh <add-on dir> [admin dir]
#
# It stops (exit 1) if Poste.io changes the menu or if something is missing:
# a failed build is better than a half-installed backup.
set -e
SRC=${1:?usage: sh backup-install.sh <add-on dir> [admin dir]}
ADMIN=${2:-/opt/admin}
MENU="$ADMIN/templates/_menu.html.twig"
LIB=/usr/local/lib/poste-backup
ENTRY="route starts with 'poste_backup'"

for tool in restic rclone ssh-keyscan ssh-keygen smbclient sqlite3 php runuser setsid; do
    command -v "$tool" >/dev/null || { echo "backup: STOP, $tool is not installed"; exit 1; }
done
for ext in pcntl posix sqlite3 mbstring; do
    php -m | grep -qx "$ext" || { echo "backup: STOP, the PHP extension $ext is missing"; exit 1; }
done
if grep -qF "$ENTRY" "$MENU"; then
    echo "backup: STOP, already installed in $MENU"; exit 1
fi
# the entry goes in its own block right before the line that closes the
# administrators' part of the menu (the same place as External Mailboxes)
if [ "$(grep -c '^{% endif %}$' "$MENU")" != "1" ]; then
    echo "backup: STOP, the end of the administrators' menu ({% endif %} alone on a line) is not found exactly once in $MENU"; exit 1
fi
for l in en cs; do
    if grep -q '^poste_backup:' "$ADMIN/translations/Base/messages+intl-icu.$l.yml"; then
        echo "backup: STOP, the 'poste_backup:' key already exists in the $l translations"; exit 1
    fi
    [ -f "$SRC/backup.$l.yml" ] || { echo "backup: STOP, missing $SRC/backup.$l.yml"; exit 1; }
done

# engine: library, command, an empty rclone configuration (every remote is
# defined in the environment of each run)
mkdir -p "$LIB/lib"
install -m 0644 "$SRC"/engine/lib/*.php "$LIB/lib/"
install -m 0755 "$SRC/engine/poste-backup" /usr/local/bin/poste-backup
: > "$LIB/rclone.conf"
chmod 0644 "$LIB/rclone.conf"
chmod 0755 "$LIB" "$LIB/lib"

# every minute; the engine decides what is due (and takes a lock)
printf '%s\n' '# Backup add-on: the engine runs what is due, as the user mail (it takes a lock: one run at a time)' \
    '* * * * * mail /usr/local/bin/poste-backup tick >/dev/null 2>&1' > /etc/cron.d/poste-backup
chmod 0644 /etc/cron.d/poste-backup

# /data is a volume: its folders are made at every start (also on an existing installation)
install -m 0755 "$SRC/90-backup.sh" /etc/cont-init.d/90-backup.sh

# admin pages (new files only)
cp -R "$SRC/admin/." "$ADMIN/"

# menu entry, in a block of its own for super admins
awk '
    $0 == "{% endif %}" {
        print "    {% if is_granted('\''ROLE_SUPER_ADMIN'\'') %}"
        print "<ul class=\"nav nav-sidebar\">"
        print "    <li class=\"{% if route starts with '\''poste_backup'\'' %}active{% endif %}\">"
        print "        <a href=\"{{ path('\''poste_backup'\'') }}\"><i class=\"fa fa-hdd-o fa-fw\"></i> {{ '\''poste_backup.menu'\''|trans }}</a>"
        print "    </li>"
        print "</ul>"
        print "    {% endif %}"
    }
    { print }
' "$MENU" > "$MENU.tmp"
mv "$MENU.tmp" "$MENU"
if [ "$(grep -cF "$ENTRY" "$MENU")" != "1" ]; then
    echo "backup: STOP, the menu entry was not inserted exactly once in $MENU"; exit 1
fi

for l in en cs; do
    T="$ADMIN/translations/Base/messages+intl-icu.$l.yml"
    echo "" >> "$T"
    cat "$SRC/backup.$l.yml" >> "$T"
done

# the PHP files must at least parse
for f in "$LIB"/lib/*.php /usr/local/bin/poste-backup "$ADMIN/src/Base/Controller/PosteBackupController.php"; do
    php -l "$f" >/dev/null || { echo "backup: STOP, $f does not parse"; exit 1; }
done
echo "backup: installed"
