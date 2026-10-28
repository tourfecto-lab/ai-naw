<?php

/**
 * Tourfecto - ملخص الصبح لصاحب الشركة (واتساب، أو إيميل)
 *
 * كل يوم الصبح في الساعة اللي يختارها: إيه اللي حصل امبارح (حجوزات، فلوس، تقييمات، عملاء جداد)
 * وإيه اللي مستنيه النهارده (رحلات النهارده، حجوزات محتاجة تأكيد، رسايل مستنية رد، موافقات).
 * الهدف إن صاحب الشركة يعرف حاله في 10 ثواني من غير ما يفتح المنصة، ويدخل على الحاجة المهمة على طول.
 *
 * الأرقام كلها من قاعدة البيانات مباشرة (مفيش تقدير). لو اليوم هادي خالص مش بنبعت حاجة (اختياري).
 */
class MorningBriefService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    // ------------------------------------------------------------------ الإعدادات

    public function settings(int $userId): array
    {
        $row = null;
        try {
            $row = $this->db->query('SELECT * FROM morning_brief_settings WHERE user_id = ? LIMIT 1', [$userId])[0] ?? null;
        } catch (Throwable $e) {
        }
        $phone = (string) ($row['phone'] ?? '');
        if ($phone === '') {
            try {
                $phone = (string) ($this->db->query('SELECT phone FROM users WHERE id = ? LIMIT 1', [$userId])[0]['phone'] ?? '');
            } catch (Throwable $e) {
            }
        }
        return [
            'enabled' => (int) ($row['enabled'] ?? 0),
            'send_hour' => (int) ($row['send_hour'] ?? 8),
            'channel' => (string) ($row['channel'] ?? 'auto'),
            'phone' => $phone,
            'skip_quiet_days' => (int) ($row['skip_quiet_days'] ?? 1),
            'language' => in_array($row['language'] ?? '', ['ar', 'en'], true) ? (string) $row['language'] : $this->userLang($userId),
            'last_sent_on' => $row['last_sent_on'] ?? null,
            'last_status' => $row['last_status'] ?? null,
            'whatsapp_available' => $this->platformWhatsappReady() || $this->ownWhatsappWebsite($this->scopeOf($userId)) > 0,
        ];
    }

    private function userLang(int $userId): string
    {
        try {
            $l = (string) ($this->db->query('SELECT language FROM users WHERE id = ? LIMIT 1', [$userId])[0]['language'] ?? 'ar');
            return $l === 'en' ? 'en' : 'ar';
        } catch (Throwable $e) {
            return 'ar';
        }
    }

    /** @return array{ok:bool, error?:string} */
    public function save(int $userId, array $in): array
    {
        $hour = (int) ($in['send_hour'] ?? 8);
        if ($hour < 5 || $hour > 12) {
            return ['ok' => false, 'error' => 'الساعة لازم تكون بين 5 و12 الضهر'];
        }
        $channel = in_array($in['channel'] ?? 'auto', ['auto', 'whatsapp', 'email'], true) ? (string) ($in['channel'] ?? 'auto') : 'auto';
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        if ($phone !== '' && (strlen($phone) < 10 || strlen($phone) > 15)) {
            return ['ok' => false, 'error' => 'اكتب الرقم بكود الدولة، مثلاً 201001234567'];
        }
        if (!empty($in['enabled']) && $channel === 'whatsapp' && $phone === '') {
            return ['ok' => false, 'error' => 'اكتب رقم الواتساب اللي يوصله الملخص'];
        }
        $saved = $this->db->exec(
            'INSERT INTO morning_brief_settings (user_id, enabled, send_hour, channel, phone, skip_quiet_days, language)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), send_hour = VALUES(send_hour), channel = VALUES(channel),
                phone = VALUES(phone), skip_quiet_days = VALUES(skip_quiet_days), language = VALUES(language)',
            [$userId, !empty($in['enabled']) ? 1 : 0, $hour, $channel, $phone !== '' ? $phone : null, !empty($in['skip_quiet_days']) ? 1 : 0,
             ($in['language'] ?? 'ar') === 'en' ? 'en' : 'ar']
        );
        if (!$saved) {
            return ['ok' => false, 'error' => 'تعذر الحفظ - جرّب تاني'];
        }
        return ['ok' => true];
    }

    // ------------------------------------------------------------------ المحتوى

    /** عضو الفريق بيشوف أرقام صاحب الشغل */
    private function scopeOf(int $userId): int
    {
        try {
            $owner = (int) ($this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId])[0]['owner_user_id'] ?? 0);
            return $owner > 0 ? $owner : $userId;
        } catch (Throwable $e) {
            return $userId;
        }
    }

    private function n(string $sql, array $p): float
    {
        try {
            return (float) ($this->db->query($sql, $p)[0]['v'] ?? 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /** حدود "امبارح" و"النهارده" بتوقيت صاحب الشغل، متحوّلة لـ UTC (توقيت قاعدة البيانات) */
    private function ranges(): array
    {
        $tzName = function_exists('env') ? (string) (env('CRON_TIMEZONE') ?: 'Africa/Cairo') : 'Africa/Cairo';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Cairo');
        }
        $utc = new DateTimeZone('UTC');
        $today = new DateTime('today', $tz);
        $yesterday = (clone $today)->modify('-1 day');
        return [
            'today' => $today->format('Y-m-d'),
            'y_from' => (clone $yesterday)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'y_to' => (clone $today)->setTimezone($utc)->format('Y-m-d H:i:s'),
            'label' => $yesterday->format('Y-m-d'),
        ];
    }

    /** @return array{text:string, has_activity:bool, stats:array} */
    public function build(int $userId, ?string $lang = null): array
    {
        $o = $this->scopeOf($userId);
        $r = $this->ranges();
        $Y = [$r['y_from'], $r['y_to']];

        $st = [];
        $st['new_bookings'] = (int) $this->n("SELECT COUNT(*) v FROM bookings WHERE user_id = ? AND created_at >= ? AND created_at < ? AND status <> 'cancelled'", array_merge([$o], $Y));
        $st['new_revenue'] = round($this->n("SELECT COALESCE(SUM(total_amount),0) v FROM bookings WHERE user_id = ? AND created_at >= ? AND created_at < ? AND status IN ('confirmed','completed')", array_merge([$o], $Y)), 2);
        $currency = '';
        try {
            $currency = (string) ($this->db->query("SELECT currency FROM bookings WHERE user_id = ? GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1", [$o])[0]['currency'] ?? '');
        } catch (Throwable $e) {
        }
        $st['cancelled'] = (int) $this->n("SELECT COUNT(*) v FROM booking_status_history h JOIN bookings b ON b.id = h.booking_id WHERE b.user_id = ? AND h.to_status = 'cancelled' AND h.created_at >= ? AND h.created_at < ?", array_merge([$o], $Y));
        $st['trips_today'] = (int) $this->n("SELECT COUNT(*) v FROM bookings WHERE user_id = ? AND status = 'confirmed' AND start_date = ?", [$o, $r['today']]);
        $st['people_today'] = (int) $this->n("SELECT COALESCE(SUM(adults_count + COALESCE(children_count,0)),0) v FROM bookings WHERE user_id = ? AND status = 'confirmed' AND start_date = ?", [$o, $r['today']]);
        $st['pending_bookings'] = (int) $this->n("SELECT COUNT(*) v FROM bookings WHERE user_id = ? AND status = 'pending' AND start_date >= ?", [$o, $r['today']]);
        $st['unread_chats'] = (int) $this->n("SELECT COUNT(*) v FROM ai_conversations c JOIN websites w ON w.id = c.website_id WHERE w.user_id = ? AND c.unread_count > 0 AND c.status IN ('open','pending')", [$o]);
        $st['handoff_chats'] = (int) $this->n("SELECT COUNT(*) v FROM ai_conversations c JOIN websites w ON w.id = c.website_id WHERE w.user_id = ? AND c.ai_status = 'human' AND c.unread_count > 0 AND c.status IN ('open','pending')", [$o]);
        $st['new_reviews'] = (int) $this->n("SELECT COUNT(*) v FROM reviews WHERE user_id = ? AND created_at >= ? AND created_at < ?", array_merge([$o], $Y));
        $st['avg_rating'] = round($this->n("SELECT COALESCE(AVG(rating),0) v FROM reviews WHERE user_id = ? AND created_at >= ? AND created_at < ?", array_merge([$o], $Y)), 1);
        $st['bad_reviews_open'] = (int) $this->n("SELECT COUNT(*) v FROM reviews WHERE user_id = ? AND rating <= 3 AND reply_sent_at IS NULL AND created_at >= (NOW() - INTERVAL 14 DAY)", [$o]);
        $st['new_leads'] = (int) $this->n("SELECT COUNT(*) v FROM website_leads l JOIN generated_websites g ON g.id = l.website_id WHERE g.user_id = ? AND l.created_at >= ? AND l.created_at < ?", array_merge([$o], $Y))
            + (int) $this->n("SELECT COUNT(*) v FROM ai_leads l JOIN websites w ON w.id = l.website_id WHERE w.user_id = ? AND l.created_at >= ? AND l.created_at < ?", array_merge([$o], $Y));
        $st['approvals'] = (int) $this->n("SELECT COUNT(*) v FROM automation_decisions WHERE user_id = ? AND status = 'pending'", [$o]);
        $st['competitor_prices'] = (int) $this->n("SELECT COUNT(*) v FROM ci_alerts WHERE user_id = ? AND type = 'product_price_change' AND created_at >= ? AND created_at < ?", array_merge([$o], $Y));

        $en = ($lang ?? $this->settings($userId)['language']) === 'en';
        $T = fn (string $ar, string $e) => $en ? $e : $ar;
        $money = rtrim(rtrim(number_format($st['new_revenue'], 2, '.', ','), '0'), '.') . ' ' . $currency;

        $L = [];
        $L[] = $T('☀️ صباح الخير! ملخص ', '☀️ Good morning! Summary for ') . $r['label'];
        $L[] = '';
        $L[] = $T('*امبارح*', '*Yesterday*');
        $L[] = $T('🧳 حجوزات جديدة: ', '🧳 New bookings: ') . $st['new_bookings'] . ($st['new_revenue'] > 0 ? $T(' (مؤكد: ', ' (confirmed: ') . $money . ')' : '');
        if ($st['cancelled'] > 0) {
            $L[] = $T('↩️ إلغاءات: ', '↩️ Cancellations: ') . $st['cancelled'];
        }
        if ($st['new_leads'] > 0) {
            $L[] = $T('📩 عملاء مهتمين جداد: ', '📩 New leads: ') . $st['new_leads'];
        }
        if ($st['competitor_prices'] > 0) {
            $L[] = $T('💲 منافسين غيّروا أسعار: ', '💲 Competitor price changes: ') . $st['competitor_prices'] . $T(' (التفاصيل في صفحة المنافسين)', ' (details on the Competitors page)');
        }
        if ($st['new_reviews'] > 0) {
            $L[] = $T('⭐ تقييمات جديدة: ', '⭐ New reviews: ') . $st['new_reviews'] . $T(' (متوسط ', ' (avg ') . $st['avg_rating'] . ')';
        }
        $L[] = '';
        $L[] = $T('*النهارده*', '*Today*');
        $L[] = $T('🚐 رحلات النهارده: ', '🚐 Trips today: ') . $st['trips_today'] . ($st['people_today'] > 0 ? ' (' . $st['people_today'] . $T(' فرد)', ' people)') : '');
        if ($st['pending_bookings'] > 0) {
            $L[] = $T('⏳ حجوزات مستنية تأكيدك: ', '⏳ Bookings awaiting your confirmation: ') . $st['pending_bookings'];
        }
        if ($st['unread_chats'] > 0) {
            $L[] = $T('💬 محادثات فيها رسايل ماتقرتش: ', '💬 Chats with unread messages: ') . $st['unread_chats']
                . ($st['handoff_chats'] > 0 ? $T(' (منها ', ' (') . $st['handoff_chats'] . $T(' البوت حوّلها لك)', ' handed over to you by the bot)') : '');
        }
        if ($st['bad_reviews_open'] > 0) {
            $L[] = $T('⚠️ تقييمات سلبية من غير رد: ', '⚠️ Negative reviews without a reply: ') . $st['bad_reviews_open'];
        }
        if ($st['approvals'] > 0) {
            $L[] = $T('🤖 حاجات مستنية موافقتك: ', '🤖 Items awaiting your approval: ') . $st['approvals'];
        }

        // أهم حاجة تعملها
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        $top = null;
        if ($st['bad_reviews_open'] > 0) {
            $top = [$T('رد على التقييمات السلبية قبل ما ناس أكتر تشوفها', 'Reply to negative reviews before more people see them'), '/reputation/reviews'];
        } elseif ($st['handoff_chats'] > 0) {
            $top = [$T('فيه عملاء مستنيين رد منك انت شخصيًا', 'Customers are waiting for a reply from you personally'), '/chat'];
        } elseif ($st['pending_bookings'] > 0) {
            $top = [$T('أكّد الحجوزات المستنية عشان العملاء مايروحوش لغيرك', 'Confirm pending bookings so customers don\'t go elsewhere'), '/bookings'];
        } elseif ($st['approvals'] > 0) {
            $top = [$T('راجع الحاجات المستنية موافقتك', 'Review the items awaiting your approval'), '/automation'];
        } elseif ($st['unread_chats'] > 0) {
            $top = [$T('بص على المحادثات الجديدة', 'Check the new conversations'), '/chat'];
        }
        $L[] = '';
        if ($top) {
            $L[] = $T('👉 *أهم حاجة النهارده:* ', '👉 *Top priority today:* ') . $top[0];
            if ($base !== '') {
                $L[] = $base . $top[1];
            }
        } elseif ($base !== '') {
            $L[] = '📊 ' . $base . '/dashboard';
        }

        $activity = $st['competitor_prices'] + $st['new_bookings'] + $st['cancelled'] + $st['new_leads'] + $st['new_reviews'] + $st['trips_today']
            + $st['pending_bookings'] + $st['unread_chats'] + $st['bad_reviews_open'] + $st['approvals'];
        return ['text' => trim(implode("\n", $L)), 'has_activity' => $activity > 0, 'stats' => $st];
    }

    // ------------------------------------------------------------------ الإرسال

    private function platformWhatsappReady(): bool
    {
        return function_exists('env') && (string) env('ULTRAMSG_INSTANCE_ID') !== '' && (string) env('ULTRAMSG_API_KEY') !== '';
    }

    /** رقم واتساب المنصة نفسها (UltraMsg في .env) - نفس اللي بيبعت تنبيهات الأدمن */
    private function sendPlatformWhatsapp(string $to, string $body): bool
    {
        $ch = curl_init('https://api.ultramsg.com/' . rawurlencode((string) env('ULTRAMSG_INSTANCE_ID')) . '/messages/chat');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['token' => (string) env('ULTRAMSG_API_KEY'), 'to' => $to, 'body' => mb_substr($body, 0, 3500)]),
        ]);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300 && is_string($out) && stripos($out, 'error') === false;
    }

    /** موقع العميل اللي عليه واتساب مربوط (احتياطي لو المنصة مالهاش رقم) */
    private function ownWhatsappWebsite(int $ownerId): int
    {
        try {
            return (int) ($this->db->query(
                "SELECT w.id FROM websites w JOIN platform_connections pc ON pc.website_id = w.id AND pc.platform = 'ultramsg' AND pc.status = 'connected'
                  WHERE w.user_id = ? ORDER BY w.id LIMIT 1",
                [$ownerId]
            )[0]['id'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array{ok:bool, channel:?string, error:?string} */
    public function deliver(int $userId, array $s, string $text): array
    {
        $phone = preg_replace('/\D/', '', (string) $s['phone']);
        $err = null;
        if ($s['channel'] !== 'email' && $phone !== '') {
            if ($this->platformWhatsappReady() && $this->sendPlatformWhatsapp($phone, $text)) {
                return ['ok' => true, 'channel' => 'whatsapp', 'error' => null];
            }
            $site = $this->ownWhatsappWebsite($this->scopeOf($userId));
            if ($site > 0) {
                try {
                    if ((new ChatManager())->sendMessageForWebsite($site, $phone, $text, 'whatsapp')) {
                        return ['ok' => true, 'channel' => 'whatsapp', 'error' => null];
                    }
                } catch (Throwable $e) {
                }
            }
            $err = 'الواتساب ماوصلش (مفيش رقم واتساب مربوط)';
        } elseif ($s['channel'] === 'whatsapp') {
            $err = 'مفيش رقم واتساب متسجّل';
        }
        if ($s['channel'] !== 'whatsapp') {
            try {
                $email = (string) ($this->db->query('SELECT email FROM users WHERE id = ? LIMIT 1', [$userId])[0]['email'] ?? '');
                $mailer = new Mailer();
                if ($email !== '' && $mailer->isConfigured()) {
                    $html = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.9;">'
                        . nl2br(htmlspecialchars(str_replace('*', '', $text), ENT_QUOTES, 'UTF-8')) . '</div>';
                    $r = $mailer->context('morning_brief', $userId)->send($email, '', (($s['language'] ?? 'ar') === 'en' ? '☀️ Morning brief - ' : '☀️ ملخص الصبح - ') . date('Y-m-d'), $html);
                    if (!empty($r['success'])) {
                        return ['ok' => true, 'channel' => 'email', 'error' => null];
                    }
                }
                $err = $err ?: 'الإيميل ماوصلش';
            } catch (Throwable $e) {
                $err = $err ?: 'الإيميل ماوصلش';
            }
        }
        return ['ok' => false, 'channel' => null, 'error' => $err ?: 'تعذر الإرسال'];
    }

    private function mark(int $userId, string $status): void
    {
        try {
            $this->db->exec('UPDATE morning_brief_settings SET last_sent_on = ?, last_status = ? WHERE user_id = ?',
                [TripReminderService::localDate(), mb_substr($status, 0, 60), $userId]);
        } catch (Throwable $e) {
        }
    }

    /** إرسال فوري (زرار "ابعتلي دلوقتي") - بيبعت حتى لو اليوم هادي */
    public function sendNow(int $userId): array
    {
        $s = $this->settings($userId);
        $b = $this->build($userId, $s['language']);
        return $this->deliver($userId, $s, $b['text']);
    }

    /** الكرون: كل اللي مفعّلين ووصل ميعادهم ولسه مااتبعتلهمش النهارده */
    public function runDue(): array
    {
        $out = ['sent' => 0, 'quiet' => 0, 'failed' => 0];
        $today = TripReminderService::localDate();
        $hour = (int) TripReminderService::localDate('now', 'G');
        try {
            $rows = $this->db->query(
                "SELECT s.user_id FROM morning_brief_settings s JOIN users u ON u.id = s.user_id AND u.status = 'active'
                  WHERE s.enabled = 1 AND s.send_hour <= ? AND (s.last_sent_on IS NULL OR s.last_sent_on < ?) LIMIT 500",
                [$hour, $today]
            );
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $s = $this->settings($uid);
            $b = $this->build($uid, $s['language']);
            if (!$b['has_activity'] && $s['skip_quiet_days']) {
                $this->mark($uid, 'quiet');
                $out['quiet']++;
                continue;
            }
            $r = $this->deliver($uid, $s, $b['text']);
            $this->mark($uid, $r['ok'] ? 'sent_' . $r['channel'] : 'failed: ' . $r['error']);
            $r['ok'] ? $out['sent']++ : $out['failed']++;
        }
        return $out;
    }
}
