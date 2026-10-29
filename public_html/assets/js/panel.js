/**
 * Tourfecto - Panel Common Helpers
 * دوال مشتركة تُستخدم في لوحة الأدمن ولوحة العميل
 * @version 2.0.0
 */
(function (global) {
    'use strict';
    // Send affected routes to the same front controller without relying on pretty-URL rewrites.
    // Dispatch still runs the original AuthMiddleware, CSRF and ownership checks. Never retry writes.
    const nativeFetch = global.fetch;
    global.fetch = function (input, init) {
        try {
            const url = new URL(input instanceof Request ? input.url : String(input), global.location.href);
            if (url.origin === global.location.origin && /^\/api\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(url.pathname)) {
                const route = url.pathname;
                url.pathname = '/index.php';
                url.searchParams.set('__tf_api', route);
                input = input instanceof Request ? new Request(url.href, input) : url.href;
            }
        } catch (_) { /* Keep normal fetch behavior for unsupported inputs. */ }
        return nativeFetch.call(global, input, init);
    };


    // Older deployed views can still emit these two legacy customer links.
    function fixLegacyResearchLinks() {
        document.querySelectorAll('a[href="/keywords"],a[href="/competitors"]').forEach(a => a.setAttribute('href', '/ai' + a.getAttribute('href')));
    }
    function watchResearchLinks() {
        fixLegacyResearchLinks();
        if (document.body) new MutationObserver(fixLegacyResearchLinks).observe(document.body, { childList: true, subtree: true });
    }
    if (document.body) watchResearchLinks(); else document.addEventListener('DOMContentLoaded', watchResearchLinks, { once: true });

    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // الرد مش JSON (غالبًا صفحة خطأ من nginx/Cloudflare): رسالة مفهومة حسب الكود
    function badResponseMessage(status) {
        if (status === 502 || status === 504 || status === 524 || status === 408) return 'العملية أخدت وقت أطول من المسموح على السيرفر. جرّب تاني بعد دقيقة. (' + status + ')';
        if (status === 503) return 'الخدمة مشغولة دلوقتي. جرّب تاني بعد دقيقة. (503)';
        if (status === 413) return 'الملف أو البيانات أكبر من المسموح. (413)';
        if (status === 401 || status === 419) return 'الجلسة انتهت. اعمل تحديث للصفحة وسجّل دخول تاني. (' + status + ')';
        if (status === 403) return 'السيرفر رفض الطلب (403). لو فيه جدار حماية (WAF) شغّال ممكن يكون هو السبب.';
        return 'استجابة غير صالحة من الخادم' + (status ? ' (' + status + ')' : '');
    }

    async function fetchJSON(url, options) {
        // رسايل الشبكة بلغة الواجهة (الحسابات الإنجليزي كانت بتشوفها بالعربي)
        const L = (ar, en) => (document.documentElement.lang || 'ar').toLowerCase().startsWith('ar') ? ar : en;
        const opts = options || {};
        const method = String(opts.method || 'GET').toUpperCase();
        const readOnly = method === 'GET' || method === 'HEAD';
        let res;
        try {
            // توكن الحماية مع كل طلب بيغيّر بيانات (كتير من الصفحات كانت بتنساه فتطلع "انتهت الجلسة")
            const csrfMeta = readOnly ? null : document.querySelector('meta[name="csrf-token"]');
            const extra = csrfMeta && csrfMeta.content ? { 'X-CSRF-Token': csrfMeta.content } : {};
            res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts, {
                headers: Object.assign({ 'Accept': 'application/json' }, extra, opts.headers || {})
            }));
        } catch (e) {
            return { success: false, error: readOnly
                ? L('تعذر الاتصال. تحقق من اتصال الإنترنت ثم أعد المحاولة.', 'Connection failed. Check your internet connection and try again.')
                : L('تعذر التأكد من نتيجة العملية. حدّث البيانات للتحقق قبل تكرار الطلب.', 'Could not confirm the result. Refresh to check before repeating the request.'),
                network_error: true, retry_safe: readOnly };
        }
        let body;
        try { body = await res.json(); } catch (e) {
            return { success: false, error: readOnly ? L('تعذر تحميل البيانات الآن. حاول مرة أخرى.', 'Could not load data right now. Please try again.')
                : L('تعذر التأكد من نتيجة العملية. تحقق من البيانات قبل تكرار الطلب.', 'Could not confirm the result. Check before repeating the request.'), retry_safe: readOnly, http_status: res.status };
        }
        if (!body || typeof body !== 'object' || Array.isArray(body)) {
            return { success: false, error: L('تعذر قراءة البيانات. حاول مرة أخرى.', 'Could not read the response. Please try again.'), retry_safe: readOnly };
        }
        if (!res.ok) {
            body.success = false;
            if (res.status === 401) {
                // السيرفر بيرجّع "Authentication token required" - رسالة مش مفهومة للعميل
                body.error = 'انتهت جلستك. سجّل الدخول مرة أخرى.';
                showSessionExpired();
            } else if (res.status === 419) {
                // العميل لسه داخل - بس الصفحة مفتوحة من بدري وتوكن الحماية بتاعها اتغير
                body.error = 'الصفحة دي مفتوحة من بدري. اعمل تحديث للصفحة (F5) وكمّل.';
            } else if (!body.error) {
                body.error = 'تعذر إتمام الطلب الآن. حاول مرة أخرى.';
            }
        }
        if (body.success === false && body.upgrade_url) showUpgradePrompt(body.error, body.upgrade_url);
        return body;
    }

    // الجلسة انتهت: شريط ثابت بزرار دخول يرجّع العميل لنفس الصفحة. مش تحويل
    // تلقائي عشان لو العميل كاتب حاجة في فورم متضيعش.
    function showSessionExpired() {
        if (document.getElementById('tfSessionExpired')) return;
        const back = location.pathname + location.search;
        const bar = document.createElement('div');
        bar.id = 'tfSessionExpired';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:fixed;z-index:10000;inset-inline:16px;top:16px;max-width:560px;margin-inline:auto;display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 16px;border-radius:14px;background:var(--panel-card-bg,#fff);color:var(--panel-text,#0F1B2D);border:1px solid #E5484D;box-shadow:0 18px 40px -18px rgba(11,27,51,.45);font-size:14px;';
        bar.innerHTML = '<span style="flex:1;min-width:200px;">انتهت جلستك. سجّل الدخول تاني وكمّل من نفس المكان.</span>'
            + '<a href="/login?redirect=' + encodeURIComponent(back) + '" style="text-decoration:none;background:var(--panel-accent,#1F4FFF);color:#fff;padding:8px 16px;border-radius:10px;font-weight:600;white-space:nowrap;">تسجيل الدخول</a>';
        document.body.appendChild(bar);
    }

    // الأداة محتاجة اشتراك/رصيد (SubscriptionMiddleware بيرجّع upgrade_url): شريط ثابت
    // فيه زرار للباقات بدل toast بيختفي في 3 ثواني ومن غير ما العميل يعرف يعمل إيه.
    function showUpgradePrompt(message, url) {
        if (document.getElementById('tfUpgradePrompt')) return;
        const bar = document.createElement('div');
        bar.id = 'tfUpgradePrompt';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:fixed;z-index:9999;inset-inline:16px;bottom:16px;max-width:560px;margin-inline:auto;display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 16px;border-radius:14px;background:var(--panel-card-bg,#fff);color:var(--panel-text,#0F1B2D);border:1px solid var(--panel-accent,#1F4FFF);box-shadow:0 18px 40px -18px rgba(11,27,51,.45);font-size:14px;';
        bar.innerHTML = '<span style="flex:1;min-width:200px;">' + esc(message || 'الأداة دي محتاجة اشتراك فعّال.') + '</span>'
            + '<a href="' + esc(url) + '" style="text-decoration:none;background:var(--panel-accent,#1F4FFF);color:#fff;padding:8px 16px;border-radius:10px;font-weight:600;white-space:nowrap;">شوف الباقات</a>'
            + '<button type="button" aria-label="إغلاق" style="background:none;border:0;font-size:18px;cursor:pointer;color:inherit;">×</button>';
        bar.querySelector('button').addEventListener('click', function () { bar.remove(); });
        document.body.appendChild(bar);
    }

    function toast(message, type) {
        let stack = document.getElementById('toastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'toastStack';
            document.body.appendChild(stack);
        }
        const el = document.createElement('div');
        el.className = 'toast' + (type ? ' ' + type : '');
        const icon = type === 'success' ? '✅' : (type === 'error' ? '⚠️' : 'ℹ️');
        el.innerHTML = '<span>' + icon + '</span><span>' + esc(message) + '</span>';
        stack.appendChild(el);
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transition = 'opacity .25s ease';
            setTimeout(function () { el.remove(); }, 250);
        }, 3200);
    }

    function timeAgo(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(dateStr);
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        const ar = uiLocale().startsWith('ar');
        if (diff < 60) return ar ? 'الآن' : 'just now';
        try {
            const rtf = new Intl.RelativeTimeFormat(uiLocale(), { numeric: 'auto' });
            if (diff < 3600) return rtf.format(-Math.floor(diff / 60), 'minute');
            if (diff < 86400) return rtf.format(-Math.floor(diff / 3600), 'hour');
            if (diff < 2592000) return rtf.format(-Math.floor(diff / 86400), 'day');
        } catch (e) { /* متصفح قديم: نعرض التاريخ */ }
        return d.toLocaleDateString(uiLocale(), { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(dateStr);
        return d.toLocaleString(uiLocale(), { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /** لغة التواريخ حسب لغة الواجهة (كانت عربي دايمًا حتى في الإنجليزي)، بأرقام 0-9 زي باقي اللوحة */
    function uiLocale() {
        const lang = (document.documentElement.lang || 'ar').slice(0, 2).toLowerCase();
        return ({ ar: 'ar-EG-u-nu-latn', en: 'en-GB', fr: 'fr-FR', de: 'de-DE' })[lang] || 'en-GB';
    }

    function initSidebar() {
        const shell = document.querySelector('.panel-shell');
        const toggleBtn = document.getElementById('panelMenuToggle');
        const overlay = document.querySelector('.panel-overlay-bg');
        const sidebar = document.querySelector('.panel-sidebar');
        function setSidebar(open, restoreFocus) {
            if (!shell) return;
            shell.classList.toggle('sidebar-open', open);
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', String(open));
            if (restoreFocus && toggleBtn) toggleBtn.focus();
        }
        if (toggleBtn && shell) {
            toggleBtn.setAttribute('aria-label', (document.documentElement.lang || 'ar').startsWith('ar') ? 'قائمة التنقل' : 'Navigation menu');
            toggleBtn.setAttribute('aria-expanded', 'false');
            if (sidebar) { sidebar.id = sidebar.id || 'panelSidebar'; toggleBtn.setAttribute('aria-controls', sidebar.id); }
            toggleBtn.addEventListener('click', () => setSidebar(!shell.classList.contains('sidebar-open')));
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape' && shell.classList.contains('sidebar-open')) { e.preventDefault(); setSidebar(false, true); }
            });
            if (sidebar) sidebar.addEventListener('click', e => { if (e.target.closest('a[href]')) setSidebar(false); });
            window.matchMedia('(min-width: 1025px)').addEventListener('change', e => { if (e.matches) setSidebar(false); });
        }
        if (overlay && shell) overlay.addEventListener('click', () => setSidebar(false, true));

        initNavGroups();
        initNavSearch();
    }

    // المجموعات القابلة للطي في السايد بار: حالة كل مجموعة محفوظة في
    // localStorage عشان تفضل زي ما المستخدم سابها بين الصفحات. المجموعة
    // اللي فيها العنصر النشط دايمًا مفتوحة مهما كانت الحالة المحفوظة.
    function initNavGroups() {
        const groups = document.querySelectorAll('.panel-nav-group');
        if (!groups.length) return;
        let storage = null;
        try { storage = JSON.parse(localStorage.getItem('tf_nav_v2') || '{}'); } catch (e) { storage = {}; }

        groups.forEach(function (group) {
            const title = group.querySelector('.panel-nav-group-title');
            if (!title) return;
            const idx = String(group.getAttribute('data-group-idx'));
            const hasActive = group.querySelector('.panel-nav-link.active') !== null;
            // افتراضيًا كل الأقسام مطوية ماعدا الأول والقسم النشط (storage[idx]===0 يعني المستخدم فتحه بنفسه)
            const collapsedByDefault = (idx !== '1') && (storage[idx] === undefined);
            if (!hasActive && (storage[idx] === 1 || collapsedByDefault)) {
                group.classList.add('collapsed');
                title.setAttribute('aria-expanded', 'false');
            }
            title.addEventListener('click', function () {
                const willCollapse = !group.classList.contains('collapsed');
                group.classList.toggle('collapsed');
                title.setAttribute('aria-expanded', String(!willCollapse));
                storage[idx] = willCollapse ? 1 : 0;
                try { localStorage.setItem('tf_nav_v2', JSON.stringify(storage)); } catch (e) {}
            });
            title.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); title.click(); }
            });
        });
    }

    // بحث فوري في السايد بار: بينطّي المجموعات اللي مالهاش عنصر مطابق،
    // وبيشيل الـ collapse مؤقتًا عشان النتايج تبان. لو المسطرة اتفضّت
    // بيرجع الـ collapse للحالة المحفوظة.
    function initNavSearch() {
        const input = document.getElementById('panelNavSearch');
        if (!input) return;
        const groups = Array.prototype.slice.call(document.querySelectorAll('.panel-nav-group'));
        let storage = null;
        try { storage = JSON.parse(localStorage.getItem('tf_nav_v2') || '{}'); } catch (e) { storage = {}; }

        input.addEventListener('input', function () {
            const q = input.value.trim().toLowerCase();
            let anyMatch = false;
            groups.forEach(function (group) {
                const links = group.querySelectorAll('.panel-nav-link');
                let groupMatch = false;
                links.forEach(function (link) {
                    const hay = (link.getAttribute('data-search') || '').toLowerCase();
                    const m = q === '' || hay.indexOf(q) !== -1;
                    link.style.display = m ? '' : 'none';
                    if (m) groupMatch = true;
                });
                group.classList.toggle('hidden-group', q !== '' && !groupMatch);
                if (q !== '') {
                    if (groupMatch) group.classList.remove('collapsed');
                } else {
                    // رجوع للحالة المحفوظة بعد مسح البحث
                    const idx = String(group.getAttribute('data-group-idx'));
                    const hasActive = group.querySelector('.panel-nav-link.active') !== null;
                    const title = group.querySelector('.panel-nav-group-title');
                    if (!hasActive && (storage[idx] === 1 || (idx !== '1' && storage[idx] === undefined))) {
                        group.classList.add('collapsed');
                        if (title) title.setAttribute('aria-expanded', 'false');
                    } else {
                        group.classList.remove('collapsed');
                        if (title) title.setAttribute('aria-expanded', 'true');
                    }
                }
                if (groupMatch) anyMatch = true;
            });
            const footer = document.querySelector('.panel-sidebar-footer');
            if (footer) footer.style.display = (q === '' || anyMatch) ? '' : 'none';
        });
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('open');
    }
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }

    function initials(name) {
        if (!name) return '؟';
        const parts = String(name).trim().split(/\s+/);
        if (parts.length === 1) return parts[0].substring(0, 2);
        return parts[0].charAt(0) + parts[1].charAt(0);
    }

    function statusPill(isActive) {
        return isActive == 1
            ? '<span class="pill green">● نشط</span>'
            : '<span class="pill red">● موقوف</span>';
    }

    /**
     * تأثير ميل 3D بالماوس على أي كارت عليه class stat-tile أو orbit-glow
     * (نظام تصميم Compass) - بيتطبّق تلقائيًا على أي صفحة بدون أي كود
     * إضافي فيها.
     */
    function initTilt() {
        document.querySelectorAll('.stat-tile, .kcard').forEach(function (card) {
            if (card.dataset.tiltBound) return;
            card.dataset.tiltBound = '1';
            card.addEventListener('mousemove', function (e) {
                var r = card.getBoundingClientRect();
                var x = (e.clientX - r.left - r.width / 2) / r.width;
                var y = (e.clientY - r.top - r.height / 2) / r.height;
                card.style.transform = 'rotateY(' + (x * 7) + 'deg) rotateX(' + (-y * 7) + 'deg) translateY(-2px)';
            });
            card.addEventListener('mouseleave', function () {
                card.style.transform = 'rotateY(0) rotateX(0) translateY(0)';
            });
        });
    }

    /**
     * عدّاد متحرك للأرقام جوه .stat-value لما تظهر لأول مرة - بيشتغل
     * تلقائيًا على أي رقم اتحط بـ JS بعد تحميل الصفحة (زي KPI cards).
     * @param {HTMLElement} el
     * @param {number} target
     */
    function animateCount(el, target) {
        var start = 0;
        var isFloat = target % 1 !== 0;
        var step = target / 40;
        function tick() {
            start = Math.min(start + step, target);
            el.textContent = isFloat ? start.toFixed(1) : Math.round(start).toLocaleString();
            if (start < target) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    }

    // إعادة تفعيل تأثير الميل كل ما محتوى الصفحة يتغيّر (بعد أي fetch/render)
    var tiltObserver = new MutationObserver(function () { initTilt(); });

    /**
     * جرس الإشعارات المشترك - موجود في شريط كل صفحة (مش الداشبورد بس).
     * بيحمّل الإشعارات أول ما الصفحة تفتح، وبعدين كل 60 ثانية.
     */
    function stateLabel(value) {
        const code = String(value ?? '');
        const labels = {
            google_business:['Google Business Profile','Google Business Profile'], tripadvisor:['TripAdvisor','TripAdvisor'], auto_sent:['تم الإرسال تلقائيًا','Automatically sent'],
            pending:['في الانتظار','Pending'], open:['مفتوحة','Open'], medium:['متوسطة','Medium'], high:['عالية','High'], low:['منخفضة','Low'], done:['منتهية','Done'],
            active:['نشط','Active'], inactive:['غير نشط','Inactive'], completed:['مكتمل','Completed'], released:['أُلغي الحجز على الرصيد','Released'], reserved:['محجوز','Reserved'],
            paid:['مدفوعة','Paid'], wallet:['المحفظة','Wallet'], subscription_charge:['خصم اشتراك','Subscription charge'], deposit:['إيداع','Deposit'],
            seo_data:['قياسات الكلمات والترتيب','Keyword and rank measurements'], ai_visibility:['قياس الظهور في الإجابات','AI answer measurements'], ai_assistant_message:['رسائل المساعد','Assistant messages'],
            website_generation:['توليد المواقع','Website generations'], website_item:['عناصر المواقع','Website items'], article_generation:['توليد المقالات','Articles'], marketing_tool:['إعداد خطط التسويق','Marketing planning'],
            ai_tool:['أدوات الذكاء الاصطناعي','AI tools'], media_image:['توليد الصور','Image generations'], media_video:['توليد الفيديو','Video generations']
        };
        return labels[code]?.[(document.documentElement.lang || '').startsWith('ar') ? 0 : 1] || code;
    }

    function groupNotifications(items) {
        const groups = new Map();
        for (const n of items || []) {
            const id = Number(n.id);
            if (!Number.isSafeInteger(id) || id < 1) continue;
            // Same event and destination within one UTC day. Different campaigns never collapse together.
            const key = JSON.stringify([n.type || '', n.title || '', n.link || '', String(n.created_at || '').slice(0,10)]);
            const g = groups.get(key);
            if (g) { g.notification_ids.push(id); g.repeat_count++; if (!n.read_at) g.read_at = null; }
            else groups.set(key, {...n, notification_ids:[id], repeat_count:1});
        }
        return [...groups.values()];
    }

    function initNotifBell() {
        var btn = document.getElementById('panelNotifBtn');
        var menu = document.getElementById('panelNotifMenu');
        var badge = document.getElementById('panelNotifBadge');
        if (!btn || !menu || !badge) return;

        function renderMenu(notifications) {
            if (!notifications || !notifications.length) {
                menu.innerHTML = '<div class="panel-notif-empty">🔔<br>لا يوجد إشعارات</div>';
                return;
            }
            menu.innerHTML = groupNotifications(notifications).map(function (n) {
                var unreadClass = n.read_at ? '' : ' unread';
                var link = '/dashboard';
                try { const u=new URL(n.link || '/dashboard',location.href); if(['http:','https:'].includes(u.protocol)) link=u.href; } catch (_) {}
                return '<a href="' + esc(link) + '" class="panel-notif-item' + unreadClass + '" data-id="' + n.id + '" data-notification-ids="' + n.notification_ids.join(',') + '">' +
                    '<div class="nt-title">' + esc(n.title) + (n.repeat_count > 1 ? ' <small>×' + n.repeat_count + '</small>' : '') + '</div>' +
                    (n.body ? '<div class="nt-body">' + esc(n.body) + '</div>' : '') +
                    '<div class="nt-time">' + timeAgo(n.created_at) + '</div>' +
                    '</a>';
            }).join('');
        }

        async function load() {
            var res = await fetchJSON('/api/dashboard/notifications');
            if (!res.success) return;
            var notifications = res.data.notifications || [];
            var unreadCount = notifications.filter(function (n) { return !n.read_at; }).length;
            if (unreadCount > 0) {
                badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
            btn.setAttribute('aria-label', (uiLocale().startsWith('ar') ? 'الإشعارات غير المقروءة: ' : 'Unread notifications: ') + unreadCount);
            renderMenu(notifications);
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = menu.style.display === 'block';
            menu.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) load();
        });

        menu.addEventListener('click', function (e) {
            var item = e.target.closest('.panel-notif-item');
            if (!item) return;
            var ids = String(item.dataset.notificationIds || item.dataset.id).split(',').map(Number);
            if (ids.length) fetchJSON('/api/dashboard/notifications/read-group', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids}),keepalive:true});
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('#panelNotifWrap')) menu.style.display = 'none';
        });

        load();
        setInterval(load, 60000);
    }

    /**
     * "الموقع الحالي" الموحّد - قائمة اختيار في الشريط العلوي بتفضل ثابتة
     * عبر كل صفحات اللوحة (مخزّنة في localStorage). أي صفحة تانية تقدر
     * تقرأ الاختيار الحالي بـ Panel.getCurrentWebsiteId()، أو تسمع لحدث
     * 'tourfecto:website-changed' لو عايزة تتفاعل فورًا مع أي تغيير.
     */
    var CURRENT_WEBSITE_KEY = 'tourfecto_current_website_id';

    function getCurrentWebsiteId() {
        return localStorage.getItem(CURRENT_WEBSITE_KEY) || '';
    }

    function setCurrentWebsiteId(id) {
        if (id) localStorage.setItem(CURRENT_WEBSITE_KEY, id);
        else localStorage.removeItem(CURRENT_WEBSITE_KEY);
        window.dispatchEvent(new CustomEvent('tourfecto:website-changed', { detail: { websiteId: id } }));
    }

    async function loadWebsiteSelector() {
        var sel = document.getElementById('panelWebsiteSelect');
        if (!sel) return;

        try {
            var res = await fetchJSON('/api/websites');
            var sites = (res.success && res.data.websites) ? res.data.websites : [];
            if (!sites.length) return; // مفيش مواقع لسه - سيبها مخفية

            sel.innerHTML = sites.map(function (w) {
                var host = String(w.main_url || '').replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/.*$/, '');
                return '<option value="' + w.id + '">' + esc(host || w.company_name || ('#' + w.id)) + '</option>';
            }).join('');

            var saved = getCurrentWebsiteId();
            var validIds = sites.map(function (w) { return String(w.id); });
            if (!saved || validIds.indexOf(String(saved)) === -1) {
                saved = String(sites[0].id);
                setCurrentWebsiteId(saved);
            }
            sel.value = saved;
            // موقع واحد بس: مفيش حاجة تختارها، فالقائمة بتفضل مخفية
            if (sites.length > 1) sel.style.display = 'block';

            sel.addEventListener('change', function () {
                setCurrentWebsiteId(sel.value);
            });
        } catch (e) {
            // فشل صامت - القائمة دي تحسين مش أساسي
        }
    }

    /**
     * أي صفحة عندها قائمة اختيار موقع خاصة بيها تقدر تنده الدالة دي بعد
     * ما تملاها بالخيارات، عشان تتزامن تلقائيًا مع "الموقع الحالي"
     * الموحّد بدل ما العميل يضطر يختار تاني من الصفر في كل صفحة.
     */
    function syncWebsiteSelect(selectId) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        var current = getCurrentWebsiteId();
        if (current && sel.querySelector('option[value="' + current + '"]')) {
            sel.value = current;
        }
        sel.addEventListener('change', function () {
            setCurrentWebsiteId(sel.value);
        });
    }

    // مبلغ بعملة النشاط (window.TF_CURRENCY من renderPanelPage) - صفحة
    // الإيرادات كانت بتنادي P.money ومكانتش موجودة فالصفحة كلها بتقف.
    function money(n) {
        var v = Number(n);
        if (!isFinite(v)) return '—';
        var cur = (global.TF_CURRENCY || 'USD');
        try {
            return new Intl.NumberFormat(document.documentElement.lang || 'en', { style: 'currency', currency: cur, maximumFractionDigits: 2 }).format(v);
        } catch (e) {
            return cur + ' ' + v.toFixed(2);
        }
    }

    /**
     * قوائم "اختار موقع": العميل اللي عنده موقع واحد كان لازم يختاره بإيده في كل
     * صفحة (والا تطلع "اختار موقع الأول"). هنا بنختاره لوحده، ولو عنده كذا موقع
     * بنختار آخر موقع اشتغل عليه. القائمة لازم يكون أول اختيار فيها فاضي (placeholder).
     */
    function autoSelectWebsite(sel) {
        if (!sel || sel.dataset.tfAutoSite || sel.disabled || sel.multiple || sel.value !== '') return;
        if (!/site|website|موقع/i.test((sel.id || '') + ' ' + (sel.name || '') + ' ' + (sel.options[0] ? sel.options[0].text : ''))) return;
        if (sel.options[0] && /كل|all/i.test(sel.options[0].text)) return; // فلتر "كل المواقع" مش اختيار ناقص
        const real = Array.prototype.filter.call(sel.options, o => o.value !== '' && !o.disabled);
        if (!real.length) return;
        let pick = real.length === 1 ? real[0] : null;
        if (!pick) {
            let current = '';
            try { current = getCurrentWebsiteId(); } catch (e) {}
            pick = real.find(o => o.value === String(current)) || null;
        }
        if (!pick) return;
        sel.dataset.tfAutoSite = '1';
        sel.value = pick.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function watchWebsiteSelects() {
        const scan = () => document.querySelectorAll('.panel-content select').forEach(autoSelectWebsite);
        scan();
        let queued = false;
        new MutationObserver(() => { if (queued) return; queued = true; setTimeout(() => { queued = false; scan(); }, 150); })
            .observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchWebsiteSelects); else watchWebsiteSelects();

    /** أسماء مفهومة لأحداث سجل النشاط (كانت بتظهر زي monitoring.cycle_completed) */
    const ACTIVITY_LABELS = {
        'monitoring.cycle_completed': ['فحص تلقائي للموقع خلص', 'Automatic site check completed'],
        'monitoring.paused_all': ['المتابعة التلقائية اتوقفت', 'Automatic monitoring paused'],
        'monitoring.resumed_all': ['المتابعة التلقائية رجعت تشتغل', 'Automatic monitoring resumed'],
        'onboarding.submitted': ['إعداد الحساب اتحفظ', 'Account setup saved'],
        'onboarding.viewed': ['فتحت صفحة الإعداد السريع', 'Opened quick setup'],
        'onboarding.completed': ['إعداد الحساب خلص', 'Account setup completed'],
        'executive_summary.viewed': ['فتحت الملخص التنفيذي', 'Opened the executive summary'],
        'wallet.deposit_requested': ['طلب شحن رصيد', 'Wallet top-up requested'],
        'wallet.deposit_approved': ['شحن الرصيد اتقبل', 'Wallet top-up approved'],
        'report.generated': ['تقرير جديد اتعمل', 'New report generated'],
        'report.exported': ['تقرير اتصدّر', 'Report exported'],
        'report.deleted': ['تقرير اتمسح', 'Report deleted'],
        'booking.reminder_reconciled': ['تذكير حجز اتراجع', 'Booking reminder reconciled'],
        'subscription.cancelled': ['الاشتراك اتلغى', 'Subscription cancelled'],
        'subscription.resumed': ['الاشتراك رجع', 'Subscription resumed'],
        'subscriptions.metrics_viewed': ['فتحت مؤشرات الاشتراك', 'Opened subscription metrics'],
    };
    function activityLabel(key) {
        const k = String(key || '');
        const ar = (document.documentElement.lang || 'ar').slice(0, 2) === 'ar';
        if (ACTIVITY_LABELS[k]) return ACTIVITY_LABELS[k][ar ? 0 : 1];
        // مفتاح تقني تاني (a.b_c): نحوله لجملة مقروءة بدل ما يظهر زي ما هو
        if (/^[a-z_]+\.[a-z_]+$/.test(k)) { const t = k.replace(/[._]/g, ' '); return t.charAt(0).toUpperCase() + t.slice(1); }
        return k;
    }

    global.Panel = {
        activityLabel,
        money: money,
        sessionExpired: showSessionExpired,
        esc: esc,
        fetchJSON: fetchJSON,
        toast: toast,
        timeAgo: timeAgo,
        formatDate: formatDate,
        initSidebar: initSidebar,
        openModal: openModal,
        closeModal: closeModal,
        initials: initials,
        statusPill: statusPill,
        animateCount: animateCount,
        stateLabel: stateLabel,
        groupNotifications: groupNotifications,
        getCurrentWebsiteId: getCurrentWebsiteId,
        setCurrentWebsiteId: setCurrentWebsiteId,
        syncWebsiteSelect: syncWebsiteSelect,
    };
    // تصحيح باغ حقيقي وواسع الانتشار: صفحات كتير بتستخدم onclick="P.xxx()"
    // مباشرة جوه HTML (زي "إيداع رصيد" وزراير إغلاق المودالات). الـ inline
    // onclick بيتنفّذ في السياق العام (window)، ومتغيّر P المحلي جوه كل IIFE
    // (const P = window.Panel) مش متاح له خالص - يعني كل زرار زي ده كان
    // بيفشل بصمت (ReferenceError: P is not defined) من غير أي رد فعل ظاهر.
    // الحل: نتيح P عالميًا كمان، مش بس Panel.
    global.P = global.Panel;

    /**
     * تفعيل PWA: ربط manifest.json + أيقونات + تسجيل Service Worker.
     * بنعملها من هنا (JS مشترك) بدل ما نضيفها لكل شل HTML على حدة،
     * عشان تغطي كل صفحات لوحة التحكم من غير ما نلمس أي ملف PHP ضخم.
     */
    function initPWA() {
        if (!document.querySelector('link[rel="manifest"]')) {
            var manifestLink = document.createElement('link');
            manifestLink.rel = 'manifest';
            manifestLink.href = '/manifest.json';
            document.head.appendChild(manifestLink);
        }
        if (!document.querySelector('meta[name="theme-color"]')) {
            var themeColor = document.createElement('meta');
            themeColor.name = 'theme-color';
            themeColor.content = '#060A13';
            document.head.appendChild(themeColor);
        }
        if (!document.querySelector('link[rel="apple-touch-icon"]')) {
            var appleIcon = document.createElement('link');
            appleIcon.rel = 'apple-touch-icon';
            appleIcon.href = '/assets/icons/apple-touch-icon.png';
            document.head.appendChild(appleIcon);
        }
        var appleCapable = document.createElement('meta');
        appleCapable.name = 'apple-mobile-web-app-capable';
        appleCapable.content = 'yes';
        document.head.appendChild(appleCapable);

        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(function () {});
        }
    }

    /**
     * شارة رصيد AI في الشريط العلوي - بتستخدم endpoint موجود بالفعل
     * (GET /api/subscription/current) فمفيش أي تعديل خلفي مطلوب.
     * بتتلوّن (عادي/تحذير/خلاص) حسب النسبة المتبقية.
     */
    async function loadCreditsBadge() {
        var wrap = document.getElementById('panelCreditsWrap');
        var textEl = document.getElementById('panelCreditsText');
        if (!wrap || !textEl) return;

        try {
            var res = await fetchJSON('/api/subscription/current');
            if (!res.success || !res.data.has_subscription || !res.data.usage || !res.data.usage.ai) return;

            var ai = res.data.usage.ai;
            var remaining = Math.max(0, ai.remaining);
            var total = ai.total || 0;
            if (!total) return;

            // كان بيظهر «60 / 51» من غير أي شرح (والاتجاه العربي كان بيقلب الرقمين) - دلوقتي العدد ومعناه
            var isAr = (document.documentElement.lang || '').startsWith('ar');
            textEl.setAttribute('dir', 'auto');
            textEl.textContent = isAr ? ('باقي ' + remaining + ' تحليل') : (remaining + ' analyses left');
            wrap.title = isAr ? ('تحليلات السيو الباقية الشهر ده: ' + remaining + ' من ' + total + ' - دوس للتفاصيل') : ('SEO analyses left this month: ' + remaining + ' of ' + total + ' - click for details');
            wrap.classList.remove('low', 'empty');
            var ratio = remaining / total;
            if (remaining <= 0) wrap.classList.add('empty');
            else if (ratio <= 0.15) wrap.classList.add('low');
            wrap.style.display = 'flex';
        } catch (e) {
            // فشل صامت - الشارة دي تحسين مش أساسي، مش هنعطّل باقي الصفحة عشانها
        }
    }

    /**
     * شارة رصيد المحفظة في الشريط العلوي - بتستخدم endpoint موجود
     * بالفعل (GET /api/wallet/balance) فمفيش أي تعديل خلفي مطلوب.
     */
    async function loadWalletBadge() {
        var wrap = document.getElementById('panelWalletWrap');
        var textEl = document.getElementById('panelWalletText');
        if (!wrap || !textEl) return;

        try {
            var res = await fetchJSON('/api/wallet/balance');
            if (!res.success) return;

            var balance = Number(res.data.balance || 0);
            textEl.textContent = '$' + balance.toFixed(2);
            textEl.title = (document.documentElement.lang.startsWith('ar')?'المتاح للإنفاق؛ المحجوز: $':'Spendable; reserved: $') + Number(res.data.reserved || 0).toFixed(2);
            wrap.style.display = 'flex';
        } catch (e) {
            // فشل صامت
        }
    }

    // أي صفحة بتخصم من الرصيد (الاستوديو مثلًا) تقدر تحدّث الرصيد اللي فوق من غير reload
    window.refreshWalletBadge = loadWalletBadge;

    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initTilt();
        initNotifBell();
        initPWA();
        loadCreditsBadge();
        loadWalletBadge();
        loadWebsiteSelector();
        var content = document.querySelector('.panel-content');
        if (content) tiltObserver.observe(content, { childList: true, subtree: true });
    });
})(window);

