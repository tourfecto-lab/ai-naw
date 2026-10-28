<?php

/**
 * Tourfecto - Competitor Intelligence: AI Competitive Analyst
 * @version 1.0.0
 *
 * يعتمد حصريًا على البيانات المُخزَّنة فعليًا (ci_changes/ci_insights/
 * competitors) - يُمرَّر كسياق (context) صريح داخل الـ prompt، ويُطلب
 * من النموذج صراحة عدم اختلاق أي معلومة غير موجودة في السياق. لا
 * يخترع مصادر أبدًا (يُعاد استخدام GeminiClient الموحّد بالمشروع، بنفس
 * أسلوب CompetitorAnalysisService الموجود مسبقًا).
 */
class AICompetitiveAnalyst
{
    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    /**
     * سؤال حر بالعربي أو الإنجليزي، مبني على آخر نشاط حقيقي مُسجَّل فقط.
     */
    public function ask(int $userId, string $question, int $days = 30): array
    {
        $context = $this->buildContext($userId, $days);

        if (empty($context['changes']) && empty($context['insights'])) {
            return [
                'success' => true,
                'answer' => 'لا توجد بيانات كافية عن نشاط المنافسين خلال الفترة المطلوبة للإجابة على هذا السؤال. فعّل المراقبة الآلية لمنافسيك أولًا.',
                'grounded_on' => [],
            ];
        }

        $prompt = $this->buildPrompt($question, $context);
        $response = $this->ai->generateContent($prompt, ['task'=>'competitive_insights', 'maxOutputTokens' => 1024]);

        if (!($response['success'] ?? false)) {
            return ['success' => false, 'answer' => null, 'error' => $response['error'] ?? 'AI request failed', 'grounded_on' => []];
        }

        return [
            'success' => true,
            'answer' => trim((string) ($response['data'] ?? '')),
            'grounded_on' => array_map(fn ($c) => $c['summary'], array_slice($context['changes'], 0, 10)),
        ];
    }

