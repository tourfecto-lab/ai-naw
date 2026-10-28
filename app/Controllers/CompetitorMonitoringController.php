<?php

/**
 * Tourfecto - Competitor Monitoring Controller
 * بيبني فوق جدول competitors الموجود بالفعل (مش بيكرره) - بيضيف تتبع
 * أسعار وعروض وترتيب Google وتنبيهات للمنافسين اللي المستخدم ضايفهم
 * بالفعل من صفحة /ai/competitors.
 * @version 2.0.0 - إعادة تصميم كاملة: شرح واضح لطريقة عمل الميزة،
 * إحصائيات سريعة، ربط صحيح بصفحة "المنافسين" (كان بيوجّه غلط لصفحة
 * تحليل SEO)، واتجاه تغيّر السعر (↑/↓ %) بدل أرقام مجردة.
 */
class CompetitorMonitoringController extends Controller
{
    /** GET /competitor-monitoring */
    public function index(array $params = []): array
    {
        $tHowTitle = $this->tr('cm.how.title');
        $tHowBody = $this->tr('cm.how.body');
        $tAddCompetitorBtn = $this->tr('cm.add_competitor_btn');
        $tNoCompetitorsBody = $this->tr('cm.no_competitors_body');
        $tNoCompetitorsCta = $this->tr('cm.no_competitors_cta');

        $body = <<<HTML
        <div class="p-card" style="margin-bottom:16px;">
            <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
                <div style="font-size:22px;line-height:1;">🎯</div>
                <div style="flex:1;min-width:240px;">
                    <div style="font-weight:800;font-size:14px;">{$tHowTitle}</div>
                    <div class="p-cell-muted" style="margin-top:4px;line-height:1.8;">{$tHowBody}</div>
                </div>
            </div>
        </div>

        <div class="p-grid cols-4" id="cmStats">
            <div class="p-card stat-tile"><div class="stat-icon blue">🏁</div><div class="stat-info"><div class="stat-value" id="cmStatCompetitors">-</div><div class="stat-label">{$this->tr('cm.stat.competitors_tracked')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value" id="cmStatPricePoints">-</div><div class="stat-label">{$this->tr('cm.stat.price_points')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🔔</div><div class="stat-info"><div class="stat-value" id="cmStatAlertsMonth">-</div><div class="stat-label">{$this->tr('cm.stat.alerts_month')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">⏱️</div><div class="stat-info"><div class="stat-value" style="font-size:14px;" id="cmStatLastAlert">-</div><div class="stat-label">{$this->tr('cm.stat.last_alert')}</div></div></div>
        </div>

        <div class="p-toolbar" style="margin-top:18px;">
            <select id="cmCompetitorSelect" class="p-select"><option value="">{$this->tr('cm.select_competitor')}</option></select>
            <a href="/ai/competitors" class="p-btn outline xs">+ {$tAddCompetitorBtn}</a>
        </div>

        <div class="p-card" id="cmNoCompetitorsEmpty" style="display:none;">
            <div class="p-empty">
                <div class="p-empty-icon">🏁</div>
                {$tNoCompetitorsBody}
                <div style="margin-top:14px;"><a href="/ai/competitors" class="p-btn primary">{$tNoCompetitorsCta}</a></div>
            </div>
        </div>

        <div id="cmDashboardContent">
            <div class="p-grid cols-2" style="margin-top:14px;align-items:start;">
                <div class="p-card no-pad">
                    <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('cm.pricing.title')}</h3></div>
                    <div style="padding:16px 20px;display:flex;gap:8px;flex-wrap:wrap;">
                        <input type="text" id="cmPriceName" class="p-input" placeholder="{$this->tr('cm.pricing.name_placeholder')}" style="flex:1;min-width:120px;">
                        <input type="number" id="cmPriceValue" class="p-input" placeholder="{$this->tr('cm.pricing.price_placeholder')}" step="0.01" style="max-width:110px;">
                        <button class="p-btn primary xs" onclick="cmAddPrice()">{$this->tr('cm.add_btn')}</button>
                    </div>
                    <div class="p-table-scroll"><table class="p-table" id="cmPriceTable">
                        <thead><tr><th>{$this->tr('cm.col.service')}</th><th>{$this->tr('cm.pricing.price_placeholder')}</th><th>{$this->tr('cm.col.date')}</th></tr></thead>
                        <tbody><tr><td colspan="3" class="p-cell-muted">{$this->tr('cm.select_competitor_hint')}</td></tr></tbody>
                    </table></div>
                </div>
                <div class="p-card no-pad">
                    <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('cm.offers.title')}</h3></div>
                    <div style="padding:16px 20px;display:flex;gap:8px;">
                        <input type="text" id="cmOfferTitle" class="p-input" placeholder="{$this->tr('cm.offers.title_placeholder')}" style="flex:1;">
                        <button class="p-btn primary xs" onclick="cmAddOffer()">{$this->tr('cm.add_btn')}</button>
                    </div>
                    <div class="p-table-scroll"><table class="p-table" id="cmOfferTable">
                        <thead><tr><th>{$this->tr('cm.offers.title_placeholder')}</th><th>{$this->tr('cm.col.date')}</th></tr></thead>
                        <tbody><tr><td colspan="2" class="p-cell-muted">{$this->tr('cm.select_competitor_hint')}</td></tr></tbody>
                    </table></div>
                </div>
            </div>

