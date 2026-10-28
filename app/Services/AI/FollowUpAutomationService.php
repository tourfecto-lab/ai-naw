<?php

/**
 * Tourfecto - AI Chat Platform
 * Follow-up Automation Service (بند 7): لو العميل سأل ثم اختفى، ينشئ
 * ويرسل رسائل متابعة تلقائية حسب قواعد كل شركة (ai_followup_rules)،
 * بحد أقصى Maximum Follow-ups، ويتوقف فورًا عند أي Stop Condition
 * (طلب عدم التواصل، تحويل لموظف، إغلاق Lead، حجز مكتمل...).
 *
 * معطّل افتراضيًا لكل شركة (Opt-in): لو لا يوجد صف مفعّل في
 * ai_followup_rules لموقع معيّن، لا تُنشأ أي متابعات له إطلاقًا.
 *
 * يُستدعى من Cron Job دوري (انظر cron/process_ai_followups.php).
 *
 * @version 1.0.0
 */

class FollowUpAutomationService
{
    /** @var Database */
    private $db;

    /** @var UnifiedInboxService */
    private $inbox;

    /** @var ChatManager */
    private $chatManager;

    /** @var AiFollowupRule */
    private $ruleModel;

    /** @var AiLead */
    private $leadModel;

    /** قنوات Meta اللي ليها شباك 24 ساعة للرسايل التلقائية */
    private const META_CHANNELS = ['messenger', 'instagram','whatsapp'];
    private const META_WINDOW_SECONDS = 23 * 3600;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->inbox = new UnifiedInboxService();
        $this->chatManager = new ChatManager();
        $this->ruleModel = new AiFollowupRule();
        $this->leadModel = new AiLead();
    }

    /**
     * دورة كاملة: اكتشاف محادثات مستحقة لمتابعة جديدة + إرسال كل المتابعات
     * المستحقة الآن. يُستدعى مرة كل تشغيلة Cron.
     * @return array ['scheduled' => int, 'sent' => int, 'cancelled' => int, 'failed' => int]
     */
    public function processDueFollowUps(): array
    {
        $stats = ['scheduled' => 0, 'sent' => 0, 'cancelled' => 0, 'failed' => 0];

        $this->discoverAndScheduleNewFollowUps($stats);
        $this->sendDueFollowUps($stats);

        return $stats;
    }

    /**
     * الخطوة 1: إيجاد محادثات "سكتت" بعد سؤال العميل، وجدولة الخطوة
     * التالية من المتابعة لها حسب قواعد الشركة، لو حان وقتها.
     *
     * إدراك ساعات العمل (تحسين تنافسي): لو الشركة مهيأة قسم business_hours
     * في Knowledge Base، أي لحظة استحقاق بتقع خارج ساعات العمل بتتأجل تلقائيًا
     * لأقرب لحظة فتح - مش هنبعت متابعة للعميل الساعة 3 الفجر (Intercom/
     * respond.io/ManyChat كلهم بيعملوا كده، وبيقلل معدل الانسحاب).
     *
     * @param array $stats مرجع - يُحدَّث مباشرة
     */
    private function discoverAndScheduleNewFollowUps(array &$stats): void
    {
        $candidates = $this->db->query(
            "SELECT c.id AS conversation_id, c.website_id, c.last_customer_message_at, c.channel,
                    r.steps, r.max_followups
             FROM ai_conversations c
             INNER JOIN ai_followup_rules r ON r.website_id = c.website_id AND r.is_enabled = 1
             WHERE c.ai_status = 'ai'
               AND c.do_not_contact = 0
               AND c.status != 'closed'
               AND c.lead_status NOT IN ('converted', 'lost')
               AND c.last_customer_message_at IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM ai_followups f
                   WHERE f.conversation_id = c.id AND f.status = 'pending'
               )
             LIMIT 300"
        );

        if (empty($candidates)) {
            return;
        }

        // جلب ساعات عمل كل المواقع المعنية دفعة واحدة (بدل استعلام لكل صف)
        $websiteIds = array_values(array_unique(array_map(function ($r) {
            return (int) $r['website_id'];
        }, $candidates)));
        $schedules = $this->businessSchedulesFor($websiteIds);

        foreach ($candidates as $row) {
            $steps = json_decode((string) $row['steps'], true);
            if (!is_array($steps) || empty($steps)) {
                continue;
            }

            $websiteId = (int) $row['website_id'];
            $conversationId = (int) $row['conversation_id'];
            $maxFollowups = (int) $row['max_followups'];

            $alreadySent = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM ai_followups WHERE conversation_id = ? AND status = 'sent'",
                [$conversationId]
            )[0]['c'] ?? 0);

            if ($alreadySent >= $maxFollowups || !isset($steps[$alreadySent])) {
                continue; // وصل للحد الأقصى، أو لا توجد خطوة تالية معرَّفة
            }

            $step = $steps[$alreadySent];
            // الساعات ممكن تكون كسور (0.5 = نص ساعة) - كانت بتتقرّب لصفر
            $afterHours = max(0.25, (float) ($step['after_hours'] ?? 24));
            $lastCustomerAt = strtotime((string) $row['last_customer_message_at']);
            $dueAt = $lastCustomerAt + (int) round($afterHours * 3600);

            // 🔧 ماسنجر وإنستجرام: Meta بترفض أي رسالة تلقائية بعد 24 ساعة من آخر رسالة للعميل.
            //    أول متابعة بنقدّمها لـ 23 ساعة لو كانت بعد كده، وأي متابعة بعد الشباك ده مش بنجدولها أصلًا.
            if (in_array((string) ($row['channel'] ?? ''), self::META_CHANNELS, true)) {
                $windowEnd = $lastCustomerAt + self::META_WINDOW_SECONDS;
                if ($alreadySent > 0 && $dueAt > $windowEnd) {
                    continue;
                }
                $dueAt = min($dueAt, $windowEnd);
                if (time() > $windowEnd) {
                    continue; // الشباك قفل خلاص
                }
            }

            if (time() < $dueAt) {
                continue; // لسه معاش وقتها
            }

            // إدراك ساعات العمل: أرجِع لحظة الاستحقاق لأقرب لحظة عمل فعلية
            $schedule = $schedules[$websiteId] ?? null;
            if ($schedule !== null) {
                $dueAt = BusinessHoursService::nextOpenTime($dueAt, $schedule);
            }

            $lead = $this->leadModel->where(['conversation_id' => $conversationId], [], 1);

            $followup = new AiFollowup();
            $followup->fill([
                'website_id' => $websiteId,
                'conversation_id' => $conversationId,
                'lead_id' => !empty($lead) ? $lead[0]->getAttribute('id') : null,
                'followup_number' => $alreadySent + 1,
                'scheduled_at' => date('Y-m-d H:i:s', $dueAt),
                'status' => 'pending',
                'template_used' => $step['template'] ?? null,
            ]);

            if ($followup->save() !== false) {
                $stats['scheduled']++;
            }
        }
    }

    /**
     * الخطوة 2: إرسال كل المتابعات المستحقة الآن، بعد إعادة فحص Stop
     * Conditions لحظيًا (العميل ممكن يكون اتحوّل لموظف أو رد بنفسه من
     * وقت الجدولة لحد الآن).
     * @param array $stats مرجع - يُحدَّث مباشرة
     */
    private function sendDueFollowUps(array &$stats): void
    {
        $due = $this->db->query(
            "SELECT f.*, c.customer_phone, c.customer_email, c.customer_name, c.do_not_contact, c.ai_status,
                    c.status AS conversation_status, c.user_id, c.channel
             FROM ai_followups f
             INNER JOIN ai_conversations c ON c.id = f.conversation_id
             WHERE f.status = 'pending' AND f.scheduled_at <= NOW()
             LIMIT 200"
        );

        if (empty($due)) {
            return;
        }

        // ساعات عمل المواقع المعنية دفعة واحدة
        $websiteIds = array_values(array_unique(array_map(function ($r) {
            return (int) $r['website_id'];
        }, $due)));
        $schedules = $this->businessSchedulesFor($websiteIds);

        foreach ($due as $row) {
            $followupId = (int) $row['id'];
            $conversationId = (int) $row['conversation_id'];

            if ($this->inbox->shouldStopAutomation($conversationId)) {
                $reason = $row['do_not_contact'] ? 'customer_opted_out'
                    : ($row['ai_status'] !== 'ai' ? 'human_handoff' : 'lead_closed');
                $this->cancelFollowUp($followupId, $reason);
                $stats['cancelled']++;
                continue;
            }

            // إدراك ساعات العمل: لو الوقت الحالي خارج ساعات عمل الشركة، نأجل
            // الإرسال لأقرب لحظة فتح بدل إرسال متابعة في ساعة متأخرة
            $schedule = $schedules[(int) $row['website_id']] ?? null;
            if ($schedule !== null && !BusinessHoursService::isOpenAt(time(), $schedule)) {
                $nextOpen = BusinessHoursService::nextOpenTime(time(), $schedule);
                $this->db->query(
                    "UPDATE ai_followups SET scheduled_at = ? WHERE id = ?",
                    [date('Y-m-d H:i:s', $nextOpen), $followupId]
                );
                continue;
            }

            $message = $this->renderTemplate((string) $row['template_used'], (string) $row['customer_name']);
            if ($message === '') {
                $this->cancelFollowUp($followupId, 'empty_template');
                $stats['cancelled']++;
                continue;
            }

            // ماسنجر/إنستجرام: لو الشباك (24 ساعة من آخر رسالة للعميل) قفل قبل الإرسال، Meta هترفض - نلغي بدل ما تفشل
            if (in_array((string) $row['channel'], self::META_CHANNELS, true)) {
                $lastAt = $this->db->query('SELECT last_customer_message_at FROM ai_conversations WHERE id = ?', [$conversationId]);
                $last = strtotime((string) ($lastAt[0]['last_customer_message_at'] ?? ''));
                if (!$last || time() > $last + 24 * 3600 - 60) {
                    $this->cancelFollowUp($followupId, 'meta_24h_window');
                    $stats['cancelled']++;
                    continue;
                }
            }

            if($row['channel']==='whatsapp'&&WhatsAppMarketingService::installed()){
                try{$p=WhatsAppCloudAPI::normalizePhone((string)$row['customer_phone']);}catch(InvalidArgumentException $e){$this->cancelFollowUp($followupId,'invalid_international_phone');$stats['cancelled']++;continue;}
                if($this->db->query("SELECT id FROM wa_marketing_contacts WHERE user_id=? AND website_id=? AND phone=? AND consent_state='opted_out' LIMIT 1",[(int)$row['user_id'],(int)$row['website_id'],$p])){$this->cancelFollowUp($followupId,'customer_opt_out');$stats['cancelled']++;continue;}
            }
            $recipient = $row['channel'] === 'email' ? $row['customer_email'] : $row['customer_phone'];
            $sent = false;
            if (!empty($recipient)) {
                $sent = $this->chatManager->sendMessageForWebsite((int) $row['website_id'], $recipient, $message, (string) $row['channel']);
            }

            if ($sent) {
                $this->db->query(
                    "UPDATE ai_followups SET status = 'sent', sent_at = NOW() WHERE id = ?",
                    [$followupId]
                );

                $this->db->query(
                    "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform,
                        customer_name, customer_phone, message_direction, message_text, bot_status,
                        is_auto_pilot, sent_at, created_at)
                     SELECT website_id, id, user_id, CONCAT('followup_', id), channel,
                        customer_name, customer_phone, 'outgoing', ?, 'sent', 1, NOW(), NOW()
                     FROM ai_conversations WHERE id = ?",
                    [$message, $conversationId]
                );

                $this->inbox->addTags($conversationId, ['FOLLOW_UP']);
                $this->inbox->updateConversation($conversationId, ['last_message_at' => date('Y-m-d H:i:s')]);
                $stats['sent']++;

                try {
                    Notification::notify(
                        (int) $row['user_id'],
                        'ai_chat_followup_sent',
                        'Follow-up sent',
                        'Follow-up #' . $row['followup_number'] . ' sent to ' . ($row['customer_name'] ?: 'a customer'),
                        '/ai-chat/conversations/' . $conversationId
                    );
                } catch (Exception $e) {
                    Logger::warning('FollowUpAutomationService: notification failed', ['error' => $e->getMessage()]);
                }
            } else {
                $this->db->query(
                    "UPDATE ai_followups SET status = 'failed' WHERE id = ?",
                    [$followupId]
                );
                $stats['failed']++;
            }
        }
    }

    /**
     * @param int $followupId
     * @param string $reason
     */
    private function cancelFollowUp(int $followupId, string $reason): void
    {
        $this->db->query(
            "UPDATE ai_followups SET status = 'cancelled', stop_reason = ? WHERE id = ?",
            [$reason, $followupId]
        );
    }

    /**
     * جداول ساعات العمل لعدد من المواقع دفعة واحدة (استعلام واحد بدل
     * استعلام لكل موقع). الجدول = null لو الموقع مش مهيأ ساعات عمل
     * (يعني 24/7 ولا يوجد أي قيد - سلوك قديم محفوظ).
     * @param int[] $websiteIds
     * @return array [websiteId => array|null]
     */
    private function businessSchedulesFor(array $websiteIds): array
    {
        $schedules = [];
        foreach ($websiteIds as $websiteId) {
            $schedules[(int) $websiteId] = null;
        }
        if (empty($websiteIds)) {
            return $schedules;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($websiteIds), '?'));
            $rows = $this->db->query(
                "SELECT website_id, content, structured_data FROM ai_knowledge_base
                 WHERE website_id IN ({$placeholders})
                   AND section = 'business_hours' AND is_active = 1 AND deleted_at IS NULL
                 LIMIT 500",
                $websiteIds
            );

            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(int) $row['website_id']][] = $row;
            }

            foreach ($grouped as $websiteId => $entries) {
                $schedules[$websiteId] = BusinessHoursService::fromEntries($entries);
            }
        } catch (Exception $e) {
            Logger::warning('FollowUpAutomationService: failed to load business hours', [
                'error' => $e->getMessage(),
            ]);
        }

        return $schedules;
    }

    /**
     * @param string $template
     * @param string $customerName
     * @return string
     */
    private function renderTemplate(string $template, string $customerName): string
    {
        $template = trim($template);
        if ($template === '') {
            return '';
        }
        return str_replace('{name}', $customerName ?: '', $template);
    }

    /**
     * إعدادات المتابعة الحالية لموقع معيّن، أو قيم افتراضية معطّلة لو لم
     * تُضبط بعد (بند 7: Enable/Disable, Schedule, Templates, Max, Stop Conditions).
     * @param int $websiteId
     * @return array
     */
    public function getRules(int $websiteId): array
    {
        $rule = $this->ruleModel->forWebsite($websiteId);
        if (!$rule) {
            return [
                'is_enabled' => false,
                'steps' => [
                    ['after_hours' => 20, 'template' => 'أهلًا {name} 👋 حبينا نطمن - لسه مهتم؟ لو عندك أي سؤال عن الرحلة أو الأسعار إحنا موجودين.'],
                    ['after_hours' => 72, 'template' => 'أهلًا {name}، بنتابع معاك تاني لو رسالتنا اللي فاتت ماوصلتكش. تحب نبعتلك التفاصيل؟'],
                    ['after_hours' => 168, 'template' => 'أهلًا {name}، دي آخر متابعة مننا. لو غيّرت رأيك في أي وقت ابعتلنا وهنكون مبسوطين نساعدك!', 'is_final' => true],
                ],
                'max_followups' => 3,
                'stop_conditions' => [],
            ];
        }

        return [
            'is_enabled' => (bool) $rule->getAttribute('is_enabled'),
            'steps' => json_decode((string) $rule->getAttribute('steps'), true) ?: [],
            'max_followups' => (int) $rule->getAttribute('max_followups'),
            'stop_conditions' => json_decode((string) $rule->getAttribute('stop_conditions'), true) ?: [],
        ];
    }

    /**
     * تحديث/إنشاء إعدادات المتابعة لموقع معيّن.
     * @param int $websiteId
     * @param array $data ['is_enabled','steps','max_followups','stop_conditions']
     * @return bool
     */
    public function updateRules(int $websiteId, array $data): bool
    {
        $rule = $this->ruleModel->forWebsite($websiteId) ?: new AiFollowupRule();

        $rule->fill([
            'website_id' => $websiteId,
            'is_enabled' => !empty($data['is_enabled']) ? 1 : 0,
            'steps' => json_encode($data['steps'] ?? [], JSON_UNESCAPED_UNICODE),
            'max_followups' => (int) ($data['max_followups'] ?? 3),
            'stop_conditions' => json_encode($data['stop_conditions'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        return $rule->save() !== false;
    }
}
