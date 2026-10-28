<?php

/**
 * Tourfecto - Email Marketing: Audience (Lists + Subscribers) Service
 * @version 1.0.0
 *
 * إدارة قوائم الجمهور والمشتركين:
 *   - CRUD للقوائم مع عداد فعلي للمشتركين
 *   - اشتراك/إلغاء اشتراك/حذف (عزل كامل لكل مستخدم - tenant)
 *   - استيراد دفعة (array/CSV) مع تجاهل المكرر على (user_id, email)
 *   - توليد توكن إلغاء اشتراك فريد
 */
class EmailListService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ Lists ============================

    public function lists(int $userId): array
    {
        return $this->db->query(
            "SELECT l.*,
                    (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.list_id = l.id) AS actual_count
             FROM email_lists l
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC",
            [$userId]
        );
    }

    public function createList(int $userId, string $name, ?string $description = null): array
    {
        if (trim($name) === '') {
            return ['success' => false, 'error' => 'اسم القائمة مطلوب'];
        }

        $list = new EmailList([
            'user_id' => $userId,
            'name' => trim($name),
            'description' => $description !== null ? trim($description) : null,
            'subscriber_count' => 0,
        ]);
        $id = $list->save();

        return $id ? ['success' => true, 'id' => (int) $id] : ['success' => false, 'error' => 'تعذر إنشاء القائمة'];
    }

    public function updateList(int $userId, int $listId, array $data): array
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القائمة غير موجودة'];
        }
        if (isset($data['name'])) {
            if (trim((string) $data['name']) === '') {
                return ['success' => false, 'error' => 'اسم القائمة مطلوب'];
            }
            $list->setAttribute('name', trim((string) $data['name']));
        }
        if (array_key_exists('description', $data)) {
            $list->setAttribute('description', $data['description'] !== null ? trim((string) $data['description']) : null);
        }
        $list->save();
        return ['success' => true];
    }

    public function deleteList(int $userId, int $listId): array
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'القائمة غير موجودة'];
        }
        // حذف القائمة يمسح الربط تلقائيًا (FK cascade) - لا نحذف المشتركين أنفسهم
        $list->delete();
        return ['success' => true];
    }

    // ============================ Subscribers ============================

    public function subscribers(int $userId, int $listId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $where = 's.user_id = ?';
        $params = [$userId];

        if ($listId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM email_list_subscriber els WHERE els.subscriber_id = s.id AND els.list_id = ?)';
            $params[] = $listId;
        }
        if (!empty($filters['status'])) {
            $where .= ' AND s.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (s.email LIKE ? OR s.name LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $countRow = $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers s WHERE {$where}",
            $params
        );
        $total = (int) ($countRow[0]['total'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.subscriber_id = s.id) AS list_count
             FROM email_subscribers s
             WHERE {$where}
             ORDER BY s.created_at DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
            $params
        );

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * اشتراك جديد (أو تفعيل مجدد لو كان unsubscribed/bounced).
     *
     * Double Opt-In (بند 2): لو اتّمرر `$data['require_optin']` (الاشتراك
     * العام من نموذج عام — مش استيراد/إدخال الأدمن) بيبدأ المشترك الجديد
     * (أو اللي مش subscribed حاليًا) بحالة `pending_optin` مع `optin_token`
     * وبيتبعتله بريد تأكيد عبر Mailer الحساب — ولن يصل لأي حملة إلا بعد
     * `confirmOptin()`. الاستيراد/الإدخال اليدوي (source=manual/import) يبقى
     * `subscribed` فورًا (سلوك غير متغيّر).
     *
     * @return array ['success'=>bool, 'id'=>?int, 'created'=>bool, 'status'=>?string,
     *                'pending_optin'=>bool, 'error'=>?string]
     */
    public function subscribe(int $userId, string $email, array $data = [], ?int $listId = null): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'بريد إلكتروني غير صالح'];
        }

        $requireOptin = !empty($data['require_optin']);

        $existing = (new EmailSubscriber())->where(['user_id' => $userId, 'email' => $email]);
        $subscriber = $existing[0] ?? null;

        $created = false;
        $pendingOptin = false;

        if ($subscriber) {
            $currentStatus = (string) $subscriber->getAttribute('status');

            if ($requireOptin && $currentStatus !== 'subscribed') {
                // إعادة اشتراك عبر النموذج العام → يلزم تأكيد جديد (Double Opt-In)
                $subscriber->setAttribute('status', 'pending_optin');
                $subscriber->setAttribute('unsubscribed_at', null);
                $subscriber->setAttribute('optin_token', $this->uniqueOptinToken());
                $subscriber->save();
                $id = (int) $subscriber->getAttribute('id');
                $pendingOptin = true;
            } else {
                $subscriber->setAttribute('status', 'subscribed');
                $subscriber->setAttribute('unsubscribed_at', null);
                if ($requireOptin) {
                    $subscriber->setAttribute('optin_token', null);
                }
                if (!empty($data['name'])) {
                    $subscriber->setAttribute('name', trim((string) $data['name']));
                }
                if (isset($data['source'])) {
                    $subscriber->setAttribute('source', (string) $data['source']);
                }
                $subscriber->save();
                $id = (int) $subscriber->getAttribute('id');
            }
        } else {
            $token = $this->uniqueUnsubscribeToken();
            $newStatus = $requireOptin ? 'pending_optin' : 'subscribed';
            $optinToken = $requireOptin ? $this->uniqueOptinToken() : null;
            $sub = new EmailSubscriber([
                'user_id' => $userId,
                'email' => $email,
                'name' => trim((string) ($data['name'] ?? '')),
                'attributes' => !empty($data['attributes']) ? json_encode($data['attributes'], JSON_UNESCAPED_UNICODE) : null,
                'status' => $newStatus,
                'unsubscribe_token' => $token,
                'optin_token' => $optinToken,
                'source' => (string) ($data['source'] ?? 'manual'),
            ]);
            $id = (int) $sub->save();
            $created = true;
            $pendingOptin = $requireOptin;
        }

        if ($listId !== null && $listId > 0) {
            $this->attachToList($userId, $id, $listId);
        }

        // خطاف الأتمتة: مشغّل "عند الاشتراك" للمشتركين الجدد المؤكَّدين فقط
        // (pending_optin لا يُشغّل أتمتة "subscribed" حتى التفعيل).
        if ($created && !$pendingOptin && class_exists('EmailAutomationService')) {
            try {
                (new EmailAutomationService())->handleEvent($userId, 'subscribed', [
                    'subscriber_id' => $id,
                    'list_id' => $listId !== null ? (int) $listId : 0,
                ]);
            } catch (\Throwable $e) {
                // خطأ أتمتة لا يفشل الاشتراك
            }
        }

        // سجل الموافقة (إثبات قانوني): المصدر/IP/النص. ctx اختياري: consent_ip, consent_text, user_agent
        if (class_exists('EmailComplianceService')) {
            (new EmailComplianceService())->recordConsent($userId, $id, $email, $pendingOptin ? 'pending_optin' : (($data['source'] ?? '') === 'import' ? 'imported' : 'subscribed'), [
                'source' => $data['source'] ?? 'manual',
                'list_id' => $listId,
                'ip' => $data['consent_ip'] ?? '',
                'user_agent' => $data['user_agent'] ?? null,
                'consent_text' => $data['consent_text'] ?? null,
            ]);
        }

        // إرسال بريد تأكيد الاشتراك (best-effort: فشل الإرسال لا يفشل الاشتراك)
        if ($pendingOptin) {
            $this->sendOptinEmail($userId, $id);
        }

        return [
            'success' => true,
            'id' => $id,
            'created' => $created,
            'status' => $pendingOptin ? 'pending_optin' : 'subscribed',
            'pending_optin' => $pendingOptin,
        ];
    }

    /**
     * توليد توكن تأكيد فريد (يشبه توكن الإلغاء لكن لعمود optin_token).
     */
    public function uniqueOptinToken(): string
    {
        do {
            $token = bin2hex(random_bytes(20));
            $exists = $this->db->query(
                "SELECT id FROM email_subscribers WHERE optin_token = ? LIMIT 1",
                [$token]
            );
        } while (!empty($exists));
        return $token;
    }

    /**
     * إرسال بريد تأكيد الاشتراك (Double Opt-In) عبر Mailer الحساب.
     * الرابط يوجّه لـ GET /webhooks/email/confirm/{token}.
     * Best-effort: لو SMTP غير مكتمل/فشل الإرسال يُرجع ['success'=>false]
     * بدون إفشال الاشتراك — القيد موثّق في الواجهة.
     */
    public function sendOptinEmail(int $userId, int $subscriberId): array
    {
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $token = (string) $sub->getAttribute('optin_token');
        if ($token === '') {
            return ['success' => false, 'error' => 'لا يوجد توكن تأكيد'];
        }
        $email = (string) $sub->getAttribute('email');
        $name = (string) $sub->getAttribute('name');

        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        $confirmUrl = $base . '/webhooks/email/confirm/' . rawurlencode($token);

        $html = <<<HTML
        <div dir="rtl" style="font-family:Segoe UI,Tahoma,Arial,sans-serif;max-width:600px;margin:auto;padding:24px;background:#f8fafc;border-radius:12px;">
            <h2 style="color:#0f172a;margin:0 0 12px;">تأكيد اشتراكك</h2>
            <p style="color:#334155;line-height:1.8;">مرحبًا{$this->nameGreeting($name)}، شكرًا لاشتراكك. لتأكيد اشتراكك واستلام رسائلنا، اضغط على الزر أدناه:</p>
            <p style="text-align:center;margin:24px 0;">
                <a href="{$confirmUrl}" style="display:inline-block;background:#16a34a;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;">تأكيد الاشتراك</a>
            </p>
            <p style="color:#64748b;font-size:13px;line-height:1.8;">لو ما طلبتش هذا الاشتراك يمكنك تجاهل هذه الرسالة. الرابط يعمل لمرة واحدة.</p>
        </div>
        HTML;

        try {
            $mailer = (new SmtpSettingsService())->mailerForUser($userId);
            return $mailer->send($email, $name, 'تأكيد اشتراكك في النشرة البريدية', $html);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تأكيد الاشتراك عبر توكن (بند 2): pending_optin → subscribed مع تسجيل
     * optin_ip/optin_at وتفعيل أتمتة "subscribed".
     * @return array ['success'=>bool, 'subscriber_id'=>?int, 'error'=>?string]
     */
    public function confirmOptin(string $token, string $ip = ''): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['success' => false, 'error' => 'رابط التأكيد غير صالح'];
        }
        $rows = (new EmailSubscriber())->where(['optin_token' => $token, 'status' => 'pending_optin']);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'رابط التأكيد غير صالح أو منتهٍ'];
        }
        $subscriber = $rows[0];
        $subscriber->setAttribute('status', 'subscribed');
        $subscriber->setAttribute('optin_token', null);
        $subscriber->setAttribute('optin_ip', substr($ip, 0, 64));
        $subscriber->setAttribute('optin_at', date('Y-m-d H:i:s'));
        $subscriber->save();

        $userId = (int) $subscriber->getAttribute('user_id');
        $id = (int) $subscriber->getAttribute('id');

        if (class_exists('EmailComplianceService')) {
            (new EmailComplianceService())->recordConsent($userId, $id, (string) $subscriber->getAttribute('email'), 'confirmed', [
                'source' => 'double_optin', 'ip' => $ip,
            ]);
        }

        if (class_exists('EmailAutomationService')) {
            try {
                (new EmailAutomationService())->handleEvent($userId, 'subscribed', [
                    'subscriber_id' => $id,
                    'list_id' => 0,
                ]);
            } catch (\Throwable $e) {
                // خطأ أتمتة لا يفشل التأكيد
            }
        }

        return ['success' => true, 'subscriber_id' => $id];
    }

    /** " فلان" لو فيه اسم مسجّل، و"!" بدل الاسم — لو مفيش. */
    private function nameGreeting(string $name): string
    {
        $name = trim($name);
        return $name !== '' ? ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') : '!';
    }

    public function attachToList(int $userId, int $subscriberId, int $listId): bool
    {
        $list = (new EmailList())->find($listId);
        if (!$list || (int) $list->getAttribute('user_id') !== $userId) {
            return false;
        }
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return false;
        }

        $this->db->query(
            "INSERT IGNORE INTO email_list_subscriber (list_id, subscriber_id) VALUES (?, ?)",
            [$listId, $subscriberId]
        );
        $this->refreshListCount($listId);
        return true;
    }

    public function detachFromList(int $userId, int $subscriberId, int $listId): bool
    {
        $this->db->query(
            "DELETE FROM email_list_subscriber WHERE list_id = ? AND subscriber_id = ?",
            [$listId, $subscriberId]
        );
        $this->refreshListCount($listId);
        return true;
    }

    public function unsubscribeByToken(string $token): bool
    {
        $sub = (new EmailSubscriber())->where(['unsubscribe_token' => $token]);
        if (empty($sub)) {
            return false;
        }
        $subscriber = $sub[0];
        $subscriber->setAttribute('status', 'unsubscribed');
        $subscriber->setAttribute('unsubscribed_at', date('Y-m-d H:i:s'));
        $subscriber->save();
        if (class_exists('EmailComplianceService')) {
            (new EmailComplianceService())->recordConsent(
                (int) $subscriber->getAttribute('user_id'), (int) $subscriber->getAttribute('id'),
                (string) $subscriber->getAttribute('email'), 'unsubscribed', ['source' => 'unsubscribe_link']
            );
        }
        return true;
    }

    public function deleteSubscriber(int $userId, int $subscriberId): array
    {
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $sub->delete();
        return ['success' => true];
    }

    /**
     * استيراد دفعة مشتركين: array of ['email'=>..., 'name'=>...]
     * المكرر يتم تجاهله (upsert على user_id+email).
     */
    public function import(int $userId, array $rows, ?int $listId = null): array
    {
        $added = 0;
        $updated = 0;
        $invalid = 0;

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }
            $result = $this->subscribe($userId, $email, [
                'name' => (string) ($row['name'] ?? ''),
                'source' => 'import',
            ], $listId);
            if ($result['success']) {
                $result['created'] ? $added++ : $updated++;
            }
        }

        return ['success' => true, 'added' => $added, 'updated' => $updated, 'invalid' => $invalid];
    }

    // ============================ Helpers ============================

    public function uniqueUnsubscribeToken(): string
    {
        do {
            $token = bin2hex(random_bytes(20));
            $exists = $this->db->query(
                "SELECT id FROM email_subscribers WHERE unsubscribe_token = ? LIMIT 1",
                [$token]
            );
        } while (!empty($exists));
        return $token;
    }

    private function refreshListCount(int $listId): void
    {
        $this->db->query(
            "UPDATE email_lists l
             SET l.subscriber_count = (SELECT COUNT(*) FROM email_list_subscriber els WHERE els.list_id = l.id)
             WHERE l.id = ?",
            [$listId]
        );
    }
}
