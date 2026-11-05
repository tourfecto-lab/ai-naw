/* CRM custom field values editor: window.CrmCustomFields.open(entityType, entityId, title)
   Works for contact / lead / deal / company. Builds its own modal, so any CRM page can use it. */
(function () {
  'use strict';
  const T = k => (window.I18N && window.I18N[k]) || k;
  let overlay = null;
  let current = null;

  function ensureModal() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'p-modal-overlay';
    overlay.innerHTML =
      '<div class="p-modal" style="max-width:520px;">' +
      '<div class="p-modal-head"><h3 data-cf="title"></h3><button type="button" class="p-modal-close" data-cf="close" aria-label="close">×</button></div>' +
      '<div class="p-modal-body" data-cf="body"></div>' +
      '<div class="p-modal-foot"><button type="button" class="p-btn primary" data-cf="save"></button></div>' +
      '</div>';
    document.body.appendChild(overlay);
    overlay.querySelector('[data-cf="save"]').textContent = T('common.save');
    overlay.querySelector('[data-cf="close"]').addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
    overlay.querySelector('[data-cf="save"]').addEventListener('click', save);
    return overlay;
  }

  function close() { if (overlay) overlay.classList.remove('open'); current = null; }

  function field(f) {
    const P = window.Panel, esc = P.esc;
    const id = 'ccf_' + esc(f.field_key);
    const val = f.value === null || f.value === undefined ? '' : String(f.value);
    let input;
    if (f.field_type === 'select') {
      input = '<select class="form-control" data-key="' + esc(f.field_key) + '" id="' + id + '"><option value=""></option>' +
        (f.options || []).map(o => '<option value="' + esc(o) + '"' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
    } else {
      const type = f.field_type === 'number' ? 'number' : (f.field_type === 'date' ? 'date' : 'text');
      input = '<input type="' + type + '"' + (type === 'number' ? ' step="any"' : '') + ' class="form-control" data-key="' + esc(f.field_key) + '" id="' + id + '" value="' + esc(val) + '">';
    }
    return '<label for="' + id + '" class="form-label">' + esc(f.label) + '</label><div style="margin-bottom:10px;">' + input + '</div>';
  }

  async function open(entityType, entityId, title) {
    const P = window.Panel;
    const m = ensureModal();
    current = { entityType, entityId };
    m.querySelector('[data-cf="title"]').textContent = T('crm.custom_fields.title') + (title ? ' - ' + title : '');
    const body = m.querySelector('[data-cf="body"]');
    body.textContent = T('common.loading');
    m.classList.add('open');
    const res = await P.fetchJSON('/api/crm/entities/' + encodeURIComponent(entityType) + '/' + Number(entityId) + '/custom-fields');
    const list = res.success ? (res.data.fields || []) : [];
    if (!res.success) { body.textContent = res.error || '-'; return; }
    if (!list.length) {
      body.innerHTML = '<p class="p-cell-muted">' + P.esc(T('crm.custom_fields.empty')) + '</p>' +
        '<a class="p-btn xs" href="/crm/custom-fields">' + P.esc(T('crm.custom_fields.new')) + '</a>';
      m.querySelector('[data-cf="save"]').style.display = 'none';
      return;
    }
    m.querySelector('[data-cf="save"]').style.display = '';
    body.innerHTML = list.map(field).join('');
  }

  async function save() {
    if (!current) return;
    const P = window.Panel;
    const values = {};
    overlay.querySelectorAll('[data-cf="body"] [data-key]').forEach(el => { values[el.dataset.key] = el.value; });
    const res = await P.fetchJSON('/api/crm/entities/' + encodeURIComponent(current.entityType) + '/' + Number(current.entityId) + '/custom-fields', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ values }),
    });
    if (res.success) { P.toast(T('crm.custom_fields.values_saved'), 'success'); close(); }
    else P.toast(res.error || '-', 'error');
  }

  window.CrmCustomFields = { open: open };
})();
