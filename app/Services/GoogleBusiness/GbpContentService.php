<?php

/**
 * Tourfecto - Google Business Profile Content Service
 * توليد محتوى منشورات GBP بالذكاء الاصطناعي + جدولة نشرها. النشر
 * الفعلي يعتمد على GoogleBusinessAPI.php الموجود بالفعل في المشروع
 * (نفس اتصال OAuth المستخدم لاستيراد المراجعات) - لا عميل API منفصل.
 * @version 1.0.0
 */
class GbpContentService
{
    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    public function generate(int $userId, int $websiteId, string $type, string $prompt): GbpContent
    {
        $typeLabels = ['update' => 'تحديث عام', 'offer' => 'عرض خاص', 'event' => 'فعالية', 'product' => 'منتج/خدمة'];
        $label = $typeLabels[$type] ?? 'تحديث عام';

        $aiPrompt = "اكتب منشور {$label} قصير لصفحة Google Business Profile عن: \"{$prompt}\". "
            . "بحد أقصى 1500 حرف، أسلوب مباشر وجذاب باللغة العربية.";

        $response = $this->ai->generateContent($aiPrompt, ['task'=>'gbp_content', 'user_id'=>$userId, 'website_id'=>$websiteId, 'maxOutputTokens' => 512]);

        $content = new GbpContent([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'type' => $type,
            'prompt' => $prompt,
            'status' => ($response['success'] ?? false) ? 'ready' : 'failed',
        ]);

        if ($response['success'] ?? false) {
            $content->setAttribute('generated_text', trim((string) ($response['data'] ?? '')));
        }

        $content->save();

        if (!($response['success'] ?? false)) {
            if (class_exists('GbpAuditLogger')) {
                GbpAuditLogger::log('post_create', $websiteId, $userId, 'failed', ['type' => $type]);
            }
            throw new Exception($response['error'] ?? 'فشل توليد المحتوى');
        }

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_create', $websiteId, $userId, 'success', ['type' => $type, 'content_id' => (int) $content->getAttribute('id')]);
        }

