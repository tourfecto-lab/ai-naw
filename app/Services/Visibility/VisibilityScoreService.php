<?php
/**
 * لوحة الظهور الموحدة: SEO + AEO + GEO لموقع واحد.
 * كل رقم له مصدر وتاريخ ومستوى ثقة، وأي محور غير مقيس يرجع `not_measured` بدل رقم مخترع.
 * الدوال الساكنة (seo/aeo/geo/topActions) منطق صرف بلا قاعدة بيانات علشان تتختبر مباشرة.
 */
final class VisibilityScoreService
{
    private const STALE_DAYS = 14;
    private ?Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** @throws InvalidArgumentException لو الموقع مش بتاع المستخدم (عزل Tenant) */
    public function overview(int $uid, int $wid, ?string $now = null): array
    {
        $site = $this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ?', [$wid, $uid]);
        if (!$site) {
            throw new InvalidArgumentException('الموقع مش موجود');
        }
        $now = $now ?? gmdate('Y-m-d H:i:s');

        $seoReport = $this->safe(fn () => $this->db->query(
            'SELECT overall_score, findings_total, source, created_at FROM seo_reports WHERE website_id = ? AND user_id = ? AND overall_score IS NOT NULL ORDER BY id DESC LIMIT 1',
            [$wid, $uid]
        )[0] ?? null);

        $geoMetrics = $this->safe(fn () => (new AiVisibilityService($this->db))->metrics($uid, $wid));

        // آخر دورة SEO Expert مكتملة، والمهام المفتوحة فيها (active/pending/needs_client). الدورات القديمة ما بتدخلش.
        $run = $this->safe(fn () => $this->db->query(
            "SELECT id, created_at, JSON_UNQUOTE(JSON_EXTRACT(snapshot_json, '$.coverage.audited')) AS audited, JSON_UNQUOTE(JSON_EXTRACT(snapshot_json, '$.partial')) AS is_partial
             FROM seo_expert_runs WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$wid, $uid]
        )[0] ?? null);
        $open = is_array($run) ? (array) $this->safe(fn () => $this->db->query(
            "SELECT id, priority, title, page_path FROM seo_expert_tasks WHERE run_id = ? AND website_id = ? AND user_id = ? AND status IN ('active','pending','needs_client') ORDER BY id DESC LIMIT 200",
            [(int) $run['id'], $wid, $uid]
        )) : [];

        $candidates = [];
        foreach (array_slice($open, 0, 40) as $t) {
            $candidates[] = ['area' => 'seo', 'ref' => 'seo_expert:' . $t['id'], 'priority' => (string) $t['priority'],
                'title' => (string) $t['title'], 'detail' => (string) $t['page_path'], 'url' => '/seo-expert?website_id=' . $wid];
        }
        foreach ((array) $this->safe(fn () => $this->db->query(
            "SELECT id, title FROM seo_ai_tasks WHERE website_id = ? AND user_id = ? AND status <> 'done' ORDER BY id DESC LIMIT 20",
            [$wid, $uid]
        )) as $t) {
            $candidates[] = ['area' => 'geo', 'ref' => 'ai_task:' . $t['id'], 'priority' => 'medium',
                'title' => (string) $t['title'], 'detail' => '', 'url' => '/ai-visibility?website_id=' . $wid];
        }

