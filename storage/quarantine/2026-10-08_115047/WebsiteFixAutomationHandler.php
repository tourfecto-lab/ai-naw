<?php

/**
 * تطبيق إصلاح موقع متوافق عليه. نوعين:
 *  - embed: موقع العميل مربوط بكود Auto SEO -> بنحقن الإصلاح عن طريق embed.js
 *  - builder: موقع معمول بمنشئ المواقع بتاعنا -> بنكتب القيمة في الموقع نفسه
 * @version 1.0.0
 */
class WebsiteFixAutomationHandler implements AutomationHandler
{
    public function execute(array $decision, string $finalText): array
    {
        $payload = json_decode((string) ($decision['payload'] ?? ''), true) ?: [];
        $userId = (int) $decision['user_id'];
        $db = Database::getInstance();

        if (($payload['kind'] ?? '') === 'embed') {
            $finding = $payload['finding'] ?? [];
            $websiteId = (int) ($payload['website_id'] ?? $decision['website_id'] ?? 0);
            $owns = $db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $userId]);
            if (empty($owns) || empty($finding)) {
                return ['success' => false, 'error' => 'الموقع أو الإصلاح مش موجود'];
            }
            $res = (new AutoSeoEmbedService($db))->applyFix($userId, $websiteId, $finding, 'automation_center', 'approval');
            return ['success' => !empty($res['success']), 'error' => $res['error'] ?? null];
        }

        if (($payload['kind'] ?? '') === 'builder') {
            $fixId = (int) ($payload['fix_id'] ?? 0);
            $siteId = (int) ($payload['generated_website_id'] ?? 0);
            $rows = $db->query(
                'SELECT f.* FROM wo_fixes f INNER JOIN wo_audits a ON a.id = f.audit_id WHERE f.id = ? AND a.user_id = ? LIMIT 1',
                [$fixId, $userId]
            );
            if (empty($rows)) {
                return ['success' => false, 'error' => 'الإصلاح مش موجود'];
            }
            return self::applyBuilderFix($rows[0], $siteId, 'automation_center');
        }

        return ['success' => false, 'error' => 'نوع إصلاح غير معروف'];
    }

    /** نفس منطق WebsiteOptimizerController::applyFixInternal بالظبط */
    public static function applyBuilderFix(array $fix, int $generatedWebsiteId, string $trigger): array
    {
        $db = Database::getInstance();
        if (empty($fix['suggested_value'])) {
            return ['success' => false, 'error' => 'مفيش قيمة جاهزة للتطبيق'];
        }
        if (($fix['status'] ?? '') === 'applied') {
            return ['success' => true, 'note' => 'already_applied'];
        }
        $columnMap = ['title_tag' => 'seo_title', 'meta_description' => 'seo_description'];
        $column = $columnMap[$fix['check_key'] ?? ''] ?? null;
        if (!$column) {
            return ['success' => false, 'error' => 'نوع الإصلاح ده مش مدعوم للتطبيق التلقائي'];
        }
        $before = $db->query("SELECT {$column} AS val FROM generated_websites WHERE id = ?", [$generatedWebsiteId]);
        $oldValue = $before[0]['val'] ?? null;
        $db->exec("UPDATE generated_websites SET {$column} = ? WHERE id = ?", [$fix['suggested_value'], $generatedWebsiteId]);
        $db->exec("UPDATE wo_fixes SET status = 'applied', applied_at = NOW(), applied_by = 'auto_pilot' WHERE id = ?", [$fix['id']]);
        try {
            $db->exec(
                'INSERT INTO auto_pilot_change_log (generated_website_id, fix_id, field_name, old_value, new_value, `trigger`) VALUES (?, ?, ?, ?, ?, ?)',
                [$generatedWebsiteId, $fix['id'], $column, $oldValue, $fix['suggested_value'], $trigger]
            );
        } catch (Throwable $e) {
        }
        return ['success' => true];
    }
}
