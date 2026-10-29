<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';
require_once __DIR__ . '/../Brain/BrainConfig.php';
require_once __DIR__ . '/PlatformDecisions.php';

/**
 * TOURFECTO AI CEO — القرارات والمهام والموافقات، والذاكرة المؤسسية، وسجل الأنشطة، والأوضاع، وزرار الطوارئ.
 *
 * حالات القرار: proposed (مقترح) · approved (اتوافق، بقى مهمة شغالة) · done (اتنفّذ) · dismissed (مش دلوقتي) · resolved (سببه اختفى).
 * الأوضاع (system_settings عن طريق BrainConfig):
 *   APPROVAL MODE (aiceo_auto = 0، الافتراضي): العقل بيجهّز ويستنى.
 *   AUTO MODE (aiceo_auto = 1): القرارات منخفضة الخطر بس (risk = low، شغل داخلي) بتتوافق لوحدها وتتحول مهمة.
 *   أي قرار medium أو high (تواصل بره المنصة، فلوس، أسعار، صلاحيات، Production) مابيتنفّذش غير بموافقة صريحة.
 * زرار الطوارئ (aiceo_paused = 1): مفيش تنفيذ تلقائي، ولا دورة مجدولة، ولا طلبات ذكاء اصطناعي من المحادثة.
 * كل تغيير بيتسجل في aiceo_activity (actor_id = NULL لما العقل نفسه هو اللي عمل).
 * الجداول دي منفصلة عن brain_decisions بتاعة العملاء؛ الكلاس ده مابيكتبش في أي جدول عميل.
 */
