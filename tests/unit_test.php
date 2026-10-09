<?php
// tests/unit_test.php - dependency-free unit tests for pure logic. Run: php tests/unit_test.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/mailer.php';

$pass = $fail = 0;
function t($name, $cond) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

// TOTP (RFC 6238 appendix B vectors, SHA-1, secret "12345678901234567890")
$secret = Totp::base32Encode('12345678901234567890');
t('totp 59s',         Totp::code($secret, 59, 8) === '94287082');
t('totp 1111111109',  Totp::code($secret, 1111111109, 8) === '07081804');
t('totp 20000000000', Totp::code($secret, 20000000000, 8) === '65353130');
t('base32 roundtrip', Totp::base32Decode(Totp::base32Encode("abc\x00\xff")) === "abc\x00\xff");
t('totp verify now',  Totp::verify($secret, Totp::code($secret)));
t('totp verify drift', Totp::verify($secret, Totp::code($secret, time() - 30)));
t('totp rejects old', !Totp::verify($secret, Totp::code($secret, time() - 300)));
t('totp rejects junk', !Totp::verify($secret, 'abcdef') && !Totp::verify($secret, ''));
t('totp uri', strpos(Totp::uri('ABC', 'a@b.c', 'School'), 'otpauth://totp/School%3Aa%40b.c?secret=ABC') === 0);

// Validation
t('email ok', Security::validateEmail('a@b.com'));
t('email bad', !Security::validateEmail('a@@b'));
t('phone ok', Security::validatePhone('08031234567'));
t('phone bad', !Security::validatePhone('12345'));
t('strong pw rejects short', strong_password('Ab1') !== null);
t('strong pw accepts', strong_password('Goodpass123') === null);

// Grades
foreach ([[85,'A'],[70,'A'],[69,'B'],[60,'B'],[55,'C'],[45,'D'],[40,'E'],[39,'F'],[0,'F']] as [$sc,$g]) t("grade $sc", letterGrade($sc) === $g);
t('grade max=0', letterGrade(5, 0) === 'F');
t('grade scaled', letterGrade(35, 50) === 'A');

// Escaping
t('e() escapes', e('<script>"x"</script>') === '&lt;script&gt;&quot;x&quot;&lt;/script&gt;');
t('e() null', e(null) === '');

// Password hashing
$h = Security::hashPassword('Secret123');
t('hash verifies', Security::verifyPassword('Secret123', $h));
t('hash rejects', !Security::verifyPassword('secret123', $h));

// Mailer: header injection + MIME structure
t('mail rejects CRLF', Mailer::send("a@b.com\r\nBcc: x@y.z", 's', 'b') === false);
t('mail rejects bad addr', Mailer::send('nope', 's', 'b') === false);
$msg = (new Mailer())->buildMessage('p@x.com', "Résumé", '<p>Hello <b>World</b></p>', 'info@school.ng');
t('mime subject encoded', strpos($msg, 'Subject: =?UTF-8?B?') !== false);
t('mime has both parts', strpos($msg, 'text/plain') !== false && strpos($msg, 'text/html') !== false);
t('mime text part stripped', strpos(base64_decode(explode("\r\n\r\n", $msg)[1] ?? ''), '<b>') === false);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
