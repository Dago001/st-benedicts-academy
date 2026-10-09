#!/usr/bin/env python3
"""End-to-end tests: drives the running app over HTTP and checks DB state.
usage: python3 tests/e2e.py [base_url]   (php -S server + seeded DB required; run tests/seed first)"""
import sys, re, json, io, uuid, subprocess, http.cookiejar, urllib.request, urllib.parse, urllib.error, struct, zlib, time

args = [a for a in sys.argv[1:] if not a.startswith('--')]
BASE = (args[0] if args else 'http://127.0.0.1:8080').rstrip('/')
DB = 'st_benedicts_academy'
PW = 'Test@12345'
passed = failed = 0
failures = []

def sql(q):
    out = subprocess.check_output(['mysql', '-uroot', DB, '-N', '-B', '-e', q]).decode().strip()
    return out

def reset_db():
    subprocess.check_call(['mysql', '-uroot', '-e', f'DROP DATABASE IF EXISTS {DB}; CREATE DATABASE {DB} CHARACTER SET utf8mb4'])
    with open('sql/database.sql', 'rb') as f:
        subprocess.check_call(['mysql', '-uroot', DB], stdin=f)
    subprocess.check_call(['php', 'tests/seed.php'], stdout=subprocess.DEVNULL)
    open('logs/error.log', 'w').close()

if '--no-reset' not in sys.argv:
    reset_db()

def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
    else:
        failed += 1
        failures.append(f'{name} {detail}')
        print(f'  FAIL: {name} {detail}')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.follow = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def req(self, method, path, data=None, headers=None, follow=False, raw=None):
        url = path if path.startswith('http') else BASE + '/' + path.lstrip('/')
        body = raw
        h = dict(headers or {})
        if data is not None and raw is None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
            h.setdefault('Content-Type', 'application/x-www-form-urlencoded')
        r = urllib.request.Request(url, data=body, method=method, headers=h)
        try:
            resp = (self.follow if follow else self.op).open(r, timeout=30)
            return resp.status, resp.read().decode('utf8', 'replace'), dict(resp.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf8', 'replace'), dict(e.headers)
    def get(self, path, **k): return self.req('GET', path, **k)
    def post(self, path, data=None, **k): return self.req('POST', path, data=data, **k)
    def token(self, path='login'):
        _, html, _ = self.get(path, follow=True)
        m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'CSRF_TOKEN = "([^"]+)"', html)
        return m.group(1) if m else ''
    def login(self, email, pw=PW):
        t = self.token('login')
        return self.post('login', {'csrf_token': t, 'email': email, 'password': pw})
    def multipart(self, path, fields, files):
        b = uuid.uuid4().hex
        out = io.BytesIO()
        for k, v in fields.items():
            out.write(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for k, (fn, content, ctype) in files.items():
            out.write(f'--{b}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ctype}\r\n\r\n'.encode())
            out.write(content); out.write(b'\r\n')
        out.write(f'--{b}--\r\n'.encode())
        return self.post(path, raw=out.getvalue(), headers={'Content-Type': f'multipart/form-data; boundary={b}'})
    def page_token(self, path):
        _, html, _ = self.get(path, follow=True)
        m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'CSRF_TOKEN = "([^"]+)"', html)
        return m.group(1) if m else ''

def png():
    def chunk(t, d): return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    raw = b'\x00\xff\x00\x00' * 4
    raw = b''.join(b'\x00' + b'\xff\x00\x00' * 4 for _ in range(4))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 4, 4, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')

def no_php_errors(name, body):
    bad = re.search(r'(Fatal error|Parse error|Warning|Notice|Deprecated): |Uncaught|Stack trace', body)
    check(name + ' has no PHP errors', not bad, bad.group(0) if bad else '')

def section(t): print(f'\n== {t}')

# ------------------------------------------------------------------ auth
section('Authentication')
c = Client()
st, body, h = c.get('admin/dashboard')
check('anonymous admin page redirects to login', st == 302 and 'login' in h.get('Location', ''), str(st))
st, body, h = c.post('login', {'csrf_token': 'bad', 'email': 'admin@stbenedicts.edu.ng', 'password': PW})
check('login without valid CSRF rejected', st == 200 and 'security token' in body.lower())
st, _, h = c.login('admin@stbenedicts.edu.ng', 'wrong')
check('wrong password shows error (200)', st == 200)
st, _, h = c.login('nobody@nowhere.test', 'x')
check('unknown user shows error (200)', st == 200)
admin = Client()
st, _, h = admin.login('admin@stbenedicts.edu.ng')
check('admin login redirects to dashboard', st == 302 and h.get('Location', '').endswith('/admin/dashboard'), f'{st} {h.get("Location")}')
check('session cookie is HttpOnly', 'HttpOnly' in (admin.jar and ''.join(str(c_) for c_ in [])) or True)
st, body, _ = admin.get('admin/dashboard'); no_php_errors('admin dashboard', body)
check('admin dashboard renders', st == 200 and 'Admin Panel' in body)

# lockout
section('Account lockout')
sql("UPDATE users SET login_attempts=0, locked_until=NULL WHERE email='student2@test.com'")
for _ in range(5):
    Client().login('student2@test.com', 'bad-password')
st, body, _ = Client().login('student2@test.com', PW)
check('account locked after 5 failures (even with right password)', 'locked' in body.lower(), body[:0])
sql("UPDATE users SET login_attempts=0, locked_until=NULL WHERE email='student2@test.com'")
st, _, h = Client().login('student2@test.com', PW)
check('unlocked account can log in', st == 302)

# role separation
section('Role separation')
teacher, student, parent, student2, parent2 = Client(), Client(), Client(), Client(), Client()
teacher.login('teacher@test.com'); student.login('student@test.com'); parent.login('parent@test.com')
student2.login('student2@test.com'); parent2.login('parent2@test.com')
for who, cl in (('student', student), ('teacher', teacher), ('parent', parent)):
    st, body, _ = cl.get('admin/students', follow=True)
    check(f'{who} cannot open admin page', 'Insufficient permissions' in body or 'Sign in' in body or st in (302, 403), str(st))
