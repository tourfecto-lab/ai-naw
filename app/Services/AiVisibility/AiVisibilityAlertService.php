<?php
/** Alerts on lost AI mentions/citations. Compares the two latest measured weeks per provider; never alerts on partial samples. */
final class AiVisibilityAlertService
{
    public const MIN_QUESTIONS = 5;
    public const SOV_DROP = 0.30;

    /** @return list<array{provider:string,kind:string,from:float,to:float,week:string}> */
    public static function detect(array $metrics): array
    {
        $alerts = [];
        foreach (($metrics['providers'] ?? []) as $provider => $p) {
            $weeks = array_values(array_filter($p['trend'] ?? [], static fn($w) => ($w['questions'] ?? 0) >= self::MIN_QUESTIONS && $w['share_of_voice'] !== null));
            if (count($weeks) < 2) continue;
            [$prev, $cur] = array_slice($weeks, -2);
            if ($prev['share_of_voice'] > 0 && $cur['share_of_voice'] == 0) {
                $alerts[] = ['provider' => $provider, 'kind' => 'mention_lost', 'from' => $prev['share_of_voice'], 'to' => 0.0, 'week' => $cur['week']];
            } elseif ($prev['share_of_voice'] - $cur['share_of_voice'] >= self::SOV_DROP) {
                $alerts[] = ['provider' => $provider, 'kind' => 'sov_drop', 'from' => $prev['share_of_voice'], 'to' => $cur['share_of_voice'], 'week' => $cur['week']];
            }
            if (($prev['citation_rate'] ?? 0) > 0 && ($cur['citation_rate'] ?? null) === 0.0) {
                $alerts[] = ['provider' => $provider, 'kind' => 'citation_lost', 'from' => $prev['citation_rate'], 'to' => 0.0, 'week' => $cur['week']];
            }
        }
        return $alerts;
    }

    public static function message(array $alerts): array
    {
        $names = ['openai' => 'ChatGPT', 'gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'claude' => 'Claude'];
        $kinds = ['mention_lost' => 'اسم شركتك اختفى من الإجابات', 'sov_drop' => 'نسبة ظهورك وقعت', 'citation_lost' => 'موقعك لم يعد مذكوراً كمصدر'];
        $lines = [];
        foreach ($alerts as $a) {
            $lines[] = '• ' . ($names[$a['provider']] ?? $a['provider']) . ': ' . $kinds[$a['kind']] . ' (' . round($a['from'] * 100) . '% ← ' . round($a['to'] * 100) . '%)';
        }
        return ['تنبيه: ظهورك في إجابات الذكاء الاصطناعي تراجع', implode("\n", $lines) . "\nالقياس عينة من واجهات API بالبحث على الويب، مش ترتيب تطبيقات المستخدمين. افتح صفحة ظهور AI وراجع الأسئلة والمهام المقترحة."];
    }

    public function run(int $limit = 200): array
    {
        $db = Database::getInstance();
        $svc = new AiVisibilityService($db);
        $rows = $db->getConnection()->query('SELECT p.user_id,p.website_id,p.market_id FROM seo_ai_prompt_sets p JOIN websites w ON w.id=p.website_id AND w.user_id=p.user_id WHERE p.approved_at IS NOT NULL LIMIT ' . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        foreach ($rows as $r) {
            try {
                $alerts = self::detect($svc->metrics((int) $r['user_id'], (int) $r['website_id'], (int) $r['market_id']));
                if (!$alerts) continue;
                $key = 'geo_loss_alert_' . $r['website_id'] . '_' . $r['market_id'] . '_' . max(array_column($alerts, 'week'));
                $cache = new Cache();
                if ($cache->get($key)) continue;
                $cache->set($key, 1, 15 * 86400);
                [$title, $body] = self::message($alerts);
                Notification::notify((int) $r['user_id'], 'geo_visibility_drop', $title, $body, '/ai-visibility');
                $sent++;
            } catch (Throwable $e) {
                error_log('[AiVisibilityAlertService] ' . $e->getMessage());
            }
        }
        return ['checked' => count($rows), 'alerts_sent' => $sent];
    }
}
