#!/usr/bin/env python3
"""
install_stape_loader.py — Idempotent injector.

Adds the Stape sGTM loader + meta tags into every HTML page that has
GA4 (G-8W7W5FKYV9). Insertion happens immediately after the </script>
that closes the GA4 init block so the override fires after the initial
gtag('config') call.

Run from repo root:
    python3 scripts/install_stape_loader.py
"""
from __future__ import annotations
import re, sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
MARKER = '<!-- Moonlight Stape sGTM loader -->'
# Match both spaced and unspaced variants:
#   gtag('config', 'G-8W7W5FKYV9')   (old pages)
#   gtag('config','G-8W7W5FKYV9')    (newer PR #19 pages)
GA4_BLOCK_RE = re.compile(
    r"(gtag\('config',\s*'G-8W7W5FKYV9'\);[^<]*</script>)",
    re.DOTALL,
)
INJECT = (
    "\\1\n"
    "    " + MARKER + "\n"
    "    <meta name=\"moonlight:sgtm-url\" content=\"\">\n"
    "    <meta name=\"moonlight:ga4-id\"   content=\"G-8W7W5FKYV9\">\n"
    "    <script defer src=\"/assets/tracking-stape.js\"></script>"
)

def main() -> int:
    touched = 0
    skipped_already = 0
    skipped_no_ga4 = 0
    for path in REPO.rglob("*.html"):
        if any(p.startswith('.') for p in path.parts):
            continue
        text = path.read_text(encoding="utf-8")
        if MARKER in text:
            skipped_already += 1
            continue
        if "G-8W7W5FKYV9" not in text:
            skipped_no_ga4 += 1
            continue
        new = GA4_BLOCK_RE.sub(INJECT, text, count=1)
        if new == text:
            print(f"!  could not locate GA4 block in: {path.relative_to(REPO)}")
            continue
        path.write_text(new, encoding="utf-8")
        touched += 1
        print(f"+  injected loader into: {path.relative_to(REPO)}")

    print()
    print(f"installed in {touched} files")
    print(f"already had loader: {skipped_already}")
    print(f"no GA4 tag found:   {skipped_no_ga4}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