    /**
     * ملخص أسبوعي (What Changed / Why It Matters / Threats / Opportunities /
     * Recommended Actions) - فقط لو توفرت بيانات كافية، وإلا يُرجَّع بوضوح
     * أن البيانات غير كافية بدل توليد ملخص فارغ/مُختلَق.
     */
    public function weeklySummary(int $userId, int $websiteId): array
    {
        $context = $this->buildContext($userId, 7);

        if (empty($context['changes'])) {
            return ['available' => false, 'reason' => 'insufficient_data_last_7_days', 'summary' => null];
        }

        $prompt = $this->buildPrompt(
            'اكتب ملخص أسبوعي منظم بعناوين: What Changed, Why It Matters, Threats, Opportunities, Recommended Actions. '
            . 'رجّع JSON فقط بالشكل: {"what_changed":"...","why_it_matters":"...","threats":"...","opportunities":"...","recommended_actions":"..."}',
            $context
        );

        $response = $this->ai->generateContent($prompt, ['task'=>'competitive_insights', 'maxOutputTokens' => 2048, 'responseMimeType' => 'application/json']);

        if (!($response['success'] ?? false)) {
            return ['available' => false, 'reason' => $response['error'] ?? 'ai_request_failed', 'summary' => null];
        }

        $raw = preg_replace('/^```(json)?|```$/m', '', trim((string) ($response['data'] ?? '')));
        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed)) {
            return ['available' => false, 'reason' => 'unparseable_ai_response', 'summary' => null];
        }

        return ['available' => true, 'reason' => null, 'summary' => $parsed];
    }

    private function buildContext(int $userId, int $days): array
    {
        $db = Database::getInstance();

        $changeRows = $db->query(
            "SELECT c.*, comp.competitor_name, comp.competitor_domain
             FROM `ci_changes` c JOIN `competitors` comp ON comp.id = c.competitor_id
             WHERE c.user_id = ? AND c.detected_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY c.detected_at DESC LIMIT 60",
            [$userId, $days]
        );

        $changes = array_map(function ($r) {
            $name = $r['competitor_name'] ?: $r['competitor_domain'];
            return [
                'summary' => "[{$r['detected_at']}] {$name}: {$r['change_type']} on {$r['page_type']} (severity={$r['severity']}, confidence={$r['confidence']})",
                'raw' => $r,
            ];
        }, $changeRows);

        $insightRows = $db->query(
            "SELECT * FROM `ci_insights` WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at DESC LIMIT 20",
            [$userId, $days]
        );

        return ['changes' => $changes, 'insights' => $insightRows];
    }

    private function buildPrompt(string $question, array $context): string
    {
        $lines = array_map(fn ($c) => '- ' . $c['summary'], $context['changes']);
        $dataBlock = implode("\n", $lines);

        $insightLines = array_map(fn ($i) => "- [{$i['type']}] {$i['title']}: {$i['description']}", $context['insights']);
        $insightBlock = implode("\n", $insightLines);

        return <<<PROMPT
أنت محلل منافسين لمنصة سياحية. أجب فقط بناءً على البيانات التالية،
المُسجَّلة فعليًا من مراقبة صفحات المنافسين العامة. لا تخترع أي منافس
أو تغيير أو مصدر غير موجود في هذه القائمة. لو البيانات غير كافية
للإجابة بدقة، قل ذلك صراحة.

بيانات التغييرات المُكتشفة فعليًا (آخر فترة):
{$dataBlock}

رؤى تحليلية سابقة (Threats/Opportunities مبنية على نفس البيانات):
{$insightBlock}

السؤال: {$question}
PROMPT;
    }

    /**
     * يولّد Positioning/Strengths/Weaknesses لصفحة Competitor Profile -
     * مبني حصريًا على النصوص الحقيقية المُلتقطة فعليًا (عناوين/meta
     * description/مقتطفات الصفحات المُراقَبة) - مش تخمين عام عن الشركة.
     * لو مفيش لقطات ناجحة بعد، يرجّع available=false بوضوح.
     * النتيجة بتُخزَّن كـ ci_insights (type=insight, generated_by=ai)
     * عشان تظهر في Competitor Profile زي أي insight تاني، ومُعلَّم إنه
     * تحليل مش حقيقة مؤكدة.
     */
    public function analyzeProfile(Competitor $competitor): array
    {
        $competitorId = (int) $competitor->getAttribute('id');
        $db = Database::getInstance();

        $snapshots = $db->query(
            "SELECT s1.* FROM ci_snapshots s1
             INNER JOIN (SELECT page_type, MAX(captured_at) AS max_date FROM ci_snapshots WHERE competitor_id = ? AND fetch_status = 'ok' GROUP BY page_type) s2
             ON s1.page_type = s2.page_type AND s1.captured_at = s2.max_date
             WHERE s1.competitor_id = ?",
            [$competitorId, $competitorId]
        );

        if (empty($snapshots)) {
            return ['available' => false, 'reason' => 'no_successful_snapshots_yet', 'insight' => null];
        }

        $name = (string) ($competitor->getAttribute('competitor_name') ?: $competitor->getAttribute('competitor_domain'));
        $capturedText = '';
        foreach ($snapshots as $s) {
            $capturedText .= "\n[{$s['page_type']}] title: " . ($s['title'] ?: '(none)') . ' | meta: ' . ($s['meta_description'] ?: '(none)')
                . ' | excerpt: ' . mb_substr((string) $s['normalized_excerpt'], 0, 500);
        }

        $prompt = <<<PROMPT
أنت محلل منافسين. اعتمد فقط على النصوص المُلتقطة فعليًا من صفحات
"{$name}" العامة أدناه - لا تخترع أي معلومة عن الشركة غير موجودة في
هذا النص. رجّع JSON فقط بالشكل:
{"positioning":"...","strengths":["...","..."],"weaknesses":["...","..."]}

النصوص المُلتقطة فعليًا من صفحات المنافس:
{$capturedText}
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task'=>'competitive_insights', 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json']);
        if (!($response['success'] ?? false)) {
            return ['available' => false, 'reason' => $response['error'] ?? 'ai_request_failed', 'insight' => null];
        }

        $raw = preg_replace('/^```(json)?|```$/m', '', trim((string) ($response['data'] ?? '')));
        $parsed = json_decode(trim($raw), true);
        if (!is_array($parsed)) {
            return ['available' => false, 'reason' => 'unparseable_ai_response', 'insight' => null];
        }

        $description = ($parsed['positioning'] ?? '')
            . (!empty($parsed['strengths']) ? "\n\nStrengths: " . implode('; ', (array) $parsed['strengths']) : '')
            . (!empty($parsed['weaknesses']) ? "\n\nWeaknesses: " . implode('; ', (array) $parsed['weaknesses']) : '');

        $insight = new CiInsight([
            'user_id' => (int) $competitor->getAttribute('user_id'),
            'website_id' => (int) $competitor->getAttribute('website_id'),
            'competitor_id' => $competitorId,
            'type' => 'insight',
            'title' => "AI positioning analysis: {$name}",
            'description' => $description,
            'evidence' => 'Derived from captured page content (titles/meta/excerpts) across ' . count($snapshots) . ' monitored page(s).',
            'confidence' => 'medium',
            'recommended_action' => null,
            'status' => 'new',
            'generated_by' => 'ai',
        ]);
        $insight->save();

        return ['available' => true, 'reason' => null, 'insight' => $insight->toArray()];
    }
}
