<?php

/**
 * View: Settings - JS (extracted from renderSettingsPage Phase 16F).
 */
return <<<JS
(function () {
    const P = window.Panel;
    const esc = P.esc, toast = P.toast;
    const rawFetchJSON = P.fetchJSON;

    // Phase 12: نحقن csrf_token تلقائيًا في أي نداء بجسم JSON (POST/PUT/
    // DELETE) - بنستبدل fetchJSON المحلية بغلاف بسيط حواليها، عشان كل
    // نداء fetchJSON(...) موجود بالفعل في الملف ده كله (عشرات النداءات)
    // ياخد الحماية دي تلقائيًا من غير ما نلمس ولا نداء منهم بنفسه.
    // مبنيّة فوق window.TF_CSRF_TOKEN المحقون فوق في الصفحة.
    // عملاء Bearer token (Authorization header) مش محتاجين التوكن ده.
    async function fetchJSON(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        if (method !== 'GET' && typeof options.body === 'string') {
            try {
                const bodyObj = JSON.parse(options.body);
                bodyObj.csrf_token = window.TF_CSRF_TOKEN || '';
                options = Object.assign({}, options, { body: JSON.stringify(bodyObj) });
            } catch (e) {
                // جسم الطلب مش JSON (مثلًا FormData لرفع صورة) - نسيبه زي
                // ما هو، أماكن الرفع بتضيف csrf_token بنفسها لو محتاجة.
            }
        } else if (method !== 'GET' && !options.body) {
            // نداءات POST من غير جسم (زي 2fa/setup) - نضيف جسم بسيط فيه التوكن.
            options = Object.assign({}, options, {
                headers: Object.assign({ 'Content-Type': 'application/json' }, options.headers || {}),
                body: JSON.stringify({ csrf_token: window.TF_CSRF_TOKEN || '' }),
            });
        } else if (method !== 'GET' && typeof FormData !== 'undefined' && options.body instanceof FormData) {
            options.body.append('csrf_token', window.TF_CSRF_TOKEN || '');
        }
        return rawFetchJSON(url, options);
    }

    // ============ التابات (ديسكتوب + Dropdown الموبايل - Phase 14) ============
    const settingsSections = ['profile', 'security', 'notifications', 'api', 'integrations', 'billing', 'audit', 'workspace', 'team', 'general', 'connected', 'activity', 'permissions'];
    function switchSettingsTab(section) {
        if (settingsSections.indexOf(section) === -1) {
            section = 'profile';
        }
        document.querySelectorAll('#settingsTabs .p-tab').forEach(b => {
            b.classList.toggle('active', b.dataset.section === section);
        });
        document.getElementById('settingsTabsMobile').value = section;
        document.querySelectorAll('.settings-section').forEach(s => {
            s.style.display = (s.id === 'section_' + section) ? 'block' : 'none';
        });
    }
    document.querySelectorAll('#settingsTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', () => switchSettingsTab(btn.dataset.section));
    });
    // فتح تبويب معيّن من الرابط (?tab=security أو #security) - مثلاً الرابط القديم /profile/security
    (function openTabFromUrl() {
        const want = new URLSearchParams(window.location.search).get('tab') || (window.location.hash || '').replace('#', '');
        if (want && settingsSections.indexOf(want) !== -1) {
            switchSettingsTab(want);
        }
    })();
    document.getElementById('settingsTabsMobile').addEventListener('change', function () {
        switchSettingsTab(this.value);
    });

    // Connected Accounts (Profile Center Phase 2): توست بعد الرجوع من
    // /auth/{provider}?link=1 (نجاح أو فشل)، وتنظيف الـURL بعدها.
    (function handleOAuthRedirectFlash() {
        const params = new URLSearchParams(window.location.search);
        const connected = params.get('oauth_connected');
        const error = params.get('oauth_error');
        if (connected) {
            toast(connected + ' ' + {$tConnectedSuccess}, 'success');
        }
        if (error) {
            toast(error, 'error');
        }
        if (connected || error) {
            params.delete('oauth_connected');
            params.delete('oauth_error');
            const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', clean);
        }
    })();

    // ============ الملف الشخصي + الصورة ============
    document.getElementById('avatarInput').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('avatar', file);

        const wrap = document.getElementById('avatarPreviewWrap');
        const originalHtml = wrap.innerHTML;
        wrap.innerHTML = '<span style="font-size:13px;">' + {$tUploading} + '</span>';

        try {
            const res = await fetch('/api/user/avatar', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                wrap.innerHTML = `<img src="\${esc(data.data.avatar_url)}" alt="الصورة الشخصية" style="width:100%;height:100%;object-fit:cover;">`;
                toast({$tPhotoChanged}, 'success');
            } else {
                wrap.innerHTML = originalHtml;
                toast(data.error || {$tPhotoUploadFailed}, 'error');
            }
        } catch (err) {
            wrap.innerHTML = originalHtml;
            toast({$tConnectionFailed}, 'error');
        }
    });

    const profileFieldIds = ['first_name', 'last_name', 'display_name', 'company_name', 'job_title', 'phone', 'country_code', 'currency', 'bio'];
    const profileOriginal = {};
    profileFieldIds.forEach(id => { profileOriginal[id] = document.getElementById(id).value; });

    function clearProfileFieldErrors() {
        profileFieldIds.forEach(id => {
            const err = document.getElementById('err_' + id);
            if (err) err.textContent = '';
        });
    }

    const bioEl = document.getElementById('bio');
    const bioCount = document.getElementById('bioCount');
    function updateBioCount() { bioCount.textContent = bioEl.value.length; }
    bioEl.addEventListener('input', updateBioCount);
    updateBioCount();

    document.getElementById('profileForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('profileAlert');
        const saveBtn = document.getElementById('profileSaveBtn');
        const savingIndicator = document.getElementById('profileSavingIndicator');
        alertBox.style.display = 'none';
        clearProfileFieldErrors();

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/user/profile', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    first_name: document.getElementById('first_name').value.trim(),
                    last_name: document.getElementById('last_name').value.trim(),
                    display_name: document.getElementById('display_name').value.trim(),
                    company_name: document.getElementById('company_name').value.trim(),
                    job_title: document.getElementById('job_title').value.trim(),
                    bio: bioEl.value.trim(),
                    phone: document.getElementById('phone').value.trim(),
                    country_code: document.getElementById('country_code').value.trim(),
                    currency: document.getElementById('currency').value.trim(),
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            profileFieldIds.forEach(id => { profileOriginal[id] = document.getElementById(id).value; });
            toast({$tChangesSaved}, 'success');
        } else {
            // لا نفرغ الفورم أبدًا لو فشل الحفظ - القيم اللي كتبها المستخدم تفضل زي ما هي
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('profileCancelBtn').addEventListener('click', function () {
        profileFieldIds.forEach(id => { document.getElementById(id).value = profileOriginal[id]; });
        updateBioCount();
        clearProfileFieldErrors();
        document.getElementById('profileAlert').style.display = 'none';
    });

    // ============ التحقق بخطوتين (2FA) ============
    window.startTwoFactorSetup = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const res = await fetchJSON('/api/user/2fa/setup', { method: 'POST' });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaSecretDisplay').textContent = res.data.secret;
        document.getElementById('tfaDisabledState').style.display = 'none';
        document.getElementById('tfaSetupState').style.display = 'block';
    };

    window.confirmTwoFactorSetup = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const code = document.getElementById('tfaConfirmCode').value.trim();
        const res = await fetchJSON('/api/user/2fa/enable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code: code }),
        });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaSetupState').style.display = 'none';
        const list = document.getElementById('tfaRecoveryCodesList');
        list.innerHTML = res.data.recovery_codes.map(c => '<div>' + c + '</div>').join('');
        document.getElementById('tfaRecoveryState').style.display = 'block';
    };

    window.acknowledgeRecoveryCodes = function () {
        document.getElementById('tfaRecoveryState').style.display = 'none';
        document.getElementById('tfaEnabledState').style.display = 'block';
        toast({$tTfaEnabledToast}, 'success');
    };

    window.disableTwoFactor = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const password = document.getElementById('tfaDisablePassword').value;
        if (!password) {
            alertBox.textContent = {$tPasswordRequired};
            alertBox.style.display = 'block';
            return;
        }
        if (!confirm({$tTfaDisableConfirm})) return;
        const res = await fetchJSON('/api/user/2fa/disable', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password }),
        });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaEnabledState').style.display = 'none';
        document.getElementById('tfaDisabledState').style.display = 'block';
        toast({$tTfaDisabledToast}, 'success');
    };

    window.regenerateRecoveryCodes = async function () {
        if (!confirm({$tRegenRecoveryConfirm})) return;

        const password = document.getElementById('regenRecoveryPassword').value;
        const code = document.getElementById('regenRecoveryCode').value;
        if (!password || !code) {
            toast({$tRecoveryNeedTotp}, 'error');
            return;
        }

        const res = await fetchJSON('/api/user/2fa/recovery-codes/regenerate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password, code: code }),
        });
        if (!res.success) {
            toast(res.error || {$tRegenRecoveryFailed}, 'error');
            return;
        }
        const box = document.getElementById('regenRecoveryCodesBox');
        box.innerHTML = res.data.recovery_codes.map(c => '<div>' + c + '</div>').join('');
        box.style.display = 'block';
        document.getElementById('regenRecoveryPassword').value = '';
        document.getElementById('regenRecoveryCode').value = '';
        toast({$tRegenRecoveryDone}, 'success');
    };

    window.toggleReEnrollForm = function () {
        const box = document.getElementById('tfaReEnrollBox');
        box.style.display = box.style.display === 'none' ? 'block' : 'none';
        document.getElementById('tfaAlert').style.display = 'none';
    };

    window.startReEnroll = async function () {
        const alertBox = document.getElementById('tfaAlert');
        alertBox.style.display = 'none';
        const password = document.getElementById('reEnrollPassword').value;
        const code = document.getElementById('reEnrollCode').value;
        if (!password || !code) {
            alertBox.textContent = {$tReEnrollRequired};
            alertBox.style.display = 'block';
            return;
        }
        if (!confirm({$tReEnrollConfirm})) return;
        const res = await fetchJSON('/api/user/2fa/re-enroll', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: password, code: code }),
        });
        if (!res.success) {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
            return;
        }
        document.getElementById('tfaEnabledState').style.display = 'none';
        document.getElementById('tfaSecretDisplay').textContent = res.data.secret;
        document.getElementById('tfaConfirmCode').value = '';
        document.getElementById('tfaSetupState').style.display = 'block';
        document.getElementById('reEnrollPassword').value = '';
        document.getElementById('reEnrollCode').value = '';
        toast({$tReEnrollStarted}, 'success');
    };

    window.disconnectOAuth = async function (provider) {
        if (!confirm({$tDisconnectConfirm})) return;
        const res = await fetchJSON('/api/user/oauth/' + encodeURIComponent(provider), { method: 'DELETE' });
        if (res.success) {
            toast({$tAccountDisconnected}, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            toast(res.error || {$tDisconnectFailed}, 'error');
        }
    };

    // ============ الأمان ============
    document.getElementById('securityForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('securityAlert');
        const saveBtn = document.getElementById('securitySaveBtn');
        const savingIndicator = document.getElementById('securitySavingIndicator');
        alertBox.style.display = 'none';
        document.getElementById('err_current_password').textContent = '';
        document.getElementById('err_new_password').textContent = '';

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/user/password', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: document.getElementById('current_password').value,
                    new_password: document.getElementById('new_password').value,
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            toast({$tPasswordUpdated}, 'success');
            document.getElementById('securityForm').reset();
            loadSessions();
        } else {
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tUpdateFailed};
            alertBox.style.display = 'block';
        }
    });

    // ============ الجلسات النشطة ============
    function escapeHtml(str) {
        return (str || '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function loadSessions() {
        const list = document.getElementById('sessionsList');
        const res = await fetchJSON('/api/user/sessions');
        if (!res.success) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tSessionsLoadFailed}) + '</p>';
            return;
        }
        const sessions = res.data.sessions || [];
        if (sessions.length === 0) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tSessionsEmpty}) + '</p>';
            return;
        }
        list.innerHTML = sessions.map(s => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">
                    \${escapeHtml(s.device_name)} — \${escapeHtml(s.browser)} / \${escapeHtml(s.os)}
                    \${s.is_current ? '<span class="p-badge status-active" style="margin-inline-start:8px;">' + esc({$tCurrentDevice}) + '</span>' : ''}
                    <br><span class="p-cell-muted" style="font-size:12px;">\${escapeHtml(s.ip_masked)} · \${escapeHtml(s.last_active || s.created_at || '')}</span>
                </span>
                <span class="v" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <input type="text" class="form-control rename-session-input" data-session-id="\${s.id}" placeholder="{$tRenameDevicePlaceholder}" maxlength="60" value="" style="width:150px;height:30px;font-size:12px;">
                    <button type="button" class="p-btn outline xs" data-rename-session="\${s.id}">{$tRenameDevice}</button>
                    \${s.is_current ? '' : `<button type="button" class="p-btn outline xs" data-logout-session="\${s.id}">\${esc({$tLogoutDevice})}</button>`}
                </span>
            </div>
        `).join('');

        list.querySelectorAll('[data-rename-session]').forEach(btn => {
            btn.addEventListener('click', async function () {
                const id = this.getAttribute('data-rename-session');
                const input = list.querySelector('.rename-session-input[data-session-id="' + id + '"]');
                const name = (input ? input.value : '').trim();
                if (!name) {
                    toast({$tRenameDeviceRequired}, 'error');
                    return;
                }
                const r = await fetchJSON('/api/user/sessions/' + id + '/name', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ device_name: name }),
                });
                if (r.success) {
                    toast({$tDeviceRenamed}, 'success');
                    loadSessions();
                } else {
                    toast(r.error || {$tUpdateFailed}, 'error');
                }
            });
        });

        list.querySelectorAll('[data-logout-session]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tLogoutDeviceConfirm})) return;
                const id = this.getAttribute('data-logout-session');
                const r = await fetchJSON('/api/user/sessions/' + id + '/logout', { method: 'POST' });
                if (r.success) {
                    toast({$tDeviceLoggedOut}, 'success');
                    loadSessions();
                } else {
                    toast(r.error || {$tUpdateFailed}, 'error');
                }
            });
        });
    }

    document.getElementById('logoutOthersBtn').addEventListener('click', async function () {
        if (!confirm({$tLogoutOthersConfirm})) return;
        const res = await fetchJSON('/api/user/sessions/logout-others', { method: 'POST' });
        if (res.success) {
            toast({$tOthersLoggedOut}, 'success');
            loadSessions();
        } else {
            toast(res.error || {$tUpdateFailed}, 'error');
        }
    });

    // ============ مفاتيح API الشخصية ============
    async function loadApiKeys() {
        const list = document.getElementById('apiKeysList');
        const res = await fetchJSON('/api/user/api-keys');
        if (!res.success) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tKeysLoadFailed}) + '</p>';
            return;
        }
        const keys = res.data.keys || [];
        if (keys.length === 0) {
            list.innerHTML = '<p class="p-cell-muted">' + esc({$tKeysEmpty}) + '</p>';
            return;
        }
        const expiresLabel = esc({$jsKeyExpiresNever});
        list.innerHTML = keys.map(k => `
            <div class="p-kv" style="align-items:center;">
                <span class="k">
                    \${escapeHtml(k.name)}
                    \${k.revoked ? '<span class="p-badge status-suspended" style="margin-inline-start:8px;">' + esc({$tRevoked}) + '</span>' : ''}
                    <br><span class="p-cell-muted" style="font-size:12px;direction:ltr;display:inline-block;">\${escapeHtml(k.key_prefix)}••••••••</span>
                    <span class="p-cell-muted" style="font-size:12px;"> · \${escapeHtml(k.last_used_at || esc({$tNeverUsed}))}</span>
                    <span class="p-cell-muted" style="font-size:12px;"> · \${k.expires_at ? esc({$jsKeyExpiryLabel}) + ' ' + escapeHtml(k.expires_at) : expiresLabel}</span>
                    <br><span class="p-cell-muted" style="font-size:12px;">\${(k.scopes || []).length ? esc({$jsKeyScopesTitle}) + ' ' + (k.scopes || []).join(', ') : ''}</span>
                </span>
                <span class="v">
                    \${k.revoked ? '' : `<button type="button" class="p-btn outline xs" data-revoke-key="\${k.id}">\${esc({$tRevoke})}</button>`}
                </span>
            </div>
        `).join('');

        list.querySelectorAll('[data-revoke-key]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRevokeConfirm})) return;
                const id = this.getAttribute('data-revoke-key');
                const r = await fetchJSON('/api/user/api-keys/' + id + '/revoke', { method: 'POST' });
                if (r.success) {
                    toast({$tKeyRevoked}, 'success');
                    loadApiKeys();
                } else {
                    toast(r.error || {$tUpdateFailed}, 'error');
                }
            });
        });
    }

    document.getElementById('createKeyBtn').addEventListener('click', async function () {
        const nameInput = document.getElementById('newKeyName');
        const errEl = document.getElementById('err_key_name');
        errEl.textContent = '';

        const btn = this;
        btn.disabled = true;
        let res;
        try {
            const expiryInput = document.getElementById('newKeyExpiry');
            const expiryDays = expiryInput && expiryInput.value ? parseInt(expiryInput.value, 10) : 0;
            const scopes = Array.from(document.querySelectorAll('.key-scope-cb:checked')).map(cb => cb.value);
            res = await fetchJSON('/api/user/api-keys', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: nameInput.value.trim(), expires_in_days: expiryDays > 0 ? expiryDays : 0, scopes }),
            });
        } finally {
            btn.disabled = false;
        }

        if (res.success) {
            nameInput.value = '';
            const box = document.getElementById('newKeyRevealBox');
            document.getElementById('newKeyRaw').textContent = res.data.raw_key;
            box.style.display = 'block';
            toast({$tKeyCreated}, 'success');
            loadApiKeys();
        } else {
            if (res.details && res.details.name) {
                errEl.textContent = Array.isArray(res.details.name) ? res.details.name[0] : res.details.name;
            } else {
                toast(res.error || {$tSaveFailed}, 'error');
            }
        }
    });

    // ============ الإشعارات ============
    async function loadNotifications() {
        const res = await fetchJSON('/api/settings/notifications');
        if (res.success) {
            const prefs = res.data.preferences || {};
            document.getElementById('notif_cat_reviews').checked = prefs.reviews !== false;
            document.getElementById('notif_cat_content').checked = prefs.content_publishing !== false;
            document.getElementById('notif_cat_leads').checked = prefs.leads !== false;
            document.getElementById('notif_cat_system').checked = prefs.system !== false;
            document.getElementById('notif_digest_daily').checked = prefs.digest_daily !== false;
            document.getElementById('notif_digest_weekly').checked = prefs.digest_weekly !== false;
        }
    }

    window.saveNotifications = async function () {
        const alertBox = document.getElementById('notifAlert');
        const saveBtn = document.getElementById('notifSaveBtn');
        const savingIndicator = document.getElementById('notifSavingIndicator');
        alertBox.style.display = 'none';
        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';

        let res;
        try {
            res = await fetchJSON('/api/settings/notifications', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    preferences: {
                        reviews: document.getElementById('notif_cat_reviews').checked,
                        content_publishing: document.getElementById('notif_cat_content').checked,
                        leads: document.getElementById('notif_cat_leads').checked,
                        system: document.getElementById('notif_cat_system').checked,
                        digest_daily: document.getElementById('notif_digest_daily').checked,
                        digest_weekly: document.getElementById('notif_digest_weekly').checked,
                    },
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) toast({$tPrefsSaved}, 'success');
        else { alertBox.textContent = res.error || {$tSaveFailed}; alertBox.style.display = 'block'; }
    };

    // ============ API ============
    window.copyToken = function () {
        const text = document.getElementById('apiToken').textContent;
        navigator.clipboard.writeText(text).then(() => toast({$tKeyCopied}, 'success'));
    };

    window.regenerateToken = async function () {
        if (!confirm({$tRegenerateConfirm})) return;
        const res = await fetchJSON('/api/settings/api/regenerate', { method: 'POST' });
        if (res.success) {
            document.getElementById('apiToken').textContent = res.data.api_token;
            toast({$tKeyRegenerated}, 'success');
        } else {
            toast(res.error || {$tGenerateFailed}, 'error');
        }
    };

    // ============ الفوترة ============
    async function loadBilling() {
        loadBillingPlan();
        loadBillingWallet();
        loadBillingInvoices();
    }

    async function loadBillingPlan() {
        const box = document.getElementById('billingPlanBox');
        const res = await fetchJSON('/api/subscription/current');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }

        if (!res.data.has_subscription) {
            box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingNoPlan}) + '</p>';
            return;
        }

        const s = res.data.subscription;
        box.innerHTML = `
            <div class="p-kv"><span class="k">\${esc({$tPlanNameLabel})}</span><span class="v">\${escapeHtml(s.plan_name)} (\${escapeHtml(s.plan_type)})</span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanStatusLabel})}</span><span class="v"><span class="p-badge status-\${escapeHtml(s.status)}">\${escapeHtml(s.status)}</span></span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanPriceLabel})}</span><span class="v">\${escapeHtml(s.price)} \${escapeHtml(s.currency || 'USD')}</span></div>
            <div class="p-kv"><span class="k">\${esc({$tPlanExpiryLabel})}</span><span class="v">\${escapeHtml(s.expiry_date || '-')}</span></div>
        `;
        if (s.status === 'active') {
            box.innerHTML += `<button type="button" class="p-btn outline" id="cancelPlanBtn" style="margin-top:10px;">\${esc({$tBillingCancelPlan})}</button>`;
            document.getElementById('cancelPlanBtn').addEventListener('click', async function () {
                if (!confirm({$tCancelPlanConfirm})) return;
                const r = await fetchJSON('/api/subscription/cancel', { method: 'POST' });
                if (r.success) { toast({$tPlanCancelled}, 'success'); loadBillingPlan(); }
                else toast(r.error || {$tCancelFailed}, 'error');
            });
        }
    }

    async function loadBillingWallet() {
        const box = document.getElementById('billingWalletBox');
        const res = await fetchJSON('/api/wallet/balance');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }
        box.innerHTML = `<div class="p-kv"><span class="k">\${esc({$tWalletBalanceLabel})}</span><span class="v" style="font-weight:800;">\${escapeHtml(res.data.balance)}</span></div>`;
    }

    async function loadBillingInvoices() {
        const box = document.getElementById('billingInvoicesBox');
        const res = await fetchJSON('/api/subscription/invoices');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingLoadFailed}) + '</p>'; return; }
        const invoices = res.data.invoices || [];
        if (invoices.length === 0) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tBillingInvoicesEmpty}) + '</p>'; return; }
        box.innerHTML = invoices.map(inv => `
            <div class="p-kv">
                <span class="k">\${escapeHtml(inv.invoice_number)} <span class="p-cell-muted" style="font-size:12px;">· \${escapeHtml(inv.created_at)}</span></span>
                <span class="v">\${escapeHtml(inv.amount)} \${escapeHtml(inv.currency || 'USD')} <span class="p-badge status-\${escapeHtml(inv.status)}" style="margin-inline-start:8px;">\${escapeHtml(inv.status)}</span></span>
            </div>
        `).join('');
    }

    // ============ سجل النشاط ============
    let auditPage = 1;
    let auditHasNext = false;

    async function loadAuditLog() {
        const body = document.getElementById('auditLogBody');
        body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tLoadingJs})}</td></tr>`;

        const qs = new URLSearchParams({
            page: String(auditPage),
            search: document.getElementById('auditSearch').value.trim(),
            from: document.getElementById('auditFrom').value,
            to: document.getElementById('auditTo').value,
            result: document.getElementById('auditResult').value,
            action: document.getElementById('auditAction').value.trim(),
        });

        const res = await fetchJSON('/api/user/audit-log?' + qs.toString());
        if (!res.success) {
            body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tAuditLoadFailed})}</td></tr>`;
            return;
        }

        const rows = res.data.rows || [];
        if (rows.length === 0) {
            body.innerHTML = `<tr><td colspan="4" class="p-cell-muted" style="padding:14px;">\${esc({$tAuditEmpty})}</td></tr>`;
        } else {
            body.innerHTML = rows.map(r => `
                <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
                    <td style="padding:8px;">\${escapeHtml(r.action)}</td>
                    <td style="padding:8px;">\${r.object_type ? escapeHtml(r.object_type) + (r.object_id ? ' #' + escapeHtml(r.object_id) : '') : '-'}</td>
                    <td style="padding:8px;"><span class="p-badge status-\${r.result === 'success' ? 'active' : 'suspended'}">\${escapeHtml(r.result)}</span></td>
                    <td style="padding:8px;" class="p-cell-muted">\${escapeHtml(r.created_at)}</td>
                </tr>
            `).join('');
        }

        const total = res.data.total || 0;
        const perPage = res.data.per_page || 20;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        auditHasNext = auditPage < totalPages;
        document.getElementById('auditPageInfo').textContent = {$tAuditPageOf}.replace('{page}', auditPage).replace('{total}', totalPages);
        document.getElementById('auditPrevBtn').disabled = auditPage <= 1;
        document.getElementById('auditNextBtn').disabled = !auditHasNext;
    }

    document.getElementById('auditFilterBtn').addEventListener('click', () => { auditPage = 1; loadAuditLog(); });
    document.getElementById('auditPrevBtn').addEventListener('click', () => { if (auditPage > 1) { auditPage--; loadAuditLog(); } });
    document.getElementById('auditNextBtn').addEventListener('click', () => { if (auditHasNext) { auditPage++; loadAuditLog(); } });

    document.getElementById('auditExportBtn').addEventListener('click', async function () {
        const btn = this;
        const originalLabel = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ ' + esc({$tAuditExporting});
        try {
            const qsBase = new URLSearchParams({
                search: document.getElementById('auditSearch').value.trim(),
                from: document.getElementById('auditFrom').value,
                to: document.getElementById('auditTo').value,
                result: document.getElementById('auditResult').value,
                action: document.getElementById('auditAction').value.trim(),
            });
            let csvParts = [];
            let filename = 'tourfecto-audit-log-' + new Date().toISOString().slice(0, 10) + '.csv';
            let offset = 0;
            let hasMore = true;
            while (hasMore) {
                const qs = new URLSearchParams(qsBase);
                qs.set('offset', String(offset));
                qs.set('limit', '5000');
                const res = await fetchJSON('/api/user/audit-log/export?' + qs.toString());
                if (!res.success || !res.data || typeof res.data.csv !== 'string') {
                    toast({$tAuditExportFailed}, 'error');
                    return;
                }
                if (res.data.filename) filename = res.data.filename;
                if (res.data.csv !== '') csvParts.push(res.data.csv);
                hasMore = !!(res.data.has_more);
                offset += res.data.limit || 0;
                if (offset >= 50000) break;
            }
            if (csvParts.length === 0) {
                toast({$tAuditExportFailed}, 'error');
                return;
            }
            const blob = new Blob(['\uFEFF' + csvParts.join('\\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } finally {
            btn.innerHTML = originalLabel;
            btn.disabled = false;
        }
    });

    // ============ الـ Workspace ============
    let wsIsOwner = true;
    let wsCanManageWorkspace = true;
    let wsCanManageTeam = true;

    async function loadWorkspace() {
        const res = await fetchJSON('/api/workspace');
        if (!res.success) { toast({$tWorkspaceLoadFailed}, 'error'); return; }

        const w = res.data.workspace;
        wsIsOwner = res.data.is_owner;
        wsCanManageWorkspace = res.data.can_manage_workspace;
        wsCanManageTeam = res.data.can_manage_team;

        document.getElementById('ws_name').value = w.name || '';
        document.getElementById('ws_industry').value = w.industry || '';
        document.getElementById('ws_country').value = w.country_code || '';
        document.getElementById('ws_timezone').value = w.timezone || '';
        document.getElementById('ws_language').value = w.default_language || 'ar';
        if (w.logo_url) {
            const img = document.getElementById('ws_logo_preview');
            img.src = w.logo_url;
            img.style.display = 'inline-block';
        }

        const readOnly = !wsCanManageWorkspace;
        ['ws_name', 'ws_industry', 'ws_country', 'ws_timezone', 'ws_language', 'ws_logo_input'].forEach(id => {
            document.getElementById(id).disabled = readOnly;
        });
        document.getElementById('workspaceSaveBtn').style.display = readOnly ? 'none' : 'inline-block';
        document.getElementById('workspaceReadOnlyNotice').style.display = readOnly ? 'block' : 'none';

        document.getElementById('teamInviteBox').style.display = wsCanManageTeam ? 'block' : 'none';
        document.getElementById('leaveWorkspaceBox').style.display = wsIsOwner ? 'none' : 'block';
    }

    document.getElementById('workspaceForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('workspaceAlert');
        const saveBtn = document.getElementById('workspaceSaveBtn');
        const savingIndicator = document.getElementById('workspaceSavingIndicator');
        alertBox.style.display = 'none';
        document.getElementById('err_ws_name').textContent = '';
        document.getElementById('err_ws_industry').textContent = '';

        saveBtn.disabled = true;
        savingIndicator.style.display = 'inline';
        let res;
        try {
            res = await fetchJSON('/api/workspace', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: document.getElementById('ws_name').value.trim(),
                    industry: document.getElementById('ws_industry').value.trim(),
                    country_code: document.getElementById('ws_country').value.trim(),
                    timezone: document.getElementById('ws_timezone').value.trim(),
                    default_language: document.getElementById('ws_language').value,
                }),
            });
        } finally {
            saveBtn.disabled = false;
            savingIndicator.style.display = 'none';
        }

        if (res.success) {
            toast({$tWorkspaceSaved}, 'success');
        } else {
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_ws_' + field);
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tWorkspaceSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('ws_logo_input').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('logo', file);
        const res = await fetchJSON('/api/workspace/logo', { method: 'POST', body: formData });
        if (res.success) {
            document.getElementById('ws_logo_preview').src = res.data.logo_url;
            document.getElementById('ws_logo_preview').style.display = 'inline-block';
            toast({$tLogoUpdated}, 'success');
        } else {
            toast(res.error || {$tLogoFailed}, 'error');
        }
    });

    // ============ الفريق ============
    async function loadMembers() {
        const box = document.getElementById('membersList');
        const res = await fetchJSON('/api/workspace/members');
        if (!res.success) { box.innerHTML = '<p class="p-cell-muted">' + esc({$tMembersLoadFailed}) + '</p>'; return; }

        box.innerHTML = res.data.members.map(m => {
            const roleControl = (m.role === 'owner' || !wsCanManageTeam || m.is_self)
                ? `<span class="p-badge">\${escapeHtml(m.role)}</span>`
                : `<select class="form-control" style="display:inline-block;width:auto;" data-role-select="\${m.id}">
                    \${['admin','manager','sales','support','viewer'].map(r => `<option value="\${r}" \${r === m.role ? 'selected' : ''}>\${r}</option>`).join('')}
                   </select>`;
            const actions = (m.role === 'owner' || !wsCanManageTeam || m.is_self) ? '' : `
                \${m.status === 'active'
                    ? `<button type="button" class="p-btn outline xs" data-deactivate="\${m.id}">\${esc({$tDeactivateBtnLabel})}</button>`
                    : `<button type="button" class="p-btn outline xs" data-reactivate="\${m.id}">✓</button>`}
                <button type="button" class="p-btn outline xs" data-remove="\${m.id}">\${esc({$tRemoveBtnLabel})}</button>
            `;
            return `
                <div class="p-kv" style="align-items:center;">
                    <span class="k">\${escapeHtml(m.name)} <span class="p-cell-muted" style="font-size:12px;">\${escapeHtml(m.email)}</span>
                        \${m.status !== 'active' ? '<span class="p-badge status-suspended" style="margin-inline-start:6px;">' + escapeHtml(m.status) + '</span>' : ''}
                    </span>
                    <span class="v" style="display:flex;gap:8px;align-items:center;">\${roleControl} \${actions}</span>
                </div>
            `;
        }).join('');

        box.querySelectorAll('[data-role-select]').forEach(sel => {
            sel.addEventListener('change', async function () {
                const id = this.getAttribute('data-role-select');
                const res = await fetchJSON('/api/workspace/members/' + id + '/role', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ role: this.value }),
                });
                if (res.success) { toast({$tRoleChanged}, 'success'); loadMembers(); }
                else { toast(res.error || {$tRoleChangeFailed}, 'error'); loadMembers(); }
            });
        });
        box.querySelectorAll('[data-deactivate]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tDeactivateMemberConfirm})) return;
                const id = this.getAttribute('data-deactivate');
                const res = await fetchJSON('/api/workspace/members/' + id + '/deactivate', { method: 'POST' });
                if (res.success) { toast({$tMemberDeactivated}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
        box.querySelectorAll('[data-reactivate]').forEach(btn => {
            btn.addEventListener('click', async function () {
                const id = this.getAttribute('data-reactivate');
                const res = await fetchJSON('/api/workspace/members/' + id + '/reactivate', { method: 'POST' });
                if (res.success) { toast({$tMemberReactivated}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
        box.querySelectorAll('[data-remove]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRemoveMemberConfirm})) return;
                const id = this.getAttribute('data-remove');
                const res = await fetchJSON('/api/workspace/members/' + id, { method: 'DELETE' });
                if (res.success) { toast({$tMemberRemoved}, 'success'); loadMembers(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
    }

    async function loadPendingInvites() {
        const box = document.getElementById('pendingInvitesList');
        const res = await fetchJSON('/api/workspace/invites');
        if (!res.success) { box.style.display = 'none'; return; }
        const invites = res.data.invites || [];
        if (invites.length === 0) { box.innerHTML = '<p>' + esc({$tNoInvites}) + '</p>'; return; }
        box.innerHTML = invites.map(inv => `
            <div class="p-kv">
                <span class="k">\${escapeHtml(inv.email)} <span class="p-badge" style="margin-inline-start:6px;">\${escapeHtml(inv.role)}</span></span>
                <span class="v">
                    <span class="p-badge status-\${inv.status === 'pending' ? 'pending' : 'suspended'}">\${escapeHtml(inv.status)}</span>
                    <button type="button" class="p-btn outline xs" data-revoke-invite="\${inv.id}">✕</button>
                </span>
            </div>
        `).join('');
        box.querySelectorAll('[data-revoke-invite]').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm({$tRevokeInviteConfirm})) return;
                const id = this.getAttribute('data-revoke-invite');
                const res = await fetchJSON('/api/workspace/invites/' + id + '/revoke', { method: 'POST' });
                if (res.success) { toast({$tInviteRevoked}, 'success'); loadPendingInvites(); }
                else toast(res.error || {$tUpdateFailed}, 'error');
            });
        });
    }

    document.getElementById('sendInviteBtn').addEventListener('click', async function () {
        const email = document.getElementById('invite_email').value.trim();
        const role = document.getElementById('invite_role').value;
        const errEl = document.getElementById('err_invite_email');
        errEl.textContent = '';

        const btn = this;
        btn.disabled = true;
        let res;
        try {
            res = await fetchJSON('/api/workspace/invite', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, role }),
            });
        } finally {
            btn.disabled = false;
        }

        if (res.success) {
            document.getElementById('invite_email').value = '';
            const resultBox = document.getElementById('inviteResultBox');
            resultBox.style.display = 'block';
            resultBox.innerHTML = escapeHtml(res.message || {$tInviteSent}) +
                (res.data.email_sent ? '' : `<br><code style="direction:ltr;display:inline-block;margin-top:6px;">\${escapeHtml(res.data.invite_url)}</code> <button type="button" class="p-btn outline xs" id="copyInviteLinkBtn">\${esc({$tCopyLink})}</button>`);
            if (!res.data.email_sent) {
                document.getElementById('copyInviteLinkBtn').addEventListener('click', () => {
                    navigator.clipboard.writeText(res.data.invite_url).then(() => toast({$tLinkCopied}, 'success'));
                });
            }
            toast(res.message || {$tInviteSent}, 'success');
            loadPendingInvites();
        } else {
            if (res.details && res.details.email) {
                errEl.textContent = Array.isArray(res.details.email) ? res.details.email[0] : res.details.email;
            } else {
                toast(res.error || {$tInviteFailed}, 'error');
            }
        }
    });

    // ============ اللغة والمنطقة ============
    document.getElementById('generalForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('generalAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                language: document.getElementById('language').value,
                timezone: document.getElementById('timezone').value,
            }),
        });

        if (res.success) {
            toast({$tSettingsSaved}, 'success');
        } else {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('leaveWorkspaceForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tLeaveWorkspaceConfirm})) return;

        const alertBox = document.getElementById('leaveWorkspaceAlert');
        const errEl = document.getElementById('err_leave_password');
        alertBox.style.display = 'none';
        errEl.textContent = '';

        const res = await fetchJSON('/api/workspace/leave', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: document.getElementById('leave_password').value }),
        });

        if (res.success) {
            toast({$tLeftWorkspace}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            if (res.details && res.details.current_password) {
                errEl.textContent = Array.isArray(res.details.current_password) ? res.details.current_password[0] : res.details.current_password;
            }
            alertBox.textContent = res.error || {$tLeaveFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('deactivateForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tDeactivateConfirm})) return;

        const alertBox = document.getElementById('deactivateAlert');
        const errEl = document.getElementById('err_deactivate_password');
        alertBox.style.display = 'none';
        errEl.textContent = '';

        const res = await fetchJSON('/api/user/deactivate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current_password: document.getElementById('deactivate_password').value }),
        });

        if (res.success) {
            toast({$tAccountDeactivated}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            if (res.details && res.details.current_password) {
                errEl.textContent = Array.isArray(res.details.current_password) ? res.details.current_password[0] : res.details.current_password;
            }
            alertBox.textContent = res.error || {$tDeactivateFailed};
            alertBox.style.display = 'block';
        }
    });

    document.getElementById('deleteAccountForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        if (!confirm({$tDeleteConfirm1})) return;
        if (!confirm({$tDeleteConfirm2})) return;

        const alertBox = document.getElementById('deleteAccountAlert');
        document.getElementById('err_delete_password').textContent = '';
        document.getElementById('err_delete_confirm_email').textContent = '';
        alertBox.style.display = 'none';

        const payload = {
            current_password: document.getElementById('delete_password').value,
            confirm_email: document.getElementById('delete_confirm_email').value,
        };

        let res = await fetchJSON('/api/user/account', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        // Profile Center Phase 3: عندك اشتراك مدفوع فعّال - نوضّح للمستخدم
        // إن الحذف مش هيلغي الاشتراك تلقائيًا عند مزوّد الدفع، ونطلب تأكيد
        // واعي إضافي قبل ما نكمل فعليًا.
        if (!res.success && res.details && res.details.subscription) {
            if (!confirm(res.error + '\\n\\n' + {$tDeleteConfirmSubscription})) return;
            payload.acknowledge_active_subscription = '1';
            res = await fetchJSON('/api/user/account', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
        }

        if (res.success) {
            toast({$tAccountDeleted}, 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            const fieldMap = { current_password: 'delete_password', confirm_email: 'delete_confirm_email' };
            if (res.details && typeof res.details === 'object') {
                Object.keys(res.details).forEach(field => {
                    const err = document.getElementById('err_' + (fieldMap[field] || field));
                    if (err) err.textContent = Array.isArray(res.details[field]) ? res.details[field][0] : res.details[field];
                });
            }
            alertBox.textContent = res.error || {$tDeleteFailed};
            alertBox.style.display = 'block';
        }
    });

    // ============ تصدير بيانات الحساب (Profile Center Phase 9) ============
    window.requestDataExport = async function () {
        const alertBox = document.getElementById('exportAlert');
        alertBox.style.display = 'none';
        const res = await fetchJSON('/api/user/data-export', { method: 'POST' });
        if (res.success) {
            toast({$tExportRequested}, 'success');
            loadDataExports();
        } else {
            alertBox.textContent = res.error || {$tSaveFailed};
            alertBox.style.display = 'block';
        }
    };

    window.loadDataExports = async function () {
        const list = document.getElementById('exportsList');
        const res = await fetchJSON('/api/user/data-export');
        if (!res.success || !res.data.exports || res.data.exports.length === 0) {
            list.innerHTML = '';
            return;
        }
        const statusLabels = {
            requested: {$tExportRequestedStatus},
            processing: {$tExportProcessingStatus},
            ready: {$tExportReadyStatus},
            failed: {$tExportFailedStatus},
        };
        list.innerHTML = res.data.exports.map(function (e) {
            const label = statusLabels[e.status] || e.status;
            const downloadBtn = e.status === 'ready'
                ? '<a class="p-btn outline" href="/profile/data-export/download/' + e.id + '">' + {$tDownload} + '</a>'
                : '';
            return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #eee;">'
                + '<div><span>' + label + '</span>'
                + '<div class="p-cell-muted" style="font-size:12px;">' + (e.requested_at || '') + '</div></div>'
                + downloadBtn
                + '</div>';
        }).join('');
    };

    loadNotifications();
    loadSessions();
    loadApiKeys();
    loadBilling();
    loadAuditLog();
    loadWorkspace();
    loadMembers();
    loadPendingInvites();
    loadDataExports();
})();
JS;
