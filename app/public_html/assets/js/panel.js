/**
 * Tourfecto - Panel Common Helpers
 * دوال مشتركة تُستخدم في لوحة الأدمن ولوحة العميل
 * @version 2.0.0
 */
(function (global) {
    'use strict';

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
        const opts = options || {};
        const method = String(opts.method || 'GET').toUpperCase();
        const readOnly = method === 'GET' || method === 'HEAD';
        let res;
        try {
            res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts, {
                headers: Object.assign({ 'Accept': 'application/json' }, opts.headers || {})
            }));
        } catch (e) {
            return { success: false, error: readOnly
                ? 'تعذر الاتصال. تحقق من اتصال الإنترنت ثم أعد المحاولة.'
                : 'تعذر التأكد من نتيجة العملية. حدّث البيانات للتحقق قبل تكرار الطلب.',
                network_error: true, retry_safe: readOnly };
        }
        let body;
        try { body = await res.json(); } catch (e) {
            return { success: false, error: readOnly ? 'تعذر تحميل البيانات الآن. حاول مرة أخرى.'
                : 'تعذر التأكد من نتيجة العملية. تحقق من البيانات قبل تكرار الطلب.', retry_safe: readOnly };
        }
        if (!body || typeof body !== 'object' || Array.isArray(body)) {
            return { success: false, error: 'تعذر قراءة البيانات. حاول مرة أخرى.', retry_safe: readOnly };
        }
        if (!res.ok) {
            body.success = false;
            if (!body.error) body.error = res.status === 401 || res.status === 419
                ? 'انتهت جلستك. سجّل الدخول مرة أخرى.' : 'تعذر إتمام الطلب الآن. حاول مرة أخرى.';
        }
        if (body.success === false && body.upgrade_url) showUpgradePrompt(body.error, body.upgrade_url);
        return body;
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
        if (diff < 60) return 'الآن';
        if (diff < 3600) return 'منذ ' + Math.floor(diff / 60) + ' دقيقة';
        if (diff < 86400) return 'منذ ' + Math.floor(diff / 3600) + ' ساعة';
        if (diff < 2592000) return 'منذ ' + Math.floor(diff / 86400) + ' يوم';
        return d.toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(dateStr);
        return d.toLocaleString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function initSidebar() {
        const shell = document.querySelector('.panel-shell');
        const toggleBtn = document.getElementById('panelMenuToggle');
        const overlay = document.querySelector('.panel-overlay-bg');
        if (toggleBtn && shell) {
            toggleBtn.addEventListener('click', function () {
                shell.classList.toggle('sidebar-open');
            });
        }
        if (overlay && shell) {
            overlay.addEventListener('click', function () {
                shell.classList.remove('sidebar-open');
            });
        }

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
            menu.innerHTML = notifications.map(function (n) {
                var unreadClass = n.read_at ? '' : ' unread';
                var link = n.link || '#';
                return '<a href="' + esc(link) + '" class="panel-notif-item' + unreadClass + '" data-id="' + n.id + '">' +
                    '<div class="nt-title">' + esc(n.title) + '</div>' +
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
            var id = item.dataset.id;
            if (id) fetchJSON('/api/dashboard/notifications/' + id + '/read', { method: 'POST' });
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
                return '<option value="' + w.id + '">' + esc(w.company_name || w.main_url) + '</option>';
            }).join('');

            var saved = getCurrentWebsiteId();
            var validIds = sites.map(function (w) { return String(w.id); });
            if (!saved || validIds.indexOf(String(saved)) === -1) {
                saved = String(sites[0].id);
                setCurrentWebsiteId(saved);
            }
            sel.value = saved;
            sel.style.display = 'block';

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

    global.Panel = {
        money: money,
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

            textEl.textContent = remaining + ' / ' + total;
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
