/* ============================================================
   Tourfecto Billing — Shared UI Helpers
   Toast, Modal, formatters, DOM helpers, SVG chart renderers
   ============================================================ */

window.UI = (() => {
  'use strict';

  // ---------- Formatting ----------
  function money(v, symbol) {
    const n = Number(v || 0);
    const neg = n < 0;
    const s = (neg ? '-' : '') + (symbol || '$');
    return s + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function moneySigned(v, symbol) {
    const n = Number(v || 0);
    if (n > 0) return '+' + money(n, symbol);
    if (n < 0) return '-' + money(-n, symbol);
    return money(0, symbol);
  }
  function date(d, opts) {
    if (!d) return '—';
    const dt = new Date(String(d).replace(' ', 'T'));
    if (isNaN(dt)) return String(d);
    return dt.toLocaleDateString('ar-EG', opts || { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function dateTime(d) {
    return date(d) + ' · ' + new Date(String(d).replace(' ', 'T')).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
  }
  function timeAgo(d) {
    if (!d) return '—';
    const dt = new Date(String(d).replace(' ', 'T'));
    const diff = Date.now() - dt.getTime();
    const m = Math.floor(diff / 60000);
    if (m < 1) return 'الآن';
    if (m < 60) return 'منذ ' + m + ' دقيقة';
    const h = Math.floor(m / 60);
    if (h < 24) return 'منذ ' + h + ' ساعة';
    const dd = Math.floor(h / 24);
    if (dd < 30) return 'منذ ' + dd + ' يوم';
    return date(d);
  }
  function num(v) { return Number(v || 0).toLocaleString('en-US'); }

  // ---------- Status pill ----------
  const STATUS_MAP = {
    active: ['green', 'نشط'], trialing: ['blue', 'تجريبي'],
    past_due: ['amber', 'متأخر'], cancelled: ['red', 'ملغي'],
    paused: ['gray', 'موقوف'],
    completed: ['green', 'مكتمل'], pending: ['amber', 'قيد الانتظار'],
    rejected: ['red', 'مرفوض'],
    paid: ['green', 'مدفوع'], failed: ['red', 'فاشل'],
    issued: ['blue', 'مُصدر'], overdue: ['amber', 'متأخرة'],
    refunded: ['purple', 'مُسترجع'], partially_paid: ['blue', 'مدفوع جزئيًا'],
    unused: ['gray', 'غير مستخدمة'], used: ['green', 'مستخدمة'],
    succeeded: ['green', 'نجحت'], processing: ['amber', 'جارٍ'],
    monthly: ['blue', 'شهري'], yearly: ['purple', 'سنوي'],
    deposit: ['green', 'إيداع'], subscription_charge: ['amber', 'اشتراك'],
    refund: ['blue', 'استرجاع'], admin_adjustment: ['purple', 'تعديل أدمن'],
    card_redemption: ['blue', 'شحن بطاقة'], subscription_credit: ['green', 'رصيد اشتراك']
  };
  function pill(status) {
    const map = STATUS_MAP[status] || ['gray', status];
    return '<span class="pill ' + map[0] + '">' + map[1] + '</span>';
  }

  // ---------- Toast ----------
  let toastWrap;
  function ensureToastWrap() {
    if (toastWrap) return toastWrap;
    toastWrap = document.createElement('div');
    toastWrap.className = 'toast-wrap';
    toastWrap.setAttribute('aria-live', 'polite');
    document.body.appendChild(toastWrap);
    return toastWrap;
  }
  function toast(type, title, msg, ms) {
    const wrap = ensureToastWrap();
    const icons = { success: ICONS.check, error: ICONS.x, info: ICONS.bell };
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = '<span>' + (icons[type] || ICONS.bell) + '</span><div><div class="toast-title">' + title + '</div>' +
      (msg ? '<div class="toast-msg">' + msg + '</div>' : '') + '</div>';
    wrap.appendChild(el);
    setTimeout(() => {
      el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
      el.style.opacity = '0';
      el.style.transform = 'translateX(20px)';
      setTimeout(() => el.remove(), 300);
    }, ms || 4000);
  }
  const toastSuccess = (t, m) => toast('success', t, m);
  const toastError = (t, m) => toast('error', t, m);
  const toastInfo = (t, m) => toast('info', t, m);

  // ---------- Modal ----------
  let backdrop;
  function openModal(html, onClose) {
    backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = '<div class="modal" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(backdrop);
    requestAnimationFrame(() => backdrop.classList.add('open'));
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) closeModal(); });
    document.querySelectorAll('.modal [data-close]').forEach((b) => b.addEventListener('click', closeModal));
    if (onClose) backdrop._onClose = onClose;
    return backdrop.querySelector('.modal');
  }
  function closeModal() {
    if (!backdrop) return;
    if (backdrop._onClose) backdrop._onClose();
    backdrop.classList.remove('open');
    setTimeout(() => backdrop.remove(), 200);
    backdrop = null;
  }
  function confirmDialog(title, msg, confirmText, danger, onConfirm) {
    const html =
      '<div class="modal-header"><div class="modal-title">' + title + '</div>' +
      '<button class="modal-close" data-close aria-label="إغلاق">' + ICONS.close + '</button></div>' +
      '<div class="modal-body"><p style="color:var(--text-secondary)">' + msg + '</p></div>' +
      '<div class="modal-footer">' +
      '<button class="btn btn-ghost" data-close>إلغاء</button>' +
      '<button class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" id="confirm-yes">' + confirmText + '</button>' +
      '</div>';
    const modal = openModal(html);
    modal.querySelector('#confirm-yes').addEventListener('click', () => { closeModal(); onConfirm && onConfirm(); });
  }

  // ---------- Loading state on buttons ----------
  function setLoading(btn, loading) {
    if (!btn) return;
    if (loading) { btn.classList.add('loading'); btn.disabled = true; }
    else { btn.classList.remove('loading'); btn.disabled = false; }
  }

  // ---------- SVG charts ----------
  function lineChart(container, points, opts) {
    opts = opts || {};
    const W = opts.width || 760, H = opts.height || 220;
    const pad = { top: 16, right: 16, bottom: 28, left: 52 };
    const iw = W - pad.left - pad.right, ih = H - pad.top - pad.bottom;
    const vals = points.map(p => Number(p.mrr) || 0);
    if (!vals.length) {
      container.innerHTML = '<div class="empty" style="padding:48px"><h3>لا توجد بيانات بعد</h3><p>ستظهر بيانات الإيراد هنا فور توفرها</p></div>';
      return;
    }
    const min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
    const range = (max - min) || 1;
    const stepX = iw / Math.max(points.length - 1, 1);
    const y = (v) => pad.top + ih - ((v - min) / range) * ih;
    const x = (i) => pad.left + stepX * i;

    // y gridlines (4)
    let grid = '';
    for (let g = 0; g <= 4; g++) {
      const gy = pad.top + (ih / 4) * g;
      const gv = max - (range / 4) * g;
      grid += '<line x1="' + pad.left + '" y1="' + gy + '" x2="' + (pad.left + iw) + '" y2="' + gy + '" stroke="rgba(159,176,199,0.12)" stroke-width="1"/>' +
        '<text x="' + (pad.left - 10) + '" y="' + (gy + 4) + '" text-anchor="end" fill="#64748B" font-size="11" font-family="Fira Code,monospace">' + fmtAxis(gv) + '</text>';
    }
    // line path
    const d = points.map((p, i) => (i === 0 ? 'M' : 'L') + x(i) + ' ' + y(Number(p.mrr) || 0)).join(' ');
    // area fill
    const area = d + ' L' + (pad.left + iw) + ' ' + (pad.top + ih) + ' L' + pad.left + ' ' + (pad.top + ih) + ' Z';
    // x labels (show ~6)
    const labelEvery = Math.ceil(points.length / 6);
    let xlabels = '';
    points.forEach((p, i) => {
      if (i % labelEvery !== 0 && i !== points.length - 1) return;
      xlabels += '<text x="' + x(i) + '" y="' + (H - 8) + '" text-anchor="middle" fill="#64748B" font-size="11">' + fmtDate(p.snapshot_date) + '</text>';
    });

    container.innerHTML =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img" aria-label="مخطط اتجاه الإيراد الشهري المتكرر">' +
      '<defs><linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">' +
      '<stop offset="0%" stop-color="#10B981" stop-opacity="0.28"/><stop offset="100%" stop-color="#10B981" stop-opacity="0"/></linearGradient></defs>' +
      grid +
      '<path d="' + area + '" fill="url(#areaGrad)"/>' +
      '<path d="' + d + '" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
      xlabels +
      '</svg>';
    function fmtAxis(v) {
      if (v >= 1000) return (v / 1000).toFixed(v >= 10000 ? 0 : 1) + 'k';
      return Math.round(v);
    }
    function fmtDate(s) {
      const d = new Date(s + 'T00:00:00');
      return d.toLocaleDateString('ar-EG', { day: 'numeric', month: 'short' });
    }
  }

  function donutChart(container, segments, opts) {
    opts = opts || {};
    const W = opts.width || 260, H = opts.height || 260;
    const cx = W / 2, cy = H / 2, r = Math.min(W, H) / 2 - 24;
    const total = segments.reduce((s, x) => s + x.value, 0);
    if (!total) {
      container.innerHTML = '<div class="empty" style="padding:40px"><h3>لا توجد بيانات</h3></div>';
      return;
    }
    const C = 2 * Math.PI * r;
    let offset = 0;
    let inner = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img" aria-label="توزيع الإيراد حسب الميزة">';
    segments.forEach((seg) => {
      const frac = seg.value / total;
      const len = C * frac;
      const dash = len - 4; // gap
      inner += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + seg.color + '" stroke-width="22" stroke-dasharray="' + Math.max(dash, 1) + ' ' + (C - Math.max(dash, 1)) + '" stroke-dashoffset="' + (-offset) + '" transform="rotate(-90 ' + cx + ' ' + cy + ')"/>';
      offset += len;
    });
    inner += '<text x="' + cx + '" y="' + (cy - 6) + '" text-anchor="middle" fill="#E8EEF7" font-size="26" font-weight="700" font-family="Fira Code,monospace">' + money(total) + '</text>' +
      '<text x="' + cx + '" y="' + (cy + 18) + '" text-anchor="middle" fill="#64748B" font-size="12">إجمالي الإيراد</text>';
    inner += '</svg>';
    container.innerHTML = inner;
  }

  function barChart(container, segments, opts) {
    opts = opts || {};
    const W = opts.width || 700, H = opts.height || 220;
    const pad = { top: 20, right: 16, bottom: 34, left: 56 };
    const iw = W - pad.left - pad.right, ih = H - pad.top - pad.bottom;
    if (!segments.length) {
      container.innerHTML = '<div class="empty" style="padding:48px"><h3>لا توجد بيانات بعد</h3></div>';
      return;
    }
    const max = Math.max.apply(null, segments.map(s => s.value));
    const bw = Math.min(70, (iw / segments.length) * 0.55);
    let grid = '';
    for (let g = 0; g <= 4; g++) {
      const gy = pad.top + (ih / 4) * g;
      const gv = max - (max / 4) * g;
      grid += '<line x1="' + pad.left + '" y1="' + gy + '" x2="' + (pad.left + iw) + '" y2="' + gy + '" stroke="rgba(159,176,199,0.12)" stroke-width="1"/>' +
        '<text x="' + (pad.left - 10) + '" y="' + (gy + 4) + '" text-anchor="end" fill="#64748B" font-size="11" font-family="Fira Code,monospace">' + money(gv) + '</text>';
    }
    let bars = '';
    const step = iw / segments.length;
    segments.forEach((seg, i) => {
      const h = max ? (seg.value / max) * ih : 0;
      const bx = pad.left + step * i + (step - bw) / 2;
      const by = pad.top + ih - h;
      bars += '<rect x="' + bx + '" y="' + by + '" width="' + bw + '" height="' + Math.max(h, 1) + '" rx="4" fill="' + seg.color + '" opacity="0.9">' +
        '<title>' + seg.label + ': ' + money(seg.value) + '</title></rect>';
      bars += '<text x="' + (bx + bw / 2) + '" y="' + (H - 10) + '" text-anchor="middle" fill="#9FB0C7" font-size="11">' + seg.label + '</text>';
      bars += '<text x="' + (bx + bw / 2) + '" y="' + (by - 6) + '" text-anchor="middle" fill="#E8EEF7" font-size="11" font-family="Fira Code,monospace">' + money(seg.value) + '</text>';
    });
    container.innerHTML =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto" role="img" aria-label="مخطط أعمدة">' + grid + bars + '</svg>';
  }

  // ---------- Escape HTML ----------
  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function html(tpl, data) {
    return tpl.replace(/\{\{(\w+)\}\}/g, (m, k) => data[k] !== undefined ? esc(data[k]) : '');
  }

  return {
    money, moneySigned, date, dateTime, timeAgo, num, pill,
    toast, toastSuccess, toastError, toastInfo,
    openModal, closeModal, confirmDialog,
    setLoading, lineChart, donutChart, barChart, esc, html
  };
})();
