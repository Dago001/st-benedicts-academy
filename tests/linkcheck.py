#!/usr/bin/env python3
"""Static check: relative .php links/redirects/forms in templates must point at existing files."""
import re, glob, os, sys
bad = 0
for f in sorted(glob.glob('**/*.php', recursive=True)):
    if f.startswith(('tests/', 'sql/')): continue
    d = os.path.dirname(f) or '.'
    src = open(f, encoding='utf8').read()
    for m in re.finditer(r'''(?:href|action|src)\s*=\s*["']([^"'<>$#]*?\.php)(?:[?#][^"']*)?["']|location\.href\s*=\s*[`"']([^"'`<>$]*?\.php)''', src):
        t = m.group(1) or m.group(2)
        if t.startswith(('http', '//', 'mailto', 'tel')): continue
        path = os.path.normpath(os.path.join(d, t))
        if not os.path.exists(path):
            print('%s:%d -> %s (missing)' % (f, src.count('\n', 0, m.start()) + 1, t)); bad += 1
    for m in re.finditer(r'BASE_URL\s*;\s*\?>\s*/([A-Za-z0-9_/\-\.]+\.php)', src):
        if not os.path.exists(m.group(1)):
            print('%s:%d -> BASE_URL/%s (missing)' % (f, src.count('\n', 0, m.start()) + 1, m.group(1))); bad += 1
print(bad, 'broken links', file=sys.stderr)
sys.exit(1 if bad else 0)