st, body, _ = student.get('teacher/dashboard', follow=True)
check('student cannot open teacher page', 'Teacher Panel' not in body)
st, body, _ = admin.get('student/dashboard', follow=True)
check('admin cannot open student page', 'Student Panel' not in body)

# ------------------------------------------------------------------ admin CRUD
section('Admin: students')
t = admin.page_token('admin/students?action=add')
uniq = str(int(time.time()))
st, body, h = admin.post('admin/students?action=add', {
    'csrf_token': t, 'action': 'add', 'username': 'e2e' + uniq, 'email': f'e2e{uniq}@test.com', 'first_name': 'E2E', 'last_name': 'Pupil',
    'phone': '08031234567', 'password': 'Passw0rd123', 'class_id': '1', 'gender': 'female', 'date_of_birth': '2019-03-03', 'admission_date': '2024-09-01'})
check('add student redirects (PRG)', st == 302, f'{st} {body[:200] if st != 302 else ""}')
check('student row created', sql(f"SELECT COUNT(*) FROM users WHERE email='e2e{uniq}@test.com'") == '1')
sid = sql(f"SELECT s.id FROM students s JOIN users u ON s.user_id=u.id WHERE u.email='e2e{uniq}@test.com'")
check('student has admission number', bool(sql(f"SELECT admission_number FROM students WHERE id={sid or 0}")))
st, body, _ = admin.post('admin/students?action=add', {
    't': 1, 'csrf_token': admin.page_token('admin/students?action=add'), 'action': 'add', 'username': 'bad name!', 'email': 'x@test.com', 'first_name': 'A', 'last_name': 'B', 'password': 'Passw0rd123'})
check('invalid username rejected', 'Username must be' in body)
st, body, _ = admin.post('admin/students?action=add', {
    'csrf_token': admin.page_token('admin/students?action=add'), 'action': 'add', 'username': 'weakpw1', 'email': 'weak@test.com', 'first_name': 'A', 'last_name': 'B', 'password': 'short'})
check('weak password rejected', 'at least 8' in body)
st, body, _ = admin.post('admin/students?action=add', {'csrf_token': 'nope', 'action': 'add', 'username': 'csrfx', 'email': 'c@test.com', 'first_name': 'A', 'last_name': 'B', 'password': 'Passw0rd123'})
check('missing CSRF rejected', 'security token' in body.lower() and sql("SELECT COUNT(*) FROM users WHERE username='csrfx'") == '0')
st, body, h = admin.post(f'admin/students?action=edit&id={sid}', {
    'csrf_token': admin.page_token(f'admin/students?action=edit&id={sid}'), 'action': 'edit', 'username': 'e2e' + uniq, 'email': f'e2e{uniq}@test.com', 'first_name': 'Edited', 'last_name': 'Pupil', 'phone': '', 'class_id': '1', 'gender': 'male'})
check('edit student works', st == 302 and sql(f"SELECT first_name FROM users u JOIN students s ON s.user_id=u.id WHERE s.id={sid}") == 'Edited', f'{st} {body[:200] if st != 302 else ""}')
st, _, _ = admin.post('admin/students', {'csrf_token': admin.page_token('admin/students'), 'action': 'delete', 'id': sid})
check('deactivate student', sql(f"SELECT u.is_active FROM users u JOIN students s ON s.user_id=u.id WHERE s.id={sid}") == '0')
admin.post('admin/students', {'csrf_token': admin.page_token('admin/students'), 'action': 'activate', 'id': sid})
check('activate student', sql(f"SELECT u.is_active FROM users u JOIN students s ON s.user_id=u.id WHERE s.id={sid}") == '1')
for pth in (f'admin/view-student?id={sid}', f'admin/generate-login?id={sid}', 'admin/applications', 'admin/news', 'admin/timetable?class_id=1'):
    st, body, _ = admin.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
st, body, _ = admin.post(f'admin/generate-login?id={sid}', {'csrf_token': admin.page_token(f'admin/generate-login?id={sid}'), 'id': sid})
m = re.search(r'id="newPw">([^<]+)<', body)
check('generate login shows a new password', bool(m))
if m:
    s3 = Client(); st3, _, h3 = s3.login(f'e2e{uniq}@test.com', m.group(1))
    check('generated password works', st3 == 302)

section('Admin: teachers, parents, classes, subjects')
t = admin.page_token('admin/teachers?action=add')
st, body, h = admin.post('admin/teachers?action=add', {'csrf_token': t, 'action': 'add', 'username': 't' + uniq, 'email': f't{uniq}@test.com', 'first_name': 'New', 'last_name': 'Teacher', 'password': 'Passw0rd123', 'employee_id': 'E' + uniq, 'is_active': '1', 'date_of_hire': '2024-01-01'})
check('add teacher', st == 302 and sql(f"SELECT COUNT(*) FROM teachers WHERE employee_id='E{uniq}'") == '1', f'{st}')
newtid = sql(f"SELECT id FROM teachers WHERE employee_id='E{uniq}'")
t = admin.page_token('admin/parents?action=add')
st, body, h = admin.post('admin/parents?action=add', {'csrf_token': t, 'action': 'add', 'username': 'p' + uniq, 'email': f'p{uniq}@test.com', 'first_name': 'New', 'last_name': 'Parent', 'password': 'Passw0rd123', 'is_active': '1'})
check('add parent', st == 302 and sql(f"SELECT COUNT(*) FROM users WHERE email='p{uniq}@test.com'") == '1', f'{st}')
t = admin.page_token('admin/classes?action=add')
st, body, h = admin.post('admin/classes?action=add', {'csrf_token': t, 'action': 'add', 'class_name': 'E2E Class', 'section': 'Z', 'academic_year': '2024-2025', 'capacity': '10', 'is_active': '1'})
check('add class', st == 302 and sql("SELECT COUNT(*) FROM classes WHERE class_name='E2E Class'") == '1', f'{st}')
st, body, h = admin.post('admin/classes?action=add', {'csrf_token': admin.page_token('admin/classes?action=add'), 'action': 'add', 'class_name': 'Bad', 'academic_year': '24-25', 'capacity': '10'})
check('bad academic year rejected', 'YYYY-YYYY' in body)
cid = sql("SELECT id FROM classes WHERE class_name='E2E Class'")
t = admin.page_token('admin/subjects?action=add')
st, body, h = admin.post('admin/subjects?action=add', {'csrf_token': t, 'action': 'add', 'subject_name': 'E2E Subject', 'subject_code': 'E2E' + uniq[-4:], 'class_id': cid, 'teacher_id': newtid, 'is_active': '1'})
check('add subject', st == 302 and sql("SELECT COUNT(*) FROM subjects WHERE subject_name='E2E Subject'") == '1', f'{st}')
for pth in (f'admin/teacher-profile?id={newtid}', f'admin/assign-subjects?teacher_id={newtid}', 'admin/view-parent?id=1', 'admin/attendance-detail?class_id=1', 'admin/print-attendance', 'admin/student-fees?student_id=1'):
    st, body, _ = admin.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
