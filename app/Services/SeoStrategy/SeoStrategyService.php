<?php

/**
 * Tourfecto - SEO Strategy Service
 * أول Agent في الجلسة دي بيربط كل الـAgents التانية في خطة تنفيذ واحدة:
 * SEO Score/Findings الحقيقية (Website Optimizer)، مقارنة المنافسين
 * (Phase 7)، فرص الكلمات المفتاحية (Phase 6)، حالة Outreach Pipeline
 * (Phase 10) - وبيطلب من الذكاء الاصطناعي يبني خطة 30/60/90 يوم مبنية
 * على البيانات دي فعليًا، مش خطة عامة قياسية.
 * @version 1.0.0
 */
class SeoStrategyService
{
    /** @var mixed أي كائن عنده generateContent($prompt,$options):array - عادة AIOrchestrator */
    private $ai;

    public function __construct($ai = null)
    {
        $this->ai = $ai ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
    }

    /**
     * تجميع بيانات حقيقية عن موقع معيّن من كل الـAgents ذات الصلة.
     */
    public function gatherWebsiteContext(Database $db, int $userId, int $websiteId): array
    {
        $context = [
            'seo_score' => null,
            'top_findings' => [],
            'competitor_comparisons' => [],
            'keyword_opportunities' => [],
            'keyword_gaps' => [],
            'outreach_summary' => ['prospect' => 0, 'contacted' => 0, 'link_acquired' => 0],
        ];

        try {
            $audit = $db->query(
                "SELECT id, overall_score FROM wo_audits WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
                [$websiteId, $userId]
            );
            if (!empty($audit)) {
                $context['seo_score'] = (float) $audit[0]['overall_score'];
                $findings = $db->query(
                    "SELECT title, severity, message FROM wo_audit_findings WHERE audit_id = ? AND status IN ('fail','warn') ORDER BY FIELD(severity,'critical','high','medium','low') LIMIT 10",
                    [$audit[0]['id']]
                );
                $context['top_findings'] = $findings;
            }
        } catch (Exception $e) {
        }

        try {
            $context['competitor_comparisons'] = $db->query(
                "SELECT competitor_name, competitor_domain, my_score, competitor_score FROM competitors WHERE website_id = ? AND user_id = ? AND last_analyzed_at IS NOT NULL ORDER BY last_analyzed_at DESC LIMIT 3",
                [$websiteId, $userId]
            );
        } catch (Exception $e) {
        }

        try {
            $context['keyword_opportunities'] = $db->query(
                "SELECT keyword, priority, opportunity_score, target_page FROM tracked_keywords WHERE website_id = ? AND user_id = ? AND priority = 'high' ORDER BY opportunity_score DESC LIMIT 8",
                [$websiteId, $userId]
            );
        } catch (Exception $e) {
        }

        // Keyword Gap: competitor keywords client is not ranking for
        try {
            $context['keyword_gaps'] = $this->fetchKeywordGaps($db, $userId, $websiteId);
        } catch (Exception $e) {
            $context['keyword_gaps'] = [];
        }

        try {
            $rows = $db->query("SELECT status, COUNT(*) AS c FROM outreach_prospects WHERE website_id = ? AND user_id = ? GROUP BY status", [$websiteId, $userId]);
            foreach ($rows as $r) {
                if (isset($context['outreach_summary'][$r['status']])) {
                    $context['outreach_summary'][$r['status']] = (int) $r['c'];
                }
            }
        } catch (Exception $e) {
        }

        return $context;
    }

