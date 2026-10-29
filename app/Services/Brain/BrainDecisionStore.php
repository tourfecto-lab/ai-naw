<?php
require_once __DIR__ . '/BrainSchema.php';
require_once __DIR__ . '/StrategicDecisions.php';
require_once __DIR__ . '/BrainExecutor.php';

/**
 * Tourfecto - العقل الاستراتيجي: حفظ القرارات وقياس نتيجتها (الحلقة: قرار ← موافقة ← تنفيذ ← قياس ← تعلّم)
 *
 * - القرار بيتحفظ مرة واحدة لكل شركة/موقع (decision_key)، وحالته بتفضل زي ما صاحب الشركة سابها.
 * - المقياس بيتسجل وقت الاقتراح، وبيتحدث وقت الموافقة (baseline).
 * - بعد ما القرار يتعلّم "اتنفّذ" بـ 7 أيام، أو لما سببه يختفي من البيانات، بنقيس تاني ونسجل النتيجة.
 * - نتايج الشركة نفسها بتغيّر أوزان مجالاتها في القرارات الجاية (تعلّم على مستوى الشركة بس؛
 *   مفيش بيانات بتعدي بين الشركات هنا).
 */
final class BrainDecisionStore
{
    public const STATUSES = ['proposed', 'approved', 'done', 'dismissed', 'resolved'];
    public const MEASURE_AFTER_DAYS = 7;

    private Database $db;
    private ?BrainExecutor $executor;

    public function __construct(?Database $db = null, ?BrainExecutor $executor = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
        $this->executor = $executor;
    }

    private function executor(): BrainExecutor
    {
        return $this->executor ??= new BrainExecutor($this->db);
    }

    /** التشغيل الذاتي: مقفول افتراضيًا */
    public function autonomy(int $uid): bool
    {
        return (bool) ($this->db->query('SELECT autonomy FROM brain_settings WHERE user_id = ? LIMIT 1', [$uid])[0]['autonomy'] ?? false);
    }

    public function setAutonomy(int $uid, bool $on): void
    {
        $this->db->query('INSERT INTO brain_settings (user_id, autonomy, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE autonomy = VALUES(autonomy), updated_at = VALUES(updated_at)', [$uid, $on ? 1 : 0]);
    }

