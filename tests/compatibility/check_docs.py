#!/usr/bin/env python3
"""Check relative Markdown and HTML links in current entrypoint documentation."""
from pathlib import Path
import html
import re
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[2]
FILES = ['README.md', 'docs/README.md', 'docs/installation.md', 'docs/compatibility.md', 'docs/development.md', 'tests/ci/README.md', 'tests/compatibility/README.md']
missing = []
for name in FILES:
    path = ROOT / name
    text = path.read_text()
    targets = re.findall(r'\]\(([^)]+)\)', text) + re.findall(r'(?:href|src)="([^"]+)"', text)
    for target in targets:
        target = html.unescape(target).strip('<>')
        url = urlsplit(target)
        if url.scheme or url.netloc or not url.path:
            continue
        if not (path.parent / unquote(url.path)).exists():
            missing.append((name, target))
if missing:
    raise SystemExit(str(missing))
print(f'{len(FILES)} documentation entrypoints: 0 missing relative links')