        return [
            'website_id' => $wid,
            'generated_at' => $now,
            'seo' => self::pickSeo(self::seo(is_array($seoReport) ? $seoReport : null, $now), self::seoFromRun(is_array($run) ? $run : null, $open, $now)),
            'aeo' => self::aeo(null),
            'geo' => self::geo(is_array($geoMetrics) ? $geoMetrics : null, $now),
            'actions' => self::topActions($candidates, 5),
        ];
    }

    public static function seo(?array $report, string $now): array
    {
        if (!$report || $report['overall_score'] === null) {
            return ['state' => 'not_measured', 'score' => null, 'source' => 'seo_reports', 'as_of' => null, 'confidence' => null, 'stale' => false];
        }
        return [
            'state' => 'measured', 'score' => (int) round(max(0, min(100, (float) $report['overall_score']))),
            'source' => 'site_audit', 'as_of' => $report['created_at'], 'confidence' => 'high',
            'stale' => self::isStale((string) $report['created_at'], $now),
            'findings_total' => (int) ($report['findings_total'] ?? 0),
        ];
    }

    /**
     * درجة SEO من دورة SEO Expert: نسبة الصفحات اللي اتفحصت وما عليهاش مهمة مفتوحة.
     * ده مقياس "صحة الصفحات" وليس درجة تقييم شاملة؛ الثقة بتقل لو الفحص جزئي أو عدد الصفحات قليل.
     */
    public static function seoFromRun(?array $run, array $openTasks, string $now): array
    {
        $audited = $run ? (int) ($run['audited'] ?? 0) : 0;
        if (!$run || $audited <= 0) {
            return ['state' => 'not_measured', 'score' => null, 'source' => 'seo_expert_run', 'as_of' => null, 'confidence' => null, 'stale' => false];
        }
        $paths = [];
        foreach ($openTasks as $t) {
            $paths[(string) $t['page_path']] = true;
        }
        $score = (int) round(100 * max(0, 1 - count($paths) / $audited));
        $partial = in_array(strtolower((string) ($run['is_partial'] ?? '')), ['1', 'true'], true);
        return ['state' => 'measured', 'score' => $score, 'source' => 'seo_expert_run', 'basis' => 'audited_pages_without_open_tasks',
            'as_of' => $run['created_at'], 'confidence' => $partial || $audited < 5 ? 'low' : ($audited >= 20 ? 'high' : 'medium'),
            'stale' => self::isStale((string) $run['created_at'], $now), 'audited_pages' => $audited, 'pages_with_open_tasks' => count($paths)];
    }

    /** الأحدث تاريخًا بين لقطة الـAuto SEO ودورة SEO Expert؛ لو واحدة بس مقاسة نرجّعها. */
    public static function pickSeo(array $a, array $b): array
    {
        if ($a['state'] !== 'measured') return $b;
        if ($b['state'] !== 'measured') return $a;
        return strcmp((string) $b['as_of'], (string) $a['as_of']) > 0 ? $b : $a;
    }

    /** AEO مش مقيس لحد ما مولّد/قياس الإجابات (مرحلة AEO) يتفعّل؛ نرجّع not_measured صراحة. */
    public static function aeo(?array $measurement): array
    {
        if ($measurement === null || !isset($measurement['score'])) {
            return ['state' => 'not_measured', 'score' => null, 'source' => null, 'as_of' => null, 'confidence' => null, 'stale' => false];
        }
        return ['state' => 'measured', 'score' => (int) max(0, min(100, $measurement['score'])), 'source' => 'answer_coverage',
            'as_of' => $measurement['as_of'] ?? null, 'confidence' => $measurement['confidence'] ?? 'low', 'stale' => false];
    }

    /** نسبة الذكر 70% + نسبة الاستشهاد 30%، متوسط المزودين اللي عندهم عينة فعلية. هي عينة API مش ترتيب تطبيقات المستهلك. */
    public static function geo(?array $metrics, string $now): array
    {
        $empty = ['state' => 'not_measured', 'score' => null, 'source' => 'ai_answers_sample', 'as_of' => null, 'confidence' => null, 'stale' => false, 'providers' => 0];
        if (!$metrics || empty($metrics['providers'])) {
            return $empty;
        }
        $sov = [];
        $cite = [];
        $questions = 0;
        $latest = null;
        foreach ($metrics['providers'] as $p) {
            if (($p['state'] ?? '') !== 'measured_sample' || $p['share_of_voice'] === null) {
                continue;
            }
            $sov[] = (float) $p['share_of_voice'];
            if ($p['citation_rate'] !== null) {
                $cite[] = (float) $p['citation_rate'];
            }
            $questions += (int) $p['questions'];
            if ($p['week_start'] && ($latest === null || $p['week_start'] > $latest)) {
                $latest = $p['week_start'];
            }
        }
        if (!$sov) {
            return $empty;
        }
        $avgSov = array_sum($sov) / count($sov);
        $avgCite = $cite ? array_sum($cite) / count($cite) : 0.0;
        return [
            'state' => 'measured_sample', 'score' => (int) round(100 * (0.7 * $avgSov + 0.3 * $avgCite)),
            'source' => 'ai_answers_sample', 'as_of' => $latest, 'providers' => count($sov), 'questions' => $questions,
            'confidence' => $questions >= 30 ? 'high' : ($questions >= 10 ? 'medium' : 'low'),
            'stale' => $latest === null || self::isStale($latest . ' 00:00:00', $now),
        ];
    }

    /** ترتيب بالأولوية ثم الأحدث، مع منع تكرار نفس المرجع وحد أقصى لكل محور علشان القائمة تبقى متنوعة. */
    public static function topActions(array $candidates, int $limit = 5): array
    {
        $weight = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $seen = [];
        $unique = [];
        foreach ($candidates as $i => $c) {
            if (isset($seen[$c['ref']])) {
                continue;
            }
            $seen[$c['ref']] = true;
            $c['_w'] = $weight[strtolower($c['priority'])] ?? 2;
            $c['_i'] = $i;
            $unique[] = $c;
        }
        usort($unique, static fn ($a, $b) => [$b['_w'], $a['_i']] <=> [$a['_w'], $b['_i']]);
        $out = [];
        $perArea = [];
        foreach ($unique as $c) {
            $perArea[$c['area']] = ($perArea[$c['area']] ?? 0) + 1;
            if ($perArea[$c['area']] > 3) {
                continue;
            }
            unset($c['_w'], $c['_i']);
            $out[] = $c;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private static function isStale(string $ts, string $now): bool
    {
        $t = strtotime($ts . (str_contains($ts, ' ') || str_contains($ts, 'T') ? '' : ' 00:00:00') . ' UTC');
        $n = strtotime($now . ' UTC');
        return $t === false || $n === false || ($n - $t) > self::STALE_DAYS * 86400;
    }

    /** مصدر ناقص (جدول لسه ما اتعملش migration) ما يكسرش اللوحة. */
    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Logger::error('VisibilityScoreService source unavailable', ['exception' => get_class($e), 'error' => $e->getMessage()]);
            return null;
        }
    }
}