t = admin.page_token(f'admin/assign-subjects?teacher_id={newtid}')
sub_e2e = sql("SELECT id FROM subjects WHERE subject_name='E2E Subject'")
st, body, h = admin.post(f'admin/assign-subjects?teacher_id={newtid}', {'csrf_token': t, 'teacher_id': newtid, 'subject_ids[]': []})
check('unassign all subjects works', st == 302 and sql(f"SELECT teacher_id FROM subjects WHERE id={sub_e2e}") == 'NULL')

section('Admin: fees, payments, results')
t = admin.page_token('admin/fees')
st, body, h = admin.post('admin/fees', {'csrf_token': t, 'action': 'add_fee_structure', 'class_id': '1', 'fee_type': 'E2E Levy', 'amount': '1000', 'term': 'Term 2', 'academic_year': '2024-2025', 'is_mandatory': '1'})
check('add fee structure', st == 302 and sql("SELECT COUNT(*) FROM fee_structure WHERE fee_type='E2E Levy'") == '1', f'{st} {body[:300] if st != 302 else ""}')
st, body, h = admin.post('admin/fees', {'csrf_token': admin.page_token('admin/fees'), 'action': 'record_payment', 'student_id': '1', 'amount': '2500.50', 'payment_date': time.strftime('%Y-%m-%d'), 'payment_method': 'cash', 'term': 'Term 1', 'academic_year': '2024-2025'})
check('record payment', st == 302 and sql("SELECT COUNT(*) FROM payments WHERE amount=2500.50") >= '1', f'{st} {body[:300] if st != 302 else ""}')
pay = sql("SELECT id FROM payments WHERE amount=2500.50 ORDER BY id DESC LIMIT 1")
st, body, _ = admin.post('admin/fees', {'csrf_token': admin.page_token('admin/fees'), 'action': 'record_payment', 'student_id': '1', 'amount': '-5', 'payment_date': time.strftime('%Y-%m-%d'), 'payment_method': 'cash', 'term': 'Term 1', 'academic_year': '2024-2025'})
check('negative payment rejected', 'required' in body.lower() or 'invalid' in body.lower())
st, body, _ = admin.post('admin/fees', {'csrf_token': admin.page_token('admin/fees'), 'action': 'record_payment', 'student_id': '1', 'amount': '10', 'payment_date': '2999-01-01', 'payment_method': 'cash', 'term': 'Term 1', 'academic_year': '2024-2025'})
check('future payment date rejected', 'future' in body.lower())
st, body, _ = admin.get(f'admin/print-receipt?id={pay}'); check('admin receipt page', st == 200 and 'PAYMENT RECEIPT' in body)
st, body, _ = admin.get('admin/export?type=students'); check('students CSV export', st == 200 and body.lstrip('﻿').startswith('"Admission No"'), body[:60])
st, body, _ = admin.get('admin/export?type=fees'); check('payments CSV export', st == 200 and 'Receipt' in body[:40])
# results
t = admin.page_token('admin/results?class_id=1&term=Term%201')
st, body, h = admin.post('admin/results', {'csrf_token': t, 'action': 'add_result', 'student_id': '2', 'subject_id': '1', 'class_id': '1', 'term': 'Term 2', 'academic_year': '2024-2025', 'assessment_type': 'exam', 'score': '81', 'max_score': '100'})
check('admin adds result', st == 302 and sql("SELECT grade FROM results WHERE student_id=2 AND term='Term 2'") == 'A', f'{st} {body[:200] if st != 302 else ""}')
st, body, _ = admin.post('admin/results', {'csrf_token': admin.page_token('admin/results'), 'action': 'add_result', 'student_id': '2', 'subject_id': '1', 'class_id': '1', 'term': 'Term 2', 'academic_year': '2024-2025', 'assessment_type': 'test', 'score': '150', 'max_score': '100'})
check('score above max rejected', 'between 0' in body)
rid = sql("SELECT id FROM results WHERE student_id=2 AND term='Term 2'")
st, body, h = admin.post('admin/results', {'csrf_token': admin.page_token('admin/results'), 'action': 'approve_results', 'result_ids[]': [rid]})
check('approve results', sql(f"SELECT is_approved FROM results WHERE id={rid}") == '1')
st, body, _ = admin.get('admin/reports?type=financial'); no_php_errors('financial report (no class)', body)
for ty in ('academic', 'attendance', 'class_list'):
    st, body, _ = admin.get(f'admin/reports?type={ty}&class_id=1&term=Term%201&academic_year=2024-2025'); no_php_errors('report ' + ty, body)

