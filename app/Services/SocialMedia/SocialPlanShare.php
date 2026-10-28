<?php

/**
 * Tourfecto - رابط موافقة العميل على الخطة الشهرية
 *
 * الوكالة/صاحب الحساب بيعمل رابط خاص (مدته محددة وممكن يتلغي) لخطة الشهر ويبعته لصاحب الشركة.
 * صاحب الشركة بيفتحه من غير تسجيل دخول: بيشوف كل منشور بالصورة والنص والميعاد، يكتب ملاحظات على أي منشور،
 * ويعتمد الخطة (لو الرابط مسموحله) أو يطلب تعديلات. كل رد بيوصل إشعار لصاحب الحساب.
 * التوكن بيتخزن hash بس (SHA-256) - اللي معاه الرابط بس يقدر يفتحه.
 * @version 1.0.0
 */
final class SocialPlanShare
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function ready(): bool
    {
        try {
            return (bool) $this->db->query("SHOW TABLES LIKE 'social_share_links'");
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function ownedPlan(int $uid, int $planId): array
    {
        $p = $this->db->query('SELECT id, status, month_key FROM social_monthly_plans WHERE id = ? AND user_id = ? LIMIT 1', [$planId, $uid])[0] ?? null;
        if (!$p) {
            throw new InvalidArgumentException('الخطة مش موجودة');
        }
        return $p;
    }

    /** @return array ['url'=>string,'link'=>array] - التوكن الخام بيرجع مرة واحدة بس */
    public function create(int $uid, int $planId, bool $canApprove, string $clientName = '', int $days = 14): array
    {
        if (!$this->ready()) {
            throw new InvalidArgumentException('شغّل migration السوشيال (2026_10_21_000001_social_growth.sql) الأول');
        }
        $this->ownedPlan($uid, $planId);
        $active = (int) ($this->db->query('SELECT COUNT(*) AS n FROM social_share_links WHERE plan_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()', [$planId])[0]['n'] ?? 0);
        if ($active >= 5) {
            throw new InvalidArgumentException('فيه 5 روابط شغالة للخطة دي - الغي واحد الأول');
        }
        $token = bin2hex(random_bytes(24));
        $days = max(1, min(60, $days));
        $this->db->query(
            'INSERT INTO social_share_links (user_id, plan_id, token_hash, can_approve, client_name, expires_at) VALUES (?,?,?,?,?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY))',
            [$uid, $planId, self::hash($token), $canApprove ? 1 : 0, mb_substr(trim($clientName), 0, 120) ?: null]
        );
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        return ['url' => $base . '/review/' . $token, 'links' => $this->links($uid, $planId)];
    }

    public function links(int $uid, int $planId): array
    {
        if (!$this->ready()) {
            return [];
        }
        $this->ownedPlan($uid, $planId);
        return $this->db->query(
            'SELECT id, can_approve, client_name, expires_at, revoked_at, last_viewed_at, created_at, (revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()) AS active
               FROM social_share_links WHERE plan_id = ? ORDER BY id DESC LIMIT 20',
            [$planId]
        ) ?: [];
    }

    public function revoke(int $uid, int $planId, int $linkId): array
    {
        $this->ownedPlan($uid, $planId);
        $this->db->query('UPDATE social_share_links SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND plan_id = ? AND revoked_at IS NULL', [$linkId, $planId]);
        return $this->links($uid, $planId);
    }

    public function feedback(int $uid, int $planId): array
    {
        if (!$this->ready()) {
            return [];
        }
        $this->ownedPlan($uid, $planId);
        return $this->db->query('SELECT id, item_id, author_name, decision, message, created_at FROM social_plan_feedback WHERE plan_id = ? ORDER BY id DESC LIMIT 200', [$planId]) ?: [];
    }

    /** رابط صالح (مش ملغي ومش منتهي) أو null */
    public function resolve(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $token) || !$this->ready()) {
            return null;
        }
        $row = $this->db->query(
            'SELECT l.*, p.status AS plan_status, p.version AS plan_version FROM social_share_links l JOIN social_monthly_plans p ON p.id = l.plan_id AND p.user_id = l.user_id
              WHERE l.token_hash = ? AND l.revoked_at IS NULL AND l.expires_at > UTC_TIMESTAMP() LIMIT 1',
            [self::hash($token)]
        )[0] ?? null;
        if ($row) {
            $this->db->query('UPDATE social_share_links SET last_viewed_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $row['id']]);
        }
        return $row ?: null;
    }

    /** بيانات الخطة للعرض العام (من غير أي بيانات داخلية) */
    public function publicView(array $link): array
    {
        $d = (new SocialMonthlyPlanService($this->db))->detail((int) $link['user_id'], (int) $link['plan_id']);
        $site = $this->db->query('SELECT company_name, domain FROM websites WHERE id = ? LIMIT 1', [(int) $d['plan']['website_id']])[0] ?? [];
        $items = [];
        foreach ($d['items'] as $i) {
            $items[] = [
                'id' => (int) $i['id'],
                'when' => (string) $i['scheduled_local'],
                'platform' => (string) $i['platform'],
                'page' => (string) ($i['page_name'] ?? ''),
                'theme' => (string) ($i['theme'] ?? ''),
                'objective' => (string) ($i['objective'] ?? ''),
                'content' => (string) $i['content'],
                'format' => (string) ($i['format'] ?? 'photo'),
                'headline' => $i['design']['headline'] ?? null,
                'has_media' => !empty($i['media_item_id']),
                'status' => (string) ($i['effective_status'] ?? ''),
            ];
        }
        return [
            'company' => (string) (($site['company_name'] ?? '') ?: ($site['domain'] ?? '')),
            'month' => (string) $d['plan']['month_key'],
            'timezone' => (string) $d['plan']['timezone'],
            'status' => (string) $d['plan']['status'],
            'version' => (int) $d['plan']['version'],
            'strategy' => is_array($d['plan']['strategy']) ? (string) ($d['plan']['strategy']['summary'] ?? '') : '',
            'items' => $items,
            'can_approve' => (bool) $link['can_approve'],
            'client_name' => (string) ($link['client_name'] ?? ''),
            'feedback' => $this->db->query('SELECT item_id, author_name, decision, message, created_at FROM social_plan_feedback WHERE plan_id = ? ORDER BY id DESC LIMIT 100', [(int) $link['plan_id']]) ?: [],
        ];
    }

    /** ملف وسائط منشور في الخطة (للعرض في صفحة المراجعة) - المسار على السيرفر أو null */
    public function mediaPath(array $link, int $itemId): ?array
    {
        $row = $this->db->query(
            'SELECT m.file_path, m.type FROM social_monthly_plan_items i JOIN media_items m ON m.id = i.media_item_id AND m.user_id = ?
              WHERE i.id = ? AND i.plan_id = ? LIMIT 1',
            [(int) $link['user_id'], $itemId, (int) $link['plan_id']]
        )[0] ?? null;
        if (!$row) {
            return null;
        }
        $path = (string) $row['file_path'];
        if (class_exists('PrivateMedia') && PrivateMedia::valid($path)) {
            try {
                return ['file' => PrivateMedia::resolve($path), 'video' => $row['type'] === 'short_video'];
            } catch (Throwable $e) {
                return null;
            }
        }
        if (preg_match('#^/uploads/social/' . (int) $link['user_id'] . '/[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|webp|mp4|mov)$#D', $path)) {
            $file = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3)) . '/public_html' . $path;
            return is_file($file) ? ['file' => $file, 'video' => (bool) preg_match('/\.(mp4|mov)$/i', $path)] : null;
        }
        return null;
    }

    /**
     * ملاحظة/قرار من العميل. approved = يعتمد الخطة فعلًا (لو مسموح والخطة لسه مسودة) - نفس اعتماد صاحب الحساب.
     * @return array ['ok'=>bool,'approved'=>bool,'error'=>?string]
     */
    public function submit(array $link, string $decision, string $message, ?int $itemId, string $author): array
    {
        $decision = in_array($decision, ['comment', 'changes', 'approved'], true) ? $decision : 'comment';
        $message = trim(mb_substr($message, 0, 2000));
        $author = trim(mb_substr($author, 0, 120)) ?: (string) ($link['client_name'] ?? '') ?: 'Client';
        if ($decision !== 'approved' && $message === '') {
            throw new InvalidArgumentException('اكتب ملاحظتك');
        }
        if ($itemId && !$this->db->query('SELECT id FROM social_monthly_plan_items WHERE id = ? AND plan_id = ?', [$itemId, (int) $link['plan_id']])) {
            $itemId = null;
        }
        $approved = false;
        $error = null;
        if ($decision === 'approved') {
            if (!$link['can_approve']) {
                throw new InvalidArgumentException('الرابط ده للمراجعة والملاحظات بس');
            }
            if ($link['plan_status'] === 'draft') {
                try {
                    (new SocialMonthlyPlanService($this->db))->approve((int) $link['user_id'], (int) $link['plan_id'], (int) $link['plan_version']);
                    $approved = true;
                } catch (Throwable $e) {
                    // الاعتماد ما تمّش (ميعاد عدّى/العامل مش شغال...): بنسجّل موافقة العميل ونبلّغ صاحب الحساب يكمّل
                    $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'approval pending';
                }
            } else {
                $approved = in_array($link['plan_status'], ['active', 'paused', 'completed'], true);
            }
        }
        $this->db->query(
            'INSERT INTO social_plan_feedback (plan_id, item_id, share_link_id, author_name, decision, message) VALUES (?,?,?,?,?,?)',
            [(int) $link['plan_id'], $itemId, (int) $link['id'], $author, $decision, $message !== '' ? $message : null]
        );
        if (class_exists('Notification')) {
            $title = ['approved' => $approved ? 'العميل اعتمد خطة الشهر ✅' : 'العميل وافق على الخطة - محتاج تكمّل الاعتماد', 'changes' => 'العميل طلب تعديلات على خطة الشهر', 'comment' => 'ملاحظة جديدة من العميل على خطة الشهر'][$decision];
            try {
                Notification::notify((int) $link['user_id'], 'social_plan_feedback', $title, $author . ': ' . mb_substr($message !== '' ? $message : ($error ?? ''), 0, 160), '/social#plan');
            } catch (Throwable $e) {
            }
        }
        return ['ok' => true, 'approved' => $approved, 'error' => $error];
    }
}
