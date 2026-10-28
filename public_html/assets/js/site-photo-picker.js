/* Tourfecto - اختيار صور من موقع العميل قبل سحبها (الموقع كله أو روابط رحلات معينة) */
(() => {
    'use strict';
    const ar = () => (document.documentElement.lang || '').toLowerCase().startsWith('ar');
    const t = (a, e) => ar() ? a : e;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const URLS_KEY = 'tf-trip-urls';
    const css = `.tfp-dialog{width:min(1100px,96vw);max-height:92vh;border:1px solid var(--panel-border,#2a3a4a);border-radius:14px;background:var(--panel-card-bg,#111a24);color:inherit;padding:0}
.tfp-dialog::backdrop{background:rgba(0,0,0,.6)}.tfp-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--panel-border,#2a3a4a);position:sticky;top:0;background:inherit;z-index:2}
.tfp-head h3{margin:0;font-size:17px}.tfp-body{padding:14px 18px;overflow:auto;max-height:calc(92vh - 140px)}.tfp-urls{width:100%;box-sizing:border-box;min-height:70px;direction:ltr}
.tfp-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:10px 0}.tfp-tools{position:sticky;top:0;background:var(--panel-card-bg,#111a24);z-index:1;padding:8px 0;border-bottom:1px solid var(--panel-border,#2a3a4a)}
.tfp-group{margin:16px 0}.tfp-group h4{margin:0 0 8px;font-size:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.tfp-group h4 a{font-size:12px;opacity:.7;font-weight:normal;direction:ltr;color:inherit}
.tfp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}.tfp-img{position:relative;border:2px solid transparent;border-radius:10px;overflow:hidden;cursor:pointer;background:#0006;aspect-ratio:4/3}
.tfp-img img{width:100%;height:100%;object-fit:cover;display:block}.tfp-img.on{border-color:var(--panel-accent,#3b82f6)}.tfp-img.on::after{content:'✓';position:absolute;top:6px;inset-inline-end:6px;background:var(--panel-accent,#3b82f6);color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:bold}
.tfp-img.have{opacity:.45;cursor:default}.tfp-img .tfp-tag{position:absolute;bottom:0;inset-inline:0;font-size:11px;padding:3px 6px;background:#000a;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tfp-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 18px;border-top:1px solid var(--panel-border,#2a3a4a);position:sticky;bottom:0;background:inherit}
.tfp-status{font-size:13px;opacity:.85}.tfp-hide-have .tfp-img.have{display:none}@media(max-width:600px){.tfp-grid{grid-template-columns:repeat(3,1fr)}}`;

    let dialog = null;
    const P = () => window.Panel;
    const post = async (url, body) => {
        const r = await P().fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        if (!r?.success) throw new Error(r?.error || t('حصلت مشكلة، جرّب تاني', 'Something went wrong, try again'));
        return r.data;
    };

    const open = ({ websiteId = 0, onDone = () => {} } = {}) => {
        if (!document.getElementById('tfpStyle')) { const s = document.createElement('style'); s.id = 'tfpStyle'; s.textContent = css; document.head.append(s); }
        dialog?.remove();
        dialog = document.createElement('dialog');
        dialog.className = 'tfp-dialog';
        dialog.setAttribute('dir', ar() ? 'rtl' : 'ltr');
        let saved = ''; try { saved = localStorage.getItem(URLS_KEY) || ''; } catch (e) {}
        dialog.innerHTML = `<div class="tfp-head"><h3>${t('🌐 اختار الصور اللي عاوزها من موقعك', '🌐 Pick the photos you want from your website')}</h3><button type="button" class="p-btn outline xs" data-close>✕</button></div>
<div class="tfp-body"><p class="p-cell-muted" style="margin:0 0 6px">${t('دوّر في الموقع كله، أو حط روابط رحلات معينة (رابط في كل سطر) - الروابط بتتحفظ للمرة الجاية.', 'Search your whole website, or paste specific trip links (one per line) - links are remembered for next time.')}</p>
<textarea class="p-select tfp-urls" placeholder="https://yoursite.com/tours/luxor-day-trip">${esc(saved)}</textarea>
<div class="tfp-row"><button type="button" class="p-btn" data-scan="site">${t('🔍 دوّر في الموقع كله', '🔍 Search the whole website')}</button><button type="button" class="p-btn outline" data-scan="urls">${t('🔗 دوّر في الروابط دي بس', '🔗 Only these links')}</button><span class="tfp-status" data-status></span></div>
<div data-results></div></div>
<div class="tfp-foot"><div class="tfp-row" style="margin:0"><label class="smp-check"><input type="checkbox" data-folders checked> ${t('📁 صور كل رحلة في مجلد باسمها', '📁 One folder per trip')}</label><label class="smp-check"><input type="checkbox" data-learn checked> ${t('✨ الذكاء الصناعي يتعلم منها', '✨ Let the AI learn them')}</label></div><span class="tfp-status" data-status2></span><button type="button" class="p-btn" data-import disabled>${t('اسحب الصور المختارة', 'Get selected photos')}</button></div>`;
        document.body.append(dialog);
        dialog.showModal();
        const $ = sel => dialog.querySelector(sel);
        const status = (msg) => { $('[data-status]').textContent = msg; $('[data-status2]').textContent = msg; };
        let pages = [];
        let busy = false;
        const selected = new Map(); // src => item

        const refreshCount = () => {
            const n = selected.size;
            const btn = $('[data-import]');
            btn.disabled = !n || busy;
            btn.textContent = n ? t('اسحب الصور المختارة (', 'Get selected photos (') + n + ')' : t('اختار صور الأول', 'Select photos first');
            dialog.querySelectorAll('[data-count]').forEach(el => { el.textContent = n; });
        };
        const toggle = (card, on) => {
            if (card.classList.contains('have') || card.hidden) return;
            const it = pages[+card.dataset.p].images[+card.dataset.i];
            const item = { src: it.src, alt: it.alt, page: pages[+card.dataset.p].url, title: pages[+card.dataset.p].title };
            on = on ?? !selected.has(it.src);
            card.classList.toggle('on', on);
            if (on) selected.set(it.src, item); else selected.delete(it.src);
        };
        const visibleCards = root => [...root.querySelectorAll('.tfp-img')].filter(c => !c.hidden && !c.classList.contains('have') && c.offsetParent !== null);

        const render = (data) => {
            pages = data.pages;
            selected.clear();
            const have = pages.reduce((n, p) => n + p.images.filter(i => i.already).length, 0);
            $('[data-results]').innerHTML = `<div class="tfp-tools"><div class="tfp-row" style="margin:0"><b>${t('لقينا ', 'Found ')}${data.total}${t(' صورة في ', ' photos on ')}${pages.length}${t(' صفحة', ' pages')}</b>${have ? `<span class="p-cell-muted">(${have} ${t('موجودة في مكتبتك', 'already in your library')})</span>` : ''}</div>
<div class="tfp-row"><input type="search" class="p-select" data-filter placeholder="${t('دوّر باسم الرحلة أو المكان…', 'Filter by trip or place…')}" style="flex:1;min-width:180px"><button type="button" class="p-btn outline xs" data-all>${t('اختار الكل', 'Select all')}</button><button type="button" class="p-btn outline xs" data-none>${t('شيل الكل', 'Clear')}</button><label class="smp-check"><input type="checkbox" data-hide-have checked> ${t('اخفي الموجودة عندي', 'Hide ones I have')}</label><span>${t('مختار: ', 'Selected: ')}<b data-count>0</b></span></div></div>
${pages.map((p, pi) => `<section class="tfp-group" data-group="${pi}" data-text="${esc((p.title + ' ' + p.url + ' ' + p.images.map(i => i.alt).join(' ')).toLowerCase())}"><h4>${esc(p.title || t('صفحة', 'Page'))} <span class="p-cell-muted">(${p.images.length})</span><button type="button" class="p-btn outline xs" data-group-all="${pi}">${t('اختار صور الصفحة دي', 'Select this page')}</button><a href="${esc(p.url)}" target="_blank" rel="noopener noreferrer">${esc(p.url.replace(/^https?:\/\/(www\.)?/, '').slice(0, 60))}</a></h4>
<div class="tfp-grid">${p.images.map((im, ii) => `<div class="tfp-img${im.already ? ' have' : ''}" data-p="${pi}" data-i="${ii}" title="${esc(im.alt)}"><img src="${esc(im.src)}" alt="${esc(im.alt)}" loading="lazy" referrerpolicy="no-referrer">${im.already ? `<span class="tfp-tag">✓ ${t('في مكتبتك', 'In your library')}</span>` : im.alt ? `<span class="tfp-tag">${esc(im.alt)}</span>` : ''}</div>`).join('')}</div></section>`).join('')}`;
            $('[data-results]').classList.add('tfp-hide-have');
            // الصور الصغيرة (أيقونات) أو اللي مش بتفتح بتختفي لوحدها
            dialog.querySelectorAll('.tfp-img img').forEach(img => {
                const check = () => { if (img.naturalWidth && img.naturalWidth < 300) img.closest('.tfp-img').hidden = true; };
                img.addEventListener('load', check); img.addEventListener('error', () => { img.closest('.tfp-img').hidden = true; });
                if (img.complete) check();
            });
            refreshCount();
        };

        const scan = async (mode) => {
            if (busy) return;
            const urls = mode === 'urls' ? $('.tfp-urls').value.split(/\s+/).map(s => s.trim()).filter(s => /^https?:\/\//i.test(s)) : [];
            if (mode === 'urls' && !urls.length) { status(t('حط رابط رحلة واحد على الأقل (بيبدأ بـ https://)', 'Paste at least one trip link (starting with https://)')); return; }
            try { localStorage.setItem(URLS_KEY, $('.tfp-urls').value.trim()); } catch (e) {}
            busy = true; dialog.querySelectorAll('[data-scan]').forEach(b => { b.disabled = true; });
            status(mode === 'urls' ? t('بنفتح الروابط ونجمع الصور…', 'Opening the links and collecting photos…') : t('بنلف على صفحات موقعك ونجمع الصور… (ممكن تاخد دقيقة)', 'Going through your website pages… (may take a minute)'));
            try { const d = await post('/api/social/media/site-scan', { website_id: websiteId, urls }); render(d); status(''); }
            catch (e) { status(e.message); }
            finally { busy = false; dialog.querySelectorAll('[data-scan]').forEach(b => { b.disabled = false; }); refreshCount(); }
        };

        const runImport = async () => {
            if (busy || !selected.size) return;
            busy = true; refreshCount();
            let queue = [...selected.values()];
            const total = queue.length; const ids = []; let dup = 0, bad = 0;
            try {
                while (queue.length) {
                    status(t('بنسحب الصور… ', 'Getting photos… ') + (total - queue.length) + ' / ' + total);
                    const batch = queue.slice(0, 20);
                    const r = await post('/api/social/media/site-import-selected', { items: batch, folders: $('[data-folders]').checked });
                    ids.push(...(r.added || []).map(Number)); dup += r.duplicate || 0; bad += r.rejected || 0;
                    const left = r.remaining || [];
                    queue = left.concat(queue.slice(batch.length));
                    if (left.length === batch.length) break; // ماتقدمش خالص
                }
                if ($('[data-learn]').checked) {
                    for (const [n, id] of ids.entries()) {
                        status(t('✅ اتسحبت ', '✅ Got ') + ids.length + t(' — ✨ الذكاء الصناعي بيتعلم منها ', ' — ✨ the AI is learning them ') + (n + 1) + ' / ' + ids.length + '…');
                        try { await post('/api/social/media/' + id + '/learn', {}); } catch (e) {}
                        if (n === 0) onDone(ids);
                    }
                }
                const msg = t('✅ اتسحبت ', '✅ Added ') + ids.length + t(' صورة', ' photos') + (dup ? t(' — ', ' — ') + dup + t(' كانت موجودة فماتكررتش', ' were already there (not duplicated)') : '') + (bad ? t(' — ', ' — ') + bad + t(' صغيرة أو مابتفتحش فاتسابت', ' too small or unavailable, skipped') : '');
                status(msg);
                onDone(ids, msg);
                selected.clear();
                dialog.querySelectorAll('.tfp-img.on').forEach(c => { c.classList.remove('on'); c.classList.add('have'); });
            } catch (e) { status(e.message); }
            finally { busy = false; refreshCount(); }
        };

        dialog.addEventListener('click', e => {
            const el = e.target;
            if (el.closest('[data-close]')) { if (!busy || confirm(t('لسه بنسحب الصور. تقفل؟', 'Still working. Close anyway?'))) dialog.close(); return; }
            if (el.closest('[data-scan]')) { scan(el.closest('[data-scan]').dataset.scan); return; }
            if (el.closest('[data-import]')) { runImport(); return; }
            if (el.closest('[data-all]')) { visibleCards(dialog).forEach(c => toggle(c, true)); refreshCount(); return; }
            if (el.closest('[data-none]')) { dialog.querySelectorAll('.tfp-img.on').forEach(c => toggle(c, false)); refreshCount(); return; }
            const g = el.closest('[data-group-all]');
            if (g) { const cards = visibleCards(dialog.querySelector(`[data-group="${g.dataset.groupAll}"]`)); const allOn = cards.every(c => c.classList.contains('on')); cards.forEach(c => toggle(c, !allOn)); refreshCount(); return; }
            const card = el.closest('.tfp-img');
            if (card && !busy) { toggle(card); refreshCount(); }
        });
        dialog.addEventListener('input', e => {
            if (e.target.matches('[data-filter]')) { const q = e.target.value.trim().toLowerCase(); dialog.querySelectorAll('.tfp-group').forEach(g => { g.hidden = q && !g.dataset.text.includes(q); }); }
        });
        dialog.addEventListener('change', e => { if (e.target.matches('[data-hide-have]')) $('[data-results]').classList.toggle('tfp-hide-have', e.target.checked); });
        dialog.addEventListener('close', () => dialog.remove());
        refreshCount();
        if (!saved) scan('site');
    };
    window.TFSitePicker = { open };
})();
