<?php

/**
 * Tourfecto - Competitor Intelligence: Alert Service
 * @version 1.0.0
 *
 * كل تنبيه هنا مربوط إجباريًا بتغيير فعلي مُسجَّل في ci_changes (change_id) -
 * ممنوع توليد تنبيهات "متوقعة" أو تخمينية. يحترم إعدادات Watchlist لكل
 * مستخدم/منافس (الحد الأدنى لخطورة التنبيه + القنوات المفعّلة).
 */
class AlertService
{
    private const SEVERITY_RANK = ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function notifyChange(Competitor $competitor, CiChange $change): void
    {
        $userId = (int) $competitor->getAttribute('user_id');
        $competitorId = (int) $competitor->getAttribute('id');

        $watchlist = (new CiWatchlistItem())->where(['user_id' => $userId, 'competitor_id' => $competitorId], [], 1);
        $rule = $watchlist[0] ?? null;

        if ($rule !== null && (int) $rule->getAttribute('is_paused') === 1) {
            return; // المستخدم أوقف تنبيهات هذا المنافس صراحة
        }

        $minSeverity = $rule ? (string) $rule->getAttribute('alert_min_severity') : 'medium';
        $severity = (string) $change->getAttribute('severity');

        $matchedKeyword = $this->matchKeyword($rule, $change);

        // لو فيه كلمة مفتاحية اتطابقت، التنبيه بيتولّد فورًا بغض النظر عن
        // الحد الأدنى المُعتاد للخطورة - المستخدم صراحة قال "نبّهني لو
        // ظهرت الكلمة دي" بغض النظر عن أي حاجة تانية.
        if ($matchedKeyword === null && (CiConstants::SEVERITY_RANK[$severity] ?? 0) < (CiConstants::SEVERITY_RANK[$minSeverity] ?? 2)) {
            return; // أقل من الحد الأدنى اللي المستخدم اختاره، ومفيش كلمة مفتاحية اتطابقت
        }

        $channels = ['dashboard'];
        if ($rule && $rule->getAttribute('alert_channels')) {
            $decoded = json_decode((string) $rule->getAttribute('alert_channels'), true);
            if (is_array($decoded) && !empty($decoded)) {
                $channels = $decoded;
            }
        }

        [$type, $title, $message] = $this->buildContent($competitor, $change, $matchedKeyword);
        $competitorName = (string) ($competitor->getAttribute('competitor_name') ?: $competitor->getAttribute('competitor_domain'));

        foreach ($channels as $channel) {
            $channel = in_array($channel, ['dashboard', 'email', 'in_app', 'webhook', 'slack'], true) ? $channel : 'dashboard';

            $alert = new CiAlert([
                'user_id' => $userId,
                'competitor_id' => $competitorId,
                'change_id' => (int) $change->getAttribute('id'),
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'channel' => $channel,
                'is_read' => 0,
            ]);
            $alert->save();

            if ($channel === 'email') {
                $this->dispatchEmail($userId, $title, $message, $alert);
            } elseif ($channel === 'webhook' || $channel === 'slack') {
                $this->dispatchWebhook($userId, $channel, $title, $message, $severity, $competitorName, $alert);
            }
        }

        ActivityLog::record('competitor_intelligence', 'alert.created', [
            'user_id' => $userId,
            'subject_type' => 'ci_changes',
            'subject_id' => (int) $change->getAttribute('id'),
            'meta' => ['severity' => $severity, 'type' => $type],
        ]);
    }

    private function buildContent(Competitor $competitor, CiChange $change, ?string $matchedKeyword = null): array
    {
        $name = (string) $competitor->getAttribute('competitor_name') ?: (string) $competitor->getAttribute('competitor_domain');
        $changeType = (string) $change->getAttribute('change_type');

        $typeLabels = [
            'pricing_change' => 'Competitor Changed Pricing',
            'offer_change' => 'Competitor Launched New Offer',
            'new_product' => 'Competitor Added New Service',
            'headline_change' => 'Competitor Changed Positioning',
            'content_change' => 'Competitor Published New Content',
            'new_page' => 'Competitor Published New Landing Page',
            'removed_page' => 'Competitor Removed a Page',
            'announcement' => 'Competitor Announcement Detected',
            'other' => 'Competitor Website Change Detected',
        ];

        $title = $typeLabels[$changeType] ?? 'Competitor Change Detected';
        $message = sprintf(
            '%s on %s (%s page). Severity: %s.',
            $title,
            $name,
            (string) $change->getAttribute('page_type'),
            (string) $change->getAttribute('severity')
        );

        if ($matchedKeyword !== null) {
            $message .= " Matched your keyword alert: \"{$matchedKeyword}\".";
        }

        return [$changeType, "{$title}: {$name}", $message];
    }

