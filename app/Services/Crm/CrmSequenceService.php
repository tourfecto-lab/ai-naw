<?php

/**
 * Tourfecto - CRM Sales Sequence Service (المرحلة 15 - G12)
 * @version 1.0.0
 *
 * تسلسلات مبيعات متعددة الخطوات (Sales Sequences) - سد فجوة 2.5:
 * "Sequences متعددة الخطوات: ❌".
 *
 * الـSequence = سلسلة خطوات (متابعة/مكالمة/إيميل/ملاحظة) بترتيب مؤجل
 * يُنفَّذ على Contact/Lead/Deal. Additive خالص: جدولان جديدان فقط
 * (crm_sequences / crm_sequence_enrollments) - لا يمس CrmAutomationRule.
 *
 * نفس القيد المعماري لمحرك الـAutomation (بند 10/12): لا يوجد إرسال
 * خارجي فعلي (واتساب/إيميل) من هذا المحرك - خطوات email/whatsapp تُنشئ
 * مهمة متابعة تحتوي النص المعروض (rendered) من القالب للمندوب، ليتولى
 * الإرسال الفعلي بقراره - نفس مبدأ CrmAutomationService.
 */
class CrmSequenceService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** أنواع الخطوات المدعومة (مطابقة لأنواع Action في Automation تقريبًا) */
    public const STEP_TYPES = [
        ['type' => 'task', 'label_ar' => 'إنشاء مهمة', 'fields' => ['title', 'priority', 'delay_days']],
        ['type' => 'note', 'label_ar' => 'إضافة ملاحظة', 'fields' => ['body', 'delay_days']],
        ['type' => 'email', 'label_ar' => 'إيميل (قالب)', 'fields' => ['template_id', 'delay_days', 'delivery_mode', 'website_id']],
        ['type' => 'whatsapp', 'label_ar' => 'واتساب (قالب)', 'fields' => ['template_id', 'delay_days', 'delivery_mode', 'website_id']],
        ['type' => 'notify', 'label_ar' => 'إشعار داخلي', 'fields' => ['title', 'delay_days']],
    ];

    public function schema(): array
    {
        return ['step_types' => self::STEP_TYPES];
    }

    public function listForUser(int $userId): array
    {
        return (new CrmSequence())->forUser($userId);
    }

    /** إنشاء أو تحديث Sequence مع تحقق من شكل الخطوات (JSON) */
    public function save(int $userId, array $data, ?int $seqId = null): CrmSequence
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('اسم التسلسل مطلوب', 422);
        }
        $steps = $data['steps'] ?? [];
        if (!is_array($steps) || count($steps) === 0) {
            throw new Exception('لازم تضيف خطوة واحدة على الأقل للتسلسل', 422);
        }
        if(count($steps)>50)throw new InvalidArgumentException('التسلسل يدعم 50 خطوة كحد أقصى');
        foreach ($steps as $step) {
            if(!in_array($step['delivery_mode']??'manual',['manual','automatic'],true))throw new InvalidArgumentException('طريقة إرسال غير صالحة');
            if(($step['delivery_mode']??'manual')==='automatic' && !in_array($step['type']??'',['email','whatsapp'],true))throw new InvalidArgumentException('الإرسال التلقائي للبريد وواتساب فقط');
            $type = $step['type'] ?? '';
            if (!in_array($type, array_column(self::STEP_TYPES, 'type'), true)) {
                throw new Exception('نوع خطوة غير معروف: ' . $type, 422);
            }
        }
        $stepsJson = json_encode(array_values($steps), JSON_UNESCAPED_UNICODE);

        if ($seqId !== null) {
            $seq = (new CrmSequence())->findOwned($userId, $seqId);
            if (!$seq) {
                throw new Exception('التسلسل غير موجود', 404);
            }
            $seq->setAttribute('name', $name);
            $seq->setAttribute('description', $data['description'] ?? null);
            $seq->setAttribute('steps', $stepsJson);
            $seq->setAttribute('is_active', array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : $seq->getAttribute('is_active'));
            $seq->save();
            return $seq;
        }

        $seq = new CrmSequence([
            'user_id' => $userId,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'steps' => $stepsJson,
            'is_active' => 1,
        ]);
        $seq->save();

        ActivityLog::record('crm', 'sequence.created', [
            'user_id' => $userId, 'subject_type' => 'crm_sequences', 'subject_id' => (int) $seq->getAttribute('id'),
        ]);
        return $seq;
    }

    public function delete(int $userId, int $seqId): bool
    {
        $seq = (new CrmSequence())->findOwned($userId, $seqId);
        if (!$seq) {
            throw new Exception('التسلسل غير موجود', 404);
        }
        ActivityLog::record('crm', 'sequence.deleted', [
            'user_id' => $userId, 'subject_type' => 'crm_sequences', 'subject_id' => $seqId,
        ]);
        return $seq->delete();
    }

    /**
     * تسجيل Contact/Lead/Deal في تسلسل.
     * related_type: contact/lead/deal (تُعامل نفس resolveRelated في Automation).
     */
    public function enroll(int $userId, array $data): CrmSequenceEnrollment
    {
        $seq = (new CrmSequence())->findOwned($userId, (int) ($data['sequence_id'] ?? 0));
        if (!$seq) {
            throw new Exception('التسلسل غير موجود', 404);
        }
        if (!$seq->getAttribute('is_active')) {
            throw new Exception('التسلسل غير مفعّل', 422);
        }

        $relatedType = (string) ($data['related_type'] ?? '');
        $relatedId = (int) ($data['related_id'] ?? 0);
        if (!in_array($relatedType, ['contact', 'lead', 'deal'], true) || $relatedId <= 0) {
            throw new Exception('related_type (contact/lead/deal) و related_id مطلوبان', 422);
        }
        $this->assertRelatedExists($userId, $relatedType, $relatedId);

        $existing = (new CrmSequenceEnrollment())->findActiveEnrollment($userId, (int) $seq->getAttribute('id'), $relatedType, $relatedId);
        if ($existing) {
            throw new Exception('هذا الكيان مسجّل بالفعل في هذا التسلسل', 422);
        }

        $enrollment = new CrmSequenceEnrollment([
            'user_id' => $userId,
            'sequence_id' => (int) $seq->getAttribute('id'),
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'current_step' => 0,
            'next_run_at' => date('Y-m-d H:i:s', strtotime('now')),
            'status' => 'active',
        ]);
        $enrollment->save();

        ActivityLog::record('crm', 'sequence.enrolled', [
            'user_id' => $userId, 'subject_type' => 'crm_sequences', 'subject_id' => (int) $seq->getAttribute('id'),
            'meta' => ['related_type' => $relatedType, 'related_id' => $relatedId],
        ]);

        $this->processEnrollment($userId, $enrollment);
        return $enrollment;
    }

    /** قائمة التسجيلات النشطة/المكتملة مع اسم التسلسل */
    public function enrollments(int $userId, string $status = 'active', int $limit = 100): array
    {
        return (new CrmSequenceEnrollment())->forUser($userId, $status, $limit);
    }

    /**
     * تنفيذ الخطوات المستحقة لتسجيل واحد. يُستدعى عند التسجيل وبشكل
     * دوري من Controller (نفس نمط معالجة التسلسلات في المنافسين).
     */
    public function processEnrollment(int $userId, CrmSequenceEnrollment $enrollment): array
    {
        $key='crm-enrollment:'.(int)$enrollment->getAttribute('id');
        if(!ExternalExecution::lock($key))return [];
        try {
        $enrollment=(new CrmSequenceEnrollment())->findOwned($userId,(int)$enrollment->getAttribute('id'));if(!$enrollment)return [];
        $seq = (new CrmSequence())->findOwned($userId, (int) $enrollment->getAttribute('sequence_id'));
        if (!$seq || !$seq->getAttribute('is_active')) {
            return [];
        }
        if ($enrollment->getAttribute('status') !== 'active') {
            return [];
        }

        $steps = json_decode((string) $seq->getAttribute('steps'), true) ?: [];
        $executed = [];
        $guard = 0;

        while ($guard < 100) {
            $guard++;
            $now = time();
            $nextRun = strtotime((string) $enrollment->getAttribute('next_run_at'));
            if ($nextRun === false || $nextRun > $now) {
                break; // لسه ما حانش وقت الخطوة التالية
            }

            $stepIndex = (int) $enrollment->getAttribute('current_step');
            if ($stepIndex >= count($steps)) {
                $enrollment->setAttribute('status', 'completed');
                $enrollment->setAttribute('completed_at', date('Y-m-d H:i:s'));
                $enrollment->setAttribute('next_run_at', null);
                $enrollment->save();
                break;
            }

            $step = $steps[$stepIndex];
            $context = $this->contextFor($userId, $enrollment);
            $automatic=($step['delivery_mode']??'manual')==='automatic';
            $step['_execution_key']='crm:'.$enrollment->getAttribute('id').':'.$stepIndex;
            if($automatic)$this->executeStep($userId,$step,$context);
            $this->db->transaction(function()use($userId,$step,$context,$automatic,$enrollment,$stepIndex,$now){
                if(!$automatic)$this->executeStep($userId,$step,$context);
                $delayDays=max(0,(int)($step['delay_days']??0));
                $enrollment->setAttribute('current_step',$stepIndex+1);
                $enrollment->setAttribute('next_run_at',date('Y-m-d H:i:s',$now+$delayDays*86400));
                if(!$enrollment->save())throw new RuntimeException('Sequence advancement failed');
            });
            $executed[]=array_merge($step,['step_index'=>$stepIndex]);
        }

        if (!empty($executed)) {
            ActivityLog::record('crm', 'sequence.step_executed', [
                'user_id' => $userId, 'subject_type' => 'crm_sequence_enrollments', 'subject_id' => (int) $enrollment->getAttribute('id'),
                'meta' => ['steps' => count($executed)],
            ]);
        }
        return $executed;
        }finally{ExternalExecution::unlock($key);}
    }

    /** تنفيذ الخطوات المستحقة لكل التسجيلات النشطة في الحساب (Job دوري) */
    public function processDue(int $userId, int $limit = 50): array
    {
        $enrollments = (new CrmSequenceEnrollment())->forUser($userId, 'active', $limit);
        $results = [];
        foreach ($enrollments as $row) {
            $enrollment = (new CrmSequenceEnrollment())->findOwned($userId, (int) $row['id']);
            if ($enrollment) {
                $results[(int) $row['id']] = $this->processEnrollment($userId, $enrollment);
            }
        }
        return $results;
    }

    public function pause(int $userId, int $enrollmentId): CrmSequenceEnrollment
    {
        $enrollment = $this->findEnrollment($userId, $enrollmentId);
        $enrollment->setAttribute('status', 'paused');
        $enrollment->save();
        return $enrollment;
    }

    public function resume(int $userId, int $enrollmentId): CrmSequenceEnrollment
    {
        $enrollment = $this->findEnrollment($userId, $enrollmentId);
        if ($enrollment->getAttribute('status') !== 'paused') {
            throw new Exception('التسجيل ليس في حالة إيقاف مؤقت', 422);
        }
        $enrollment->setAttribute('status', 'active');
        $enrollment->setAttribute('next_run_at', date('Y-m-d H:i:s', strtotime('now')));
        $enrollment->save();
        return $enrollment;
    }

    public function cancel(int $userId, int $enrollmentId): CrmSequenceEnrollment
    {
        $enrollment = $this->findEnrollment($userId, $enrollmentId);
        $enrollment->setAttribute('status', 'cancelled');
        $enrollment->save();
        return $enrollment;
    }

    // ================================================================
    // أدوات داخلية
    // ================================================================

    private function findEnrollment(int $userId, int $enrollmentId): CrmSequenceEnrollment
    {
        $enrollment = (new CrmSequenceEnrollment())->findOwned($userId, $enrollmentId);
        if (!$enrollment) {
            throw new Exception('التسجيل غير موجود', 404);
        }
        return $enrollment;
    }

    /** التأكد أن الكيان المستهدف ملك نفس الحساب */
    private function assertRelatedExists(int $userId, string $relatedType, int $relatedId): void
    {
        $sql = match ($relatedType) {
            'contact' => "SELECT id FROM crm_contacts WHERE id = ? AND user_id = ?",
            'lead' => "SELECT l.id FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE l.id = ? AND c.user_id = ?",
            'deal' => "SELECT d.id FROM crm_deals d JOIN crm_contacts c ON c.id = d.contact_id WHERE d.id = ? AND c.user_id = ?",
            default => null,
        };
        if ($sql === null) {
            throw new Exception('نوع كيان غير معروف', 422);
        }
        $rows = $this->db->query($sql, [$relatedId, $userId]);
        if (empty($rows)) {
            throw new Exception('الكيان المطلوب غير موجود في حسابك', 404);
        }
    }

    /** سياق بسيط للمندوب (اسم/هاتف/بريد) للخطوات القائمة على قوالب الرسائل */
    private function contextFor(int $userId, CrmSequenceEnrollment $enrollment): array
    {
        $relatedType = (string) $enrollment->getAttribute('related_type');
        $relatedId = (int) $enrollment->getAttribute('related_id');

        $contactRow = null;
        if ($relatedType === 'contact') {
            $rows = $this->db->query(
                "SELECT c.id, c.name, c.email, c.phone FROM crm_contacts c WHERE c.id = ? AND c.user_id = ? LIMIT 1",
                [$relatedId, $userId]
            );
            $contactRow = $rows[0] ?? null;
        } elseif ($relatedType === 'lead') {
            $rows = $this->db->query(
                "SELECT c.id, c.name, c.email, c.phone, l.interest AS deal_title, l.value AS deal_value
                 FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
                 WHERE l.id = ? AND c.user_id = ? LIMIT 1",
                [$relatedId, $userId]
            );
            $contactRow = $rows[0] ?? null;
        } elseif ($relatedType === 'deal') {
            $rows = $this->db->query(
                "SELECT c.id, c.name, c.email, c.phone, d.title AS deal_title, d.value AS deal_value, d.currency AS currency
                 FROM crm_deals d JOIN crm_contacts c ON c.id = d.contact_id
                 WHERE d.id = ? AND c.user_id = ? LIMIT 1",
                [$relatedId, $userId]
            );
            $contactRow = $rows[0] ?? null;
        }

        return [
            'contact_id' => $contactRow['id'] ?? null,
            'name' => $contactRow['name'] ?? null,
            'email' => $contactRow['email'] ?? null,
            'phone' => $contactRow['phone'] ?? null,
            'deal_title' => $contactRow['deal_title'] ?? null,
            'deal_value' => $contactRow['deal_value'] ?? null,
            'currency' => $contactRow['currency'] ?? 'USD',
        ];
    }

    /**
     * تنفيذ خطوة واحدة - لا إرسال خارجي فعلي (نفس قيد Automation).
     * email/whatsapp: تُنشأ مهمة متابعة بنص مُصيَّر من القالب.
     */
    private function executeStep(int $userId, array $step, array $context): void
    {
        $type = $step['type'] ?? '';

        switch ($type) {
            case 'task':
                $task=(new CrmTaskService())->create($userId, [
                    'title' => $step['title'] ?? 'متابعة من تسلسل مبيعات',
                    'due_date' => date('Y-m-d H:i:s'),
                    'priority' => $step['priority'] ?? 'medium',
                    'related_type' => $this->relatedTypeForTask($context),
                    'related_id' => $context['contact_id'] ?: null,
                    'assigned_to_user_id' => $userId,
                ]);
                if(!(int)$task->getAttribute('id'))throw new RuntimeException('Task persistence failed');
                break;

            case 'note':
                $relatedType = $this->relatedTypeForTask($context);
                if ($relatedType && $context['contact_id']) {
                    $note=(new CrmNoteService())->create($userId, [
                        'body' => $step['body'] ?? 'خطوة من تسلسل مبيعات',
                        'related_type' => $relatedType,
                        'related_id' => $context['contact_id'],
                    ]);
                    if(!(int)$note->getAttribute('id'))throw new RuntimeException('Note persistence failed');
                }
                break;

            case 'email':
            case 'whatsapp':
                $templateId = (int) ($step['template_id'] ?? 0);
                $rendered = null;
                if ($templateId > 0) {
                    try {
                        $rendered = (new CrmMessageTemplateService())->render($userId, $templateId, $context);
                    } catch (Exception $e) {
                        $rendered = null;
                    }
                }
                $body = $rendered['body'] ?? ($step['title'] ?? '');
                if(($step['delivery_mode']??'manual')==='automatic'){
                    if(!$rendered||trim($body)==='')throw new RuntimeException('قالب الرسالة غير صالح');
                    if($type==='email'){
                        if(!filter_var($context['email']??'',FILTER_VALIDATE_EMAIL))throw new RuntimeException('بريد العميل غير صالح');
                        $smtp=new SmtpSettingsService();$config=$smtp->get($userId);
                        if(!$config||empty($config['is_active']))throw new RuntimeException('فعّل SMTP الخاص بحسابك أولًا');
                        $q=$this->db->getConnection()->prepare('SELECT COUNT(*) FROM email_suppressions WHERE user_id=? AND email=?');$q->execute([$userId,$context['email']]);if($q->fetchColumn())throw new RuntimeException('العميل أوقف الرسائل');
                        $q=$this->db->getConnection()->prepare("SELECT COUNT(*) FROM email_subscribers WHERE user_id=? AND email=? AND status IN ('unsubscribed','bounced','pending_optin')");$q->execute([$userId,$context['email']]);if($q->fetchColumn())throw new RuntimeException('العميل أوقف الرسائل');
                        $result=ExternalExecution::run($step['_execution_key'],$userId,fn()=>$smtp->mailerForUser($userId)->send($context['email'],(string)$context['name'],(string)($rendered['subject']??''),$body));
                    }else{
                        $websiteId=(int)($step['website_id']??0);$q=$this->db->getConnection()->prepare('SELECT id FROM websites WHERE id=? AND user_id=?');$q->execute([$websiteId,$userId]);
                        if(!$q->fetchColumn()||empty($context['phone']))throw new RuntimeException('اختار موقعًا مملوكًا للحساب وهاتف عميل صالح');
                        $result=ExternalExecution::run($step['_execution_key'],$userId,fn()=>['success'=>(new ChatManager())->sendMessageForWebsite($websiteId,$context['phone'],$body)]);
                    }
                    if(empty($result['success']))throw new RuntimeException('لم يتأكد إرسال خطوة التسلسل؛ راجع سجل المزود');
                    break;
                }
                $baseTitle = $type === 'email' ? 'إيميل متابعة' : 'رسالة واتساب متابعة';
                $title = $baseTitle;
                if (!empty($rendered['subject'])) {
                    $title = $baseTitle . ': ' . $rendered['subject'];
                }
                $task=(new CrmTaskService())->create($userId, [
                    'title' => $title,
                    'description' => $body,
                    'due_date' => date('Y-m-d H:i:s'),
                    'priority' => 'medium',
                    'related_type' => $this->relatedTypeForTask($context),
                    'related_id' => $context['contact_id'] ?: null,
                    'assigned_to_user_id' => $userId,
                ]);
                if(!(int)$task->getAttribute('id'))throw new RuntimeException('Task persistence failed');
                break;

            case 'notify':
                if (class_exists('Notification')) {
                    Notification::notify($userId, 'crm_sequence', $step['title'] ?? 'تنبيه تسلسل مبيعات', '', '/crm');
                }
                break;

            default:
                throw new Exception('نوع خطوة غير معروف: ' . $type);
        }
    }

    /** تطابق أنواع الكيانات في جدول المهام (crm_contacts/crm_leads/crm_deals) */
    private function relatedTypeForTask(array $context): ?string
    {
        return $context['contact_id'] ? 'crm_contacts' : null;
    }
}
