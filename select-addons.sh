#!/bin/sh
# select-addons.sh <Dockerfile> <add-on>...
#
# Prints the Dockerfile with only the add-ons you name; everything else is
# left exactly as it is. The add-ons are the sections marked
# "ADD-ON: ... [name]" in the Dockerfile:
#
#   external-mailboxes  dark-theme  activesync-dav  upstream-fixes
#
# Example: only the dark theme and the upstream fixes, Pro edition
#
#   sh select-addons.sh Dockerfile-pro dark-theme upstream-fixes > Dockerfile.custom
#   docker build -f Dockerfile.custom --build-arg POSTE_IO_VERSION=2.5.17 -t my/poste.io:2.5.17 .
set -eu

F=${1:?usage: sh select-addons.sh <Dockerfile> <add-on>...}
shift
[ -f "$F" ] || { echo "select-addons: $F not found" >&2; exit 2; }

known=$(sed -n 's/^#  ADD-ON: .*\[\([a-z-]*\)\]$/\1/p' "$F")
[ -n "$known" ] || { echo "select-addons: no ADD-ON sections in $F" >&2; exit 2; }
[ $# -gt 0 ] || { echo "select-addons: name at least one add-on:" $known >&2; exit 2; }
for a in "$@"; do
    echo "$known" | grep -qx -- "$a" || { echo "select-addons: unknown add-on '$a' (known:" $known")" >&2; exit 2; }
done

# A section starts at the "#####" line just above "#  ADD-ON: ... [name]" and
# ends where the next one starts. Lines before the first section are kept.
BAR=$(printf '%71s' '' | tr ' ' '#')
awk -v want=" $* " -v bar="$BAR" '
    $0 == bar { held = $0; next }
    {
        if (held != "") {
            if ($0 ~ /^#  ADD-ON: .*\[[a-z-]+\]$/) {
                name = $0; sub(/.*\[/, "", name); sub(/\]$/, "", name)
                started = 1; keep = (index(want, " " name " ") > 0)
            }
            if (!started || keep) print held
            held = ""
        }
        if (!started || keep) print
    }
' "$F"
