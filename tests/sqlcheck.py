#!/usr/bin/env python3
"""Static check: tables/columns referenced in PHP SQL strings must exist in the live schema."""
import re, subprocess, sys, glob, os
db = os.environ.get('DB', 'st_benedicts_academy')
out = subprocess.check_output(['mysql','-uroot',db,'-N','-e',
  "select table_name,column_name from information_schema.columns where table_schema='%s'" % db]).decode()
schema = {}
for l in out.splitlines():
    t,c = l.split('\t'); schema.setdefault(t,set()).add(c)
files = [f for f in glob.glob('**/*.php', recursive=True) if not f.startswith(('tests/','sql/'))]
strre = re.compile(r'"((?:[^"\\]|\\.)*)"|\'((?:[^\'\\]|\\.)*)\'', re.S)
problems = set()
for f in files:
    src = open(f, encoding='utf8').read()
    for m in strre.finditer(src):
        s = m.group(1) or m.group(2) or ''
        if not re.match(r'\s*(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b', s, re.I): continue
        line = src.count('\n', 0, m.start()) + 1
        # aliases
        alias = {}
        for t, a in re.findall(r'\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-z_]+)`?(?:\s+(?:AS\s+)?([a-z_]+))?', s, re.I):
            if a and a.upper() in ('SET','WHERE','ON','LEFT','JOIN','INNER','GROUP','ORDER','VALUES','AS','USING','LIMIT','RIGHT','CROSS'): a = ''
            alias[t] = t
            if a: alias[a] = t
        tables = set(alias.values())
        for t in tables:
            if t not in schema and not t.startswith('information'):
                problems.add((f, line, 'missing table %s' % t))
        for a, c in re.findall(r'\b([a-z_]+)\.([a-z_]+)\b', s):
            if a in alias and alias[a] in schema and c not in schema[alias[a]]:
                problems.add((f, line, 'missing column %s.%s' % (alias[a], c)))
        # INSERT column lists
        mi = re.match(r'\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([a-z_]+)`?\s*\(([^)]*)\)', s, re.I)
        if mi and mi.group(1) in schema:
            for c in [x.strip(' `\n\t') for x in mi.group(2).split(',')]:
                if c and c not in schema[mi.group(1)]:
                    problems.add((f, line, 'missing column %s.%s (insert)' % (mi.group(1), c)))
        mu = re.match(r'\s*UPDATE\s+`?([a-z_]+)`?\s+(?:[a-z_]+\s+)?SET\s+(.*?)(?:\bWHERE\b|$)', s, re.I|re.S)
        if mu and mu.group(1) in schema:
            for c in re.findall(r'(?:^|,)\s*(?:[a-z_]+\.)?`?([a-z_]+)`?\s*=', mu.group(2)):
                if c not in schema[mu.group(1)]:
                    problems.add((f, line, 'missing column %s.%s (update)' % (mu.group(1), c)))
        # unqualified columns in single-table statements
        if len(tables) == 1:
            t = next(iter(tables))
            if t in schema:
                body = re.sub(r"'[^']*'", "''", s)
                for c in re.findall(r'\b(?:WHERE|AND|OR|ORDER BY|GROUP BY|SET|,)\s+`?([a-z_]+)`?\s*(?:=|<|>|!=|IS\b|LIKE\b|IN\b|BETWEEN\b)', body, re.I):
                    if c.lower() not in ('and','or','not','null','is') and c not in schema[t] and not c.isupper():
                        problems.add((f, line, 'maybe missing column %s.%s' % (t, c)))
for p in sorted(problems): print('%s:%d %s' % p)
print(len(problems), 'problems', file=sys.stderr)
sys.exit(1 if problems else 0)
