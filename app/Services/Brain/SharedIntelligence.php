<?php
require_once __DIR__ . '/BrainSchema.php';

/**
 * Tourfecto - الذكاء المشترك بين الشركات (من غير هوية وبموافقة)
 *
 * بيتعلّم من نتايج قرارات العقل عند كل الشركات اللي وافقت على المشاركة: نوع القرار ده نفع عند كام في المية.
 * الحماية:
 * - المشاركة اختيارية ومقفولة افتراضيًا (brain_sharing.opted_in). الشركة اللي ماوافقتش نتايجها مابتدخلش،
 *   ومابتشوفش الأرقام المجمّعة.
 * - أي رقم مجمّع مابيطلعش غير لو جاي من MIN_COMPANIES شركات مختلفة أو أكتر (k-anonymity).
 * - بيرجع نوع القرار (عائلته من غير رقم الرحلة) ونسب بس: مفيش اسم ولا موقع ولا رقم مطلق لأي شركة.
 */
final class SharedIntelligence
{
    public const MIN_COMPANIES = 5;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
    }

    /** نوع القرار من غير الجزء الخاص بالشركة: promote_peak_12 → promote_peak */
    public static function family(string $key): string
    {
        return (string) preg_replace('/_\d+$/', '', $key);
    }

    public function optedIn(int $uid): bool
    {
        return (bool) ($this->db->query('SELECT opted_in FROM brain_sharing WHERE user_id = ? LIMIT 1', [$uid])[0]['opted_in'] ?? false);
    }

    public function setOptIn(int $uid, bool $on): void
    {
        $this->db->query('INSERT INTO brain_sharing (user_id, opted_in, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE opted_in = VALUES(opted_in), updated_at = VALUES(updated_at)', [$uid, $on ? 1 : 0]);
    }

    /**
     * نتايج كل نوع قرار عند الشركات المشارِكة، للأنواع اللي فيها MIN_COMPANIES شركات أو أكتر بس
     * @return array<string,array{companies:int,measured:int,improved_rate:float}>
     */
    public function patterns(): array
    {
        $rows = $this->db->query("SELECT d.user_id, d.decision_key, d.outcome FROM brain_decisions d
            JOIN brain_sharing s ON s.user_id = d.user_id AND s.opted_in = 1
            WHERE d.outcome IS NOT NULL") ?: [];
        return self::aggregate($rows);
    }

    /** منطق التجميع نقي عشان يتختبر من غير قاعدة بيانات */
    public static function aggregate(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $f = self::family((string) $r['decision_key']);
            $by[$f]['users'][(int) $r['user_id']] = true;
            $by[$f]['n'] = ($by[$f]['n'] ?? 0) + 1;
            $by[$f]['up'] = ($by[$f]['up'] ?? 0) + ($r['outcome'] === 'improved' ? 1 : 0);
        }
        $out = [];
        foreach ($by as $f => $x) {
            $companies = count($x['users']);
            if ($companies < self::MIN_COMPANIES) continue;
            $out[$f] = ['companies' => $companies, 'measured' => (int) $x['n'], 'improved_rate' => round($x['up'] / max(1, $x['n']), 2)];
        }
        ksort($out);
        return $out;
    }

    /** وزن لنوع القرار من نسبة نجاحه عند الشركات التانية: من 0.8 (مانفعش غالبًا) لـ 1.2 (نفع غالبًا) */
    public static function weight(array $pattern): float
    {
        return round(0.8 + 0.4 * max(0.0, min(1.0, (float) $pattern['improved_rate'])), 2);
    }
}
