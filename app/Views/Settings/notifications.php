<?php

/**
 * View: Settings - notifications section (extracted Phase 16F).
 */
return <<<HTML
        <div class="settings-section" id="section_notifications" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$tNotifPrefs}</h3></div>
                <p class="p-cell-muted" style="font-size:12.5px;">{$tNotifChannelNote}</p>

                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_reviews"> {$tNotifReviewsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_content"> {$tNotifContentCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_leads"> {$tNotifLeadsCat}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_cat_system"> {$tNotifSystemCat}
                </label>

                <div style="height:1px;background:var(--panel-border,#eee);margin:8px 0 16px;"></div>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_digest_daily"> {$tNotifDigestDaily}
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notif_digest_weekly"> {$tNotifDigestWeekly}
                </label>
                <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">{$tNotifDigestHint}</p>

                <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">ℹ️ {$tNotifUnavailableCats}</p>

                <div id="notifAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" id="notifSaveBtn" onclick="saveNotifications()">{$tSavePrefs}</button>
                <span id="notifSavingIndicator" style="display:none;font-size:13px;color:var(--panel-text-muted,#888);margin-inline-start:10px;">{$tSaving}</span>
            </div>

            <div class="p-card" id="mbCard" style="margin-top:16px;">
                <div class="p-card-head"><h3>☀️ ملخص الصبح على واتساب</h3><span class="p-card-sub">كل يوم الصبح: حجوزات وفلوس امبارح، رحلات النهارده، واللي مستنيك ترد عليه</span></div>
                <label style="display:flex;align-items:center;gap:10px;margin:10px 0;font-weight:700;"><input type="checkbox" id="mbEnabled"> ابعتلي ملخص كل يوم الصبح</label>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:150px;"><label class="form-label">الساعة</label><select id="mbHour" class="form-control"></select></div>
                    <div style="flex:1;min-width:150px;"><label class="form-label">يوصلني على</label>
                        <select id="mbChannel" class="form-control">
                            <option value="auto">واتساب، ولو ماوصلش إيميل</option>
                            <option value="whatsapp">واتساب بس</option>
                            <option value="email">إيميل بس</option>
                        </select></div>
                    <div style="flex:1.3;min-width:180px;"><label class="form-label">رقم الواتساب (بكود الدولة)</label><input id="mbPhone" class="form-control" dir="ltr" placeholder="201001234567"></div>
                    <div style="flex:.8;min-width:120px;"><label class="form-label">اللغة</label><select id="mbLang" class="form-control"><option value="ar">عربي</option><option value="en">English</option></select></div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin:10px 0;font-size:13px;"><input type="checkbox" id="mbSkipQuiet" checked> ماتبعتش في الأيام اللي مفيهاش أي جديد</label>
                <div id="mbWaHint" class="p-cell-muted" style="font-size:12px;display:none;margin-bottom:8px;">ℹ️ مفيش رقم واتساب مربوط لسه (لا للمنصة ولا لحسابك من التكاملات)، فالملخص هيوصلك على الإيميل لحد ما يتربط.</div>
                <label class="form-label">شكل ملخص النهارده</label>
                <div id="mbPreview" dir="rtl" style="white-space:pre-wrap;background:var(--panel-card-bg-2,#f5f7fb);border-radius:12px;padding:12px 14px;font-size:13.5px;line-height:1.8;margin-bottom:12px;">...</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="p-btn primary" onclick="mbSave()">💾 احفظ</button>
                    <button type="button" class="p-btn outline" onclick="mbSendNow()">📲 ابعتهولي دلوقتي</button>
                </div>
            </div>
            <script>
            (function () {
                function el(id) { return document.getElementById(id); }
                function note(msg, ok) { if (window.Panel && window.Panel.toast) window.Panel.toast(msg, ok ? 'success' : 'error'); else alert(msg); }
                async function call(url, body) {
                    const opt = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
                    if (body) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; body.csrf_token = window.TF_CSRF_TOKEN || ''; opt.body = JSON.stringify(body); }
                    const r = await fetch(url, opt); try { return await r.json(); } catch (e) { return { success: false, error: 'HTTP ' + r.status }; }
                }
                const hs = el('mbHour');
                for (let h = 5; h <= 12; h++) { const o = document.createElement('option'); o.value = h; o.textContent = h === 12 ? '12 الضهر' : h + ' الصبح'; hs.appendChild(o); }
                call('/api/morning-brief').then(function (r) {
                    if (!r.success) { el('mbCard').style.display = 'none'; return; }
                    const s = r.data.settings;
                    el('mbEnabled').checked = Number(s.enabled) === 1;
                    hs.value = String(s.send_hour);
                    if (!hs.value) hs.value = '8';
                    el('mbChannel').value = s.channel;
                    el('mbPhone').value = s.phone || '';
                    el('mbSkipQuiet').checked = Number(s.skip_quiet_days) === 1;
                    el('mbWaHint').style.display = s.whatsapp_available ? 'none' : 'block';
                    el('mbLang').value = s.language || 'ar';
                    mbPrev = { ar: r.data.preview_ar || '', en: r.data.preview_en || '' };
                    mbShowPreview();
                });
                let mbPrev = { ar: '', en: '' };
                function mbShowPreview() {
                    const l = el('mbLang').value;
                    el('mbPreview').dir = l === 'en' ? 'ltr' : 'rtl';
                    el('mbPreview').textContent = String(mbPrev[l] || '').split('*').join('');
                }
                el('mbLang').addEventListener('change', mbShowPreview);
                window.mbSave = async function () {
                    const r = await call('/api/morning-brief', {
                        enabled: el('mbEnabled').checked ? 1 : 0, send_hour: parseInt(hs.value, 10), channel: el('mbChannel').value,
                        phone: el('mbPhone').value, skip_quiet_days: el('mbSkipQuiet').checked ? 1 : 0, language: el('mbLang').value,
                    });
                    note(r.success ? r.message : (r.error || 'تعذر الحفظ'), r.success);
                };
                window.mbSendNow = async function () {
                    const r = await call('/api/morning-brief/send-now', {});
                    note(r.success ? r.message : (r.error || 'تعذر الإرسال'), r.success);
                };
            })();
            </script>
        </div>

        <!-- API -->
HTML;
