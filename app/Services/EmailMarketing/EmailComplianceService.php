<?php

/**
 * Tourfecto - Email Compliance Service (الامتثال: موافقات + GDPR)
 * @version 1.0.0
 *
 *   - recordConsent / consentHistory : إثبات الموافقة (المصدر، IP، النص، الوقت)
 *   - exportSubscriberData           : تصدير كل بيانات مشترك (Right of access)
 *   - eraseSubscriber                : محو مشترك (Right to erasure) مع الحفاظ على حظر الإرسال له
 *   - sendReadiness                  : فحص جاهزية الحساب قانونيًا قبل الإرسال (اسم الشركة...)
 * كل العمليات معزولة بـ user_id (tenant). الأخطاء في جداول اختيارية (أتمتة...) بتتجاهل بأمان.
 */
class EmailComplianceService
{
    public const EVENTS = ['subscribed', 'pending_optin', 'confirmed', 'unsubscribed', 'imported'];

    /** @var Database */
    private $db;
    /** @var callable */
    private $clock;

    public function __construct($db = null, ?callable $clock = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->clock = $clock ?: 'time';
    }

    private function ts(): string
    {
        return gmdate('Y-m-d H:i:s', (int) call_user_func($this->clock));
    }

    /** يسجّل حدث موافقة. لا يرمي استثناء (التسجيل ما يفشلش الاشتراك). */
    public function recordConsent(int $userId, int $subscriberId, string $email, string $event, array $ctx = []): bool
    {
        if (!in_array($event, self::EVENTS, true) || $userId <= 0 || $subscriberId <= 0) {
            return false;
        }
        try {
            $this->db->getConnection()->prepare(
                "INSERT INTO email_consent_log (user_id, subscriber_id, email, event, source, list_id, ip, user_agent, consent_text, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $userId, $subscriberId, strtolower(trim($email)), $event,
                isset($ctx['source']) ? substr((string) $ctx['source'], 0, 50) : null,
                !empty($ctx['list_id']) ? (int) $ctx['list_id'] : null,
                isset($ctx['ip']) && $ctx['ip'] !== '' ? substr((string) $ctx['ip'], 0, 64) : null,
                isset($ctx['user_agent']) ? substr((string) $ctx['user_agent'], 0, 255) : null,
                isset($ctx['consent_text']) ? substr((string) $ctx['consent_text'], 0, 1000) : null,
                $this->ts(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false; // الجدول لسه مش موجود (migration) أو خطأ مؤقت
        }
    }

    public function consentHistory(int $userId, int $subscriberId): array
    {
        return $this->db->query(
            "SELECT id, event, source, list_id, ip, user_agent, consent_text, created_at
             FROM email_consent_log WHERE user_id = ? AND subscriber_id = ? ORDER BY id ASC",
            [$userId, $subscriberId]
        );
    }

    private function ownedSubscriber(int $userId, int $subscriberId): ?array
    {
        $r = $this->db->query("SELECT * FROM email_subscribers WHERE id = ? AND user_id = ? LIMIT 1", [$subscriberId, $userId]);
        return $r[0] ?? null;
    }

    private function audit(int $userId, string $kind, string $email, ?int $actorId): void
    {
        try {
            $this->db->getConnection()->prepare("INSERT INTO email_data_requests (user_id, kind, email_hash, actor_id, created_at) VALUES (?, ?, ?, ?, ?)")
                ->execute([$userId, $kind, hash('sha256', strtolower(trim($email))), $actorId, $this->ts()]);
        } catch (\Throwable $e) {
        }
    }

    private function safeQuery(string $sql, array $params): array
    {
        try {
            return $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * تصدير بيانات المشترك. التوكنات الداخلية (unsubscribe/optin/open/click) مستبعدة.
     * @return array|null null لو المشترك مش موجود/مش تبع الحساب
     */
    public function exportSubscriberData(int $userId, int $subscriberId, ?int $actorId = null): ?array
    {
        $sub = $this->ownedSubscriber($userId, $subscriberId);
        if (!$sub) {
            return null;
        }
        foreach (['unsubscribe_token', 'optin_token'] as $secret) {
            unset($sub[$secret]);
        }
        $data = [
            'exported_at' => $this->ts() . ' UTC',
            'subscriber' => $sub,
            'lists' => $this->safeQuery(
                "SELECT l.id, l.name, els.created_at AS joined_at FROM email_list_subscriber els
                 JOIN email_lists l ON l.id = els.list_id WHERE els.subscriber_id = ? AND l.user_id = ?",
                [$subscriberId, $userId]
            ),
            'tags' => $this->safeQuery(
                "SELECT t.name FROM email_subscriber_tag st JOIN email_tags t ON t.id = st.tag_id WHERE st.subscriber_id = ? AND t.user_id = ?",
                [$subscriberId, $userId]
            ),
            'custom_fields' => $this->safeQuery(
                "SELECT f.name, v.value FROM email_subscriber_custom_values v JOIN email_custom_fields f ON f.id = v.field_id
                 WHERE v.subscriber_id = ? AND f.user_id = ?",
                [$subscriberId, $userId]
            ),
            'consent_history' => $this->consentHistory($userId, $subscriberId),
            'campaign_history' => $this->safeQuery(
                "SELECT c.name AS campaign, r.status, r.sent_at, r.opened_at, r.clicked_at
                 FROM email_campaign_recipients r JOIN email_campaigns c ON c.id = r.campaign_id
                 WHERE r.subscriber_id = ? AND c.user_id = ? ORDER BY r.id ASC",
                [$subscriberId, $userId]
            ),
        ];
        $this->audit($userId, 'export', (string) $sub['email'], $actorId);
        return $data;
    }

    /**
     * محو مشترك. بيحذف بياناته الشخصية وبيعمل إخفاء هوية لسجل الحملات (الإحصائيات تفضل).
     * $keepSuppression=true (افتراضي): العنوان يتحفظ في قائمة المنع عشان ما يتراسلش تاني
     * (أساس قانوني مشروع لاحترام الإلغاء) — لو false يتمحي تمامًا.
     */
    public function eraseSubscriber(int $userId, int $subscriberId, bool $keepSuppression = true, ?int $actorId = null): array
    {
        $sub = $this->ownedSubscriber($userId, $subscriberId);
        if (!$sub) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $email = strtolower((string) $sub['email']);
        $pdo = $this->db->getConnection();
        $own = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $own = true;
        }
        try {
            // إخفاء هوية سجل الحملات (الأعداد تفضل سليمة، والتوكنات تتلغي)
            $pdo->prepare(
                "UPDATE email_campaign_recipients SET email = ?, name = NULL, open_token = NULL, click_token = NULL,
                        subscriber_id = NULL, error_message = NULL
                 WHERE subscriber_id = ? AND campaign_id IN (SELECT id FROM email_campaigns WHERE user_id = ?)"
            )->execute(['erased-' . substr(hash('sha256', $userId . ':' . $subscriberId . ':' . $email), 0, 16) . '@erased.invalid', $subscriberId, $userId]);

            foreach ([
                "DELETE FROM email_automation_logs WHERE subscriber_id = ? AND user_id = ?",
                "DELETE FROM email_automation_entries WHERE subscriber_id = ? AND automation_id IN (SELECT id FROM email_automations WHERE user_id = ?)",
                "DELETE FROM email_subscriber_custom_values WHERE subscriber_id = ? AND field_id IN (SELECT id FROM email_custom_fields WHERE user_id = ?)",
                "DELETE FROM email_subscriber_tag WHERE subscriber_id = ? AND tag_id IN (SELECT id FROM email_tags WHERE user_id = ?)",
                "DELETE FROM email_list_subscriber WHERE subscriber_id = ? AND list_id IN (SELECT id FROM email_lists WHERE user_id = ?)",
                "DELETE FROM email_consent_log WHERE subscriber_id = ? AND user_id = ?",
            ] as $sql) {
                try {
                    $pdo->prepare($sql)->execute([$subscriberId, $userId]);
                } catch (\Throwable $e) {
                    // جدول اختياري مش موجود في النسخة دي
                    if ($pdo->inTransaction() && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                        throw $e;
                    }
                }
            }
            $pdo->prepare("DELETE FROM email_subscribers WHERE id = ? AND user_id = ?")->execute([$subscriberId, $userId]);

            if ($keepSuppression) {
                $exists = $this->db->query("SELECT id FROM email_suppressions WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $email]);
                if (empty($exists)) {
                    $pdo->prepare("INSERT INTO email_suppressions (user_id, email, type, reason, source) VALUES (?, ?, 'manual', 'gdpr_erasure', 'gdpr')")
                        ->execute([$userId, $email]);
                }
            } else {
                $pdo->prepare("DELETE FROM email_suppressions WHERE user_id = ? AND email = ?")->execute([$userId, $email]);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'تعذر إتمام المحو'];
        }
        $this->audit($userId, 'erase', $email, $actorId);
        return ['success' => true, 'suppression_kept' => $keepSuppression];
    }

    /**
     * جاهزية الإرسال القانونية: اسم الشركة في التذييل + وجود مشتركين بموافقة موثقة.
     * @return array{ready:bool,issues:string[],warnings:string[]}
     */
    public function sendReadiness(int $userId): array
    {
        $issues = [];
        $warnings = [];
        $u = $this->safeQuery("SELECT company_name FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (trim((string) ($u[0]['company_name'] ?? '')) === '') {
            $issues[] = 'أضف اسم الشركة في الإعدادات: التذييل القانوني يحتاجه (CAN-SPAM/GDPR).';
        }
        $row = $this->safeQuery(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM email_consent_log c WHERE c.subscriber_id = s.id AND c.user_id = s.user_id) THEN 1 ELSE 0 END) AS undocumented
             FROM email_subscribers s WHERE s.user_id = ? AND s.status = 'subscribed'",
            [$userId]
        );
        $undoc = (int) ($row[0]['undocumented'] ?? 0);
        $total = (int) ($row[0]['total'] ?? 0);
        if ($total > 0 && $undoc > 0) {
            $warnings[] = "{$undoc} من {$total} مشترك بلا سجل موافقة موثّق (مشتركون قدامى أو مستوردون)؛ احتفظ بإثبات مصدر الموافقة.";
        }
        return ['ready' => !$issues, 'issues' => $issues, 'warnings' => $warnings];
    }
}