    /**
     * يفحص لو محتوى التغيير (before/after) فيه أي من الكلمات المفتاحية
     * اللي المستخدم سجّلها في Watchlist. Case-insensitive، بحث نصي بسيط
     * ومباشر - مفيش أي تخمين أو AI هنا، تطابق نصي حقيقي فقط.
     * @return string|null الكلمة المُتطابقة، أو null لو مفيش تطابق/مفيش كلمات مسجّلة
     */
    private function matchKeyword(?CiWatchlistItem $rule, CiChange $change): ?string
    {
        if ($rule === null || !$rule->getAttribute('keyword_filters')) {
            return null;
        }

        $keywords = json_decode((string) $rule->getAttribute('keyword_filters'), true);
        if (!is_array($keywords) || empty($keywords)) {
            return null;
        }

        $haystack = mb_strtolower(
            (string) $change->getAttribute('previous_value') . ' ' . (string) $change->getAttribute('new_value')
        );

        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '' && mb_strpos($haystack, mb_strtolower($keyword)) !== false) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * إرسال الإيميل مؤجّل عبر QueueManager (Job منفصل) بدل إبطاء دورة
     * المراقبة بانتظار SMTP. لو نظام الطابور مش جاهز، يُسجَّل فقط في
     * ci_alerts (channel=email) بدون إرسال فعلي - الواجهة تعرضه كـ
     * "لم يُرسل بعد" بدل ادّعاء نجاح كاذب.
     */
    private function dispatchEmail(int $userId, string $title, string $message, CiAlert $alert): void
    {
        // Profile Center Phase 10 (2026-08-10): أول اتصال حقيقي بين
        // notify_email وأي سلوك إرسال فعلي في المشروع كله - كان الحقل
        // ده Cosmetic بالكامل قبل كده (بيتخزن في قاعدة البيانات ومفيش
        // كود بيقرأه). notify_email افتراضيًا = 1 لكل المستخدمين
        // الحاليين (اتأكدنا من الـmigration)، فمفيش حد هيتوقف يستقبل
        // إيميلات كان بيستقبلها من غير ما يغيّر الإعداد بنفسه.
        $user = (new User())->find($userId);
        if ($user && !(bool) $user->getAttribute('notify_email')) {
            if (class_exists('Logger')) {
                Logger::info('CI AlertService email skipped - user disabled email notifications', ['user_id' => $userId]);
            }
            return;
        }

        try {
            $queue = new QueueManager();
            $pushed = $queue->push('SendCompetitorAlertEmailJob', [
                'user_id' => $userId,
                'ci_alert_id' => (int) $alert->getAttribute('id'),
                'title' => $title,
                'message' => $message,
            ]);
            if ($pushed) {
                $alert->setAttribute('sent_at', date('Y-m-d H:i:s'));
                $alert->save();
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('CI AlertService email dispatch failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * إرسال Webhook/Slack مؤجّل عبر QueueManager (Job منفصل) - نفس منطق
     * الإيميل. الرابط يُقرَأ من تفضيلات المستخدم (ci_user_preferences) -
     * لو المستخدم اختار القناة دي بس ما سجّلش رابط، نتجاهل بصمت (بدل
     * فشل الدورة كلها) ونسجّل تحذير فقط.
     */
    private function dispatchWebhook(int $userId, string $channel, string $title, string $message, string $severity, string $competitorName, CiAlert $alert): void
    {
        $prefsRows = (new CiUserPreference())->where(['user_id' => $userId], [], 1);
        $prefs = $prefsRows[0] ?? null;
        $url = $channel === 'slack'
            ? (string) ($prefs ? $prefs->getAttribute('slack_webhook_url') : '')
            : (string) ($prefs ? $prefs->getAttribute('webhook_url') : '');

        if ($url === '') {
            if (class_exists('Logger')) {
                Logger::warning("CI AlertService: {$channel} channel selected but no URL configured", ['user_id' => $userId]);
            }
            return;
        }

        try {
            $queue = new QueueManager();
            $pushed = $queue->push('SendCompetitorAlertWebhookJob', [
                'url' => $url,
                'format' => $channel === 'slack' ? 'slack' : 'generic',
                'title' => $title,
                'message' => $message,
                'severity' => $severity,
                'competitor_name' => $competitorName,
            ]);
            if ($pushed) {
                $alert->setAttribute('sent_at', date('Y-m-d H:i:s'));
                $alert->save();
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error("CI AlertService {$channel} dispatch failed: " . $e->getMessage());
            }
        }
    }
}
