<?php

/**
 * Tourfecto - تنبيهات الأدمن
 *
 * بيشتغل كل 10 دقايق من cron/admin_watchdog.php:
 *  1) بيقرا صحة المنصة (PlatformHealthService): أي فحص "fail" = تنبيه حرج، وبعض الـ "warn" = تحذير.
 *  2) أحداث الفلوس: عميل مهم لغى (MRR ≥ الحد)، اشتراك دخل في تأخير دفع، دفعات فشلت.
 *  3) تنبيه واحد مفتوح لكل مشكلة (alert_key)، وبيتقفل لوحده لما المشكلة تتحل.
 *  4) الإبلاغ: إيميل + إشعار جوه اللوحة (+ واتساب لو UltraMsg متظبط ورقم الأدمن موجود).
 *     الحرج بيتبعت تاني كل 24 ساعة لو لسه مفتوح.
 *
 * الإعدادات (system_settings): admin_alerts_enabled, admin_alert_emails,
 * admin_alert_whatsapp, admin_alert_min_mrr.
 *
 * @version 1.0.0
 */
class AdminAlertService
{
    /** @var Database */
    private $db;

    /** فحوصات الصحة اللي الـ warn بتاعها يستاهل تنبيه */
    private const WARN_ALERTS = ['cron_jobs', 'mail_delivery', 'queue_lag', 'disk', 'past_due', 'payments_failed', 'integrations_broken', 'app_errors', 'customer_messages', 'scheduler_runtime', 'export_retention', 'trip_reminder_worker', 'reminder_delivery'];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public function settings(): array
    {
        $s = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        $get = fn ($k, $d = '') => $s ? $s->get($k, $d) : $d;
        return [
            'enabled' => $get('admin_alerts_enabled', '1') !== '0',
            'emails' => $get('admin_alert_emails', ''),
            'whatsapp' => $get('admin_alert_whatsapp', ''),
            'min_mrr' => (float) ($get('admin_alert_min_mrr', '99') ?: 99),
        ];
    }

