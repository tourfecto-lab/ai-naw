// Browser check of the AI Voice Agent page with the API mocked (no server, no provider, no real data).
// Run: node tests/ui/voice-agent-ui.test.js   (needs Playwright + Chromium)
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '../../public_html/assets/voice');
const ok = (data) => ({ success: true, data });
const API = {
  '/api/voice-agent/status': ok({ feature_enabled: true, role: 'admin', capabilities: [] }),
  '/api/voice-agent/settings': ok({ settings: { sandbox_mode: 1, enabled: 1, booking_mode: 'request_only', budget_policy: 'block', monthly_budget_usd: '50' } }),
  '/api/voice-agent/analytics': ok({ calls: { total: 12, minutes: 40.5, missed_rate: 8.3 }, bookings: { count: 3, conversion: 25 }, cost: { total_usd: 4.2 }, roi: { note: 'x' }, objections: [{ key: 'price<img src=x onerror=window.__xss=1>', count: 2 }], loss_reasons: [] }),
  '/api/voice-agent/costs': ok({ costs: { month_usd: 4.2, budget_usd: 50 } }),
  '/api/voice-agent/numbers': ok({ numbers: [{ e164: '+201001234567', country: 'EG', number_type: 'mobile', provider: 'twilio', status: 'simulated' }] }),
  '/api/voice-agent/agents': ok({ agents: [{ name: 'Reception', role: 'receptionist', language: 'en', status: 'active' }] }),
  '/api/voice-agent/calls': ok({ calls: [{ started_at: '2026-10-30 10:00:00', direction: 'inbound', from_e164: '+2010', outcome: 'booked', summary: 'A very long summary '.repeat(12) }] }),
  '/api/voice-agent/outbound/campaigns': ok({ campaigns: [] }), '/api/voice-agent/outbound/jobs': ok({ jobs: [] }),
  '/api/voice-agent/billing': ok({ statement: { total_usd: 4.2, lines: { inbound_minutes: { usd: 4.2, quantity: 41 } } }, budget: { budget_usd: 50, percent: 8.4, state: 'ok' } }),
  '/api/admin/voice/providers': ok({ providers: [] }), '/api/admin/voice/quality': ok({ calls: 12, fallback_rate_pct: 0, avg_reply_ms: 900, provider_errors: 0 }),
  '/api/admin/voice/margins': ok({ total: { margin_pct: 20 } }),
};
(async () => {
  const browser = await chromium.launch();
  const failures = [];
  for (const [name, vp] of [['mobile', { width: 375, height: 800 }], ['desktop', { width: 1280, height: 800 }]]) {
    for (const lang of ['en', 'ar']) {
      const page = await (await browser.newContext({ viewport: vp })).newPage();
      await page.route('**/*', (route) => {
        const u = new URL(route.request().url());
        if (u.pathname === '/voice-agent') {
          return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="t"></head><body><div id="voice-app" class="va"></div><script>window.VOICE_BOOT={admin:true,lang:'${lang}'}</script><link rel="stylesheet" href="/assets/voice/voice-agent.css"><script src="/assets/voice/voice-agent.js"></script></body></html>` });
        }
        if (u.pathname.startsWith('/assets/voice/')) return route.fulfill({ contentType: u.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, path.basename(u.pathname))) });
        if (API[u.pathname]) return route.fulfill({ contentType: 'application/json', body: JSON.stringify(API[u.pathname]) });
        return route.fulfill({ status: 404, body: '{}' });
      });
      await page.goto('http://voice.test/voice-agent');
      await page.waitForSelector('.va-tab');
      const tabs = await page.$$('.va-tab');
      if (tabs.length !== 12) failures.push(`${name}/${lang}: expected 12 tabs, got ${tabs.length}`);
      for (let i = 0; i < tabs.length; i++) {
        await (await page.$$('.va-tab'))[i].click();
        await page.waitForFunction(() => !document.querySelector('.va-body .va-muted')?.textContent.match(/Loading|جارٍ/));
        const over = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (over) failures.push(`${name}/${lang}: horizontal page overflow on tab ${i}`);
        if (await page.$('.va-err')) failures.push(`${name}/${lang}: error shown on tab ${i}: ${await page.textContent('.va-err')}`);
      }
      if (await page.evaluate(() => window.__xss)) failures.push(`${name}/${lang}: XSS executed`);
      if (lang === 'ar' && (await page.getAttribute('#voice-app', 'dir')) !== 'rtl') failures.push('ar: not rtl');
      await page.context().close();
    }
  }
  await browser.close();
  if (failures.length) { console.error(failures.join('\n')); process.exit(1); }
  console.log('UI OK: 12 tabs, no overflow at 375px, English and Arabic, escaped output');
})();
