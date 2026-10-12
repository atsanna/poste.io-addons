#!/usr/bin/env python3
"""A translation of the documentation, held against its English source.

    python3 docs/translation.py --ids cs     # gives every heading the anchor of the English one
    python3 docs/translation.py --check cs   # what docs/build.sh runs for every language

A translated page keeps the anchors of the English page ({#id} on each
heading), so every link, from any page and any language, keeps working.
The check does not judge the language; it finds what a translation can lose
without anyone noticing: a heading, a code block changed, a link or an image.
For every page of docs/<lang>/docs, against docs/en/docs:
  - the same headings, same levels, same order, each with the English anchor;
  - the code blocks identical, byte for byte;
  - the same link and image targets, in the same order.

Needs the Markdown package (the one MkDocs uses: the anchors are the ones
MkDocs makes).
"""
import re
import sys
from pathlib import Path

import markdown

DOCS = Path(__file__).resolve().parent
FENCE = re.compile(r"^(\s*)(```|~~~)")
HEADING = re.compile(r"^(#{1,6})\s+(.*?)\s*$")
ID = re.compile(r"\s*\{#([^}\s]+)\}\s*$")
TARGET = re.compile(r"!?\[(?:[^\]\\]|\\.)*\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)")


def blocks(text: str):
    """(headings [(line number, level, text)], code blocks [str], prose lines [str])."""
    heads, codes, prose = [], [], []
    lines = text.split("\n")
    i = 0
    while i < len(lines):
        m = FENCE.match(lines[i])
        if m:
            j = i + 1
            while j < len(lines) and not lines[j].lstrip().startswith(m.group(2)):
                j += 1
            codes.append("\n".join(lines[i:j + 1]))
            i = j + 1
            continue
        h = HEADING.match(lines[i])
        if h:
            heads.append((i, len(h.group(1)), h.group(2)))
        prose.append(lines[i])
        i += 1
    return heads, codes, prose


def english_ids(text: str) -> list:
    """The anchors MkDocs gives the headings of an English page, in order."""
    md = markdown.Markdown(extensions=["toc", "attr_list", "md_in_html", "tables", "admonition",
                                       "pymdownx.superfences"])
    md.convert(text)
    out = []

    def walk(tokens):
        for t in tokens:
            out.append((t["level"], t["id"]))
            walk(t["children"])
    walk(md.toc_tokens)
    return out


def targets(prose: list) -> list:
    # inline code may hold brackets that are not links
    return TARGET.findall(re.sub(r"`[^`]*`", "", "\n".join(prose)))


def page(lang: str, name: str, fix: bool) -> list:
    src = (DOCS / "en" / "docs" / name).read_text(encoding="utf-8")
    path = DOCS / lang / "docs" / name
    tr = path.read_text(encoding="utf-8")
    eh, ec, ep = blocks(src)
    th, tc, tp = blocks(tr)
    ids = english_ids(src)
    errors = []
    if len(ids) != len(eh):
        return [f"{name}: the English page has {len(eh)} headings, MkDocs sees {len(ids)}"]
    if [lv for _, lv, _ in th] != [lv for _, lv, _ in eh]:
        return [f"{name}: headings differ from English (levels {[lv for _, lv, _ in eh]} vs {[lv for _, lv, _ in th]})"]
    if fix:
        lines = tr.split("\n")
        for (n, lv, text), (_, want) in zip(th, ids):
            lines[n] = "#" * lv + " " + ID.sub("", text) + " {#" + want + "}"
        tr = "\n".join(lines)
        path.write_text(tr, encoding="utf-8")
        th, tc, tp = blocks(tr)
    for (n, lv, text), (_, want) in zip(th, ids):
        m = ID.search(text)
        if not m or m.group(1) != want:
            errors.append(f"{name}:{n + 1}: heading without the English anchor {{#{want}}}")
    if tc != ec:
        diff = [i for i, (a, b) in enumerate(zip(ec, tc)) if a != b]
        errors.append(f"{name}: code blocks differ from English ({len(ec)} vs {len(tc)}; first different: "
                      f"{diff[0] + 1 if diff else min(len(ec), len(tc)) + 1})")
    et, tt = targets(ep), targets(tp)
    if et != tt:
        missing = [t for t in et if t not in tt]
        extra = [t for t in tt if t not in et]
        errors.append(f"{name}: link/image targets differ from English (missing {missing[:3]}, extra {extra[:3]}"
                      + (", order" if not missing and not extra else "") + ")")
    return errors


def main() -> int:
    args = sys.argv[1:]
    if len(args) != 2 or args[0] not in ("--ids", "--check") or args[1] == "en":
        print(__doc__)
        return 2
    fix, lang = args[0] == "--ids", args[1]
    names = sorted(p.relative_to(DOCS / "en" / "docs").as_posix() for p in (DOCS / "en" / "docs").rglob("*.md"))
    errors = []
    for name in names:
        if not (DOCS / lang / "docs" / name).is_file():
            errors.append(f"{name}: missing in {lang}")
            continue
        errors += page(lang, name, fix)
    for e in errors:
        print(f"x {lang}/{e}")
    if not errors:
        print(f"{lang}: {len(names)} pages, the structure of English" + (" (anchors set)" if fix else ""))
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
