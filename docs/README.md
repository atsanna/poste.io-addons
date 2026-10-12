# The documentation

Published at <https://atsanna.github.io/poste.io-addons/>, in HTML and PDF,
by the [docs workflow](../.github/workflows/docs.yml) (from `main` only).

```
docs/
  en/mkdocs.yml, en/docs/   English: the source. Images and style sheets live
                            here only (en/docs/assets/)
  cs/mkdocs.yml, cs/docs/   Czech: the same pages, translated
  design/                   the design of the PDF (see design/README.md)
  languages.py              writes the mkdocs.yml of every language from one table
  translation.py            gives a translation the anchors of English, and checks it
  build.sh                  builds the whole site into site/
  requirements.txt          the tools, pinned
```

## Building it

```sh
python3 -m venv .venv && . .venv/bin/activate
pip install -r docs/requirements.txt     # and Pango for the PDF (libpango-1.0-0)
sh docs/build.sh                         # site/en, site/cs, each with pdf/
```

`build.sh` is strict. It stops when:

- a `mkdocs.yml` is not what `languages.py` writes;
- a language has not the same pages as English;
- a translation lost a heading, changed a code block, a link or an image, or
  a heading lacks the anchor of the English one (`translation.py --check`);
- a link or an anchor is broken, or a PDF is missing.

## Changing a page

Change the English page, then the same page of every other language. The
code blocks, links and images must stay identical; headings keep the English
anchor written after them, `## Titulek {#english-anchor}`. After adding or
renaming headings in a translation, `python3 docs/translation.py --ids cs`
sets the anchors from English again.

## Adding a language

A line in `LANGUAGES` and its page titles in `PAGES` (`languages.py`), then
`python3 docs/languages.py`, then `docs/<lang>/docs/` with every page
translated, and `python3 docs/translation.py --ids <lang>`. A language read
from right to left gets `"rtl": True`: the site turns, and the PDF uses
`design/report-rtl-delta.css`.
