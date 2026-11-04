<?php
require_once __DIR__ . '/BrainSchema.php';
require_once __DIR__ . '/BrainPlanner.php';
require_once __DIR__ . '/StrategicDecisions.php';

/**
 * Tourfecto - العقل الاستراتيجي: الأهداف، السياسة، التفضيلات، الذاكرة، وسجل التدقيق (طبقة قاعدة البيانات)
 *
 * كل حاجة مقيّدة بـ user_id (والموقع لازم يكون بتاع المستخدم من الكنترولر). لو جداول الإضافات مش متاحة
 * (السيرفر مارضيش DDL) كل القراءات بترجّع قيم افتراضية وكل الكتابات بتتجاهل: العقل الأساسي مايتأثرش.
 */
final class BrainControl
{
    public const MAX_ACTIVE_GOALS = 5;
    public const REASONS = ['not_relevant', 'later', 'done_elsewhere'];
    public const SNOOZE_DAYS = 14;
    public const MODES = ['auto', 'suggest'];

    private Database $db;
    private bool $ok;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ok = BrainSchema::ensureExtras($this->db);
    }

    public function available(): bool
    {
        return $this->ok;
    }

    // ---------- سجل التدقيق ----------

    public function log(int $uid, int $wid, ?string $key, string $event, ?string $detail = null): void
    {
        if (!$this->ok) return;
        try {
            $this->db->query('INSERT INTO brain_audit (user_id, website_id, decision_key, event, detail, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$uid, $wid, $key, substr($event, 0, 30), $detail === null ? null : mb_substr($detail, 0, 255)]);
        } catch (Throwable $e) {
            // السجل مايوقفش العقل
        }
    }

    public function recent(int $uid, int $wid, int $limit = 25): array
    {
        if (!$this->ok) return [];
        try {
            return $this->db->query('SELECT id, decision_key, event, detail, created_at FROM brain_audit WHERE user_id = ? AND website_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$uid, $wid]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---------- السياسة لكل مجال ----------

    /** @return array<string,string> المجال ← auto|suggest (كل المجالات، الافتراضي auto) */
    public function areaModes(int $uid): array
    {
        $out = array_fill_keys(StrategicDecisions::AREAS, 'auto');
        if (!$this->ok) return $out;
        try {
            foreach ($this->db->query('SELECT area, mode FROM brain_policy WHERE user_id = ?', [$uid]) ?: [] as $r) {
                if (isset($out[$r['area']]) && in_array($r['mode'], self::MODES, true)) $out[$r['area']] = $r['mode'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** المجالات اللي الشركة قافلة التنفيذ التلقائي فيها (اقتراح بس) */
    public function suggestOnlyAreas(int $uid): array
    {
        return array_keys(array_filter($this->areaModes($uid), static fn ($m) => $m === 'suggest'));
    }

    public function setAreaMode(int $uid, string $area, string $mode): void
    {
        if (!in_array($area, StrategicDecisions::AREAS, true)) throw new InvalidArgumentException('Unknown area');
        if (!in_array($mode, self::MODES, true)) throw new InvalidArgumentException('Invalid mode');
        if (!$this->ok) throw new RuntimeException('unavailable');
        $this->db->query('INSERT INTO brain_policy (user_id, area, mode, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE mode = VALUES(mode), updated_at = VALUES(updated_at)', [$uid, $area, $mode]);
        $this->log($uid, 0, null, 'policy', $area . '=' . $mode);
    }

    // ---------- التفضيلات ----------

    /** @return array{weekly_capacity:int,digest_enabled:bool,digest_sent_at:?string} */
    public function prefs(int $uid): array
    {
        $r = [];
        if ($this->ok) {
            try { $r = $this->db->query('SELECT weekly_capacity, digest_enabled, digest_sent_at FROM brain_prefs WHERE user_id = ? LIMIT 1', [$uid])[0] ?? []; } catch (Throwable $e) { $r = []; }
        }
        return ['weekly_capacity' => BrainPlanner::capacity($r['weekly_capacity'] ?? 0), 'digest_enabled' => !empty($r['digest_enabled']), 'digest_sent_at' => $r['digest_sent_at'] ?? null];
    }

    public function setPrefs(int $uid, ?int $capacity, ?bool $digest): array
    {
        if (!$this->ok) throw new RuntimeException('unavailable');
        $cur = $this->prefs($uid);
        $cap = $capacity === null ? $cur['weekly_capacity'] : BrainPlanner::capacity($capacity);
        $dig = $digest === null ? $cur['digest_enabled'] : $digest;
        $this->db->query('INSERT INTO brain_prefs (user_id, weekly_capacity, digest_enabled, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE weekly_capacity = VALUES(weekly_capacity), digest_enabled = VALUES(digest_enabled), updated_at = VALUES(updated_at)', [$uid, $cap, $dig ? 1 : 0]);
        return $this->prefs($uid);
    }

    public function markDigestSent(int $uid): void
    {
        if ($this->ok) $this->db->query('UPDATE brain_prefs SET digest_sent_at = UTC_TIMESTAMP() WHERE user_id = ?', [$uid]);
    }

    // ---------- الذاكرة ----------

    /** الشركة رفضت القرار: السبب بيحدد هل بيرجع بعد فترة (later) ولا لأ */
    public function remember(int $uid, int $wid, string $key, ?string $reason): void
    {
        if (!$this->ok || $reason === null || !in_array($reason, self::REASONS, true)) return;
        try {
            $snooze = $reason === 'later' ? gmdate('Y-m-d H:i:s', time() + self::SNOOZE_DAYS * 86400) : null;
            $this->db->query('INSERT INTO brain_memory (user_id, website_id, decision_key, reason, snooze_until, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), snooze_until = VALUES(snooze_until), created_at = VALUES(created_at)', [$uid, $wid, $key, $reason, $snooze]);
        } catch (Throwable $e) {
        }
    }

    public function forget(int $uid, int $wid, string $key): void
    {
        if (!$this->ok) return;
        try { $this->db->query('DELETE FROM brain_memory WHERE user_id = ? AND website_id = ? AND decision_key = ?', [$uid, $wid, $key]); } catch (Throwable $e) {
        }
    }

    /** مفاتيح القرارات اللي مايدخلوش الخطة: مرفوضة بسبب دايم، أو مؤجلة لسه */
    public function excludedKeys(int $uid, int $wid): array
    {
        if (!$this->ok) return [];
        try {
            return array_column($this->db->query("SELECT decision_key FROM brain_memory WHERE user_id = ? AND website_id = ? AND (snooze_until IS NULL OR snooze_until > UTC_TIMESTAMP())", [$uid, $wid]) ?: [], 'decision_key');
        } catch (Throwable $e) {
            return [];
        }
    }

    // ---------- الأهداف ----------

    /**
     * @param array $snapshot صورة الشركة (المقياس الحالي بيتقرأ منها وبيبقى خط الأساس)
     * @return int id الهدف
     */
    public function createGoal(int $uid, int $wid, string $metric, $target, string $deadline, array $snapshot): int
    {
        $m = BrainPlanner::metric($metric);
        if (!$m) throw new InvalidArgumentException('Unknown goal metric');
        if (!$this->ok) throw new RuntimeException('unavailable');
        if (!is_numeric($target) || !is_finite((float) $target) || abs((float) $target) > 1e9) throw new InvalidArgumentException('Invalid target');
        $target = (float) $target;
        $ts = strtotime($deadline . ' 00:00:00 UTC');
        if (!$ts || gmdate('Y-m-d', $ts) !== $deadline || $ts <= time() || $ts > time() + 366 * 86400) throw new InvalidArgumentException('Deadline must be within the next year');
        $base = StrategicDecisions::metricValue($snapshot, $metric);
        if ($base === null) throw new InvalidArgumentException('This metric is not measured yet for your business');
        $up = $m[0] === 'up';
        if ($up ? $target <= $base : $target >= $base) throw new InvalidArgumentException($up ? 'Target must be higher than the current value' : 'Target must be lower than the current value');
        $active = (int) ($this->db->query("SELECT COUNT(*) AS c FROM brain_goals WHERE user_id = ? AND website_id = ? AND status = 'active'", [$uid, $wid])[0]['c'] ?? 0);
        if ($active >= self::MAX_ACTIVE_GOALS) throw new InvalidArgumentException('Too many active goals');
        if ((int) ($this->db->query("SELECT COUNT(*) AS c FROM brain_goals WHERE user_id = ? AND website_id = ? AND status = 'active' AND metric_key = ?", [$uid, $wid, $metric])[0]['c'] ?? 0) > 0) {
            throw new InvalidArgumentException('You already have an active goal on this metric');
        }
        $this->db->query('INSERT INTO brain_goals (user_id, website_id, metric_key, direction, target_value, baseline_value, deadline, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', UTC_TIMESTAMP())',
            [$uid, $wid, $metric, $m[0], $target, $base, $deadline]);
        $id = (int) $this->db->getConnection()->lastInsertId();
        $this->log($uid, $wid, null, 'goal_set', $metric . ' → ' . $target);
        return $id;
    }

    public function cancelGoal(int $uid, int $wid, int $id): void
    {
        if (!$this->ok) throw new RuntimeException('unavailable');
        $this->db->query("UPDATE brain_goals SET status = 'cancelled', closed_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND website_id = ? AND status = 'active'", [$id, $uid, $wid]);
        $this->log($uid, $wid, null, 'goal_cancelled', (string) $id);
    }

    /**
     * أهداف الموقع (النشطة + آخر المقفولة) بتقدمها. الهدف اللي اتحقق أو فات ميعاده بيتقفل هنا.
     * @return array<int,array<string,mixed>>
     */
    public function goals(int $uid, int $wid, array $snapshot): array
    {
        if (!$this->ok) return [];
        try {
            $rows = $this->db->query("SELECT * FROM brain_goals WHERE user_id = ? AND website_id = ? AND (status = 'active' OR closed_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)) ORDER BY FIELD(status, 'active', 'achieved', 'missed', 'cancelled'), deadline ASC, id ASC", [$uid, $wid]) ?: [];
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $progress = BrainPlanner::goalProgress($r, StrategicDecisions::metricValue($snapshot, (string) $r['metric_key']));
            $status = $r['status'];
            if ($status === 'active' && in_array($progress['status'], ['achieved', 'missed'], true)) {
                $status = $progress['status'];
                try {
                    $this->db->query('UPDATE brain_goals SET status = ?, closed_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?', [$status, (int) $r['id'], $uid]);
                    $this->log($uid, $wid, null, 'goal_' . $status, (string) $r['metric_key']);
                } catch (Throwable $e) {
                }
            }
            $out[] = ['id' => (int) $r['id'], 'metric_key' => $r['metric_key'], 'direction' => $r['direction'], 'target' => (float) $r['target_value'], 'baseline' => (float) $r['baseline_value'],
                'deadline' => $r['deadline'], 'status' => $status, 'created_at' => $r['created_at'], 'progress' => $progress];
        }
        return $out;
    }
}
