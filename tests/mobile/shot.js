// usage: node tests/mobile/shot.js <role|public> <path> [out.png] [--full] [--drawer] [--width=375]
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const users = { admin: 'admin@stbenedicts.edu.ng', teacher: 'teacher@test.com', student: 'student@test.com', parent: 'parent@test.com' };
(async () => {
  const [role, p, out = '/tmp/shot.png', ...rest] = process.argv.slice(2);
  const width = parseInt((rest.find(a => a.startsWith('--width=')) || '--width=375').split('=')[1], 10);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const c = await b.newContext({ viewport: { width, height: 760 }, deviceScaleFactor: 1.5, isMobile: width < 700, hasTouch: width < 700 });
  const page = await c.newPage();
  if (role !== 'public') {
    await page.goto('http://127.0.0.1:8080/login.php');
    await page.fill('#email', users[role]); await page.fill('#password', 'Test@12345');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  }
  await page.goto('http://127.0.0.1:8080/' + p, { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(400);
  if (rest.includes('--drawer')) { await page.click('#sidebarToggle'); await page.waitForTimeout(400); }
  await page.screenshot({ path: out, fullPage: rest.includes('--full') });
  await b.close();
})();
