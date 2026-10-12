#!/usr/bin/env python3
"""Writes docs/<lang>/mkdocs.yml for every language of the documentation.

The sites of the languages differ only in what this table says (name,
description, labels of the theme switch, titles of the pages, direction);
everything else is the same by construction, so it is written here once.

    python3 docs/languages.py          # writes the files
    python3 docs/languages.py --check  # fails if a file is not what it would write
    python3 docs/languages.py --list   # the language codes, English first

The English pages (docs/en/docs/) are the source; the other languages are
their translations and must have the same files (docs/build.sh checks it).
"""
import sys
from pathlib import Path

SITE = "https://atsanna.github.io/poste.io-addons"
DOCS = Path(__file__).resolve().parent

# the pages, in reading order: the file, then its title in each language
PAGES = [
    ("index.md", {"en": "Home", "cs": "Úvod"}),
    ("installation.md", {"en": "Installation", "cs": "Instalace"}),
    ("choosing-addons.md", {"en": "Choosing the add-ons", "cs": "Výběr doplňků"}),
    ("external-mailboxes.md", {"en": "External Mailboxes", "cs": "Externí schránky"}),
    ("dark-theme.md", {"en": "Dark theme", "cs": "Tmavý motiv"}),
    ("activesync-dav.md", {"en": "Calendars and contacts over ActiveSync", "cs": "Kalendáře a kontakty přes ActiveSync"}),
    ("activesync-logs.md", {"en": "ActiveSync logs", "cs": "Logy ActiveSync"}),
    ("backup.md", {"en": "Backup and restore", "cs": "Zálohování a obnova"}),
    ("upstream-fixes.md", {"en": "Upstream fixes", "cs": "Opravy upstreamu"}),
    ("testing.md", {"en": "Tests", "cs": "Testy"}),
]

# A new language: a line here (with "rtl": True for a language read from right
# to left: the site and the PDF follow, see docs/design/report-rtl-delta.css),
# its titles in PAGES, then docs/<lang>/docs/ with the same pages as English.
LANGUAGES = {
    "en": {"name": "English", "description": "Add-ons for the Poste.io mail server",
           "dark": "Switch to dark mode", "light": "Switch to light mode"},
    "cs": {"name": "Čeština", "description": "Doplňky pro poštovní server Poste.io",
           "dark": "Přepnout na tmavý režim", "light": "Přepnout na světlý režim"},
}

# the languages the search of the theme can stem (lunr in Material 9.6.9: no Czech);
# the others search as English, which finds the words as written
SEARCH = {"en", "it", "de", "fr", "es", "ar"}


def quote(s: str) -> str:
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"') + '"'


def mkdocs_yml(lang: str) -> str:
    L = LANGUAGES[lang]
    alternate = "".join(
        f"    - name: {quote(o['name'])}\n      link: {SITE}/{code}/\n      lang: {code}\n"
        for code, o in LANGUAGES.items())
    nav = "".join(f"  - {quote(t[lang])}: {f}\n" for f, t in PAGES)
    design = "report-rtl.css" if L.get("rtl") else "report.css"
    return f"""# Documentation of poste.io-addons ({L['name']}).
# Written by docs/languages.py: change the table there, not this file.

site_name: poste.io-addons
site_description: {quote(L['description'])}
site_url: {SITE}/{lang}/
site_dir: ../../site/{lang}

theme:
  name: material
  language: {lang}
{"  direction: rtl" + chr(10) if L.get("rtl") else ""}  features:
    - navigation.instant
    - navigation.top
    - navigation.footer
    - content.code.copy
    - search.suggest
    - search.highlight
  palette:
    - media: "(prefers-color-scheme: light)"
      scheme: addons
      primary: custom
      accent: custom
      toggle:
        icon: material/brightness-7
        name: {quote(L['dark'])}
    - media: "(prefers-color-scheme: dark)"
      scheme: slate
      primary: custom
      accent: custom
      toggle:
        icon: material/brightness-4
        name: {quote(L['light'])}

copyright: Copyright © 2026 Antonio Sanna

extra_css:
  - assets/css/theme.css
  - assets/css/theme_dark_mode.css

extra:
  generator: false
  # absolute links: a relative one would change meaning from page to page
  alternate:
{alternate}
markdown_extensions:
  - admonition
  - attr_list
  - md_in_html
  - tables
  - toc
  - pymdownx.details
  - pymdownx.inlinehilite
  - pymdownx.highlight:
      anchor_linenums: true
      use_pygments: true
  - pymdownx.tabbed:
      alternate_style: true
  - pymdownx.superfences

validation:
  links:
    not_found: warn
    anchors: warn
    unrecognized_links: warn

plugins:
  - search:
      lang:
        - {lang if lang in SEARCH else "en"}
  - mkpdfs:
      company: poste.io-addons
      author: Antonio Sanna
      toc_title: poste.io-addons
      output_path: pdf/poste.io-addons-{lang}.pdf
      design: ../design/{design}

nav:
{nav}"""


def main() -> int:
    if "--list" in sys.argv[1:]:
        print(" ".join(LANGUAGES))
        return 0
    check = "--check" in sys.argv[1:]
    bad = []
    for lang in LANGUAGES:
        path = DOCS / lang / "mkdocs.yml"
        text = mkdocs_yml(lang)
        if check:
            if not path.is_file() or path.read_text(encoding="utf-8") != text:
                bad.append(str(path.relative_to(DOCS.parent)))
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8")
            print("written", path.relative_to(DOCS.parent))
    if bad:
        print("not what docs/languages.py writes (run it and commit):", ", ".join(bad))
        return 1
    if check:
        print(f"mkdocs.yml of {len(LANGUAGES)} languages: as written by docs/languages.py")
    return 0


if __name__ == "__main__":
    sys.exit(main())
