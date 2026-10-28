<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';

/**
 * Tourfecto - Reputation Controller
 * متحكم إدارة السمعة والمراجعات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ReputationController extends Controller
{
    /**
     * @var ReputationManager $reputationManager - مدير السمعة
     */
    private $reputationManager;

    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->reputationManager = new ReputationManager();
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * معالجة Webhook للمراجعات
     * POST /api/reputation/webhook
     * @param array $params
     * @return array
     */
    public function webhook(array $params = []): array
    {
        try {
            // التحقق من صحة الـ Webhook
            $platform = $this->get('platform', 'tripadvisor');
            $secret = $this->get('secret');

            if (!$this->validateWebhook($platform, $secret)) {
                return $this->error('Invalid webhook signature', 401);
            }

            // معالجة المراجعة
            $result = $this->reputationManager->processWebhook($this->all());

            return $result;

        } catch (Exception $e) {
            Logger::error('Reputation Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    /**
     * الحصول على جميع المراجعات
     * GET /api/reputation/reviews
     * @param array $params
     * @return array
     */
    public function getReviews(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $websiteId = $this->get('website_id');
            // 🔧 حدود آمنة للصفحات (page سالب كان بيعمل OFFSET سالب)
            $page = max(1, (int) ($this->get('page', 1)));
            $limit = min(100, max(1, (int) ($this->get('limit', 20))));
            $offset = ($page - 1) * $limit;
            $sentiment = $this->get('sentiment');
            $platform = $this->get('platform');
            $minRating = $this->get('min_rating');
            $dateFrom = $this->get('date_from');
            $dateTo = $this->get('date_to');
            $search = trim((string) $this->get('search', ''));
            $unreplied = (string) $this->get('unreplied', '') === '1';

            // 🔧 التاريخ الحقيقي للتقييم (review_date) مش تاريخ ما اتسحب عندنا (created_at):
            //    أول مزامنة كانت بتحط سنين من التقييمات كلها في نفس اليوم
            $dateExpr = 'COALESCE(review_date, created_at)';
            $where = ' WHERE user_id = ?';
            $params = [$this->user['id']];
            if ($websiteId) {
                $where .= ' AND website_id = ?';
                $params[] = $websiteId;
            }
            if ($sentiment) {
                $where .= ' AND sentiment = ?';
                $params[] = $sentiment;
            }
            if ($platform) {
                $where .= ' AND source_platform = ?';
                $params[] = $platform;
            }
            if ($minRating !== null && $minRating !== '') {
                $where .= ' AND rating >= ?';
                $params[] = (int) $minRating;
            }
            if ($dateFrom) {
                $where .= " AND {$dateExpr} >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where .= " AND {$dateExpr} <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }
            if ($search !== '') {
                $where .= ' AND (review_text LIKE ? OR reviewer_name LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            if ($unreplied) {
                $where .= ' AND reply_sent_at IS NULL';
            }

            $reviews = $this->db->query(
                "SELECT reviews.*,
                        source_platform AS platform,
                        external_review_id AS platform_review_id,
                        sentiment AS sentiment_label,
                        ai_generated_reply AS auto_reply_generated,
                        (reply_sent_at IS NOT NULL) AS reply_sent,
                        {$dateExpr} AS review_at
                   FROM reviews{$where} ORDER BY {$dateExpr} DESC, id DESC LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
                $params
            );
            $countResult = $this->db->query('SELECT COUNT(*) as total FROM reviews' . $where, $params);
            $total = (int) ($countResult[0]['total'] ?? 0);

            return $this->success([
                'reviews' => $reviews,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Get Reviews Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('تعذر تحميل التقييمات', 500);
        }
    }

    /**
     * الحصول على إحصائيات المراجعات
     * GET /api/reputation/stats
     * @param array $params
     * @return array
     */
    public function getStats(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $websiteId = $this->get('website_id');

            if ($websiteId) {
                if (!$this->canAccessWebsite((int) $websiteId)) {
                    return $this->error('الموقع غير موجود', 404);
                }
                $stats = Review::getSentimentStats($websiteId);
                $platformStats = Review::getPlatformStats($websiteId);
            } else {
                // إحصائيات لكل المواقع
                $stats = $this->getAllWebsitesStats();
                $platformStats = $this->getAllPlatformsStats();
            }

            return $this->success([
                'stats' => $stats,
                'platforms' => $platformStats
            ]);

        } catch (Exception $e) {
            Logger::error('Get Reputation Stats Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get stats', 500);
        }
    }

    /**
     * تحديث الرد على مراجعة
     * PUT /api/reputation/review/{id}/reply
     * @param array $params
     * @return array
     */
    public function updateReply(array $params): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $reviewId = $params['id'] ?? 0;
            $reply = $this->get('reply');

            if (!$reviewId || !$reply) {
                return $this->error('Review ID and reply are required', 400);
            }

            // التحقق من صلاحية المراجعة
            $sql = "SELECT * FROM reviews WHERE id = ? AND user_id = ? LIMIT 1";
            $result = $this->db->query($sql, [$reviewId, $this->user['id']]);

            if (empty($result)) {
                return $this->error('Review not found', 404);
            }

            // تحديث الرد
            $review = new Review($result[0]);
            $review->updateAutoReply($reply);

            return $this->success([
                'review_id' => $reviewId,
                'reply' => $reply
            ], 'Reply updated successfully');

        } catch (Exception $e) {
            Logger::error('Update Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to update reply', 500);
        }
    }

    /**
     * توليد رد تلقائي لمراجعة
     * POST /api/reputation/review/{id}/generate-reply
     * @param array $params
     * @return array
     */
    public function generateReply(array $params): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $reviewId = $params['id'] ?? 0;

            if (!$reviewId) {
                return $this->error('Review ID is required', 400);
            }

            // جلب المراجعة
            $sql = "SELECT * FROM reviews WHERE id = ? AND user_id = ? LIMIT 1";
            $result = $this->db->query($sql, [$reviewId, $this->user['id']]);

            if (empty($result)) {
                return $this->error('Review not found', 404);
            }

            $reviewData = $result[0];

            // توليد الرد
            $sentiment = [
                'label' => $reviewData['sentiment'] ?? 'neutral',
                'score' => $reviewData['sentiment_score'] ?? 0.5,
                'confidence' => $reviewData['sentiment_confidence'] ?? 0.7
            ];

            $reply = $this->reputationManager->generateReply(
                $reviewData['review_text'],
                $sentiment,
                $reviewData['source_platform'],
                $this->user['id']
            );

            if (!$reply) {
                return $this->error('Failed to generate reply', 500);
            }

            // تحديث المراجعة بالرد
            $review = new Review($reviewData);
            $review->updateAutoReply($reply);

            return $this->success([
                'review_id' => $reviewId,
                'reply' => $reply
            ], 'Reply generated successfully');

        } catch (Exception $e) {
            Logger::error('Generate Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to generate reply', 500);
        }
    }

    /**
     * التحقق من صحة Webhook
     * @param string $platform
     * @param string $secret
     * @return bool
     */
    private function validateWebhook(string $platform, ?string $secret): bool
    {
        // تصحيح أمني: كان فيه fallback لقيمة نصية ثابتة ('default_secret')
        // لو env var الخاص بالمنصة مش متظبط. ده معناه أي حد يعرف الكلمة دي
        // (وهي مكتوبة في نفس الكود ده) يقدر يبعت مراجعات مزيّفة. دلوقتي:
        // لو مفيش secret حقيقي متظبط في .env، الـ webhook يترفض تمامًا.
        $envKeys = [
            'tripadvisor' => 'TRIPADVISOR_WEBHOOK_SECRET',
            'google_business' => 'GOOGLE_WEBHOOK_SECRET',
        ];

        $envKey = $envKeys[$platform] ?? null;
        $expectedSecret = $envKey ? getenv($envKey) : false;

        if (!$expectedSecret) {
            return false; // لا يوجد secret حقيقي متظبط لهذه المنصة - ارفض
        }

        return hash_equals($expectedSecret, $secret ?? '');
    }

    /**
     * الحصول على إحصائيات جميع المواقع
     * @return array
     */
    private function getAllWebsitesStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
                    SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
                    SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
                    AVG(rating) as avg_rating
                FROM reviews 
                WHERE user_id = ?";

        $result = $this->db->query($sql, [$this->user['id']]);

        if (empty($result)) {
            return [
                'total' => 0,
                'positive' => 0,
                'neutral' => 0,
                'negative' => 0,
                'avg_rating' => 0
            ];
        }

        return [
            'total' => (int) ($result[0]['total'] ?? 0),
            'positive' => (int) ($result[0]['positive'] ?? 0),
            'negative' => (int) ($result[0]['negative'] ?? 0),
            'neutral' => (int) ($result[0]['neutral'] ?? 0),
            'avg_rating' => round((float) ($result[0]['avg_rating'] ?? 0), 2)
        ];
    }

    /**
     * الحصول على إحصائيات جميع المنصات
     * @return array
     */
    private function getAllPlatformsStats(): array
    {
        $sql = "SELECT 
                    source_platform AS platform,
                    COUNT(*) as count,
                    AVG(rating) as avg_rating
                FROM reviews 
                WHERE user_id = ? 
                GROUP BY source_platform";

        return $this->db->query($sql, [$this->user['id']]);
    }

    // ============================================
    // صفحات الويب الفعلية (كانت بترجع JSON فاضي بدل صفحة حقيقية)
    // ============================================

    /** GET /reputation/reviews */
    public function showReviews(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;">
            <input type="text" id="filterSearch" class="p-select" placeholder="🔍 {$this->tr('rep.col.review_text')}..." style="min-width:180px;">
            <select id="filterSentiment" class="p-select">
                <option value="">{$this->tr('rep.filter.all_sentiments')}</option>
                <option value="positive">{$this->tr('rep.sentiment.positive')}</option>
                <option value="neutral">{$this->tr('rep.sentiment.neutral')}</option>
                <option value="negative">{$this->tr('rep.sentiment.negative')}</option>
            </select>
            <select id="filterPlatform" class="p-select">
                <option value="">{$this->tr('rep.filter.all_platforms')}</option>
                <option value="tripadvisor">TripAdvisor</option>
                <option value="google_business">Google Business</option>
            </select>
            <select id="filterMinRating" class="p-select">
                <option value="">كل التقييمات</option>
                <option value="5">⭐⭐⭐⭐⭐ فقط</option>
                <option value="4">4 نجوم فأكثر</option>
                <option value="3">3 نجوم فأكثر</option>
                <option value="2">2 نجوم فأكثر</option>
                <option value="1">1 نجمة فأكثر</option>
            </select>
            <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" id="filterUnreplied"> من غير رد بس</label>
            <input type="date" id="filterDateFrom" class="p-select" title="من تاريخ">
            <input type="date" id="filterDateTo" class="p-select" title="إلى تاريخ">
            <button class="p-btn outline xs" onclick="clearGbpReviewFilters()">مسح الفلاتر</button>
            <a href="/reputation/platforms" class="p-btn primary xs">{$this->tr('rep.connect_new_platform')}</a>
        </div>
        <div class="p-card no-pad">
            <div class="p-table-scroll"><table class="p-table" id="reviewsTable">
                <thead><tr><th>{$this->tr('rep.col.platform')}</th><th>{$this->tr('rep.col.rating')}</th><th>{$this->tr('rep.col.sentiment')}</th><th>{$this->tr('rep.col.review_text')}</th><th>{$this->tr('rep.col.reply')}</th><th>{$this->tr('rep.col.date')}</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="7">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
            <div id="reviewsPager" style="display:flex;gap:10px;align-items:center;justify-content:center;padding:12px;flex-wrap:wrap"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const sentimentPill = { positive: `<span class="pill green">😊 ${I18N['rep.sentiment.positive']}</span>`, neutral: `<span class="pill">😐 ${I18N['rep.sentiment.neutral']}</span>`, negative: `<span class="pill red">😞 ${I18N['rep.sentiment.negative']}</span>` };

    window.deleteReview = async function (id) {
        if (!confirm(I18N['rep.confirm_delete'])) return;
        const res = await fetchJSON('/api/reputation/review/' + id, { method: 'DELETE' });
        if (res.success) { toast(I18N['rep.deleted'], 'success'); load(); }
        else { toast(res.error || I18N['rep.delete_failed'], 'error'); }
    };

    window.reload = function () { load(); };
    window.clearGbpReviewFilters = function () {
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterSentiment').value = '';
        document.getElementById('filterPlatform').value = '';
        document.getElementById('filterMinRating').value = '';
        document.getElementById('filterDateFrom').value = '';
        document.getElementById('filterDateTo').value = '';
        document.getElementById('filterUnreplied').checked = false;
        load(1);
    };

    let gbpSearchDebounce = null;
    ['filterSentiment', 'filterPlatform', 'filterMinRating', 'filterDateFrom', 'filterDateTo', 'filterUnreplied'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => load(1));
    });
    document.getElementById('filterSearch').addEventListener('input', () => {
        clearTimeout(gbpSearchDebounce);
        gbpSearchDebounce = setTimeout(() => load(1), 400);
    });

    let currentPage = 1;
    window.reviewsGoTo = function (p) { load(p); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    function renderPager(pg) {
        const box = document.getElementById('reviewsPager');
        if (!pg || !pg.total) { box.innerHTML = ''; return; }
        const pages = Math.max(1, Math.ceil(pg.total / pg.limit));
        box.innerHTML = `<button class="p-btn outline xs" ${pg.page <= 1 ? 'disabled' : ''} onclick="reviewsGoTo(${pg.page - 1})">→ السابق</button>
            <span class="p-cell-muted" style="font-size:13px">صفحة ${pg.page} من ${pages} · ${pg.total} تقييم</span>
            <button class="p-btn outline xs" ${pg.page >= pages ? 'disabled' : ''} onclick="reviewsGoTo(${pg.page + 1})">التالي ←</button>`;
    }

    async function load(page) {
        if (typeof page === 'number') currentPage = page;
        const sentiment = document.getElementById('filterSentiment').value;
        const platform = document.getElementById('filterPlatform').value;
        const minRating = document.getElementById('filterMinRating').value;
        const dateFrom = document.getElementById('filterDateFrom').value;
        const dateTo = document.getElementById('filterDateTo').value;
        const search = document.getElementById('filterSearch').value.trim();
        const qs = new URLSearchParams();
        if (sentiment) qs.set('sentiment', sentiment);
        if (platform) qs.set('platform', platform);
        if (minRating) qs.set('min_rating', minRating);
        if (dateFrom) qs.set('date_from', dateFrom);
        if (dateTo) qs.set('date_to', dateTo);
        if (search) qs.set('search', search);
        if (document.getElementById('filterUnreplied').checked) qs.set('unreplied', '1');
        qs.set('page', currentPage);
        qs.set('limit', 25);

        const res = await fetchJSON('/api/reputation/reviews?' + qs.toString());
        const tbody = document.querySelector('#reviewsTable tbody');
        if (res.success && Array.isArray(res.data.reviews) && res.data.reviews.length) {
            tbody.innerHTML = res.data.reviews.map(r => `
                <tr>
                    <td>${esc(r.platform || '-')}</td>
                    <td>${'⭐'.repeat(Math.max(0, Math.min(5, parseInt(r.rating) || 0)))}</td>
                    <td>${sentimentPill[r.sentiment_label] || esc(r.sentiment_label || '-')}</td>
                    <td style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc((r.review_text || '').slice(0, 80))}</td>
                    <td>${r.reply_sent == 1 ? `<span class="pill green">✔ ${I18N['rep.reply.sent'].replace('✔ ', '')}</span>` : `<span class="pill">${I18N['rep.reply.none']}</span>`}</td>
                    <td class="p-cell-muted">${formatDate(r.review_at || r.created_at)}</td>
                    <td style="white-space:nowrap;">
                        <a href="/reputation/review/${r.id}" class="p-btn outline xs">${I18N['rep.open']}</a>
                        <button class="p-btn danger xs" onclick="deleteReview(${r.id})">${I18N['rep.delete']}</button>
                    </td>
                </tr>`).join('');
            renderPager(res.data.pagination);
        } else if (!res.success) {
            // كان الفشل بيظهر كأن مفيش تقييمات خالص
            tbody.innerHTML = `<tr><td colspan="7" class="p-cell-muted text-center">⚠️ ${esc(res.error || 'تعذر تحميل التقييمات')} - <a href="#" onclick="reload();return false;">حاول تاني</a></td></tr>`;
            renderPager(null);
        } else {
            tbody.innerHTML = `<tr><td colspan="7" class="p-cell-muted text-center">${I18N['rep.no_reviews_yet']}</td></tr>`;
            renderPager(null);
        }
    }
    load(1);
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', $this->tr('rep.reviews.page.title'), $this->tr('rep.reviews.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /reputation/review/{id} */
    public function showReview(array $params): array
    {
        $reviewId = (int) ($params['id'] ?? 0);

        $body = <<<HTML
        <div id="loadingReview" class="p-empty"><div class="p-empty-icon">⏳</div>{$this->tr('rep.review.loading')}</div>
        <div id="reviewBody" style="display:none;" class="p-grid cols-2"></div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const reviewId = __REVIEW_ID__;
    let current = null;

    const sentimentPill = { positive: `<span class="pill green">😊 ${I18N['rep.sentiment.positive']}</span>`, neutral: `<span class="pill">😐 ${I18N['rep.sentiment.neutral']}</span>`, negative: `<span class="pill red">😞 ${I18N['rep.sentiment.negative']}</span>` };

    window.generateAIReply = async function () {
        const btn = document.getElementById('genBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/reputation/review/' + reviewId + '/generate-reply', { method: 'POST' });
        btn.disabled = false;
        if (res.success) {
            document.getElementById('replyText').value = res.data.reply || '';
            toast(I18N['rep.review.reply_generated'], 'success');
        } else {
            toast(res.error || I18N['rep.review.reply_generation_failed'], 'error');
        }
    };

    window.sendReply = async function () {
        const text = document.getElementById('replyText').value.trim();
        if (!text) { toast(I18N['rep.review.write_first'], 'error'); return; }
        const btn = document.getElementById('sendBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/reputation/review/' + reviewId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reply: text }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(I18N['rep.review.reply_sent'], 'success');
            load();
        } else {
            toast(res.error || I18N['rep.review.reply_send_failed'], 'error');
        }
    };

    async function load() {
        const res = await fetchJSON('/api/reputation/review/' + reviewId);
        document.getElementById('loadingReview').style.display = 'none';
        const box = document.getElementById('reviewBody');
        box.style.display = 'grid';

        if (!res.success) {
            box.innerHTML = `<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>${esc(res.error || I18N['rep.review.load_failed'])}</div></div>`;
            return;
        }

        const r = res.data.review;
        current = r;

        box.innerHTML = `
            <div class="p-card">
                <div class="p-card-head"><h3>${esc(r.reviewer_name || I18N['rep.reviewer_default'])}</h3><span class="p-card-sub">${esc(r.platform || '-')} · ${formatDate(r.created_at)}</span></div>
                <div class="p-kv"><span class="k">${I18N['rep.review.rating_label']}</span><span class="v">${'⭐'.repeat(Math.max(0, Math.min(5, parseInt(r.rating) || 0)))}</span></div>
                <div class="p-kv"><span class="k">${I18N['rep.review.sentiment_label']}</span><span class="v">${sentimentPill[r.sentiment_label] || esc(r.sentiment_label || '-')}</span></div>
                <div class="p-kv"><span class="k">${I18N['rep.review.reply_status_label']}</span><span class="v">${r.reply_sent == 1 ? `<span class="pill green">✔ ${I18N['rep.reply.sent'].replace('✔ ', '')}</span>` : `<span class="pill">${I18N['rep.review.no_reply_yet']}</span>`}</span></div>
                <p style="margin-top:14px;line-height:1.8;background:var(--panel-bg,#f7f8fa);padding:14px;border-radius:10px;">${esc(r.review_text || '-')}</p>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>${I18N['rep.review.reply_section_title']}</h3></div>
                <div class="form-group">
                    <textarea id="replyText" class="form-control" rows="6" placeholder="${I18N['rep.review.reply_placeholder']}">${esc(r.auto_reply_generated || '')}</textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <button class="p-btn outline" id="genBtn" onclick="generateAIReply()">${I18N['rep.review.generate_ai_reply']}</button>
                    <button class="p-btn primary" id="sendBtn" onclick="sendReply()">${I18N['rep.review.send_reply']}</button>
                </div>
            </div>
        `;
    }
    load();
})();
JS;
        $script = str_replace('__REVIEW_ID__', (string) $reviewId, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', $this->tr('rep.review.title_prefix') . $reviewId, $this->tr('rep.review.subtitle'), $body, $script);
        exit;
    }

    public function getReview(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "SELECT reviews.*,
                        source_platform AS platform,
                        external_review_id AS platform_review_id,
                        sentiment AS sentiment_label,
                        ai_generated_reply AS auto_reply_generated,
                        (reply_sent_at IS NOT NULL) AS reply_sent
                     FROM reviews WHERE id = ? AND user_id = ? LIMIT 1";
            $result = $this->db->query($sql, [(int) ($params['id'] ?? 0), $this->user['id']]);

            if (empty($result)) {
                return $this->error('المراجعة غير موجودة', 404);
            }

            return $this->success(['review' => $result[0]]);
        } catch (Exception $e) {
            Logger::error('Get Review Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المراجعة', 500);
        }
    }

    /** GET /reputation/stats */
    public function showStats(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-grid cols-4" id="statCards">
            <div class="p-card stat-tile"><div class="stat-icon blue">⭐</div><div class="stat-info"><div class="stat-value" id="stTotal">0</div><div class="stat-label">{$this->tr('rep.stats.total')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">😊</div><div class="stat-info"><div class="stat-value" id="stPositive">0</div><div class="stat-label">{$this->tr('rep.stats.positive')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">😐</div><div class="stat-info"><div class="stat-value" id="stNeutral">0</div><div class="stat-label">{$this->tr('rep.stats.neutral')}</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">😞</div><div class="stat-info"><div class="stat-value" id="stNegative">0</div><div class="stat-label">{$this->tr('rep.stats.negative')}</div></div></div>
        </div>
        <div class="p-grid cols-2" style="margin-top:18px;">
            <div class="p-card">
                <div class="p-card-head"><h3>{$this->tr('rep.stats.avg_rating')}</h3></div>
                <div style="font-size:42px;font-weight:700;text-align:center;padding:20px 0;" id="stAvgRating">-</div>
            </div>
            <div class="p-card no-pad">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('rep.stats.by_platform')}</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="platformStatsTable">
                    <thead><tr><th>{$this->tr('rep.col.platform')}</th><th>{$this->tr('rep.stats.col.count')}</th><th>{$this->tr('rep.col.rating')}</th></tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
                </table></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;

    async function load() {
        const res = await fetchJSON('/api/reputation/stats');
        if (!res.success) { toast(res.error || 'تعذر تحميل الإحصائيات - حدّث الصفحة', 'error'); return; }

        const s = res.data.stats || {};
        document.getElementById('stTotal').textContent = s.total || 0;
        document.getElementById('stPositive').textContent = s.positive || 0;
        document.getElementById('stNeutral').textContent = s.neutral || 0;
        document.getElementById('stNegative').textContent = s.negative || 0;
        document.getElementById('stAvgRating').textContent = s.avg_rating ? (s.avg_rating + ' ⭐') : '-';

        const tbody = document.querySelector('#platformStatsTable tbody');
        const platforms = res.data.platforms || [];
        if (platforms.length) {
            tbody.innerHTML = platforms.map(p => `
                <tr><td>${esc(p.platform || '-')}</td><td>${esc(p.count || 0)}</td><td>${p.avg_rating ? esc(Math.round(p.avg_rating * 10) / 10) + ' ⭐' : '-'}</td></tr>
            `).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="3" class="p-cell-muted text-center">${I18N['crm.no_data']}</td></tr>`;
        }
    }
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', $this->tr('rep.stats.page.title'), $this->tr('rep.stats.page.subtitle'), $body, $script);
        exit;
    }

    /** GET /reputation/platforms */
    public function showPlatforms(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>Google Business Profile</h3><span class="p-card-sub">{$this->tr('rep.platforms.gbp.sub')}</span></div>
            <div id="googleConnections"><div class="p-loading-row">{$this->tr('common.loading')}</div></div>
        </div>
        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>TripAdvisor</h3><span class="p-card-sub">{$this->tr('rep.platforms.ta.sub')}</span></div>
            <div id="tripadvisorConnections"><div class="p-loading-row">{$this->tr('common.loading')}</div></div>
        </div>
        <div class="p-card no-pad" style="margin-top:18px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>{$this->tr('rep.platforms.current_data')}</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="platformsTable">
                <thead><tr><th>{$this->tr('rep.col.platform')}</th><th>{$this->tr('crm.col.review_count')}</th><th>{$this->tr('rep.col.rating')}</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3">{$this->tr('common.loading')}</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    window.disconnectGoogle = async function (websiteId) {
        if (!confirm(I18N['rep.platforms.confirm_disconnect_google'])) return;
        const res = await fetchJSON('/api/reputation/disconnect/google/' + websiteId, { method: 'POST' });
        if (res.success) { toast(I18N['rep.platforms.disconnected'], 'success'); loadConnections(); }
        else { toast(res.error || I18N['rep.platforms.disconnect_failed'], 'error'); }
    };

    window.disconnectTripAdvisor = async function (websiteId) {
        if (!confirm(I18N['rep.platforms.confirm_disconnect'])) return;
        const res = await fetchJSON('/api/reputation/disconnect/tripadvisor/' + websiteId, { method: 'POST' });
        if (res.success) { toast(I18N['rep.platforms.disconnected'], 'success'); loadConnections(); }
        else { toast(res.error || I18N['rep.platforms.disconnect_failed'], 'error'); }
    };

    async function loadConnections() {
        const box = document.getElementById('googleConnections');
        const taBox = document.getElementById('tripadvisorConnections');
        const websitesRes = await fetchJSON('/api/websites');
        const websites = (websitesRes.success && Array.isArray(websitesRes.data.websites)) ? websitesRes.data.websites : [];

        if (!websites.length) {
            const emptyMsg = `<div class="p-empty"><div class="p-empty-icon">🌐</div>${I18N['rep.platforms.add_site_first']} <a href="/websites">${I18N['rep.platforms.add_site_cta']}</a></div>`;
            box.innerHTML = emptyMsg;
            taBox.innerHTML = emptyMsg;
            return;
        }

        box.innerHTML = websites.map(w => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong>${esc(w.company_name || w.main_url || (I18N['rep.platforms.site_prefix'] + w.id))}</strong><br>
                    <span class="p-cell-muted" id="gstatus-${w.id}">${I18N['rep.platforms.checking']}</span>
                </div>
                <div id="gaction-${w.id}"></div>
            </div>`).join('');

        taBox.innerHTML = websites.map(w => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong>${esc(w.company_name || w.main_url || (I18N['rep.platforms.site_prefix'] + w.id))}</strong><br>
                    <span class="p-cell-muted" id="tastatus-${w.id}">${I18N['rep.platforms.checking']}</span>
                </div>
                <div id="taaction-${w.id}"></div>
            </div>`).join('');

        websites.forEach(async (w) => {
            const res = await fetchJSON('/api/reputation/platforms?website_id=' + w.id);
            if (!res.success) return;

            const gConnected = res.data.google_connected;
            document.getElementById('gstatus-' + w.id).innerHTML = gConnected
                ? `<span class="pill green">${I18N['rep.platforms.connected']}</span> ` + esc(res.data.google_location_name || '')
                : `<span class="pill">${I18N['rep.platforms.not_connected']}</span>`;
            document.getElementById('gaction-' + w.id).innerHTML = gConnected
                ? `<button class="p-btn outline xs" onclick="disconnectGoogle(${w.id})">${I18N['rep.platforms.disconnect']}</button>`
                : `<a href="/reputation/connect/google/${w.id}" class="p-btn primary xs">${I18N['rep.platforms.connect_account']}</a>`;

            const taConnected = res.data.tripadvisor_connected;
            document.getElementById('tastatus-' + w.id).innerHTML = taConnected
                ? `<span class="pill green">${I18N['rep.platforms.connected']}</span> ` + esc(res.data.tripadvisor_location_name || '')
                : `<span class="pill">${I18N['rep.platforms.not_connected']}</span>`;
            document.getElementById('taaction-' + w.id).innerHTML = taConnected
                ? `<button class="p-btn outline xs" onclick="disconnectTripAdvisor(${w.id})">${I18N['rep.platforms.disconnect']}</button>`
                : `<a href="/reputation/connect/tripadvisor/${w.id}" class="p-btn primary xs">${I18N['rep.platforms.connect_account']}</a>`;
        });
    }

    async function load() {
        const res = await fetchJSON('/api/reputation/platforms');
        const tbody = document.querySelector('#platformsTable tbody');
        if (res.success && Array.isArray(res.data.platforms) && res.data.platforms.length) {
            tbody.innerHTML = res.data.platforms.map(p => `
                <tr><td>${esc(p.platform || '-')}</td><td>${esc(p.count || 0)}</td><td>${p.avg_rating ? esc(Math.round(p.avg_rating * 10) / 10) + ' ⭐' : '-'}</td></tr>
            `).join('');
        } else {
            tbody.innerHTML = `<tr><td colspan="3" class="p-cell-muted text-center">${I18N['crm.no_data']}</td></tr>`;
        }
    }
    loadConnections();
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', $this->tr('rep.platforms.page.title'), $this->tr('rep.platforms.page.subtitle'), $body, $script);
        exit;
    }

    public function getPlatforms(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $response = ['platforms' => $this->getAllPlatformsStats()];

            $websiteId = $this->get('website_id');
            if ($websiteId) {
                $connections = (new PlatformConnection())->where([
                    'website_id' => (int) $websiteId,
                    'platform' => 'google_business',
                    'status' => 'connected',
                ], [], 1);

                $response['google_connected'] = !empty($connections);
                $response['google_location_name'] = !empty($connections) ? $connections[0]->getAttribute('external_location_name') : null;
                $response['google_connection_id'] = !empty($connections) ? (int) $connections[0]->getAttribute('id') : null;

                $taConnections = (new PlatformConnection())->where([
                    'website_id' => (int) $websiteId,
                    'platform' => 'tripadvisor',
                    'status' => 'connected',
                ], [], 1);

                $response['tripadvisor_connected'] = !empty($taConnections);
                $response['tripadvisor_location_name'] = !empty($taConnections) ? $taConnections[0]->getAttribute('external_location_name') : null;
            }

            return $this->success($response);
        } catch (Exception $e) {
            Logger::error('Get Platforms Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG)
                ? 'تعذر جلب بيانات المنصات: ' . $e->getMessage()
                : 'تعذر جلب بيانات المنصات';
            return $this->error($debugMsg, 500);
        }
    }

    /**
     * GET /api/reputation/google/profile-completeness - درجة اكتمال بروفايل
     * Google Business (0-100) بناءً على بيانات حقيقية من getLocation().
     * @since 2026-08-15 (صلح مسار كان مسجّل من غير الميثود فعليًا)
     */
    public function getProfileCompleteness(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        try {
            $connections = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'google_business',
                'status' => 'connected',
            ], [], 1);

            if (empty($connections)) {
                return $this->error('الموقع مش مربوط بـ Google Business', 404);
            }

            $connection = $connections[0];

            try {
                $syncService = new GoogleReviewSyncService();
                $accessToken = $syncService->getValidAccessToken($connection);
            } catch (Exception $e) {
                return $this->error(CustomerError::message($e->getMessage(), 500), 502);
            }

            $api = new GoogleBusinessAPI(
                $accessToken,
                $connection->getAttribute('external_account_id'),
                $connection->getAttribute('external_location_id')
            );

            $locationResult = $api->getLocation();
            if (!$locationResult['success']) {
                return $this->error($locationResult['error'] ?? 'فشل جلب بيانات الموقع', 502);
            }

            $score = (new GbpProfileScoreService())->calculateCompletenessScore($locationResult['location'] ?? []);

            return $this->success([
                'score' => $score['score'],
                'max_score' => $score['max_score'],
                'missing' => $score['missing'],
                'complete' => $score['complete'],
                'percentage' => round(($score['score'] / $score['max_score']) * 100, 1),
            ]);
        } catch (Exception $e) {
            Logger::error('Get Profile Completeness Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حساب درجة اكتمال البروفايل', 500);
        }
    }

    /** POST /api/reputation/review/{id}/reply */
    public function sendReply(array $params): array
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        if (!$this->validate(['reply' => 'required'])) {
            return $this->error('الرد مطلوب', 422, $this->getErrors());
        }

        $reviewId = (int) ($params['id'] ?? 0);
        $replyText = $this->get('reply');

        try {
            $reviewRows = $this->db->query(
                "SELECT * FROM reviews WHERE id = ? AND user_id = ? LIMIT 1",
                [$reviewId, $this->user['id']]
            );

            if (empty($reviewRows)) {
                return $this->error('المراجعة غير موجودة', 404);
            }

            $review = $reviewRows[0];
            $platform = $review['source_platform'] ?? null;
            $platformReviewId = $review['external_review_id'] ?? null;

            // تصحيح: الكود القديم كان بيعلّم الرد "تم الإرسال" في قاعدة
            // بياناتنا بس، من غير ما يبعته فعليًا لـ Google/TripAdvisor -
            // يعني العميل يفتكر إن رده وصل للعميل الحقيقي وهو ماوصلش.
            // دلوقتي بنحاول الإرسال الفعلي حسب المنصة.
$creditsCheck = $this->subscription->reserveReviewCredits((int) $this->user['id'], 1);
            if (empty($creditsCheck['available'])) return $this->error($creditsCheck['message'] ?? 'رصيدك غير كافٍ',402);
            if ($platform === 'google_business' && $platformReviewId) {
                $sendResult = $this->sendGoogleReply((int) $review['website_id'], $platformReviewId, $replyText);
                if (!$sendResult['success']) {
                    return $this->error('تعذر إرسال الرد فعليًا لـ Google: ' . ($sendResult['error'] ?? ''), 500);
                }
            } elseif ($platform === 'tripadvisor') {
                // TripAdvisor مفيهاش endpoint رد برمجي - نرجع رسالة واضحة
                // بدل ما نكذب إن الرد اتبعت، ونسيب العميل ينسخه يدويًا.
                $this->db->exec(
                    "UPDATE reviews SET ai_generated_reply = ?, reply_status = 'pending' WHERE id = ? AND user_id = ?",
                    [$replyText, $reviewId, $this->user['id']]
                );
                return $this->error('TripAdvisor مبيسمحش بالرد البرمجي. الرد اتحفظ - انسخه وحطه يدويًا على صفحة المراجعة في TripAdvisor.', 422, [
                    'manual_action_required' => true,
                    'reply_text' => $replyText,
                ]);
            }

            $sql = "UPDATE reviews SET ai_generated_reply = ?, reply_sent_at = NOW(), reply_status = 'sent',
                    reply_approved_by = ? WHERE id = ? AND user_id = ?";
            $this->db->exec($sql, [$replyText, $this->user['id'], $reviewId, $this->user['id']]);

            // مركز الأتمتة: الرد اللي العميل بعته بإيده (أو عدّله) بيتحفظ كمثال
            // لأسلوبه، ولو كان فيه مسودة مستنية موافقة بتتحسب اتوافق عليها.
            if (class_exists('AutomationEngine')) {
                AutomationEngine::instance()->recordManualAction(
                    (int) $this->user['id'],
                    'review_replies',
                    AutomationCatalog::reviewBucket($review['rating'] ?? 0),
                    'review',
                    (string) $reviewId,
                    (string) $replyText,
                    (string) ($review['review_text'] ?? ''),
                    (string) ($review['ai_generated_reply'] ?? '')
                );
            }

            // تصحيح (2026-08-25): كان الرد بيتبعت فعليًا لكن رصيد الردود
            // (المعروض في الباقات 10/50/200) مكنش بيتخصم خالص. دلوقتي
            // بنستهلك رصيدًا بعد الإرسال الناجح، مع الرجوع للمحفظة لو
            // الاستخدام "ادفع حسب الاستخدام".
            
            $viaWallet = $creditsCheck['source'] === 'wallet';
            if (!$this->subscription->consumeReviewCredits((int) $this->user['id'], 1, $viaWallet)) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            return $this->success([], 'تم إرسال الرد');
        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Send Reply Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إرسال الرد', 500);
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    private function sendGoogleReply(int $websiteId, string $platformReviewId, string $replyText): array
    {
        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_business',
            'status' => 'connected',
        ], [], 1);

        if (empty($connections)) {
            return ['success' => false, 'error' => 'الموقع مش مربوط بـ Google Business'];
        }

        $connection = $connections[0];

        try {
            $syncService = new GoogleReviewSyncService();
            $accessToken = $syncService->getValidAccessToken($connection);
        } catch (Exception $e) {
            return ['success' => false, 'error' => CustomerError::message($e->getMessage(), 500)];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        return $api->sendReply($platformReviewId, $replyText);
    }

    /** DELETE /api/reputation/review/{id} */
    public function deleteReview(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "DELETE FROM reviews WHERE id = ? AND user_id = ?";
            $this->db->exec($sql, [(int) ($params['id'] ?? 0), $this->user['id']]);
            return $this->success([], 'تم حذف المراجعة');
        } catch (Exception $e) {
            Logger::error('Delete Review Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حذف المراجعة', 500);
        }
    }

    /**
     * ربط منصات المراجعات الخارجية (TripAdvisor / Google Business).
     * ملاحظة: لا يوجد تكامل OAuth فعلي مكتمل هنا رغم وجود المفاتيح في .env،
     * لذا نُعيد استجابة صريحة بدل التظاهر بربط ناجح.
     */
    /** GET /reputation/connect/tripadvisor/{website_id} - صفحة البحث عن الموقع */
    public function connectTripAdvisor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/reputation/platforms'));
            exit;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            $this->renderOAuthError('الموقع غير موجود أو ملكش صلاحية عليه');
            exit;
        }

        $tripAdvisor = new TripAdvisorAPI();
        if (!$tripAdvisor->isConfigured()) {
            $this->renderOAuthError('ربط TripAdvisor لسه مش مفعّل من إدارة النظام (TRIPADVISOR_API_KEY ناقص). راجع docs/TRIPADVISOR_SETUP.md');
            exit;
        }

        $companyName = htmlspecialchars((string) $website->getAttribute('company_name'), ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>ابحث عن نشاطك على TripAdvisor</h3><span class="p-card-sub">اكتب اسم الشركة زي ما هو مكتوب في TripAdvisor بالظبط</span></div>
            <div class="form-group">
                <input type="text" id="taQuery" class="form-control" value="{$companyName}" placeholder="اسم الفندق/المطعم/النشاط">
            </div>
            <button class="p-btn primary" onclick="searchTA()">🔍 بحث</button>
            <div id="taResults" style="margin-top:16px;"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const websiteId = __WEBSITE_ID__;

    window.searchTA = async function () {
        const q = document.getElementById('taQuery').value.trim();
        if (!q) return;
        const box = document.getElementById('taResults');
        box.innerHTML = '<div class="p-loading-row">جارِ البحث...</div>';

        const res = await fetchJSON('/api/reputation/tripadvisor/search?q=' + encodeURIComponent(q));
        if (!res.success) {
            const message = res.error || res.message || 'تعذر البحث في TripAdvisor';
            box.innerHTML = `<div class="p-cell-muted" style="color:var(--danger,#c33)">${esc(message)}</div>`;
            return;
        }
        const locations = res.data?.locations || [];
        if (!locations.length) {
            const message = res.data?.message || 'مفيش نتائج. جرّب الاسم الأقصر أو اكتب الاسم كما يظهر في TripAdvisor.';
            box.innerHTML = `<div class="p-cell-muted">${esc(message)}</div>`;
            return;
        }

        box.innerHTML = locations.map(loc => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                <div><strong>${esc(loc.name)}</strong><br><span class="p-cell-muted">${esc(loc.address || '')}</span></div>
                <button class="p-btn primary xs" onclick="selectTA('${loc.location_id}', '${esc(loc.name).replace(/'/g, "\\'")}')">اختيار</button>
            </div>`).join('');
    };

    window.selectTA = async function (locationId, name) {
        const res = await fetchJSON('/api/reputation/connect/tripadvisor', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, location_id: locationId, location_name: name }),
        });
        if (res.success) {
            toast('تم ربط TripAdvisor بنجاح ✔', 'success');
            window.location.href = '/reputation/platforms';
        } else {
            toast(res.error || 'تعذر الربط', 'error');
        }
    };
})();
JS;
        $script = str_replace('__WEBSITE_ID__', (string) $websiteId, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', 'ربط TripAdvisor', 'ابحث عن نشاطك التجاري', $body, $script);
        exit;
    }

    /** GET /api/reputation/tripadvisor/search?q= */
    public function searchTripAdvisor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $query = trim((string) $this->get('q', ''));
        if ($query === '') {
            return $this->error('اكتب اسم للبحث عنه', 422);
        }

        $result = (new TripAdvisorAPI())->searchLocations($query);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر البحث', 500);
        }

        return $this->success(['locations' => $result['locations']]);
    }

    /** POST /api/reputation/connect/tripadvisor */
    public function finalizeTripAdvisorConnection(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $locationId = $this->get('location_id');
        $locationName = $this->get('location_name');

        if (!$websiteId || !$locationId) {
            return $this->error('الموقع ومعرف TripAdvisor مطلوبين', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        try {
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'tripadvisor',
            ], [], 1);

            $data = [
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => 'tripadvisor',
                'external_location_id' => $locationId,
                'external_location_name' => $locationName,
                'status' => 'connected',
                'last_error' => null,
            ];

            $connection = new PlatformConnection($data);
            if (!empty($existing)) {
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log('TripAdvisor Connected', ['website_id' => $websiteId, 'location_id' => $locationId]);

            return $this->success([], 'تم ربط TripAdvisor بنجاح');
        } catch (Exception $e) {
            Logger::error('Finalize TripAdvisor Connection Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** POST /api/reputation/disconnect/tripadvisor/{website_id} */
    public function disconnectTripAdvisor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);

        try {
            $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'tripadvisor']);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('Disconnect TripAdvisor Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر فصل الربط', 500);
        }
    }

    // ============================================
    // ربط Google Business Profile عن طريق OAuth حقيقي
    // ============================================
    // كل عميل بيربط حسابه هو بنفسه (مش حساب واحد ثابت للموقع كله).
    // ملحوظة: ده محتاج مشروع Google Cloud + موافقة Google على الوصول
    // لـ Business Profile API قبل ما يشتغل فعليًا - شوف .env.example.

    /** GET /reputation/connect/google/{website_id} - يبدأ تدفّق OAuth */
    public function connectGoogleBusiness(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/reputation/platforms'));
            exit;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            $this->renderOAuthError('الموقع غير موجود أو ملكش صلاحية عليه');
            exit;
        }

        $oauth = new GoogleOAuthClient();
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('ربط Google Business لسه مش مفعّل من إدارة النظام (بيانات OAuth ناقصة في إعدادات السيرفر). راجع docs/GOOGLE_BUSINESS_SETUP.md');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_nonce'] = $nonce;
        $_SESSION['google_oauth_website_id'] = $websiteId;
        // الرجوع للصفحة اللي بدأ منها الربط (مثلاً مركز الأتمتة) - مسارات داخلية بس
        $ret = (string) ($_GET['return'] ?? '');
        $_SESSION['google_oauth_return'] = in_array($ret, ['/automation', '/reputation/platforms', '/gbp-content'], true) ? $ret : '/reputation/platforms';

        $state = OAuthState::issue(['aud' => 'google_business', 'nonce' => $nonce, 'website_id' => $websiteId], (int) ($this->user['id'] ?? 0));

        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /reputation/connect/google/callback - Google بيرجّع العميل هنا */
    public function googleOAuthCallback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['google_oauth_nonce'] ?? null, 'google_business');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $websiteId = (int) ($decodedState['website_id'] ?? 0);

        $oauth = new GoogleOAuthClient();
        $tokenResult = $oauth->exchangeCodeForTokens($code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('فشل تبادل التوكن مع Google: ' . htmlspecialchars((string) ($tokenResult['error'] ?? ''), ENT_QUOTES, 'UTF-8'));
            exit;
        }

        // تصحيح: شاشة Google بتسمح للعميل يشيل علامة الصلاحية. من غيرها كل طلب بعد كده
        // بيرجع 403 ويظهر كأن "مفيش حسابات". بنوقف هنا برسالة واضحة.
        $granted = (string) ($tokenResult['scope'] ?? '');
        if ($granted !== '' && strpos($granted, 'business.manage') === false) {
            $this->renderOAuthError('Google ما اداش صلاحية إدارة النشاط التجاري.<br><br>اضغط «ربط» تاني، وفي شاشة Google <b>علّم على</b> صلاحية «See, edit, create and delete your Google business listings» قبل ما تضغط Continue.');
            exit;
        }

        // بنخزن التوكنات مؤقتًا في الجلسة لحد ما العميل يختار الفرع
        // (location) بتاعه، بعدين بس بنحفظهم فعليًا في قاعدة البيانات.
        $_SESSION['google_oauth_temp'] = [
            'issued_at' => time(),
            'website_id' => $websiteId,
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'] ?? null,
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['google_oauth_nonce']);

        header('Location: /reputation/connect/google/choose');
        exit;
    }

    /** GET /reputation/connect/google/choose - يختار العميل حسابه/فرعه */
    public function showGoogleLocationPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['google_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /reputation/platforms');
            exit;
        }

        if (!empty($temp['issued_at']) && time() >= (int) $temp['issued_at'] + (int) ($temp['expires_in'] ?? 0) - 30) {
            unset($_SESSION['google_oauth_temp']);
            $this->renderOAuthError('انتهت مهلة اختيار الحساب. ابدأ ربط الحساب مرة أخرى.');
            exit;
        }
        $api = new GoogleBusinessAPI($temp['access_token']);
        $accountsResult = $api->listAccounts();

        if (!$accountsResult['success']) {
            Logger::warning('Google account discovery failed', ['user_id' => (int) ($this->user['id'] ?? 0), 'http_code' => $accountsResult['http_code'] ?? null, 'status' => $accountsResult['google_status'] ?? null]);
            $this->renderOAuthError('تعذر قراءة حسابات Google Business. أعد ربط الحساب ثم حاول مرة أخرى.');
            exit;
        }
        if (empty($accountsResult['accounts'])) {
            $this->renderOAuthError('مفيش حسابات Google Business مرتبطة بحساب Google ده. تأكد إنك اخترت في شاشة Google نفس الحساب اللي بيدير صفحة النشاط التجاري (المالك أو المدير).');
            exit;
        }

        // نجمع كل الفروع تحت كل الحسابات في قائمة واحدة للاختيار
        $options = [];
        foreach ($accountsResult['accounts'] as $account) {
            $locationsResult = $api->listLocations($account['id']);
            if (!$locationsResult['success']) {
                Logger::warning('Google location discovery failed', ['user_id' => (int) ($this->user['id'] ?? 0)]);
                $this->renderOAuthError('تعذر تحميل الفروع الآن. أعد ربط الحساب أو حاول مرة أخرى لاحقًا.');
                exit;
            }
            if ($locationsResult['success']) {
                foreach ($locationsResult['locations'] as $loc) {
                    $options[] = [
                        'account_id' => $account['id'],
                        'account_name' => $account['name'],
                        'location_id' => $loc['id'],
                        'location_name' => $loc['name'],
                        'address' => $loc['address'] ?? '',
                    ];
                }
            }
        }

        if (empty($options)) {
            $this->renderOAuthError('الحساب موجود بس مفيش أي فرع (location) متاح للربط.');
            exit;
        }

        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $body = <<<'HTML'
        <div class="p-card">
            <div class="p-card-head"><h3>اختر فرعك على Google Business</h3><span class="p-card-sub">لقينا أكتر من فرع مرتبط بحسابك</span></div>
            <div id="locationOptions"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const options = __OPTIONS_JSON__;

    document.getElementById('locationOptions').innerHTML = options.map((o, i) => `
        <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <strong>${esc(o.location_name)}</strong><br>
                <span class="p-cell-muted">${esc(o.address || o.account_name)}</span>
            </div>
            <button class="p-btn primary" onclick="selectLocation(${i})">اختيار</button>
        </div>`).join('');

    window.selectLocation = async function (i) {
        const o = options[i];
        const res = await fetchJSON('/api/reputation/connect/google/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ account_id: o.account_id, location_id: o.location_id, location_name: o.location_name }),
        });
        if (res.success) {
            toast('تم ربط Google Business بنجاح ✔', 'success');
            window.location.href = __RETURN_URL__;
        } else {
            toast(res.error || 'تعذر إتمام الربط', 'error');
        }
    };
})();
JS;
        $script = str_replace('__OPTIONS_JSON__', $optionsJson, $script);
        $script = str_replace('__RETURN_URL__', json_encode($_SESSION['google_oauth_return'] ?? '/reputation/platforms'), $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', 'اختيار الفرع', 'Google Business Profile', $body, $script);
        exit;
    }

    /** POST /api/reputation/connect/google/finalize */
    public function finalizeGoogleConnection(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $temp = $_SESSION['google_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت جلسة الربط، جرّب تاني', 422);
        }

        $accountId = $this->get('account_id');
        $locationId = $this->get('location_id');
        $locationName = $this->get('location_name');

        if (!$accountId || !$locationId) {
            return $this->error('اختيار الفرع مطلوب', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $temp['website_id'],
                'platform' => 'google_business',
            ], [], 1);

            $data = [
                'website_id' => $temp['website_id'],
                'user_id' => $this->user['id'],
                'platform' => 'google_business',
                'access_token' => $encryption->encrypt($temp['access_token']),
                'refresh_token' => $temp['refresh_token'] ? $encryption->encrypt($temp['refresh_token']) : null,
                'token_expires_at' => date('Y-m-d H:i:s', (int) ($temp['issued_at'] ?? time()) + (int) $temp['expires_in']),
                'external_account_id' => $accountId,
                'external_location_id' => $locationId,
                'external_location_name' => $locationName,
                'status' => 'connected',
                'last_error' => null,
            ];

            $connection = new PlatformConnection($data);
            if (!empty($existing)) {
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
                $connection->save();
            } else {
                $connection->save();
            }

            unset($_SESSION['google_oauth_temp'], $_SESSION['google_oauth_website_id']);

            $this->log('Google Business Connected', ['website_id' => $temp['website_id'], 'location_id' => $locationId]);
            // GBP Module Upgrade (2026-08-09): مفيش أي Event كان بيتبعت هنا
            // قبل كده رغم إن سبيك الموديول بيطلبه صراحة (GBPConnected).
            if (function_exists('event')) {
                event('GBPConnected', [
                    'website_id' => (int) $temp['website_id'],
                    'user_id' => (int) $this->user['id'],
                    'location_id' => $locationId,
                    'location_name' => $locationName,
                ]);
            }
            // GBP Module Upgrade (Round 5): مزامنة فورية في الخلفية بعد
            // الربط مباشرة (Background Sync حقيقي عن طريق نظام الطابور) -
            // الـ request بتاع المستخدم مبيستناش عملية المزامنة الثقيلة.
            if (function_exists('enqueue')) {
                enqueue('GbpBackgroundSyncJob', ['website_id' => (int) $temp['website_id'], 'user_id' => (int) $this->user['id']], 'gbp_sync');
            }
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('connect', (int) $temp['website_id'], (int) $this->user['id'], 'success', ['location_id' => $locationId]);
            }

            return $this->success([], 'تم ربط Google Business بنجاح');
        } catch (Exception $e) {
            Logger::error('Finalize Google Connection Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** POST /api/reputation/disconnect/google/{website_id} */
    public function disconnectGoogleBusiness(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);

        try {
            $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'google_business']);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            // GBP Module Upgrade (2026-08-09): GBPDisconnected event مطلوب صراحة بالسبيك
            if (function_exists('event')) {
                event('GBPDisconnected', ['website_id' => $websiteId, 'user_id' => (int) $this->user['id']]);
            }
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('disconnect', $websiteId, (int) $this->user['id'], 'success');
            }
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('Disconnect Google Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر فصل الربط', 500);
        }
    }

    private function renderOAuthError(string $message): void
    {
        $message = htmlspecialchars(CustomerError::oauthMessage($message), ENT_QUOTES, 'UTF-8');
        $websiteId = (int) ($_SESSION['google_oauth_website_id'] ?? $_SESSION['google_oauth_temp']['website_id'] ?? 0);
        $retry = $websiteId > 0 ? '<a href="/reputation/connect/google/' . $websiteId . '" class="p-btn primary">إعادة ربط الحساب</a><br><br>' : '';
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $message . '<br><br>' . $retry . '<a href="/reputation/platforms" class="p-btn primary">الرجوع لمنصات المراجعات</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', 'تعذر الربط', 'Google Business Profile', $body, '');
    }

    // ============================================
    // نظرة عامة على السمعة (Reputation Overview)
    // ============================================

    /** GET /reputation/overview */
    public function showOverview(array $params = []): array
    {
        $body = <<<'HTML'
        <div class="p-grid cols-4" id="repoKpis">
            <div class="p-card stat-tile"><div class="stat-icon blue">⭐</div><div class="stat-info"><div class="stat-value" id="kpiTotal">0</div><div class="stat-label">إجمالي المراجعات (30 يوم)</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">📈</div><div class="stat-info"><div class="stat-value" id="kpiAvg">-</div><div class="stat-label">متوسط التقييم</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">⚠️</div><div class="stat-info"><div class="stat-value" id="kpiNegative">0</div><div class="stat-label">مراجعات سلبية</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">✔</div><div class="stat-info"><div class="stat-value" id="kpiPending">0</div><div class="stat-label">ردود مسودة بانتظار اعتمادك</div></div></div>
        </div>

        <div class="p-card" style="margin-top:18px;">
            <div class="p-card-head"><h3>اتجاه التقييم آخر 8 أسابيع (كل منصة)</h3></div>
            <div style="padding:10px 4px;"><canvas id="repoTrendChart" height="90"></canvas></div>
        </div>

        <div class="p-grid cols-2" style="margin-top:18px;align-items:start;">
            <div>
                <div class="p-toolbar">
                    <div class="p-tabs" id="repoFilterTabs">
                        <button class="p-tab active" data-filter="all">كل المراجعات</button>
                        <button class="p-tab" data-filter="negative">سلبية فقط</button>
                        <button class="p-tab" data-filter="tripadvisor">TripAdvisor</button>
                        <button class="p-tab" data-filter="google_business">Google Business</button>
                        <button class="p-tab" data-filter="booking">Booking.com</button>
                    </div>
                </div>
                <div id="repoFeed" style="display:flex;flex-direction:column;gap:10px;">
                    <div class="p-loading-row">جارِ التحميل...</div>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:16px;">
                <div class="p-card">
                    <div class="p-card-head"><h3>⚠️ تنبيهات نشطة</h3></div>
                    <div id="repoAlerts" style="display:flex;flex-direction:column;gap:8px;">
                        <div class="p-loading-row">جارِ التحميل...</div>
                    </div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>📈 اقتراحات تحسين</h3><span class="p-card-sub">مبنية على الموضوعات الأكثر تكرارًا في المراجعات السلبية</span></div>
                    <div id="repoImprovements" style="display:flex;flex-direction:column;gap:12px;">
                        <div class="p-loading-row">جارِ التحميل...</div>
                    </div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>🏷 أهم الموضوعات المذكورة</h3><span class="p-card-sub">استخراج ديناميكي من نصوص المراجعات (عربي/إنجليزي)</span></div>
                    <div id="repoTopics" style="display:flex;flex-direction:column;gap:10px;">
                        <div class="p-loading-row">جارِ التحميل...</div>
                    </div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>📤 تصدير البيانات</h3></div>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <a class="p-btn outline" href="/api/reputation/export-reviews">⬇ تصدير المراجعات CSV</a>
                        <span class="p-card-sub">تتضمن مؤشرات مختصرة (العدد/المتوسط/المشاعر) أعلى الملف</span>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    let trendChart = null;
    let currentFilter = 'all';
    let allReviews = [];

    const PLATFORM_LABEL = { tripadvisor: 'TripAdvisor', google_business: 'Google Business', booking: 'Booking.com', expedia: 'Expedia', trustpilot: 'Trustpilot', other: 'أخرى' };
    const sentimentPill = { positive: '<span class="pill green">😊 إيجابي</span>', neutral: '<span class="pill">😐 محايد</span>', negative: '<span class="pill red">😞 سلبي</span>' };
    const replyStatusLabel = { pending: 'بانتظار الاعتماد', approved: 'اتعتمد', sent: 'اتبعت فعليًا', rejected: 'اتجوهل' };

    function starsHtml(rating) {
        const full = Math.max(0, Math.min(5, Math.round(parseFloat(rating) || 0)));
        return '⭐'.repeat(full) || '—';
    }

    window.repoApprove = async function (id, text) {
        const res = await fetchJSON('/api/reputation/review/' + id + '/reply', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ reply: text }) });
        if (res.success) { toast('تم اعتماد الرد وإرساله', 'success'); load(); }
        else { toast(res.error || 'تعذر إرسال الرد', 'error'); }
    };

    window.repoEditToggle = function (id) {
        const box = document.getElementById('replyEdit-' + id);
        if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
    };

    window.repoSaveEdit = async function (id) {
        const textarea = document.getElementById('replyText-' + id);
        const res = await fetchJSON('/api/reputation/review/' + id + '/reply', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ reply: textarea.value }) });
        if (res.success) { toast('تم حفظ التعديل', 'success'); load(); }
        else { toast(res.error || 'تعذر الحفظ', 'error'); }
    };

    window.repoDismiss = async function (id) {
        const res = await fetchJSON('/api/reputation/review/' + id + '/dismiss', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { toast('تم تجاهل هذا الرد', 'success'); load(); }
        else { toast(res.error || 'تعذر التنفيذ', 'error'); }
    };

    document.querySelectorAll('#repoFilterTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#repoFilterTabs .p-tab').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            renderFeed();
        });
    });

    function renderFeed() {
        const feed = document.getElementById('repoFeed');
        let list = allReviews;
        if (currentFilter === 'negative') list = list.filter(r => r.sentiment_label === 'negative');
        else if (currentFilter !== 'all') list = list.filter(r => r.platform === currentFilter);

        if (!list.length) {
            feed.innerHTML = '<div class="p-empty"><div class="p-empty-icon">⭐</div>لا يوجد مراجعات مطابقة</div>';
            return;
        }

        feed.innerHTML = list.map(r => {
            const status = r.reply_status || 'pending';
            const hasDraft = !!r.auto_reply_generated;
            let replyBox = '';
            if (hasDraft) {
                const draftEsc = esc(r.auto_reply_generated);
                replyBox = `
                    <div style="background:var(--panel-bg,#f7f8fa);border-radius:10px;padding:12px;margin-top:10px;">
                        <div style="display:flex;justify-content:space-between;font-size:11.5px;font-weight:600;margin-bottom:6px;">
                            <span>✨ رد مولّد بالذكاء الاصطناعي</span>
                            <span class="p-cell-muted">${replyStatusLabel[status] || status}</span>
                        </div>
                        <p style="font-size:12.5px;margin:0 0 10px;">${draftEsc}</p>
                        <div id="replyEdit-${r.id}" style="display:none;margin-bottom:10px;">
                            <textarea id="replyText-${r.id}" class="p-input" rows="3" style="width:100%;">${draftEsc}</textarea>
                        </div>
                        ${status === 'pending' ? `
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="p-btn primary xs" data-reply="${draftEsc}" onclick="repoApprove(${Number(r.id)}, document.getElementById('replyEdit-${r.id}').style.display !== 'none' ? document.getElementById('replyText-${r.id}').value : this.dataset.reply)">✔ اعتماد وإرسال</button>
                            <button class="p-btn outline xs" onclick="repoEditToggle(${r.id})">✏ تعديل</button>
                            <button class="p-btn outline xs" onclick="repoSaveEdit(${r.id})">💾 حفظ التعديل</button>
                            <button class="p-btn danger xs" onclick="repoDismiss(${r.id})">✕ تجاهل</button>
                        </div>` : ''}
                    </div>`;
            }
            return `
                <div class="p-card" style="padding:16px;">
                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="p-cell-muted">${esc(PLATFORM_LABEL[r.platform] || r.platform)}</span>
                            <strong>${esc(r.reviewer_name || 'مستخدم')}</strong>
                            <span>${starsHtml(r.rating)}</span>
                        </div>
                        ${sentimentPill[r.sentiment_label] || ''}
                    </div>
                    <p style="font-size:13.5px;margin:6px 0 4px;">${esc((r.review_text || '').slice(0, 220))}</p>
                    <span class="p-cell-muted" style="font-size:11px;">${formatDate(r.created_at)}</span>
                    ${replyBox}
                </div>`;
        }).join('');
    }

    function renderAlerts(list) {
        const box = document.getElementById('repoAlerts');
        const negatives = list.filter(r => r.sentiment_label === 'negative').sort((a, b) => (parseFloat(a.rating) || 0) - (parseFloat(b.rating) || 0)).slice(0, 6);
        if (!negatives.length) {
            box.innerHTML = '<div class="p-cell-muted">مفيش تنبيهات دلوقتي 👍</div>';
            return;
        }
        box.innerHTML = negatives.map(a => `
            <div style="border-right:3px solid #E2A03F;padding-right:10px;">
                <div class="p-cell-muted" style="font-size:11px;margin-bottom:2px;">${esc(PLATFORM_LABEL[a.platform] || a.platform)} · ${starsHtml(a.rating)}</div>
                <div style="font-size:12.5px;">${esc((a.review_text || '').slice(0, 90))}…</div>
            </div>`).join('');
    }

    function renderImprovements(list) {
        // G4: الاقتراحات بقت ديناميكية من السيرفر (ReviewTopicExtractor)
        // بدل الكلمات المفتاحية الثابتة القديمة في JS.
        const box = document.getElementById('repoImprovements');
        if (!list.length) {
            box.innerHTML = '<div class="p-cell-muted">مفيش أنماط واضحة في المراجعات السلبية الحالية</div>';
            return;
        }
        const priorityLabel = { high: 'عالي', medium: 'متوسط', low: 'منخفض' };
        box.innerHTML = list.map(t => `
            <div>
                <div style="display:flex;justify-content:space-between;margin-bottom:3px;">
                    <span style="font-weight:600;font-size:12.5px;">${esc(t.label)}</span>
                    <span class="p-cell-muted" style="font-size:10.5px;">أولوية ${priorityLabel[t.priority] || 'منخفض'} · ${t.count} مراجعة</span>
                </div>
            </div>`).join('');
    }

    function renderTopics(list) {
        const box = document.getElementById('repoTopics');
        if (!list.length) {
            box.innerHTML = '<div class="p-cell-muted">مفيش مراجعات فيها موضوعات واضحة حاليًا</div>';
            return;
        }
        const max = list[0].count || 1;
        box.innerHTML = list.map(t => `
            <div>
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">
                    <span style="font-weight:600;">${esc(t.label)}</span>
                    <span class="p-cell-muted">${t.count} · ${t.avg_rating} ⭐ · ${t.share_percent}%</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <div style="flex:1;height:6px;background:var(--panel-bg,#f0f1f4);border-radius:3px;overflow:hidden;">
                        <div style="width:${Math.max(4, Math.round((t.count / max) * 100))}%;height:100%;background:#5B9BD5;border-radius:3px;"></div>
                    </div>
                    <span class="pill ${t.negative > 0 ? 'red' : 'green'}" style="font-size:10px;">😞 ${t.negative}</span>
                </div>
            </div>`).join('');
    }

    function renderTrendChart(trend) {
        const ctx = document.getElementById('repoTrendChart');
        if (!ctx || typeof Chart === 'undefined') return;
        if (trendChart) trendChart.destroy();
        const platforms = Object.keys(PLATFORM_LABEL);
        const colors = { tripadvisor: '#3FA796', google_business: '#5B9BD5', booking: '#E2A03F', expedia: '#9A8CF5', trustpilot: '#8891A0', other: '#4A5261' };
        const datasets = platforms
            .filter(p => trend.some(w => w[p] !== null && w[p] !== undefined))
            .map(p => ({ label: PLATFORM_LABEL[p], data: trend.map(w => w[p]), borderColor: colors[p], backgroundColor: 'transparent', tension: 0.3, spanGaps: true }));
        trendChart = new Chart(ctx, {
            type: 'line',
            data: { labels: trend.map(w => w.week), datasets },
            options: { responsive: true, scales: { y: { min: 0, max: 5 } } }
        });
    }

    async function load() {
        const res = await fetchJSON('/api/reputation/overview-data');
        if (!res.success) { toast(res.error || 'تعذر تحميل البيانات', 'error'); return; }

        const k = res.data.kpis || {};
        document.getElementById('kpiTotal').textContent = k.total_reviews || 0;
        document.getElementById('kpiAvg').textContent = k.avg_rating ? (k.avg_rating + ' ⭐') : '-';
        document.getElementById('kpiNegative').textContent = k.negative || 0;
        document.getElementById('kpiPending').textContent = k.pending_reply || 0;

        allReviews = res.data.reviews || [];
        renderFeed();
        renderAlerts(allReviews);
        renderImprovements(res.data.improvements || []);
        renderTopics(res.data.topics || []);
        renderTrendChart(res.data.trend || []);
    }

    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation_overview', 'نظرة عامة على السمعة', 'كل حاجة عن مراجعاتك في صفحة واحدة: مؤشرات، اتجاه، تنبيهات، واقتراحات', $body, $script);
        exit;
    }

    /** GET /api/reputation/overview-data */
    public function getOverviewData(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $userId = (int) $this->user['id'];
            $kpis = $this->reputationManager->getReputationStats($userId);

            $reviews = $this->db->query(
                "SELECT id, source_platform AS platform, reviewer_name, review_text, rating,
                        sentiment AS sentiment_label,
                        ai_generated_reply AS auto_reply_generated,
                        (reply_sent_at IS NOT NULL) AS reply_sent,
                        reply_status, COALESCE(review_date, created_at) AS created_at
                 FROM reviews
                 WHERE user_id = ?
                 ORDER BY COALESCE(review_date, created_at) DESC
                 LIMIT 60",
                [$userId]
            );

            $trendRows = $this->db->query(
                "SELECT YEARWEEK(COALESCE(review_date, created_at), 3) as yw,
                        MIN(DATE(COALESCE(review_date, created_at))) as week_start,
                        AVG(CASE WHEN source_platform = 'tripadvisor' THEN rating END) as tripadvisor,
                        AVG(CASE WHEN source_platform = 'google_business' THEN rating END) as google_business,
                        AVG(CASE WHEN source_platform = 'booking' THEN rating END) as booking,
                        AVG(CASE WHEN source_platform = 'expedia' THEN rating END) as expedia,
                        AVG(CASE WHEN source_platform = 'trustpilot' THEN rating END) as trustpilot,
                        AVG(CASE WHEN source_platform = 'other' THEN rating END) as other
                 FROM reviews
                 WHERE user_id = ? AND COALESCE(review_date, created_at) > DATE_SUB(NOW(), INTERVAL 8 WEEK)
                 GROUP BY yw
                 ORDER BY yw ASC",
                [$userId]
            );

            $trend = array_map(function ($row) {
                $out = ['week' => date('d/m', strtotime($row['week_start']))];
                foreach (['tripadvisor', 'google_business', 'booking', 'expedia', 'trustpilot', 'other'] as $p) {
                    $out[$p] = $row[$p] !== null ? round((float) $row[$p], 2) : null;
                }
                return $out;
            }, $trendRows);

            // G4 (Topic Extraction): تحليل ديناميكي لموضوعات المراجعات عبر
            // ReviewTopicExtractor (ثنائي اللغة، بلا LLM) - كان سابقًا كلمات
            // مفتاحية ثابتة في الواجهة وتقتصر على المراجعات السلبية.
            $topicExtractor = new ReviewTopicExtractor();
            $topics = $topicExtractor->extractTopics($reviews);
            $improvements = $topicExtractor->topTopicsForNegative($reviews);

            return $this->success([
                'kpis' => $kpis,
                'reviews' => $reviews,
                'trend' => $trend,
                'topics' => $topics,
                'improvements' => $improvements,
            ]);
        } catch (Exception $e) {
            Logger::error('Reputation Overview Data Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل بيانات النظرة العامة', 500);
        }
    }

    /**
     * GET /api/reputation/export-reviews
     * تصدير المراجعات + مؤشرات مختصرة (G5) - نفس نمط exportCsv في
     * ReviewRequestController: فلاتر اختيارية (website_id/platform/
     * sentiment/min_rating/date_from/date_to/search) مع تحقق الملكية.
     * لا يُصدَّر بريد/هاتف المراجع (حقول مشفرة) حفاظًا على الخصوصية.
     */
    public function exportReviewsCsv(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            http_response_code(401);
            exit;
        }

        $websiteId = (int) $this->get('website_id', 0);
        if ($websiteId > 0) {
            $website = (new Website())->find($websiteId);
            if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
                http_response_code(403);
                exit;
            }
        }

        try {
            $sql = "SELECT source_platform AS platform, reviewer_name, review_text, rating,
                           sentiment, review_date, reply_status, reply_sent_at, created_at
                    FROM reviews WHERE user_id = ?";
            $params = [$this->user['id']];

            if ($websiteId > 0) {
                $sql .= " AND website_id = ?";
                $params[] = $websiteId;
            }

            $sentiment = $this->get('sentiment');
            if ($sentiment) {
                $sql .= " AND sentiment = ?";
                $params[] = $sentiment;
            }

            $platform = $this->get('platform');
            if ($platform) {
                $sql .= " AND source_platform = ?";
                $params[] = $platform;
            }

            $minRating = $this->get('min_rating');
            if ($minRating !== null && $minRating !== '') {
                $sql .= " AND rating >= ?";
                $params[] = (int) $minRating;
            }

            $dateFrom = $this->get('date_from');
            if ($dateFrom) {
                $sql .= " AND created_at >= ?";
                $params[] = $dateFrom . ' 00:00:00';
            }

            $dateTo = $this->get('date_to');
            if ($dateTo) {
                $sql .= " AND created_at <= ?";
                $params[] = $dateTo . ' 23:59:59';
            }

            $search = trim((string) $this->get('search', ''));
            if ($search !== '') {
                $sql .= " AND (review_text LIKE ? OR reviewer_name LIKE ?)";
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }

            $sql .= " ORDER BY created_at DESC LIMIT 5000";
            $rows = $this->db->query($sql, $params);

            // مؤشرات مختصرة في أعلى الملف
            $total = count($rows);
            $ratingSum = 0;
            $sentimentCounts = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
            foreach ($rows as $r) {
                $ratingSum += (float) ($r['rating'] ?? 0);
                $s = (string) ($r['sentiment'] ?? '');
                if (isset($sentimentCounts[$s])) {
                    $sentimentCounts[$s]++;
                }
            }
            $avg = $total > 0 ? round($ratingSum / $total, 2) : 0;

            $output = fopen('php://temp', 'r+');
            fputcsv($output, ['Tourfecto Reputation Export', date('Y-m-d H:i')]);
            fputcsv($output, ['Total', 'Avg Rating', 'Positive', 'Neutral', 'Negative', 'Mixed']);
            fputcsv($output, [$total, $avg, $sentimentCounts['positive'], $sentimentCounts['neutral'], $sentimentCounts['negative'], $sentimentCounts['mixed']]);
            fputcsv($output, []);
            fputcsv($output, ['#', 'Platform', 'Reviewer', 'Rating', 'Sentiment', 'Review Text', 'Review Date', 'Reply Status', 'Reply Sent At', 'Created At']);

            foreach ($rows as $i => $r) {
                fputcsv($output, [
                    $i + 1,
                    $r['platform'] ?? 'other',
                    $r['reviewer_name'] ?? '',
                    $r['rating'] ?? '',
                    $r['sentiment'] ?? '',
                    $r['review_text'] ?? '',
                    $r['review_date'] ?? '',
                    $r['reply_status'] ?? '',
                    $r['reply_sent_at'] ?? '',
                    $r['created_at'] ?? '',
                ]);
            }

            rewind($output);
            $csv = "\xEF\xBB\xBF" . stream_get_contents($output);
            fclose($output);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="tourfecto-reputation-reviews-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportReviewsCsv Error', ['message' => $e->getMessage()]);
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
            echo 'تعذر التصدير';
            exit;
        }
    }

    /** POST /api/reputation/review/{id}/dismiss */
    public function dismissReply(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $reviewId = (int) ($params['id'] ?? 0);

        try {
            $rows = $this->db->query(
                "SELECT id FROM reviews WHERE id = ? AND user_id = ? LIMIT 1",
                [$reviewId, $this->user['id']]
            );
            if (empty($rows)) {
                return $this->error('المراجعة غير موجودة', 404);
            }

            $this->db->exec(
                "UPDATE reviews SET reply_status = 'rejected', updated_at = NOW() WHERE id = ? AND user_id = ?",
                [$reviewId, $this->user['id']]
            );
            if (class_exists('AutomationEngine')) {
                AutomationEngine::instance()->recordManualRejection((int) $this->user['id'], 'review_replies', 'review', (string) $reviewId);
            }

            return $this->success([], 'تم تجاهل الرد');
        } catch (Exception $e) {
            Logger::error('Dismiss Reply Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ الإجراء', 500);
        }
    }

    /** 🔒 الموقع ده بتاع المستخدم الحالي (أو صاحب الحساب لو هو عضو في فريقه)؟ */
    private function canAccessWebsite(int $websiteId): bool
    {
        if ($websiteId <= 0 || empty($this->user['id'])) {
            return false;
        }
        $ids = [(int) $this->user['id']];
        if (!empty($this->user['owner_user_id'])) {
            $ids[] = (int) $this->user['owner_user_id'];
        }
        $rows = $this->db->query(
            'SELECT id FROM websites WHERE id = ? AND user_id IN (' . implode(',', $ids) . ') LIMIT 1',
            [$websiteId]
        );
        return !empty($rows);
    }
}