    /** موافقة + تنفيذ (مهمة وتنبيه). القرار اللي فيه صرف مابيتنفّذش من هنا أبدًا */
    private function approveAndExecute(int $uid, int $wid, array $row, array $snapshot, string $lang): void
    {
        $current = $row['metric_key'] ? StrategicDecisions::metricValue($snapshot, $row['metric_key']) : null;
        $this->db->query("UPDATE brain_decisions SET status = 'approved', decided_at = UTC_TIMESTAMP(), baseline_value = COALESCE(?, baseline_value) WHERE id = ? AND user_id = ?", [$current, (int) $row['id'], $uid]);
        $decision = json_decode((string) $row['payload_json'], true) ?: [];
        if (!empty($decision['requires_spend']) || (int) $row['requires_spend'] === 1) return;
        try {
            $this->executor()->executeDecision($uid, $wid, $decision, $lang);
        } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('Brain execution failed', ['user_id' => $uid, 'decision' => $row['decision_key'], 'error' => $e->getMessage()]);
        }
    }

    /** يحدّث قرارات الشركة من صورتها الحالية ويرجعها مرتبة */
    /** @param array $shared أنماط الذكاء المشترك (فاضية لو الشركة مش مشارِكة) */
    public function sync(int $uid, int $wid, array $snapshot, array $shared = [], ?bool $autonomy = null, string $lang = 'ar'): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $fresh = StrategicDecisions::decide($snapshot, $this->learnedWeights($uid, $wid), $shared);
        $existing = [];
        foreach ($this->db->query('SELECT * FROM brain_decisions WHERE user_id = ? AND website_id = ?', [$uid, $wid]) ?: [] as $row) $existing[$row['decision_key']] = $row;

        $seen = [];
        foreach ($fresh as $d) {
            $seen[$d['key']] = true;
            $metric = $d['metric'];
            $payload = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!isset($existing[$d['key']])) {
                $this->db->query('INSERT INTO brain_decisions (user_id, website_id, decision_key, area, status, priority, requires_spend, payload_json, metric_key, metric_direction, baseline_value, proposed_at, last_seen_at)
                    VALUES (?, ?, ?, ?, \'proposed\', ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$uid, $wid, $d['key'], $d['area'], $d['priority'], $d['requires_spend'] ? 1 : 0, $payload, $metric['key'] ?? null, $metric['direction'] ?? null, $metric['value'] ?? null, $now, $now]);
                continue;
            }
            $row = $existing[$d['key']];
            // القرار رجع بعد ما اتحل: يرجع مقترح من جديد بقياس جديد
            $reopen = in_array($row['status'], ['resolved'], true);
            $this->db->query('UPDATE brain_decisions SET area = ?, priority = ?, requires_spend = ?, payload_json = ?, metric_key = ?, metric_direction = ?, last_seen_at = ?'
                . ($reopen ? ', status = \'proposed\', baseline_value = ?, result_value = NULL, outcome = NULL, proposed_at = ?, decided_at = NULL, done_at = NULL, measured_at = NULL' : '')
                . ' WHERE id = ? AND user_id = ?',
                array_merge([$d['area'], $d['priority'], $d['requires_spend'] ? 1 : 0, $payload, $metric['key'] ?? null, $metric['direction'] ?? null, $now],
                    $reopen ? [$metric['value'] ?? null, $now] : [], [(int) $row['id'], $uid]));
        }

        // سبب القرار اختفى من البيانات، أو عدّى أسبوع على تنفيذه: نقيس النتيجة
        foreach ($existing as $key => $row) {
            $gone = !isset($seen[$key]) && in_array($row['status'], ['proposed', 'approved', 'done'], true);
            $due = $row['status'] === 'done' && $row['outcome'] === null && $row['done_at'] !== null && strtotime($row['done_at'] . ' UTC') <= time() - self::MEASURE_AFTER_DAYS * 86400;
            if (!$gone && !$due) continue;
            $after = $row['metric_key'] ? StrategicDecisions::metricValue($snapshot, $row['metric_key']) : null;
            // مصدر البيانات مش متاح دلوقتي: مانحكمش إن المشكلة اتحلت
            if ($gone && $row['metric_key'] && $after === null) continue;
            $before = $row['baseline_value'] !== null ? (float) $row['baseline_value'] : null;
            // سبب من غير مقياس اختفى: في بيانات الشركة (زي ملف ناقص اتكمّل) يبقى اتحل فعلًا؛
            // في السوق (إجازة عدّت، تحذير بقى قديم) ده مرور وقت مش نتيجة، فمابيتحسبش نجاح ولا فشل
            $outcome = StrategicDecisions::outcome($before, $after, (string) $row['metric_direction']) ?? ($gone && $row['area'] === 'data' ? 'improved' : null);
            $this->db->query('UPDATE brain_decisions SET status = ?, result_value = ?, outcome = ?, measured_at = ? WHERE id = ? AND user_id = ?',
                [$gone ? 'resolved' : 'done', $after, $outcome, $now, (int) $row['id'], $uid]);
        }
        // التشغيل الذاتي: القرارات المقترحة اللي مافيهاش صرف بتتوافق وتتنفّذ (مهمة + تنبيه) من غير ما حد يدوس
        if ($autonomy ?? $this->autonomy($uid)) {
            foreach ($this->db->query("SELECT * FROM brain_decisions WHERE user_id = ? AND website_id = ? AND status = 'proposed' AND requires_spend = 0", [$uid, $wid]) ?: [] as $row) {
                $this->approveAndExecute($uid, $wid, $row, $snapshot, $lang);
            }
        }
        // أي قرار متوافق عليه لازم يكون ليه مهمة (منع التكرار في المنفّذ بيخلي ده آمن مع كل تحديث)
        foreach ($this->db->query("SELECT * FROM brain_decisions WHERE user_id = ? AND website_id = ? AND status = 'approved' AND requires_spend = 0", [$uid, $wid]) ?: [] as $row) {
            try { $this->executor()->executeDecision($uid, $wid, json_decode((string) $row['payload_json'], true) ?: [], $lang); }
            catch (Throwable $e) { if (class_exists('Logger')) Logger::warning('Brain execution failed', ['user_id' => $uid, 'decision' => $row['decision_key'], 'error' => $e->getMessage()]); }
        }
        return $this->list($uid, $wid);
    }

    public function list(int $uid, int $wid): array
    {
        $rows = $this->db->query("SELECT * FROM brain_decisions WHERE user_id = ? AND website_id = ?
            ORDER BY FIELD(status, 'approved', 'proposed', 'done', 'resolved', 'dismissed'), priority DESC, id ASC", [$uid, $wid]) ?: [];
        return array_map(static function (array $r): array {
            $p = json_decode((string) $r['payload_json'], true) ?: [];
            return $p + ['id' => (int) $r['id'], 'status' => $r['status'], 'priority' => (float) $r['priority'], 'baseline' => $r['baseline_value'] !== null ? (float) $r['baseline_value'] : null,
                'result' => $r['result_value'] !== null ? (float) $r['result_value'] : null, 'outcome' => $r['outcome'], 'proposed_at' => $r['proposed_at'], 'decided_at' => $r['decided_at'], 'done_at' => $r['done_at'], 'measured_at' => $r['measured_at']];
        }, $rows);
    }

    /** صاحب الشركة بيوافق/يرفض/يعلّم إنه اتنفّذ. القرار لازم يكون بتاعه. */
    public function setStatus(int $uid, int $wid, int $id, string $status, array $snapshot, string $lang = 'ar'): array
    {
        if (!in_array($status, ['approved', 'dismissed', 'done', 'proposed'], true)) throw new InvalidArgumentException('Invalid status');
        $row = $this->db->query('SELECT * FROM brain_decisions WHERE id = ? AND user_id = ? AND website_id = ? LIMIT 1', [$id, $uid, $wid])[0] ?? null;
        if (!$row) throw new InvalidArgumentException('Decision not found');
        $now = gmdate('Y-m-d H:i:s');
        $current = $row['metric_key'] ? StrategicDecisions::metricValue($snapshot, $row['metric_key']) : null;
        if ($status === 'approved') {
            $this->approveAndExecute($uid, $wid, $row, $snapshot, $lang);
        } elseif ($status === 'done') {
            $this->db->query('UPDATE brain_decisions SET status = ?, decided_at = COALESCE(decided_at, ?), done_at = ?, outcome = NULL, measured_at = NULL WHERE id = ? AND user_id = ?', [$status, $now, $now, $id, $uid]);
        } else {
            $this->db->query('UPDATE brain_decisions SET status = ?, decided_at = ? WHERE id = ? AND user_id = ?', [$status, $now, $id, $uid]);
        }
        return $this->list($uid, $wid);
    }

    /** أوزان المجالات من نتايج قرارات الشركة نفسها: كل تحسّن +0.1 وكل تراجع -0.1 (بين 0.5 و1.5) */
    public function learnedWeights(int $uid, int $wid): array
    {
        $w = [];
        foreach ($this->db->query("SELECT area, SUM(outcome = 'improved') AS up, SUM(outcome = 'worse') AS down FROM brain_decisions
            WHERE user_id = ? AND website_id = ? AND outcome IS NOT NULL GROUP BY area", [$uid, $wid]) ?: [] as $r) {
            $w[$r['area']] = max(0.5, min(1.5, 1 + 0.1 * ((int) $r['up'] - (int) $r['down'])));
        }
        return $w;
    }

    /** ملخص النتايج للوحة: قد إيه من القرارات اتنفّذت ونفعت */
    public function results(int $uid, int $wid): array
    {
        $r = $this->db->query("SELECT COUNT(*) AS total, SUM(status IN ('done','resolved')) AS completed, SUM(outcome = 'improved') AS improved,
            SUM(outcome = 'worse') AS worse, SUM(status = 'approved') AS in_progress FROM brain_decisions WHERE user_id = ? AND website_id = ?", [$uid, $wid])[0] ?? [];
        return array_map(static fn ($v) => (int) $v, $r);
    }
}
