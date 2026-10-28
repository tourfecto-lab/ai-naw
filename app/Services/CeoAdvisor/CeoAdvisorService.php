<?php

/**
 * Tourfecto - CEO Advisor Service
 * Phase 11 (AI CEO Advisor). بيجمع بيانات حقيقية من كل الـAgents اللي
 * اتبنوا في الـPhases السابقة (SEO Score من Website Optimizer, Competitor
 * Score من Phase 7, فرص الكلمات المفتاحية من Phase 6, حالة Outreach
 * Pipeline من Phase 10, تكلفة AI من Phase 4) + الملاحظات/التنبيهات
 * اليدوية الموجودة بالفعل في جداول ceo_ و cc_ai (بادئات الأسماء) - وبيبني إجابة مبنية على
 * البيانات دي فعليًا، مش إجابات عامة، بالظبط زي ما السبيك بتطلب صراحةً.
 *
 * ملحوظة: الجداول ceo_business_context_notes و ceo_risk_alerts و
 * ceo_growth_opportunities و cc_ai_alerts و cc_ai_tasks كانت موجودة بالفعل
 * (migration من BATCH6) وبيقراها ExecutiveExtrasController الموجود، لكن
 * مفيش حد كان بيكتب فيها تلقائيًا أو بيستخدمها في سؤال/جواب حر - الكلاس
 * ده أول مستهلك حقيقي ليها بالمعنى ده.
 * @version 1.0.0
 */
class CeoAdvisorService
{
    /** @var mixed أي كائن عنده generateContent($prompt,$options):array - عادة AIOrchestrator */
    private $ai;

    public function __construct($ai = null)
    {
        $this->ai = $ai ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
    }

    /**
     * تجميع لقطة حقيقية من بيانات حساب المستخدم عبر كل الـAgents.
     * دالة عامة (مش private) عشان تقدر تتستخدم لوحدها لو حبينا نعرضها
     * كـ"ملخص الحساب" في مكان تاني من غير سؤال AI أصلًا.
     */
    public function gatherAccountSnapshot(Database $db, int $userId): array
    {
        $websites = $db->query("SELECT id, main_url, company_name FROM websites WHERE user_id = ?", [$userId]);
        $websiteIds = array_column($websites, 'id');

        $snapshot = [
            'websites_count' => count($websites),
            'seo_scores' => [],
            'competitor_comparisons' => [],
            'keyword_opportunities' => ['high_priority_count' => 0, 'total_tracked' => 0],
            'outreach_pipeline' => ['prospect' => 0, 'contacted' => 0, 'replied' => 0, 'negotiating' => 0, 'link_acquired' => 0],
            'ai_cost_this_month' => 0,
            'manual_notes' => [],
            'open_risks' => [],
            'open_opportunities' => [],
        ];

        if (empty($websiteIds)) {
            return $snapshot;
        }
        $placeholders = implode(',', array_fill(0, count($websiteIds), '?'));

        // آخر SEO Score حقيقي لكل موقع (من Website Optimizer - Phase موجودة من الأول)
        try {
            $rows = $db->query(
                "SELECT w.main_url, a.overall_score, a.completed_at
                 FROM wo_audits a
                 INNER JOIN websites w ON w.id = a.website_id
                 WHERE a.website_id IN ($placeholders) AND a.status = 'completed'
                 ORDER BY a.completed_at DESC LIMIT 5",
                $websiteIds
            );
            $seen = [];
            foreach ($rows as $r) {
                if (isset($seen[$r['main_url']])) {
                    continue;
                } // أحدث سكور لكل موقع بس
                $seen[$r['main_url']] = true;
                $snapshot['seo_scores'][] = ['url' => $r['main_url'], 'score' => (float) $r['overall_score']];
            }
        } catch (Exception $e) { /* الجدول ممكن يكون مش موجود لسه على بيئات قديمة - نكمل من غيره */
        }

        // مقارنات المنافسين (Phase 7)
        try {
            $rows = $db->query(
                "SELECT competitor_name, competitor_domain, my_score, competitor_score
                 FROM competitors WHERE website_id IN ($placeholders) AND last_analyzed_at IS NOT NULL
                 ORDER BY last_analyzed_at DESC LIMIT 5",
                $websiteIds
            );
            $snapshot['competitor_comparisons'] = $rows;
        } catch (Exception $e) {
        }

        // فرص الكلمات المفتاحية (Phase 6)
        try {
            $high = $db->query("SELECT COUNT(*) AS c FROM tracked_keywords WHERE website_id IN ($placeholders) AND priority = 'high'", $websiteIds);
            $total = $db->query("SELECT COUNT(*) AS c FROM tracked_keywords WHERE website_id IN ($placeholders)", $websiteIds);
            $snapshot['keyword_opportunities'] = [
                'high_priority_count' => (int) ($high[0]['c'] ?? 0),
                'total_tracked' => (int) ($total[0]['c'] ?? 0),
            ];
        } catch (Exception $e) {
        }

        // حالة Outreach Pipeline (Phase 10)
        try {
            $rows = $db->query(
                "SELECT status, COUNT(*) AS c FROM outreach_prospects WHERE website_id IN ($placeholders) GROUP BY status",
                $websiteIds
            );
            foreach ($rows as $r) {
                if (isset($snapshot['outreach_pipeline'][$r['status']])) {
                    $snapshot['outreach_pipeline'][$r['status']] = (int) $r['c'];
                }
            }
        } catch (Exception $e) {
        }

        // تكلفة AI الشهر ده (Phase 4)
        try {
            $rows = $db->query(
                "SELECT SUM(cost_in_usd) AS total FROM api_usage_logs
                 WHERE user_id = ? AND api_type IN ('gemini','openai','deepseek','kimi')
                 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
                [$userId]
            );
            $snapshot['ai_cost_this_month'] = round((float) ($rows[0]['total'] ?? 0), 4);
        } catch (Exception $e) {
        }

        // الملاحظات/المخاطر/الفرص اليدوية الموجودة بالفعل (ExecutiveExtrasController)
        try {
            $snapshot['manual_notes'] = array_column(
                $db->query("SELECT note FROM ceo_business_context_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5", [$userId]),
                'note'
            );
            $snapshot['open_risks'] = $db->query(
                "SELECT title, severity FROM ceo_risk_alerts WHERE user_id = ? AND is_resolved = 0 ORDER BY FIELD(severity,'critical','high','medium','low') LIMIT 5",
                [$userId]
            );
            $snapshot['open_opportunities'] = $db->query(
                "SELECT title, estimated_impact FROM ceo_growth_opportunities WHERE user_id = ? AND status NOT IN ('done','dismissed') LIMIT 5",
                [$userId]
            );
        } catch (Exception $e) {
        }

        return $snapshot;
    }

