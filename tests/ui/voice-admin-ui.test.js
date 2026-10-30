// Browser check of the AI Voice administration page with the API mocked. Run: node tests/ui/voice-admin-ui.test.js
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '../../public_html/assets/voice');
const ok = (data) => ({ success: true, data });
const defs = [['voice_live_operations_enabled', 'bool', true], ['voice_markup_pct', 'float', false], ['voice_recording_countries', 'iso', false]].map(([key, type, danger]) => ({ key, type, danger, label: key, default: '0' }));
const API = {
  '/api/admin/voice/providers': ok({ providers: [{ provider: 'twilio', configured: true, missing_keys: [], errors_24h: 0 }, { provider: 'telnyx', configured: false, missing_keys: ['TELNYX_API_KEY'], errors_24h: 0 }] }),
  '/api/admin/voice/settings': ok({ values: { voice_live_operations_enabled: '0', voice_markup_pct: '20', voice_recording_countries: '' }, definitions: defs, confirm_phrase: 'I UNDERSTAND' }),
  '/api/admin/voice/quality': ok({ calls: 3, fallback_rate_pct: 0, avg_reply_ms: 800, provider_errors: 1 }),
  '/api/admin/voice/companies': ok({ companies: [{ company: 5, company_name: 'Nile <b>Tours</b>', enabled: 1, sandbox_mode: 1, outbound_enabled: 0, active_numbers: 1, month_calls: 4, month_price_usd: 2.5, monthly_budget_usd: 50 }] }),
  '/api/admin/voice/margins': ok({ total: { cost_usd: 2, price_usd: 2.5, margin_pct: 20 }, companies: [] }),
  '/api/admin/voice/errors': ok({ errors: [] }), '/api/admin/voice/access': ok({ users: [{ id: 9, email: 'a@b.c', company_name: 'Acme', voice_enabled: false, voice_max_numbers: 1, source: 'plan', is_staff_of: null }] }), '/api/admin/voice/dnc': ok({ entries: [{ e164: '+2010', reason: 'x', created_at: 'now' }] }),
};
(async () => {
  const browser = await chromium.launch();
  const failures = [];
  for (const [name, vp] of [['mobile', { width: 375, height: 800 }], ['desktop', { width: 1280, height: 800 }]]) {
    const page = await (await browser.newContext({ viewport: vp })).newPage();
    const posts = [];
    await page.route('**/*', (route) => {
      const u = new URL(route.request().url());
      if (route.request().method() === 'POST') posts.push(u.pathname + ' ' + route.request().postData());
      if (u.pathname === '/admin/voice') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="t"></head><body><div id="voice-admin" class="va"></div><link rel="stylesheet" href="/assets/voice/voice-agent.css"><script src="/assets/voice/voice-admin.js"></script></body></html>' });
      if (u.pathname.startsWith('/assets/voice/')) return route.fulfill({ contentType: u.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, path.basename(u.pathname))) });
      if (API[u.pathname]) return route.fulfill({ contentType: 'application/json', body: JSON.stringify(API[u.pathname]) });
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(ok({ cancelled_jobs: 2 })) });
    });
    page.on('dialog', (d) => d.accept(d.type() === 'prompt' ? 'I UNDERSTAND' : undefined));
    await page.goto('http://voice.test/admin/voice');
    await page.waitForSelector('.va-tab');
    const n = (await page.$$('.va-tab')).length;
    if (n !== 7) failures.push(`${name}: expected 7 tabs, got ${n}`);
    for (let i = 0; i < n; i++) {
      await (await page.$$('.va-tab'))[i].click();
      await page.waitForFunction(() => !(document.querySelector('.va-body .va-muted')?.textContent || '').startsWith('Loading'));
      if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) failures.push(`${name}: horizontal overflow on tab ${i}`);
      if (await page.$('.va-err')) failures.push(`${name}: error on tab ${i}: ${await page.textContent('.va-err')}`);
      if (name === 'desktop' && process.env.SHOT && [0, 2].includes(i)) await page.screenshot({ path: process.env.SHOT + `/admin-${i}.png`, fullPage: true });
    }
    if (await page.$('.va-body b:text("Tours")')) failures.push(`${name}: company name rendered as HTML`);
    await (await page.$$('.va-tab'))[0].click();
    await page.waitForSelector('[data-stop]');
    await page.click('[data-stop]');
    await page.waitForFunction(() => document.querySelector('[data-msg]'));
    if (!posts.some((p) => p.startsWith('/api/admin/voice/emergency-stop'))) failures.push(`${name}: emergency stop did not post`);
    await (await page.$$('.va-tab'))[2].click();
    await page.waitForSelector('[data-live]');
    await page.click('[data-live]');
    await page.waitForTimeout(300);
    if (!posts.some((p) => p.includes('/companies/5/control') && p.includes('"sandbox_mode":0') && p.includes('I UNDERSTAND'))) failures.push(`${name}: take-out-of-test-mode did not send confirmation`);
    await (await page.$$('.va-tab'))[3].click();
    await page.fill('[data-search] input', 'acme');
    await page.click('[data-search] button');
    await page.waitForSelector('[data-on]');
    await page.click('[data-on]');
    await page.waitForTimeout(300);
    if (!posts.some((p) => p.startsWith('/api/admin/voice/access/9') && p.includes('"enabled":1'))) failures.push(`${name}: turn-on did not post`);
    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) failures.push(`${name}: overflow on access tab`);
    await page.context().close();
  }
  await browser.close();
  if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
  console.log('ADMIN UI OK: 7 tabs, no overflow at 375px, escaped output, emergency stop and live-mode confirmation send the right requests');
})();
