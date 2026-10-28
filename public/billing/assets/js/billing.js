/* ============================================================
   Tourfecto Billing — Customer SPA logic
   ============================================================ */

(() => {
  'use strict';

  const api = window.TourfectoAPI.client;
  const { money, moneySigned, num, date, dateTime, timeAgo, pill, toastSuccess, toastError, toastInfo, setLoading, openModal, closeModal, confirmDialog, esc, html } = UI;

  const state = {
    view: 'overview',
    cycle: 'monthly',
    plans: null,
    current: null,
    wallet: null,
    invoices: null,
    profile: null,
    transactions: null,
    loading: false
  };

  // ---------- Icon injection ----------
  document.querySelectorAll('[data-icon]').forEach((el) => {
    el.innerHTML = ICONS[el.dataset.icon] || '';
  });

  // ---------- Navigation ----------
  function switchView(view) {
    state.view = view;
    document.querySelectorAll('.view').forEach((v) => v.hidden = v.id !== 'view-' + view);
    document.querySelectorAll('.nav-item').forEach((b) => {
      b.classList.toggle('active', b.dataset.view === view);
    });
    const titles = { overview: 'نظرة عامة', plans: 'الباقات', wallet: 'المحفظة', invoices: 'الفواتير', profile: 'بيانات الفوترة' };
    document.getElementById('pageTitle').textContent = titles[view];
    document.getElementById('sidebar').classList.remove('open');
    document.body.classList.remove('sidebar-open');
    renderView(view);
  }
  document.querySelectorAll('.nav-item').forEach((b) => b.addEventListener('click', () => switchView(b.dataset.view)));
  document.getElementById('menuToggle').addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
  document.getElementById('sidebarBackdrop').addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  document.getElementById('refreshBtn').addEventListener('click', () => { toastInfo('جارٍ التحديث', ''); loadAll(true); });

  // ---------- Data loading ----------
  async function loadAll(silent) {
    if (!silent) showSkeleton();
    try {
      const [plans, current, wallet, invoices, profile] = await Promise.all([
        api['/subscription/plans'](),
        api['/subscription/current'](),
        api['/wallet/balance'](),
        api['/subscription/invoices'](),
        api['/subscription/billing-profile']()
      ]);
      state.plans = plans.data.plans || {};
      state.current = current.data;
      state.wallet = wallet.data;
      state.invoices = invoices.data.invoices || [];
      state.profile = profile.data.profile || null;

      // wallet history (separate call — only loaded when needed by wallet view)
      try {
        const h = await api['/wallet/history']();
        state.transactions = h.data.transactions || [];
      } catch (e) { state.transactions = []; }

      if (TourfectoAPI.isMock()) {
        document.getElementById('mockBadge').hidden = false;
      }
      renderView(state.view);
      renderSidebarUser();
    } catch (err) {
      toastError('تعذر تحميل البيانات', err.message);
    }
  }

  function showSkeleton() {
    const targets = ['overviewBody', 'walletBody', 'invoicesBody', 'profileBody', 'plansGrid'];
    targets.forEach((id) => {
      const el = document.getElementById(id);
      if (el && state.view === id.replace(/Body|Grid/, '')) {
        el.innerHTML = '';
        for (let i = 0; i < 3; i++) {
          const sk = document.createElement('div');
          sk.className = 'card skeleton';
          sk.style.height = '120px';
          sk.style.marginBottom = '16px';
          el.appendChild(sk);
        }
      }
    });
  }

  // ---------- Renderers ----------
  function renderView(view) {
    switch (view) {
      case 'overview': renderOverview(); break;
      case 'plans': renderPlans(); break;
      case 'wallet': renderWallet(); break;
      case 'invoices': renderInvoices(); break;
      case 'profile': renderProfile(); break;
    }
  }

  function renderSidebarUser() {
    const el = document.getElementById('sidebarUser');
    if (state.current && state.current.subscription) {
      const sub = state.current.subscription;
      el.innerHTML =
        '<div class="flex items-center gap-2 mb-2">' +
        '<div class="avatar">T</div>' +
        '<div style="min-width:0">' +
        '<div style="font-weight:600;font-size:13px;color:var(--text)">' + esc(sub.plan_name || '') + '</div>' +
        '<div style="font-size:11px;color:var(--text-muted)" class="mono">' + esc(money(state.wallet ? state.wallet.balance : 0, sub.currency_symbol)) + '</div>' +
        '</div></div>';
    } else {
      el.innerHTML = '<div style="font-size:12px">لا يوجد اشتراك نشط</div>';
    }
  }

  // ===== Overview =====
  function renderOverview() {
    const body = document.getElementById('overviewBody');
    const cur = state.current;
    if (!cur || !cur.has_subscription || !cur.subscription) {
      body.innerHTML =
        '<div class="card"><div class="empty">' + ICONS.sparkles +
        '<h3>لا يوجد اشتراك نشط</h3><p>ابدأ باختيار باقة تناسب أعمالك، ويتم التفعيل فورًا من رصيد محفظتك.</p>' +
        '<button class="btn btn-primary mt-4" onclick="window.__switchView(\'plans\')">استعرض الباقات</button></div></div>';
      return;
    }
    const sub = cur.subscription;
    const usage = cur.usage || {};
    const usageGroups = [
      { key: 'ai', name: 'تحليلات AI', icon: 'sparkles' },
      { key: 'chat', name: 'رسائل الشات', icon: 'send' },
      { key: 'review', name: 'ردود المراجعات', icon: 'receipt' },
      { key: 'competitor', name: 'تحليل المنافسين', icon: 'globe' }
    ];
    let meters = '';
    usageGroups.forEach((g) => {
      const u = usage[g.key] || { total: 0, used: 0, remaining: 0 };
      const pct = u.total ? Math.round((u.used / u.total) * 100) : 0;
      const cls = pct >= 90 ? 'danger' : pct >= 70 ? 'warn' : '';
      meters +=
        '<div class="meter"><div class="meter-head"><span class="meter-name">' + g.name + '</span>' +
        '<span class="meter-val" dir="ltr">' + num(u.used) + ' / ' + num(u.total) + '</span></div>' +
        '<div class="meter-bar"><div class="meter-fill ' + cls + '" style="width:' + Math.min(pct, 100) + '%"></div></div></div>';
    });

    const statusCls = sub.cancel_at_period_end == 1 ? 'cancelled' : sub.status;
    const planIcon = sub.plan_name && /enterprise/i.test(sub.plan_name) ? 'globe' : (/pro/i.test(sub.plan_name) ? 'zap' : 'sparkles');

    body.innerHTML =
      '<div class="grid grid-2">' +
      '<div class="card">' +
      '<div class="card-title">الخطة الحالية</div>' +
      '<div class="plan-summary mt-2">' +
      '<div class="plan-icon" style="background:linear-gradient(135deg,rgba(37,99,235,0.2),rgba(5,150,105,0.2));color:var(--info)">' + ICONS[planIcon] + '</div>' +
      '<div style="flex:1;min-width:0">' +
      '<div class="flex items-center gap-2 wrap">' +
      '<div style="font-size:19px;font-weight:700">' + esc(sub.plan_name) + '</div>' +
      pill(statusCls === 'active' && sub.cancel_at_period_end == 1 ? 'cancelled' : sub.status) +
      '</div>' +
      '<div class="fs-13 text-muted mt-1">' + esc(sub.plan_type === 'yearly' ? 'فوترة سنوية' : 'فوترة شهرية') +
      ' · ' + '<span class="mono" dir="ltr">' + esc(money(sub.price, sub.currency_symbol)) + '/' + (sub.plan_type === 'yearly' ? 'سنة' : 'شهر') + '</span></div>' +
      '<div class="fs-13 mt-1">' + (sub.cancel_at_period_end == 1 ? '<span class="text-amber">ينتهي في نهاية الفترة الحالية (' + esc(date(sub.expiry_date)) + ')</span>' : 'يجدد تلقائيًا في ' + esc(date(sub.expiry_date))) + '</div>' +
      '</div></div>' +
      '<div class="flex gap-2 wrap mt-4">' +
      '<button class="btn btn-primary btn-sm" onclick="window.__switchView(\'plans\')">تغيير الباقة</button>' +
      (sub.cancel_at_period_end == 1
        ? '<button class="btn btn-accent btn-sm" onclick="window.__renewNow()">إلغاء الإلغاء والتجديد</button>'
        : '<button class="btn btn-ghost btn-sm" onclick="window.__cancelPlan()">إلغاء الاشتراك</button>') +
      '</div>' +
      '</div>' +

      '<div class="card">' +
      '<div class="card-title">رصيد المحفظة</div>' +
      '<div class="mono" style="font-size:34px;font-weight:800;letter-spacing:-1px;margin-top:8px" dir="ltr">' + esc(money(state.wallet ? state.wallet.balance : 0, sub.currency_symbol)) + '</div>' +
      '<div class="fs-13 text-muted mt-1">متاح لدفع الاشتراكات ورسوم الاستخدام</div>' +
      '<div class="flex gap-2 wrap mt-4">' +
      '<button class="btn btn-accent btn-sm" onclick="window.__openDeposit()">شحن الرصيد</button>' +
      '<button class="btn btn-ghost btn-sm" onclick="window.__openRedeem()">استخدام بطاقة</button>' +
      '</div>' +
      '<div class="divider"></div>' +
      '<div class="card-title" style="font-size:14px">استهلاك الفترة الحالية</div>' +
      '<div class="mt-3">' + meters + '</div>' +
      '</div>' +
      '</div>';
  }

  // ===== Plans =====
  function renderPlans() {
    const grid = document.getElementById('plansGrid');
    const note = document.getElementById('planWalletNote');
    const plans = state.plans;
    const balance = state.wallet ? state.wallet.balance : 0;

    note.innerHTML = 'رصيدك الحالي: <span class="text-green mono" dir="ltr">' + esc(money(balance, '$')) + '</span>';

    const keys = Object.keys(plans);
    if (!keys.length) {
      grid.innerHTML = '<div class="card"><div class="empty"><h3>لا توجد باقات متاحة</h3></div></div>';
      return;
    }

    let html = '';
    const featured = keys.find(k => /pro/i.test(k)) || keys[Math.floor(keys.length / 2)];
    const currentPlanKey = state.current && state.current.subscription ? (state.current.subscription.plan_code || state.current.subscription.plan_name || '').toLowerCase() : null;

    keys.forEach((key) => {
      const p = plans[key];
      const price = state.cycle === 'yearly' ? p.price_yearly : p.price_monthly;
      const features = p.features || {};
      const isCurrent = currentPlanKey && currentPlanKey === key && state.current.subscription.plan_type === state.cycle;
      const isFeatured = key === featured;
      const isUpgrade = currentPlanKey && isHigherPlan(currentPlanKey, key);

      const featList = [
        features.multiple_websites !== undefined && features.multiple_websites !== false
          ? { text: 'مواقع: ' + (features.multiple_websites === 99 ? 'غير محدود' : features.multiple_websites), on: true }
          : { text: 'موقع واحد', on: features.multiple_websites !== 0 },
        { text: 'تحليلات AI: ' + (features.ai_analysis === 9999 ? 'غير محدودة' : features.ai_analysis + '/شهر'), on: true },
        { text: 'رسائل شات: ' + (features.chat_credits === 9999 ? 'غير محدودة' : features.chat_credits + '/شهر'), on: true },
        { text: 'ردود مراجعات: ' + (features.review_credits === 9999 ? 'غير محدودة' : features.review_credits + '/شهر'), on: true },
        { text: 'تحليل منافسين', on: !!features.competitor_analysis },
        { text: 'وضع Auto-Pilot', on: !!features.auto_pilot },
        { text: 'تحليلات متقدمة', on: !!features.advanced_analytics }
      ];

      html += '<div class="pricing-card' + (isFeatured ? ' featured' : '') + '">' +
        '<div class="pricing-name">' + esc(p.name) + '</div>' +
        '<div class="pricing-desc">' + planDesc(key) + '</div>' +
        '<div class="pricing-price"><span class="currency">' + esc(p.currency_symbol) + '</span><span class="amount" dir="ltr">' + price + '</span>' +
        '<span class="period"> / ' + (state.cycle === 'yearly' ? 'سنة' : 'شهر') + '</span></div>' +
        '<ul class="pricing-features">' + featList.map(f => '<li class="' + (f.on ? '' : 'off') + '">' + ICONS.check + f.text + '</li>').join('') + '</ul>' +
        (isCurrent
          ? '<button class="btn btn-ghost btn-block" disabled>باقتك الحالية</button>'
          : '<button class="btn ' + (isUpgrade ? 'btn-primary' : isFeatured ? 'btn-accent' : 'btn-ghost') + ' btn-block" data-subscribe="' + key + '">' +
            (isUpgrade ? 'الترقية الآن' : 'الاشتراك') + '</button>') +
        '</div>';
    });
    grid.innerHTML = html;

    grid.querySelectorAll('[data-subscribe]').forEach((btn) => {
      btn.addEventListener('click', () => subscribePlan(btn.dataset.subscribe));
    });
  }

  function planDesc(key) {
    return { starter: 'للفرق الصغيرة والبداية', pro: 'الأكثر توازنًا لنمو أعمالك', enterprise: 'حل شامل للمؤسسات الكبيرة' }[key] || '';
  }

  function isHigherPlan(currentKey, targetKey) {
    const order = Object.keys(state.plans || {});
    return order.indexOf(targetKey) > order.indexOf(currentKey);
  }

  function subscribePlan(key) {
    const p = state.plans[key];
    const balance = state.wallet ? state.wallet.balance : 0;
    const price = state.cycle === 'yearly' ? p.price_yearly : p.price_monthly;
    const cur = state.current && state.current.subscription;

    // For mock/demo: plan key for upgrade endpoint is lowercase name
    const payload = { plan_key: key, plan_type: state.cycle, idempotency_key: uuid() };

    if (cur && cur.plan_code === key) {
      toastInfo('نفس الباقة', 'أنت مشترك بالفعل في هذه الباقة.'); return;
    }

    const isUpgrade = cur && isHigherPlan((cur.plan_code || cur.plan_name || '').toLowerCase(), key);
    let msg = 'سيتم خصم <b class="mono" dir="ltr">' + money(price) + '</b> ' + (state.cycle === 'yearly' ? 'سنويًا' : 'شهريًا') + ' من رصيد محفظتك فورًا.';
    if (isUpgrade) msg = 'سيتم حساب فرق الترقية فقط من رصيدك وتحديث باقتك فورًا.';

    const body =
      '<div class="flex items-center gap-3 mb-4" style="padding:14px;background:var(--bg);border:1px solid var(--border);border-radius:12px">' +
      '<div class="plan-icon" style="background:rgba(5,150,105,0.15);color:var(--color-success)">' + ICONS.plans + '</div>' +
      '<div><div style="font-weight:700">' + esc(p.name) + '</div>' +
      '<div class="fs-13 text-muted mono" dir="ltr">' + money(price) + ' / ' + (state.cycle === 'yearly' ? 'سنة' : 'شهر') + '</div></div>' +
      '</div>' +
      '<p style="color:var(--text-secondary);font-size:14px">' + msg + '</p>' +
      '<div class="mt-4 flex items-center justify-between" style="padding:12px 14px;background:var(--bg);border:1px solid var(--border);border-radius:12px">' +
      '<span class="fs-13 text-muted">رصيدك الحالي</span><span class="mono" dir="ltr">' + money(balance) + '</span></div>';

    const modal = openModal(
      '<div class="modal-header"><div class="modal-title">تأكيد الاشتراك</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' + body +
      (balance < price ? '<div class="mt-3" style="padding:12px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:12px;font-size:13px;color:var(--color-warning)">' +
        'رصيدك غير كافٍ — تحتاج <b class="mono">' + money(price - balance) + '</b> إضافية. يمكنك شحن الرصيد أولًا.</div>' : '') +
      '</div>' +
      '<div class="modal-footer">' +
      '<button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn btn-primary" id="confirmSubscribe">تأكيد الاشتراك</button>' +
      '</div>'
    );
    modal.querySelector('#confirmSubscribe').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      setLoading(btn, true);
      try {
        const res = await api['/wallet/subscribe'](payload);
        toastSuccess('تم تفعيل الاشتراك', res.data.success ? (res.data.charged ? 'تم خصم ' + money(res.data.charged) + ' من رصيدك.' : 'تم تفعيل الاشتراك بنجاح.') : (res.message || ''));
        closeModal();
        await loadAll(true);
      } catch (err) {
        if (err.code === 402 && err.payload) {
          closeModal();
          toastError('رصيد غير كافٍ', 'تحتاج ' + money(err.payload.shortfall || 0) + ' إضافية لإتمام هذه العملية.');
          openDeposit(err.payload.shortfall);
        } else {
          setLoading(btn, false);
          toastError('فشل الاشتراك', err.message);
        }
      }
    });
  }

  // ===== Wallet =====
  function renderWallet() {
    const body = document.getElementById('walletBody');
    const balance = state.wallet ? state.wallet.balance : 0;
    const pinfo = (state.wallet && state.wallet.payment_info) || {};
    const txs = state.transactions || [];

    let txRows = '';
    if (!txs.length) {
      txRows = '<tr><td colspan="4"><div class="empty" style="padding:24px"><h3>لا توجد حركات بعد</h3><p>ستظهر حركات المحفظة هنا</p></div></td></tr>';
    } else {
      txs.slice(0, 15).forEach((t) => {
        const cls = t.amount > 0 ? 'pos' : 'neg';
        txRows += '<tr>' +
          '<td><div style="font-weight:600;color:var(--text)">' + esc(t.reference_note || t.type) + '</div>' +
          '<div class="fs-12 text-muted mt-1" dir="ltr">' + esc(t.idempotency_key ? t.idempotency_key : ('#' + t.id + ' · ' + t.payment_method)) + '</div></td>' +
          '<td>' + pill(t.type) + '</td>' +
          '<td><span class="pill ' + (t.status === 'completed' ? 'green' : t.status === 'pending' ? 'amber' : 'red') + '">' + statusAr(t.status) + '</span></td>' +
          '<td class="num"><span class="amount ' + cls + '" dir="ltr">' + moneySigned(t.amount) + '</span></td>' +
          '<td class="fs-12 text-muted">' + timeAgo(t.created_at) + '</td>' +
          '</tr>';
      });
    }

    body.innerHTML =
      '<div class="grid grid-2">' +
      '<div class="balance-hero">' +
      '<div class="fs-13" style="color:var(--text-secondary);font-weight:600;letter-spacing:0.4px">رصيد المحفظة</div>' +
      '<div class="amount mt-1" dir="ltr">' + money(balance) + '</div>' +
      '<div class="fs-13 text-muted mt-2">مستحق الإضافة والمتاح للاشتراكات والاستخدام</div>' +
      '<div class="flex gap-2 wrap mt-5">' +
      '<button class="btn btn-accent" onclick="window.__openDeposit()">' + ICONS.plus + ' شحن الرصيد</button>' +
      '<button class="btn btn-ghost" onclick="window.__openRedeem()">' + ICONS.card + ' استرداد بطاقة</button>' +
      '</div></div>' +

      '<div class="card">' +
      '<div class="card-title">بيانات الدفع</div>' +
      '<div class="fs-13 text-muted">طرق الإيداع المتاحة في هذه النسخة</div>' +
      '<div class="mt-4">' +
      depositRow('الشحن المصرفي (IBAN)', pinfo.iban, pinfo.iban_bank_name ? pinfo.iban_bank_name + ' · ' + (pinfo.iban_account_name || '') : '') +
      depositRow('PayPal', pinfo.paypal_email || 'غير مضبوط', '') +
      depositRow('واتساب الدعم', pinfo.whatsapp_number || 'غير مضبوط', 'للتواصل عند الحاجة') +
      '</div></div></div>' +

      '<div class="card mt-5">' +
      '<div class="flex items-center justify-between wrap">' +
      '<div><div class="card-title" style="margin:0">سجل الحركات</div></div>' +
      '<button class="btn btn-ghost btn-sm" onclick="window.__openDeposit()">' + ICONS.plus + ' إيداع جديد</button>' +
      '</div>' +
      '<div class="table-wrap mt-4"><table class="table">' +
      '<thead><tr><th>الوصف</th><th>النوع</th><th>الحالة</th><th>المبلغ</th><th>الوقت</th></tr></thead>' +
      '<tbody>' + txRows + '</tbody></table></div>' +
      '</div>';

    function depositRow(label, value, hint) {
      return '<div class="flex items-center justify-between" style="padding:10px 0;border-bottom:1px solid var(--border)">' +
        '<div><div class="fs-13" style="color:var(--text);font-weight:500">' + label + '</div>' +
        (hint ? '<div class="fs-12 text-muted">' + hint + '</div>' : '') + '</div>' +
        '<div class="fs-13 mono text-secondary" dir="ltr" style="max-width:200px;overflow:hidden;text-overflow:ellipsis">' + esc(value) + '</div>' +
        '</div>';
    }
  }

  function statusAr(s) {
    return { completed: 'مكتمل', pending: 'قيد الانتظار', rejected: 'مرفوض', paid: 'مدفوع', failed: 'فاشل', issued: 'مُصدر', overdue: 'متأخرة', refunded: 'مُسترجع', active: 'نشط', cancelled: 'ملغي' }[s] || s;
  }

  // ===== Invoices =====
  function renderInvoices() {
    const body = document.getElementById('invoicesBody');
    const invoices = state.invoices || [];
    if (!invoices.length) {
      body.innerHTML = '<div class="card"><div class="empty">' + ICONS.receipt + '<h3>لا توجد فواتير بعد</h3><p>ستظهر فواتير اشتراكاتك هنا فور إصدارها</p></div></div>';
      return;
    }
    let rows = '';
    invoices.forEach((inv) => {
      const statusKey = inv.status;
      rows += '<tr>' +
        '<td><div style="font-weight:600;color:var(--text)" dir="ltr">' + esc(inv.invoice_number) + '</div>' +
        '<div class="fs-12 text-muted mt-1">' + esc(inv.plan_name) + ' · ' + (inv.plan_type === 'yearly' ? 'سنوي' : 'شهري') + '</div></td>' +
        '<td>' + pill(inv.status) + '</td>' +
        '<td class="num"><span class="amount" dir="ltr">' + money(inv.amount, inv.currency === 'USD' ? '$' : inv.currency) + '</span></td>' +
        '<td class="fs-12 text-muted">' + date(inv.created_at) + '</td>' +
        '<td><button class="btn btn-ghost btn-sm" data-invoice="' + inv.id + '">' + ICONS.external + ' عرض</button></td>' +
        '</tr>';
    });
    body.innerHTML =
      '<div class="card">' +
      '<div class="table-wrap"><table class="table">' +
      '<thead><tr><th>رقم الفاتورة</th><th>الحالة</th><th>المبلغ</th><th>التاريخ</th><th></th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>' +
      '</div>';
    body.querySelectorAll('[data-invoice]').forEach((b) => b.addEventListener('click', () => showInvoice(Number(b.dataset.invoice))));
  }

  function showInvoice(id) {
    const inv = (state.invoices || []).find(i => Number(i.id) === id);
    if (!inv) return;
    let itemsHtml = '';
    let items = [];
    try { items = JSON.parse(inv.items || '[]'); } catch (e) { items = []; }
    if (!items.length) items = [{ description: inv.plan_name + ' - ' + (inv.plan_type === 'yearly' ? 'سنوي' : 'شهري'), amount: inv.amount, quantity: 1 }];
    items.forEach((it) => {
      itemsHtml += '<tr><td>' + esc(it.description) + '</td><td class="num">' + (it.quantity || 1) + '</td><td class="num"><span class="amount" dir="ltr">' + money(it.amount) + '</span></td></tr>';
    });
    openModal(
      '<div class="modal-header"><div class="modal-title">' + esc(inv.invoice_number) + '</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' +
      '<div class="flex items-center justify-between mb-4">' +
      '<div><div class="fs-13 text-muted">الحالة</div>' + pill(inv.status) + '</div>' +
      '<div class="text-left"><div class="fs-13 text-muted">التاريخ</div><div class="fs-14">' + date(inv.created_at) + '</div></div>' +
      '</div>' +
      '<div class="table-wrap"><table class="table" style="min-width:0">' +
      '<thead><tr><th>الوصف</th><th>الكمية</th><th>السعر</th></tr></thead>' +
      '<tbody>' + itemsHtml + '</tbody>' +
      '</table></div>' +
      '<div class="flex justify-between mt-4" style="padding-top:16px;border-top:1px solid var(--border)">' +
      '<span class="fs-14" style="font-weight:700">الإجمالي</span><span class="mono" dir="ltr" style="font-weight:800;font-size:18px">' + money(inv.amount, inv.currency === 'USD' ? '$' : inv.currency) + '</span></div>' +
      (inv.tax_amount ? '<div class="fs-12 text-muted mt-2">تشمل ضريبة ' + esc(inv.tax_type || '') + ' (' + money(inv.tax_amount) + ')</div>' : '') +
      '</div>' +
      '<div class="modal-footer"><button class="btn btn-ghost" data-close>إغلاق</button></div>'
    );
  }

  // ===== Profile =====
  function renderProfile() {
    const body = document.getElementById('profileBody');
    const p = state.profile || {};
    const fields = [
      { label: 'الاسم القانوني', name: 'legal_name', value: p.legal_name },
      { label: 'البريد الإلكتروني للفوترة', name: 'billing_email', value: p.billing_email, type: 'email' },
      { label: 'العنوان (سطر 1)', name: 'address_line1', value: p.address_line1 },
      { label: 'العنوان (سطر 2)', name: 'address_line2', value: p.address_line2 },
      { label: 'المدينة', name: 'city', value: p.city },
      { label: 'الدولة', name: 'country', value: p.country },
      { label: 'الرقم الضريبي (اختياري)', name: 'tax_id', value: p.tax_id }
    ];
    let form = '';
    fields.forEach((f) => {
      form += '<div class="form-group">' +
        '<label class="form-label" for="pf_' + f.name + '">' + f.label + '</label>' +
        '<input type="' + (f.type || 'text') + '" id="pf_' + f.name + '" name="' + f.name + '" value="' + esc(f.value || '') + '" placeholder="—">' +
        '</div>';
    });
    body.innerHTML =
      '<div class="grid grid-2">' +
      '<div class="card">' +
      '<div class="card-title">بيانات الفوترة</div>' +
      '<div class="card-sub">تُستخدم في الفواتير وحساب الضريبة (VAT/GST حسب الدولة)</div>' +
      '<form id="profileForm">' +
      '<div class="grid grid-2" style="gap:16px">' + form + '</div>' +
      '<div class="flex gap-2 mt-5">' +
      '<button type="submit" class="btn btn-primary">حفظ البيانات</button>' +
      '<button type="button" class="btn btn-ghost" onclick="window.__loadProfile()">إعادة تحميل</button>' +
      '</div></form></div>' +

      '<div class="card">' +
      '<div class="card-title">لماذا بيانات الفوترة؟</div>' +
      '<div class="fs-14 text-secondary" style="line-height:1.9">' +
      '<div class="flex gap-3 mb-4"><div class="kpi-icon blue" style="position:static;width:40px;height:40px">' + ICONS.shield + '</div>' +
      '<div><div style="font-weight:600;color:var(--text)">فواتير ضريبية صحيحة</div><div class="fs-13 text-muted">نحسب الضريبة حسب الدولة تلقائيًا</div></div></div>' +
      '<div class="flex gap-3 mb-4"><div class="kpi-icon green" style="position:static;width:40px;height:40px">' + ICONS.check + '</div>' +
      '<div><div style="font-weight:600;color:var(--text)">خصم فوري من المحفظة</div><div class="fs-13 text-muted">يتم إصدار الفاتورة وتحصيل المبلغ فورًا</div></div></div>' +
      '<div class="flex gap-3"><div class="kpi-icon amber" style="position:static;width:40px;height:40px">' + ICONS.invoice + '</div>' +
      '<div><div style="font-weight:600;color:var(--text)">أرشيف فواتير دائم</div><div class="fs-13 text-muted">تحتفظ بسجل كامل لكل الفواتير</div></div></div>' +
      '</div></div></div>';

    document.getElementById('profileForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.submitter;
      const data = {};
      fields.forEach((f) => {
        const v = document.getElementById('pf_' + f.name).value.trim();
        if (v) data[f.name] = v;
      });
      setLoading(btn, true);
      try {
        const res = await api['/subscription/billing-profile'](data);
        state.profile = res.data.profile || data;
        toastSuccess('تم حفظ بيانات الفوترة', res.message || '');
      } catch (err) {
        toastError('تعذر الحفظ', err.message);
      } finally {
        setLoading(btn, false);
      }
    });
  }

  // ===== Actions =====
  async function cancelPlan() {
    confirmDialog('إلغاء الاشتراك', 'سيتم إيقاف تجديد باقتك تلقائيًا في نهاية الفترة الحالية. ستبقى الخدمة متاحة حتى تاريخ الانتهاء.', 'إلغاء الاشتراك', true, async () => {
      try {
        await api['/subscription/cancel']();
        toastSuccess('تم إلغاء الاشتراك', 'سيتم الإيقاف في نهاية الفترة الحالية.');
        await loadAll(true);
      } catch (err) { toastError('تعذر الإلغاء', err.message); }
    });
  }

  async function renewNow() {
    confirmDialog('إلغاء الإلغاء', 'سيتم إلغاء طلب الإلغاء واستئناف التجديد التلقائي لاشتراكك.', 'تأكيد التجديد', false, async () => {
      try {
        await api['/subscription/renew']();
        toastSuccess('تم استئناف التجديد', 'سيتم التجديد تلقائيًا في نهاية الفترة.');
        await loadAll(true);
      } catch (err) { toastError('تعذر التجديد', err.message); }
    });
  }

  function openDeposit(amount) {
    const body =
      '<div class="form-group">' +
      '<label class="form-label" for="dep_amount">المبلغ <span class="required">*</span></label>' +
      '<div class="input-wrap"><span class="input-prefix" dir="ltr">$</span>' +
      '<input type="number" id="dep_amount" min="1" step="0.01" placeholder="مثال: 50" dir="ltr" value="' + (amount ? Math.ceil(amount) : '') + '"></div>' +
      '<div class="form-hint">الحد الأدنى ' + money(1) + '</div></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="dep_method">طريقة الدفع <span class="required">*</span></label>' +
      '<select id="dep_method"><option value="iban">تحويل مصرفي (IBAN)</option><option value="paypal">PayPal</option></select></div>' +
      '<div class="form-group">' +
      '<label class="form-label" for="dep_note">ملاحظة (اختياري)</label>' +
      '<input type="text" id="dep_note" placeholder="مثال: رقم المرجع من التحويل"></div>' +
      '<div class="fs-13" style="padding:12px;background:rgba(96,165,250,0.08);border:1px solid rgba(96,165,250,0.25);border-radius:12px;color:var(--text-secondary)">' +
      'بعد إتمام التحويل سنراجع الطلب يدويًا ويُضاف المبلغ لرصيدك خلال مدة قصيرة.</div>';
    const modal = openModal(
      '<div class="modal-header"><div class="modal-title">شحن الرصيد</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' + body + '</div>' +
      '<div class="modal-footer"><button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn btn-accent" id="confirmDeposit">إرسال طلب الشحن</button></div>'
    );
    modal.querySelector('#confirmDeposit').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const amt = parseFloat(document.getElementById('dep_amount').value);
      const method = document.getElementById('dep_method').value;
      if (!amt || amt < 1) { toastError('بيانات ناقصة', 'أدخل مبلغًا صحيحًا (1 على الأقل).'); return; }
      setLoading(btn, true);
      try {
        await api['/wallet/deposit']({ amount: amt, payment_method: method, note: document.getElementById('dep_note').value.trim() || undefined });
        toastSuccess('تم تسجيل طلب الإيداع', 'سيُراجع ويُضاف لرصيدك قريبًا.');
        closeModal();
        await loadAll(true);
      } catch (err) {
        setLoading(btn, false);
        toastError('فشل الطلب', err.message);
      }
    });
  }

  function openRedeem() {
    const body =
      '<div class="form-group">' +
      '<label class="form-label" for="card_code">رمز البطاقة <span class="required">*</span></label>' +
      '<input type="text" id="card_code" placeholder="TRFC-XXXX-XXXX-XXXX" dir="ltr" style="font-family:var(--font-mono)">' +
      '<div class="form-hint">أدخل الكود المطبوع على البطاقة (غير حساس لحالة الأحرف)</div></div>';
    const modal = openModal(
      '<div class="modal-header"><div class="modal-title">استرداد بطاقة شحن</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' + body + '</div>' +
      '<div class="modal-footer"><button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn btn-accent" id="confirmRedeem">استرداد</button></div>'
    );
    modal.querySelector('#confirmRedeem').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const code = document.getElementById('card_code').value.trim();
      if (!code) { toastError('بيانات ناقصة', 'أدخل رمز البطاقة.'); return; }
      setLoading(btn, true);
      try {
        const res = await api['/wallet/redeem-card']({ code });
        toastSuccess('تم شحن الرصيد', res.message || ('أضيف ' + money(res.data.value) + ' لرصيدك.'));
        closeModal();
        await loadAll(true);
      } catch (err) {
        setLoading(btn, false);
        toastError('فشل الاسترداد', err.message);
      }
    });
  }

  // ---------- wiring global actions for inline onclick ----------
  window.__switchView = switchView;
  window.__cancelPlan = cancelPlan;
  window.__renewNow = renewNow;
  window.__openDeposit = openDeposit;
  window.__openRedeem = openRedeem;
  window.__loadProfile = () => loadAll(true);

  // ---------- cycle toggle ----------
  document.querySelectorAll('.cycle-toggle button').forEach((b) => {
    b.addEventListener('click', () => {
      document.querySelectorAll('.cycle-toggle button').forEach((x) => x.classList.remove('active'));
      b.classList.add('active');
      state.cycle = b.dataset.cycle;
      renderPlans();
    });
  });

  // ---------- boot ----------
  loadAll(false);

  // ---------- helpers ----------
  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0;
      const v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }
})();
