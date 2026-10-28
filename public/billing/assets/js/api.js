/* ============================================================
   Tourfecto Billing — API Client
   Same-origin session auth (PHP session cookie), JSON envelope:
     success: { success, data, message? }
     error:   { success:false, error, code, data? }
   MOCK mode is available only when explicitly enabled for a preview.
   Failed production requests are never converted to simulated success. Dynamic routes (/{id}) supported via
   wildcard matching so admin actions work in both modes.
   ============================================================ */

window.TourfectoAPI = (() => {
  'use strict';

  const MOCK_KEY = 'tourfecto_billing_mock';
  let mockMode = window.BILLING_FORCE_MOCK === true;

  const conf = {
    basePath: '/api',
    timeoutMs: 12000
  };

  async function request(method, path, body, query) {
    let url = conf.basePath + path;
    if (query) {
      const qs = new URLSearchParams();
      Object.entries(query).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') qs.set(k, v);
      });
      const s = qs.toString();
      if (s) url += '?' + s;
    }

    const fetchOpts = {
      method,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin' // PHP session cookie
    };
    if (body !== undefined && body !== null) {
      fetchOpts.headers['Content-Type'] = 'application/json';
      fetchOpts.body = JSON.stringify(body);
    }

    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), conf.timeoutMs);
    try {
      const res = await fetch(url, { ...fetchOpts, signal: ctrl.signal });
      let json;
      try { json = await res.json(); }
      catch (e) { throw new Error('استجابة غير صالحة من الخادم'); }

      if (!res.ok || json.success === false) {
        const err = new Error(json.error || 'حدث خطأ غير متوقع');
        err.code = json.code || res.status;
        err.payload = json.data || null;
        err.status = res.status;
        throw err;
      }
      return json;
    } finally {
      clearTimeout(t);
    }
  }

  function methodFor(path) {
    if (/\/wallet\/deposit$|\/wallet\/redeem-card$|\/wallet\/subscribe$|\/subscription\/upgrade$|\/admin\/wallet\/cards\/generate$/.test(path)) return 'POST';
    if (/\/billing-profile$/.test(path)) return 'GET';
    if (/\/wallet\/\d+\/(approve|reject)$|\/subscriptions\/\d+\/cancel$|\/usage-pricing\/\d+$/.test(path)) return 'POST';
    return 'GET';
  }

  function mockStore() {
    if (window.__mockDb) return window.__mockDb;
    window.__mockDb = (typeof window.MockData !== 'undefined') ? window.MockData.create() : {};
    return window.__mockDb;
  }

  // Mock handlers keyed by canonical path (with {id} placeholders).
  // The Proxy resolves the actual path to the closest canonical one
  // before calling, so dynamic routes share one handler.
  function mockify(canonical, actualPath) {
    const db = mockStore();
    const routeId = () => {
      const m = (actualPath || canonical).match(/(\d+)/);
      return m ? m[1] : null;
    };

    const handlers = {
      '/subscription/plans': () => ({ success: true, data: { plans: window.MockData.PLANS } }),

      '/subscription/current': () => ({
        success: true,
        data: {
          has_subscription: true,
          subscription: db.subscription,
          usage: {
            ai: { total: 300, used: db.subscription.ai_credits_used, remaining: 300 - db.subscription.ai_credits_used },
            chat: { total: 1000, used: db.subscription.chat_credits_used, remaining: 1000 - db.subscription.chat_credits_used },
            review: { total: 500, used: db.subscription.review_credits_used, remaining: 500 - db.subscription.review_credits_used },
            competitor: { total: 20, used: 6, remaining: 14 }
          },
          features: db.subscription
        }
      }),

      '/subscription/invoices': () => ({ success: true, data: { invoices: db.invoices } }),

      '/subscription/invoice/': () => {
        const id = Number(routeId());
        const inv = db.invoices.find((x) => Number(x.id) === id) || db.invoices[0];
        return { success: true, data: { invoice: inv } };
      },

      '/subscription/billing-profile': () => ({ success: true, data: { profile: db.billingProfile } }),

      '/wallet/balance': () => ({ success: true, data: { balance: db.balance, payment_info: db.payment_info } }),

      '/wallet/history': () => ({ success: true, data: { transactions: db.transactions } }),

      '/wallet/deposit': (body) => {
        db.transactions.unshift({ id: 99, user_id: 1, type: 'deposit', amount: body.amount, currency: 'USD', status: 'pending', payment_method: body.payment_method, reference_note: body.note || null, feature_key: null, created_at: new Date().toISOString().replace('T', ' ').slice(0, 19) });
        return { success: true, data: { transaction: { id: 99, status: 'pending', amount: body.amount, payment_method: body.payment_method } }, message: 'تم تسجيل طلب الإيداع - هيتراجع بعد تأكيد استلام التحويل', code: 201 };
      },

      '/wallet/redeem-card': (body) => {
        db.balance += 25;
        return { success: true, data: { value: 25, new_balance: db.balance }, message: 'تم شحن $25.00 لرصيدك بنجاح' };
      },

      '/wallet/subscribe': (body) => ({
        success: true,
        data: { success: true, subscription: db.subscription, new_balance: db.balance, charged: db.subscription.price, is_plan_change: body.plan_key !== 'pro' },
        message: 'تم تفعيل الاشتراك فورًا من رصيدك'
      }),

      '/subscription/upgrade': (body) => ({
        success: true,
        data: { success: true, subscription: db.subscription, new_balance: db.balance, charged: db.subscription.price, is_plan_change: true },
        message: 'تمت الترقية فورًا من رصيدك'
      }),

      '/admin/wallet/stats': () => ({ success: true, data: { stats: db.adminStats } }),

      '/admin/wallet/mrr-trend': (body, query) => ({
        success: true,
        data: { trend: db.mrrTrend, days: query && query.days ? query.days : 30 }
      }),

      '/admin/wallet/usage-revenue': () => ({ success: true, data: { usage_revenue: db.usageRevenue } }),

      '/admin/wallet/pending': () => ({ success: true, data: { deposits: db.pendingDeposits } }),

      '/admin/wallet/cards': () => ({ success: true, data: { cards: db.cards } }),

      '/admin/wallet/cards/generate': (body) => {
        for (let i = 0; i < body.count; i++) {
          db.cards.unshift({ id: 100 + i, code: 'TRFC-NEW' + i + '-XXXX', value: body.value, status: 'unused', batch_label: new Date().toISOString().slice(0, 16), created_by_admin_id: 1, used_by_user_id: null, used_at: null, created_at: new Date().toISOString().replace('T', ' ').slice(0, 19) });
        }
        return { success: true, data: { cards: db.cards }, message: 'تم توليد ' + body.count + ' بطاقة' };
      },

      '/admin/wallet/{id}/approve': () => {
        const id = Number(routeId());
        const dep = db.pendingDeposits.find((x) => Number(x.id) === id);
        if (dep) {
          db.pendingDeposits = db.pendingDeposits.filter((x) => Number(x.id) !== id);
          db.balance += Number(dep.amount);
        }
        return { success: true, data: {}, message: 'تم اعتماد الإيداع' };
      },

      '/admin/wallet/{id}/reject': () => {
        const id = Number(routeId());
        db.pendingDeposits = db.pendingDeposits.filter((x) => Number(x.id) !== id);
        return { success: true, data: {}, message: 'تم رفض الإيداع' };
      },

      '/admin/subscriptions/{id}/cancel': () => {
        const id = Number(routeId());
        db.adminSubscriptions.forEach((s) => { if (Number(s.id) === id) s.status = 'cancelled'; });
        return { success: true, data: {}, message: 'تم إلغاء الاشتراك' };
      },

      '/admin/wallet/usage-pricing/{id}': (body) => {
        const id = Number(routeId());
        db.usagePricing.forEach((p) => {
          if (Number(p.id) === id) { p.unit_price = body.unit_price; p.label = body.label; p.unit = body.unit; }
        });
        return { success: true, data: {}, message: 'تم حفظ التسعير' };
      },

      '/admin/wallet/settings': () => ({ success: true, data: { settings: db.walletSettings } }),

      '/admin/wallet/usage-pricing': () => ({ success: true, data: { pricing: db.usagePricing } }),

      '/admin/subscriptions': () => ({ success: true, data: { subscriptions: db.adminSubscriptions } })
    };

    return (body, query) => (handlers[canonical] || (() => ({ success: true, data: {} })))(body, query);
  }

  function buildClient() {
    const canonicalPaths = [
      '/subscription/plans',
      '/subscription/current',
      '/subscription/invoices',
      '/subscription/invoice/',
      '/subscription/billing-profile',
      '/wallet/balance',
      '/wallet/history',
      '/wallet/deposit',
      '/wallet/redeem-card',
      '/wallet/subscribe',
      '/subscription/upgrade',
      '/admin/wallet/stats',
      '/admin/wallet/mrr-trend',
      '/admin/wallet/usage-revenue',
      '/admin/wallet/pending',
      '/admin/wallet/cards',
      '/admin/wallet/cards/generate',
      '/admin/wallet/settings',
      '/admin/wallet/usage-pricing',
      '/admin/subscriptions',
      '/admin/wallet/{id}/approve',
      '/admin/wallet/{id}/reject',
      '/admin/subscriptions/{id}/cancel',
      '/admin/wallet/usage-pricing/{id}'
    ];
    const exactMock = {};
    canonicalPaths.forEach((p) => { exactMock[p] = mockify(p, p); });

    const wildcardPatterns = [
      { re: /^\/admin\/wallet\/\d+\/(approve|reject)$/, canonical: '/admin/wallet/{id}/approve' },
      { re: /^\/admin\/subscriptions\/\d+\/cancel$/, canonical: '/admin/subscriptions/{id}/cancel' },
      { re: /^\/admin\/wallet\/usage-pricing\/\d+$/, canonical: '/admin/wallet/usage-pricing/{id}' },
      { re: /^\/subscription\/invoice\/\d+$/, canonical: '/subscription/invoice/' },
      { re: /^\/subscription\/billing-profile$/, canonical: '/subscription/billing-profile' }
    ];

    function resolve(prop) {
      if (exactMock[prop]) return { canonical: prop, mock: exactMock[prop] };
      for (const w of wildcardPatterns) {
        if (w.re.test(prop)) return { canonical: w.canonical, mock: mockify(w.canonical, prop) };
      }
      return null;
    }

    function makeCall(method, path, mockFn) {
      return async (body, query) => {
        if (mockMode) return mockFn(body, query);
        try {
          return await request(method, path, body, query);
        } catch (err) {
          throw err; // A failed real request remains failed; demo mode must be explicitly selected.
        }
      };
    }

    return new Proxy({}, {
      get: (target, prop) => {
        if (typeof prop !== 'string') return undefined;
        const r = resolve(prop);
        if (!r) return undefined;
        return makeCall(methodFor(prop), prop, r.mock);
      }
    });
  }

  return { client: buildClient(), request, setMock: (v) => { mockMode = v; }, isMock: () => mockMode };
})();