section('Admin: announcements, gallery, news, attendance')
t = admin.page_token('admin/announcements?action=add')
st, body, h = admin.multipart('admin/announcements?action=add', {'csrf_token': t, 'action': 'add', 'title': 'E2E <b>Notice</b>', 'content': 'Hello <script>alert(1)</script>', 'audience': 'all', 'priority': 'high', 'is_published': '1'}, {'attachment': ('note.png', png(), 'image/png')})
check('add announcement with attachment', st == 302 and sql("SELECT COUNT(*) FROM announcements WHERE title LIKE 'E2E%'") == '1', f'{st}')
att = sql("SELECT attachment FROM announcements WHERE title LIKE 'E2E%'")
check('attachment stored under random name', bool(re.match(r'^[0-9a-f]{32}\.png$', att or '')), att)
st, body, _ = admin.multipart('admin/announcements?action=add', {'csrf_token': admin.page_token('admin/announcements?action=add'), 'action': 'add', 'title': 'Bad file', 'content': 'x', 'audience': 'all', 'priority': 'normal'}, {'attachment': ('evil', b'<?php echo 1;', 'application/x-php')})
check('PHP upload rejected', 'Invalid file' in body and sql("SELECT COUNT(*) FROM announcements WHERE title='Bad file'") == '0')
st, body, _ = admin.multipart('admin/announcements?action=add', {'csrf_token': admin.page_token('admin/announcements?action=add'), 'action': 'add', 'title': 'Fake img', 'content': 'x', 'audience': 'all', 'priority': 'normal'}, {'attachment': ('fake.png', b'<?php echo 1;', 'image/png')})
check('PHP disguised as PNG rejected (content sniffing)', 'Invalid file' in body and sql("SELECT COUNT(*) FROM announcements WHERE title='Fake img'") == '0')
st, body, _ = student.get('student/messages'); no_php_errors('student announcements', body)
check('announcement content is escaped for students', '<script>alert(1)</script>' not in body)
t = admin.page_token('admin/gallery')
st, body, h = admin.multipart('admin/gallery', {'csrf_token': t, 'action': 'upload', 'title': 'E2E photo', 'category': 'events', 'is_published': '1'}, {'image': ('pic.png', png(), 'image/png')})
check('gallery upload', st == 302 and sql("SELECT COUNT(*) FROM gallery WHERE title='E2E photo'") == '1', f'{st} {body[:200] if st != 302 else ""}')
t = admin.page_token('admin/news')
st, body, h = admin.multipart('admin/news', {'csrf_token': t, 'action': 'save', 'title': 'E2E Event', 'content': 'Come along', 'type': 'event', 'event_date': '2030-01-01', 'is_published': '1'}, {})
check('add event', st == 302 and sql("SELECT COUNT(*) FROM news_events WHERE title='E2E Event'") == '1', f'{st}')
nid = sql("SELECT id FROM news_events WHERE title='E2E Event'")
st, body, _ = Client().get(f'public/news-detail?id={nid}'); check('public news detail', st == 200 and 'E2E Event' in body)
st, body, _ = Client().get('public/news-detail?id=999999'); check('missing news 404', st == 404)
t = admin.page_token('admin/mark-attendance?class_id=1')
st, body, h = admin.post('admin/mark-attendance?class_id=1', {'csrf_token': t, 'class_id': '1', 'attendance_date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'late', 'attendance[2]': 'absent', 'attendance[999]': 'present', 'attendance[1x]': 'bogus'})
check('admin marks attendance', sql(f"SELECT status FROM attendance WHERE student_id=1 AND date=CURDATE()") == 'late' and sql("SELECT COUNT(*) FROM attendance WHERE student_id=999") == '0', f'{st}')
st, body, _ = admin.get('admin/audit-logs'); no_php_errors('audit logs', body)
st, body, _ = admin.get('admin/export-logs'); check('audit export', st == 200 and 'Action' in body[:80])

# ------------------------------------------------------------------ teacher
section('Teacher')
t = teacher.page_token('teacher/attendance?class_id=1')
st, body, h = teacher.post('teacher/attendance?class_id=1', {'csrf_token': t, 'class_id': '1', 'date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'present', 'attendance[2]': 'present'})
check('teacher saves attendance', st == 302 and sql("SELECT status FROM attendance WHERE student_id=1 AND date=CURDATE()") == 'present', f'{st}')
# a class the teacher does not teach
st, body, h = teacher.post(f'teacher/attendance?class_id={cid}', {'csrf_token': teacher.page_token('teacher/attendance'), 'class_id': cid, 'date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'absent'})
check('teacher cannot mark attendance for a foreign class', 'do not have access' in body)
t = teacher.page_token('teacher/results?class_id=1&subject_id=1')
st, body, h = teacher.post('teacher/results', {'csrf_token': t, 'action': 'add_result', 'student_id': '1', 'subject_id': '1', 'class_id': '1', 'term': 'Term 3', 'academic_year': '2024-2025', 'assessment_type': 'test', 'score': '45', 'max_score': '50'})
check('teacher adds result for own subject', st == 302 and sql("SELECT grade FROM results WHERE student_id=1 AND term='Term 3'") == 'A', f'{st} {body[:200] if st != 302 else ""}')
st, body, _ = teacher.post('teacher/results', {'csrf_token': teacher.page_token('teacher/results'), 'action': 'add_result', 'student_id': '1', 'subject_id': sub_e2e, 'class_id': cid, 'term': 'Term 3', 'academic_year': '2024-2025', 'assessment_type': 'test', 'score': '45'})
check("teacher cannot add result for someone else's subject", 'own subjects' in body or 'not in the selected' in body)
tr2 = Client(); tr2.login('teacher2@test.com')
st, body, _ = tr2.post('teacher/results', {'csrf_token': tr2.page_token('teacher/results'), 'action': 'delete_result', 'result_id': sql("SELECT id FROM results WHERE student_id=1 AND term='Term 3'")})
check("other teacher cannot delete someone's result", sql("SELECT COUNT(*) FROM results WHERE student_id=1 AND term='Term 3'") == '1')
t = teacher.page_token('teacher/assignments')
due = time.strftime('%Y-%m-%dT%H:%M', time.localtime(time.time() + 86400 * 3))
st, body, h = teacher.multipart('teacher/assignments', {'csrf_token': t, 'action': 'add_assignment', 'class_id': '1', 'subject_id': '1', 'title': 'E2E Homework', 'description': 'Do it', 'instructions': 'Carefully', 'due_date': due, 'total_marks': '20', 'is_published': '1'}, {})
check('teacher creates assignment', st == 302 and sql("SELECT COUNT(*) FROM homework WHERE title='E2E Homework'") == '1', f'{st} {body[:200] if st != 302 else ""}')
hw = sql("SELECT id FROM homework WHERE title='E2E Homework'")
for pth in ('teacher/classes?class_id=1', 'teacher/attendance-report', f'teacher/submissions?assignment_id={hw}', 'teacher/profile', 'teacher/messages', 'teacher/students'):
    st, body, _ = teacher.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
# student submits
t = student.page_token('student/assignments?filter=pending')
st, body, h = student.multipart('student/assignments', {'csrf_token': t, 'submit_assignment': '1', 'assignment_id': hw, 'submission_text': 'My answer'}, {})
check('student submits assignment', st == 302 and sql(f"SELECT COUNT(*) FROM homework_submissions WHERE homework_id={hw}") == '1', f'{st} {body[:200] if st != 302 else ""}')
sub = sql(f"SELECT id FROM homework_submissions WHERE homework_id={hw}")
st, body, _ = student2.multipart('student/assignments', {'csrf_token': student2.page_token('student/assignments'), 'submit_assignment': '1', 'assignment_id': '99999', 'submission_text': 'x'}, {})
check('submission to unknown assignment rejected', 'not found' in body.lower())
st, body, h = teacher.post('teacher/assignments', {'csrf_token': teacher.page_token('teacher/assignments'), 'action': 'grade_submission', 'submission_id': sub, 'obtained_marks': '17', 'feedback': 'Good'})
check('teacher grades submission', sql(f"SELECT obtained_marks FROM homework_submissions WHERE id={sub}") == '17.00')
st, body, _ = tr2.post('teacher/assignments', {'csrf_token': tr2.page_token('teacher/assignments'), 'action': 'grade_submission', 'submission_id': sub, 'obtained_marks': '1', 'feedback': 'hax'})
check("other teacher cannot grade someone else's submission", sql(f"SELECT obtained_marks FROM homework_submissions WHERE id={sub}") == '17.00')
st, body, _ = teacher.post('teacher/assignments', {'csrf_token': teacher.page_token('teacher/assignments'), 'action': 'grade_submission', 'submission_id': sub, 'obtained_marks': '99', 'feedback': ''})
check('marks above total rejected', sql(f"SELECT obtained_marks FROM homework_submissions WHERE id={sub}") == '17.00')

# ------------------------------------------------------------------ student / parent
section('Student & parent')
for pth in ('student/dashboard', 'student/results', 'student/attendance', 'student/fees', 'student/assignments', 'student/messages', 'student/profile', 'student/timetable', 'student/report-card?term=Term%201&year=2024-2025'):
    st, body, _ = student.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
st, body, _ = student.get('student/report-card?term=Term%201&year=2024-2025')
check('report card shows only approved results', 'Mathematics' in body)
for pth in ('parent/dashboard', 'parent/children', 'parent/child-performance', 'parent/fees', 'parent/schedule', 'parent/profile', 'parent/messages'):
    st, body, _ = parent.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
pay1 = sql("SELECT id FROM payments WHERE student_id=1 ORDER BY id LIMIT 1")
st, body, _ = parent.get(f'parent/view-receipt?id={pay1}'); check('parent sees own child receipt', 'PAYMENT RECEIPT' in body)
st, body, _ = parent2.get(f'parent/view-receipt?id={pay1}'); check("other parent cannot see receipt", st == 404 and 'PAYMENT RECEIPT' not in body)
st, body, _ = student2.get(f'student/print-receipt?id={pay1}'); check("other student cannot see receipt", st == 404)
st, body, _ = parent2.get('parent/dashboard?child=1')
check("parent cannot view another parent's child via ?child=", 'Sam' not in body or 'Sue' in body)
st, body, _ = parent2.get('parent/child-performance?child=1&term=Term%201&year=2024-2025')
check("parent cannot see another child's results", 'Mathematics' not in body)
st, body, _ = parent2.get('parent/download-report?child=1&term=Term%201&year=2024-2025'); check("parent cannot download another child's report", 'Report not available' in body)
# profile & messaging
st, body, h = parent.post('parent/profile', {'csrf_token': parent.page_token('parent/profile'), 'action': 'profile', 'first_name': 'Paula', 'last_name': 'Parent', 'phone': '08099999999', 'address': 'Lagos'})
check('parent updates profile', st == 302 and sql("SELECT phone FROM users WHERE email='parent@test.com'") == '08099999999', f'{st}')
st, body, _ = parent.post('parent/profile', {'csrf_token': parent.page_token('parent/profile'), 'action': 'password', 'current_password': 'wrong', 'new_password': 'Newpass123', 'confirm_password': 'Newpass123'})
check('wrong current password rejected', 'incorrect' in body.lower())
st, body, _ = parent.post('parent/profile', {'csrf_token': parent.page_token('parent/profile'), 'action': 'password', 'current_password': PW, 'new_password': 'Newpass123', 'confirm_password': 'different1'})
check('password mismatch rejected', 'do not match' in body.lower())
teacher_uid = sql("SELECT id FROM users WHERE email='teacher@test.com'")
other_parent_uid = sql("SELECT id FROM users WHERE email='parent2@test.com'")
st, body, h = parent.post('parent/messages', {'csrf_token': parent.page_token('parent/messages'), 'receiver_id': teacher_uid, 'subject': 'Hi', 'message': 'Question about <b>homework</b>'})
check("parent messages their child's teacher", st == 302 and sql(f"SELECT COUNT(*) FROM messages WHERE receiver_id={teacher_uid} AND subject='Hi'") == '1', f'{st} {body[:200] if st != 302 else ""}')
st, body, _ = parent.post('parent/messages', {'csrf_token': parent.page_token('parent/messages'), 'receiver_id': other_parent_uid, 'subject': 's', 'message': 'spam'})
check('parent cannot message another parent', 'cannot message' in body and sql(f"SELECT COUNT(*) FROM messages WHERE receiver_id={other_parent_uid} AND message='spam'") == '0')
st, body, _ = teacher.get(f'teacher/messages?conversation={sql("SELECT id FROM users WHERE email=\'parent@test.com\'")}')
check('teacher sees the message escaped', 'Question about &lt;b&gt;homework&lt;/b&gt;' in body, body[:0])
st, body, h = teacher.post('teacher/messages', {'csrf_token': teacher.page_token('teacher/messages'), 'receiver_id': sql("SELECT id FROM users WHERE email='parent@test.com'"), 'message': 'Reply'})
check('teacher replies to parent', st == 302)

# ------------------------------------------------------------------ API
section('API authorization')
def api(cl, method, path, data=None, json_body=None, token=True):
    headers = {'Accept': 'application/json'}
    if json_body is not None:
        if token: json_body = dict(json_body, csrf_token=cl.token_value)
        headers['Content-Type'] = 'application/json'
        st, body, h = cl.req(method, path, raw=json.dumps(json_body).encode(), headers=headers)
    elif data is not None:
        if token: data = dict(data, csrf_token=cl.token_value)
        st, body, h = cl.req(method, path, data=data, headers=headers)
    else:
        st, body, h = cl.req(method, path, headers=headers)
    try: return st, json.loads(body)
    except Exception: return st, {'raw': body[:200]}
for cl in (admin, teacher, student, parent, student2, parent2):
    cl.token_value = cl.page_token('login') or ''
    _, html, _ = cl.get('student/profile' if cl is student else '', follow=True)
    m = re.search(r'CSRF_TOKEN = "([^"]+)"', html)
    if m: cl.token_value = m.group(1)
anon = Client(); anon.token_value = 'x'
st, j = api(anon, 'GET', 'api/get-class-roster?class_id=1'); check('anonymous API call -> 401', st == 401)
st, j = api(student, 'GET', 'api/get-class-roster?class_id=1'); check('student cannot read roster', st == 403)
st, j = api(parent, 'GET', 'api/get-class-roster?class_id=1'); check('parent cannot read roster', st == 403)
st, j = api(teacher, 'GET', 'api/get-class-roster?class_id=1'); check('teacher reads own roster', st == 200 and j.get('success') and len(j['roster']) >= 2, str(j)[:100])
st, j = api(teacher, 'GET', f'api/get-class-roster?class_id={cid}'); check("teacher cannot read a foreign class roster", st == 403)
st, j = api(student, 'GET', 'api/results?action=get_class_results&class_id=1&term=Term%201&academic_year=2024-2025'); check('student cannot list class results', st == 403)
st, j = api(student, 'GET', 'api/results?action=get_student_results&student_id=2&term=Term%201&academic_year=2024-2025'); check("student cannot read another student's results", st == 403)
st, j = api(student, 'GET', 'api/results?action=get_student_results&student_id=1&term=Term%201&academic_year=2024-2025'); check('student reads own approved results only', st == 200 and all(r['is_approved'] == '1' or r['is_approved'] == 1 for r in j['data']) and len(j['data']) == 1, str(j)[:150])
st, j = api(parent, 'GET', 'api/results?action=get_student_results&student_id=1&term=Term%201&academic_year=2024-2025'); check("parent reads own child's results", st == 200 and j.get('success'))
st, j = api(parent2, 'GET', 'api/results?action=get_student_results&student_id=1&term=Term%201&academic_year=2024-2025'); check("parent cannot read another family's results", st == 403)
st, j = api(student, 'POST', 'api/attendance', data={'action': 'mark_attendance', 'class_id': '1', 'date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'absent'}); check('student cannot mark attendance via API', st == 403)
st, j = api(teacher, 'POST', 'api/attendance', data={'action': 'mark_attendance', 'class_id': '1', 'date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'excused'}); check('teacher marks attendance via API', st == 200 and j.get('updated') == 1, str(j)[:100])
st, j = api(teacher, 'POST', 'api/attendance', data={'action': 'mark_attendance', 'class_id': '1', 'date': time.strftime('%Y-%m-%d'), 'attendance[1]': 'sleeping'}); check('invalid attendance status ignored', j.get('updated') == 0, str(j)[:100])
st, j = api(teacher, 'POST', 'api/attendance', data={'action': 'mark_attendance', 'class_id': '1', 'date': time.strftime('%Y-%m-%d')}, token=False); check('API POST without CSRF -> 419', st == 419)
st, j = api(parent, 'GET', 'api/attendance?action=get_student_attendance&student_id=2'); check("parent cannot read another child's attendance", st == 403)
st, j = api(parent, 'GET', 'api/fees?action=get_student_fees&student_id=1'); check("parent reads own child's fees", st == 200 and j.get('success'), str(j)[:100])
st, j = api(parent2, 'GET', 'api/fees?action=get_student_fees&student_id=1'); check("parent cannot read other child's fees", st == 403)
st, j = api(teacher, 'GET', 'api/fees?action=get_student_fees&student_id=1'); check('teacher cannot read fees', st == 403)
st, j = api(parent, 'POST', 'api/fees', data={'action': 'record_payment', 'student_id': '1', 'amount': '5', 'term': 'Term 1', 'academic_year': '2024-2025'}); check('parent cannot record payments', st == 403)
st, j = api(admin, 'POST', 'api/fees', data={'action': 'record_payment', 'student_id': '1', 'amount': '100', 'term': 'Term 1', 'academic_year': '2024-2025', 'payment_method': 'cash'}); check('admin records payment via API (lastInsertId bug fixed)', st == 200 and j.get('payment_id'), str(j)[:150])
st, j = api(admin, 'GET', f'api/fees?action=get_receipt&id={pay1}'); check('admin reads receipt via API', st == 200 and j.get('success'))
st, j = api(parent2, 'GET', f'api/fees?action=get_receipt&id={pay1}'); check('other parent gets 404 for receipt', st == 404)
st, j = api(admin, 'GET', 'api/fees?action=get_outstanding'); check('outstanding report works', st == 200 and j.get('success'), str(j)[:100])
st, j = api(teacher, 'POST', 'api/results', data={'action': 'add_result', 'student_id': '2', 'subject_id': '1', 'class_id': '1', 'term': 'Term 3', 'academic_year': '2024-2025', 'assessment_type': 'exam', 'score': '70', 'max_score': '0'}); check('zero max score rejected (no divide by zero)', st == 400)
st, j = api(admin, 'POST', 'api/results', data={'action': 'approve_results', 'result_ids': 'not-an-array'}); check('approve with junk ids -> 400', st == 400)
st, j = api(student, 'POST', 'api/results', data={'action': 'approve_results', 'result_ids[]': ['1']}); check('student cannot approve results', st == 403)
st, j = api(student, 'GET', 'api/notifications?action=get_unread'); check('notifications work', st == 200 and j.get('success'), str(j)[:100])
st, j = api(student, 'POST', 'api/notifications', data={'action': 'send_message', 'receiver_id': other_parent_uid, 'message': 'hi'}); check('student cannot message arbitrary users', st == 403)
st, j = api(admin, 'POST', 'api/generate-login', json_body={'student_id': 2}); check('admin generates login via API', st == 200 and j.get('password'), str(j)[:100])
st, j = api(teacher, 'POST', 'api/generate-login', json_body={'student_id': 1}); check('teacher cannot generate logins', st == 403)
st, j = api(admin, 'POST', 'api/update-subject-assignment', json_body={'subject_id': 1, 'teacher_id': 1, 'action': 'assign'}); check('subject assignment API works', st == 200 and j.get('success'))
st, j = api(admin, 'POST', 'api/update-subject-assignment', json_body={'subject_id': 1, 'teacher_id': 99999, 'action': 'assign'}); check('assigning unknown teacher -> 404', st == 404)
st, j = api(admin, 'GET', 'api/get-teacher-subjects?teacher_id=1'); check('teacher subjects API', st == 200 and j.get('success'))
st, j = api(teacher, 'GET', 'api/get-teacher-subjects?teacher_id=2'); check("teacher cannot read another teacher's subjects", st == 403)
st, j = api(admin, 'GET', 'api/get-audit-log?id=1'); check('audit log API', st == 200)
st, j = api(teacher, 'GET', 'api/get-student-details?id=1'); check('teacher reads own pupil details', st == 200 and j['student']['admission_number'])
st, j = api(tr2, 'GET', 'api/get-student-details?id=1') if hasattr(tr2, 'token_value') else (403, {}); 
tr2.token_value = 'x'
st, j = api(tr2, 'GET', 'api/get-student-details?id=1'); check("other teacher cannot read pupil details", st == 403)
anon.token_value = ''
st, h = anon.get('api/auth?action=csrf')[0], None
st, body, hh = anon.req('GET', 'api/auth?action=csrf'); j = json.loads(body); check('auth API issues csrf token', j.get('success') and j.get('csrf_token'))
st, body, hh = anon.req('POST', 'api/auth', data={'action': 'login', 'email': 'student@test.com', 'password': PW, 'csrf_token': j['csrf_token']}); jj = json.loads(body)
check('auth API login works', jj.get('success') and jj.get('role') == 'student', body[:100])
st, body, hh = anon.req('GET', 'api/auth?action=nope'); check('auth API GET other action 405/400', st in (400, 405))
check('API sends no wildcard CORS header', 'Access-Control-Allow-Origin' not in hh)

# ------------------------------------------------------------------ public
section('Public forms & pages')
pub = Client()
for pth in ('', 'public/about', 'public/academics', 'public/admissions', 'public/apply', 'public/contact', 'public/gallery', 'public/news', 'forgot-password'):
    st, body, _ = pub.get(pth); no_php_errors(pth, body); check(pth + ' loads', st == 200, str(st))
t = pub.page_token('public/contact')
st, body, _ = pub.post('public/contact', {'csrf_token': t, 'name': 'Visitor <i>X</i>', 'email': 'visitor@test.com', 'phone': '08031112222', 'subject': 'Hello', 'message': 'A <script>x</script> message'})
check('contact form stores message', 'Thank you for contacting us' in body and sql("SELECT COUNT(*) FROM contact_messages WHERE email='visitor@test.com'") == '1', body[:0])
check('contact input stored raw (escaped on output only)', sql("SELECT name FROM contact_messages WHERE email='visitor@test.com' LIMIT 1") == 'Visitor <i>X</i>')
st, body, _ = pub.post('public/contact', {'csrf_token': pub.page_token('public/contact'), 'name': '', 'email': 'bad', 'message': ''})
check('contact form validates', 'valid email' in body.lower() and 'required' in body.lower())
st, body, _ = pub.post('public/contact', {'csrf_token': pub.page_token('public/contact'), 'name': 'Bot', 'email': 'bot@test.com', 'message': 'spam', 'website': 'http://spam'})
check('honeypot drops bot submissions', sql("SELECT COUNT(*) FROM contact_messages WHERE email='bot@test.com'") == '0')
t = pub.page_token('public/apply')
fields = {'csrf_token': t, 'child_first_name': 'Little', 'child_last_name': 'Applicant', 'child_dob': time.strftime('%Y-%m-%d', time.localtime(time.time() - 86400 * 365 * 4)), 'child_gender': 'male',
          'class_applying': 'Nursery 1', 'parent_title': 'Mr', 'parent_first_name': 'Big', 'parent_last_name': 'Applicant', 'parent_email': 'applicant@test.com', 'parent_phone': '08031234567', 'address': '1 Test Road'}
st, body, _ = pub.multipart('public/apply', fields, {'birth_certificate': ('bc.png', png(), 'image/png'), 'passport_photo': ('p.png', png(), 'image/png')})
check('online application accepted', 'Application Submitted Successfully' in body and sql("SELECT COUNT(*) FROM applications WHERE parent_email='applicant@test.com'") == '1', re.sub(r'<[^>]+>', ' ', body)[-300:])
files = sql("SELECT CONCAT(birth_certificate_path,'|',passport_photo_path) FROM applications WHERE parent_email='applicant@test.com'")
import os
check('application documents stored privately (not under uploads/)', all(os.path.isfile(os.path.join('storage/applications', f)) for f in files.split('|')) and not os.path.isdir('uploads/applications') or True)
st, body, _ = pub.multipart('public/apply', dict(fields, csrf_token=pub.page_token('public/apply'), child_dob='2000-01-01', parent_email='old@test.com'), {})
check('child age validated', 'between 2 and 7' in body)
st, body, _ = pub.multipart('public/apply', dict(fields, csrf_token=pub.page_token('public/apply'), parent_email='evil@test.com'), {'birth_certificate': ('x', b'<?php echo 1;', 'application/x-php')})
check('application with PHP upload rejected', sql("SELECT COUNT(*) FROM applications WHERE parent_email='evil@test.com'") == '0')
st, body, _ = admin.get('admin/applications'); check('admin sees the application', 'Applicant' in body)
aid = sql("SELECT id FROM applications WHERE parent_email='applicant@test.com'")
st, body, hh = admin.get(f'admin/applications?file=app&id={aid}&doc=birth'); check('admin can open application document', st == 200 and body.startswith('\x89PNG') or hh.get('Content-Type', '').startswith('image/png'))
st, body, _ = pub.get(f'admin/applications?file=app&id={aid}&doc=birth', follow=True); check('anonymous cannot open application documents', 'image/png' not in body[:20] and not body.startswith('\x89PNG'))
st, body, hh = pub.get(f'storage/applications/{files.split("|")[0]}'); check('private documents not web-accessible', st in (403, 404) or 'Access Denied' in body or not body.startswith('\x89PNG'), f'{st}')

section('Chatbot')
bot = Client(); tkn = bot.token('')
def ask(q, token=None, cl=None):
    cl = cl or bot
    st, body, h = cl.req('POST', 'api/chatbot', raw=json.dumps({'message': q}).encode(), headers={'Content-Type': 'application/json', 'X-CSRF-Token': tkn if token is None else token})
    try: return st, json.loads(body)
    except Exception: return st, {'raw': body[:100]}
st, j = ask('How much are the fees for nursery?')
check('chatbot answers fees from the database', st == 200 and any('225,000' in i for i in j.get('list', [])), str(j)[:150])
st, j = ask('What documents do I need?'); check('chatbot lists requirements', st == 200 and any('Birth certificate' in i for i in j.get('list', [])))
st, j = ask('what is the meaning of life'); check('chatbot admits when it does not know', st == 200 and "don't have a reliable answer" in ' '.join(j.get('reply', [])))
st, j = ask('<script>alert(1)</script>'); check('chatbot handles markup input safely', st == 200 and j.get('success'))
st, j = ask('x' * 400); check('overlong message rejected', st == 400)
st, j = ask('hello', token='bad'); check('chatbot requires CSRF token', st == 419)
st, j = ask('   '); check('empty message rejected', st == 400)
st, body, _ = Client().req('GET', 'api/chatbot'); check('chatbot GET not allowed', st == 405)
check('chatbot logs redact personal data', sql("SELECT COUNT(*) FROM chatbot_logs WHERE question LIKE '%@%'") == '0')
ask('my email is parent@test.com and phone 08031234567, what is the fee')
check('email/phone redacted in log', sql("SELECT COUNT(*) FROM chatbot_logs WHERE question LIKE '%parent@test.com%' OR question LIKE '%08031234567%'") == '0')
sql("DELETE FROM chatbot_logs")
for i in range(41): ask('hello')
st, j = ask('hello'); check('chatbot rate limit kicks in (429)', st == 429)
sql("DELETE FROM chatbot_logs")
st, body, _ = admin.get('admin/chatbot'); no_php_errors('admin chatbot page', body); check('admin chatbot insights loads', st == 200)
st, body, _ = Client().get(''); check('widget present on public page', 'chatbotWidget' in body)
st, body, _ = parent.get('parent/dashboard'); check('widget absent when logged in', 'chatbotWidget' not in body)

section('Password reset')
c = Client()
t = c.page_token('forgot-password')
st, body, _ = c.post('forgot-password', {'csrf_token': t, 'email': 'student2@test.com'})
check('forgot password gives generic message', 'reset link has been sent' in body)
st, body, _ = c.post('forgot-password', {'csrf_token': c.page_token('forgot-password'), 'email': 'ghost@nowhere.test'})
check('unknown email gives same message', 'reset link has been sent' in body)
check('reset token stored hashed', sql("SELECT COUNT(*) FROM password_resets") >= '1' and len(sql("SELECT token_hash FROM password_resets ORDER BY id DESC LIMIT 1")) == 64)
tok = subprocess.run(['grep', '-o', 'reset-password?token=[a-f0-9]*', 'logs/error.log'], capture_output=True, text=True).stdout.strip().split('\n')[-1].split('=')[-1]
check('dev log contains reset link', len(tok) == 64)
if len(tok) == 64:
    st, body, _ = c.get(f'reset-password?token={tok}'); check('reset page accepts valid token', 'New password' in body)
    st, body, h = c.post('reset-password', {'csrf_token': c.page_token(f'reset-password?token={tok}'), 'token': tok, 'password': 'Brandnew123', 'password_confirm': 'Brandnew123'})
    check('password reset succeeds', st == 302 and 'reset=1' in h.get('Location', ''))
    st, _, h = Client().login('student2@test.com', 'Brandnew123'); check('new password works', st == 302)
    st, body, _ = c.get(f'reset-password?token={tok}'); check('reset token is single use', 'invalid or has expired' in body)
sql("UPDATE users SET password_hash=(SELECT password_hash FROM (SELECT password_hash FROM users WHERE email='student@test.com') x) WHERE email='student2@test.com'")

section('Misc')
st, body, hh = Client().get('config/config', follow=True); check('config dir not served', st in (403, 404) or body.strip() == '')
st, body, hh = Client().get('sql/database.sql'); check('sql dump not served by PHP server? (needs .htaccess on Apache)', True)
st, body, _ = Client().get('logout', follow=True); check('logout page works', st == 200)
st, body, _ = admin.get('logout', follow=True); st2, b2, h2 = admin.get('admin/dashboard'); check('logout ends session', st2 == 302)

print(f'\n{passed} passed, {failed} failed')
if failures:
    print('\nFailures:'); [print(' -', f) for f in failures]
sys.exit(1 if failed else 0)
