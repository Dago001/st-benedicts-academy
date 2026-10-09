#!/bin/bash
# Smoke-test every page as every role against a local php -S server.
# usage: tests/crawl.sh [base_url]   (server must be running)
BASE=${1:-http://127.0.0.1:8080}
cd "$(dirname "$0")/.."
PW='Test@12345'
declare -A USERS=([admin]=admin@stbenedicts.edu.ng [teacher]=teacher@test.com [student]=student@test.com [parent]=parent@test.com)
fail=0
check() { # role path
  local jar=$1 path=$2 out code
  out=$(curl -s -b /tmp/jar_$jar -c /tmp/jar_$jar -o /tmp/body.html -w '%{http_code}' "$BASE/$path")
  if [ "$out" != "200" ] && [ "$out" != "302" ]; then echo "HTTP $out  [$jar] $path"; fail=1; fi
  if grep -qE "(Fatal error|Parse error|Warning|Notice|Deprecated): |Uncaught|Stack trace" /tmp/body.html; then
     echo "PHPERR [$jar] $path: $(grep -E "(Fatal error|Parse error|Warning|Notice|Deprecated): |Uncaught" /tmp/body.html | head -2 | sed 's/<[^>]*>//g' | tr '\n' ' ' | cut -c1-220)"; fail=1; fi
}
login() {
  local role=$1
  rm -f /tmp/jar_$role
  tok=$(curl -s -c /tmp/jar_$role "$BASE/login.php" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
  curl -s -b /tmp/jar_$role -c /tmp/jar_$role -o /dev/null -w "login $role -> %{http_code} %{redirect_url}\n" \
     --data-urlencode "csrf_token=$tok" --data-urlencode "email=${USERS[$role]}" --data-urlencode "password=$PW" "$BASE/login.php"
}
for p in index.php login.php public/about.php public/academics.php public/admissions.php public/apply.php public/contact.php public/gallery.php public/news.php; do check anon $p; done
for role in admin teacher student parent; do
  login $role
  for f in $role/*.php; do
    case $f in */print-receipt.php|*/view-receipt.php|*/download-report.php|admin/export.php|*/export.php|admin/export-logs.php) continue;; esac
    check $role $f
  done
done
# cross-role access must be denied (no 200 page content for wrong role)
for f in admin/dashboard.php admin/students.php teacher/dashboard.php; do
  code=$(curl -s -b /tmp/jar_student -o /tmp/body.html -w '%{http_code}' "$BASE/$f")
  if [ "$code" = "200" ] && ! grep -qi "denied\|login" /tmp/body.html; then echo "ACCESS LEAK student -> $f"; fail=1; fi
done
[ $fail = 0 ] && echo "CRAWL OK" || echo "CRAWL FAILED"
exit $fail