            <div class="p-card no-pad" style="margin-top:18px;">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('cm.alerts.title')}</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="cmAlertsTable">
                    <thead><tr><th>{$this->tr('cm.col.competitor')}</th><th>{$this->tr('cm.col.type')}</th><th>{$this->tr('cm.col.message')}</th><th>{$this->tr('cm.col.date')}</th></tr></thead>
                    <tbody><tr><td colspan="4" class="p-cell-muted">{$this->tr('common.loading')}</td></tr></tbody>
                </table></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate, timeAgo = P.timeAgo;
    let currentId = null;

    const alertTypeMeta = {
        price_change: { icon: '💰', label: __ALERT_TYPE_PRICE__ },
        new_offer: { icon: '🎁', label: __ALERT_TYPE_OFFER__ },
    };

    async function loadSummary() {
        const res = await fetchJSON('/api/competitor-monitoring/summary');
        if (!res.success) return;
        const d = res.data;
        document.getElementById('cmStatCompetitors').textContent = d.competitors_count;
        document.getElementById('cmStatPricePoints').textContent = d.price_points_count;
        document.getElementById('cmStatAlertsMonth').textContent = d.alerts_this_month;
        document.getElementById('cmStatLastAlert').textContent = d.last_alert_at ? timeAgo(d.last_alert_at) : __NO_ALERTS_YET__;
    }

    async function loadCompetitors() {
        const res = await fetchJSON('/api/ai/competitors');
        const sel = document.getElementById('cmCompetitorSelect');
        const list = (res.success && res.data.competitors) || [];
        const emptyBox = document.getElementById('cmNoCompetitorsEmpty');
        const dashboard = document.getElementById('cmDashboardContent');
        const toolbar = document.getElementById('cmCompetitorSelect').closest('.p-toolbar');

        if (list.length) {
            sel.innerHTML = `<option value="">${__SELECT_COMPETITOR__}</option>` + list.map(c => `<option value="${c.id}">${esc(c.competitor_name || c.competitor_domain)}</option>`).join('');
            emptyBox.style.display = 'none';
            dashboard.style.display = 'block';
            toolbar.style.display = 'flex';
        } else {
            sel.innerHTML = `<option value="">${__NO_COMPETITORS_YET__}</option>`;
            emptyBox.style.display = 'block';
            dashboard.style.display = 'none';
            toolbar.style.display = 'none';
        }

        sel.addEventListener('change', function () {
            currentId = this.value;
            if (currentId) loadDetail();
        });
    }

    window.cmAddPrice = async function () {
        if (!currentId) { toast(__SELECT_COMPETITOR_HINT__, 'error'); return; }
        const item_name = document.getElementById('cmPriceName').value;
        const price = parseFloat(document.getElementById('cmPriceValue').value);
        if (!item_name || !price) { toast(__FILL_SERVICE_PRICE__, 'error'); return; }
        const res = await fetchJSON('/api/competitor-monitoring/pricing', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: currentId, item_name, price }) });
        if (res.success) {
            toast(__ADDED__, 'success');
            document.getElementById('cmPriceName').value = '';
            document.getElementById('cmPriceValue').value = '';
            loadDetail();
            loadSummary();
            loadAlerts();
        } else {
            toast(res.error || __GENERIC_ERROR__, 'error');
        }
    };

    window.cmAddOffer = async function () {
        if (!currentId) { toast(__SELECT_COMPETITOR_HINT__, 'error'); return; }
        const title = document.getElementById('cmOfferTitle').value;
        if (!title) { toast(__FILL_OFFER_TITLE__, 'error'); return; }
        const res = await fetchJSON('/api/competitor-monitoring/offers', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ competitor_id: currentId, title }) });
        if (res.success) {
            toast(__ADDED__, 'success');
            document.getElementById('cmOfferTitle').value = '';
            loadDetail();
            loadSummary();
            loadAlerts();
        } else {
            toast(res.error || __GENERIC_ERROR__, 'error');
        }
    };

    function withTrends(pricing) {
        const groups = {};
        pricing.forEach(p => {
            if (!groups[p.item_name]) groups[p.item_name] = [];
            groups[p.item_name].push(p);
        });
        return pricing.map(p => {
            const g = groups[p.item_name];
            if (g[0] === p && g.length > 1) {
                const prev = parseFloat(g[1].price);
                const curr = parseFloat(p.price);
                if (prev > 0 && curr !== prev) {
                    return Object.assign({}, p, { trendPct: ((curr - prev) / prev) * 100 });
                }
            }
            return p;
        });
    }

    function trendBadge(p) {
        if (typeof p.trendPct !== 'number') return '';
        const up = p.trendPct > 0;
        const color = up ? 'var(--panel-danger)' : 'var(--panel-success)';
        const arrow = up ? '▲' : '▼';
        return ` <span style="color:${color};font-size:11.5px;font-weight:700;">${arrow} ${Math.abs(p.trendPct).toFixed(1)}%</span>`;
    }

    async function loadDetail() {
        const res = await fetchJSON('/api/competitor-monitoring/detail?competitor_id=' + currentId);
        if (!res.success) return;

        const pricing = withTrends(res.data.pricing || []);
        document.querySelector('#cmPriceTable tbody').innerHTML = pricing.length
            ? pricing.map(p => `<tr><td>${esc(p.item_name)}</td><td>${esc(p.price)}${trendBadge(p)}</td><td>${formatDate(p.observed_at)}</td></tr>`).join('')
            : `<tr><td colspan="3" class="p-cell-muted">${__NO_PRICING_YET__}</td></tr>`;

        const offers = res.data.offers || [];
        document.querySelector('#cmOfferTable tbody').innerHTML = offers.length
            ? offers.map(o => `<tr><td>${esc(o.title)}</td><td>${formatDate(o.detected_at)}</td></tr>`).join('')
            : `<tr><td colspan="2" class="p-cell-muted">${__NO_OFFERS_YET__}</td></tr>`;
    }

    async function loadAlerts() {
        const res = await fetchJSON('/api/competitor-monitoring/alerts');
        const rows = (res.success && res.data.alerts) || [];
        document.querySelector('#cmAlertsTable tbody').innerHTML = rows.length
            ? rows.map(a => {
                const meta = alertTypeMeta[a.alert_type] || { icon: '🔔', label: esc(a.alert_type) };
                return `<tr><td>${esc(a.competitor_name || '-')}</td><td>${meta.icon} ${meta.label}</td><td>${esc(a.message)}</td><td>${timeAgo(a.created_at)}</td></tr>`;
            }).join('')
            : `<tr><td colspan="4" class="p-cell-muted">${__NO_ALERTS_TABLE__}</td></tr>`;
    }

    loadSummary();
    loadCompetitors();
    loadAlerts();
})();
JS;
        $script = str_replace(
            [
                '__ALERT_TYPE_PRICE__', '__ALERT_TYPE_OFFER__', '__NO_ALERTS_YET__',
                '__SELECT_COMPETITOR__', '__NO_COMPETITORS_YET__', '__SELECT_COMPETITOR_HINT__',
                '__FILL_SERVICE_PRICE__', '__ADDED__', '__GENERIC_ERROR__', '__FILL_OFFER_TITLE__',
                '__NO_PRICING_YET__', '__NO_OFFERS_YET__', '__NO_ALERTS_TABLE__',
            ],
            [
                $this->trJs('cm.alert.type.price_change'),
                $this->trJs('cm.alert.type.new_offer'),
                $this->trJs('cm.no_alerts_yet'),
                $this->trJs('cm.select_competitor'),
                $this->trJs('cm.no_competitors'),
                $this->trJs('cm.select_competitor_hint'),
                $this->trJs('cm.fill_service_price'),
                $this->trJs('common.added'),
                $this->trJs('cm.generic_error'),
                $this->trJs('cm.fill_offer_title'),
                $this->trJs('cm.no_pricing_yet'),
                $this->trJs('cm.no_offers_yet'),
                $this->trJs('cm.no_alerts_yet'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('competitor_monitoring', $this->tr('cm.page.title'), $this->tr('cm.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /api/competitor-monitoring/summary */
    public function getSummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->user['id'];

        try {
            $competitorsCount = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM competitors WHERE user_id = ?",
                [$userId]
            );
            $pricePointsCount = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM cm_pricing p
                 JOIN competitors c ON c.id = p.competitor_id
                 WHERE c.user_id = ?",
                [$userId]
            );
            $alertsThisMonth = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM cm_alerts
                 WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')",
                [$userId]
            );
            $lastAlert = $this->db->query(
                "SELECT created_at FROM cm_alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );

            return $this->success([
                'competitors_count' => (int) ($competitorsCount[0]['cnt'] ?? 0),
                'price_points_count' => (int) ($pricePointsCount[0]['cnt'] ?? 0),
                'alerts_this_month' => (int) ($alertsThisMonth[0]['cnt'] ?? 0),
                'last_alert_at' => $lastAlert[0]['created_at'] ?? null,
            ]);
        } catch (Exception $e) {
            Logger::error('Competitor Monitoring Summary Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    /** GET /api/competitor-monitoring/detail?competitor_id=X */
    public function getDetail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitorId = (int) $this->get('competitor_id');
        if (!$this->ownsCompetitor($competitorId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $pricing = $this->db->query("SELECT item_name, price, observed_at FROM cm_pricing WHERE competitor_id = ? ORDER BY observed_at DESC LIMIT 20", [$competitorId]);
            $offers = $this->db->query("SELECT title, detected_at FROM cm_offers WHERE competitor_id = ? ORDER BY detected_at DESC LIMIT 20", [$competitorId]);
            return $this->success(['pricing' => $pricing, 'offers' => $offers]);
        } catch (Exception $e) {
            Logger::error('Competitor Monitoring Detail Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    /** POST /api/competitor-monitoring/pricing */
    public function addPricing(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitorId = (int) $this->get('competitor_id');
        if (!$this->ownsCompetitor($competitorId)) {
            return $this->error('غير مصرح', 403);
        }

        $itemName = $this->get('item_name');
        $price = (float) $this->get('price');
        if (!$itemName || $price <= 0) {
            return $this->error('بيانات ناقصة', 422);
        }

        try {
            $this->db->exec("INSERT INTO cm_pricing (competitor_id, item_name, price, observed_at) VALUES (?, ?, ?, CURDATE())", [$competitorId, $itemName, $price]);
            $this->maybeAlertPriceChange($competitorId, $itemName, $price);
            return $this->success([], 'تم التسجيل');
        } catch (Exception $e) {
            Logger::error('Competitor Monitoring Add Pricing Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التسجيل', 500);
        }
    }

    /** POST /api/competitor-monitoring/offers */
    public function addOffer(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $competitorId = (int) $this->get('competitor_id');
        if (!$this->ownsCompetitor($competitorId)) {
            return $this->error('غير مصرح', 403);
        }

        $title = $this->get('title');
        if (!$title) {
            return $this->error('العنوان مطلوب', 422);
        }

        try {
            $this->db->exec("INSERT INTO cm_offers (competitor_id, title, detected_at) VALUES (?, ?, CURDATE())", [$competitorId, $title]);
            $this->db->exec(
                "INSERT INTO cm_alerts (competitor_id, user_id, alert_type, message) VALUES (?, ?, 'new_offer', ?)",
                [$competitorId, $this->user['id'], "عرض جديد رُصد: {$title}"]
            );
            return $this->success([], 'تم التسجيل');
        } catch (Exception $e) {
            Logger::error('Competitor Monitoring Add Offer Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التسجيل', 500);
        }
    }

    /** GET /api/competitor-monitoring/alerts */
    public function getAlerts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $alerts = $this->db->query(
                "SELECT a.alert_type, a.message, a.created_at, c.competitor_name
                 FROM cm_alerts a
                 JOIN competitors c ON c.id = a.competitor_id
                 WHERE a.user_id = ?
                 ORDER BY a.created_at DESC LIMIT 30",
                [$this->user['id']]
            );
            return $this->success(['alerts' => $alerts]);
        } catch (Exception $e) {
            Logger::error('Competitor Monitoring Alerts Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    private function ownsCompetitor(int $competitorId): bool
    {
        if (!$competitorId) {
            return false;
        }
        $rows = $this->db->query("SELECT id FROM competitors WHERE id = ? AND user_id = ? LIMIT 1", [$competitorId, $this->user['id']]);
        return !empty($rows);
    }

    private function maybeAlertPriceChange(int $competitorId, string $itemName, float $newPrice): void
    {
        $prev = $this->db->query(
            "SELECT price FROM cm_pricing WHERE competitor_id = ? AND item_name = ? AND observed_at < CURDATE() ORDER BY observed_at DESC LIMIT 1",
            [$competitorId, $itemName]
        );
        if (empty($prev)) {
            return;
        }
        $oldPrice = (float) $prev[0]['price'];
        if ($oldPrice > 0 && abs($newPrice - $oldPrice) / $oldPrice >= 0.05) {
            $direction = $newPrice > $oldPrice ? 'زيادة' : 'نقصان';
            $this->db->exec(
                "INSERT INTO cm_alerts (competitor_id, user_id, alert_type, message) VALUES (?, ?, 'price_change', ?)",
                [$competitorId, $this->user['id'], "{$direction} في سعر \"{$itemName}\" من {$oldPrice} إلى {$newPrice}"]
            );
        }
    }
}
