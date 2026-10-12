#!/bin/sh
# Builds the documentation site into site/: every language, HTML and PDF.
#
#   sh docs/build.sh            # all the languages
#   sh docs/build.sh en it      # only these (a quick look; the checks still run)
#
# Needs MkDocs with the Material theme and the mkpdfs plugin (versions in
# .github/workflows/docs.yml, which runs this script). Every build is strict:
# a broken link or anchor, or a missing PDF, stops it.
set -eu
cd "$(dirname "$0")/.."

ALL=$(python3 docs/languages.py --list)
LANGS=${*:-$ALL}

# 1. the configuration of each language is the one the table writes
python3 docs/languages.py --check

# 2. every language has the same pages as English: a page missing in one
#    language is a page someone reads in one language and not in another
SRC=$(cd docs/en/docs && find . -name '*.md' | sort)
for L in $ALL; do
    [ "$L" = en ] && continue
    if [ ! -d "docs/$L/docs" ]; then
        echo "x docs/$L/docs is missing"; exit 1
    fi
    HAVE=$(cd "docs/$L/docs" && find . -name '*.md' | sort)
    if [ "$HAVE" != "$SRC" ]; then
        echo "x docs/$L/docs has not the same pages as docs/en/docs:"
        printf '%s\n' "$SRC" > /tmp/docs-src.$$; printf '%s\n' "$HAVE" > /tmp/docs-have.$$
        diff /tmp/docs-src.$$ /tmp/docs-have.$$ || true
        rm -f /tmp/docs-src.$$ /tmp/docs-have.$$
        exit 1
    fi
done
echo "the same $(printf '%s\n' "$SRC" | wc -l) pages in every language"

# and each translation keeps the structure of English: headings with the
# English anchors, code blocks, links and images (docs/translation.py)
for L in $ALL; do
    [ "$L" = en ] || python3 docs/translation.py --check "$L"
done

# 3. images and style sheets are kept once, in English, and copied
for L in $ALL; do
    [ "$L" = en ] && continue
    rm -rf "docs/$L/docs/assets"
    cp -R docs/en/docs/assets "docs/$L/docs/assets"
done

# 4. the PDF design for right-to-left languages: report.css plus its delta
cat docs/design/report.css docs/design/report-rtl-delta.css > docs/design/report-rtl.css

# 5. the sites
rm -rf site
mkdir -p site
for L in $LANGS; do
    (cd "docs/$L" && mkdocs build --strict --quiet -d "../../site/$L")
    PDF="site/$L/pdf/poste.io-addons-$L.pdf"
    [ -s "$PDF" ] || { echo "x $L: no PDF ($PDF)"; exit 1; }
    echo "OK  $L  $(du -h "$PDF" | cut -f1) PDF"
done

# 6. the root of the site: the reader's language if there is one, else English
{
    echo '<!doctype html><html><head><meta charset="utf-8"><title>poste.io-addons</title>'
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">'
    echo '<script>(function () { var have = "'"$ALL"'".split(" ");'
    echo '  var want = (navigator.languages || [navigator.language || "en"]).map(function (l) { return l.slice(0, 2).toLowerCase(); });'
    echo '  var go = want.filter(function (l) { return have.indexOf(l) >= 0; })[0] || "en";'
    echo '  location.replace(go + "/"); })();</script>'
    echo '<noscript><meta http-equiv="refresh" content="0; url=en/"></noscript></head><body>'
    echo '<p>poste.io-addons:'
    for L in $ALL; do echo " <a href=\"$L/\">$L</a>"; done
    echo '</p></body></html>'
} > site/index.html
touch site/.nojekyll
echo "site/ built: $(echo $LANGS | wc -w) language(s)"
