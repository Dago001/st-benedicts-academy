#!/usr/bin/env python3
"""Static check: internal links (clean URLs, no .php) must resolve to an existing page."""
import re, glob, os, sys
bad = 0
def exists(path):
    p = path.rstrip('/')
    return os.path.exists(p + '.php') or os.path.isfile(p) or os.path.isfile(os.path.join(p, 'index.php')) or p in ('', '.')
for f in sorted(glob.glob('**/*.php', recursive=True)):
    if f.startswith(('tests/', 'sql/')): continue
    d = os.path.dirname(f) or '.'
    src = open(f, encoding='utf8').read()
    for m in re.finditer(r'''(?:href|action)=["']([A-Za-z0-9_\-/]+)(?:[?#][^"']*)?["']''', src):
        t = m.group(1)
        if t in ('#',) or t.startswith(('http', '//', 'mailto', 'tel')): continue
        if not exists(os.path.normpath(os.path.join(d, t))):
            print('%s:%d -> %s (missing)' % (f, src.count('\n', 0, m.start()) + 1, t)); bad += 1
    for m in re.finditer(r'BASE_URL\s*;\s*\?>\s*/([A-Za-z0-9_/\-]+)(?=[?"\'])', src):
        if not exists(m.group(1)):
            print('%s:%d -> BASE_URL/%s (missing)' % (f, src.count('\n', 0, m.start()) + 1, m.group(1))); bad += 1
print(bad, 'broken links', file=sys.stderr)
sys.exit(1 if bad else 0)
