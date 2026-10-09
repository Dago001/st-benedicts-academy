#!/usr/bin/env python3
"""Flag `<?php echo $x ?>` outputs that are not escaped / cast (potential XSS)."""
import re, glob, sys
SAFE = re.compile(r'^\s*(htmlspecialchars|e|e_|json_encode|number_format|formatCurrency|formatDate|formatTime|timeAgo|date|count|intval|round|floor|ceil|abs|strtoupper\(substr|ucfirst\(e|ucfirst\(htmlspecialchars|ucwords\(htmlspecialchars|nl2br\(htmlspecialchars|nl2br\(e|\(int\)|\(float\)|BASE_URL|SITE_NAME|SCHOOL_|csrf_field|Security::generateCSRFToken|PHP_|str_pad|min|max|array_sum|ucfirst\(str_replace|\$(i|index|key|page|totalPages|count|total|current|n)\b|\$[a-zA-Z_]*(Id|_id|count|Count|total|Total|percentage|Percentage|percent|rate|Rate)\b)')
bad = 0
for f in sorted(glob.glob('**/*.php', recursive=True)):
    if f.startswith(('tests/','sql/','config/')): continue
    src = open(f, encoding='utf8').read()
    for m in re.finditer(r'<\?(?:php\s+)?echo\s+(.+?)\s*;?\s*\?>', src, re.S):
        expr = m.group(1).strip()
        if SAFE.match(expr) or re.fullmatch(r"['\"][^$]*['\"]", expr): continue
        if '$' not in expr: continue
        line = src.count('\n', 0, m.start()) + 1
        print('%s:%d: %s' % (f, line, expr[:110].replace('\n',' ')))
        bad += 1
print(bad, 'unescaped outputs', file=sys.stderr)
