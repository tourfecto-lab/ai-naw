<?php

/**
 * تسعير الباقات مقارنة بالمنافسين: الأدمن بيحدد «% أقل من المنافس» وسعر المنافس المرجعي لكل باقة، والمنصة بتحسب أعلى سعر مسموح
 * (المرجع × (100 - النسبة)). مفيش سعر بيتغيّر من هنا؛ التطبيق بيتم بزرار من الأدمن على صفحة الباقات.
 * المراجع الافتراضية من صفحات أسعار المنافسين الرسمية (تحقق 2026-10-31، docs/seo/COMPETITOR_BENCHMARK.md).
 */
final class PlanPositioning
{
    public const KEY = 'plan_price_positioning';
    public const DEFAULT_BELOW_PCT = 20;

    /** أقل منافس مماثل لكل مستوى سيو */
    public const DEFAULT_REFS = [
        'seo-essential' => ['name' => 'Ahrefs Lite', 'price' => 129.0, 'checked' => '2026-10-31'],
        'seo-growth' => ['name' => 'Semrush Starter', 'price' => 199.0, 'checked' => '2026-10-31'],
        'seo-agency' => ['name' => 'Semrush Pro+', 'price' => 299.0, 'checked' => '2026-10-31'],
    ];

    public static function config(Database $db): array
    {
        $cfg = ['below_pct' => (float) self::DEFAULT_BELOW_PCT, 'refs' => self::DEFAULT_REFS];
        try {
            $st = $db->getConnection()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $st->execute([self::KEY]);
            $saved = json_decode((string) $st->fetchColumn(), true);
            if (is_array($saved)) {
                if (isset($saved['below_pct'])) $cfg['below_pct'] = (float) $saved['below_pct'];
                if (isset($saved['refs']) && is_array($saved['refs'])) $cfg['refs'] = $saved['refs'];
            }
        } catch (Throwable $e) {
            // الإعداد اختياري؛ الافتراضي هو اللي بيتعرض
        }
        return $cfg;
    }

    /** أعلى سعر شهري مسموح لكل باقة ليها مرجع، مقرّب لأقرب دولار لتحت */
    public static function limits(array $cfg): array
    {
        $out = [];
        foreach ($cfg['refs'] as $key => $ref) {
            $price = (float) ($ref['price'] ?? 0);
            if ($price <= 0) continue;
            $out[$key] = ['ref_name' => (string) ($ref['name'] ?? ''), 'ref_price' => $price, 'max_price' => (float) floor($price * (100 - $cfg['below_pct']) / 100)];
        }
        return $out;
    }

    public static function save(Database $db, array $in, ?int $actorId): array
    {
        $pct = $in['below_pct'] ?? null;
        if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > 90) throw new InvalidArgumentException('النسبة لازم تكون بين 0 و 90');
        $refs = [];
        foreach ((array) ($in['refs'] ?? []) as $key => $ref) {
            $key = (string) $key;
            if (!preg_match('/^[a-z][a-z0-9-]{2,29}$/D', $key)) throw new InvalidArgumentException('مفتاح باقة غير صالح');
            $price = $ref['price'] ?? '';
            if ($price === '' || $price === null) continue; // مرجع فاضي = بدون مقارنة لباقة دي
            if (!is_numeric($price) || (float) $price <= 0 || (float) $price > 100000) throw new InvalidArgumentException('سعر المنافس لازم يكون رقم موجب');
            $name = trim((string) ($ref['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) throw new InvalidArgumentException('اكتب اسم المنافس (حتى 60 حرف)');
            $refs[$key] = ['name' => $name, 'price' => round((float) $price, 2), 'checked' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) ($ref['checked'] ?? '')) ? $ref['checked'] : gmdate('Y-m-d')];
        }
        $cfg = ['below_pct' => round((float) $pct, 1), 'refs' => $refs];
        BillingPlanCatalog::ensureAuditTable($db);
        $pdo = $db->getConnection();
        $before = self::config($db);
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'pricing') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([self::KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        $diff = [];
        if ((float) $before['below_pct'] !== $cfg['below_pct']) $diff['below_pct'] = ['from' => $before['below_pct'], 'to' => $cfg['below_pct']];
        foreach (array_unique(array_merge(array_keys($before['refs']), array_keys($refs))) as $k) {
            $o = $before['refs'][$k]['price'] ?? null;
            $n = $refs[$k]['price'] ?? null;
            if ((string) $o !== (string) $n) $diff['ref:' . $k] = ['from' => $o, 'to' => $n];
        }
        if ($diff) {
            $pdo->prepare('INSERT INTO plan_change_audit (plan_key, actor_id, changes_json) VALUES (?,?,?)')->execute(['_positioning', $actorId, json_encode($diff, JSON_UNESCAPED_UNICODE)]);
        }
        return $cfg;
    }
}
