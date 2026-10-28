<?php

/**
 * Tourfecto - SEO Content Engine Service (Phase 24)
 * @version 1.0.0
 *
 * محرك محتوى SEO تلقائي بيحوّل الفرص (كلمات مفتاحية / استعلامات GSC) إلى
 * مقالات مولّدة ومفهرسة ومختبَرة A/B مع قياس CTR - حلقة مغلقة بتكمل محرك
 * الـ SEO التنفيذي (المراحل 21-23):
 *
 *   اكتشاف مواضيع -> توليد مقالات (ArticleGenerator) -> حفظ في ai_articles
 *   -> فهرسة فورية (IndexNow) -> تجربة A/B على العنوان -> قياس CTR من GSC.
 *
 * الجداول: seo_content_campaigns (الحملة) + seo_content_items (العناصر).
 */
class SeoContentService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * اكتشاف مواضيع/كلمات مفتاحية لموقع معيّن.
     * @param string $source 'keywords' (كلمات متابَعة) | 'gsc' (استعلامات GSC) | 'manual'
     * @return array قائمة ['topic'=>, 'keyword'=>, 'opportunity_score'=>, 'priority'=>]
     */
    public function discoverTopics(int $websiteId, string $source = 'keywords', int $limit = 20): array
    {
        $topics = [];

        if ($source === 'gsc') {
            $topics = $this->discoverFromGsc($websiteId, $limit);
        }

        // لو GSC مفيهاش بيانات (أو المصدر keywords)، نستخدم الكلمات المتابَعة
        if (empty($topics)) {
            try {
                $rows = $this->db->query(
                    "SELECT keyword, current_position, search_volume, difficulty,
                            opportunity_score, priority
                       FROM tracked_keywords
                      WHERE website_id = ? AND keyword IS NOT NULL AND keyword <> ''
                      ORDER BY (priority = 'high') DESC,
                               (priority = 'medium') DESC,
                               COALESCE(opportunity_score, 0) DESC,
                               COALESCE(search_volume, 0) DESC
                      LIMIT ?",
                    [$websiteId, $limit]
                );
            } catch (Exception $e) {
                // جدول tracked_keywords مش موجود في قاعدة قديمة/ناقصة -
                // نرجّع قائمة فاضية بهدوء بدل ما نوقع الـ endpoint بالكامل.
                Logger::warning('SeoContentService discoverTopics: tracked_keywords unavailable', [
                    'website_id' => $websiteId,
                    'message' => $e->getMessage(),
                ]);
                $rows = [];
            }
            foreach ($rows as $r) {
                $topics[] = [
                    'topic' => (string) $r['keyword'],
                    'keyword' => (string) $r['keyword'],
                    'opportunity_score' => (int) ($r['opportunity_score'] ?? 0),
                    'priority' => (string) ($r['priority'] ?? 'medium'),
                    'current_position' => $r['current_position'] !== null ? (int) $r['current_position'] : null,
                    'search_volume' => $r['search_volume'] !== null ? (int) $r['search_volume'] : null,
                ];
            }
        }

        return $topics;
    }

    /** استعلامات بحث حقيقية من GSC (best-effort) لو الموقع مربوط */
    private function discoverFromGsc(int $websiteId, int $limit): array
    {
        $topics = [];
        try {
            $conn = $this->getGscConnection($websiteId);
            if (!$conn) {
                return $topics;
            }

            $accessToken = $this->decryptToken($conn['access_token']);
            if ($accessToken === '') {
                return $topics;
            }

            $api = new GoogleSearchConsoleAPI($accessToken);
            $endDate = date('Y-m-d', strtotime('-2 days'));
            $startDate = date('Y-m-d', strtotime('-28 days', strtotime($endDate)));
            $res = $api->getSearchAnalytics((string) $conn['site_url'], $startDate, $endDate, ['query'], $limit);

            if (!$res['success']) {
                return $topics;
            }

            foreach ($res['rows'] ?? [] as $row) {
                $query = trim((string) ($row['query'] ?? ''));
                if ($query === '') {
                    continue;
                }
                // نستبعد الاستعلامات اللي الموقع ترتيبه فيها كويس أصلًا (أقل فرصة)
                $topics[] = [
                    'topic' => $query,
                    'keyword' => $query,
                    'opportunity_score' => 60,
                    'priority' => 'medium',
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                ];
            }
        } catch (Exception $e) {
            Logger::error('SeoContentService discoverFromGsc error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
        }
        return $topics;
    }

    /** إنشاء حملة محتوى جديدة مع عناصرها (موضوع لكل عنصر) */
    public function createCampaign(int $userId, int $websiteId, string $name, array $topics, string $source = 'manual'): array
    {
        if ($name === '' || empty($topics)) {
            return ['success' => false, 'error' => 'اسم الحملة وموضوع واحد على الأقل مطلوبان'];
        }

        $campaignId = $this->db->query(
            "INSERT INTO seo_content_campaigns
                (user_id, website_id, name, topic_source, status, total_items, generated_items, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'draft', ?, 0, NOW(), NOW())",
            [$userId, $websiteId, $name, $source, count($topics)]
        );

        $itemIds = [];
        foreach ($topics as $t) {
            if (is_string($t)) {
                $t = ['topic' => $t];
            }
            $topic = trim((string) ($t['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $itemIds[] = (int) $this->db->query(
                "INSERT INTO seo_content_items
                    (campaign_id, topic, keyword, status, created_at, updated_at)
                 VALUES (?, ?, ?, 'queued', NOW(), NOW())",
                [$campaignId, $topic, ($t['keyword'] ?? null) ?: null]
            );
        }

        return ['success' => true, 'campaign_id' => (int) $campaignId, 'item_count' => count($itemIds)];
    }

    /**
     * توليد مقال واحد لعنصر معيّن (باستخدام ArticleGenerator) وحفظه في ai_articles.
     * @return array ['success'=>bool, 'article_id'=>?, 'title'=>?, 'error'=>?]
     */
    public function generateItem(int $itemId): array
    {
        $item = $this->getItem($itemId);
        if (!$item) {
            return ['success' => false, 'error' => 'العنصر غير موجود'];
        }

        $campaign = $this->getCampaign((int) $item['campaign_id']);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }

        $websiteId = (int) $campaign['website_id'];
        $companyName = null;
        $websiteUrl = null;
        $existingPages = [];

        $website = (new Website())->find($websiteId);
        if ($website) {
            $companyName = $website->getAttribute('company_name');
            $websiteUrl = $website->getAttribute('main_url');
            $prev = $this->db->query(
                "SELECT title, slug FROM ai_articles WHERE website_id = ? AND status IN ('completed','published') ORDER BY id DESC LIMIT 15",
                [$websiteId]
            );
            foreach ($prev as $p) {
                $existingPages[] = ['title' => $p['title'], 'path' => '/blog/' . $p['slug']];
            }
        }

        try {
            $targetLanguage = 'ar';
            $campaignLang = $campaign['target_language'] ?? null;
            if ($campaignLang && is_string($campaignLang)) {
                $targetLanguage = $campaignLang;
            } else {
                $siteLangs = $this->db->query(
                    "SELECT target_languages FROM websites WHERE id = ? LIMIT 1",
                    [$websiteId]
                );
                if (!empty($siteLangs[0]['target_languages'])) {
                    $decoded = json_decode((string) $siteLangs[0]['target_languages'], true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $first = is_array($decoded[0]) ? ($decoded[0]['code'] ?? 'ar') : $decoded[0];
                        $targetLanguage = strtolower((string) $first);
                    }
                }
            }

            $generator = new ArticleGenerator();
            $result = $generator->generate((string) $item['topic'], $targetLanguage, 'professional', $companyName, $websiteUrl, $existingPages);

            if (!$result['success']) {
                $this->markItemFailed($itemId, $result['error'] ?? 'فشل التوليد');
                return ['success' => false, 'error' => $result['error'] ?? 'فشل التوليد'];
            }

            $data = $result['data'];

            $article = new AIArticle([
                'user_id' => (int) $campaign['user_id'],
                'website_id' => $websiteId,
                'topic' => (string) $item['topic'],
                'target_language' => $targetLanguage,
                'tone' => 'professional',
                'title' => $data['title'],
                'meta_description' => $data['meta_description'],
                'slug' => $data['slug'],
                'content' => $data['content'],
                'suggested_keywords' => json_encode($data['suggested_keywords'] ?? [], JSON_UNESCAPED_UNICODE),
                'word_count' => $data['word_count'],
                'status' => 'completed',
            ]);
            $articleId = $article->save();

            $this->db->exec(
                "UPDATE seo_content_items SET article_id = ?, title = ?, slug = ?, status = 'generated', error_message = NULL, updated_at = NOW() WHERE id = ?",
                [$articleId, $data['title'], $data['slug'], $itemId]
            );

            $this->touchCampaignCounters((int) $item['campaign_id']);

            return ['success' => true, 'article_id' => (int) $articleId, 'title' => (string) $data['title']];
        } catch (Exception $e) {
            Logger::error('SeoContentService generateItem error', ['item_id' => $itemId, 'message' => $e->getMessage()]);
            $this->markItemFailed($itemId, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** توليد كل العناصر اللي لسه queued في حملة (متزامن - للـ batches الصغيرة) */
    public function generateCampaign(int $campaignId): array
    {
        $items = $this->db->query(
            "SELECT id FROM seo_content_items WHERE campaign_id = ? AND status = 'queued' ORDER BY id ASC",
            [$campaignId]
        );

        $ok = 0;
        $fail = 0;
        foreach ($items as $it) {
            $res = $this->generateItem((int) $it['id']);
            $res['success'] ? $ok++ : $fail++;
        }

        // لو خلّصنا كل العناصر بنجاح، نعلّم الحملة ready
        $this->touchCampaignCounters($campaignId);

        return ['success' => true, 'generated' => $ok, 'failed' => $fail];
    }

    /** فهرسة مقال العنصر فورًا عبر IndexNow */
    public function indexItem(int $itemId): array
    {
        $item = $this->getItem($itemId);
        if (!$item) {
            return ['success' => false, 'error' => 'العنصر غير موجود'];
        }

        $campaign = $this->getCampaign((int) $item['campaign_id']);
        $website = $campaign ? (new Website())->find((int) $campaign['website_id']) : null;
        if (!$website) {
            return ['success' => false, 'error' => 'الموقع غير موجود'];
        }

        $indexNowKey = (string) $website->getAttribute('indexnow_key');
        $mainUrl = (string) $website->getAttribute('main_url');
        if ($indexNowKey === '' || $mainUrl === '') {
            return ['success' => false, 'error' => 'الموقع مش مفعّل ليه IndexNow (ولّد مفتاح الأول)'];
        }

        $slug = (string) ($item['slug'] ?? '');
        $host = parse_url($mainUrl, PHP_URL_HOST);
        if (!$host) {
            return ['success' => false, 'error' => 'main_url غير صالح'];
        }

        $url = rtrim($mainUrl, '/') . '/blog/' . $slug;
        $service = new IndexNowService();
        $result = $service->submitUrls($host, $indexNowKey, [$url]);

        $code = (int) ($result['status'] ?? 0);
        $this->db->exec(
            "UPDATE seo_content_items SET published_url = ?, indexnow_code = ?, status = ?, updated_at = NOW() WHERE id = ?",
            [$url, $code, $result['success'] ? 'indexed' : 'generated', $itemId]
        );

        return $result;
    }

    /**
     * إنشاء تجربة A/B على عنوان المقال (control = العنوان الحالي).
     * @param string|null $variantTitle عنوان بديل؛ لو null بيتولّد من الكلمة/العنوان
     */
    public function createTitleAbTest(int $itemId, ?string $variantTitle = null): array
    {
        $item = $this->getItem($itemId);
        if (!$item || empty($item['title'])) {
            return ['success' => false, 'error' => 'العنصر مش مولّد بعد'];
        }

        $campaign = $this->getCampaign((int) $item['campaign_id']);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }

        $variantTitle = trim((string) $variantTitle);
        if ($variantTitle === '') {
            $variantTitle = $this->deriveVariantTitle((string) $item['title'], (string) ($item['keyword'] ?? $item['topic']));
        }

        $slug = (string) ($item['slug'] ?? '');
        $service = new SeoAbTestService($this->db);
        $created = $service->createTest(
            (int) $campaign['user_id'],
            (int) $campaign['website_id'],
            'محتوى: ' . $slug,
            'seo_title',
            $slug !== '' ? ('/blog/' . $slug) : null
        );
        if (empty($created['success'])) {
            return ['success' => false, 'error' => $created['error'] ?? 'فشل إنشاء التجربة'];
        }

        $testId = (int) $created['test_id'];
        $service->addVariant($testId, 'control', (string) $item['title'], true, 50);
        $service->addVariant($testId, 'variant', $variantTitle, false, 50);
        $service->startTest($testId);

        $this->db->exec(
            "UPDATE seo_content_items SET ab_test_id = ?, status = 'testing', updated_at = NOW() WHERE id = ?",
            [$testId, $itemId]
        );

        return ['success' => true, 'test_id' => $testId, 'variant_title' => $variantTitle];
    }

    /** عنوان بديل خفيف من غير استدعاء LLM إضافي (نفس الكلمة + Hook) */
    private function deriveVariantTitle(string $title, string $keyword): string
    {
        $kw = trim($keyword);
        if ($kw !== '' && mb_stripos($title, $kw) === false) {
            return $title . ' | ' . $kw;
        }
        return $title . ' (دليل شامل)';
    }

    /** مقاييس CTR مجمّعة لحملة (من كاش GSC لكل صفحة) */
    public function campaignStats(int $campaignId): array
    {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }

        $items = $this->db->query(
            "SELECT id, topic, title, slug, status, ab_test_id, indexnow_code
               FROM seo_content_items WHERE campaign_id = ? ORDER BY id ASC",
            [$campaignId]
        );

        $metrics = (new SeoPerformanceService($this->db))->getCachedPageMetrics((int) $campaign['website_id']);

        $totalClicks = 0;
        $totalImpressions = 0;
        $rows = [];
        foreach ($items as $it) {
            $path = '/blog/' . ($it['slug'] ?? '');
            $m = $metrics[$path] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
            $totalClicks += (int) $m['clicks'];
            $totalImpressions += (int) $m['impressions'];
            $rows[] = [
                'id' => (int) $it['id'],
                'topic' => (string) $it['topic'],
                'title' => (string) $it['title'],
                'slug' => (string) $it['slug'],
                'status' => (string) $it['status'],
                'ab_test_id' => $it['ab_test_id'] !== null ? (int) $it['ab_test_id'] : null,
                'indexnow_code' => $it['indexnow_code'] !== null ? (int) $it['indexnow_code'] : null,
                'clicks' => (int) $m['clicks'],
                'impressions' => (int) $m['impressions'],
                'ctr' => (float) $m['ctr'],
                'avg_position' => (float) $m['position'],
            ];
        }

        return [
            'success' => true,
            'campaign' => $campaign,
            'items' => $rows,
            'totals' => [
                'clicks' => $totalClicks,
                'impressions' => $totalImpressions,
                'ctr' => $totalImpressions > 0 ? round(($totalClicks / $totalImpressions) * 100, 2) : 0,
            ],
        ];
    }

    /** قائمة الحملات لموقع */
    public function listCampaigns(int $websiteId): array
    {
        return $this->db->query(
            "SELECT * FROM seo_content_campaigns WHERE website_id = ? ORDER BY id DESC",
            [$websiteId]
        );
    }

    /** قائمة عناصر حملة */
    public function listItems(int $campaignId): array
    {
        return $this->db->query(
            "SELECT * FROM seo_content_items WHERE campaign_id = ? ORDER BY id ASC",
            [$campaignId]
        );
    }

    // ==================== Automatic loop (cron) ====================

    /** عناصر مستحقة التوليد (status='queued') لحد $limit */
    public function pendingGenerationItems(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT i.id, i.campaign_id, i.topic
               FROM seo_content_items i
               JOIN seo_content_campaigns c ON c.id = i.campaign_id
              WHERE i.status = 'queued' AND c.status IN ('draft','generating','ready')
              ORDER BY i.id ASC LIMIT ?",
            [$limit]
        );
    }

    /** عناصر مستحقة الفهرسة (status='generated') لحد $limit */
    public function pendingIndexItems(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT i.id
               FROM seo_content_items i
               JOIN seo_content_campaigns c ON c.id = i.campaign_id
              WHERE i.status = 'generated' AND c.status IN ('generating','ready')
              ORDER BY i.id ASC LIMIT ?",
            [$limit]
        );
    }

    /** عناصر مستحقة تجربة A/B (status='indexed' من غير تجربة) لحد $limit */
    public function pendingAbTestItems(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT i.id
               FROM seo_content_items i
               JOIN seo_content_campaigns c ON c.id = i.campaign_id
              WHERE i.status = 'indexed' AND i.ab_test_id IS NULL
              ORDER BY i.id ASC LIMIT ?",
            [$limit]
        );
    }

    /** عناصر تجاربها A/B اكتملت وفيه فائز - مستحقة تطبيق العنوان الفائز */
    public function pendingWinnerApplyItems(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT i.id, i.ab_test_id
               FROM seo_content_items i
               JOIN seo_ab_tests t ON t.id = i.ab_test_id
              WHERE t.status = 'completed' AND t.winner_variant_id IS NOT NULL
                AND i.status IN ('testing','indexed','generated')
              ORDER BY i.id ASC LIMIT ?",
            [$limit]
        );
    }

    /**
     * تطبيق العنوان الفائز من تجربة A/B المكتملة على المقال والعنصر.
     * بيقفل حلقة القياس: تجربة -> فائز -> عنوان منشور فعليًا.
     */
    public function applyWinningTitleToItem(int $itemId): array
    {
        $item = $this->getItem($itemId);
        if (!$item || empty($item['ab_test_id'])) {
            return ['success' => false, 'error' => 'العنصر غير موجود أو ليس له تجربة A/B'];
        }

        $testId = (int) $item['ab_test_id'];
        $tests = $this->db->query(
            "SELECT winner_variant_id FROM seo_ab_tests WHERE id = ? AND status = 'completed' AND winner_variant_id IS NOT NULL LIMIT 1",
            [$testId]
        );
        if (empty($tests)) {
            return ['success' => false, 'error' => 'التجربة غير مكتملة أو لا يوجد فائز'];
        }

        $winnerVariantId = (int) $tests[0]['winner_variant_id'];
        $variants = $this->db->query(
            "SELECT value FROM seo_ab_variants WHERE id = ? LIMIT 1",
            [$winnerVariantId]
        );
        if (empty($variants)) {
            return ['success' => false, 'error' => 'النسخة الفائزة غير موجودة'];
        }

        $winningTitle = trim((string) $variants[0]['value']);
        if ($winningTitle === '') {
            return ['success' => false, 'error' => 'العنوان الفائز فارغ'];
        }

        // تحديث المقال في ai_articles (لو لسه موجود)
        if (!empty($item['article_id'])) {
            $this->db->exec(
                "UPDATE ai_articles SET title = ? WHERE id = ?",
                [$winningTitle, (int) $item['article_id']]
            );
        }

        // تحديث العنصر ونقله لحالة "published" (العنوان النهائي مطبّق)
        $this->db->exec(
            "UPDATE seo_content_items SET title = ?, status = 'published', updated_at = NOW() WHERE id = ?",
            [$winningTitle, $itemId]
        );

        // لو كل عناصر الحملة اتنشرت، نعلّم الحملة completed
        $this->touchCampaignCounters((int) $item['campaign_id']);

        return [
            'success' => true,
            'item_id' => $itemId,
            'test_id' => $testId,
            'winner_title' => $winningTitle,
        ];
    }

    /**
     * تنفيذ دورة واحدة من محرك المحتوى التلقائي (نفس منطق الكرون).
     * بيكررها أي حد: cron/seo_content_engine.php أو زر "تشغيل" في اللوحة.
     * @return array ['campaigns_enqueued'=>int, 'indexed'=>int, 'ab_created'=>int, 'winner_applied'=>int]
     */
    public function runEngineCycle(
        int $generateLimit = 20,
        int $indexLimit = 20,
        int $abLimit = 20,
        int $winnerLimit = 20
    ): array {
        $summary = [
            'campaigns_enqueued' => 0,
            'indexed' => 0,
            'ab_created' => 0,
            'winner_applied' => 0,
        ];

        // 1) توليد: جدولة حملة لكل عنصر queued (خلفي عبر الطابور)
        $pendingCampaigns = [];
        foreach ($this->pendingGenerationItems($generateLimit) as $item) {
            $pendingCampaigns[(int) $item['campaign_id']] = true;
        }
        if (!empty($pendingCampaigns)) {
            $queue = new QueueManager($this->db);
            foreach (array_keys($pendingCampaigns) as $campaignId) {
                if ($queue->push('SeoContentGenerateJob', ['campaign_id' => $campaignId])) {
                    $summary['campaigns_enqueued']++;
                }
            }
        }

        // 2) فهرسة IndexNow
        foreach ($this->pendingIndexItems($indexLimit) as $item) {
            try {
                // مركز الأتمتة: نشر المقال + فهرسته أوتوماتيك أو بعد موافقة العميل
                if (class_exists('AutomationEngine')) {
                    $campaign = $this->getCampaign((int) $item['campaign_id']);
                    $ownerId = (int) ($campaign['user_id'] ?? 0);
                    $gate = AutomationEngine::instance()->gate(
                        $ownerId,
                        (int) ($campaign['website_id'] ?? 0),
                        'seo_content',
                        'default',
                        'seo_content_item',
                        (string) $item['id'],
                        [
                            'title' => (string) ($item['title'] ?: $item['topic']),
                            'context' => (string) $item['topic'],
                            'draft' => (string) ($item['title'] ?? ''),
                            'payload' => ['article_id' => (int) ($item['article_id'] ?? 0)],
                        ]
                    );
                    if ($gate['action'] !== 'execute') {
                        continue;
                    }
                    $res = SeoContentAutomationHandler::publishItem((int) $item['id'], $ownerId);
                    AutomationEngine::instance()->complete($gate['decision_id'] ?? null, !empty($res['success']), $res['error'] ?? null);
                } else {
                    $res = $this->indexItem((int) $item['id']);
                }
                if (!empty($res['success'])) {
                    $summary['indexed']++;
                }
            } catch (Exception $e) {
                Logger::warning('SEO content engine: index failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }

        // 3) تجارب A/B
        foreach ($this->pendingAbTestItems($abLimit) as $item) {
            try {
                $res = $this->createTitleAbTest((int) $item['id']);
                if (!empty($res['success'])) {
                    $summary['ab_created']++;
                }
            } catch (Exception $e) {
                Logger::warning('SEO content engine: A/B create failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }

        // 4) تطبيق العنوان الفائز
        foreach ($this->pendingWinnerApplyItems($winnerLimit) as $item) {
            try {
                $res = $this->applyWinningTitleToItem((int) $item['id']);
                if (!empty($res['success'])) {
                    $summary['winner_applied']++;
                }
            } catch (Exception $e) {
                Logger::warning('SEO content engine: winner apply failed', ['item_id' => $item['id'], 'error' => $e->getMessage()]);
            }
        }

        return $summary;
    }

    // ==================== helpers ====================

    private function getCampaign(int $campaignId): ?array
    {
        $rows = $this->db->query("SELECT * FROM seo_content_campaigns WHERE id = ?", [$campaignId]);
        return $rows[0] ?? null;
    }

    private function getItem(int $itemId): ?array
    {
        $rows = $this->db->query("SELECT * FROM seo_content_items WHERE id = ?", [$itemId]);
        return $rows[0] ?? null;
    }

    private function markItemFailed(int $itemId, string $error): void
    {
        $this->db->exec(
            "UPDATE seo_content_items SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?",
            [mb_substr($error, 0, 500), $itemId]
        );
    }

    private function touchCampaignCounters(int $campaignId): void
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(status IN ('generated','indexed','testing','published')) AS done,
                    SUM(status = 'published') AS published
               FROM seo_content_items WHERE campaign_id = ?",
            [$campaignId]
        );
        $total = (int) ($row[0]['total'] ?? 0);
        $done = (int) ($row[0]['done'] ?? 0);
        $published = (int) ($row[0]['published'] ?? 0);
        $status = 'draft';
        if ($total > 0 && $published >= $total) {
            $status = 'completed';
        } elseif ($total > 0 && $done >= $total) {
            $status = 'ready';
        } elseif ($done > 0) {
            $status = 'generating';
        }
        $this->db->exec(
            "UPDATE seo_content_campaigns SET generated_items = ?, status = ?, updated_at = NOW() WHERE id = ?",
            [$done, $status, $campaignId]
        );
    }

    private function getGscConnection(int $websiteId): ?array
    {
        $conns = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);
        if (empty($conns)) {
            return null;
        }
        $c = $conns[0];
        return [
            'access_token' => $c->getAttribute('access_token'),
            'site_url' => $c->getAttribute('external_location_id'),
        ];
    }

    private function decryptToken(?string $encrypted): string
    {
        if (!$encrypted) {
            return '';
        }
        try {
            return (new Encryption())->decrypt($encrypted);
        } catch (Exception $e) {
            return '';
        }
    }
}
