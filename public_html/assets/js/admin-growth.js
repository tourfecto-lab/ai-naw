/* Tourfecto - لوحة تسويق المنصة بالإيميل (أدمن) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const STATUS = {
        new: ['جديدة - في الدور', 'blue'], no_email: ['مالهاش إيميل', 'gray'], contacted: ['اتبعتلها', 'blue'], finished: ['خلصت الرسايل الـ3', 'gray'],
        replied: ['ردّت ✉️', 'green'], interested: ['مهتمة (ضغطت اللينك) 🔥', 'green'], signed_up: ['سجّلت في المنصة 🎉', 'green'],
        unsubscribed: ['ألغت الاشتراك', 'orange'], bounced: ['الإيميل راجع', 'red'], invalid: ['مستبعدة (إيميل غلط أو مش شركة سياحة)', 'red'], paused: ['متوقفة', 'gray'], queued: ['في الدور', 'blue'],
    };
    const DAYS = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];
    let P, root, data, tplLang = 'ar', tplStep = 1, filters = { status: '', country: '', city: '', track: '', approval: '', q: '', page: 1 };
    const LANG = { ar: 'عربي', en: 'English', tr: 'Türkçe' };
    const COUNTRY = {
        TR: 'تركيا', EG: 'مصر', AE: 'الإمارات', SA: 'السعودية', JO: 'الأردن', MA: 'المغرب', TN: 'تونس', OM: 'عُمان', QA: 'قطر', KW: 'الكويت', BH: 'البحرين', LB: 'لبنان', IQ: 'العراق', SY: 'سوريا', PS: 'فلسطين', DZ: 'الجزائر', LY: 'ليبيا', SD: 'السودان', YE: 'اليمن',
        GR: 'اليونان', CY: 'قبرص', IT: 'إيطاليا', ES: 'إسبانيا', PT: 'البرتغال', FR: 'فرنسا', MT: 'مالطا', DE: 'ألمانيا', AT: 'النمسا', CH: 'سويسرا', NL: 'هولندا', BE: 'بلجيكا', GB: 'بريطانيا', IE: 'أيرلندا', IS: 'آيسلندا', NO: 'النرويج', SE: 'السويد', FI: 'فنلندا', DK: 'الدنمارك',
        CZ: 'التشيك', PL: 'بولندا', HU: 'المجر', HR: 'كرواتيا', SI: 'سلوفينيا', ME: 'الجبل الأسود', AL: 'ألبانيا', BA: 'البوسنة', RS: 'صربيا', MK: 'مقدونيا الشمالية', BG: 'بلغاريا', RO: 'رومانيا', SK: 'سلوفاكيا', EE: 'إستونيا', LV: 'لاتفيا', LT: 'ليتوانيا', UA: 'أوكرانيا', RU: 'روسيا',
        GE: 'جورجيا', AZ: 'أذربيجان', AM: 'أرمينيا', UZ: 'أوزبكستان', KZ: 'كازاخستان', KG: 'قيرغيزستان', IL: 'إسرائيل', IR: 'إيران', US: 'أمريكا', CA: 'كندا', MX: 'المكسيك', BR: 'البرازيل', AR: 'الأرجنتين', CL: 'تشيلي', PE: 'بيرو', CO: 'كولومبيا', EC: 'الإكوادور', BO: 'بوليفيا',
        CR: 'كوستاريكا', PA: 'بنما', GT: 'جواتيمالا', BZ: 'بليز', CU: 'كوبا', DO: 'الدومينيكان', JM: 'جامايكا', TH: 'تايلاند', ID: 'إندونيسيا', MY: 'ماليزيا', MV: 'المالديف', VN: 'فيتنام', KH: 'كمبوديا', LA: 'لاوس', PH: 'الفلبين', SG: 'سنغافورة', JP: 'اليابان', KR: 'كوريا الجنوبية', CN: 'الصين',
        TW: 'تايوان', HK: 'هونج كونج', IN: 'الهند', LK: 'سريلانكا', NP: 'نيبال', BT: 'بوتان', PK: 'باكستان', BD: 'بنجلاديش', AU: 'أستراليا', NZ: 'نيوزيلندا', FJ: 'فيجي', ZA: 'جنوب أفريقيا', KE: 'كينيا', TZ: 'تنزانيا', UG: 'أوغندا', RW: 'رواندا', ET: 'إثيوبيا', NA: 'ناميبيا',
        BW: 'بوتسوانا', ZW: 'زيمبابوي', ZM: 'زامبيا', MG: 'مدغشقر', MU: 'موريشيوس', SC: 'سيشل', GH: 'غانا', NG: 'نيجيريا', SN: 'السنغال', CV: 'الرأس الأخضر', LU: 'لوكسمبورج', MC: 'موناكو',
    };
    const flag = c => /^[A-Z]{2}$/.test(c) ? String.fromCodePoint(...[...c].map(x => 0x1F1E6 + x.charCodeAt(0) - 65)) : '🌐';
    const countryName = c => c === '?' || !c ? 'دولة مش معروفة' : (COUNTRY[c] || c);
    const trackName = t => (data?.stats?.track_labels || {})[t] || t || '—';

    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: data?.csrf_token }) } : undefined);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        return r.data;
    };
    const toast = (msg, bad) => { const el = root.querySelector('#grMsg'); el.textContent = msg; el.className = 'gr-msg' + (bad ? ' bad' : ''); el.hidden = !msg; if (msg) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); };
    const run = async (btn, fn) => { if (btn) btn.disabled = true; try { await fn(); } catch (e) { toast(e.message, true); } finally { if (btn) btn.disabled = false; } };
    const v = id => root.querySelector('#' + id);
    const tested = () => { try { return !!localStorage.getItem('gr-tested'); } catch (e) { return false; } };

    const render = () => {
        const s = data.stats, st = data.settings, by = s.by_status || {};
        const cs = s.country_stats || [];
        const cities = (cs.find(c => c.country === filters.country) || {}).cities || {};
        const withEmail = s.total - (by.no_email || 0) - (by.invalid || 0);
        const pct = (a, b) => b ? Math.round(a * 100 / b) + '%' : '—';
        const on = st.enabled === '1';
        // «شغّال» من غير ولا رسالة جاهزة كان بيبان أخضر - والحملة شكلها شغالة بقالها أيام ومابتبعتش
        const idle = on && !(s.pending > 0);
        // أيام الإجازة لازم تكون بتاعة دولة الشركات (تركيا: السبت والحد، مش الجمعة)
        const skip = String(st.skip_days || '').split(',').map(x => x.trim()).filter(Boolean);
        const weekendWarn = /Istanbul|Europe\//.test(st.timezone || '') && skip.indexOf('5') !== -1 && (skip.indexOf('6') === -1 || skip.indexOf('0') === -1)
            ? '<p class="gr-muted" style="color:#B54708">⚠️ الإجازة في تركيا وأوروبا السبت والحد، والإعدادات مخلّية الجمعة بس. الرسايل هتوصل الشركات يوم السبت والحد ومحدش هيقراها - عدّل «أيام مايبعتش فيها» تحت.</p>' : '';
        const checks = [
            [!!st.from_email, 'إيميل المرسل متظبط'],
            [!!st.smtp_host, 'SMTP مخصص للتسويق (مش إيميل المنصة الأساسي)'],
            [s.total > 0, 'استوردت شركات'],
            [s.total > 0 && !s.unscanned, 'اتفحصت المواقع واتحدد احتياج كل شركة'],
            [!!st.whatsapp_number, 'رقم واتساب للزرار اللي في الإيميل والتقرير (اختياري)'],
            [tested(), 'بعتّ رسالة تجربة لنفسك وشفت شكلها'],
            [on, 'شغّلت الإرسال التلقائي'],
        ];
        root.innerHTML = `
<header class="gr-hero"><div><span class="gr-eye">TOURFECTO / GROWTH</span><h1>تسويق المنصة لشركات السياحة</h1><p>استورد الشركات، والمنصة تفحص موقع كل شركة وتحدد أكبر احتياج عندها (سمعة، حجز مباشر، موقع، جوجل، سوشيال، إعلانات، رد على الزباين، CRM)، وتبعتلها مسار رسايل عن مشكلتها هي بأرقامها، وبعدين مسار تاني لاحتياج مختلف. وتقف لوحدها لو الشركة ردّت أو سجّلت أو ألغت.</p></div>
<div class="gr-switch ${on && !idle ? 'on' : ''}"><b>${on ? (idle ? '🟠 شغّال بس مفيش حاجة يبعتها' : '🟢 الإرسال التلقائي شغال') : '⏸ الإرسال متوقف'}</b><span>${s.paused_reason === 'bounces' ? '⚠️ وقفناه لوحده لأن إيميلات كتير رجعت. نضّف القائمة وبعدين شغّله.' : on ? (idle ? (s.awaiting_approval ? 'مفيش ولا شركة موافق عليها - وافق على الشركات (تحت) عشان الرسايل تبدأ تطلع.' : 'مفيش شركات جاهزة للإرسال - استورد شركات وافحص مواقعها.') : (s.in_window ? 'بيبعت دلوقتي في مواعيد العمل' : 'هيبعت في مواعيد العمل الجاية')) : 'شغّله بعد ما تجرب رسالة على نفسك'}</span><button class="p-btn ${on ? 'outline' : ''}" data-toggle>${on ? 'وقّف الإرسال' : 'شغّل الإرسال'}</button></div></header>
<div id="grMsg" class="gr-msg" hidden></div>
<section class="gr-funnel">
 ${(() => { const f = s.funnel || {}; const ch = f.channels || {}; const em = ch.email || {}; const wa = ch.whatsapp || {};
  const step = (l, n, sub, hint) => `<div class="gr-fstep"><span>${l}</span><b>${n}</b><small>${sub || ''}</small></div>`;
  return step('شركات صالحة', f.valid || 0, f.invalid ? f.invalid + ' مستبعدة' : '') +
   step('وصلنا لها', f.reached || 0, pct(f.reached, f.valid) + ' من الصالحة · إيميل ' + (f.reached_email || 0) + ' · واتساب ' + (f.reached_whatsapp || 0)) +
   step('فتحوا التقرير', f.viewed || 0, pct(f.viewed, f.reached) + ' ممن وصلنا لهم') +
   step('ردّوا / مهتمين', f.replied || 0, pct(f.replied, f.reached) + ' ممن وصلنا لهم') +
   step('سجّلوا 🎉', f.signed_up || 0, pct(f.signed_up, f.reached) + ' ممن وصلنا لهم'); })()}
</section>
<section class="gr-box gr-chan"><h3>القنوات</h3>
 ${(() => { const ch = (s.funnel || {}).channels || {}; const em = ch.email || {}; const wa = ch.whatsapp || {};
  return `<table class="gr-table"><thead><tr><th>القناة</th><th>وصلنا لها</th><th>فتحوا التقرير</th><th>ردّوا</th><th>سجّلوا / جرّبوا</th></tr></thead><tbody>
  <tr><td>📧 إيميل (تلقائي)</td><td>${em.reached || 0}</td><td>${em.viewed || 0} <span class="gr-muted">${pct(em.viewed || 0, em.reached || 0)}</span></td><td>${(by.replied || 0) + (by.interested || 0)}</td><td>${em.signed_up || 0}</td></tr>
  <tr><td>💬 واتساب (الفريق يدوي)</td><td>${wa.reached || 0}</td><td class="gr-muted">—</td><td>${wa.replied || 0} <span class="gr-muted">${pct(wa.replied || 0, wa.reached || 0)}</span></td><td>${(wa.trial || 0)} / ${(wa.subscribed || 0)}</td></tr></tbody></table>`; })()}
 <p class="gr-muted">الشركة اللي اتواصل معاها بالإيميل والواتساب بتتحسب مرة واحدة في القمع فوق. فتح التقرير بيتحسب على الشركة مش على القناة.</p>
</section>
<section class="gr-mini">
 ${[['مستنية موافقتك', s.awaiting_approval || 0], ['مالهاش إيميل', by.no_email || 0], ['واقفة (Paused)', by.paused || 0], ['ألغوا الاشتراك', by.unsubscribed || 0], ['إيميل راجع', by.bounced || 0]].map(([l, n]) => `<div><span>${l}</span><b>${n}</b></div>`).join('')}
</section>
<section class="gr-today"><div><b>النهارده:</b> اتبعت ${s.today.sent} من ${s.today.limit} <span class="gr-muted">(الحد بيزيد كل يوم لحد ${esc(st.daily_limit)} عشان الإيميل مايدخلش سبام)</span></div><div><b>مستني الإرسال:</b> ${s.pending} رسالة · <span class="gr-muted">آخر تشغيل: ${esc(s.last_run || 'لسه')}</span></div>
<div class="gr-actions"><button class="p-btn outline sm" data-scan>افحص المواقع وحدد الاحتياج (${s.unscanned || 0})</button><button class="p-btn sm" data-approve-tours ${s.awaiting_tours ? '' : 'disabled'} title="اللي تصنيفها فيه Tours أو رحلات أو كروز أو سفاري - من غير السبا والملاهي والمطاعم والنقل">وافق على شركات الرحلات بس (${s.awaiting_tours || 0})</button><button class="p-btn outline sm" data-approve-all ${s.awaiting_approval ? '' : 'disabled'}>وافق على كل اللي مستنية (${s.awaiting_approval || 0})</button><button class="p-btn outline sm" data-run="send">ابعت دفعة دلوقتي</button><button class="p-btn outline sm" data-run="enrich">دوّر على إيميلات الشركات الناقصة (${by.no_email || 0})</button></div>
${st.require_approval !== '0' && s.awaiting_approval ? `<p class="gr-muted">⏳ ${s.awaiting_approval} شركة مستنية موافقتك قبل أول رسالة. افتح «معاينة» جنب أي شركة تحت، وشوف الرسالة بالظبط، وبعدين وافق.</p>` : ''}${weekendWarn}</section>

<section class="gr-box"><h2>🧭 المسارات (كل شركة على حسب احتياجها)</h2>
<div class="gr-cards">${Object.entries(s.tracks || {}).map(([t, n]) => `<button class="gr-card gr-card-btn" data-track-filter="${esc(t)}"><span>${esc(trackName(t))}</span><b>${n}</b></button>`).join('') || '<p class="gr-muted">لسه مفيش شركات اتشخّصت. اضغط «افحص المواقع» فوق.</p>'}</div>
${(s.track_stats || []).length ? `<h3>أداء كل مسار في كل دولة</h3><p class="gr-muted">ده اللي بيقولك أنهي احتياج بيجيب عملاء أكتر في أنهي بلد. ركّز على اللي بيجيب تسجيل.</p><table class="gr-table"><thead><tr><th>المسار</th><th>الدولة</th><th>شركات</th><th>رسايل</th><th>فتحوا التقرير</th><th>ضغطوا</th><th>مهتمين</th><th>سجّلوا</th></tr></thead><tbody>${s.track_stats.map(r => `<tr><td>${esc(trackName(r.track))}</td><td>${flag(r.country)} ${esc(countryName(r.country))}</td><td>${r.leads}</td><td>${r.sent}</td><td>${r.report_views} <span class="gr-muted">${pct(+r.report_views, +r.leads)}</span></td><td>${r.clicks}</td><td>${r.interested}</td><td><b>${r.signed_up}</b> <span class="gr-muted">${pct(+r.signed_up, +r.leads)}</span></td></tr>`).join('')}</tbody></table>` : ''}
</section>
<section class="gr-box"><h2>🌍 الدول</h2>
<p class="gr-muted">الدولة بتتعرف لوحدها من الملف، ولو مش مكتوبة فيه، من كود التليفون أو امتداد الموقع (‎.com.tr، ‎.gr…). اللغة بتتحدد منها: الدول العربية بالعربي، وتركيا بالتركي، والباقي بالإنجليزي. وكل شركة بتتقارن بمنافسينها في نفس مدينتها.</p>
${cs.length ? `<div class="gr-scroll"><table class="gr-table gr-countries"><thead><tr><th>الدولة</th><th>الشركات</th><th>ليها إيميل</th><th>مستنية موافقتك</th><th>اتبعتلها</th><th>فتحوا التقرير</th><th>ضغطوا</th><th>مهتمين</th><th>سجّلوا</th><th>متوسط الدرجة</th><th>أكتر احتياج</th><th></th></tr></thead><tbody>${cs.map(c => `<tr class="${filters.country === c.country ? 'gr-sel' : ''}">
<td><b>${flag(c.country)} ${esc(countryName(c.country))}</b><div class="gr-muted">${Object.entries(c.cities).slice(0, 4).map(([n, k]) => `${esc(n)} (${k})`).join(' · ')}${Object.keys(c.cities).length > 4 ? ` · +${Object.keys(c.cities).length - 4} مدينة` : ''}</div></td>
<td>${c.total}${c.invalid ? ` <span class="gr-muted">(${c.invalid} مستبعدة)</span>` : ''}</td><td>${c.with_email}</td><td>${c.awaiting || '—'}</td><td>${c.contacted || '—'}</td><td>${c.reports || '—'}</td><td>${c.clicks || '—'}</td><td>${c.interested || '—'}</td><td><b>${c.signed_up || '—'}</b></td>
<td>${c.avg_score !== null ? c.avg_score + '/100' : '—'}</td><td>${Object.entries(c.tracks).map(([t, n]) => `${esc(trackName(t))} <span class="gr-muted">(${n})</span>`).join('<br>') || '—'}</td>
<td class="gr-nowrap"><button class="p-btn outline xs" data-country-filter="${esc(c.country)}">اعرض شركاتها</button>${c.awaiting && c.country !== '?' ? ` <button class="p-btn xs" data-approve-country="${esc(c.country)}" data-n="${c.awaiting}">وافق على الـ ${c.awaiting}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="gr-muted">لسه مفيش شركات.</p>'}
</section>
<section class="gr-box"><h2>✅ قبل ما تشغّل</h2><ul class="gr-checks">${checks.map(([ok, t]) => `<li class="${ok ? 'ok' : ''}">${ok ? '✔' : '○'} ${t}</li>`).join('')}</ul>
<p class="gr-muted">نصيحة مهمة: ابعت من دومين أو إيميل منفصل (زي hello@try-tourfecto.com) مش من tourfecto.pro نفسه، عشان لو حد عمل Spam مايأثرش على إيميلات المنصة (التسجيل والفواتير). وفعّل SPF وDKIM وDMARC للدومين ده.</p></section>

<section class="gr-box"><h2>1) استيراد الشركات</h2>
<div class="gr-row"><input id="grUrl" class="p-select" dir="ltr" placeholder="https://api.apify.com/v2/datasets/.../items?..." value="${esc(st.source_url)}"><button class="p-btn" data-import>استورد / حدّث</button></div>
<p class="gr-muted">بيقبل رابط Dataset من Apify (Google Maps أو Contact scraper) أو أي رابط JSON. ممكن تستورد نفس الرابط تاني بعد ما تجيب بيانات جديدة: مفيش تكرار، وعملاء المنصة الحاليين بيتشالوا لوحدهم.</p>
<div class="gr-row"><input type="file" id="grFile" class="p-select" accept=".json,.csv,application/json,text/csv"><button class="p-btn" data-import-file>ارفع الملف</button></div>
<p class="gr-muted">ملف JSON من Apify (TripAdvisor أو Google Maps) أو CSV، بأي حجم: بيتبعت على دفعات صغيرة، ومعالم السياحة والمتاحف بتتشال لوحدها.</p>
<details><summary>أو الصق البيانات (JSON أو CSV فيه عمود email)</summary><textarea id="grText" class="p-select" rows="5" dir="ltr" placeholder="company,email,website,city,country"></textarea><button class="p-btn outline sm" data-import-text>استورد اللي اتلزق</button></details></section>

<section class="gr-box"><h2>2) الرسايل العامة</h2>
<p class="gr-muted">الشركات اللي اتشخّصت بتاخد رسايل مسارها الجاهزة (عربي وإنجليزي وتركي) بأرقامها هي. شوفها من «معاينة» جنب كل شركة. الرسايل دي تحت للشركات القديمة اللي اتبعتلها قبل التشخيص بس.</p>
<div class="gr-tabs">${['ar', 'en'].map(l => `<button class="p-btn xs ${tplLang === l ? '' : 'outline'}" data-lang="${l}">${l === 'ar' ? 'عربي (الدول العربية)' : 'English (باقي الدول)'}</button>`).join('')}<span class="gr-sep"></span>${[1, 2, 3].map(n => `<button class="p-btn xs ${tplStep === n ? '' : 'outline'}" data-step="${n}">${['', 'الرسالة 1: التعارف (يوم 0)', 'الرسالة 2: تحليل مجاني (بعد ' + (st.step_gaps.split(',')[0] || 3) + ' أيام)', 'الرسالة 3: الأخيرة (بعد ' + (st.step_gaps.split(',')[1] || 4) + ' أيام كمان)'][n]}</button>`).join('')}</div>
<div class="gr-split"><div><label class="gr-f">العنوان<input id="grSubj" class="p-select" value="${esc(st.templates[tplLang][tplStep].subject)}"></label><label class="gr-f">الرسالة<textarea id="grBody" class="p-select" rows="16">${esc(st.templates[tplLang][tplStep].body)}</textarea></label>
<p class="gr-muted">متغيرات: {{company}} {{city}} {{rating}} {{reviews}} {{opener}} (أول سطر شخصي) {{signup_link}} (زرار التسجيل) {{website_or_company}} {{sender_name}}</p>
<div class="gr-actions"><button class="p-btn" data-save-tpl>حفظ الرسالة</button><button class="p-btn outline" data-preview>معاينة على شركة حقيقية</button><button class="p-btn outline" data-reset-tpl>رجّع الرسالة الأصلية</button></div></div>
<div><div class="gr-preview-head" id="grPrevHead">المعاينة</div><iframe id="grPreview" class="gr-preview" sandbox title="preview"></iframe>
<div class="gr-row"><input id="grTestTo" class="p-select" dir="ltr" value="${esc(data.admin_email)}"><button class="p-btn outline" data-test>ابعتها لإيميلي</button></div></div></div></section>

<section class="gr-box"><h2>3) الإعدادات</h2><div class="gr-grid">
${f('sender_name', 'اسم المرسل (اسمك)')}${f('sender_title', 'المسمى (مثلًا: مؤسس Tourfecto)')}${f('from_email', 'إيميل المرسل', 'email')}${f('reply_to', 'الردود تروح على', 'email')}
${f('smtp_host', 'SMTP Host (للتسويق)')}${f('smtp_port', 'Port', 'number')}${f('smtp_username', 'SMTP Username')}<label class="gr-f">SMTP Password<input id="s_smtp_password" type="password" class="p-select" placeholder="${st.smtp_password_set ? '•••••• (محفوظ)' : ''}"></label>
<label class="gr-f">التشفير<select id="s_smtp_encryption" class="p-select">${['tls', 'ssl', ''].map(x => `<option value="${x}" ${st.smtp_encryption === x ? 'selected' : ''}>${x || 'بدون'}</option>`).join('')}</select></label>
${f('daily_limit', 'أقصى عدد في اليوم (بعد التسخين)', 'number')}${f('hour_from', 'يبعت من الساعة', 'number')}${f('hour_to', 'لحد الساعة', 'number')}${f('timezone', 'المنطقة الزمنية')}
<label class="gr-f">أيام مايبعتش فيها<div class="gr-days">${DAYS.map((d, i) => `<label><input type="checkbox" data-day="${i}" ${st.skip_days.split(',').includes(String(i)) ? 'checked' : ''}>${d}</label>`).join('')}</div></label>
${f('step_gaps', 'الأيام بين الرسايل (مثلًا 3,4)')}${f('phase2_gap', 'بعد كام يوم يبدأ مسار الاحتياج التاني', 'number')}${f('signup_url', 'لينك التسجيل')}${f('company_address', 'عنوان الشركة (لازم في آخر الإيميل)')}
${f('whatsapp_number', 'رقم واتساب (بكود الدولة، أرقام بس)')}
${f('sender_photo_url', 'صورة المرسل في التوقيع (رابط https لصورة مربعة)')}${f('brand_logo_url', 'لوجو الهيدر (فاضي = لوجو Tourfecto)')}
<label class="gr-f gr-chk"><input type="checkbox" id="s_require_approval" ${st.require_approval !== '0' ? 'checked' : ''}> أوافق بنفسي على أول رسالة لكل شركة قبل ما تتبعت (مستحسن في الأول)</label>
<label class="gr-f gr-chk"><input type="checkbox" id="s_ai_opener" ${st.ai_opener !== '0' ? 'checked' : ''}> الذكاء الصناعي يكتب أول سطر مخصوص لكل شركة</label>
<label class="gr-f gr-chk"><input type="checkbox" id="s_track_opens" ${st.track_opens === '1' ? 'checked' : ''}> تتبع فتح الرسالة (بيقلل الوصول للـ Inbox شوية - الأفضل مقفول)</label>
</div><button class="p-btn" data-save-settings>حفظ الإعدادات</button></section>

<section class="gr-box"><h2>4) الشركات</h2><div class="gr-row">
<select id="grFStatus" class="p-select"><option value="">كل الحالات</option>${Object.entries(STATUS).filter(([k]) => k !== 'queued').map(([k, [l]]) => `<option value="${k}" ${filters.status === k ? 'selected' : ''}>${l} (${by[k] || 0})</option>`).join('')}</select>
<select id="grFCountry" class="p-select"><option value="">كل الدول</option>${Object.entries(s.countries || {}).map(([c, n]) => `<option value="${esc(c)}" ${filters.country === c ? 'selected' : ''}>${flag(c)} ${esc(countryName(c))} (${n})</option>`).join('')}</select>
${filters.country && Object.keys(cities).length > 1 ? `<select id="grFCity" class="p-select"><option value="">كل المدن</option>${Object.entries(cities).map(([n, k]) => `<option value="${esc(n)}" ${filters.city === n ? 'selected' : ''}>${esc(n)} (${k})</option>`).join('')}</select>` : ''}
<select id="grFTrack" class="p-select"><option value="">كل المسارات</option>${Object.entries(s.tracks || {}).map(([t, n]) => `<option value="${esc(t)}" ${filters.track === t ? 'selected' : ''}>${esc(trackName(t))} (${n})</option>`).join('')}</select>
<select id="grFApproval" class="p-select"><option value="">الكل</option><option value="pending" ${filters.approval === 'pending' ? 'selected' : ''}>مستنية موافقتي (${s.awaiting_approval || 0})</option></select>
<input id="grFQ" class="p-select" placeholder="دوّر باسم الشركة أو الإيميل أو المدينة" value="${esc(filters.q)}"></div><div id="grLeads" class="gr-leads"></div></section>
<section class="gr-box"><h2>📨 الحملات اللي اتبعتت</h2><div id="grCamp"><p class="gr-muted">بيحمّل…</p></div></section>
<dialog id="grDlg" class="gr-dlg"><div class="gr-dlg-head"><b id="grDlgTitle"></b><button class="p-btn outline xs" data-close>إغلاق</button></div><div id="grDlgNeeds" class="gr-muted"></div><iframe id="grDlgFrame" class="gr-preview" sandbox title="preview"></iframe><div class="gr-actions" id="grDlgActs"></div></dialog>`;
        // الصفحة كانت 11 ألف بيكسل: كل قسم (المسارات، الدول، قبل ما تشغّل، الاستيراد، الرسايل، الإعدادات، الشركات) بقى تبويب
        if (window.SectionTabs) window.SectionTabs.apply(root, { key: 'growth', selector: 'section.gr-box', label: 'أقسام تسويق المنصة' });
        preview();
        loadLeads();
        loadCampaigns();
    };
    const f = (k, label, type = 'text') => `<label class="gr-f">${label}<input id="s_${k}" type="${type}" class="p-select" value="${esc(data.settings[k])}" ${['from_email', 'reply_to', 'smtp_host', 'smtp_username', 'signup_url', 'timezone'].includes(k) ? 'dir="ltr"' : ''}></label>`;

    const loadLeads = async () => {
        const box = v('grLeads'); if (!box) return;
        box.innerHTML = '<p class="gr-muted">بيحمّل…</p>';
        try {
            const q = new URLSearchParams(filters).toString();
            const r = await api('/api/admin/growth/leads?' + q);
            box.innerHTML = r.rows.length ? `<table class="gr-table"><thead><tr><th>الشركة</th><th>الإيميل</th><th>المكان</th><th>التقييم</th><th>الاحتياج</th><th>الحالة</th><th>آخر رسالة</th><th></th></tr></thead><tbody>${r.rows.map(l => `<tr>
<td><b>${esc(l.company || '—')}</b>${l.category ? `<div class="gr-muted">${esc(l.category)}</div>` : ''}${l.website ? `<a href="${esc(l.website)}" target="_blank" rel="noopener noreferrer" dir="ltr">${esc(l.website.replace(/^https?:\/\/(www\.)?/, '').slice(0, 40))}</a>` : ''}</td>
<td dir="ltr">${esc(l.email || '—')}${l.phone ? `<div class="gr-muted">${esc(l.phone)}</div>` : ''}</td>
<td>${l.country ? flag(l.country) + ' ' : ''}${esc([l.city, l.country ? countryName(l.country) : ''].filter(Boolean).join(' · '))}<div class="gr-muted">${esc(LANG[l.language] || l.language)}</div></td>
<td>${l.rating ? '⭐ ' + Number(l.rating) + (l.reviews ? ` <span class="gr-muted">(${l.reviews})</span>` : '') : '—'}${l.maps_url ? ` <a href="${esc(l.maps_url)}" target="_blank" rel="noopener noreferrer">${/tripadvisor/i.test(l.maps_url) ? 'TripAdvisor' : 'خريطة'}</a>` : ''}</td>
<td>${l.track ? `<b>${esc(trackName(l.track))}</b>${l.track2 ? `<div class="gr-muted">بعدها: ${esc(trackName(l.track2))}</div>` : ''}` : (l.scanned_at ? '—' : '<span class="gr-muted">لسه ماتفحصتش</span>')}${l.report_viewed_at ? '<div class="gr-muted">📄 فتح التقرير</div>' : ''}${l.whatsapp_at ? '<div class="gr-muted">💬 ضغط واتساب</div>' : ''}</td>
<td><span class="gr-badge ${STATUS[l.status]?.[1] || ''}">${esc(STATUS[l.status]?.[0] || l.status)}</span>${Number(l.step) ? `<div class="gr-muted">رسالة ${l.step} من ${l.track && l.track2 ? 5 : 3}${l.clicked_at ? ' · ضغط اللينك' : ''}</div>` : (l.track && l.status === 'new' ? `<div class="gr-muted">${+l.approved ? '✔ متوافق عليها' : '⏳ مستنية موافقتك'}</div>` : '')}</td>
<td class="gr-muted">${esc(l.last_sent_at || '—')}${l.next_send_at && ['contacted'].includes(l.status) ? `<div>الجاية: ${esc(l.next_send_at.slice(0, 10))}</div>` : ''}</td>
<td>${l.track && l.email ? `<button class="p-btn outline xs" data-preview-lead="${l.id}">معاينة</button>` : ''}${l.track && l.status === 'new' && !+l.approved && l.email ? ` <button class="p-btn xs" data-approve="${l.id}">وافق</button>` : ''}<select class="p-select gr-act" data-lead="${l.id}"><option value="">إجراء…</option><option value="replied">ردّت (وقّف الرسايل)</option><option value="interested">مهتمة</option><option value="signed_up">سجّلت</option><option value="paused">وقّف</option><option value="contacted">كمّل الرسايل</option><option value="unsubscribed">ألغت الاشتراك</option><option value="bounced">الإيميل راجع</option><option value="delete">امسح</option></select></td></tr>`).join('')}</tbody></table>
<div class="gr-row">${r.page > 1 ? '<button class="p-btn outline xs" data-page="-1">السابق</button>' : ''}<span class="gr-muted">صفحة ${r.page} من ${Math.max(1, r.pages)} · ${r.total} شركة</span>${r.page < r.pages ? '<button class="p-btn outline xs" data-page="1">التالي</button>' : ''}</div>`
                : '<p class="gr-muted">مفيش شركات هنا. استورد من فوق.</p>';
        } catch (e) { box.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
    };

    const currentTemplates = () => { const t = JSON.parse(JSON.stringify(data.settings.templates)); t[tplLang][tplStep] = { subject: v('grSubj').value, body: v('grBody').value }; return t; };
    const preview = async () => {
        try {
            const r = await api('/api/admin/growth/preview', { step: tplStep, lang: tplLang, templates: currentTemplates() });
            v('grPrevHead').innerHTML = `<b>${esc(r.subject)}</b><div class="gr-muted">معاينة على: ${esc(r.lead.company)}${r.lead.city ? ' · ' + esc(r.lead.city) : ''}${r.lead.rating ? ' · ⭐ ' + r.lead.rating : ''}</div>`;
            v('grPreview').srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:12px;background:#fff">' + r.html + '</body>';
        } catch (e) { v('grPrevHead').textContent = e.message; }
    };
    const settingsFromForm = () => {
        const out = {};
        ['sender_name', 'sender_title', 'from_email', 'reply_to', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'daily_limit', 'hour_from', 'hour_to', 'timezone', 'step_gaps', 'phase2_gap', 'signup_url', 'company_address', 'whatsapp_number', 'sender_photo_url', 'brand_logo_url'].forEach(k => { const el = v('s_' + k); if (el) out[k] = el.value.trim(); });
        out.skip_days = [...root.querySelectorAll('[data-day]:checked')].map(x => x.dataset.day).join(',');
        out.ai_opener = v('s_ai_opener').checked ? '1' : '0';
        out.require_approval = v('s_require_approval').checked ? '1' : '0';
        out.track_opens = v('s_track_opens').checked ? '1' : '0';
        return out;
    };
    const reload = async () => { data = { ...(await api('/api/admin/growth')), }; render(); };

    const importMsg = r => `تم: ${r.added} شركة جديدة · ${r.updated} اتحدّثت · ${r.no_email} مالهاش إيميل (هندوّر عليه في موقعها) · ${r.skipped} اتشالت (معالم، مكررة، عملاء عندك، أو ناقصة)`;
    // الحقول التقيلة في ملفات Apify اللي مش محتاجينها (صور، عروض، أوصاف): بنشيلها قبل الإرسال
    const HEAVY = ['offerGroup', 'photos', 'reviewTags', 'ancestorLocations', 'neighborhoodLocations', 'nearestMetroStations', 'description', 'image', 'imageUrls', 'reviews', 'reviewsDistribution', 'openingHours', 'additionalInfo', 'peopleAlsoSearch', 'questionsAndAnswers', 'webResults', 'popularTimesHistogram', 'images', 'subtype', 'localAddress'];
    /** ملف كبير: بيتقسّم دفعات صغيرة عشان السيرفر مايرفضش الطلب */
    const importInBatches = async text => {
        text = String(text || '').trim();
        if (!text) throw new Error('الصق البيانات أو اختار ملف');
        const total = { added: 0, updated: 0, skipped: 0, no_email: 0, total: 0 };
        const addUp = r => Object.keys(total).forEach(k => { total[k] += Number(r?.[k] || 0); });
        let rows = null;
        try { rows = JSON.parse(text); } catch (e) { rows = null; }
        if (rows && !Array.isArray(rows)) rows = Array.isArray(rows.items) ? rows.items : [rows];
        if (Array.isArray(rows)) {
            rows = rows.map(r => { if (!r || typeof r !== 'object') return r; const o = { ...r }; HEAVY.forEach(k => delete o[k]); if (o.booking) o.booking = { provider: o.booking.provider }; return o; });
            const size = 150;
            for (let i = 0; i < rows.length; i += size) {
                toast(`بنستورد… ${Math.min(i + size, rows.length)} من ${rows.length}`);
                addUp(await api('/api/admin/growth/import', { text: JSON.stringify(rows.slice(i, i + size)) }));
            }
            return total;
        }
        // CSV: نفس العناوين مع كل دفعة. التقسيم بيحترم علامات الاقتباس: وصف الشركة ممكن يبقى على كذا سطر وماينفعش يتقطّع
        const splitCsvRecords = t => {
            const out = []; let cur = '', quoted = false;
            for (let i = 0; i < t.length; i++) {
                const ch = t[i];
                if (ch === '"') { quoted = !quoted; cur += ch; }
                else if ((ch === '\n' || ch === '\r') && !quoted) { if (ch === '\r' && t[i + 1] === '\n') i++; out.push(cur); cur = ''; }
                else cur += ch;
            }
            if (cur !== '') out.push(cur);
            return out;
        };
        const lines = splitCsvRecords(text).filter(l => l.trim() !== '');
        const head = lines.shift();
        for (let i = 0; i < lines.length; i += 400) {
            toast(`بنستورد… ${Math.min(i + 400, lines.length)} من ${lines.length}`);
            addUp(await api('/api/admin/growth/import', { text: [head, ...lines.slice(i, i + 400)].join('\n') }));
        }
        return total;
    };

    /** الرسالة الجاية لشركة بالظبط زي ما هتتبعت + احتياجاتها */
    const STEP_NAME = { 1: 'المشكلة', 2: 'الحل والمميزات', 3: 'آخر رسالة', 4: 'المسار التاني: المشكلة', 5: 'المسار التاني: آخر رسالة' };
    const previewLead = async (id, approvable, step) => {
        const dlg = v('grDlg');
        v('grDlgTitle').textContent = 'بيحمّل…'; v('grDlgFrame').srcdoc = '';
        if (!dlg.open) { v('grDlgNeeds').textContent = ''; v('grDlgActs').innerHTML = ''; if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); }
        const r = await api('/api/admin/growth/leads/' + id + '/preview' + (step ? '?step=' + step : ''));
        v('grDlgTitle').textContent = r.subject;
        // كل رسايل الشركة دي: كل رسالة مختلفة (الاحتياج الأول 1-3، والتاني 4-5)
        const tabs = Array.from({ length: r.last_step }, (_, i) => i + 1).map(n => `<button class="p-btn xs ${n === r.step ? '' : 'outline'}" data-pv-step="${n}" data-pv-lead="${id}" data-pv-approvable="${approvable ? 1 : ''}">${n}) ${STEP_NAME[n]}</button>`).join(' ');
        v('grDlgNeeds').innerHTML = `<div class="gr-actions" style="margin:0 0 8px">${tabs}</div>المسار: <b>${esc(trackName(r.track))}</b> · اللي لقيناه: ${(r.needs || []).map((n, i) => `${i + 1}) ${esc(trackName(n.track))} <span class="gr-muted">(${n.score})</span>`).join(' · ')}<br><span class="gr-muted">الرسالة 1 بتتبعت يوم 0، وكل رسالة بعدها على حسب الأيام اللي في الإعدادات. والمسار التاني بيبدأ لو مارديتش.</span>`;
        v('grDlgFrame').srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#F2F4F7">' + r.html + '</body>';
        if (approvable) v('grDlgActs').innerHTML = `<button class="p-btn" data-approve="${id}">وافق وابعت الرسالة 1 في الدور</button>`;
    };

    // ---- الحملات اللي اتبعتت: ملخص + حملة لكل (دولة × مسار × رسالة) + سجل الرسايل + تفاصيل كل رسالة
    let mf = { country: '', track: '', step: '', status: '', q: '', page: 1 };
    const EVENT = { sent: ['✉️', 'اتبعتت'], failed: ['⚠️', 'فشل الإرسال'], opened: ['👁', 'فتح الرسالة'], clicked: ['🖱', 'ضغط على اللينك'], report_viewed: ['📄', 'فتح التقرير المجاني'], whatsapp: ['💬', 'ضغط واتساب'] };
    const rate = (a, b) => b ? Math.round(a * 100 / b) + '%' : '—';

    const loadCampaigns = async () => {
        const box = v('grCamp'); if (!box) return;
        try {
            const { totals: t, campaigns: cs } = await api('/api/admin/growth/campaigns');
            const opensOn = data.settings.track_opens === '1';
            const cards = [['اتبعتت', t.sent], ['شركات وصلها', t.recipients], ['فشل', t.failed], ['ضغطوا على اللينك', `${t.clicked} <small>${rate(t.clicked, t.sent)}</small>`], ['فتحوا التقرير', `${t.report_views} <small>${rate(t.report_views, t.recipients)}</small>`], ['ضغطوا واتساب', t.whatsapp], ['ردّوا / مهتمين', t.interested], ['سجّلوا 🎉', t.signed_up], ['ألغوا الاشتراك', t.unsubscribed], ['إيميل راجع', t.bounced]];
            const filterTxt = [mf.country && `الدولة: ${flag(mf.country)} ${countryName(mf.country)}`, mf.track !== '' && `المسار: ${trackName(mf.track)}`, mf.step && `الرسالة ${mf.step}`].filter(Boolean).join(' · ');
            box.innerHTML = `<div class="gr-cards">${cards.map(([l, n]) => `<div class="gr-card"><span>${l}</span><b>${n}</b></div>`).join('')}</div>
<p class="gr-muted">${opensOn ? '' : 'تتبع فتح الرسالة مقفول (الأفضل لوصول الإيميل للـ Inbox)، فالفتح مابيتحسبش. الضغط على اللينك وفتح التقرير والواتساب بيتحسبوا. '}الأوقات بتوقيت السيرفر.</p>
<h3>الحملات (كل دولة × مسار × رقم الرسالة)</h3>
${cs.length ? `<div class="gr-scroll"><table class="gr-table"><thead><tr><th>الحملة</th><th>اتبعتت</th><th>فشل</th><th>ضغطوا</th><th>فتحوا التقرير</th><th>واتساب</th><th>ردّوا</th><th>سجّلوا</th><th>ألغوا</th><th>راجع</th><th>أول إرسال</th><th>آخر إرسال</th><th></th></tr></thead><tbody>${cs.map(c => `<tr class="${mf.country === c.country && mf.track === c.track && String(mf.step) === String(c.step) ? 'gr-sel' : ''}"><td class="gr-nowrap">${flag(c.country)} ${esc(countryName(c.country))} · ${esc(trackName(c.track))} · <b>رسالة ${c.step}</b></td><td>${c.sent}</td><td>${c.failed}</td><td>${c.clicked} <span class="gr-muted">${rate(c.clicked, c.sent)}</span></td><td>${c.report_views}</td><td>${c.whatsapp}</td><td>${c.interested}</td><td><b>${c.signed_up}</b></td><td>${c.unsubscribed}</td><td>${c.bounced}</td><td class="gr-nowrap">${esc(c.first_sent_at)}</td><td class="gr-nowrap">${esc(c.last_sent_at)}</td><td><button class="p-btn outline xs" data-camp-c="${esc(c.country)}" data-camp-t="${esc(c.track)}" data-camp-s="${c.step}">اعرض رسايلها</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="gr-muted">لسه مفيش حملة اتبعتت.</p>'}
<h3>سجل الرسايل ${filterTxt ? `<span class="gr-muted">(${esc(filterTxt)}) <button class="p-btn outline xs" data-camp-clear>شيل الفلتر</button></span>` : ''}</h3>
<div class="gr-row"><select id="grMStatus" class="p-select"><option value="">كل الحالات</option><option value="sent" ${mf.status === 'sent' ? 'selected' : ''}>اتبعتت</option><option value="failed" ${mf.status === 'failed' ? 'selected' : ''}>فشلت</option></select><input id="grMQ" class="p-select" placeholder="دوّر باسم الشركة أو الإيميل أو العنوان" value="${esc(mf.q)}"></div>
<div id="grMsgs" class="gr-leads"><p class="gr-muted">بيحمّل…</p></div>`;
            loadMessages();
        } catch (e) { box.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
    };

    const loadMessages = async () => {
        const box = v('grMsgs'); if (!box) return;
        try {
            const r = await api('/api/admin/growth/messages?' + new URLSearchParams(mf).toString());
            const state = m => m.status === 'failed' ? `<span class="gr-badge red" title="${esc(m.error)}">فشلت</span>` : '<span class="gr-badge green">اتبعتت</span>';
            const dot = at => at ? `<span title="${esc(at)}">✔</span>` : '<span class="gr-muted">—</span>';
            box.innerHTML = r.rows.length ? `<div class="gr-scroll"><table class="gr-table"><thead><tr><th>وقت الإرسال</th><th>الشركة</th><th>المستلم</th><th>الرسالة</th><th>العنوان</th><th>الحالة</th><th>ضغط</th><th>التقرير</th><th>واتساب</th><th>حالة الشركة</th><th></th></tr></thead><tbody>${r.rows.map(m => `<tr><td class="gr-nowrap">${esc(m.sent_at)}</td><td>${flag(m.country || '?')} ${esc(m.company)}</td><td dir="ltr">${esc(m.to_email)}</td><td>${m.step}) ${esc(STEP_NAME[m.step] || '')}<br><span class="gr-muted">${esc(trackName(m.track))}</span></td><td>${esc(m.subject)}</td><td>${state(m)}</td><td>${dot(m.clicked_at)}</td><td>${dot(m.report_viewed_at)}</td><td>${dot(m.whatsapp_at)}</td><td>${esc((STATUS[m.lead_status] || [m.lead_status])[0])}</td><td><button class="p-btn outline xs" data-msg="${m.id}">التفاصيل</button></td></tr>`).join('')}</tbody></table></div>
<div class="gr-pager"><span class="gr-muted">${r.total} رسالة · صفحة ${r.page} من ${r.pages}</span> ${r.page > 1 ? '<button class="p-btn outline xs" data-mpage="-1">السابق</button>' : ''} ${r.page < r.pages ? '<button class="p-btn outline xs" data-mpage="1">التالي</button>' : ''}</div>` : '<p class="gr-muted">مفيش رسايل بالفلتر ده.</p>';
        } catch (e) { box.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
    };

    const showMessage = async id => {
        const r = await api('/api/admin/growth/messages/' + id);
        const m = r.message, l = r.lead;
        const dlg = v('grDlg');
        if (!dlg.open) { v('grDlgActs').innerHTML = ''; if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); }
        v('grDlgTitle').textContent = m.subject;
        const rows = [['من', esc(m.from_email)], ['إلى', `<span dir="ltr">${esc(m.to_email)}</span> — ${esc(l.company)}`], ['وقت الإرسال', esc(m.sent_at)], ['الرسالة', `${m.step}) ${esc(STEP_NAME[m.step] || '')} · المسار: <b>${esc(trackName(m.track))}</b>`],
            ['الشركة', `${flag(l.country || '?')} ${esc(countryName(l.country))}${l.city ? ' · ' + esc(l.city) : ''}${l.website ? ` · <a href="${esc(l.website)}" target="_blank" rel="noopener noreferrer">${esc(l.website)}</a>` : ''}`],
            ['الدرجة الرقمية', l.score != null ? esc(l.score) + '/100' : '—'], ['الحالة دلوقتي', esc((STATUS[l.status] || [l.status])[0])]];
        const events = r.events.map(e => `<li>${(EVENT[e.type] || ['•', e.type])[0]} ${esc((EVENT[e.type] || ['', e.type])[1])} <span class="gr-muted">${esc(e.at)}${e.note ? ' · ' + esc(e.note) : ''}</span></li>`).join('');
        const sib = r.siblings.length > 1 ? `<div class="gr-actions" style="margin:8px 0 0">${r.siblings.map(s => `<button class="p-btn xs ${s.id === m.id ? '' : 'outline'}" data-msg="${s.id}">${s.step}) ${esc(STEP_NAME[s.step] || '')}</button>`).join(' ')}</div>` : '';
        v('grDlgNeeds').innerHTML = `<table class="gr-table"><tbody>${rows.map(([k, val]) => `<tr><th>${k}</th><td>${val}</td></tr>`).join('')}</tbody></table>
<ul class="gr-timeline">${events}</ul>${sib}
<p class="gr-muted">${r.html_source === 'stored' ? 'ده نص الرسالة زي ما اتبعتت.' : 'الرسالة دي اتبعتت قبل ما المنصة تحفظ النص، فده نصها متعمل تاني من نفس القالب والبيانات (من غير سطر الذكاء الصناعي الأول، ممكن يختلف شوية عن اللي وصل).'} <a href="${esc(r.report_url)}" target="_blank" rel="noopener noreferrer">افتح التقرير المجاني بتاعهم</a> (فتحه من هنا مابيتحسبش عليهم).</p>`;
        v('grDlgFrame').srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#F2F4F7">' + r.html + '</body>';
    };

    const bind = () => {
        root.addEventListener('click', e => {
            const t = e.target.closest('button'); if (!t) return;
            if (t.dataset.toggle !== undefined) return run(t, async () => {
                const on = data.settings.enabled === '1';
                if (!on && !data.settings.from_email) throw new Error('حط إيميل المرسل في الإعدادات الأول');
                if (!on && !confirm('هيبدأ يبعت للشركات تلقائيًا في مواعيد العمل وبالحد اليومي. متأكد إنك راجعت الرسايل وجربتها على نفسك؟')) return;
                await api('/api/admin/growth/settings', { settings: { enabled: on ? '0' : '1' } }); await reload(); toast(on ? 'وقفنا الإرسال.' : 'الإرسال التلقائي اشتغل ✅');
            });
            if (t.dataset.run) return run(t, async () => {
                toast(t.dataset.run === 'enrich' ? 'بندوّر في مواقع الشركات… (حوالي دقيقة)' : 'بنبعت دفعة…');
                const r = await api('/api/admin/growth/run', { what: t.dataset.run });
                await reload();
                toast(t.dataset.run === 'enrich' ? `لقينا ${r.found} إيميل جديد.` : r.reason === 'daily_limit' ? 'وصلت للحد اليومي النهارده.' : r.reason === 'paused_bounces' ? 'متوقف بسبب الإيميلات الراجعة.' : `اتبعت ${r.sent}${r.failed ? ` · فشل ${r.failed}` : ''}.`);
            });
            if (t.dataset.import !== undefined) return run(t, async () => {
                toast('بنستورد… لو الملف كبير ممكن ياخد دقيقة');
                const r = await api('/api/admin/growth/import', { url: v('grUrl').value.trim() });
                await reload(); toast(importMsg(r));
            });
            if (t.dataset.importText !== undefined) return run(t, async () => { const r = await importInBatches(v('grText').value); await reload(); toast(importMsg(r)); });
            if (t.dataset.importFile !== undefined) return run(t, async () => {
                const file = v('grFile').files?.[0];
                if (!file) throw new Error('اختار الملف الأول');
                const r = await importInBatches(await file.text()); await reload(); toast(importMsg(r));
            });
            if (t.dataset.lang) { tplLang = t.dataset.lang; render(); return; }
            if (t.dataset.step) { tplStep = Number(t.dataset.step); render(); return; }
            if (t.dataset.preview !== undefined) return run(t, preview);
            if (t.dataset.saveTpl !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: { templates: currentTemplates() } }); data.settings.templates = currentTemplates(); toast('الرسالة اتحفظت ✅'); preview(); });
            if (t.dataset.resetTpl !== undefined) return run(t, async () => { if (!confirm('ترجع الرسالة دي للنص الأصلي؟')) return; const tpl = currentTemplates(); delete tpl[tplLang][tplStep]; tpl[tplLang][tplStep] = { subject: '', body: '' }; await api('/api/admin/growth/settings', { settings: { templates: tpl } }); await reload(); });
            if (t.dataset.test !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: { templates: currentTemplates() } }); data.settings.templates = currentTemplates(); await api('/api/admin/growth/test', { email: v('grTestTo').value.trim(), step: tplStep }); try { localStorage.setItem('gr-tested', '1'); } catch (x) {} toast('اتبعتت ✅ شوف الـ Inbox (ولو راحت Spam يبقى لازم تظبط SPF/DKIM للدومين).'); });
            if (t.dataset.saveSettings !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: settingsFromForm() }); await reload(); toast('الإعدادات اتحفظت ✅'); });
            if (t.dataset.page) { filters.page += Number(t.dataset.page); loadLeads(); }
            if (t.dataset.scan !== undefined) return run(t, async () => {
                toast('بنفحص مواقع الشركات… (حوالي دقيقة)');
                const r = await api('/api/admin/growth/scan', {});
                await reload();
                toast(`اتفحص ${r.scanned} شركة واتحدد احتياجها. ${data.stats.unscanned ? 'لسه ' + data.stats.unscanned + '، اضغط تاني أو سيبها للتشغيل التلقائي.' : ''}`);
            });
            if (t.dataset.approveTours !== undefined) return run(t, async () => {
                if (!confirm(`توافق على أول رسالة لـ ${data.stats.awaiting_tours} شركة رحلات؟ هتتبعت بالحد اليومي وفي مواعيد العمل، والسبا والملاهي والمطاعم والنقل هيفضلوا مستنيين.`)) return;
                const r = await api('/api/admin/growth/approve', { all: true, tours_only: true }); await reload(); toast(`اتوافق على ${r.approved} شركة رحلات ✅`);
            });
            if (t.dataset.approveAll !== undefined) return run(t, async () => {
                if (!confirm(`توافق على أول رسالة لـ ${data.stats.awaiting_approval} شركة؟ هتتبعت بالحد اليومي وفي مواعيد العمل. الأحسن تعمل معاينة لكام واحدة الأول.`)) return;
                const r = await api('/api/admin/growth/approve', { all: true }); await reload(); toast(`اتوافق على ${r.approved} شركة ✅`);
            });
            if (t.dataset.approve) return run(t, async () => {
                await api('/api/admin/growth/approve', { ids: [Number(t.dataset.approve)] });
                const dlg = v('grDlg'); if (dlg?.open) dlg.close();
                data = await api('/api/admin/growth'); loadLeads(); toast('اتوافق ✅ هتتبعت في الدور');
            });
            if (t.dataset.previewLead) {
                const row = t.closest('tr');
                return run(t, () => previewLead(t.dataset.previewLead, !!row?.querySelector('[data-approve]')));
            }
            if (t.dataset.msg) return run(t, () => showMessage(t.dataset.msg));
            if (t.dataset.campC !== undefined) { mf = { ...mf, country: t.dataset.campC, track: t.dataset.campT, step: t.dataset.campS, page: 1 }; loadCampaigns(); return; }
            if (t.dataset.campClear !== undefined) { mf = { country: '', track: '', step: '', status: mf.status, q: mf.q, page: 1 }; loadCampaigns(); return; }
            if (t.dataset.mpage) { mf.page += Number(t.dataset.mpage); loadMessages(); return; }
            if (t.dataset.pvStep) return run(t, () => previewLead(t.dataset.pvLead, !!t.dataset.pvApprovable, Number(t.dataset.pvStep)));
            if (t.dataset.close !== undefined) { const dlg = v('grDlg'); if (dlg.close) dlg.close(); else dlg.removeAttribute('open'); return; }
            if (t.dataset.trackFilter) { filters.track = t.dataset.trackFilter; filters.page = 1; v('grFTrack').value = filters.track; loadLeads(); v('grLeads').scrollIntoView({ behavior: 'smooth' }); }
            if (t.dataset.countryFilter) { filters.country = t.dataset.countryFilter; filters.city = ''; filters.page = 1; render(); v('grLeads').scrollIntoView({ behavior: 'smooth' }); }
            if (t.dataset.approveCountry) return run(t, async () => {
                const c = t.dataset.approveCountry;
                if (!confirm(`توافق على أول رسالة لـ ${t.dataset.n} شركة في ${countryName(c)}؟ هتتبعت بالحد اليومي وفي مواعيد العمل لما الإرسال يكون شغال.`)) return;
                const r = await api('/api/admin/growth/approve', { all: true, country: c }); await reload(); toast(`اتوافق على ${r.approved} شركة في ${countryName(c)} ✅`);
            });
        });
        root.addEventListener('change', e => {
            const s = e.target;
            if (s.matches('.gr-act') && s.value) {
                const st = s.value;
                if (st === 'delete' && !confirm('تمسح الشركة دي نهائيًا؟')) { s.value = ''; return; }
                run(s, async () => { await api('/api/admin/growth/leads/' + s.dataset.lead + '/status', { status: st }); toast('اتحدّث ✅'); const r = await api('/api/admin/growth'); data = r; loadLeads(); });
            }
            if (s.id === 'grFCountry') { filters.country = s.value; filters.city = ''; filters.page = 1; render(); v('grLeads').scrollIntoView({ block: 'start' }); return; }
            if (s.id === 'grMStatus') { mf.status = s.value; mf.page = 1; loadMessages(); return; }
            if (s.id === 'grFCity') { filters.city = s.value; filters.page = 1; loadLeads(); return; }
            if (['grFStatus', 'grFTrack', 'grFApproval'].includes(s.id)) { filters.status = v('grFStatus').value; filters.country = v('grFCountry').value; filters.track = v('grFTrack').value; filters.approval = v('grFApproval').value; filters.page = 1; loadLeads(); }
        });
        let mTimer; root.addEventListener('input', e => { if (e.target.id === 'grMQ') { clearTimeout(mTimer); mTimer = setTimeout(() => { mf.q = e.target.value.trim(); mf.page = 1; loadMessages(); }, 350); } });
        let timer; root.addEventListener('input', e => { if (e.target.id === 'grFQ') { clearTimeout(timer); timer = setTimeout(() => { filters.q = e.target.value.trim(); filters.page = 1; loadLeads(); }, 350); } });
    };

    window.AdminGrowth = {
        init: async panel => {
            P = panel; root = document.getElementById('growthRoot'); if (!root || !P) return;
            try { data = await api('/api/admin/growth'); bind(); render(); }
            catch (e) { root.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
        },
    };
})();