final class AiCeoStore
{
    public const STATUSES = ['proposed', 'approved', 'done', 'dismissed', 'resolved'];
    /** أقصى قرارات بتتوافق تلقائي في الدورة الواحدة */
    public const AUTO_PER_CYCLE = 5;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
    }

    // ------------------------------------------------------------------ الأوضاع والطوارئ

    public function settings(): array
    {
        BrainConfig::reset();
        return ['mode' => BrainConfig::get('aiceo_auto', $this->db) ? 'auto' : 'approval', 'paused' => (bool) BrainConfig::get('aiceo_paused', $this->db)];
    }

    public function setMode(string $mode, int $actor): array
    {
        if (!in_array($mode, ['auto', 'approval'], true)) throw new InvalidArgumentException('Invalid mode');
        BrainConfig::save($this->db, ['aiceo_auto' => $mode === 'auto']);
        $this->log($actor, 'mode_changed', ['mode' => $mode]);
        return $this->settings();
    }

    public function setPaused(bool $paused, int $actor, string $reason = ''): array
    {
        BrainConfig::save($this->db, ['aiceo_paused' => $paused]);
        $this->log($actor, $paused ? 'emergency_stop' : 'resumed', ['reason' => mb_substr($reason, 0, 300)]);
        return $this->settings();
    }

    // ------------------------------------------------------------------ القرارات

    /** يحفظ قرارات الصورة الحالية: الجديد مقترح، والقديم بيتحدّث، واللي سببه اختفى بيتقفل "اتحل". */
    public function sync(array $snapshot): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $decisions = PlatformDecisions::decide($snapshot);
        $seen = [];
        $existing = [];
        foreach ($this->db->query('SELECT id, decision_key, status, done_at FROM aiceo_decisions') ?: [] as $r) $existing[$r['decision_key']] = $r;
        foreach ($decisions as $d) {
            $seen[$d['key']] = true;
            $payload = json_encode($d, JSON_UNESCAPED_UNICODE);
            $row = $existing[$d['key']] ?? null;
            if (!$row) {
                $this->db->query('INSERT INTO aiceo_decisions (decision_key, area, status, risk, priority, payload_json, proposed_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$d['key'], $d['area'], 'proposed', $d['risk'], $d['priority'], $payload, $now, $now]);
                $this->log(null, 'decision_proposed', ['key' => $d['key'], 'priority' => $d['priority'], 'risk' => $d['risk']]);
            } else {
                // قرار اتقفل قبل كده ورجع سببه: بيرجع مقترح من جديد. اللي اتعلّم "اتنفّذ" بياخد 24 ساعة قبل ما يتحكم عليه
                $reopen = $row['status'] === 'resolved' || ($row['status'] === 'done' && $row['done_at'] && strtotime($row['done_at'] . ' UTC') < time() - 86400);
                $this->db->query('UPDATE aiceo_decisions SET area = ?, risk = ?, priority = ?, payload_json = ?, last_seen_at = ?' . ($reopen ? ", status = 'proposed', decided_at = NULL, decided_by = NULL, done_at = NULL, proposed_at = ?" : '') . ' WHERE id = ?',
                    array_merge([$d['area'], $d['risk'], $d['priority'], $payload, $now], $reopen ? [$now] : [], [(int) $row['id']]));
                if ($reopen) $this->log(null, 'decision_reopened', ['key' => $d['key']]);
            }
        }
        foreach ($existing as $key => $row) {
            if (isset($seen[$key]) || !in_array($row['status'], ['proposed', 'approved'], true)) continue;
            $this->db->query("UPDATE aiceo_decisions SET status = 'resolved', done_at = ? WHERE id = ?", [$now, (int) $row['id']]);
            $this->log(null, 'decision_resolved', ['key' => $key]);
            $this->remember('result', 'Resolved: ' . $key, 'The cause is no longer in the data.', (int) $row['id'], null);
        }
        return $this->list();
    }

    public function list(): array
    {
        $rows = $this->db->query("SELECT * FROM aiceo_decisions ORDER BY FIELD(status, 'approved', 'proposed', 'done', 'resolved', 'dismissed'), priority DESC, id ASC") ?: [];
        return array_map(static function (array $r): array {
            $p = json_decode((string) $r['payload_json'], true) ?: [];
            return ['id' => (int) $r['id'], 'status' => $r['status'], 'risk' => $r['risk'], 'priority' => (float) $r['priority'], 'proposed_at' => $r['proposed_at'],
                'decided_at' => $r['decided_at'], 'done_at' => $r['done_at'], 'decided_by' => $r['decided_by'] !== null ? (int) $r['decided_by'] : null] + $p;
        }, $rows);
    }

    /** صاحب المنصة بيوافق/يأجّل/يعلّم إنه اتنفّذ/يرجّع للمقترحات */
    public function setStatus(int $id, string $status, int $actor, string $note = ''): array
    {
        if (!in_array($status, ['approved', 'dismissed', 'done', 'proposed'], true)) throw new InvalidArgumentException('Invalid status');
        $row = $this->db->query('SELECT * FROM aiceo_decisions WHERE id = ? LIMIT 1', [$id])[0] ?? null;
        if (!$row) throw new InvalidArgumentException('Decision not found');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query('UPDATE aiceo_decisions SET status = ?, decided_at = ?, decided_by = ?, done_at = ? WHERE id = ?',
            [$status, $status === 'proposed' ? null : $now, $status === 'proposed' ? null : $actor, $status === 'done' ? $now : null, $id]);
        $this->log($actor, 'decision_' . $status, ['key' => $row['decision_key'], 'risk' => $row['risk'], 'note' => mb_substr($note, 0, 300)]);
        $title = (json_decode((string) $row['payload_json'], true)['title']['en'] ?? $row['decision_key']);
        if ($status === 'approved') $this->remember('decision', 'Approved: ' . $title, $note ?: null, $id, $actor);
        if ($status === 'done') $this->remember('result', 'Done: ' . $title, $note ?: null, $id, $actor);
        return $this->list();
    }

    /**
     * AUTO MODE: يوافق لوحده على القرارات منخفضة الخطر بس (شغل داخلي). مش بيلمس medium/high أبدًا،
     * ومش بيشتغل لو زرار الطوارئ مضغوط. بيرجع عدد اللي اتوافق عليه.
     */
    public function autoExecute(): int
    {
        $s = $this->settings();
        if ($s['paused'] || $s['mode'] !== 'auto') return 0;
        $rows = $this->db->query("SELECT id, decision_key, payload_json FROM aiceo_decisions WHERE status = 'proposed' AND risk = 'low' ORDER BY priority DESC LIMIT " . self::AUTO_PER_CYCLE) ?: [];
        $now = gmdate('Y-m-d H:i:s');
        $n = 0;
        foreach ($rows as $r) {
            $p = json_decode((string) $r['payload_json'], true) ?: [];
            if (empty($p['auto_ok'])) continue;
            $this->db->query("UPDATE aiceo_decisions SET status = 'approved', decided_at = ?, decided_by = NULL WHERE id = ? AND status = 'proposed' AND risk = 'low'", [$now, (int) $r['id']]);
            $this->log(null, 'decision_auto_approved', ['key' => $r['decision_key']]);
            $this->remember('decision', 'Auto-approved (low risk): ' . ($p['title']['en'] ?? $r['decision_key']), null, (int) $r['id'], null);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ الذاكرة والسجل

    public function remember(string $kind, string $title, ?string $body, ?int $decisionId, ?int $actor): void
    {
        if (!in_array($kind, ['decision', 'plan', 'result', 'note', 'chat'], true)) throw new InvalidArgumentException('Invalid memory kind');
        $this->db->query('INSERT INTO aiceo_memory (kind, title, body, decision_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$kind, mb_substr($title, 0, 255), $body !== null ? mb_substr($body, 0, 20000) : null, $decisionId, $actor, gmdate('Y-m-d H:i:s')]);
    }

    public function memory(int $limit = 30, ?string $kind = null): array
    {
        $limit = max(1, min(200, $limit));
        return $kind ? ($this->db->query('SELECT * FROM aiceo_memory WHERE kind = ? ORDER BY id DESC LIMIT ' . $limit, [$kind]) ?: [])
            : ($this->db->query('SELECT * FROM aiceo_memory ORDER BY id DESC LIMIT ' . $limit) ?: []);
    }

    public function log(?int $actor, string $action, array $detail = []): void
    {
        $this->db->query('INSERT INTO aiceo_activity (actor_id, action, detail_json, created_at) VALUES (?, ?, ?, ?)',
            [$actor, mb_substr($action, 0, 60), $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, gmdate('Y-m-d H:i:s')]);
    }

    public function activity(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'actor_id' => $r['actor_id'] !== null ? (int) $r['actor_id'] : null, 'action' => $r['action'],
            'detail' => json_decode((string) $r['detail_json'], true), 'created_at' => $r['created_at']], $this->db->query('SELECT * FROM aiceo_activity ORDER BY id DESC LIMIT ' . $limit) ?: []);
    }
}
