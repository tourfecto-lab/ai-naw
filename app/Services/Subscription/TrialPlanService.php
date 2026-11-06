<?php

/**
 * Tourfecto - «خطة أول 3 أيام» للعميل في التجربة المجانية
 *
 * الهدف: العميل يشوف نتيجة حقيقية في حسابه قبل ما التجربة تخلص، مش يتوه في 40 صفحة.
 * كل يوم فيه 3 مهام بتنتج قيمة فعلية، وكل مهمة بتتعلّم «خلصت» لوحدها من بيانات حسابه.
 * «اللي المنصة عملته لك» أرقام حقيقية من حسابه بس - أي رقم صفر مابيظهرش.
 * نفس الخطة بتتبعت إيميل أول اليوم التاني والتالت (مرة واحدة لكل يوم).
 */
class TrialPlanService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** null لو العميل مش في تجربة مجانية */
    public function forUser(int $userId, ?string $lang = null): ?array
    {
        $sub = $this->trialFor($userId);
        if (!$sub) {
            return null;
        }
        return $this->build($userId, $sub, $lang ?? (function_exists('current_lang') ? current_lang() : 'ar'));
    }

    /** علامة إن العميل جرّب الشات كأنه سائح (الاختبار نفسه مابيتسجلش) */
    public static function markPlaygroundUsed(int $userId): void
    {
        try {
            $db = Database::getInstance();
            $key = 'trial_pg_used_' . $userId;
            if (!$db->query('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1', [$key])) {
                $db->query('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)', [$key, gmdate('Y-m-d H:i:s')]);
            }
        } catch (Throwable $e) {
        }
    }

    /** الإيميل اليومي: أول اليوم التاني والتالت من التجربة - مرة واحدة لكل يوم */
    public function runDailyEmails(): array
    {
        $out = ['checked' => 0, 'sent' => 0, 'skipped' => 0];
        try {
            $rows = $this->db->query(
                "SELECT s.id, s.user_id, s.trial_ends_at, u.email, u.first_name, u.company_name, u.language
                 FROM subscriptions s JOIN users u ON u.id = s.user_id
                 WHERE s.status = 'trialing' AND s.trial_ends_at > NOW()"
            );
        } catch (Throwable $e) {
            return $out + ['error' => 'query failed'];
        }
        $mailer = class_exists('Mailer') ? new Mailer() : null;
        if (!$mailer || !$mailer->isConfigured()) {
            return $out + ['error' => 'mailer not configured'];
        }
        foreach ($rows as $row) {
            $out['checked']++;
            $lang = in_array($row['language'] ?? '', ['ar', 'en', 'fr', 'de'], true) ? $row['language'] : 'ar';
            $plan = $this->build((int) $row['user_id'], $row, $lang);
            if ($plan['day'] < 2) {
                $out['skipped']++;
                continue;
            }
            $flag = 'trial_mail_' . (int) $row['id'] . '_d' . $plan['day'];
            if ($this->db->query('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1', [$flag])) {
                continue;
            }
            // نحجز العلامة قبل الإرسال عشان تشغيلين ورا بعض مايبعتوش مرتين
            $this->db->query('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)', [$flag, gmdate('Y-m-d H:i:s')]);
            $name = trim((string) ($row['first_name'] ?: $row['company_name'] ?: ''));
            [$subject, $html] = $this->email($plan, $name, $lang);
            $res = $mailer->send((string) $row['email'], $name, $subject, $html);
            if (!empty($res['success'])) {
                $out['sent']++;
            } else {
                // الإرسال فشل: نشيل العلامة عشان يتحاول تاني في التشغيلة الجاية
                $this->db->query('DELETE FROM system_settings WHERE setting_key = ?', [$flag]);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ البناء

    /**
     * ملخص تقدّم التجربة لقائمة الأدمن (TrialFollowUpService → admin-insights.js: done/total/next/value).
     * كانت TrialFollowUpService بتناديها جوه try/catch(Throwable) من غير ما تكون معرّفة، فحقل progress
     * ما كانش بيظهر للأدمن وماحدش بيتنبّه.
     *
     * @return array{done:int,total:int,next:string,value:array,day:int,days:int}
     */
    public function adminProgress(int $userId, string $trialEndsAt): array
    {
        $b = $this->build($userId, ['user_id' => $userId, 'trial_ends_at' => $trialEndsAt], 'ar');
        $next = '';
        foreach ($b['plan'] as $day) {
            foreach ($day['tasks'] as $task) {
                if (!$task['done']) {
                    $next = (string) $task['label'];
                    break 2;
                }
            }
        }
        return ['done' => $b['done'], 'total' => $b['total'], 'next' => $next, 'value' => $b['value'], 'day' => $b['day'], 'days' => $b['days']];
    }

    private function trialFor(int $userId): ?array
    {
        try {
            $owner = (int) ($this->db->query('SELECT COALESCE(NULLIF(owner_user_id, 0), id) AS o FROM users WHERE id = ? LIMIT 1', [$userId])[0]['o'] ?? $userId);
            // لو عنده اشتراك فعّال (حتى لو اتلغى وهيخلص آخر المدة) مايبقاش «تجربة مجانية» في الداشبورد ويتناقض مع صفحة الاشتراك
            if ($this->db->query("SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1", [$owner])) {
                return null;
            }
            return $this->db->query(
                "SELECT id, user_id, trial_ends_at FROM subscriptions WHERE user_id = ? AND status = 'trialing' AND trial_ends_at > NOW() ORDER BY id DESC LIMIT 1",
                [$owner]
            )[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function build(int $userId, array $sub, string $lang): array
    {
        $ar = $lang === 'ar';
        $t = static fn (string $a, string $e) => $ar ? $a : $e;
        $owner = (int) $sub['user_id'];
        $days = 3;
        try {
            $days = max(1, (int) ((new PlanExtras())->config()['trial_days'] ?? 3));
        } catch (Throwable $e) {
        }
        $end = strtotime((string) $sub['trial_ends_at']) ?: time();
        $start = $end - $days * 86400;
        $day = max(1, min($days, (int) floor((time() - $start) / 86400) + 1));
        $hoursLeft = max(0, (int) ceil(($end - time()) / 3600));

        $c = fn (string $sql) => $this->count($sql, [$owner]);
        $m = [
            'trips' => $c('SELECT COUNT(*) AS c FROM crm_products WHERE user_id = ? AND is_active = 1'),
            'sites' => $c('SELECT COUNT(*) AS c FROM websites WHERE user_id = ?') + $c('SELECT COUNT(*) AS c FROM generated_websites WHERE user_id = ?'),
            'kb' => $c('SELECT COUNT(*) AS c FROM ai_knowledge_base k JOIN websites w ON w.id = k.website_id WHERE w.user_id = ? AND k.deleted_at IS NULL'),
            'gbp' => $c("SELECT COUNT(*) AS c FROM platform_connections WHERE user_id = ? AND platform = 'google_business' AND status = 'connected'"),
            'wa' => $c("SELECT COUNT(*) AS c FROM platform_connections WHERE user_id = ? AND platform IN ('whatsapp_cloud','ultramsg','whatsapp_qr','whatsapp') AND status = 'connected'"),
            'reviews' => $c('SELECT COUNT(*) AS c FROM reviews WHERE user_id = ?'),
            'replies' => $c('SELECT COUNT(*) AS c FROM reviews WHERE user_id = ? AND reply_sent_at IS NOT NULL'),
            'chats' => $c("SELECT COUNT(*) AS c FROM ai_conversations WHERE user_id = ?"),
            'bookings' => $c('SELECT COUNT(*) AS c FROM bookings WHERE user_id = ?'),
            'reports' => $c('SELECT COUNT(*) AS c FROM ai_reports WHERE user_id = ?'),
            'paid' => $c("SELECT COUNT(*) AS c FROM subscriptions WHERE user_id = ? AND status = 'active'"),
        ];
        $pg = (bool) $this->db->query('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1', ['trial_pg_used_' . $userId])
            || ($userId !== $owner && (bool) $this->db->query('SELECT id FROM system_settings WHERE setting_key = ? LIMIT 1', ['trial_pg_used_' . $owner]));

        $plan = [
            1 => ['title' => $t('جهّز شركتك', 'Set up your company'), 'tasks' => [
                ['done' => $m['trips'] > 0, 'label' => $t('ضيف رحلاتك وأسعارها', 'Add your trips and prices'), 'why' => $t('الشات الذكي بيبيعها لوحده بالسعر الصح', 'The AI chat sells them on its own at the right price'), 'link' => '/bookings?tab=trips'],
                ['done' => $m['sites'] > 0, 'label' => $t('ضيف موقعك أو ابنِ موقع حجز', 'Add your website or build a booking site'), 'why' => $t('عشان السيو والحجز الأونلاين يشتغلوا عليه', 'So SEO and online booking work on it'), 'link' => '/websites'],
                // تجربة الشات محتاجة موقع: من غيره الصفحة كانت بتقول «ضيف موقعك الأول» من غير ما المهمة تقول كده
                $m['sites'] > 0 || $pg
                    ? ['done' => $pg, 'label' => $t('جرّب الشات كأنك سائح', 'Try the chat as a traveller'), 'why' => $t('اسأله عن سعر رحلة بالإنجليزي أو الألماني', 'Ask it a price in English or German'), 'link' => '/chat/knowledge-base']
                    : ['done' => false, 'label' => $t('جرّب الشات كأنك سائح', 'Try the chat as a traveller'), 'why' => $t('محتاج موقعك الأول: ابنِ موقع حجز في دقايق وبعدها جرّب الشات', 'It needs your website first: build a booking site in minutes, then try the chat'), 'link' => '/website-builder'],
            ]],
            2 => ['title' => $t('خلّي المنصة تشتغل لك', 'Let the platform work for you'), 'tasks' => [
                ['done' => $m['gbp'] > 0, 'label' => $t('اربط Google Business', 'Connect Google Business'), 'why' => $t('تقييماتك بتتسحب وبيترد عليها بلغة كل سائح', 'Your reviews are pulled and answered in each traveller’s language'), 'link' => '/integrations'],
                ['done' => $m['replies'] > 0, 'label' => $t('رد على أول تقييم بالذكاء الاصطناعي', 'Reply to your first review with AI'), 'why' => $t('رد محترف في ثواني، وانت بتوافق قبل النشر', 'A professional reply in seconds - you approve before it’s posted'), 'link' => '/reputation/reviews'],
                ['done' => $m['wa'] > 0, 'label' => $t('اربط واتساب شركتك', 'Connect your business WhatsApp'), 'why' => $t('تذكير الرحلات وطلب التقييم بيوصلوا للسائح على الواتساب', 'Trip reminders and review requests reach travellers on WhatsApp'), 'link' => '/integrations'],
            ]],
            3 => ['title' => $t('شوف النتيجة', 'See the results'), 'tasks' => [
                ['done' => $m['bookings'] > 0, 'label' => $t('سجّل أو استقبل أول حجز', 'Record or receive your first booking'), 'why' => $t('حجز تليفون أو واتساب بيتسجل في ثانية ويوصله تذكير', 'A phone or WhatsApp booking is recorded in seconds and gets a reminder'), 'link' => '/bookings'],
                ['done' => $m['reports'] > 0, 'label' => $t('شغّل فحص السيو لموقعك', 'Run an SEO check on your website'), 'why' => $t('هتعرف بالظبط ليه السياح مش لاقيينك على جوجل', 'Find out exactly why travellers don’t find you on Google'), 'link' => '/seo-expert'],
                ['done' => $m['paid'] > 0, 'label' => $t('اختار باقتك عشان كل ده يفضل شغّال', 'Pick your plan so all of this keeps running'), 'why' => $t('بياناتك وإعداداتك بتفضل زي ما هي', 'Your data and settings stay exactly as they are'), 'link' => '/plans'],
            ]],
        ];
        if ($days < 3) {
            $plan = array_slice($plan, 0, $days, true);
        }

        // أرقام حقيقية من الحساب بس، والصفر مابيظهرش
        $value = array_values(array_filter([
            [$m['reviews'], $t('تقييم اتسحب من Google لوحده', 'reviews pulled from Google automatically')],
            [$m['replies'], $t('تقييم اترد عليه', 'reviews answered')],
            [$m['chats'], $t('محادثة مع سياح على الشات', 'chat conversations with travellers')],
            [$m['bookings'], $t('حجز اتسجّل', 'bookings recorded')],
            [$m['trips'], $t('رحلة جاهزة للبيع', 'trips ready to sell')],
        ], fn ($v) => $v[0] > 0));

        $done = 0;
        $total = 0;
        foreach ($plan as $d) {
            foreach ($d['tasks'] as $task) {
                $total++;
                $done += $task['done'] ? 1 : 0;
            }
        }
        return ['day' => $day, 'days' => $days, 'hours_left' => $hoursLeft, 'plan' => $plan, 'value' => $value, 'done' => $done, 'total' => $total, 'lang' => $lang];
    }

    private function count(string $sql, array $args): int
    {
        try {
            return (int) ($this->db->query($sql, $args)[0]['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array{0:string,1:string} [subject, html] */
    private function email(array $p, string $name, string $lang): array
    {
        $ar = $lang === 'ar';
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $base = rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.pro', '/');
        $app = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $last = $p['day'] >= $p['days'];
        $subject = $ar
            ? ($last ? 'آخر يوم في تجربتك - ده اللي اتعمل لحد دلوقتي' : 'يومك ' . $p['day'] . ' في ' . $app . ': خطوات النهارده عشان تشوف نتيجة')
            : ($last ? 'Last day of your trial - here is what got done' : 'Day ' . $p['day'] . ' on ' . $app . ': today’s steps to see results');
        $today = $p['plan'][$p['day']] ?? end($p['plan']);
        $tasks = '';
        foreach ($today['tasks'] as $task) {
            $tasks .= '<tr><td style="padding:8px 0;font-size:15px;">' . ($task['done'] ? '✅' : '⬜') . ' <a href="' . $e($base . $task['link']) . '" style="color:#1d4ed8;text-decoration:none;font-weight:600;">' . $e($task['label']) . '</a>'
                . '<div style="color:#64748b;font-size:13px;">' . $e($task['why']) . '</div></td></tr>';
        }
        $value = '';
        foreach ($p['value'] as [$n, $label]) {
            $value .= '<li style="margin:4px 0;"><b>' . (int) $n . '</b> ' . $e($label) . '</li>';
        }
        $dir = $ar ? 'rtl' : 'ltr';
        $hi = $ar ? 'أهلًا' . ($name !== '' ? ' ' . $e($name) : '') : 'Hi' . ($name !== '' ? ' ' . $e($name) : '');
        $html = '<div dir="' . $dir . '" style="font-family:Segoe UI,Tahoma,Arial,sans-serif;max-width:560px;margin:auto;color:#0f172a;line-height:1.7;">'
            . '<p style="font-size:16px;">' . $hi . ' 👋</p>'
            . '<p>' . ($ar ? 'ده اليوم <b>' . $p['day'] . '</b> من ' . $p['days'] . ' في تجربتك المجانية. خطوات النهارده:' : 'This is day <b>' . $p['day'] . '</b> of ' . $p['days'] . ' of your free trial. Today’s steps:') . '</p>'
            . '<h3 style="margin:16px 0 4px;">' . $e($today['title']) . '</h3><table style="width:100%;border-collapse:collapse;">' . $tasks . '</table>'
            . ($value !== '' ? '<h3 style="margin:20px 0 4px;">' . ($ar ? 'اللي المنصة عملته لك لحد دلوقتي' : 'What the platform has done for you so far') . '</h3><ul style="padding-inline-start:20px;margin:0;">' . $value . '</ul>' : '')
            . '<p style="margin-top:22px;"><a href="' . $e($base . '/dashboard') . '" style="background:#2563eb;color:#fff;padding:11px 20px;border-radius:10px;text-decoration:none;font-weight:700;">' . ($ar ? 'افتح لوحتك' : 'Open your dashboard') . '</a></p>'
            . ($last ? '<p style="color:#64748b;font-size:13px;">' . ($ar ? 'عشان كل ده يفضل شغّال بعد التجربة، اختار باقتك وكلمنا على الواتساب أو الإيميل ونفعّلهالك في نفس اليوم.' : 'To keep all of this running after the trial, pick your plan and message us on WhatsApp or email - we activate it the same day.') . '</p>' : '')
            . '</div>';
        return [$subject, $html];
    }
}
