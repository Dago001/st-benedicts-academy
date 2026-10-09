#!/usr/bin/env python3
"""Starts a throwaway SMTP server, sends mail through includes/mailer.php, and checks what arrived."""
import socketserver, subprocess, threading, base64, sys, os, tempfile
got = {}
class H(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda s: self.wfile.write((s + "\r\n").encode())
        w("220 fake ESMTP")
        data = False; body = []
        while True:
            l = self.rfile.readline()
            if not l: break
            l = l.decode().rstrip("\r\n")
            if data:
                if l == ".": data = False; got['body'] = "\n".join(body); w("250 queued"); continue
                body.append(l[1:] if l.startswith("..") else l); continue
            u = l.upper()
            if u.startswith("EHLO"): w("250-fake"); w("250 AUTH LOGIN")
            elif u == "AUTH LOGIN": w("334 VXNlcm5hbWU6")
            elif 'user' not in got and not u.startswith(("MAIL","RCPT","DATA","QUIT")):
                got['user'] = base64.b64decode(l).decode(); w("334 UGFzc3dvcmQ6")
            elif 'pass' not in got and not u.startswith(("MAIL","RCPT","DATA","QUIT")):
                got['pass'] = base64.b64decode(l).decode(); w("235 ok")
            elif u.startswith("MAIL FROM"): got['from'] = l; w("250 ok")
            elif u.startswith("RCPT TO"): got['to'] = l; w("250 ok")
            elif u == "DATA": data = True; w("354 go")
            elif u == "QUIT": w("221 bye"); break
socketserver.ThreadingTCPServer.allow_reuse_address = True
srv = socketserver.ThreadingTCPServer(("127.0.0.1", 0), H); srv.daemon_threads = True
threading.Thread(target=srv.serve_forever, daemon=True).start()
PORT = srv.server_address[1]
code = f"""<?php define('SMTP_HOST','127.0.0.1'); define('SMTP_PORT',{PORT}); define('SMTP_SECURE',''); define('SMTP_USER','u1'); define('SMTP_PASS','p1');
define('MAIL_FROM','noreply@school.ng');
require 'config/config.php'; require 'includes/helpers.php';
exit(sendEmail('parent@example.com','Hello','<p>Line one</p>\\n.dot line') ? 0 : 1);"""
probe = tempfile.NamedTemporaryFile('w', suffix='.php', delete=False); probe.write(code); probe.close()
r = subprocess.run(['php', probe.name], cwd=os.path.join(os.path.dirname(__file__), '..'), capture_output=True, text=True)
ok = r.returncode == 0 and got.get('user') == 'u1' and got.get('pass') == 'p1' \
     and 'parent@example.com' in got.get('to','') and 'noreply@school.ng' in got.get('from','') \
     and 'Subject: Hello' in got.get('body','')
os.unlink(probe.name)
print('SMTP send:', 'ok' if ok else f'FAIL {r.stderr} {got}')
sys.exit(0 if ok else 1)
