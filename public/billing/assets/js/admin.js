/* ============================================================
   Tourfecto Billing — Admin dashboard logic
   ============================================================ */

(() => {
  'use strict';

  const api = window.TourfectoAPI.client;
  const { money, num, date, dateTime, timeAgo, pill, toastSuccess, toastError, setLoading, openModal, closeModal, confirmDialog, esc, lineChart, donutChart, barChart } = UI;

  const state = {
    view: 'dashboard',
    stats: null,
    trend: [],
    days: 30,
    usage: null,
    deposits: [],
    subscriptions: [],
    cards: [],
    settings: null,
    pricing: [],
    search: ''
  };

  // ---------- Icon injection ----------
  document.querySelectorAll('[data-icon]').forEach((el) => {
    el.innerHTML = ICONS[el.dataset.icon] || '';
  });
  document.getElementById('searchIcon').innerHTML = ICONS.search;

  // ---------- Navigation ----------
  function switchView(view) {
    state.view = view;
    document.querySelectorAll('.view').forEach((v) => v.hidden = v.id !== 'view-' + view);
    document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.view === view));
    const titles = { dashboard: 'نظرة عامة', deposits: 'إيداعات قيد الانتظار', subscriptions: 'الاشتراكات', cards: 'بطاقات الشحن', settings: 'إعدادات الفوترة', pricing: 'تسعير الاستخدام' };
    document.getElementById('pageTitle').textContent = titles[view];
    document.body.classList.remove('sidebar-open');
    renderView(view);
  }
  document.querySelectorAll('.nav-item').forEach((b) => b.addEventListener('click', () => switchView(b.dataset.view)));
  document.getElementById('menuToggle').addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
  document.getElementById('sidebarBackdrop').addEventListener('click', () => document.body.classList.remove('sidebar-open'));
  document.getElementById('refreshBtn').addEventListener('click', () => loadAll(true));

  // ---------- Load ----------
  async function loadAll(silent) {
    if (!silent) skeleton();
    try {
      const [stats, trend, usage, deposits, subscriptions, cards, settings, pricing] = await Promise.all([
        api['/admin/wallet/stats'](),
        api['/admin/wallet/mrr-trend']({ days: state.days }),
        api['/admin/wallet/usage-revenue'](),
        api['/admin/wallet/pending'](),
        api['/admin/subscriptions'](),
        api['/admin/wallet/cards'](),
        api['/admin/wallet/settings'](),
        api['/admin/wallet/usage-pricing']()
      ]);
      state.stats = stats.data.stats || {};
      state.trend = trend.data.trend || [];
      state.days = trend.data.days || state.days;
      state.usage = usage.data.usage_revenue || null;
      state.deposits = deposits.data.deposits || [];
      state.subscriptions = subscriptions.data.subscriptions || [];
      state.cards = cards.data.cards || [];
      state.settings = settings.data.settings || {};
      state.pricing = pricing.data.pricing || [];

      if (TourfectoAPI.isMock()) document.getElementById('mockBadge').hidden = false;
      updatePendingBadge();
      renderView(state.view);
    } catch (err) {
      toastError('تعذر تحميل البيانات', err.message);
    }
  }

  function skeleton() {
    document.getElementById('kpiGrid').innerHTML =
      [1, 2, 3, 4].map(() => '<div class="kpi skeleton" style="height:120px"></div>').join('');
  }

  function updatePendingBadge() {
    const badge = document.getElementById('pendingBadge');
    badge.textContent = state.deposits.length;
    badge.hidden = state.deposits.length === 0;
  }

  // ---------- Render ----------
  function renderView(view) {
    switch (view) {
      case 'dashboard': renderDashboard(); break;
      case 'deposits': renderDeposits(); break;
      case 'subscriptions': renderSubscriptions(); break;
      case 'cards': renderCards(); break;
      case 'settings': renderSettings(); break;
      case 'pricing': renderPricing(); break;
    }
  }

  // ===== Dashboard =====
  function renderDashboard() {
    const s = state.stats || {};
    const kpis = [
      { label: 'MRR (إيراد شهري متكرر)', value: money(s.mrr), symbol: '$', icon: 'trending', cls: 'green', delta: s.new_subscriptions_this_month != null ? '+ ' + s.new_subscriptions_this_month + ' اشتراك جديد هذا الشهر' : null, deltaCls: 'up' },
      { label: 'ARR (إيراد سنوي)', value: money(s.arr), symbol: '$', icon: 'coins', cls: 'blue', delta: null },
      { label: 'اشتراكات نشطة', value: num(s.active_subscriptions), symbol: '', icon: 'users', cls: 'purple', delta: 'متوسط لكل اشتراك: ' + money(s.average_revenue_per_subscription), deltaCls: 'neutral' },
      { label: 'إيداعات هذا الشهر', value: money(s.deposits_this_month), symbol: '$', icon: 'wallet', cls: 'green', delta: s.deposits_this_month_count != null ? s.deposits_this_month_count + ' عملية' : null, deltaCls: 'neutral' },
      { label: 'رسوم استخدام هذا الشهر', value: money(s.usage_charges_this_month), symbol: '$', icon: 'zap', cls: 'amber', delta: s.usage_charges_this_month_count != null ? s.usage_charges_this_month_count + ' عملية' : null, deltaCls: 'neutral' },
      { label: 'إيداعات قيد الانتظار', value: num(s.pending_count), symbol: '', icon: 'clock', cls: 'amber', delta: money(s.pending_total), deltaCls: 'neutral' },
      { label: 'أرصدة العملاء', value: money(s.total_customer_balances), symbol: '$', icon: 'coins', cls: 'blue', delta: 'مجموع أرصدة المحافظ', deltaCls: 'neutral' },
      { label: 'معدل التوقف (Churn)', value: (s.churn_rate_this_month != null ? s.churn_rate_this_month.toFixed(1) + '%' : '—'), symbol: '', icon: 'alert', cls: 'red', delta: s.cancelled_this_month != null ? s.cancelled_this_month + ' إلغاء هذا الشهر' : null, deltaCls: 'down' }
    ];
    document.getElementById('kpiGrid').innerHTML = kpis.map((k) =>
      '<div class="kpi"><div class="kpi-label">' + k.label + '</div>' +
      '<div class="kpi-value" dir="ltr">' + k.value + '</div>' +
      (k.delta ? '<div class="kpi-delta ' + k.deltaCls + '">' + k.delta + '</div>' : '') +
      '<div class="kpi-icon ' + k.cls + '">' + ICONS[k.icon] + '</div></div>'
    ).join('');

    // Charts
    lineChart(document.getElementById('mrrChart'), state.trend, { height: 230 });
    if (state.usage) {
      const b = state.usage.breakdown || {};
      const segments = Object.keys(b).map((k) => ({
        label: featureLabel(k), value: Number(b[k].revenue || 0),
        color: segColor(k), count: b[k].usage_count || 0
      })).filter(x => x.value > 0);
      donutChart(document.getElementById('usageDonut'), segments, { height: 250 });
      document.getElementById('usageLegend').innerHTML =
        '<div class="chart-legend" style="flex-direction:column;margin:0;gap:12px">' +
        segments.map((x) =>
          '<div class="chart-legend-item"><span class="chart-legend-swatch" style="background:' + x.color + '"></span>' +
          '<span>' + esc(x.label) + '</span>' +
          '<span class="mono fs-12 text-muted" dir="ltr">' + money(x.value) + ' · ' + x.count + '</span></div>').join('') +
        '</div>';
      document.getElementById('usageCardSub').textContent = 'شهر ' + state.usage.month + ' / ' + state.usage.year + ' — إجمالي ' + money(state.usage.total_revenue);
    }
  }

  function featureLabel(k) {
    return { ai_analysis: 'تحليل AI', chat_ai_message: 'رسالة شات', review_reply: 'رد تلقائي', competitor_analysis: 'تحليل منافس', _legacy_unmapped: 'غير مُصنف' }[k] || k;
  }
  function segColor(k) {
    const m = { ai_analysis: '#2563EB', chat_ai_message: '#10B981', review_reply: '#F59E0B', competitor_analysis: '#A855F7', _legacy_unmapped: '#64748B' };
    return m[k] || '#2563EB';
  }

  // ===== Deposits =====
  function renderDeposits() {
    const body = document.getElementById('depositsBody');
    const list = state.deposits;
    if (!list.length) {
      body.innerHTML = '<div class="card"><div class="empty">' + ICONS.check + '<h3>لا توجد إيداعات قيد الانتظار</h3><p>كل طلبات الشحن تمت مراجعتها</p></div></div>';
      return;
    }
    let rows = '';
    list.forEach((d) => {
      rows += '<div class="card" style="margin-bottom:14px">' +
        '<div class="flex items-center justify-between wrap gap-3">' +
        '<div class="flex items-center gap-3" style="min-width:0">' +
        '<div class="avatar">' + (d.user_email || '?').charAt(0).toUpperCase() + '</div>' +
        '<div style="min-width:0"><div style="font-weight:600;color:var(--text)">' + esc(d.user_email) + '</div>' +
        '<div class="fs-12 text-muted mt-1">' + esc(d.user_company || '—') + ' · ' + esc(d.reference_note || '') + '</div></div>' +
        '</div>' +
        '<div class="mono" style="font-size:22px;font-weight:700;color:var(--color-success)" dir="ltr">' + money(d.amount) + '</div>' +
        '<div class="flex gap-2">' +
        '<span class="pill gray">' + esc(d.payment_method) + '</span>' +
        '<span class="fs-12 text-muted" style="align-self:center">' + timeAgo(d.created_at) + '</span>' +
        '</div>' +
        '</div>' +
        '<div class="flex justify-between mt-4" style="padding-top:14px;border-top:1px solid var(--border)">' +
        '<button class="btn btn-ghost btn-sm" data-reject="' + d.id + '">' + ICONS.x + ' رفض</button>' +
        '<button class="btn btn-accent btn-sm" data-approve="' + d.id + '">' + ICONS.check + ' اعتماد وإضافة الرصيد</button>' +
        '</div></div>';
    });
    body.innerHTML = rows;

    body.querySelectorAll('[data-approve]').forEach((b) => b.addEventListener('click', () => approveDeposit(Number(b.dataset.approve))));
    body.querySelectorAll('[data-reject]').forEach((b) => b.addEventListener('click', () => rejectDeposit(Number(b.dataset.reject))));
  }

  function approveDeposit(id) {
    const d = state.deposits.find(x => Number(x.id) === id);
    if (!d) return;
    confirmDialog('اعتماد الإيداع', 'سيتم إضافة <b class="mono" dir="ltr">' + money(d.amount) + '</b> إلى رصيد المستخدم <b>' + esc(d.user_email) + '</b>.', 'اعتماد', false, async () => {
      try {
        await api['/admin/wallet/' + id + '/approve']({});
        toastSuccess('تم اعتماد الإيداع', 'أضيف ' + money(d.amount) + ' لرصيد ' + esc(d.user_email) + '.');
        await loadAll(true);
      } catch (err) { toastError('فشل الاعتماد', err.message); }
    });
  }
  function rejectDeposit(id) {
    const d = state.deposits.find(x => Number(x.id) === id);
    if (!d) return;
    confirmDialog('رفض الإيداع', 'سيتم رفض طلب الشحن بقيمة <b class="mono" dir="ltr">' + money(d.amount) + '</b> للمستخدم <b>' + esc(d.user_email) + '</b>.', 'رفض', true, async () => {
      try {
        await api['/admin/wallet/' + id + '/reject']({});
        toastSuccess('تم رفض الإيداع', 'أُلغِي الطلب.');
        await loadAll(true);
      } catch (err) { toastError('فشل الرفض', err.message); }
    });
  }

  // ===== Subscriptions =====
  function renderSubscriptions() {
    const body = document.getElementById('subscriptionsBody');
    const q = state.search.toLowerCase();
    const list = state.subscriptions.filter((s) =>
      !q || (s.user_email && s.user_email.toLowerCase().includes(q)) ||
      (s.company && s.company.toLowerCase().includes(q)) ||
      (s.plan_name && s.plan_name.toLowerCase().includes(q))
    );
    if (!list.length) {
      body.innerHTML = '<div class="card"><div class="empty">' + ICONS.users + '<h3>' + (state.search ? 'لا نتائج مطابقة' : 'لا توجد اشتراكات') + '</h3></div></div>';
      return;
    }
    let rows = '';
    list.forEach((s) => {
      rows += '<tr>' +
        '<td><div style="font-weight:600;color:var(--text)">' + esc(s.user_email) + '</div><div class="fs-12 text-muted mt-1">' + esc(s.company || '—') + '</div></td>' +
        '<td><div class="fs-14" style="font-weight:600">' + esc(s.plan_name) + '</div>' + pill(s.plan_type) + '</td>' +
        '<td class="num"><span class="amount" dir="ltr">' + money(s.price, s.currency === 'USD' ? '$' : s.currency) + '</span></td>' +
        '<td>' + pill(s.status) + '</td>' +
        '<td class="fs-12 text-muted">' + date(s.current_period_end) + '</td>' +
        '<td>' + (s.status === 'active' ? '<button class="btn btn-ghost btn-sm" data-cancel="' + s.id + '">' + ICONS.x + ' إلغاء</button>' : '') + '</td>' +
        '</tr>';
    });
    body.innerHTML = '<div class="card"><div class="table-wrap"><table class="table">' +
      '<thead><tr><th>العميل</th><th>الباقة</th><th>السعر</th><th>الحالة</th><th>نهاية الفترة</th><th></th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div></div>';

    body.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => cancelSubscription(Number(b.dataset.cancel))));
  }

  function cancelSubscription(id) {
    const s = state.subscriptions.find(x => Number(x.id) === id);
    if (!s) return;
    confirmDialog('إلغاء الاشتراك', 'إلغاء اشتراك <b>' + esc(s.user_email) + '</b> في باقة <b>' + esc(s.plan_name) + '</b>؟', 'إلغاء', true, async () => {
      try {
        await api['/admin/subscriptions/' + id + '/cancel']({});
        toastSuccess('تم إلغاء الاشتراك', 'اشتراك ' + esc(s.user_email) + ' أصبح ملغيًا.');
        await loadAll(true);
      } catch (err) { toastError('فشل الإلغاء', err.message); }
    });
  }

  // ===== Cards =====
  function renderCards() {
    const body = document.getElementById('cardsBody');
    const list = state.cards;
    if (!list.length) {
      body.innerHTML = '<div class="card"><div class="empty">' + ICONS.card + '<h3>لا توجد بطاقات بعد</h3><p>ولّد بطاقات شحن ليستخدمها العملاء</p></div></div>';
      return;
    }
    let rows = '';
    list.forEach((c) => {
      rows += '<tr>' +
        '<td><span class="mono" dir="ltr">' + esc(c.code) + '</span></td>' +
        '<td class="num"><span class="amount" dir="ltr">' + money(c.value) + '</span></td>' +
        '<td>' + pill(c.status) + '</td>' +
        '<td class="fs-12 text-muted">' + (c.used_by_user_id ? 'بواسطة #' + c.used_by_user_id : '—') + '</td>' +
        '<td class="fs-12 text-muted">' + date(c.created_at) + '</td>' +
        '</tr>';
    });
    body.innerHTML = '<div class="card"><div class="table-wrap"><table class="table">' +
      '<thead><tr><th>الكود</th><th>القيمة</th><th>الحالة</th><th>المستخدم</th><th>التاريخ</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>' +
      '<div class="fs-12 text-muted mt-3">إجمالي البطاقات المعروضة: ' + num(list.length) + '</div></div>';
  }

  function openGenerateCards() {
    const modal = openModal(
      '<div class="modal-header"><div class="modal-title">توليد بطاقات شحن</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' +
      '<div class="form-group"><label class="form-label" for="card_count">عدد البطاقات <span class="required">*</span></label>' +
      '<input type="number" id="card_count" min="1" max="500" value="10" dir="ltr"><div class="form-hint">بين 1 و 500 بطاقة</div></div>' +
      '<div class="form-group"><label class="form-label" for="card_value">قيمة البطاقة (USD) <span class="required">*</span></label>' +
      '<input type="number" id="card_value" min="1" step="1" value="25" dir="ltr"></div>' +
      '<div class="form-group"><label class="form-label" for="card_batch">تسمية الدفعة (اختياري)</label>' +
      '<input type="text" id="card_batch" placeholder="مثال: حملة رمضان"></div>' +
      '</div>' +
      '<div class="modal-footer"><button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn btn-accent" id="confirmGen">توليد</button></div>'
    );
    modal.querySelector('#confirmGen').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const count = parseInt(document.getElementById('card_count').value, 10);
      const value = parseFloat(document.getElementById('card_value').value);
      if (!count || count < 1 || count > 500) { toastError('عدد غير صحيح', 'أدخل عددًا بين 1 و 500.'); return; }
      if (!value || value <= 0) { toastError('قيمة غير صحيحة', 'أدخل قيمة أكبر من صفر.'); return; }
      setLoading(btn, true);
      try {
        const res = await api['/admin/wallet/cards/generate']({ count, value, batch_label: document.getElementById('card_batch').value.trim() || undefined });
        toastSuccess('تم توليد ' + count + ' بطاقة', 'قيمة إجمالية ' + money(value * count) + '.');
        closeModal();
        await loadAll(true);
      } catch (err) {
        setLoading(btn, false);
        toastError('فشل التوليد', err.message);
      }
    });
  }
  document.getElementById('genCardsBtn').addEventListener('click', openGenerateCards);

  // ===== Settings =====
  function renderSettings() {
    const body = document.getElementById('settingsBody');
    const s = state.settings || {};
    const groups = [
      {
        title: 'سياسات المحفظة', icon: 'wallet',
        fields: [
          { key: 'min_deposit', label: 'الحد الأدنى للإيداع (USD)', type: 'number' },
          { key: 'max_deposit', label: 'الحد الأقصى للإيداع (USD)', type: 'number' },
          { key: 'currency', label: 'العملة الافتراضية', type: 'text' }
        ]
      },
      {
        title: 'الاستخدام والفوترة', icon: 'zap',
        fields: [
          { key: 'usage_auto_charge_threshold', label: 'عتبة الشحن التلقائي للاستخدام (USD)', type: 'number' },
          { key: 'auto_charge_usage', label: 'الشحن التلقائي عند تجاوز الحد', type: 'toggle' },
          { key: 'allow_card_redemption', label: 'السماح باسترداد بطاقات الشحن', type: 'toggle' },
          { key: 'allow_prorated_downgrade_credit', label: 'إضافة رصيد عند خفض الباقة (Prorated)', type: 'toggle' }
        ]
      }
    ];
    let html = '';
    groups.forEach((g) => {
      html += '<div class="card mb-4"><div class="card-title">' + ICONS[g.icon] + ' ' + g.title + '</div><div class="card-sub">' + g.desc + '</div><div class="grid grid-2 mt-4" style="gap:20px">';
      g.fields.forEach((f) => {
        const val = s[f.key];
        if (f.type === 'toggle') {
          html += '<div class="flex items-center justify-between" style="padding:12px 0"><div><div class="fs-14" style="font-weight:600">' + f.label + '</div></div>' +
            '<button class="btn btn-sm ' + (val ? 'btn-accent' : 'btn-ghost') + '" data-toggle="' + f.key + '">' + (val ? 'مفعّل' : 'معطّل') + '</button></div>';
        } else {
          html += '<div class="form-group"><label class="form-label" for="set_' + f.key + '">' + f.label + '</label>' +
            '<input type="' + f.type + '" id="set_' + f.key + '" value="' + esc(val === null || val === undefined ? '' : val) + '" dir="ltr" style="font-family:var(--font-mono)"></div>';
        }
      });
      html += '</div></div>';
    });
    html += '<div class="card"><div class="flex justify-between items-center wrap"><div><div class="card-title" style="margin:0">حفظ الإعدادات</div><div class="fs-13 text-muted mt-1">تُطبق التغييرات على كل العمليات الجديدة</div></div>' +
      '<button class="btn btn-primary" id="saveSettings">حفظ التغييرات</button></div></div>';
    body.innerHTML = html;

    body.querySelectorAll('[data-toggle]').forEach((b) => b.addEventListener('click', () => {
      const key = b.dataset.toggle;
      state.settings[key] = !state.settings[key];
      b.textContent = state.settings[key] ? 'مفعّل' : 'معطّل';
      b.className = 'btn btn-sm ' + (state.settings[key] ? 'btn-accent' : 'btn-ghost');
    }));

    document.getElementById('saveSettings').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      setLoading(btn, true);
      const payload = { ...state.settings };
      groups.forEach((g) => g.fields.forEach((f) => {
        if (f.type !== 'toggle') {
          const v = document.getElementById('set_' + f.key).value;
          payload[f.key] = v === '' ? null : (f.type === 'number' ? parseFloat(v) : v);
        }
      }));
      try {
        await api['/admin/wallet/settings'](payload);
        toastSuccess('تم حفظ الإعدادات', 'سيتم تطبيقها على العمليات الجديدة.');
      } catch (err) { toastError('فشل الحفظ', err.message); }
      finally { setLoading(btn, false); }
    });
  }

  // ===== Pricing =====
  function renderPricing() {
    const body = document.getElementById('pricingBody');
    const list = state.pricing;
    if (!list.length) {
      body.innerHTML = '<div class="card"><div class="empty">' + ICONS.tag + '<h3>لا يوجد تسعير استخدام</h3></div></div>';
      return;
    }
    let rows = '';
    list.forEach((p) => {
      rows += '<tr>' +
        '<td><div style="font-weight:600;color:var(--text)">' + esc(p.label || p.feature_key) + '</div>' +
        '<div class="fs-12 text-muted mono" dir="ltr">' + esc(p.feature_key) + '</div></td>' +
        '<td class="num"><span class="amount" dir="ltr">' + money(p.unit_price) + '</span> <span class="fs-12 text-muted">/ ' + esc(p.unit || 'وحدة') + '</span></td>' +
        '<td><button class="btn btn-ghost btn-sm" data-edit="' + p.id + '">' + ICONS.settings + ' تعديل</button></td>' +
        '</tr>';
    });
    body.innerHTML = '<div class="card"><div class="table-wrap"><table class="table">' +
      '<thead><tr><th>الميزة</th><th>السعر</th><th></th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div>' +
      '<div class="fs-12 text-muted mt-3">يُحتسب السعر تلقائيًا على الاستخدام بعد تجاوز الحد المدرج في الباقة.</div></div>';

    body.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => editPricing(Number(b.dataset.edit))));
  }

  function editPricing(id) {
    const p = state.pricing.find(x => Number(x.id) === id);
    if (!p) return;
    const modal = openModal(
      '<div class="modal-header"><div class="modal-title">تعديل تسعير ' + esc(p.label) + '</div><button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body">' +
      '<div class="form-group"><label class="form-label" for="p_label">اسم الميزة</label>' +
      '<input type="text" id="p_label" value="' + esc(p.label) + '"></div>' +
      '<div class="form-group"><label class="form-label" for="p_price">السعر لكل وحدة (USD) <span class="required">*</span></label>' +
      '<input type="number" id="p_price" step="0.01" min="0" value="' + p.unit_price + '" dir="ltr" style="font-family:var(--font-mono)"></div>' +
      '<div class="form-group"><label class="form-label" for="p_unit">الوحدة</label>' +
      '<input type="text" id="p_unit" value="' + esc(p.unit) + '"></div>' +
      '</div>' +
      '<div class="modal-footer"><button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn btn-primary" id="confirmPrice">حفظ</button></div>'
    );
    modal.querySelector('#confirmPrice').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      const price = parseFloat(document.getElementById('p_price').value);
      if (isNaN(price) || price < 0) { toastError('سعر غير صحيح', 'أدخل سعرًا صحيحًا.'); return; }
      setLoading(btn, true);
      try {
        await api['/admin/wallet/usage-pricing/' + id]({
          label: document.getElementById('p_label').value.trim(),
          unit_price: price,
          unit: document.getElementById('p_unit').value.trim()
        });
        toastSuccess('تم حفظ التسعير', 'سعر ' + esc(p.feature_key) + ' أصبح ' + money(price) + ' لكل ' + esc(document.getElementById('p_unit').value.trim()));
        closeModal();
        await loadAll(true);
      } catch (err) {
        setLoading(btn, false);
        toastError('فشل الحفظ', err.message);
      }
    });
  }

  // ===== Trend range =====
  document.querySelectorAll('#trendRange .chip').forEach((c) => {
    c.addEventListener('click', async () => {
      document.querySelectorAll('#trendRange .chip').forEach((x) => x.classList.remove('active'));
      c.classList.add('active');
      state.days = Number(c.dataset.days);
      try {
        const res = await api['/admin/wallet/mrr-trend']({ days: state.days });
        state.trend = res.data.trend || [];
        lineChart(document.getElementById('mrrChart'), state.trend, { height: 230 });
      } catch (err) { toastError('تعذر التحديث', err.message); }
    });
  });

  // ===== Search =====
  let searchTimer;
  document.getElementById('subSearch').addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.search = e.target.value.trim(); renderSubscriptions(); }, 250);
  });

  // ===== Boot =====
  loadAll(false);
})();
