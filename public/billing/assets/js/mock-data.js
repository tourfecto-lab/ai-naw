/* ============================================================
   Tourfecto Billing — Mock Data
   Mirrors the real API shapes exactly (see API report):
     plans, current, wallet balance/history, invoices, admin stats,
     mrr trend, usage revenue, pending deposits, cards, subscriptions
   ============================================================ */

window.MockData = (() => {
  'use strict';

  const PLANS = {
    starter: {
      name: 'Starter',
      price_monthly: 29,
      price_yearly: 290,
      currency: 'USD',
      currency_symbol: '$',
      features: {
        ai_analysis: 50,
        competitor_analysis: false,
        chat_credits: 200,
        review_credits: 100,
        multiple_websites: 1,
        auto_pilot: false,
        advanced_analytics: false
      }
    },
    pro: {
      name: 'Professional',
      price_monthly: 79,
      price_yearly: 790,
      currency: 'USD',
      currency_symbol: '$',
      features: {
        ai_analysis: 300,
        competitor_analysis: true,
        chat_credits: 1000,
        review_credits: 500,
        multiple_websites: 5,
        auto_pilot: true,
        advanced_analytics: true
      }
    },
    enterprise: {
      name: 'Enterprise',
      price_monthly: 199,
      price_yearly: 1990,
      currency: 'USD',
      currency_symbol: '$',
      features: {
        ai_analysis: 9999,
        competitor_analysis: true,
        chat_credits: 9999,
        review_credits: 9999,
        multiple_websites: 99,
        auto_pilot: true,
        advanced_analytics: true
      }
    }
  };

  function create() {
    const now = Date.now();
    const day = 86400000;

    const db = {
      user: { id: 1, email: 'demo@tourfecto.ai', name: 'أحمد محمد', company_name: 'Tourfecto Travel', role: 'admin' },
      balance: 245.75,
      subscription: {
        id: 7,
        plan_name: 'Professional',
        plan_code: 'pro',
        plan_type: 'monthly',
        price: 79.00,
        currency: 'USD',
        currency_symbol: '$',
        status: 'active',
        cancel_at_period_end: 0,
        current_period_start: fmt(new Date(now - 12 * day)),
        current_period_end: fmt(new Date(now + 18 * day)),
        expiry_date: fmt(new Date(now + 18 * day)),
        ai_credits: 300, ai_credits_used: 142,
        chat_credits: 1000, chat_credits_used: 385,
        review_credits: 500, review_credits_used: 73,
        competitor_analysis_limit: 20, competitor_analysis_used: 6,
        multiple_websites: 5, auto_pilot: true
      },
      transactions: [
        tx(1, 'deposit', 200, 'completed', 'iban', 'إيداع مصرفي', now - 40 * day),
        tx(2, 'subscription_charge', -79, 'completed', 'wallet', 'اشتراك شهري - Professional', now - 40 * day),
        tx(3, 'card_redemption', 50, 'completed', 'recharge_card', 'شحن بطاقة TRFC-XXXX', now - 21 * day),
        tx(4, 'subscription_charge', -79, 'completed', 'wallet', 'اشتراك شهري - Professional', now - 10 * day),
        tx(5, 'subscription_charge', -3.5, 'completed', 'wallet', 'تحليل AI إضافي (feature_key: ai_analysis)', now - 3 * day),
        tx(6, 'deposit', 150, 'completed', 'paypal', 'إيداع PayPal', now - 2 * day),
        tx(7, 'subscription_charge', -12.25, 'completed', 'wallet', 'رسوم استخدام إضافية', now - 1 * day)
      ],
      invoices: [
        inv(101, 'INV-20260707-001001', 'Professional', 'monthly', 79, 'paid', now - 40 * day),
        inv(102, 'INV-20260727-001002', 'Professional', 'monthly', 79, 'paid', now - 20 * day),
        inv(103, 'INV-20260807-001003', 'Professional', 'monthly', 79, 'paid', now - 10 * day),
        inv(104, 'INV-20260815-001004', 'Professional', 'monthly', 3.5, 'issued', now - 3 * day)
      ],
      billingProfile: {
        id: 1, legal_name: 'أحمد محمد', billing_email: 'demo@tourfecto.ai',
        address_line1: 'شارع النيل', address_line2: '', city: 'القاهرة',
        country: 'EG', tax_id: 'EG-123456'
      },
      payment_info: {
        iban: 'EG030002003456789012345678902', iban_bank_name: 'البنك الأهلي',
        iban_account_name: 'Tourfecto Travel', paypal_email: 'payments@tourfecto.ai',
        whatsapp_number: '+201000000000'
      },
      adminStats: {
        deposits_this_month: 1820.00, deposits_this_month_count: 12,
        pending_count: 3, pending_total: 620.00,
        total_customer_balances: 15240.50,
        usage_charges_this_month: 348.75, usage_charges_this_month_count: 47,
        mrr: 8540.00, arr: 102480.00,
        active_subscriptions: 86, new_subscriptions_this_month: 11,
        cancelled_this_month: 4, churn_rate_this_month: 4.44,
        average_revenue_per_subscription: 99.30
      },
      mrrTrend: buildTrend(30),
      usageRevenue: {
        year: 2026, month: 8, total_revenue: 348.75, total_usage_count: 47,
        breakdown: {
          ai_analysis: { usage_count: 22, revenue: 165.00 },
          chat_ai_message: { usage_count: 15, revenue: 96.75 },
          review_reply: { usage_count: 8, revenue: 57.50 },
          competitor_analysis: { usage_count: 2, revenue: 29.50 }
        }
      },
      pendingDeposits: [
        dep(11, 'sara@travel.com', 'Sara Travel', 250, 'iban', 'تحويل بنكي', now - 1 * day),
        dep(12, 'omar@tours.net', 'Omar Tours', 120, 'paypal', 'PayPal', now - 5 * 3600000),
        dep(13, 'nour@trips.io', 'Nour Trips', 250, 'iban', 'تحويل', now - 2 * 3600000)
      ],
      cards: [
        card(1, 'TRFC-A1B2-C3D4-E5F6', 25, 'unused', now - 6 * day),
        card(2, 'TRFC-7G8H-9J0K-L1M2', 50, 'unused', now - 6 * day),
        card(3, 'TRFC-N3P4-Q5R6-S7T8', 25, 'used', now - 6 * day, now - 2 * day),
        card(4, 'TRFC-U9V0-W1X2-Y3Z4', 100, 'unused', now - 4 * day)
      ],
      adminSubscriptions: [
        sub(1, 'sara@travel.com', 'Starter', 'monthly', 29, 'active', now + 12 * day),
        sub(2, 'omar@tours.net', 'Professional', 'yearly', 790, 'active', now + 200 * day),
        sub(3, 'nour@trips.io', 'Professional', 'monthly', 79, 'past_due', now - 3 * day),
        sub(4, 'huda@fly.com', 'Starter', 'monthly', 29, 'active', now + 5 * day),
        sub(5, 'khalid@go.io', 'Enterprise', 'monthly', 199, 'active', now + 25 * day),
        sub(6, 'demo@tourfecto.ai', 'Professional', 'monthly', 79, 'active', now + 18 * day)
      ],
      walletSettings: {
        min_deposit: 10, max_deposit: 10000, allow_card_redemption: true,
        auto_charge_usage: true, usage_auto_charge_threshold: 5.00,
        allow_prorated_downgrade_credit: false, currency: 'USD'
      },
      usagePricing: [
        { id: 1, feature_key: 'ai_analysis', label: 'تحليل AI', unit_price: 7.50, unit: 'تحليل' },
        { id: 2, feature_key: 'chat_ai_message', label: 'رسالة شات', unit_price: 0.05, unit: 'رسالة' },
        { id: 3, feature_key: 'review_reply', label: 'رد تلقائي', unit_price: 0.25, unit: 'رد' },
        { id: 4, feature_key: 'competitor_analysis', label: 'تحليل منافس', unit_price: 14.75, unit: 'تحليل' }
      ]
    };

    return db;
  }

  // ---------- helpers ----------
  function fmt(d) { return d.toISOString().replace('T', ' ').slice(0, 19); }
  function ago(days, hours) {
    return fmt(new Date(Date.now() - days * 86400000 - (hours || 0) * 3600000));
  }
  function tx(id, type, amount, status, payment_method, reference_note, ts) {
    return { id, user_id: 1, type, amount, currency: 'USD', status, payment_method, reference_note, admin_note: null, related_subscription_plan: null, feature_key: null, idempotency_key: null, approved_by: null, approved_at: null, created_at: fmt(new Date(ts)), updated_at: fmt(new Date(ts)) };
  }
  function inv(id, number, plan, type, amount, status, ts) {
    return { id, user_id: 1, invoice_number: number, plan_name: plan, plan_type: type, amount, subtotal: amount, tax_country: null, tax_type: null, tax_amount: null, currency: 'USD', status, payment_method: 'wallet', transaction_id: 'wallet_tx_' + id, items: JSON.stringify([{ description: plan + ' - ' + (type === 'yearly' ? 'سنوي' : 'شهري'), amount, quantity: 1 }]), due_date: fmt(new Date(ts)).slice(0, 10), paid_at: status === 'paid' ? fmt(new Date(ts)) : null, created_at: fmt(new Date(ts)), updated_at: fmt(new Date(ts)) };
  }
  function dep(id, email, company, amount, method, note, ts) {
    return { id, user_id: id, type: 'deposit', amount, currency: 'USD', status: 'pending', payment_method: method, reference_note: note, user_email: email, user_company: company, created_at: fmt(new Date(ts)) };
  }
  function card(id, code, value, status, created, used) {
    return { id, code, value, status, batch_label: '2026-08-10 10:00', created_by_admin_id: 1, used_by_user_id: status === 'used' ? 3 : null, used_at: used ? fmt(new Date(used)) : null, created_at: fmt(new Date(created)), updated_at: fmt(new Date(created)) };
  }
  function sub(id, email, plan, type, price, status, end) {
    return { id, user_email: email, plan_name: plan, plan_type: type, price, currency: 'USD', status, current_period_end: fmt(new Date(end)), created_at: ago(90), company: 'Company ' + id };
  }

  function buildTrend(days) {
    const out = [];
    let mrr = 7200;
    for (let i = days; i >= 0; i--) {
      const d = new Date(Date.now() - i * 86400000);
      mrr = Math.max(500, mrr + (Math.random() - 0.35) * 160);
      out.push({ snapshot_date: d.toISOString().slice(0, 10), mrr: Math.round(mrr * 100) / 100, arr: Math.round(mrr * 12 * 100) / 100, active_subscriptions: 60 + Math.round((days - i) * 0.9) });
    }
    return out;
  }

  return { create, PLANS };
})();
