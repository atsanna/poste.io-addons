#!/bin/sh
# ActiveSync Logs for the Poste.io admin (add-on).
#
# Installs the page, its menu entry (right after "User logs"), the en/cs
# translations and the configurable rotation of the Z-Push logs. Used both by
# the Dockerfile and for a manual install, so what is tested on a running
# server is exactly what runs in the build.
#
#   sh activesync-logs-install.sh <add-on dir> [admin dir]
#
# It stops (exit 1) if Poste.io changes the menu: a failed build is better
# than a page that silently has no menu entry.
set -e
SRC=${1:?usage: sh activesync-logs-install.sh <add-on dir> [admin dir]}
ADMIN=${2:-/opt/admin}
MENU="$ADMIN/templates/_menu.html.twig"
ANCHOR="pro_path('auth_log')"
ENTRY_ROUTE="route starts with 'activesync_logs'"

if grep -qF "$ENTRY_ROUTE" "$MENU"; then
    echo "activesync-logs: STOP, already installed in $MENU"; exit 1
fi
if [ "$(grep -cF "$ANCHOR" "$MENU")" != "1" ]; then
    echo "activesync-logs: STOP, the \"User logs\" entry ($ANCHOR) does not appear exactly once in $MENU"; exit 1
fi
# the line after the "User logs" link must close its <li>
if ! grep -A1 -F "$ANCHOR" "$MENU" | tail -n 1 | grep -qx '[[:space:]]*</li>'; then
    echo "activesync-logs: STOP, the \"User logs\" entry is not followed by </li> in $MENU"; exit 1
fi
for l in en cs; do
    if grep -q '^activesync_logs:' "$ADMIN/translations/Base/messages+intl-icu.$l.yml"; then
        echo "activesync-logs: STOP, the 'activesync_logs:' key already exists in the $l translations"; exit 1
    fi
    [ -f "$SRC/activesync-logs.$l.yml" ] || { echo "activesync-logs: STOP, missing $SRC/activesync-logs.$l.yml"; exit 1; }
done

# page (new files only)
cp -R "$SRC/admin/." "$ADMIN/"

# menu entry right after "User logs", in the super administrator's block
awk -v anchor="$ANCHOR" '
    { print }
    found && $0 ~ /^[[:space:]]*<\/li>$/ {
        print "            <li class=\"{% if route starts with '\''activesync_logs'\'' %}active{% endif %}\">"
        print "                <a href=\"{{ path('\''activesync_logs'\'') }}\"><i class=\"fa fa-mobile fa-fw\"></i> {{ '\''activesync_logs.menu'\''|trans }}</a>"
        print "            </li>"
        found = 0
    }
    index($0, anchor) { found = 1 }
' "$MENU" > "$MENU.tmp"
mv "$MENU.tmp" "$MENU"
if [ "$(grep -cF "$ENTRY_ROUTE" "$MENU")" != "1" ]; then
    echo "activesync-logs: STOP, the menu entry was not inserted exactly once in $MENU"; exit 1
fi

for l in en cs; do
    T="$ADMIN/translations/Base/messages+intl-icu.$l.yml"
    echo "" >> "$T"
    cat "$SRC/activesync-logs.$l.yml" >> "$T"
done

# Rotation of the Z-Push logs, configurable in the admin: the admin (user mail)
# writes /data/var/activesync-logs/log-rotation.ini, this root script turns it
# into the logrotate rule at 00:00 (Poste.io's logrotate runs at 00:01), at
# every container start, and once here for the default rule
install -m 0755 "$SRC/activesync-logs-logrotate" /usr/local/bin/activesync-logs-logrotate
install -m 0644 "$SRC/activesync-logs-logrotate.cron" /etc/cron.d/activesync-logs-logrotate
# /data is a volume: its folder is created at every start (also on an existing installation)
install -m 0755 "$SRC/90-activesync-logs.sh" /etc/cont-init.d/90-activesync-logs.sh
/usr/local/bin/activesync-logs-logrotate

echo "activesync-logs: installed"