    /**
     * @return array ['success'=>bool, 'summary'=>?string, 'tasks'=>?array, 'error'=>?string]
     */
    public function generatePlan(Database $db, int $userId, int $websiteId): array
    {
        $context = $this->gatherWebsiteContext($db, $userId, $websiteId);

        if ($context['seo_score'] === null) {
            return ['success' => false, 'error' => 'محتاج تشغّل تدقيق SEO (Website Optimizer) على الموقع ده الأول عشان الخطة تبقى مبنية على بيانات حقيقية'];
        }

        $prompt = $this->buildPrompt($context) . "\nEvidence rule: keyword_gaps are untracked research opportunities, NOT verified ranking gaps. Do not claim the client does not rank. An audit score is not a measurement of rankings or AI citations. Missing data is unknown, not success.";

        $response = $this->ai->generateContent($prompt, [
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
            'task' => 'strategic_seo_plan', 'user_id'=>$userId, 'website_id'=>$websiteId,
        ]);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        $parsed = $this->extractJson((string) ($response['data'] ?? ''));
        if (!$parsed || empty($parsed['tasks']) || !is_array($parsed['tasks'])) {
            return ['success' => false, 'error' => 'تعذّر تحليل رد الذكاء الاصطناعي إلى خطة منظّمة'];
        }

        $tasks = [];
        foreach ($parsed['tasks'] as $t) {
            if (empty($t['title']) || empty($t['phase']) || !in_array($t['phase'], ['30_days', '60_days', '90_days'], true)) {
                continue;
            }

            $reason = (string) ($t['reason'] ?? '');
            if (empty($reason) && !empty($context['keyword_gaps'])) {
                $title = (string) $t['title'];
                $description = (string) ($t['description'] ?? '');
                $matchedGap = array_values(array_filter($context['keyword_gaps'], fn ($g) => stripos($title, $g['keyword']) !== false || stripos($description, $g['keyword']) !== false));
                if (!empty($matchedGap)) {
                    $competitorCount = count(array_unique(array_column($matchedGap, 'competitor_domain')));
                    $totalCompetitors = count($context['competitor_comparisons'] ?? []);
                    $reason = "مقترح لأن {$competitorCount} من {$totalCompetitors} منافسين ظهرت الكلمة في بياناتهم \"{$matchedGap[0]['keyword']}\" وترتيب موقعك عليها غير متحقق";
                }
            }

            $tasks[] = [
                'phase' => $t['phase'],
                'week_label' => isset($t['week_label']) ? (string) $t['week_label'] : null,
                'title' => (string) $t['title'],
                'description' => (string) ($t['description'] ?? ''),
                'priority' => in_array($t['priority'] ?? '', ['high', 'medium', 'low'], true) ? $t['priority'] : 'medium',
                'estimated_impact' => in_array($t['estimated_impact'] ?? '', ['high', 'medium', 'low'], true) ? $t['estimated_impact'] : 'medium',
                'difficulty' => in_array($t['difficulty'] ?? '', ['easy', 'medium', 'hard'], true) ? $t['difficulty'] : 'medium',
                'owner' => (string) ($t['owner'] ?? 'العميل'),
                'reason' => $reason,
            ];
        }

        if (empty($tasks)) {
            return ['success' => false, 'error' => 'الخطة الراجعة من الذكاء الاصطناعي مالهاش مهام صالحة'];
        }

        return [
            'success' => true,
            'summary' => (string) ($parsed['summary'] ?? ''),
            'tasks' => $tasks,
            'context_used' => $context,
        ];
    }

    private function fetchKeywordGaps(Database $db, int $userId, int $websiteId): array
    {
        $competitors = $db->query(
            "SELECT id, competitor_domain FROM competitors WHERE user_id = ? AND website_id = ? AND last_analyzed_at IS NOT NULL ORDER BY last_analyzed_at DESC LIMIT 5",
            [$userId, $websiteId]
        );
        if (empty($competitors)) {
            return [];
        }

        $competitorIds = array_column($competitors, 'id');
        $placeholders = implode(',', array_fill(0, count($competitorIds), '?'));

        $competitorKeywords = $db->query(
            "SELECT keyword, competitor_id, search_volume, difficulty FROM competitor_keywords WHERE competitor_id IN ({$placeholders}) AND keyword IS NOT NULL AND keyword <> '' ORDER BY search_volume DESC",
            $competitorIds
        );

        if (empty($competitorKeywords)) {
            return [];
        }

        $clientKeywords = $db->query(
            "SELECT keyword FROM tracked_keywords WHERE website_id = ? AND user_id = ?",
            [$websiteId, $userId]
        );
        $clientKeywordSet = array_flip(array_map('strtolower', array_column($clientKeywords, 'keyword')));

        $gaps = [];
        $seen = [];
        foreach ($competitorKeywords as $ck) {
            $kw = strtolower(trim((string) $ck['keyword']));
            if (isset($seen[$kw]) || isset($clientKeywordSet[$kw])) {
                continue;
            }
            $seen[$kw] = true;
            $competitor = array_values(array_filter($competitors, fn ($c) => $c['id'] == $ck['competitor_id']))[0] ?? null;
            $gaps[] = [
                'keyword' => $ck['keyword'],
                'competitor_domain' => $competitor['competitor_domain'] ?? 'unknown',
                'search_volume' => $ck['search_volume'] ?? null,
                'difficulty' => $ck['difficulty'] ?? null,
                'evidence_state' => 'untracked_opportunity',
                'ranking_gap_verified' => false,
            ];
        }

        return array_slice($gaps, 0, 20);
    }

