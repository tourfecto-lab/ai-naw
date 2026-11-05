/* CRM deletion helpers: CrmDelete.contact(id, onDone) / CrmDelete.company(id, onDone)
   Always asks first. A contact linked to leads/deals gets a second, explicit confirmation that
   shows the server's counts (the API refuses to delete such a contact without confirm=1). */
(function () {
  'use strict';
  const T = k => (window.I18N && window.I18N[k]) || k;

  async function run(url, firstMessage, doneMessageKey, onDone) {
    const P = window.Panel;
    if (!window.confirm(firstMessage)) return false;
    let res = await P.fetchJSON(url, { method: 'DELETE' });
    if (!res.success && res.details && res.details.requires_confirm) {
      if (!window.confirm(res.error)) return false;
      res = await P.fetchJSON(url + (url.indexOf('?') === -1 ? '?' : '&') + 'confirm=1', { method: 'DELETE' });
    }
    if (res.success) {
      P.toast(T(doneMessageKey), 'success');
      if (typeof onDone === 'function') onDone(res.data);
      return true;
    }
    P.toast(res.error || T('common.delete_failed'), 'error');
    return false;
  }

  window.CrmDelete = {
    contact: (id, onDone) => run('/api/crm/contacts/' + Number(id), T('crm.delete.confirm_contact'), 'common.deleted', onDone),
    company: (id, onDone) => run('/api/crm/companies/' + Number(id), T('crm.delete.confirm_company'), 'common.deleted', onDone),
  };
})();