    /**
     * سؤال حر مبني على بيانات الحساب الحقيقية.
     * @return array ['success'=>bool, 'answer'=>?string, 'snapshot_used'=>array, 'error'=>?string]
     */
    public function ask(Database $db, int $userId, string $question): array
    {
        $question = trim($question);
        if ($question === '') {
            return ['success' => false, 'error' => 'اكتب سؤالك الأول'];
        }

        $snapshot = $this->gatherAccountSnapshot($db, $userId);

        if ($snapshot['websites_count'] === 0) {
            return ['success' => false, 'error' => 'مفيش مواقع مسجّلة على الحساب لسه - أضف موقعك الأول عشان أقدر أدّيك نصيحة حقيقية مبنية على بياناتك'];
        }

        $prompt = $this->buildPrompt($question, $snapshot);

        $response = $this->ai->generateContent($prompt, [
            'maxOutputTokens' => 2048,
            'task' => 'ceo_advisor',
            'user_id' => $userId,
        ]);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        return [
            'success' => true,
            'answer' => trim((string) ($response['data'] ?? '')),
            'snapshot_used' => $snapshot,
            'provider' => $response['provider'] ?? null,
        ];
    }

    private function buildPrompt(string $question, array $snapshot): string
    {
        $seoLines = empty($snapshot['seo_scores'])
            ? '- لا يوجد تدقيق SEO تم تشغيله بعد لأي موقع.'
            : implode("\n", array_map(fn ($s) => "- {$s['url']}: SEO Score {$s['score']}/100", $snapshot['seo_scores']));

        $compLines = empty($snapshot['competitor_comparisons'])
            ? '- لا يوجد تحليل منافسين حتى الآن.'
            : implode("\n", array_map(
                fn ($c) => "- {$c['competitor_name']} ({$c['competitor_domain']}): موقعي {$c['my_score']}/100 مقابل المنافس {$c['competitor_score']}/100",
                $snapshot['competitor_comparisons']
            ));

        $kw = $snapshot['keyword_opportunities'];
        $outreach = $snapshot['outreach_pipeline'];
        $cost = $snapshot['ai_cost_this_month'];

        $notesLines = empty($snapshot['manual_notes']) ? '- لا يوجد.' : implode("\n", array_map(fn ($n) => "- {$n}", $snapshot['manual_notes']));
        $risksLines = empty($snapshot['open_risks']) ? '- لا يوجد.' : implode("\n", array_map(fn ($r) => "- [{$r['severity']}] {$r['title']}", $snapshot['open_risks']));
        $oppsLines = empty($snapshot['open_opportunities']) ? '- لا يوجد.' : implode("\n", array_map(fn ($o) => "- [{$o['estimated_impact']}] {$o['title']}", $snapshot['open_opportunities']));

        return <<<PROMPT
أنت مستشار نمو أعمال (CEO Advisor) لشركة سياحة، وعندك وصول لبيانات الحساب الفعلية دي بس -
جاوب بناءً عليها حرفيًا، ولو معلومة غير متوفرة قول كده صراحةً بدل ما تخترع رقم.

=== SEO Scores (من تدقيق تقني حقيقي) ===
{$seoLines}

=== مقارنة المنافسين ===
{$compLines}

=== الكلمات المفتاحية ===
عدد الكلمات عالية الأولوية: {$kw['high_priority_count']} من إجمالي {$kw['total_tracked']} كلمة متابَعة.

=== Outreach Pipeline (بناء باك لينكس) ===
مرشّحين جدد: {$outreach['prospect']} | تم التواصل: {$outreach['contacted']} | رد: {$outreach['replied']}
تفاوض: {$outreach['negotiating']} | تم الحصول على رابط: {$outreach['link_acquired']}

=== تكلفة الذكاء الاصطناعي الشهر ده ===
{$cost} دولار تقريبًا

=== ملاحظات صاحب الحساب ===
{$notesLines}

=== مخاطر مفتوحة ===
{$risksLines}

=== فرص نمو مفتوحة ===
{$oppsLines}

سؤال صاحب الحساب: "{$question}"

جاوب بشكل مباشر وعملي، بالعربية، بناءً على البيانات أعلاه فقط. لو البيانات مش كافية للإجابة
بدقة، قول إيه الناقص بدل ما تجاوب بعمومية.
PROMPT;
    }
}