    public function saveSettings(array $in): array
    {
        $s = new SystemSettingsService();
        $emails = array_filter(array_map('trim', preg_split('/[,\s;]+/', (string) ($in['emails'] ?? ''))));
        foreach ($emails as $e) {
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => "الإيميل ده مش صحيح: {$e}"];
            }
        }
        $wa = preg_replace('/[^0-9+]/', '', (string) ($in['whatsapp'] ?? ''));
        $s->set('admin_alerts_enabled', !empty($in['enabled']) ? '1' : '0');
        $s->set('admin_alert_emails', implode(',', $emails));
        $s->set('admin_alert_whatsapp', $wa);
        $s->set('admin_alert_min_mrr', (string) max(0, (float) ($in['min_mrr'] ?? 99)));
        return ['ok' => true, 'settings' => $this->settings()];
    }

    /** الدورة الكاملة: تقييم + فتح/قفل التنبيهات + الإبلاغ */
    public function run(): array
    {
        $found = [];
        $health = (new PlatformHealthService($this->db))->report();
        foreach ($health['sections'] as $sec) {
            foreach ($sec['checks'] as $c) {
                if ($c['status'] === 'fail' || ($c['status'] === 'warn' && in_array($c['key'], self::WARN_ALERTS, true))) {
                    $found['health.' . $c['key']] = [
                        'severity' => $c['status'] === 'fail' ? 'critical' : 'warning',
                        'title' => $sec['title'] . ': ' . $c['label'],
                        'body' => $c['message'] . ($c['action'] ? "\nالمطلوب: " . $c['action'] : ''),
                        'link' => $c['key'] === 'app_errors' ? '/admin/errors' : '/admin/health',
                    ];
                }
            }
        }
        foreach ($this->businessEvents() as $k => $a) {
            $found[$k] = $a;
        }

        $opened = 0;
        $resolved = 0;
        $open = [];
        foreach ($this->q("SELECT * FROM admin_alerts WHERE status = 'open'") as $r) {
            $open[$r['alert_key']] = $r;
        }
        foreach ($found as $key => $a) {
            if (isset($open[$key])) {
                $this->db->query('UPDATE admin_alerts SET last_seen_at = NOW(), occurrences = occurrences + 1, severity = ?, title = ?, body = ? WHERE id = ?',
                    [$a['severity'], $a['title'], $a['body'], (int) $open[$key]['id']]);
                continue;
            }
            // اتقفل يدويًا (muted) آخر 24 ساعة: مانفتحوش تاني
            $muted = $this->q("SELECT id FROM admin_alerts WHERE alert_key = ? AND status = 'muted' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1", [$key]);
            if ($muted) {
                continue;
            }
            $this->db->query('INSERT INTO admin_alerts (alert_key, severity, title, body, link) VALUES (?, ?, ?, ?, ?)',
                [$key, $a['severity'], $a['title'], $a['body'], $a['link'] ?? null]);
            $opened++;
        }
        // اللي اتحل لوحده
        foreach ($open as $key => $r) {
            $isEvent = strpos($key, 'event.') === 0;
            if (!isset($found[$key]) && (!$isEvent || strtotime($r['first_seen_at']) < time() - 7 * 86400)) {
                $this->db->query("UPDATE admin_alerts SET status = 'resolved', resolved_at = NOW() WHERE id = ?", [(int) $r['id']]);
                $resolved++;
            }
        }
        $notified = $this->notifyPending();
        return ['found' => count($found), 'opened' => $opened, 'resolved' => $resolved, 'notified' => $notified];
    }

    /** أحداث فلوس: إلغاء عميل مهم، تأخير دفع، دفع فشل */
    private function businessEvents(): array
    {
        $out = [];
        $minMrr = $this->settings()['min_mrr'];
        $monthly = "(CASE WHEN COALESCE(sp.billing_cycle, s.plan_type) IN ('yearly','annual') THEN COALESCE(sp.price, s.price) / 12 ELSE COALESCE(sp.price, s.price) END)";
        foreach ($this->q(
            "SELECT s.id, s.user_id, COALESCE(NULLIF(u.company_name,''), u.email) name, ROUND($monthly, 2) mrr, s.cancellation_reason
               FROM subscriptions s LEFT JOIN subscription_plans sp ON sp.id = s.plan_id JOIN users u ON u.id = s.user_id
              WHERE s.status IN ('cancelled','canceled') AND COALESCE(s.cancelled_at, s.updated_at) >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND $monthly >= ?",
            [$minMrr]
        ) as $r) {
            $out['event.churn.' . $r['id']] = [
                'severity' => 'warning',
                'title' => "عميل مهم لغى: {$r['name']} (\${$r['mrr']}/شهر)",
                'body' => 'السبب: ' . ($r['cancellation_reason'] ?: 'ماكتبش سبب') . "\nكلّمه وشوف لو ينفع ترجّعه.",
                'link' => '/admin/users/' . (int) $r['user_id'],
            ];
        }
        foreach ($this->q(
            "SELECT s.id, s.user_id, COALESCE(NULLIF(u.company_name,''), u.email) name FROM subscriptions s JOIN users u ON u.id = s.user_id
              WHERE s.status = 'past_due' AND s.updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        ) as $r) {
            $out['event.past_due.' . $r['id']] = [
                'severity' => 'info',
                'title' => "اشتراك متأخر الدفع: {$r['name']}",
                'body' => 'الاشتراك دخل فترة السماح. المنصة بتبعتله تذكير لوحدها، بس مكالمة منك بتفرق.',
                'link' => '/admin/billing-ops',
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ الإبلاغ

    private function notifyPending(): int
    {
        $cfg = $this->settings();
        if (!$cfg['enabled']) {
            return 0;
        }
        $rows = $this->q(
            "SELECT * FROM admin_alerts WHERE status = 'open' AND (notified_at IS NULL OR (severity = 'critical' AND notified_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)))
              ORDER BY FIELD(severity,'critical','warning','info'), id LIMIT 20"
        );
        if (!$rows) {
            return 0;
        }
        $admins = $this->q("SELECT id, email FROM users WHERE role IN ('admin','super_admin') AND deleted_at IS NULL");
        $emails = $cfg['emails'] !== '' ? explode(',', $cfg['emails']) : array_column($admins, 'email');
        $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');

        // رسالة واحدة مجمّعة بدل إيميل لكل تنبيه
        $items = '';
        $wa = [];
        foreach ($rows as $r) {
            $color = ['critical' => '#dc2626', 'warning' => '#d97706', 'info' => '#2563eb'][$r['severity']] ?? '#555';
            $items .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eee"><b style="color:' . $color . '">●</b> <b>' . htmlspecialchars($r['title']) . '</b><br>'
                . '<span style="color:#555;font-size:13px">' . nl2br(htmlspecialchars((string) $r['body'])) . '</span>'
                . ($r['link'] ? '<br><a href="' . htmlspecialchars($base . $r['link']) . '" style="font-size:13px">افتح في لوحة الأدمن</a>' : '') . '</td></tr>';
            $wa[] = ($r['severity'] === 'critical' ? '🔴 ' : ($r['severity'] === 'warning' ? '🟠 ' : '🔵 ')) . $r['title'];
        }
        $crit = count(array_filter($rows, fn ($r) => $r['severity'] === 'critical'));
        $subject = ($crit ? "🔴 {$crit} مشكلة حرجة" : '🟠 تنبيهات') . ' - Tourfecto';
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial;max-width:600px"><h2 style="font-size:18px">' . htmlspecialchars($subject) . '</h2>'
            . '<table style="width:100%;border-collapse:collapse">' . $items . '</table>'
            . '<p style="color:#888;font-size:12px">الإعدادات: لوحة الأدمن ← التنبيهات</p></div>';

        $sent = false;
        if (class_exists('Mailer') && $emails) {
            foreach (array_slice(array_unique($emails), 0, 10) as $to) {
                $r = (new Mailer())->context('admin_alert')->send(trim($to), 'Tourfecto Admin', $subject, $html);
                $sent = $sent || !empty($r['success']);
            }
        }
        if ($cfg['whatsapp'] !== '') {
            $sent = $this->whatsapp($cfg['whatsapp'], $subject . "\n" . implode("\n", $wa) . "\n" . $base . '/admin/alerts') || $sent;
        }
        // إشعار جوه اللوحة لكل أدمن (بيوصل حتى لو البريد نفسه هو اللي واقع)
        foreach ($admins as $a) {
            try {
                $this->db->query('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
                    [(int) $a['id'], 'admin_alert', $subject, implode(' · ', array_slice($wa, 0, 5)), '/admin/alerts']);
            } catch (Throwable $e) {
            }
        }
        $ids = implode(',', array_map(fn ($r) => (int) $r['id'], $rows));
        $this->db->exec("UPDATE admin_alerts SET notified_at = NOW() WHERE id IN ($ids)");
        return count($rows);
    }

    private function whatsapp(string $to, string $body): bool
    {
        $instance = (string) (function_exists('env') ? env('ULTRAMSG_INSTANCE_ID') : '');
        $token = (string) (function_exists('env') ? env('ULTRAMSG_API_KEY') : '');
        if ($instance === '' || $token === '') {
            return false;
        }
        $ch = curl_init('https://api.ultramsg.com/' . rawurlencode($instance) . '/messages/chat');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['token' => $token, 'to' => $to, 'body' => mb_substr($body, 0, 3500)]),
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300 && is_string($out) && stripos($out, 'error') === false;
    }

    // ------------------------------------------------------------------ للوحة

    public function list(string $status = 'open'): array
    {
        $where = $status === 'all' ? '1=1' : ($status === 'resolved' ? "status IN ('resolved','muted')" : "status = 'open'");
        return $this->q("SELECT * FROM admin_alerts WHERE $where ORDER BY status = 'open' DESC, FIELD(severity,'critical','warning','info'), last_seen_at DESC LIMIT 200");
    }

    public function openCount(): array
    {
        $r = $this->q("SELECT SUM(severity = 'critical') critical, COUNT(*) total FROM admin_alerts WHERE status = 'open'");
        return ['critical' => (int) ($r[0]['critical'] ?? 0), 'total' => (int) ($r[0]['total'] ?? 0)];
    }

    /** الأدمن قفله: muted = مايتفتحش تاني 24 ساعة حتى لو المشكلة لسه موجودة */
    public function resolve(int $id, int $adminId, bool $mute = false): void
    {
        $this->db->query('UPDATE admin_alerts SET status = ?, resolved_at = NOW(), resolved_by = ? WHERE id = ?', [$mute ? 'muted' : 'resolved', $adminId, $id]);
    }

    private function q(string $sql, array $p = []): array
    {
        try {
            return $this->db->query($sql, $p) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
