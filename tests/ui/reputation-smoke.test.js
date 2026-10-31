// Read-only browser smoke test of the Reputation service on a deployed site (nothing is created, changed or charged).
// Run: TF_BASE_URL=https://tourfecto.pro TF_CUSTOMER_EMAIL=... TF_CUSTOMER_PASSWORD=... TF_ADMIN_EMAIL=... TF_ADMIN_PASSWORD=... node tests/ui/reputation-smoke.test.js
// Needs Playwright + Chromium. Credentials come only from the environment; without them the test skips (exit 0).
// It checks, as a customer: the reputation pages open without JS errors or failed requests, the QR panel opens with the keyboard,
// the public "review us" and survey pages (English and Arabic, phone width) have no horizontal overflow, the report opens;
// as an admin: the Reputation limits page loads with its cards and the history; and the public pricing tab exists.
const { chromium } = require('playwright');

const base = (process.env.TF_BASE_URL || '').replace(/\/$/, '');
const creds = {
  customer: [process.env.TF_CUSTOMER_EMAIL, process.env.TF_CUSTOMER_PASSWORD],
  admin: [process.env.TF_ADMIN_EMAIL, process.env.TF_ADMIN_PASSWORD],
};
if (!base || !creds.customer[0] || !creds.customer[1]) {
  console.log('SKIP: set TF_BASE_URL, TF_CUSTOMER_EMAIL and TF_CUSTOMER_PASSWORD (and TF_ADMIN_* for the admin checks).');
  process.exit(0);
}

let failures = 0;
const check = (name, cond, extra) => {
  console.log((cond ? 'PASS ' : 'FAIL ') + name + (extra ? ' - ' + extra : ''));
  if (!cond) failures++;
};

async function login(browser, [email, pass], viewport) {
  const ctx = await browser.newContext({ viewport: viewport || { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  page.problems = [];
  page.on('pageerror', (e) => page.problems.push('JS ' + e.message));
  page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) page.problems.push(r.status() + ' ' + r.url().replace(base, '').slice(0, 80)); });
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type=email],input[name=email]', email);
  await page.fill('input[type=password]', pass);
  await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('button[type=submit]')]);
  await page.waitForTimeout(2500);
  return page;
}

(async () => {
  const browser = await chromium.launch();
  const cu = await login(browser, creds.customer);
  await cu.goto(base + '/dashboard', { waitUntil: 'domcontentloaded' });
  check('customer is signed in', !/\/login/.test(cu.url()), cu.url().replace(base, ''));

  for (const path of ['/reputation/overview', '/reputation/reviews', '/reputation/platforms', '/reputation/stats', '/review-requests', '/reputation/surveys', '/reputation/plans']) {
    cu.problems.length = 0;
    const r = await cu.goto(base + path, { waitUntil: 'networkidle' });
    await cu.waitForTimeout(800);
    check('page ' + path, r && r.status() === 200 && cu.problems.length === 0, cu.problems.slice(0, 3).join('; '));
  }

  // keyboard: the QR panel opens with Enter and shows a link and an image
  await cu.goto(base + '/review-requests', { waitUntil: 'networkidle' });
  await cu.focus('button[onclick*="toggleQr"]');
  await cu.keyboard.press('Enter');
  const opened = await cu.waitForSelector('#qrUrl', { timeout: 8000 }).then(() => true).catch(() => false);
  check('QR panel opens with the keyboard', opened);
  const url = opened ? await cu.$eval('#qrUrl', (e) => e.value) : '';
  check('QR panel has an SVG', opened && !!(await cu.$('#qrPanel svg')));

  // public pages on a phone, English and Arabic
  if (url) {
    const guest = await (await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true })).newPage();
    for (const u of [url, url + '?lang=ar', url.replace('/review-us/', '/survey/'), url.replace('/review-us/', '/survey/') + '?lang=ar']) {
      const r = await guest.goto(u, { waitUntil: 'networkidle' });
      const over = await guest.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      check('public page ' + u.replace(/-[a-f0-9]{20}/, '-<sig>').replace(base, ''), r.status() === 200 && over <= 0, 'status ' + r.status() + ', overflow ' + over + 'px');
    }
    const tampered = await guest.goto(url.slice(0, -1) + (url.slice(-1) === '0' ? '1' : '0'), { waitUntil: 'domcontentloaded' });
    check('a tampered public link is refused', tampered.status() === 404);
  }

  const rep = await cu.goto(base + '/reputation/report?lang=en', { waitUntil: 'networkidle' });
  const repText = await cu.innerText('body');
  check('monthly report opens (or says it is not in the plan)', rep.status() === 200 || rep.status() === 403, 'status ' + rep.status());
  check('monthly report has its title', rep.status() !== 200 || /Monthly reputation report/.test(repText));

  // admin
  if (creds.admin[0] && creds.admin[1]) {
    const ad = await login(browser, creds.admin);
    ad.problems.length = 0;
    await ad.goto(base + '/admin/reputation-limits', { waitUntil: 'networkidle' });
    await ad.waitForSelector('#rep-limits-root input', { timeout: 15000 }).catch(() => {});
    const t = await ad.innerText('#rep-limits-root').catch(() => '');
    check('admin limits page renders its cards', /Limits per plan/.test(t) && /Exceptions for one customer/.test(t) && /Review sources/.test(t) && /Change history/.test(t));
    check('admin limits page has no JS or HTTP errors', ad.problems.length === 0, ad.problems.slice(0, 3).join('; '));
    const denied = await cu.evaluate(async () => (await fetch('/api/admin/reputation-limits')).status);
    check('a customer cannot read the admin limits API', denied === 403, 'status ' + denied);
  } else {
    console.log('SKIP admin checks: TF_ADMIN_EMAIL / TF_ADMIN_PASSWORD not set');
  }

  // public pricing tab
  const g2 = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await g2.goto(base + '/pricing#reputation', { waitUntil: 'networkidle' });
  check('pricing page has the Reputation tab', !!(await g2.$('#tabbtn-reputation')));

  await browser.close();
  console.log(failures ? failures + ' check(s) failed' : 'all checks passed');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