    private function buildPrompt(array $context): string
    {
        $findingsLines = empty($context['top_findings'])
            ? '- لا توجد مشاكل متاحة في البيانات؛ هذا لا يثبت خلو الموقع من المشاكل.'
            : implode("\n", array_map(fn ($f) => "- [{$f['severity']}] {$f['title']}: {$f['message']}", $context['top_findings']));

        $compLines = empty($context['competitor_comparisons'])
            ? '- لا يوجد تحليل منافسين حتى الآن.'
            : implode("\n", array_map(fn ($c) => "- {$c['competitor_name']}: أنا {$c['my_score']}/100 مقابل {$c['competitor_score']}/100", $context['competitor_comparisons']));

        $kwLines = empty($context['keyword_opportunities'])
            ? '- لا توجد كلمات مفتاحية عالية الأولوية مسجّلة.'
            : implode("\n", array_map(fn ($k) => "- \"{$k['keyword']}\" (فرصة: {$k['opportunity_score']}/100, الصفحة المستهدفة: " . ($k['target_page'] ?: 'غير محددة') . ")", $context['keyword_opportunities']));

        $gapLines = empty($context['keyword_gaps'])
            ? '- لا توجد بيانات كلمات مفتاحية للمنافسين.'
            : implode("\n", array_map(fn ($g) => "- \"{$g['keyword']}\" (منافس: {$g['competitor_domain']}" . ($g['search_volume'] ? ", حجم بحث: {$g['search_volume']}" : "") . ")", $context['keyword_gaps']));

        $outreach = $context['outreach_summary'];

        return <<<PROMPT
أنت استراتيجي SEO لشركة سياحة. عندك بيانات حقيقية عن حساب العميل، وعايزك تبني خطة
تنفيذ فعلية لمدة 90 يوم (مقسّمة 30/60/90 يوم) مبنية على البيانات دي فعليًا، مش خطة عامة.

=== SEO Score الحالي ===
{$context['seo_score']}/100

=== أهم المشاكل التقنية المكتشفة ===
{$findingsLines}

=== مقارنة المنافسين ===
{$compLines}

=== فرص الكلمات المفتاحية عالية الأولوية ===
{$kwLines}

=== فرص بحث غير متتبعة من بيانات المنافسين (ترتيب موقعك غير متحقق) ===
{$gapLines}

=== حالة بناء الباك لينكس (Outreach) ===
مرشّحين: {$outreach['prospect']} | تم التواصل: {$outreach['contacted']} | تم الحصول على رابط: {$outreach['link_acquired']}

اقترح 8-15 مهمة موزّعة على 3 مراحل (30_days/60_days/90_days)، كل مهمة مبنية على حاجة حقيقية من
البيانات أعلاه (مش عمومية). المرحلة الأولى (30 يوم) لازم تركّز على أخطر المشاكل التقنية.
المرحلة التانية (60 يوم) على المحتوى والكلمات المفتاحية. المرحلة التالتة (90 يوم) على السلطة/الـOutreach.

رجّع الرد **بصيغة JSON فقط**:
{
  "summary": "ملخص تنفيذي مختصر لاستراتيجية الـ90 يوم (2-3 جمل)",
  "tasks": [
    {
      "phase": "30_days",
      "week_label": "الأسبوع 1",
      "title": "عنوان المهمة",
      "description": "وصف مختصر قابل للتنفيذ",
      "priority": "high",
      "estimated_impact": "high",
      "difficulty": "easy",
      "owner": "العميل"
    }
  ]
}
PROMPT;
    }

    private function extractJson(string $text): ?array
    {
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $data = json_decode($m[0], true);
            return json_last_error() === JSON_ERROR_NONE ? $data : null;
        }
        return null;
    }
}
