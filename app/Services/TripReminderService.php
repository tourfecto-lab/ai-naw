<?php

/**
 * Tourfecto - تذكير العميل قبل الرحلة بيوم (واتساب أو إيميل)
 *
 * اليوم اللي قبل الرحلة، في الساعة اللي صاحب النشاط اختارها، كل حجز مؤكد بيوصله رسالة
 * فيها الميعاد ومكان التجمع ورقم التواصل. ده بيقلل اللي مابيجوش ويقلل أسئلة "هو الميعاد إمتى؟".
 * كل حجز بيتبعتله مرة واحدة بس (reminder_sent_at)، وصاحب النشاط يقدر يبعت يدوي من صفحة الحجوزات.
 */
class TripReminderService
{
    public const DEFAULT_TEMPLATE = "أهلاً {name} 👋\n"
        . "بنفكّرك إن رحلتك \"{trip}\" {when}{time}.\n"
        . "👥 عدد الأفراد: {people}\n"
        . "📍 مكان التجمع: {meeting_point}\n"
        . "📞 لو محتاج أي حاجة كلّمنا: {contact}\n"
        . "كود الحجز: {reference}\n"
        . "مستنيينك! 🌟";

    public const DEFAULT_TEMPLATE_EN = "Hi {name} 👋\n"
        . "A quick reminder that your trip \"{trip}\" is {when}{time}.\n"
        . "👥 People: {people}\n"
        . "📍 Meeting point: {meeting_point}\n"
        . "📞 Need anything? Contact us: {contact}\n"
        . "Booking code: {reference}\n"
        . "See you soon! 🌟";

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /**
     * "بكرة" والساعة بتوقيت صاحب النشاط (CRON_TIMEZONE، افتراضي القاهرة) مش توقيت السيرفر (غالبًا UTC)،
     * عشان "6 مساءً" تبقى 6 مساءً عنده فعلاً.
     */
    public static function localDate(string $modify = 'now', string $format = 'Y-m-d'): string
    {
        $tzName = function_exists('env') ? (string) (env('CRON_TIMEZONE') ?: 'Africa/Cairo') : 'Africa/Cairo';
        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Africa/Cairo');
        }
        return (new DateTime($modify, $tz))->format($format);
    }

    private static function dateForSettings(array $settings,string $modify): string
    {
        return (new DateTimeImmutable('now',new DateTimeZone($settings['timezone'] ?? 'Africa/Cairo')))->modify($modify)->format('Y-m-d');
    }

    public function settings(int $userId): array
    {
        $row = null;
        try {
            $row = $this->db->query('SELECT * FROM booking_reminder_settings WHERE user_id = ? LIMIT 1', [$userId])[0] ?? null;
        } catch (Throwable $e) {
        }
        return [
            'enabled' => (int) ($row['enabled'] ?? 0),
            'send_hour' => (int) ($row['send_hour'] ?? 18),
            'channel' => (string) ($row['channel'] ?? 'auto'),
            'meeting_point' => (string) ($row['meeting_point'] ?? ''),
            'contact_phone' => (string) ($row['contact_phone'] ?? ''),
            'message_template' => (string) (($row['message_template'] ?? '') ?: self::DEFAULT_TEMPLATE),
            'default_template' => self::DEFAULT_TEMPLATE,
            'language' => in_array($row['language'] ?? 'auto', ['auto', 'ar', 'en'], true) ? (string) ($row['language'] ?? 'auto') : 'auto',
            'message_template_en' => (string) (($row['message_template_en'] ?? '') ?: self::DEFAULT_TEMPLATE_EN),
            'default_template_en' => self::DEFAULT_TEMPLATE_EN,
        ];
    }

    /** @return array{ok:bool, error?:string} */
    public function save(int $userId, array $in): array
    {
        $hour = (int) ($in['send_hour'] ?? 18);
        if ($hour < 6 || $hour > 22) {
            return ['ok' => false, 'error' => 'الساعة لازم تكون بين 6 الصبح و10 بالليل'];
        }
        $channel = in_array($in['channel'] ?? 'auto', ['auto', 'whatsapp', 'email'], true) ? (string) ($in['channel'] ?? 'auto') : 'auto';
        $tpl = trim((string) ($in['message_template'] ?? ''));
        if (mb_strlen($tpl) > 1500) {
            return ['ok' => false, 'error' => 'الرسالة طويلة أوي (الحد 1500 حرف)'];
        }
        if ($tpl === self::DEFAULT_TEMPLATE) {
            $tpl = '';
        }
        $tplEn = trim((string) ($in['message_template_en'] ?? ''));
        if (mb_strlen($tplEn) > 1500) {
            return ['ok' => false, 'error' => 'الرسالة الإنجليزي طويلة أوي (الحد 1500 حرف)'];
        }
        if ($tplEn === self::DEFAULT_TEMPLATE_EN) {
            $tplEn = '';
        }
        $lang = in_array($in['language'] ?? 'auto', ['auto', 'ar', 'en'], true) ? (string) ($in['language'] ?? 'auto') : 'auto';
        $saved = $this->db->exec(
            'INSERT INTO booking_reminder_settings (user_id, enabled, send_hour, channel, meeting_point, contact_phone, message_template, language, message_template_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), send_hour = VALUES(send_hour), channel = VALUES(channel),
                meeting_point = VALUES(meeting_point), contact_phone = VALUES(contact_phone), message_template = VALUES(message_template),
                language = VALUES(language), message_template_en = VALUES(message_template_en)',
            [
                $userId, !empty($in['enabled']) ? 1 : 0, $hour, $channel,
                mb_substr(trim(strip_tags((string) ($in['meeting_point'] ?? ''))), 0, 500) ?: null,
                mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($in['contact_phone'] ?? '')), 0, 40) ?: null,
                $tpl !== '' ? strip_tags($tpl) : null,
                $lang,
                $tplEn !== '' ? strip_tags($tplEn) : null,
            ]
        );
        if (!$saved) {
            return ['ok' => false, 'error' => 'تعذر الحفظ - جرّب تاني'];
        }
        return ['ok' => true];
    }

    /**
     * لغة الرسالة من اختيار صريح أو جمهور الموقع، دون تخمينها من اسم العميل
     * (أغلب الأجانب بيكتبوا اسمهم بالإنجليزي).
     */
    public static function languageFor(array $b, array $s): string
    {
        $lang = $s['language'] ?? 'auto';
        if ($lang === 'ar' || $lang === 'en') {
            return $lang;
        }
        $wid=(int)($b['website_id']??$s['website_id']??0);
        $chosen=(string)($b['customer_language']??($wid?ContentLanguageService::forWebsite($wid,(int)($b['user_id']??0)):'en'));
        return preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$chosen)?$chosen:'en';
    }

    /** نص الرسالة لحجز معيّن */
    public function render(array $b, array $s, ?string $forceLang = null): string
    {
        $lang = $forceLang ?: self::languageFor($b, $s);
        $people = (int) ($b['adults_count'] ?? 1) + (int) ($b['children_count'] ?? 0);
        $d = (string) ($b['start_date'] ?? '');
        $hm = !empty($b['start_time']) ? substr((string) $b['start_time'], 0, 5) : '';
        if ($lang !== 'ar') {
            $when = $d === self::dateForSettings($s,'+1 day') ? 'tomorrow, ' . $d : ($d === self::dateForSettings($s,'now') ? 'today' : 'on ' . $d);
            $vars = [
                '{when}' => $when, '{name}' => trim((string) ($b['customer_name'] ?? '')) ?: 'there',
                '{trip}' => (string) ($b['product_name'] ?? 'your trip'), '{date}' => $d, '{time}' => $hm !== '' ? ' at ' . $hm : '',
                '{people}' => (string) $people,
                '{meeting_point}' => $s['meeting_point'] !== '' ? $s['meeting_point'] : 'we will send you the details',
                '{contact}' => $s['contact_phone'] !== '' ? $s['contact_phone'] : '-',
                '{reference}' => (string) ($b['booking_reference'] ?? ''),
            ];
            $text = strtr(($s['message_template_en'] ?? '') ?: self::DEFAULT_TEMPLATE_EN, $vars);
            $text = preg_replace("/^[^\n]*: -[ \t]*(\n|$)/mu", '', $text);
            return trim(preg_replace("/\n{3,}/", "\n\n", $text));
        }
        $time = $hm !== '' ? ' الساعة ' . $hm : '';
        $when = $d === self::dateForSettings($s,'+1 day') ? 'بكرة ' . $d : ($d === self::dateForSettings($s,'now') ? 'النهارده' : 'يوم ' . $d);
        $vars = [
            '{when}' => $when,
            '{name}' => trim((string) ($b['customer_name'] ?? '')) ?: 'عميلنا',
            '{trip}' => (string) ($b['product_name'] ?? 'رحلتك'),
            '{date}' => (string) ($b['start_date'] ?? ''),
            '{time}' => $time,
            '{people}' => (string) $people,
            '{meeting_point}' => $s['meeting_point'] !== '' ? $s['meeting_point'] : 'هنبعتلك التفاصيل',
            '{contact}' => $s['contact_phone'] !== '' ? $s['contact_phone'] : '-',
            '{reference}' => (string) ($b['booking_reference'] ?? ''),
        ];
        $text = strtr($s['message_template'] ?: self::DEFAULT_TEMPLATE, $vars);
        // سطر مكان التجمع/التواصل يتشال لو مش متحدد بدل ما يطلع فاضي
        $text = preg_replace("/^[^\n]*: -[ \t]*(\n|$)/mu", '', $text);
        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** الموقع المحدد للحجز، أو الموقع الوحيد عند غياب ربط واضح */
    private function websiteFor(int $userId,int $websiteId=0): int
    {
        try {
            if($websiteId>0)return (int)($this->db->query('SELECT id FROM websites WHERE id=? AND user_id=?',[$websiteId,$userId])[0]['id']??0);
            $sites=$this->db->query('SELECT id FROM websites WHERE user_id=? ORDER BY id LIMIT 2',[$userId]);return count($sites)===1?(int)$sites[0]['id']:0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function whatsappReady(int $websiteId): bool
    {
        if ($websiteId <= 0) {
            return false;
        }
        try {
            return (bool) $this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform IN ('ultramsg','whatsapp_cloud','whatsapp_qr') AND status = 'connected' LIMIT 1", [$websiteId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * إرسال لمستلم واحد. @return array{ok:bool, channel:?string, error:?string}
     */
    public function deliver(int $userId, string $channelPref, ?string $phone, ?string $email, string $text, string $subject,array $booking=[]): array
    {
        try{$phone=$phone?WhatsAppCloudAPI::normalizePhone($phone):'';}catch(InvalidArgumentException $e){return ['ok'=>false,'channel'=>null,'error'=>$e->getMessage()];}
        $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        $websiteId = $this->websiteFor($userId,(int)($booking['website_id']??0));
        $tryWa = $phone !== '' && $channelPref !== 'email';
        $tryMail = $email !== '' && $channelPref !== 'whatsapp';
        $lastError = null;

        if ($tryWa) {
            if (!$this->whatsappReady($websiteId)) {
                $lastError = 'واتساب مش مربوط - اربطه من صفحة التكاملات';
            } else {
                try {
                    if(WhatsAppCloudAPI::connectionRow($websiteId)){
                        if(empty($booking['id']))return ['ok'=>false,'channel'=>'whatsapp','error'=>'Select a booking before sending its reminder.'];
                        $w=$this->db->query('SELECT company_name FROM websites WHERE id=? AND user_id=?',[$websiteId,$userId])[0];
                        $language=self::languageFor($booking,['website_id'=>$websiteId,'language'=>$booking['reminder_language']??'auto']);
                        $params=[$booking['customer_name']??'Guest',$w['company_name']??'Tour operator',$booking['product_name']??'Your trip',($booking['start_date']??'').' '.($booking['start_time']??'')];
                        return WhatsAppTransactionalService::send($userId,$websiteId,$phone,'tf_trip_reminder',$language,$params,'booking:'.(int)($booking['id']??0));
                    }
                    if ((new ChatManager())->sendMessageForWebsite($websiteId, $phone, $text, 'whatsapp')) {
                        return ['ok' => true, 'channel' => 'whatsapp', 'error' => null];
                    }
                    return ['ok'=>false,'channel'=>'whatsapp','error'=>'نتيجة إرسال واتساب غير مؤكدة؛ راجع التسليم قبل إعادة الإرسال','uncertain'=>true];
                } catch (Throwable $e) {
                    return ['ok'=>false,'channel'=>'whatsapp','error'=>'نتيجة إرسال واتساب غير مؤكدة؛ راجع التسليم قبل إعادة الإرسال','uncertain'=>true];
                }
            }
        }
        if ($tryMail) {
            try {
                $mailer = class_exists('SmtpSettingsService') ? (new SmtpSettingsService())->mailerForUser($userId) : new Mailer();
                if (!$mailer->isConfigured()) {
                    $lastError = 'البريد مش متظبط على السيرفر';
                } else {
                    $html = '<div dir="auto" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.8;">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>';
                    $r = $mailer->context('trip_reminder', $userId)->send($email, '', $subject, $html);
                    if (!empty($r['success'])) {
                        return ['ok' => true, 'channel' => 'email', 'error' => null];
                    }
                    return ['ok'=>false,'channel'=>'email','error'=>'نتيجة إرسال البريد غير مؤكدة؛ راجع التسليم قبل إعادة الإرسال','uncertain'=>true];
                }
            } catch (Throwable $e) {
                return ['ok'=>false,'channel'=>'email','error'=>'نتيجة إرسال البريد غير مؤكدة؛ راجع التسليم قبل إعادة الإرسال','uncertain'=>true];
            }
        }
        if (!$tryWa && !$tryMail) {
            $lastError = $channelPref === 'whatsapp' ? 'العميل مالوش رقم موبايل' : ($channelPref === 'email' ? 'العميل مالوش إيميل' : 'العميل مالوش رقم ولا إيميل');
        }
        return ['ok' => false, 'channel' => null, 'error' => $lastError];
    }

    private function bookingRow(int $bookingId): ?array
    {
        return $this->db->query(
            'SELECT b.*, p.name AS product_name,p.website_id AS website_id FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id WHERE b.id = ? LIMIT 1',
            [$bookingId]
        )[0] ?? null;
    }

    /** إرسال تذكير لحجز واحد وتسجيل النتيجة. @return array{ok:bool, channel:?string, error:?string} */
    public function sendForBooking(array $b, ?array $s = null): array
    {
        $id = (int) $b['id'];
        $claimed = $this->db->transaction(function () use ($id) {
            $row = $this->db->query('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
            if (!$row || !in_array($row['status'], ['pending','confirmed'], true) || $row['reminder_sent_at'] || $row['reminder_claimed_at'] || (int)$row['reminder_attempts'] >= 3) { return false; }
            if(!$this->db->exec("UPDATE bookings SET reminder_claimed_at=NOW(), reminder_status='processing', reminder_attempts=reminder_attempts+1 WHERE id=?",[$id]))throw new RuntimeException('Reminder claim failed');
            return $row;
        });
        if (!$claimed) { return ['ok'=>false,'channel'=>null,'error'=>'التذكير أُرسل أو قيد المعالجة؛ راجع حالته قبل إعادة المحاولة','skipped'=>true]; }
        $mapped=$this->db->query('SELECT website_id FROM crm_products WHERE id=? AND user_id=?',[(int)$claimed['product_id'],(int)$claimed['user_id']])[0]??[];
        $b=$claimed + $b;$b['website_id']=(int)($mapped['website_id']??0);
        $userId = (int)$b['user_id'];
        try {
        $s = $s ?: $this->settings($userId);
        $s['timezone'] = (new BookingOperationsService($this->db))->timezone($userId);
        $b['reminder_language']=$s['language'];$s['website_id']=$b['website_id'];
        $text = $this->render($b,$s);
        $subject = (self::languageFor($b,$s)!=='ar'?'Trip reminder - ':'تذكير برحلتك - ').($b['product_name']??'').' ('.($b['booking_reference']??'').')';
            $r = $this->deliver($userId,$s['channel'],$b['customer_phone']??null,$b['customer_email']??null,$text,$subject,$b);
            if (!empty($r['uncertain'])) {
                $this->db->exec("UPDATE bookings SET reminder_status='manual_review', reminder_error=? WHERE id=?",[$r['error'],$id]);
            } elseif ($r['ok']) {
                $this->db->exec('UPDATE bookings SET reminder_sent_at=NOW(), reminder_status=?, reminder_error=NULL, reminder_claimed_at=NULL, reminder_next_attempt_at=NULL WHERE id=?',['sent_'.$r['channel'],$id]);
            } else {
                $this->db->exec("UPDATE bookings SET reminder_status='failed', reminder_error=?, reminder_claimed_at=NULL, reminder_next_attempt_at=DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id=?",[mb_substr((string)$r['error'],0,255),$id]);
            }
            return $r;
        } catch (Throwable $e) {
            // Keep the claim reserved. A send may have succeeded before a database/network error.
            if(class_exists('Logger'))Logger::warning('Trip reminder requires reconciliation', ['booking_id'=>$id]);
            try{$this->db->exec("UPDATE bookings SET reminder_status='manual_review' WHERE id=?",[$id]);}catch(Throwable $ignored){}
            return ['ok'=>false,'channel'=>null,'uncertain'=>true,'error'=>'راجع حالة التسليم قبل إعادة الإرسال'];
        }
    }

    public function sendForBookingId(int $ownerId, int $bookingId): array
    {
        $b = $this->bookingRow($bookingId);
        if (!$b || (int) $b['user_id'] !== $ownerId) {
            return ['ok' => false, 'channel' => null, 'error' => 'الحجز غير موجود'];
        }
        if (!in_array($b['status'], ['confirmed', 'pending'], true)) {
            return ['ok' => false, 'channel' => null, 'error' => 'الحجز ده مش شغّال (ملغي أو خلص)'];
        }
        return $this->sendForBooking($b);
    }

    /**
     * الكرون: حجوزات بكرة المؤكدة اللي صاحبها مفعّل التذكير ووصل ميعاد الإرسال عنده.
     * @return array{sent:int, failed:int, owners:int}
     */
    public function runDue(): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'owners' => 0];
        $rows = [];
        $owners = $this->db->query("SELECT DISTINCT s.user_id, o.reminder_last_scan_at FROM booking_reminder_settings s JOIN bookings b ON b.user_id=s.user_id LEFT JOIN workspace_operations_settings o ON o.user_id=s.user_id WHERE s.enabled=1 AND b.status='confirmed' AND b.reminder_sent_at IS NULL AND b.start_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 2 DAY) ORDER BY o.reminder_last_scan_at ASC, s.user_id ASC LIMIT 200");
        foreach ($owners as $owner) {
            $uid = (int)$owner['user_id'];
            $this->db->exec("INSERT INTO workspace_operations_settings(user_id,reminder_last_scan_at) VALUES(?,NOW()) ON DUPLICATE KEY UPDATE reminder_last_scan_at=NOW()",[$uid]);
            $tz = new DateTimeZone((new BookingOperationsService($this->db))->timezone($uid));
            $now = new DateTimeImmutable('now',$tz);
            $settings = $this->settings($uid);
            if ((int)$now->format('G') < $settings['send_hour']) { continue; }
            $limit = 300-count($rows);
            if ($limit <= 0) { break; }
            $due = $this->db->query("SELECT b.*, p.name AS product_name,p.website_id AS website_id FROM bookings b LEFT JOIN crm_products p ON p.id=b.product_id WHERE b.user_id=? AND b.status='confirmed' AND b.start_date=? AND b.reminder_sent_at IS NULL AND b.reminder_claimed_at IS NULL AND b.reminder_attempts<3 AND (b.reminder_next_attempt_at IS NULL OR b.reminder_next_attempt_at<=NOW()) ORDER BY b.id LIMIT " . $limit,[$uid,$now->modify('+1 day')->format('Y-m-d')]);
            $rows = array_merge($rows,$due);
        }
        $settings = [];
        $failedBy = [];
        foreach ($rows as $b) {
            $uid = (int) $b['user_id'];
            $settings[$uid] ??= $this->settings($uid);
            $r = $this->sendForBooking($b, $settings[$uid]);
            if ($r['ok']) {
                $out['sent']++;
            } else {
                $out['failed']++;
                $failedBy[$uid][] = $b['booking_reference'] . ' (' . $r['error'] . ')';
            }
        }
        $out['owners'] = count($settings);
        foreach ($failedBy as $uid => $list) {
            Notification::notify($uid, 'trip_reminder_failed',
                'تعذر إرسال ' . count($list) . ' تذكير لرحلات بكرة',
                'كلّم العملاء دول بنفسك: ' . implode('، ', array_slice($list, 0, 5)) . (count($list) > 5 ? ' …' : ''),
                '/bookings');
        }
        return $out;
    }
}
