#!/usr/bin/env python3
"""
One-shot pass:
  1. Add data-ig="<handle>" to any <a href="https://instagram.com/<handle>"> that lacks it.
  2. Insert <script src="/assets/js/ig-link.js" defer></script> before </body>
     on public-site HTML files that already have a </body> and don't already load it.

Run from repo root: python3 scripts/wire-ig-links.py
"""
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
TARGETS = [
    "index.html",
    "cosplay-signup/index.html",
    "elven-grove/index.html",
    "hatsune-miku-after-party/index.html",  # already wired manually
    "links/index.html",
    "partners/index.html",
    "past-events/index.html",
    "privacy-policy/index.html",
    "sponsors/index.html",
    "submit/index.html",
    "terms/index.html",
    "thank-you-collab.html",
    "thank-you-partner.html",
    "thank-you.html",
    "work-with-us/index.html",
]

# Match <a ... href="https://instagram.com/<handle>" ...>
# capture the full anchor opening tag so we can inspect / mutate it.
ANCHOR_RE = re.compile(
    r'(<a\b[^>]*?\bhref\s*=\s*["\']https?://(?:www\.)?instagram\.com/([A-Za-z0-9_.]+)/?["\'][^>]*?>)',
    re.IGNORECASE
)

SCRIPT_TAG = '<script src="/assets/js/ig-link.js" defer></script>'

changed_files = []

for rel in TARGETS:
    f = REPO / rel
    if not f.exists():
        print(f"  skip (missing): {rel}")
        continue
    src = f.read_text(encoding="utf-8")
    orig = src

    # 1. Add data-ig to anchors that don't have one
    def add_data_ig(m):
        tag = m.group(1)
        handle = m.group(2)
        if 'data-ig' in tag.lower():
            return tag
        # Insert data-ig="<handle>" right after the opening <a
        return re.sub(r'<a\b', f'<a data-ig="{handle}"', tag, count=1, flags=re.IGNORECASE)

    src = ANCHOR_RE.sub(add_data_ig, src)

    # 2. Ensure ig-link.js is loaded before </body>
    if SCRIPT_TAG not in src and 'ig-link.js' not in src and '</body>' in src.lower():
        # Insert before the LAST </body> (case insensitive).
        idx = src.lower().rfind('</body>')
        src = src[:idx] + SCRIPT_TAG + "\n" + src[idx:]

    if src != orig:
        f.write_text(src, encoding="utf-8")
        changed_files.append(rel)
        print(f"  updated: {rel}")
    else:
        print(f"  ok (no change): {rel}")

print(f"\n{len(changed_files)} files updated.")
