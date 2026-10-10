#!/bin/sh
# Dark theme for the Poste.io admin (add-on).
#
# Hooks our two templates into Poste.io's base.html.twig and appends the en/cs
# translations. Used both by the Dockerfile and for a manual install, so what
# is tested on a running server is exactly what runs in the build.
#
#   sh dark-theme-install.sh <admin dir> <dir containing dark-theme.{en,cs}.yml>
#
# It stops (exit 1) if Poste.io changes the hook points: a failed build is
# better than a dark theme that silently does not load.
set -e
ADMIN=${1:-/opt/admin}
YML=${2:-/tmp}
B="$ADMIN/templates/base.html.twig"
HEAD_INC="{% include 'Base/DarkTheme/_head.html.twig' %}"
TOGGLE_INC="{% include 'Base/DarkTheme/_toggle.html.twig' %}"
NAVBAR_RIGHT='<ul class="nav navbar-nav navbar-right">'

if grep -qF "$HEAD_INC" "$B"; then
    echo "dark-theme: STOP, already installed in $B"; exit 1
fi
if [ "$(grep -c '^</head>$' "$B")" != "1" ]; then
    echo "dark-theme: STOP, '</head>' does not appear exactly once (alone on a line) in $B"; exit 1
fi
if [ "$(grep -cF "$NAVBAR_RIGHT" "$B")" != "1" ]; then
    echo "dark-theme: STOP, the right navbar list does not appear exactly once in $B"; exit 1
fi
for l in en cs; do
    if grep -q '^theme:' "$ADMIN/translations/Base/messages+intl-icu.$l.yml"; then
        echo "dark-theme: STOP, the 'theme:' key already exists in the $l translations"; exit 1
    fi
    [ -f "$YML/dark-theme.$l.yml" ] || { echo "dark-theme: STOP, missing $YML/dark-theme.$l.yml"; exit 1; }
done

# theme stylesheet and script: right before </head>
sed -i "s|^</head>\$|    $HEAD_INC\n</head>|" "$B"
# theme item: first item of the right navbar list (next to Webmail and Logout)
sed -i "s|$NAVBAR_RIGHT|&\n                $TOGGLE_INC|" "$B"

if [ "$(grep -cF "$HEAD_INC" "$B")" != "1" ] || [ "$(grep -cF "$TOGGLE_INC" "$B")" != "1" ]; then
    echo "dark-theme: STOP, the includes were not inserted exactly once in $B"; exit 1
fi

for l in en cs; do
    T="$ADMIN/translations/Base/messages+intl-icu.$l.yml"
    echo "" >> "$T"
    cat "$YML/dark-theme.$l.yml" >> "$T"
done
echo "dark-theme: installed"
