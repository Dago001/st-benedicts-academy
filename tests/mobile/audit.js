// Mobile audit: logs in as each role, visits every page at phone widths and reports
// horizontal overflow, tiny tap targets and unreadable text. Optional screenshots.
// usage: node tests/mobile/audit.js [--shots] [--width=375] [--only=substring]
const path = require('path');
const fs = require('fs');
const { chromium } = require('/opt/node-tools/node_modules/playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const args = process.argv.slice(2);
const shots = args.includes('--shots');
const width = parseInt((args.find(a => a.startsWith('--width=')) || '--width=375').split('=')[1], 10);
const only = (args.find(a => a.startsWith('--only=')) || '').split('=')[1] || '';
const outDir = path.join(__dirname, 'shots');
if (shots) fs.mkdirSync(outDir, { recursive: true });

const users = {
  admin: 'admin@stbenedicts.edu.ng', teacher: 'teacher@test.com', student: 'student@test.com', parent: 'parent@test.com',
};
const publicPages = ['', 'login', 'forgot-password', 'public/about', 'public/academics', 'public/admissions', 'public/apply',
  'public/contact', 'public/gallery', 'public/news', 'public/news-detail?id=1'];
const skip = /print-receipt|view-receipt|download-report|export|report-card|print-attendance|index\.php$/;

function pagesFor(role) {
  return fs.readdirSync(path.join(__dirname, '../../' + role)).filter(f => f.endsWith('.php')).map(f => `${role}/${f.replace(/\.php$/, '')}`).filter(p => !skip.test(p));
}

async function measure(page) {
  return page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const bad = [];
    const all = document.querySelectorAll('body *');
    for (const el of all) {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || cs.position === 'fixed') continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      // ignore things inside a horizontally scrollable container
      let p = el.parentElement, scroller = false;
      while (p && p !== document.body) {
        const o = getComputedStyle(p).overflowX;
        if ((o === 'auto' || o === 'scroll' || o === 'hidden') && p.getBoundingClientRect().right <= vw + 1) { scroller = true; break; }
        p = p.parentElement;
      }
      if (!scroller && r.right > vw + 2 && bad.length < 6) {
        bad.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''} right=${Math.round(r.right)}`);
      }
    }
    const small = [];
    for (const el of document.querySelectorAll('a, button, input:not([type=hidden]), select, textarea')) {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if ((r.height < 30 || r.width < 30) && !el.closest('.dataTables_paginate') && small.length < 4) {
        small.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} ${Math.round(r.width)}x${Math.round(r.height)}`);
      }
    }
    const tiny = [...document.querySelectorAll('p, td, li, label, span, a')].filter(e => e.childNodes.length && [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim()) && parseFloat(getComputedStyle(e).fontSize) < 11 && e.getBoundingClientRect().width > 0).length;
    return { scrollWidth: document.documentElement.scrollWidth, clientWidth: vw, bad, small, tiny };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  let problems = 0, visited = 0;
  const run = async (label, role, pages) => {
    const ctx = await browser.newContext({ viewport: { width, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    const page = await ctx.newPage();
    const errors = [];
    page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE)) errors.push('HTTP ' + r.status() + ' ' + r.url().replace(BASE, '')); });
    page.on('dialog', d => { errors.push('dialog: ' + d.message().slice(0, 80)); d.dismiss(); });
    page.on('pageerror', e => errors.push('JS: ' + e.message));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|net::ERR/.test(m.text())) errors.push('console: ' + m.text()); });
    if (role) {
      await page.goto(BASE + '/login');
      await page.fill('#email', users[role]);
      await page.fill('#password', 'Test@12345');
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    }
    for (const p of pages) {
      if (only && !p.includes(only)) continue;
      errors.length = 0;
      try { await page.goto(`${BASE}/${p}`, { waitUntil: 'domcontentloaded', timeout: 20000 }); await page.waitForTimeout(250); } catch (e) { console.log(`ERR  [${label}] ${p}: ${e.message}`); problems++; continue; }
      visited++;
      const m = await measure(page);
      const overflow = m.scrollWidth > m.clientWidth + 1;
      const flag = overflow || m.bad.length;
      if (flag) problems++;
      console.log(`${flag ? 'FAIL' : 'ok  '} [${label}] ${p} scroll=${m.scrollWidth}/${m.clientWidth}${m.bad.length ? ' overflow: ' + m.bad.join(' | ') : ''}${m.small.length ? '  small: ' + m.small.join(' | ') : ''}${m.tiny ? '  tiny-text:' + m.tiny : ''}${errors.length ? '  ' + errors.slice(0, 2).join(' ; ') : ''}`);
      if (shots) await page.screenshot({ path: path.join(outDir, `${label}-${p.replace(/[\/?=&.]/g, '_')}.png`), fullPage: false });
    }
    await ctx.close();
  };
  await run('public', null, publicPages);
  for (const role of Object.keys(users)) await run(role, role, pagesFor(role));
  await browser.close();
  console.log(`\nvisited ${visited} pages at ${width}px, ${problems} with problems`);
  process.exit(problems ? 1 : 0);
})();