        return $content;
    }

    public function schedule(int $contentId, int $platformConnectionId, string $scheduledAt): GbpScheduledPost
    {
        $connection = (new PlatformConnection())->find($platformConnectionId);
        if (!$connection || $connection->getAttribute('platform') !== 'google_business') {
            throw new Exception('اتصال Google Business غير موجود أو غير صحيح');
        }

        $scheduled = new GbpScheduledPost([
            'gbp_content_id' => $contentId,
            'platform_connection_id' => $platformConnectionId,
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
        ]);
        $scheduled->save();

        $delay = max(0, strtotime($scheduledAt) - time());
        $jobId = Container::getInstance()->make(QueueManager::class)->push(
            PublishGbpPostJob::class,
            ['gbp_scheduled_post_id' => (int) $scheduled->getAttribute('id')],
            'gbp',
            $delay
        );

        // Round 6 (2026-08-11): نخزّن معرف المهمة في الطابور عشان نقدر
        // نلغيها بأمان لو المستخدم عمل Cancel قبل وقت النشر (شوف
        // cancelScheduled() تحت). لو push() فشل (jobs table مش موجود)،
        // بنسيب الحقل null - المنشور هيفضل "pending" من غير جدولة فعلية،
        // مش هنكسر أي حاجة.
        if (is_int($jobId)) {
            $scheduled->setAttribute('queue_job_id', $jobId);
            $scheduled->save();
        }

        if (class_exists('GbpAuditLogger')) {
            $content = (new GbpContent())->find($contentId);
            GbpAuditLogger::log(
                'post_update',
                $content ? (int) $content->getAttribute('website_id') : null,
                $content ? (int) $content->getAttribute('user_id') : null,
                'success',
                ['action_detail' => 'scheduled', 'content_id' => $contentId, 'scheduled_at' => $scheduledAt]
            );
        }

        return $scheduled;
    }

    /**
     * تعديل نص منشور GBP قبل ما يتنشر. مسموح بس لو لسه مفيهوش جدولة
     * "pending" فعلية (يعني عدّل النص وأنت لسه في مرحلة المسودة/التوليد)
     * - لو المنشور اتجدول بالفعل، لازم تلغي الجدولة الأول (cancelScheduled)
     * قبل ما تقدر تعدّل، عشان منخليش نص يتغيّر تحت مهمة شغالة في الطابور.
     */
    private const CTA_TYPES = ['LEARN_MORE', 'BOOK', 'ORDER', 'SHOP', 'SIGN_UP', 'CALL'];

    /**
     * تفاصيل العرض/الفعالية/الزرار - Google بيرفض منشور OFFER أو EVENT من غير عنوان وتواريخ،
     * فقبل كده كل الأنواع كانت بتتنشر "تحديث عادي" وتضيع قيمة العرض.
     * @throws Exception لو البيانات ناقصة أو غلط
     */
    public function applyDetails(GbpContent $content, array $in): void
    {
        $type = (string) $content->getAttribute('type');
        $title = trim((string) ($in['post_title'] ?? $content->getAttribute('post_title') ?? ''));
        $start = trim((string) ($in['event_start'] ?? $content->getAttribute('event_start') ?? ''));
        $end = trim((string) ($in['event_end'] ?? $content->getAttribute('event_end') ?? ''));
        $cta = strtoupper(trim((string) ($in['cta_type'] ?? $content->getAttribute('cta_type') ?? '')));
        $ctaUrl = trim((string) ($in['cta_url'] ?? $content->getAttribute('cta_url') ?? ''));

        if (in_array($type, ['offer', 'event'], true)) {
            if ($title === '' || mb_strlen($title) > 58) {
                throw new Exception($type === 'offer' ? 'اكتب عنوان العرض (لحد 58 حرف)' : 'اكتب اسم الفعالية (لحد 58 حرف)');
            }
            $ts = strtotime($start);
            $te = strtotime($end);
            if (!$ts || !$te) {
                throw new Exception('حدد تاريخ البداية والنهاية');
            }
            if ($te < $ts) {
                throw new Exception('تاريخ النهاية قبل البداية');
            }
            if ($te < time()) {
                throw new Exception('تاريخ النهاية عدّى خلاص');
            }
            $start = date('Y-m-d H:i:s', $ts);
            $end = date('Y-m-d H:i:s', $te);
        } else {
            $start = $end = '';
        }
        if ($cta !== '' && !in_array($cta, self::CTA_TYPES, true)) {
            throw new Exception('نوع الزرار مش مدعوم');
        }
        if ($ctaUrl !== '' && (!filter_var($ctaUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $ctaUrl))) {
            throw new Exception('رابط الزرار مش صحيح');
        }
        $content->setAttribute('post_title', $title !== '' ? $title : null);
        $content->setAttribute('event_start', $start ?: null);
        $content->setAttribute('event_end', $end ?: null);
        $content->setAttribute('coupon_code', $type === 'offer' ? (mb_substr(trim((string) ($in['coupon_code'] ?? $content->getAttribute('coupon_code') ?? '')), 0, 58) ?: null) : null);
        $content->setAttribute('offer_terms', $type === 'offer' ? (mb_substr(trim((string) ($in['offer_terms'] ?? $content->getAttribute('offer_terms') ?? '')), 0, 500) ?: null) : null);
        $content->setAttribute('cta_type', $cta ?: null);
        $content->setAttribute('cta_url', $ctaUrl ?: null);
    }

    /**
     * صيانة منشورات Google المجدولة (من كرون الأتمتة كل 10 دقايق):
     *  - "جاري النشر" أكتر من 20 دقيقة = العامل وقع في النص → فشل برسالة واضحة
     *    (مش بنعيد النشر لوحدنا عشان مايتنشرش مرتين لو Google استلمه فعلاً)
     *  - "مستني" وميعاده عدّى بنص ساعة ومفيش مهمة شغّالة في الطابور → بنرجّعه للطابور
     * @return array{stuck:int, requeued:int}
     */
    public function recoverStuck(): array
    {
        $db = Database::getInstance();
        $out = ['stuck' => 0, 'requeued' => 0];
        try {
            $stuck = $db->query(
                "SELECT sp.id, c.user_id FROM gbp_scheduled_posts sp JOIN gbp_content c ON c.id = sp.gbp_content_id
                  WHERE sp.status = 'processing' AND sp.updated_at < (NOW() - INTERVAL 20 MINUTE) LIMIT 100"
            );
            foreach ($stuck as $r) {
                $db->exec(
                    "UPDATE gbp_scheduled_posts SET status = 'failed', error_message = ? WHERE id = ? AND status = 'processing'",
                    ['النشر اتعلق في النص - افتح صفحتك على Google: لو المنشور مش ظاهر اعمل جدولة تاني', (int) $r['id']]
                );
                $out['stuck']++;
                Notification::notify((int) $r['user_id'], 'gbp_post_failed', 'منشور Google Business ماكملش',
                    'النشر اتعلق - اتأكد من صفحتك على Google ولو مش ظاهر جدوله تاني.', '/gbp-content');
            }
            $orphans = $db->query(
                "SELECT sp.id FROM gbp_scheduled_posts sp
                   LEFT JOIN jobs j ON j.id = sp.queue_job_id
                  WHERE sp.status = 'pending' AND sp.scheduled_at < (NOW() - INTERVAL 30 MINUTE)
                    AND (sp.queue_job_id IS NULL OR j.id IS NULL OR j.status IN ('failed','completed'))
                  LIMIT 100"
            );
            foreach ($orphans as $r) {
                $jobId = Container::getInstance()->make(QueueManager::class)->push(
                    PublishGbpPostJob::class, ['gbp_scheduled_post_id' => (int) $r['id']], 'gbp', 0
                );
                if (is_int($jobId)) {
                    $db->exec('UPDATE gbp_scheduled_posts SET queue_job_id = ? WHERE id = ?', [$jobId, (int) $r['id']]);
                    $out['requeued']++;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('GBP recoverStuck failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    public function editContent(int $contentId, int $userId, string $newText, ?array $details = null): GbpContent
    {
        $content = (new GbpContent())->find($contentId);
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('المنشور غير موجود');
        }

        $activeSchedule = $this->findActiveSchedule($contentId);
        if ($activeSchedule) {
            throw new Exception('المنشور ده مجدول بالفعل - لازم تلغي الجدولة الأول قبل التعديل');
        }

        $trimmed = trim($newText);
        if ($trimmed === '') {
            throw new Exception('نص المنشور لا يمكن أن يكون فارغًا');
        }
        if (mb_strlen($trimmed) > 1500) {
            throw new Exception('نص المنشور يجب ألا يتجاوز 1500 حرف (حد Google الرسمي لمنشورات Local Posts)');
        }

        $content->setAttribute('generated_text', $trimmed);
        if ($details !== null) {
            $this->applyDetails($content, $details);
        }
        $content->save();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_update', (int) $content->getAttribute('website_id'), $userId, 'success', ['action_detail' => 'text_edited', 'content_id' => $contentId]);
        }

        return $content;
    }

    /**
     * إلغاء منشور مجدول قبل ما ينشر فعليًا. بيشيل صف الـ Job من طابور
     * الانتظار (لو لسه "pending" ومجاش دوره) + يحدّث حالة الجدولة لـ
     * 'cancelled'. لو المنشور نشر بالفعل أو قيد التنفيذ دلوقتي، بيرفض
     * الإلغاء بدل ما يدّعي نجاح وهمي.
     */
    public function cancelScheduled(int $scheduledPostId, int $userId): GbpScheduledPost
    {
        $scheduled = (new GbpScheduledPost())->find($scheduledPostId);
        if (!$scheduled) {
            throw new Exception('الجدولة غير موجودة');
        }

        $content = (new GbpContent())->find((int) $scheduled->getAttribute('gbp_content_id'));
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('الجدولة غير موجودة');
        }

        $status = $scheduled->getAttribute('status');
        if ($status === 'published') {
            throw new Exception('المنشور اتنشر بالفعل على Google - مينفعش يتلغى');
        }
        if ($status === 'processing') {
            throw new Exception('المنشور قيد النشر دلوقتي - استنى لحد ما تخلص العملية');
        }
        if ($status === 'cancelled') {
            throw new Exception('الجدولة دي ملغاة بالفعل');
        }

        $jobId = $scheduled->getAttribute('queue_job_id');
        if ($jobId) {
            try {
                // بنمسح صف الـ Job بس لو لسه 'pending' (يعني مجاش دوره في
                // الطابور لسه) - لو اتحرّك لـ processing جوه سباق (race)
                // نادر، منمسحوش عشان منسيبش نشر ناقص، وهيرجع نفسه لـ
                // pending تلقائيًا لو عَلَق (STALE_LOCK_SECONDS في QueueManager).
                Database::getInstance()->query(
                    "DELETE FROM jobs WHERE id = ? AND status = 'pending'",
                    [$jobId]
                );
            } catch (Throwable $e) {
                Logger::error('GBP cancel scheduled: تعذر حذف صف الـ job', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            }
        }

        $scheduled->setAttribute('status', 'cancelled');
        $scheduled->save();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_update', (int) $content->getAttribute('website_id'), $userId, 'success', ['action_detail' => 'schedule_cancelled', 'scheduled_post_id' => $scheduledPostId]);
        }

        return $scheduled;
    }

    /** تحقق: فيه جدولة "pending" فعلية لسه شغالة لنفس المحتوى؟ */
    private function findActiveSchedule(int $contentId): ?GbpScheduledPost
    {
        $rows = (new GbpScheduledPost())->where(['gbp_content_id' => $contentId, 'status' => 'pending'], [], 1);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * حذف مسودة منشور (مش منشور على Google أبدًا ولا مجدول حاليًا).
     * لو اتنشر فعلاً على Google أو مجدول pending، بيرفض الحذف صراحة -
     * الحذف هنا محلي بس (سجل Tourfecto)؛ مفيش endpoint فعلي من Google
     * لحذف Local Post بعد نشره من غير Google Business Profile UI نفسه،
     * فمعملناش استدعاء وهمي هنا.
     */
    public function deleteContent(int $contentId, int $userId): void
    {
        $content = (new GbpContent())->find($contentId);
        if (!$content || (int) $content->getAttribute('user_id') !== $userId) {
            throw new Exception('المنشور غير موجود');
        }

        $schedules = (new GbpScheduledPost())->where(['gbp_content_id' => $contentId]);
        foreach ($schedules as $schedule) {
            $status = $schedule->getAttribute('status');
            if (in_array($status, ['pending', 'processing', 'published'], true)) {
                throw new Exception('المنشور ده مجدول أو منشور بالفعل - لازم تلغي الجدولة الأول قبل الحذف');
            }
        }

        // لازم نلقط website_id قبل delete() - delete() بتفضّي $content->attributes
        // بعد النجاح، فأي getAttribute() بعدها هيرجع null (باگ حقيقي كان
        // هيبوّظ الـ Audit Log لولا الالتقاط ده).
        $websiteId = (int) $content->getAttribute('website_id');

        $content->delete();

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log('post_delete', $websiteId, $userId, 'success', ['content_id' => $contentId]);
        }
    }
}