/*
 * Slim top bar: it keeps only the notifications. The account (company icon -> profile), theme, language, wallet balance and
 * monthly-analyses chip live at the bottom of the sidebar. The nodes are MOVED (same elements and ids), so the code that
 * fills them keeps working.
 */
(function () {
    'use strict';
    var tools = null;

    function footer() { return document.querySelector('.panel-sidebar-footer'); }
    function ensureTools() {
        if (tools && tools.isConnected) return tools;
        var f = footer();
        if (!f) return null;
        tools = document.createElement('div');
        tools.className = 'tf-side-tools';
        f.insertBefore(tools, f.firstChild);
        return tools;
    }
    function relocate() {
        var t = ensureTools();
        if (!t) return;
        var acct = document.getElementById('tfSideAcct');
        var rowIcons = t.querySelector('.tf-side-icons');
        if (!rowIcons) { rowIcons = document.createElement('div'); rowIcons.className = 'tf-side-icons'; t.appendChild(rowIcons); }
        [document.getElementById('tfThemeBtn'), document.querySelector('.panel-topbar-actions .panel-langsel')].forEach(function (n) {
            if (n && n.parentNode !== rowIcons) rowIcons.appendChild(n);
        });
        ['panelWalletWrap', 'panelCreditsWrap'].forEach(function (id) {
            var n = document.getElementById(id);
            if (n && n.parentNode !== t) t.appendChild(n);
        });
        var cur = document.querySelector('.panel-topbar-actions .tf-cur');
        if (cur && cur.parentNode !== t) t.appendChild(cur);
        if (acct && t.firstChild !== acct) t.insertBefore(acct, t.firstChild);
    }

    function paintAccount(data) {
        var t = ensureTools();
        if (!t) return;
        var a = document.getElementById('tfSideAcct');
        if (!a) { a = document.createElement('a'); a.id = 'tfSideAcct'; a.className = 'tf-side-acct'; a.href = '/profile/settings'; }
        var name = (data && data.name) || '';
        var icons = (data && data.icons) || [];
        a.title = name || 'Account';
        a.textContent = '';
        var av = document.createElement('span');
        av.className = 'tf-side-av';
        var letter = (name.trim().charAt(0) || '?').toUpperCase();
        av.textContent = letter;
        var i = 0;
        (function next() {
            if (i >= icons.length) return;
            var img = new Image();
            img.alt = '';
            img.referrerPolicy = 'no-referrer';
            img.onload = function () { if (img.naturalWidth > 1) { av.textContent = ''; av.appendChild(img); } else { next(); } };
            img.onerror = next;
            img.src = icons[i++];
        })();
        var nm = document.createElement('span');
        nm.className = 'tf-side-name';
        nm.textContent = name || letter;
        a.appendChild(av);
        a.appendChild(nm);
        t.insertBefore(a, t.firstChild);
    }
    function loadAccount() {
        var wid = 0;
        try { wid = (window.Panel && Panel.getCurrentWebsiteId && Panel.getCurrentWebsiteId()) || 0; } catch (e) { wid = 0; }
        fetch('/api/panel/company' + (wid ? '?website_id=' + encodeURIComponent(wid) : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { paintAccount(j && j.data); })
            .catch(function () { paintAccount(null); });
    }

    function start() {
        relocate();
        loadAccount();
        var top = document.querySelector('.panel-topbar-actions');
        if (top) {
            var obs = new MutationObserver(relocate); // the theme button and the currency picker are added by other scripts a moment later
            obs.observe(top, { childList: true });
            setTimeout(function () { obs.disconnect(); relocate(); }, 6000);
        }
        window.addEventListener('tourfecto:website-changed', loadAccount);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
