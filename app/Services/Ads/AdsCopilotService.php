<?php

/**
 * Tourfecto - Enterprise Ads Copilot Service
 * @version 2.0.0
 *
 * مساعد ذكاء اصطناعي تنفيذي واستشاري لإدارة الإعلانات:
 * 1. إصلاح حساب change_pct لضمان عدم تجاوز Guardrails في الأوامر المباشرة من الشات.
 * 2. استثناء معرّفات الحملات (#ID) من الاختلاط مع مبالغ الميزانية المطلوبة.
 * 3. دعم أوامر التراجع (Rollback) وإيقاف/تشغيل الحملات عبر الشات.
 * 4. إثراء سياق المحادثة بالتنبيهات النشطة والقرارات المعلّقة.
 */
class AdsCopilotService
{
    /** @var GeminiClient */
    private $ai;

    private Database $db;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->db = Database::getInstance();
    }

    /**
     * @return array{reply: string}
     */
    public function ask(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['reply' => 'اكتب سؤال أو أمر واضح، مثل: "إيه أداء حملاتي آخر أسبوع؟" أو "زود ميزانية حملة رحلات البحر لـ 50"'];
        }

        // 1) محاولة فهم أمر تنفيذي صريح (تعديل ميزانية / إيقاف أو تشغيل / تراجع Rollback)
        $command = $this->parseCommand($userId, $message);
        if ($command !== null) {
            $reply = $this->executeCommand($userId, $command);
            return ['reply' => $reply];
        }

        // 2) رد استشاري ذكي مبني على البيانات الحقيقية للحساب
        return ['reply' => $this->answerQuestion($userId, $message)];
    }

    // ================================================================
    // تنفيذ الأوامر الصريحة عبر مسار الـ Autopilot نفسه
    // ================================================================

    private function executeCommand(int $userId, array $command): string
    {
        $engine = new AdAutopilotEngine();

        if ($command['type'] === 'rollback') {
            $logId = (int) $command['log_id'];
            $res = $engine->rollback($userId, $logId);
            if (($res['status'] ?? '') === 'executed') {
                return "تم التراجع (Rollback) عن التعديل رقم #{$logId} بنجاح وإعادة الميزانية إلى {$res['after']} يوميًا.";
            }
            return "تعذّر التراجع عن التعديل رقم #{$logId} (قد يكون تم التراجع عنه مسبقًا أو غير قابل للتراجع).";
        }

        $campaign = $command['campaign'];

        if ($command['type'] === 'toggle_status') {
            $newStatus = $command['new_status'];
            $statusResult = $this->changeStatusManually($userId, $campaign, $newStatus);
            return $this->formatStatusReply($campaign, $newStatus, $statusResult);
        }

        $rec = $command['recommendation'];
        $result = $engine->applyExplicitRecommendation($userId, $campaign, $rec);

        return $this->formatBudgetReply($campaign, $rec, $result);
    }

    private function formatBudgetReply(AdCampaign $campaign, array $rec, array $result): string
    {
        $name = (string) $campaign->getAttribute('name');
        $reasoning = (string) ($rec['reasoning'] ?? '');

        $actionLabel = $rec['action_type'] === 'increase_budget' ? 'زيادة الميزانية' : 'تخفيض الميزانية';

        switch ($result['status'] ?? '') {
            case 'executed':
                $after = $result['after'] ?? $rec['after_daily_budget'];
                return "تم {$actionLabel} لحملة \"{$name}\" فعليًا إلى {$after} يوميًا عبر المنصة.\n\nملاحظة من التحليل: {$reasoning}";
            case 'pending_approval':
                $blocked = !empty($result['blocked_reason']) ? "\n\nالسبب: {$result['blocked_reason']}" : '';
                return "اقترح {$actionLabel} لحملة \"{$name}\" إلى {$rec['after_daily_budget']} يوميًا، وبما إن وضع الـAutopilot عندك \"موافقة\" أو القرار تجاوز حد آمن، اتحوّل لانتظار موافقتك من صفحة الـAutopilot.{$blocked}";
            case 'recommendation_only':
                return "حسب إعداداتك (الوضع اليدوي)، مقدرش أنفّذ تغيير ميزانية تلقائيًا. التوصية لحملة \"{$name}\": {$rec['after_daily_budget']} يوميًا بدل {$rec['current_daily_budget']}.\n\nالسبب: {$reasoning}\n\nلو عايزني أنفّذ تلقائيًا، فعّل وضع الـAutopilot من إعداداته.";
            default:
                return "التوصية لحملة \"{$name}\": {$rec['after_daily_budget']} يوميًا ({$reasoning}). لو حصل خطأ في التنفيذ الفعلي، راجع سجل الـAutopilot للتفاصيل.";
        }
    }

    private function formatStatusReply(AdCampaign $campaign, string $newStatus, array $result): string
    {
        $name = (string) $campaign->getAttribute('name');
        if ($result['success']) {
            return "تم {$this->statusLabel($newStatus)} حملة \"{$name}\" بنجاح على المنصة.";
        }
        return "تعذّر {$this->statusLabel($newStatus)} حملة \"{$name}\": " . ($result['error'] ?? 'خطأ غير معروف');
    }

    private function statusLabel(string $status): string
    {
        return $status === 'paused' ? 'إيقاف' : 'تشغيل';
    }

    private function changeStatusManually(int $userId, AdCampaign $campaign, string $newStatus): array
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return ['success' => false, 'error' => 'الحملة دي لسه مش متزامنة مع منصة حقيقية'];
        }

        $conn = (new PlatformConnection())->find((int) $connId);
        if (!$conn || $conn->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الربط بالمنصة غير متاح'];
        }

        $encryption = new Encryption();
        $accessToken = $encryption->decrypt((string) $conn->getAttribute('access_token'));
        $platform = (string) $conn->getAttribute('platform');

        try {
            if ($platform === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $newStatus === 'paused' ? $api->pauseCampaign($externalId) : $api->resumeCampaign($externalId);
            } elseif ($platform === 'google_ads') {
                $api = new GoogleAdsAPI($accessToken);
                $customerId = (string) $conn->getAttribute('external_account_id');
                $resource = "customers/{$customerId}/campaigns/{$externalId}";
                $result = $newStatus === 'paused' ? $api->pauseCampaign($customerId, $resource) : $api->resumeCampaign($customerId, $resource);
            } else {
                return ['success' => false, 'error' => "منصة غير مدعومة: {$platform}"];
            }

            if (!($result['success'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'فشل التنفيذ على المنصة'];
            }

            $previousStatus = (string) $campaign->getAttribute('status');
            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'user_id' => $userId,
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign',
                'mode' => 'manual',
                'description' => 'أمر صريح من العميل عبر AI Copilot',
                'before_value' => $previousStatus,
                'after_value' => $newStatus,
                'applied_automatically' => 1,
                'external_result' => 'success',
                'can_rollback' => 1,
            ]);
            $log->save();

            return ['success' => true];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('AdsCopilotService changeStatusManually Error', ['message' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => 'تعذر التنفيذ'];
        }
    }

    // ================================================================
    // فهم الأوامر الصريحة بدقة عالية (Unicode-Safe)
    // ================================================================

    private function parseCommand(int $userId, string $message): ?array
    {
        // دعم أمر التراجع الصريح: "تراجع عن #15" أو "rollback #15"
        if (preg_match('/(?:تراجع|ارجع|rollback)\s*(?:عن\s*)?(?:تعديل\s*)?#?(\d+)/iu', $message, $rbMatch)) {
            return ['type' => 'rollback', 'log_id' => (int) $rbMatch[1]];
        }

        $campaigns = (new AdCampaign())->where(['user_id' => $userId]);

        $target = $this->findTargetCampaign($campaigns, $message);
        if ($target === null) {
            return null;
        }
        $campaign = $target;

        // إزالة معرّف الحملة (#123) من النص مؤقتاً عند استخراج أرقام الميزانية حتى لا يختلط رقم الحملة بالميزانية
        $messageWithoutId = preg_replace('/#\d+/', '', $message) ?? $message;
        preg_match_all('/(?<!\w)(\d+(?:[.,]\d+)?)(?!\w)/u', $messageWithoutId, $matches);
        $numbers = array_map('floatval', str_replace(',', '.', $matches[1] ?? []));

        $currentBudget = (float) ($campaign->getAttribute('daily_budget') ?: 0);

        // إيقاف / تشغيل الحملة
        $hasBudgetWord = (bool) preg_match('/(ميزانية|ميزانيه|budget|زود|خفّض|خفض|اكتر|أكثر|اقل|أقل)/iu', $message);
        if (!$hasBudgetWord && preg_match('/(اوقف|وقّف|وقف|أوقف|ايقاف|إيقاف|pause|stop)/iu', $message)) {
            return ['type' => 'toggle_status', 'campaign' => $campaign, 'new_status' => 'paused'];
        }
        if (!$hasBudgetWord && preg_match('/(شغّل|شغل|فعّل|فعل|استأنف|start|resume|enable)/iu', $message)) {
            return ['type' => 'toggle_status', 'campaign' => $campaign, 'new_status' => 'active'];
        }

        // تعديل الميزانية
        $increase = (bool) preg_match('/(زود|زودي|زيّد|زيد|ارفع|كبّر|زيادة|increase|raise|اكثر|أكثر)/iu', $message);
        $decrease = (bool) preg_match('/(خفّض|خفض|قلّل|قلل|اقلل|نقص|نقّص|انقص|تخفيض|انخفاض|decrease|reduce|lower|اقل|أقل)/iu', $message);

        $targetBudget = null;
        foreach ($numbers as $n) {
            if ($n > 0 && $n < 1000000) {
                $targetBudget = $n;
                break;
            }
        }

        if ($increase || $decrease) {
            if ($targetBudget === null) {
                $pct = $increase ? 0.15 : 0.25;
                $targetBudget = $increase
                    ? $currentBudget + ($currentBudget * $pct)
                    : max(0.0, $currentBudget - ($currentBudget * $pct));
            }

            $actionType = ($targetBudget > $currentBudget) ? 'increase_budget' : 'decrease_budget';
            $after = round($targetBudget, 2);
            $changePct = $currentBudget > 0
                ? round((abs($after - $currentBudget) / $currentBudget) * 100, 1)
                : 100.0;

            return [
                'type' => 'budget',
                'campaign' => $campaign,
                'recommendation' => [
                    'action_type' => $actionType,
                    'current_daily_budget' => $currentBudget,
                    'after_daily_budget' => $after,
                    'change_pct' => $changePct,
                    'reasoning' => 'طلب صريح من العميل عبر AI Copilot: ' . $message,
                    'confidence_level' => 'confirmed_signal',
                ],
            ];
        }

        return null;
    }

    private function findTargetCampaign(array $campaigns, string $message): ?AdCampaign
    {
        if (preg_match('/#(\d+)/', $message, $m)) {
            foreach ($campaigns as $c) {
                if ((string) $c->getAttribute('id') === $m[1]) {
                    return $c;
                }
            }
        }

        $best = null;
        $bestLen = 0;
        foreach ($campaigns as $c) {
            $name = (string) $c->getAttribute('name');
            if ($name === '') {
                continue;
            }
            if (mb_stripos($message, $name) !== false && mb_strlen($name) > $bestLen) {
                $best = $c;
                $bestLen = mb_strlen($name);
            }
        }

        return $best;
    }

    // ================================================================
    // الأسئلة الاستشارية العامة
    // ================================================================

    private function answerQuestion(int $userId, string $message): string
    {
        $summary = $this->buildAccountSummary($userId);

        $prompt = <<<PROMPT
انت مساعد إعلانات خبير لصاحب عمل سياحي على منصة Tourfecto. أجب عن سؤال العميل بوضوح وبالعربي المصري الاحترافي، معتمدًا على بياناته الحقيقية دي:

{$summary}

سؤال العميل: "{$message}"

القواعد:
- لو السؤال عن أداء رقمي، استخدم الأرقام الفعلية فوق ولو لسه مفيش بيانات كافية قولها صراحة بدل ما تخترع أرقام.
- لو العميل بيطلب تنفيذ تغيير (تعديل ميزانية/إيقاف/تشغيل) على حملة، قوله يذكر اسم الحملة بوضوح أو يستخدم أمر صريح زي "زود ميزانية حملة X لـ 50".
- الرد 3 إلى 8 جمل، عملي ومباشر، من غير أرقام مختلقة.
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task' => 'ads_copilot', 'maxOutputTokens' => 2048]);
        if (!($response['success'] ?? false)) {
            return 'تعذر الاتصال بمحرك الذكاء الاصطناعي حاليًا - جرّب تاني بعد قليل.';
        }

        $text = trim((string) ($response['data'] ?? ''));
        return $text !== '' ? mb_substr($text, 0, 3000) : 'تعذر توليد الرد حاليًا.';
    }

    private function buildAccountSummary(int $userId): string
    {
        $rows = $this->db->query(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    COALESCE(SUM(spend), 0) AS total_spend, COALESCE(SUM(impressions), 0) AS total_impressions,
                    COALESCE(SUM(clicks), 0) AS total_clicks
             FROM ad_campaigns WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );
        $row = $rows[0] ?? [];

        $campaignRows = $this->db->query(
            "SELECT id, name, status, daily_budget, spend, impressions, clicks
             FROM ad_campaigns WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );

        $campaignLines = [];
        foreach ($campaignRows as $c) {
            $campaignLines[] = "- #{$c['id']} {$c['name']} (الحالة: {$c['status']}, ميزانية يومية: {$c['daily_budget']}, إنفاق: {$c['spend']}, ظهور: {$c['impressions']}, نقرات: {$c['clicks']})";
        }
        $campaignsText = !empty($campaignLines) ? implode("\n", $campaignLines) : '- لا توجد حملات بعد';

        $reportRows = $this->db->query(
            "SELECT COALESCE(SUM(r.spend),0) AS spend, COALESCE(SUM(r.conversions),0) AS conversions,
                    COALESCE(SUM(r.revenue),0) AS revenue
             FROM ad_performance_reports r JOIN ad_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND c.deleted_at IS NULL",
            [$userId]
        );
        $rep = $reportRows[0] ?? [];

        $pendingRows = $this->db->query(
            "SELECT COUNT(*) AS c FROM ad_pending_actions WHERE user_id = ? AND status = 'pending'",
            [$userId]
        );
        $pendingCount = (int) ($pendingRows[0]['c'] ?? 0);

        return "ملخص حساب المستخدم:
- عدد الحملات: " . (int) ($row['total'] ?? 0) . " (منها نشطة: " . (int) ($row['active'] ?? 0) . ")
- إجمالي الإنفاق المسجل: " . (float) ($row['total_spend'] ?? 0) . "
- إجمالي الظهور: " . (float) ($row['total_impressions'] ?? 0) . " | إجمالي النقرات: " . (float) ($row['total_clicks'] ?? 0) . "
- أداء مُزامن (آخر تقارير): إنفاق " . (float) ($rep['spend'] ?? 0) . " | تحويلات " . (float) ($rep['conversions'] ?? 0) . " | إيراد " . (float) ($rep['revenue'] ?? 0) . "
- قرارات معلّقة بانتظار موافقة العميل في Autopilot: {$pendingCount}

الحملات:
{$campaignsText}

ملاحظة: الأرقام دي هي اللي مسحوبة فعليًا من المنصات عبر المزامنة (sync) - لو كلها صفر فمفيش بيانات أداء حقيقية بعد.";
    }
}