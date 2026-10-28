<?php

/**
 * Tourfecto - Email Marketing Automation Service (المرحلة 3)
 * @version 1.0.0
 *
 * سير عمل تلقائي مثل Brevo:
 *   - مشغلات: اشتراك في قائمة / إضافة وسم / فتح حملة / نقر في حملة / بعد مدة
 *   - خطوات: انتظار / إرسال بريد / إضافة-إزالة وسم / إضافة-إزالة من قائمة / نهاية
 *   - دخول وخروج حسب القوائم المؤهلة (entry/exit audience)
 *   - محرك معالجة يستحق بالوقت (next_run_at) + معالجة فورية بعد الحدث
 *
 * Additive خالص - يُستدعى من الخطافات في EmailListService/EmailTrackingService/
 * ContactManagementService ولا يغيّر سلوكها.
 */
class EmailAutomationService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ CRUD ============================

    public function list(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM email_automation_entries e WHERE e.automation_id = a.id AND e.status = 'active') AS active_entries,
                    (SELECT COUNT(*) FROM email_automation_steps s WHERE s.automation_id = a.id) AS steps_count
             FROM email_automations a
             WHERE a.user_id = ?
             ORDER BY a.created_at DESC",
            [$userId]
        );
        foreach ($rows as &$row) {
            $row['trigger_value'] = json_decode((string) ($row['trigger_value'] ?? 'null'), true) ?: [];
            $row['entry_audience_ids'] = json_decode((string) ($row['entry_audience_ids'] ?? 'null'), true) ?: [];
            $row['exit_audience_ids'] = json_decode((string) ($row['exit_audience_ids'] ?? 'null'), true) ?: [];
        }
        return $rows;
    }

    public function get(int $userId, int $automationId): ?array
    {
        $auto = $this->findOwned($userId, $automationId);
        if (!$auto) {
            return null;
        }
        $row = $auto->toArray();
        $row['trigger_value'] = json_decode((string) ($row['trigger_value'] ?? 'null'), true) ?: [];
        $row['entry_audience_ids'] = json_decode((string) ($row['entry_audience_ids'] ?? 'null'), true) ?: [];
        $row['exit_audience_ids'] = json_decode((string) ($row['exit_audience_ids'] ?? 'null'), true) ?: [];
        $row['steps'] = $this->db->query(
            "SELECT * FROM email_automation_steps WHERE automation_id = ? ORDER BY position ASC, id ASC",
            [$automationId]
        );
        foreach ($row['steps'] as &$step) {
            $step['step_value'] = json_decode((string) ($step['step_value'] ?? 'null'), true) ?: [];
        }
        return $row;
    }

    public function create(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم سير العمل مطلوب'];
        }
        $triggerType = (string) ($data['trigger_type'] ?? EmailAutomation::TRIGGER_SUBSCRIBED);
        if (!isset(EmailAutomation::triggers()[$triggerType])) {
            return ['success' => false, 'error' => 'نوع المشغل غير صالح'];
        }
        $auto = new EmailAutomation([
            'user_id' => $userId,
            'name' => $name,
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'trigger_type' => $triggerType,
            'trigger_value' => $this->jsonField($data['trigger_value'] ?? null),
            'entry_audience_ids' => $this->jsonIds($data['entry_audience_ids'] ?? []),
            'exit_audience_ids' => $this->jsonIds($data['exit_audience_ids'] ?? []),
            'status' => ($data['status'] ?? EmailAutomation::STATUS_ACTIVE) === EmailAutomation::STATUS_PAUSED
                ? EmailAutomation::STATUS_PAUSED : EmailAutomation::STATUS_ACTIVE,
        ]);
        $id = (int) $auto->save();
        if ($id <= 0) {
            return ['success' => false, 'error' => 'تعذر حفظ سير العمل'];
        }
        return ['success' => true, 'id' => $id];
    }

    public function update(int $userId, int $automationId, array $data): array
    {
        $auto = $this->findOwned($userId, $automationId);
        if (!$auto) {
            return ['success' => false, 'error' => 'سير العمل غير موجود'];
        }
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $data)) {
                $auto->setAttribute($field, $data[$field] !== null ? trim((string) $data[$field]) : null);
            }
        }
        if (isset($data['trigger_type']) && isset(EmailAutomation::triggers()[$data['trigger_type']])) {
            $auto->setAttribute('trigger_type', (string) $data['trigger_type']);
        }
        if (array_key_exists('trigger_value', $data)) {
            $auto->setAttribute('trigger_value', $this->jsonField($data['trigger_value']));
        }
        if (array_key_exists('entry_audience_ids', $data)) {
            $auto->setAttribute('entry_audience_ids', $this->jsonIds($data['entry_audience_ids']));
        }
        if (array_key_exists('exit_audience_ids', $data)) {
            $auto->setAttribute('exit_audience_ids', $this->jsonIds($data['exit_audience_ids']));
        }
        $auto->save();
        return ['success' => true];
    }

    public function delete(int $userId, int $automationId): array
    {
        $auto = $this->findOwned($userId, $automationId);
        if (!$auto) {
            return ['success' => false, 'error' => 'سير العمل غير موجود'];
        }
        $auto->delete();
        return ['success' => true];
    }

    public function setStatus(int $userId, int $automationId, string $status): array
    {
        $auto = $this->findOwned($userId, $automationId);
        if (!$auto) {
            return ['success' => false, 'error' => 'سير العمل غير موجود'];
        }
        $auto->setAttribute('status', $status === EmailAutomation::STATUS_ACTIVE ? 'active' : 'paused');
        $auto->save();
        return ['success' => true];
    }

    /**
     * استبدال خطوات سير العمل دفعة واحدة (يُبسّط منشئ السير).
     */
    public function setSteps(int $userId, int $automationId, array $steps): array
    {
        $auto = $this->findOwned($userId, $automationId);
        if (!$auto) {
            return ['success' => false, 'error' => 'سير العمل غير موجود'];
        }
        $valid = [];
        $indexToPos = [];
        foreach ($steps as $i => $step) {
            $type = (string) ($step['step_type'] ?? '');
            if (!isset(EmailAutomationStep::types()[$type])) {
                continue;
            }
            $indexToPos[(int) $i] = count($valid);
            $valid[] = $step;
        }
        $prepared = [];
        foreach ($valid as $position => $step) {
            $type = (string) $step['step_type'];
            $value = is_array($step['step_value'] ?? null) ? $step['step_value'] : (json_decode((string) ($step['step_value'] ?? ''), true) ?: []);
            if ($type === EmailAutomationStep::STEP_CONDITION) {
                // else_position = index الخطوة (في القائمة المرسلة) اللي يروحلها لو الشرط false؛ -1 = إنهاء. للأمام فقط (بدون حلقات).
                $else = (int) ($value['else_position'] ?? -1);
                $mapped = $else >= 0 && isset($indexToPos[$else]) ? $indexToPos[$else] : -1;
                if ($mapped !== -1 && $mapped <= $position) {
                    return ['success' => false, 'error' => 'فرع "وإلا" لازم يروح لخطوة لاحقة (بدون حلقات)'];
                }
                $value['else_position'] = $mapped;
                if (!self::isValidCheck((string) ($value['check'] ?? ''))) {
                    return ['success' => false, 'error' => 'نوع الشرط غير مدعوم'];
                }
            }
            if ($type === EmailAutomationStep::STEP_GOAL && !self::isValidCheck((string) ($value['check'] ?? ''))) {
                return ['success' => false, 'error' => 'نوع شرط الهدف غير مدعوم'];
            }
            $prepared[] = ['position' => $position, 'step_type' => $type, 'step_value' => $this->jsonField($value)];
        }
        // الحذف بعد التحقق من كل الخطوات: خطوة غلط ما تمسحش سير العمل الموجود
        $this->db->query("DELETE FROM email_automation_steps WHERE automation_id = ?", [$automationId]);
        foreach ($prepared as $row) {
            (new EmailAutomationStep(['automation_id' => $automationId] + $row))->save();
        }
        return ['success' => true];
    }

    // ============================ Event Hooks ============================

    /**
     * يُستدعى عند وقوع حدث (اشتراك/فتح/كليك/وسم). يبحث عن أتمتة مطابقة
     * ويسجّل المشاركين ثم يعالج أي خطوات فورية مستحقة.
     */
    public function handleEvent(int $userId, string $triggerType, array $event): void
    {
        $automations = $this->db->query(
            "SELECT * FROM email_automations
             WHERE user_id = ? AND status = 'active' AND trigger_type = ?",
            [$userId, $triggerType]
        );
        if (empty($automations)) {
            return;
        }

        foreach ($automations as $row) {
            if (!$this->triggerMatches($row, $event)) {
                continue;
            }
            $this->enroll((int) $row['id'], (int) ($event['subscriber_id'] ?? 0), $event);
        }

        $this->processDue();
    }

    /** هل يطابق حدثٌ شرطَ مشغل الأتمتة؟ */
    private function triggerMatches(array $automation, array $event): bool
    {
        $trigger = json_decode((string) ($automation['trigger_value'] ?? 'null'), true) ?: [];
        switch ($automation['trigger_type']) {
            case EmailAutomation::TRIGGER_SUBSCRIBED:
                $listId = (int) ($trigger['list_id'] ?? 0);
                return $listId === 0 || (int) ($event['list_id'] ?? 0) === $listId;
            case EmailAutomation::TRIGGER_TAG_ADDED:
                $tag = (string) ($trigger['tag'] ?? '');
                return $tag === '' || (string) ($event['tag'] ?? '') === $tag;
            case EmailAutomation::TRIGGER_CAMPAIGN_OPENED:
            case EmailAutomation::TRIGGER_CAMPAIGN_CLICKED:
                $campaignId = (int) ($trigger['campaign_id'] ?? 0);
                return $campaignId === 0 || (int) ($event['campaign_id'] ?? 0) === $campaignId;
            default:
                // مشغّلات الحجز/الرحلة/CRM (EmailPlatformBridge)
                return class_exists('EmailPlatformBridge')
                    && isset(EmailPlatformBridge::triggers()[(string) $automation['trigger_type']])
                    && EmailPlatformBridge::triggerMatches((string) $automation['trigger_type'], $trigger, $event);
        }
    }

    /**
     * تسجيل مشترك في سير عمل (يبدأ من الخطوة 0). يتحقق من أهلية الدخول
     * ومن عدم وجود مشاركة نشطة مسبقة.
     */
    public function enroll(int $automationId, int $subscriberId, array $context = []): bool
    {
        if ($automationId <= 0 || $subscriberId <= 0) {
            return false;
        }
        $auto = (new EmailAutomation())->find($automationId);
        if (!$auto || $auto->getAttribute('status') !== EmailAutomation::STATUS_ACTIVE) {
            return false;
        }
        $userId = (int) $auto->getAttribute('user_id');

        // قيود الدخول: لازم يكون في واحدة من القوائم المؤهلة لو حُددت
        $entryIds = json_decode((string) $auto->getAttribute('entry_audience_ids'), true) ?: [];
        if (!empty($entryIds)) {
            $ok = false;
            foreach ($entryIds as $listId) {
                $found = $this->db->query(
                    "SELECT list_id FROM email_list_subscriber WHERE subscriber_id = ? AND list_id = ? LIMIT 1",
                    [$subscriberId, (int) $listId]
                );
                if (!empty($found)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        // منع ازدواج المشاركة النشطة
        $existing = $this->db->query(
            "SELECT id FROM email_automation_entries
             WHERE automation_id = ? AND subscriber_id = ? AND status = 'active' LIMIT 1",
            [$automationId, $subscriberId]
        );
        if (!empty($existing)) {
            return false;
        }

        $entry = new EmailAutomationEntry([
            'automation_id' => $automationId,
            'user_id' => $userId,
            'subscriber_id' => $subscriberId,
            'step_position' => 0,
            'status' => EmailAutomationEntry::STATUS_ACTIVE,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
        $entry->save();
        return true;
    }

    // ============================ Processing ============================

    /**
     * معالجة المشاركات المستحقة (الخطوات الفورية + منتهي الانتظار + date_after).
     * @return array ['processed'=>int, 'completed'=>int]
     */
    public function processDue(): array
    {
        $this->enrollDateAfterAutomations();
        if (class_exists('EmailPlatformBridge')) {
            (new EmailPlatformBridge())->enrollTripAutomations();
        }

        $entries = $this->db->query(
            "SELECT * FROM email_automation_entries
             WHERE status = 'active'
               AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY id ASC
             LIMIT 200"
        );

        $processed = 0;
        $completed = 0;
        foreach ($entries as $row) {
            $result = $this->processEntry((int) $row['id']);
            $processed++;
            if ($result['completed']) {
                $completed++;
            }
        }
        return ['processed' => $processed, 'completed' => $completed];
    }

    /** معالجة مشاركة واحدة: ينفّذ الخطوة الحالية ويتقدم (مع حلقات للخطوات غير الانتظارية). */
    public function processEntry(int $entryId): array
    {
        $entry = (new EmailAutomationEntry())->find($entryId);
        if (!$entry || $entry->getAttribute('status') !== EmailAutomationEntry::STATUS_ACTIVE) {
            return ['processed' => false, 'completed' => false];
        }
        $automationId = (int) $entry->getAttribute('automation_id');
        $auto = (new EmailAutomation())->find($automationId);
        if (!$auto || $auto->getAttribute('status') !== EmailAutomation::STATUS_ACTIVE) {
            $entry->setAttribute('status', EmailAutomationEntry::STATUS_PAUSED);
            $entry->save();
            return ['processed' => false, 'completed' => false];
        }
        if ($auto->getAttribute('trigger_type') !== EmailAutomation::TRIGGER_DATE_AFTER
            && !$this->stillInExitAudience($auto, (int) $entry->getAttribute('subscriber_id'))) {
            $this->exitEntry($entry);
            return ['processed' => false, 'completed' => false];
        }

        $userId = (int) $auto->getAttribute('user_id');
        $subscriberId = (int) $entry->getAttribute('subscriber_id');
        $context = json_decode((string) $entry->getAttribute('context'), true) ?: [];

        // قد ننفّذ أكثر من خطوة غير انتظارية في الجلسة الواحدة
        $guard = 0;
        while ($guard++ < 50) {
            $step = $this->currentStep($automationId, (int) $entry->getAttribute('step_position'));
            if ($step === null) {
                $this->completeEntry($entry);
                return ['processed' => true, 'completed' => true];
            }
            $value = json_decode((string) ($step['step_value'] ?? 'null'), true) ?: [];

            if ($step['step_type'] === EmailAutomationStep::STEP_CONDITION || $step['step_type'] === EmailAutomationStep::STEP_GOAL) {
                $ok = self::evaluateCheck($value, $this->gatherFacts($userId, $subscriberId, $automationId, $entryId));
                $pos = (int) $step['position'];
                if ($step['step_type'] === EmailAutomationStep::STEP_GOAL) {
                    if ($ok) {
                        $this->completeEntry($entry);
                        return ['processed' => true, 'completed' => true];
                    }
                    $next = $pos + 1;
                } else {
                    $next = self::nextPositionAfterCondition($ok, $pos, (int) ($value['else_position'] ?? -1));
                    if ($next === null) {
                        $this->completeEntry($entry);
                        return ['processed' => true, 'completed' => true];
                    }
                }
                $entry->setAttribute('step_position', $next);
                $entry->setAttribute('next_run_at', null);
                $entry->setAttribute('last_processed_at', date('Y-m-d H:i:s'));
                $entry->save();
                continue;
            }

            if ($step['step_type'] === EmailAutomationStep::STEP_WAIT) {
                $delayMinutes = $this->waitMinutes($value);
                $entryNextRun = $entry->getAttribute('next_run_at');
                if ($entryNextRun !== null) {
                    $nextTs = strtotime((string) $entryNextRun);
                    if ($nextTs !== false && $nextTs <= time()) {
                        // انتهت فترة الانتظار → ننتقل للخطوة التالية
                        $entry->setAttribute('step_position', (int) $entry->getAttribute('step_position') + 1);
                        $entry->setAttribute('next_run_at', null);
                        $entry->setAttribute('last_processed_at', date('Y-m-d H:i:s'));
                        $entry->save();
                        continue;
                    }
                }
                // أول زيارة لخطوة الانتظار: جدولة الاستحقاق
                $nextRun = date('Y-m-d H:i:s', time() + $delayMinutes * 60);
                $entry->setAttribute('next_run_at', $nextRun);
                $entry->setAttribute('last_processed_at', date('Y-m-d H:i:s'));
                $entry->save();
                return ['processed' => true, 'completed' => false];
            }

            // خطوة فعلية → تنفيذ ثم تقدم
            $advance = $this->executeStep($userId, $subscriberId, $context, $step, $value, $automationId, $entryId);
            if (!$advance) {
                // الخطوة لم تُنفذ (مثل قائمة غير موجودة) - نكمل للخطوة التالية
                $this->db->exec(
                    "UPDATE email_automation_entries SET step_position = step_position + 1, last_processed_at = NOW(), next_run_at = NULL WHERE id = ?",
                    [$entryId]
                );
                continue;
            }
            $entry->setAttribute('step_position', (int) $entry->getAttribute('step_position') + 1);
            $entry->setAttribute('next_run_at', null);
            $entry->setAttribute('last_processed_at', date('Y-m-d H:i:s'));
            $entry->save();
        }

        return ['processed' => true, 'completed' => false];
    }

    // ---------------------------- branching helpers ----------------------------

    public const CHECKS = ['has_tag', 'not_has_tag', 'in_list', 'not_in_list', 'opened_last_email', 'clicked_last_email', 'not_opened_last_email', 'not_clicked_last_email'];

    public static function isValidCheck(string $check): bool
    {
        return in_array($check, self::CHECKS, true);
    }

    /**
     * تقييم نقي: $facts = tags(lowercase[]), lists(int[]), last_opened(bool), last_clicked(bool)
     */
    public static function evaluateCheck(array $value, array $facts): bool
    {
        $check = (string) ($value['check'] ?? '');
        $tag = mb_strtolower(trim((string) ($value['tag'] ?? '')));
        $list = (int) ($value['list_id'] ?? 0);
        $hasTag = in_array($tag, $facts['tags'] ?? [], true);
        $inList = in_array($list, array_map('intval', $facts['lists'] ?? []), true);
        switch ($check) {
            case 'has_tag': return $tag !== '' && $hasTag;
            case 'not_has_tag': return $tag !== '' && !$hasTag;
            case 'in_list': return $list > 0 && $inList;
            case 'not_in_list': return $list > 0 && !$inList;
            case 'opened_last_email': return !empty($facts['last_opened']);
            case 'clicked_last_email': return !empty($facts['last_clicked']);
            case 'not_opened_last_email': return empty($facts['last_opened']);
            case 'not_clicked_last_email': return empty($facts['last_clicked']);
        }
        return false;
    }

    /** الموضع التالي بعد شرط: true → الخطوة اللي بعده؛ false → else_position (-1 = إنهاء ⇒ null). */
    public static function nextPositionAfterCondition(bool $result, int $position, int $elsePosition): ?int
    {
        if ($result) {
            return $position + 1;
        }
        return $elsePosition > $position ? $elsePosition : null;
    }

    private function gatherFacts(int $userId, int $subscriberId, int $automationId, int $entryId): array
    {
        $tags = $this->db->query(
            "SELECT t.name FROM email_tags t JOIN email_subscriber_tag st ON st.tag_id = t.id WHERE st.subscriber_id = ? AND t.user_id = ?",
            [$subscriberId, $userId]
        );
        $lists = $this->db->query("SELECT list_id FROM email_list_subscriber WHERE subscriber_id = ?", [$subscriberId]);
        $last = $this->db->query(
            "SELECT status, opened_at, clicked_at FROM email_automation_logs WHERE entry_id = ? AND automation_id = ? ORDER BY id DESC LIMIT 1",
            [$entryId, $automationId]
        )[0] ?? null;
        return [
            'tags' => array_map(fn ($r) => mb_strtolower((string) $r['name']), $tags),
            'lists' => array_map(fn ($r) => (int) $r['list_id'], $lists),
            'last_opened' => $last && !empty($last['opened_at']),
            'last_clicked' => $last && !empty($last['clicked_at']),
        ];
    }

    private function currentStep(int $automationId, int $position): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM email_automation_steps
             WHERE automation_id = ? AND position >= ?
             ORDER BY position ASC, id ASC LIMIT 1",
            [$automationId, $position]
        );
        return $rows[0] ?? null;
    }

    private function executeStep(int $userId, int $subscriberId, array $context, array $step, array $value, int $automationId, int $entryId): bool
    {
        switch ($step['step_type']) {
            case EmailAutomationStep::STEP_SEND_EMAIL:
                $subject = (string) ($value['subject'] ?? '');
                $html = (string) ($value['html'] ?? '');
                if ($subject === '' && $html === '') {
                    return false;
                }
                if (!empty($value['template_id'])) {
                    $template = (new EmailTemplate())->find((int) $value['template_id']);
                    if ($template && (int) $template->getAttribute('user_id') === $userId) {
                        $subject = $subject !== '' ? $subject : (string) $template->getAttribute('subject');
                        $html = $html !== '' ? $html : (string) $template->getAttribute('html_body');
                    }
                }
                return $this->sendToSubscriber($userId, $subscriberId, $subject, $html, $automationId, (int) ($step['id'] ?? 0), $entryId, $context);
            case EmailAutomationStep::STEP_ADD_TAG:
                return (new ContactManagementService())->applyTagByName($userId, $subscriberId, (string) ($value['tag'] ?? '')) !== false;
            case EmailAutomationStep::STEP_REMOVE_TAG:
                $tag = $this->findTag($userId, (string) ($value['tag'] ?? ''));
                if (!$tag) {
                    return true;
                }
                return (new ContactManagementService())->removeTag($userId, $subscriberId, (int) $tag['id'])['success'];
            case EmailAutomationStep::STEP_ADD_TO_LIST:
                $listId = (int) ($value['list_id'] ?? 0);
                if ($listId <= 0) {
                    return false;
                }
                (new EmailListService())->subscribe($userId, $this->subscriberEmail($subscriberId), [], $listId);
                return true;
            case EmailAutomationStep::STEP_REMOVE_FROM_LIST:
                $listId = (int) ($value['list_id'] ?? 0);
                if ($listId <= 0) {
                    return false;
                }
                $this->db->query("DELETE FROM email_list_subscriber WHERE subscriber_id = ? AND list_id = ?", [$subscriberId, $listId]);
                return true;
            case EmailAutomationStep::STEP_END:
                return true;
            default:
                // خطوات بتنفّذ في خدمات تانية (مهمة CRM / إشعار)
                if (class_exists('EmailPlatformBridge')) {
                    return (bool) (new EmailPlatformBridge())->executeStep((string) $step['step_type'], $userId, $subscriberId, $value, $context + ['_entry_id' => $entryId, '_step_id' => (int) ($step['id'] ?? 0)]);
                }
                return false;
        }
    }

    private function sendToSubscriber(int $userId, int $subscriberId, string $subject, string $html, int $automationId, int $stepId, int $entryId, array $context = []): bool
    {
        $row = $this->db->query(
            "SELECT * FROM email_subscribers WHERE id = ? AND user_id = ? AND status = 'subscribed' LIMIT 1",
            [$subscriberId, $userId]
        );
        if (empty($row)) {
            return false;
        }
        $sub = $row[0];
        $attributes = json_decode((string) ($sub['attributes'] ?? 'null'), true) ?: [];
        // سياق الحدث (مثلاً الحجز) يغلب على attributes: {{trip_name}}، {{booking_reference}}...
        if (($context['source'] ?? '') === 'booking' && class_exists('EmailPlatformBridge')) {
            foreach (array_keys(EmailPlatformBridge::variables()) as $placeholder) {
                $key = trim($placeholder, '{}');
                if (isset($context[$key]) && is_scalar($context[$key])) {
                    $attributes[$key] = (string) $context[$key];
                }
            }
        }
        $companyName = $this->db->query("SELECT company_name FROM users WHERE id = ? LIMIT 1", [$userId]);
        $data = [
            'name' => $sub['name'],
            'first_name' => $sub['name'],
            'email' => $sub['email'],
            'company_name' => $companyName[0]['company_name'] ?? 'Tourfecto',
            'campaign_name' => '',
            'attributes' => $attributes,
        ];
        $renderer = new EmailRenderer();
        $subject = $renderer->personalize($subject, $data);
        // لو الـ token مفقود لأي سبب، نولّد واحد فريد من البريد (مش قيم ثابتة
        // يتشاركها كل المشتركين الناقصين فيرتبط إلغاء اشتراك أي حد بالباقيين).
        $unsubToken = $sub['unsubscribe_token'] ?? '';
        if ($unsubToken === '') {
            $unsubToken = hash('sha256', 'auto-unsub:' . ($sub['email'] ?? '') . ':' . ($sub['id'] ?? 0));
        }
        $baseUrl = rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.com', '/');
        // G3: توكنات فتح/كليك فريدة تُحفظ في email_automation_logs قبل الإرسال
        // حتى تتبعها مسارات /track/open و /track/click (كانت تُولَّد وتُهمل).
        $openToken = $this->token();
        $clickToken = $this->token();
        $logId = $this->insertLog($userId, $automationId, $entryId, $stepId, $subscriberId, (string) $sub['email'], (string) ($sub['name'] ?? ''), $subject, $openToken, $clickToken);
        $html = $renderer->finalize(
            $html,
            $data,
            $openToken,
            $clickToken,
            $baseUrl,
            $baseUrl . '/api/email-marketing/unsubscribe/' . rawurlencode($unsubToken)
        );
        try {
            $result = (new SmtpSettingsService())->mailerForUser($userId)->send((string) $sub['email'], (string) ($sub['name'] ?? ''), $subject, $html);
            $ok = !empty($result['success']);
            $this->updateLogResult($logId, $ok, (string) ($result['error'] ?? ''));
            return $ok;
        } catch (\Throwable $e) {
            $this->updateLogResult($logId, false, $e->getMessage());
            return false;
        }
    }

    /** إنشاء سجل إرسال أتمتة مع توكنات التتبع (G3). */
    private function insertLog(int $userId, int $automationId, int $entryId, int $stepId, int $subscriberId, string $email, string $name, string $subject, string $openToken, string $clickToken): int
    {
        return (int) $this->db->query(
            "INSERT INTO email_automation_logs
                (user_id, automation_id, entry_id, step_id, subscriber_id, to_email, to_name, subject, status, open_token, click_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sending', ?, ?)",
            [$userId, $automationId, $entryId > 0 ? $entryId : null, $stepId > 0 ? $stepId : null, $subscriberId, $email, $name, $subject, $openToken, $clickToken]
        );
    }

    /** تحديث حالة الإرسال (sent/failed) + الخطأ على سجل الأتمتة. */
    private function updateLogResult(int $logId, bool $ok, string $error): void
    {
        if ($logId <= 0) {
            return;
        }
        $this->db->query(
            "UPDATE email_automation_logs
             SET status = ?, error = ? WHERE id = ?",
            [$ok ? 'sent' : 'failed', $error !== '' ? substr($error, 0, 1000) : null, $logId]
        );
    }

    private function completeEntry(EmailAutomationEntry $entry): void
    {
        $entry->setAttribute('status', EmailAutomationEntry::STATUS_COMPLETED);
        $entry->setAttribute('completed_at', date('Y-m-d H:i:s'));
        $entry->setAttribute('next_run_at', null);
        $entry->save();
    }

    private function exitEntry(EmailAutomationEntry $entry): void
    {
        $entry->setAttribute('status', EmailAutomationEntry::STATUS_EXITED);
        $entry->setAttribute('next_run_at', null);
        $entry->save();
    }

    /** هل المشترك ما زال في قوائم الخروج (لو حُددت)؟ */
    private function stillInExitAudience(EmailAutomation $auto, int $subscriberId): bool
    {
        $exitIds = json_decode((string) $auto->getAttribute('exit_audience_ids'), true) ?: [];
        if (empty($exitIds)) {
            return true;
        }
        $placeholders = implode(',', array_fill(0, count($exitIds), '?'));
        $rows = $this->db->query(
            "SELECT list_id FROM email_list_subscriber
             WHERE subscriber_id = ? AND list_id IN ({$placeholders}) LIMIT 1",
            array_merge([$subscriberId], array_map('intval', $exitIds))
        );
        return !empty($rows);
    }

    /** مشغلات date_after: تسجيل المشتركين الذين مرّت عليهم المدة المستحقة. */
    private function enrollDateAfterAutomations(): void
    {
        $automations = $this->db->query(
            "SELECT * FROM email_automations WHERE status = 'active' AND trigger_type = ?",
            [EmailAutomation::TRIGGER_DATE_AFTER]
        );
        foreach ($automations as $auto) {
            $userId = (int) $auto['user_id'];
            $trigger = json_decode((string) ($auto['trigger_value'] ?? 'null'), true) ?: [];
            $days = max(1, (int) ($trigger['days'] ?? 7));
            $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

            // NOT EXISTS: من غيره المشترك اللي خلّص السير كان بيتسجّل تاني في كل
            // تشغيل cron (enroll بيمنع المشاركة *النشطة* المكررة بس) فيوصله نفس البريد كل دقيقة.
            $subscribers = $this->db->query(
                "SELECT s.id FROM email_subscribers s
                 WHERE s.user_id = ? AND s.status = 'subscribed' AND s.created_at <= ?
                   AND NOT EXISTS (SELECT 1 FROM email_automation_entries e WHERE e.automation_id = ? AND e.subscriber_id = s.id)
                 ORDER BY s.id ASC LIMIT 500",
                [$userId, $cutoff, (int) $auto['id']]
            );
            foreach ($subscribers as $sub) {
                $this->enroll((int) $auto['id'], (int) $sub['id'], ['source' => 'date_after']);
            }
        }
    }

    // ============================ Helpers ============================

    private function findOwned(int $userId, int $automationId): ?EmailAutomation
    {
        $auto = (new EmailAutomation())->find($automationId);
        if (!$auto || (int) $auto->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $auto;
    }

    private function findTag(int $userId, string $name): ?array
    {
        if ($name === '') {
            return null;
        }
        $rows = $this->db->query(
            "SELECT * FROM email_tags WHERE user_id = ? AND name = ? LIMIT 1",
            [$userId, $name]
        );
        return $rows[0] ?? null;
    }

    private function subscriberEmail(int $subscriberId): string
    {
        $row = $this->db->query("SELECT email FROM email_subscribers WHERE id = ? LIMIT 1", [$subscriberId]);
        return $row[0]['email'] ?? '';
    }

    private function waitMinutes(array $value): int
    {
        return (int) $value['days'] * 1440 + (int) ($value['hours'] ?? 0) * 60 + (int) ($value['minutes'] ?? 0);
    }

    private function jsonField($value): ?string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (is_array($value) && empty($value)) {
            return null;
        }
        return json_encode($value ?? [], JSON_UNESCAPED_UNICODE);
    }

    private function jsonIds($value): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $value), fn ($v) => $v > 0)));
        return empty($ids) ? null : json_encode($ids);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
