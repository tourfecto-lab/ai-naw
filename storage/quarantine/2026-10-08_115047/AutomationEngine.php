<?php

/**
 * Tourfecto - محرك الأتمتة الموحّد (مركز الأتمتة)
 *
 * نقطة واحدة كل خدمة بتسألها قبل ما تعمل حاجة لوحدها:
 *
 *   $gate = AutomationEngine::instance()->gate($userId, $websiteId, 'review_replies',
 *       $bucket, 'review', $reviewId, ['title' => .., 'context' => .., 'draft' => ..]);
 *
 *   execute → نفّذ دلوقتي (وضع أوتوماتيك، أو العميل وافق). بعد التنفيذ نادِ complete().
 *   wait    → مستني موافقة العميل (اتسجّل في صندوق الموافقات).
 *   skip    → ماتعملش حاجة (وضع يدوي، أو العميل رفض، أو اتنفّذ قبل كده).
 *
 * التعلّم من الشغل اليدوي:
 *  - أي رد العميل بيكتبه أو بيعدّله بيتحفظ كمثال لأسلوبه (automation_examples)،
 *    والأمثلة دي بتتحط في تعليمات الذكاء الاصطناعي (styleBlock) فالمسودات
 *    الجاية بتطلع بنفس أسلوبه - في كل الأوضاع، حتى اليدوي.
 *  - في وضع "يتعلم مني": كل فئة بتتحسب لها نسبة الموافقة من غير تعديل جوهري
 *    على آخر N قرار. لما النسبة توصل للحد (افتراضي 90% من آخر 20، بحد أدنى 10)
 *    الفئة دي بتتحول أوتوماتيك لوحدها ويوصل للعميل إشعار.
 *  - لو العميل علّم إجراء اتعمل أوتوماتيك إنه "مش مناسب"، الفئة دي بترجع
 *    لوضع التعلم تاني.
 *
 * @version 1.0.0
 */
class AutomationEngine
{
    /** تعديل أقل من كده (قرب ≥ 85%) بيتحسب "موافقة" مش "تصحيح" */
    public const MINOR_EDIT_SIMILARITY = 0.85;

    /** @var AutomationEngine|null */
    private static $instance = null;

    /** @var Database */
    private $db;

    /** @var array<string,array> */
    private $policyCache = [];

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** للاختبارات بس */
    public static function setInstance(?self $engine): void
    {
        self::$instance = $engine;
    }

    // =====================================================================
    // السياسات (الأوضاع)
    // =====================================================================

    public function getPolicy(int $userId, string $feature): array
    {
        $key = $userId . ':' . $feature;
        if (isset($this->policyCache[$key])) {
            return $this->policyCache[$key];
        }

        $def = AutomationCatalog::get($feature) ?? ['default_mode' => 'manual', 'default_bucket_modes' => []];
        $policy = [
            'feature_key' => $feature,
            'mode' => $def['default_mode'],
            'bucket_modes' => $def['default_bucket_modes'] ?? [],
            'graduated_buckets' => [],
            'scope_websites' => null,
            'learn_window' => 20,
            'learn_min_samples' => 10,
            'learn_threshold' => 0.90,
            'is_default' => true,
        ];

        try {
            $rows = $this->db->query(
                'SELECT * FROM automation_policies WHERE user_id = ? AND feature_key = ? LIMIT 1',
                [$userId, $feature]
            );
            if (!empty($rows)) {
                $r = $rows[0];
                $policy['mode'] = in_array($r['mode'], AutomationCatalog::MODES, true) ? $r['mode'] : $policy['mode'];
                $policy['bucket_modes'] = self::decodeMap($r['bucket_modes'] ?? null);
                $policy['graduated_buckets'] = self::decodeMap($r['graduated_buckets'] ?? null);
                if (array_key_exists('scope_websites', $r) && $r['scope_websites'] !== null && $r['scope_websites'] !== '') {
                    $policy['scope_websites'] = array_values(array_map('intval', self::decodeMap($r['scope_websites'])));
                }
                $policy['learn_window'] = max(5, (int) $r['learn_window']);
                $policy['learn_min_samples'] = max(3, (int) $r['learn_min_samples']);
                $policy['learn_threshold'] = min(1.0, max(0.5, (float) $r['learn_threshold']));
                $policy['is_default'] = false;
            }
        } catch (Throwable $e) {
            // الجدول لسه ما اتعملش (الـ migration ما اتشغّلش): نشتغل بالافتراضي
        }

        return $this->policyCache[$key] = $policy;
    }

    /**
     * الوضع الفعلي لفئة معيّنة: وضع الفئة لو متحدد، وإلا وضع الخدمة.
     * فئة في وضع "يتعلم مني" واتخرجت = أوتوماتيك.
     */
    public function effectiveMode(int $userId, string $feature, string $bucket): string
    {
        $p = $this->getPolicy($userId, $feature);
        $mode = $p['bucket_modes'][$bucket] ?? $p['mode'];
        if ($mode === 'learning' && isset($p['graduated_buckets'][$bucket])) {
            return 'auto';
        }
        return $mode;
    }

    /** هل الخدمة مفعّلة على الموقع ده؟ (العميل بيختار المواقع من نافذة الربط) */
    public function inScope(int $userId, string $feature, ?int $websiteId): bool
    {
        if (!$websiteId) {
            return true;
        }
        $scope = $this->getPolicy($userId, $feature)['scope_websites'];
        return $scope === null || in_array((int) $websiteId, $scope, true);
    }

    /** @param int[]|null $websiteIds null = كل المواقع المربوطة */
    /** لو ملف 000002 ماتشغّلش على السيرفر، العمود بيتعمل لوحده أول مرة */
    private function ensureScopeColumn(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM automation_policies LIKE 'scope_websites'");
            if (empty($cols)) {
                $this->db->exec("ALTER TABLE automation_policies ADD COLUMN scope_websites TEXT NULL AFTER graduated_buckets");
            }
        } catch (Throwable $e) {
        }
    }

    public function setScope(int $userId, string $feature, ?array $websiteIds): array
    {
        if (!AutomationCatalog::exists($feature)) {
            return ['success' => false, 'error' => 'invalid_feature'];
        }
        $p = $this->getPolicy($userId, $feature);
        $this->ensureScopeColumn();
        $json = $websiteIds === null ? null : json_encode(array_values(array_unique(array_map('intval', $websiteIds))));
        $this->db->query(
            'INSERT INTO automation_policies (user_id, feature_key, mode, bucket_modes, graduated_buckets, scope_websites)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE scope_websites = VALUES(scope_websites)',
            [$userId, $feature, $p['mode'], json_encode((object) $p['bucket_modes'], JSON_UNESCAPED_UNICODE), json_encode((object) $p['graduated_buckets']), $json]
        );
        unset($this->policyCache[$userId . ':' . $feature]);
        return ['success' => true, 'policy' => $this->getPolicy($userId, $feature)];
    }

    public function setPolicy(int $userId, string $feature, string $mode, ?array $bucketModes = null): array
    {
        if (!AutomationCatalog::exists($feature) || !in_array($mode, AutomationCatalog::MODES, true)) {
            return ['success' => false, 'error' => 'invalid_mode'];
        }
        $def = AutomationCatalog::get($feature);
        $current = $this->getPolicy($userId, $feature);
        $buckets = $current['bucket_modes'];
        if ($bucketModes !== null) {
            $buckets = [];
            foreach ($bucketModes as $b => $m) {
                if (isset($def['buckets'][$b]) && ($m === null || $m === '' || in_array($m, AutomationCatalog::MODES, true))) {
                    if ($m !== null && $m !== '') {
                        $buckets[$b] = $m;
                    }
                }
            }
        }

        $this->db->query(
            'INSERT INTO automation_policies (user_id, feature_key, mode, bucket_modes, graduated_buckets)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), bucket_modes = VALUES(bucket_modes)',
            [$userId, $feature, $mode, json_encode((object) $buckets, JSON_UNESCAPED_UNICODE), json_encode((object) $current['graduated_buckets'])]
        );
        unset($this->policyCache[$userId . ':' . $feature]);

        $this->syncLegacySettings($userId, $feature);

        return ['success' => true, 'policy' => $this->getPolicy($userId, $feature)];
    }

    /**
     * أجزاء قديمة من المنصة ليها مفاتيح تشغيل خاصة بيها. بنحدّثها من هنا
     * عشان كل حاجة تمشي على نفس الاختيار، من غير ما نكسر الكود القديم.
     */
    public function syncLegacySettings(int $userId, string $feature): void
    {
        try {
            if ($feature === 'website_fixes') {
                $m = $this->getPolicy($userId, $feature)['mode'];
                // auto = الإصلاحات المؤهلة بتتطبق لوحدها (balanced). غير كده
                // conservative والمحرك بيعدّي كل إصلاح على gate قبل التطبيق.
                $legacy = $m === 'manual' ? 'off' : ($m === 'auto' ? 'balanced' : 'balanced');
                $this->db->exec(
                    "UPDATE websites SET auto_pilot_mode = ? WHERE user_id = ? AND is_connected = 1",
                    [$legacy, $userId]
                );
            } elseif ($feature === 'ads_optimizations') {
                $m = $this->getPolicy($userId, $feature)['mode'];
                // المحرك بيعدّي كل إجراء على gate، فالإعلانات لازم تبقى
                // "approval" على الأقل عشان مايتنفذش حاجة من غير ما نسأل.
                $legacy = $m === 'manual' ? 'manual' : 'approval';
                $this->db->exec(
                    'UPDATE ad_autopilot_settings SET optimization_mode = ? WHERE user_id = ?',
                    [$legacy, $userId]
                );
            }
        } catch (Throwable $e) {
            // الجداول القديمة مش موجودة في بيئة معينة - مش حاجة حرجة
        }
    }

    // =====================================================================
    // البوابة: كل خدمة بتسأل هنا قبل ما تنفّذ
    // =====================================================================

    /**
     * @param array $data title, context, draft, payload(array)
     * @return array action(execute|wait|skip), decision_id, final_text, mode, reason
     */
    public function gate(int $userId, ?int $websiteId, string $feature, string $bucket, string $subjectType, string $subjectId, array $data = []): array
    {
        $existing = $this->findBySubject($feature, $subjectType, $subjectId);
        if ($existing) {
            switch ($existing['status']) {
                case 'pending':
                    return ['action' => 'wait', 'decision_id' => (int) $existing['id'], 'mode' => $existing['mode_at_decision'], 'reason' => 'pending_approval'];
                case 'approved':
                case 'approved_edited':
                    if (!empty($existing['executed_at'])) {
                        return ['action' => 'skip', 'decision_id' => (int) $existing['id'], 'reason' => 'already_executed'];
                    }
                    // التنفيذ فشل قريب: نستنى ساعة قبل المحاولة الجاية
                    if (!empty($existing['error_message']) && (int) $existing['age_updated'] < 3600) {
                        return ['action' => 'wait', 'decision_id' => (int) $existing['id'], 'reason' => 'retry_later'];
                    }
                    return [
                        'action' => 'execute',
                        'decision_id' => (int) $existing['id'],
                        'final_text' => $existing['final_text'] ?? $existing['draft_text'],
                        'payload' => self::decodeMap($existing['payload'] ?? null),
                        'mode' => $existing['mode_at_decision'],
                        'reason' => 'approved',
                    ];
                case 'failed':
                    // محاولة تانية لإجراء فشل قبل كده (مثلاً Google كان واقع)
                    // بحد أقصى مرة كل ساعة، ولمدة 3 أيام بس من أول محاولة
                    // (الحساب في قاعدة البيانات عشان فرق التوقيت بين PHP وMySQL)
                    $fresh = (int) $existing['age_created'] < 3 * 86400;
                    $cooled = (int) $existing['age_updated'] >= 3600;
                    if ($fresh && $cooled && ($existing['mode_at_decision'] === 'auto' || $existing['decided_at'] !== null)) {
                        return ['action' => 'execute', 'decision_id' => (int) $existing['id'], 'final_text' => $existing['final_text'] ?? $existing['draft_text'], 'payload' => self::decodeMap($existing['payload'] ?? null), 'mode' => $existing['mode_at_decision'], 'reason' => 'retry'];
                    }
                    return ['action' => 'skip', 'decision_id' => (int) $existing['id'], 'reason' => 'failed'];
                default:
                    return ['action' => 'skip', 'decision_id' => (int) $existing['id'], 'reason' => $existing['status']];
            }
        }

        if (!$this->inScope($userId, $feature, $websiteId)) {
            return ['action' => 'skip', 'decision_id' => null, 'mode' => 'manual', 'reason' => 'out_of_scope'];
        }

        $mode = $this->effectiveMode($userId, $feature, $bucket);
        if ($mode === 'manual') {
            return ['action' => 'skip', 'decision_id' => null, 'mode' => 'manual', 'reason' => 'manual_mode'];
        }

        $status = ($mode === 'auto' && empty($data['force_pending'])) ? 'auto_executed' : 'pending';
        if ($status === 'pending' && $mode === 'auto') {
            $mode = 'approval'; // اتعدّى حدود الأمان فبيستنى موافقة
        }
        $payload = isset($data['payload']) ? json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null;

        try {
            $id = (int) $this->db->query(
                'INSERT INTO automation_decisions
                    (user_id, website_id, feature_key, bucket, subject_type, subject_id, mode_at_decision, status, title, context_text, draft_text, payload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId, $websiteId, $feature, $bucket, $subjectType, $subjectId,
                    $mode, $status,
                    self::cut($data['title'] ?? null, 255), $data['context'] ?? null, $data['draft'] ?? null, $payload,
                ]
            );
        } catch (Throwable $e) {
            // تكرار (سباق بين cron وطلب يدوي): اقرا الموجود وارجع حسبه
            $existing = $this->findBySubject($feature, $subjectType, $subjectId);
            if ($existing) {
                return $this->gate($userId, $websiteId, $feature, $bucket, $subjectType, $subjectId, $data);
            }
            // الجدول مش موجود: نحافظ على السلوك القديم (تنفيذ)
            return ['action' => $mode === 'auto' ? 'execute' : 'skip', 'decision_id' => null, 'mode' => $mode, 'reason' => 'no_table'];
        }

        if ($status === 'pending') {
            $this->notifyPending($userId, $feature);
            return ['action' => 'wait', 'decision_id' => $id, 'mode' => $mode, 'reason' => 'pending_approval'];
        }

        return ['action' => 'execute', 'decision_id' => $id, 'final_text' => $data['draft'] ?? null, 'payload' => $data['payload'] ?? [], 'mode' => 'auto', 'reason' => 'auto'];
    }

    /** بعد التنفيذ الفعلي (نجح أو فشل) */
    public function complete(?int $decisionId, bool $success, ?string $error = null, ?string $executedText = null): void
    {
        if (!$decisionId) {
            return;
        }
        try {
            $row = $this->find($decisionId);
            if (!$row) {
                return;
            }
            if ($success) {
                $newStatus = in_array($row['status'], ['approved', 'approved_edited'], true) ? $row['status'] : 'auto_executed';
                $this->db->exec(
                    'UPDATE automation_decisions SET status = ?, executed_at = NOW(), error_message = NULL,
                        final_text = COALESCE(?, final_text, draft_text) WHERE id = ?',
                    [$newStatus, $executedText, $decisionId]
                );
            } elseif (in_array($row['status'], ['approved', 'approved_edited'], true)) {
                // قرار العميل يفضل زي ما هو (مهم للتعلّم)، والتنفيذ هيتعاد بعدين
                $this->db->exec(
                    'UPDATE automation_decisions SET error_message = ?, updated_at = NOW() WHERE id = ?',
                    [self::cut($error ?? 'failed', 500), $decisionId]
                );
            } else {
                $this->db->exec(
                    "UPDATE automation_decisions SET status = 'failed', error_message = ? WHERE id = ?",
                    [self::cut($error ?? 'failed', 500), $decisionId]
                );
            }
        } catch (Throwable $e) {
        }
    }

    // =====================================================================
    // قرار العميل (موافقة / تعديل / رفض) + التعلّم
    // =====================================================================

    /**
     * @param string $action approve|reject
     * @param bool $execute نفّذ دلوقتي عن طريق الـ handler (من صفحة مركز الأتمتة)
     */
    public function decide(int $userId, int $decisionId, string $action, ?string $editedText = null, bool $execute = true): array
    {
        $row = $this->find($decisionId);
        if (!$row || (int) $row['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'not_found'];
        }
        if ($row['status'] !== 'pending') {
            return ['success' => false, 'error' => 'already_decided', 'status' => $row['status']];
        }

        $draft = (string) ($row['draft_text'] ?? '');
        if ($action === 'reject') {
            $this->db->exec(
                "UPDATE automation_decisions SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE id = ?",
                [$userId, $decisionId]
            );
            $this->evaluateGraduation($userId, $row['feature_key'], $row['bucket']);
            return ['success' => true, 'status' => 'rejected'];
        }

        $final = $editedText !== null && trim($editedText) !== '' ? trim($editedText) : $draft;
        $similarity = $draft !== '' ? self::similarity($draft, $final) : 1.0;
        $status = ($draft !== '' && $final !== $draft) ? 'approved_edited' : 'approved';

        $this->db->exec(
            'UPDATE automation_decisions SET status = ?, final_text = ?, similarity = ?, decided_by = ?, decided_at = NOW() WHERE id = ?',
            [$status, $final, round($similarity, 4), $userId, $decisionId]
        );

        $def = AutomationCatalog::get($row['feature_key']);
        if ($def && $def['learning'] === 'text' && $final !== '') {
            $this->addExample($userId, $row['feature_key'], $row['bucket'], $status === 'approved_edited' ? 'edited' : 'approved', $row['context_text'], $draft, $final);
        }

        $graduation = $this->evaluateGraduation($userId, $row['feature_key'], $row['bucket']);

        $result = ['success' => true, 'status' => $status, 'similarity' => round($similarity, 2), 'graduated' => $graduation['graduated_now'] ?? false];

        if ($execute) {
            $result['execution'] = $this->executeDecision($decisionId);
        }
        return $result;
    }

    /** تنفيذ قرار متوافق عليه عن طريق handler الخدمة */
    public function executeDecision(int $decisionId): array
    {
        $row = $this->find($decisionId);
        if (!$row) {
            return ['success' => false, 'error' => 'not_found'];
        }
        $def = AutomationCatalog::get($row['feature_key']);
        $handlerClass = $def['handler'] ?? null;
        if (!$handlerClass || !class_exists($handlerClass)) {
            // مفيش handler: الـ cron هيلاقيه متوافق عليه وينفّذه في الدورة الجاية
            return ['success' => true, 'deferred' => true];
        }
        try {
            /** @var AutomationHandler $handler */
            $handler = new $handlerClass();
            $final = (string) ($row['final_text'] ?? $row['draft_text'] ?? '');
            $res = $handler->execute($row, $final);
            if (!empty($res['deferred'])) {
                return $res;
            }
            $this->complete($decisionId, !empty($res['success']), $res['error'] ?? null, $final !== '' ? $final : null);
            return $res;
        } catch (Throwable $e) {
            $this->complete($decisionId, false, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * العميل عمل الحاجة بنفسه من غير ما المنصة تجهّزها (مثلاً كتب رد على
     * تقييم بإيده). بنحفظها كمثال لأسلوبه، ولو كان فيه مسودة مستنية لنفس
     * الحاجة بنعتبرها اتوافق عليها بتعديل.
     */
    public function recordManualAction(int $userId, string $feature, string $bucket, string $subjectType, string $subjectId, string $finalText, ?string $context = null, ?string $draft = null): void
    {
        try {
            $existing = $this->findBySubject($feature, $subjectType, $subjectId);
            if ($existing && $existing['status'] === 'pending') {
                $d = (string) ($existing['draft_text'] ?? $draft ?? '');
                $sim = $d !== '' ? self::similarity($d, $finalText) : 0.0;
                $status = ($d !== '' && trim($d) === trim($finalText)) ? 'approved' : 'approved_edited';
                $this->db->exec(
                    'UPDATE automation_decisions SET status = ?, final_text = ?, similarity = ?, decided_by = ?, decided_at = NOW(), executed_at = NOW() WHERE id = ?',
                    [$status, $finalText, round($sim, 4), $userId, (int) $existing['id']]
                );
                $draft = $d;
                $context = $context ?? $existing['context_text'];
            } elseif (!$existing && $draft !== null && trim($draft) !== '') {
                // المنصة كانت جهّزت مسودة والعميل اشتغل عليها بنفسه: بيتحسب
                // في نسبة التعلم. الرد اليدوي من غير مسودة بيبقى مثال أسلوب بس.
                $this->db->query(
                    "INSERT INTO automation_decisions
                        (user_id, feature_key, bucket, subject_type, subject_id, mode_at_decision, status, context_text, draft_text, final_text, similarity, decided_by, decided_at, executed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $userId, $feature, $bucket, $subjectType, $subjectId,
                        $this->effectiveMode($userId, $feature, $bucket) === 'auto' ? 'auto' : $this->getPolicy($userId, $feature)['mode'],
                        ($draft !== null && $draft !== '' && trim($draft) === trim($finalText)) ? 'approved' : 'approved_edited',
                        $context, $draft, $finalText,
                        $draft ? round(self::similarity($draft, $finalText), 4) : null,
                        $userId,
                    ]
                );
            }

            $def = AutomationCatalog::get($feature);
            if ($def && $def['learning'] === 'text' && trim($finalText) !== '') {
                $source = ($draft && trim($draft) !== '' && trim($draft) === trim($finalText)) ? 'approved' : (($draft && trim($draft) !== '') ? 'edited' : 'manual');
                $this->addExample($userId, $feature, $bucket, $source, $context, $draft, $finalText);
            }
            $this->evaluateGraduation($userId, $feature, $bucket);
        } catch (Throwable $e) {
            // التعلّم ميوقفش الإجراء الأساسي أبدًا
        }
    }

    /** العميل رفض حاجة من خارج مركز الأتمتة (مثلاً تجاهل إصلاح) */
    public function recordManualRejection(int $userId, string $feature, string $subjectType, string $subjectId): void
    {
        try {
            $existing = $this->findBySubject($feature, $subjectType, $subjectId);
            if ($existing && $existing['status'] === 'pending') {
                $this->db->exec(
                    "UPDATE automation_decisions SET status = 'rejected', decided_by = ?, decided_at = NOW() WHERE id = ?",
                    [$userId, (int) $existing['id']]
                );
                $this->evaluateGraduation($userId, $feature, $existing['bucket']);
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * رأي العميل في إجراء اتعمل أوتوماتيك. "مش مناسب" بترجّع الفئة لوضع
     * التعلم عشان المنصة تسأله تاني لحد ما تفهم.
     */
    public function feedback(int $userId, int $decisionId, string $verdict): array
    {
        $row = $this->find($decisionId);
        if (!$row || (int) $row['user_id'] !== $userId || !in_array($verdict, ['good', 'bad'], true)) {
            return ['success' => false, 'error' => 'not_found'];
        }
        $this->db->exec('UPDATE automation_decisions SET feedback = ? WHERE id = ?', [$verdict, $decisionId]);

        $demoted = false;
        if ($verdict === 'bad' && $row['status'] === 'auto_executed') {
            $p = $this->getPolicy($userId, $row['feature_key']);
            $bucket = $row['bucket'];
            $graduated = $p['graduated_buckets'];
            $buckets = $p['bucket_modes'];
            if (isset($graduated[$bucket])) {
                unset($graduated[$bucket]);
            } else {
                $buckets[$bucket] = 'learning';
            }
            $this->db->query(
                'INSERT INTO automation_policies (user_id, feature_key, mode, bucket_modes, graduated_buckets)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE bucket_modes = VALUES(bucket_modes), graduated_buckets = VALUES(graduated_buckets)',
                [$userId, $row['feature_key'], $p['mode'], json_encode((object) $buckets, JSON_UNESCAPED_UNICODE), json_encode((object) $graduated)]
            );
            unset($this->policyCache[$userId . ':' . $row['feature_key']]);
            $demoted = true;
        }
        return ['success' => true, 'demoted' => $demoted];
    }

    // =====================================================================
    // التخرّج من وضع التعلم
    // =====================================================================

    public function learningStats(int $userId, string $feature, string $bucket): array
    {
        $p = $this->getPolicy($userId, $feature);
        $window = (int) $p['learn_window'];
        $rows = [];
        try {
            $rows = $this->db->query(
                "SELECT status, similarity FROM automation_decisions
                  WHERE user_id = ? AND feature_key = ? AND bucket = ?
                    AND status IN ('approved','approved_edited','rejected')
                    AND decided_by IS NOT NULL
                  ORDER BY decided_at DESC, id DESC LIMIT " . $window,
                [$userId, $feature, $bucket]
            );
        } catch (Throwable $e) {
        }
        $samples = count($rows);
        $good = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'approved') {
                $good++;
            } elseif ($r['status'] === 'approved_edited' && $r['similarity'] !== null && (float) $r['similarity'] >= self::MINOR_EDIT_SIMILARITY) {
                $good++;
            }
        }
        $rate = $samples > 0 ? $good / $samples : 0.0;
        $min = (int) $p['learn_min_samples'];
        $threshold = (float) $p['learn_threshold'];

        // كام موافقة كمان محتاجين عشان نوصل للحد
        $needed = 0;
        if ($samples < $min || $rate < $threshold) {
            $s = $samples;
            $g = $good;
            while (($s < $min || ($s > 0 ? $g / $s : 0) < $threshold) && $needed < 200) {
                $needed++;
                $s = min($window, $s + 1);
                // لو النافذة مليانة، الموافقة الجديدة بتطرد أقدم قرار (ممكن يكون رفض)
                $g = min($s, $g + 1);
            }
        }

        return [
            'samples' => $samples,
            'good' => $good,
            'rate' => round($rate, 3),
            'threshold' => $threshold,
            'min_samples' => $min,
            'window' => $window,
            'needed' => $needed,
            'graduated' => isset($p['graduated_buckets'][$bucket]),
            'graduated_at' => $p['graduated_buckets'][$bucket] ?? null,
        ];
    }

    public function evaluateGraduation(int $userId, string $feature, string $bucket): array
    {
        $p = $this->getPolicy($userId, $feature);
        $mode = $p['bucket_modes'][$bucket] ?? $p['mode'];
        if ($mode !== 'learning') {
            return ['graduated_now' => false];
        }
        $stats = $this->learningStats($userId, $feature, $bucket);
        if ($stats['graduated']) {
            return ['graduated_now' => false];
        }
        if ($stats['samples'] >= $stats['min_samples'] && $stats['rate'] >= $stats['threshold']) {
            $graduated = $p['graduated_buckets'];
            $graduated[$bucket] = date('Y-m-d H:i:s');
            $this->db->query(
                'INSERT INTO automation_policies (user_id, feature_key, mode, bucket_modes, graduated_buckets)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE graduated_buckets = VALUES(graduated_buckets)',
                [$userId, $feature, $p['mode'], json_encode((object) $p['bucket_modes'], JSON_UNESCAPED_UNICODE), json_encode((object) $graduated)]
            );
            unset($this->policyCache[$userId . ':' . $feature]);

            $def = AutomationCatalog::get($feature);
            if ($def && class_exists('Notification')) {
                $name = AutomationCatalog::label($def['name']);
                $bucketName = AutomationCatalog::label($def['buckets'][$bucket] ?? ['ar' => $bucket, 'en' => $bucket]);
                $pct = (int) round($stats['rate'] * 100);
                Notification::notify(
                    $userId,
                    'automation_graduated',
                    "{$name}: بقت أوتوماتيك ({$bucketName})",
                    "وافقت على {$pct}% من آخر {$stats['samples']} من غير تعديل، فالمنصة هتكمّل لوحدها. تقدر ترجّعها في أي وقت من مركز الأتمتة.",
                    '/automation'
                );
            }
            return ['graduated_now' => true, 'stats' => $stats];
        }
        return ['graduated_now' => false, 'stats' => $stats];
    }

    // =====================================================================
    // أسلوب العميل في تعليمات الذكاء الاصطناعي
    // =====================================================================

    public function addExample(int $userId, string $feature, string $bucket, string $source, ?string $context, ?string $draft, string $final): void
    {
        try {
            $this->db->query(
                'INSERT INTO automation_examples (user_id, feature_key, bucket, source, context_text, draft_text, final_text) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $feature, $bucket, $source, self::cut($context, 2000), self::cut($draft, 4000), self::cut($final, 4000)]
            );
        } catch (Throwable $e) {
        }
    }

    /** أحدث أمثلة أسلوب العميل - التعديلات والردود اليدوية الأول لأنها الأوضح */
    public function styleExamples(int $userId, string $feature, ?string $bucket = null, int $limit = 4): array
    {
        try {
            $params = [$userId, $feature];
            $bucketSql = '';
            if ($bucket !== null) {
                $bucketSql = ' AND bucket = ?';
                $params[] = $bucket;
            }
            $rows = $this->db->query(
                "SELECT bucket, source, context_text, draft_text, final_text FROM automation_examples
                  WHERE user_id = ? AND feature_key = ?{$bucketSql}
                  ORDER BY (source = 'approved') ASC, id DESC LIMIT " . max(1, min(10, $limit)),
                $params
            );
            if (empty($rows) && $bucket !== null) {
                return $this->styleExamples($userId, $feature, null, $limit);
            }
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** نص يتحط في آخر تعليمات الذكاء الاصطناعي عشان يكتب بأسلوب العميل */
    public function styleBlock(int $userId, string $feature, ?string $bucket = null): string
    {
        $examples = $this->styleExamples($userId, $feature, $bucket);
        if (empty($examples)) {
            return '';
        }
        $out = "\n\n--- أسلوب صاحب النشاط ---\n"
            . "دي ردود كتبها أو عدّلها صاحب النشاط بنفسه. اكتب بنفس الأسلوب والطول والنبرة وطريقة التحية والختام، ومن غير ما تنسخها حرفيًا:\n";
        $i = 0;
        foreach ($examples as $ex) {
            $i++;
            $ctx = trim((string) ($ex['context_text'] ?? ''));
            if ($ctx !== '') {
                $out .= "\nمثال {$i} - الرسالة/التقييم: " . self::cut($ctx, 300) . "\n";
            } else {
                $out .= "\nمثال {$i}:\n";
            }
            if (($ex['source'] ?? '') === 'edited' && !empty($ex['draft_text'])) {
                $out .= 'المسودة اللي ما عجبتوش: ' . self::cut((string) $ex['draft_text'], 300) . "\n";
            }
            $out .= 'الرد اللي اعتمده: ' . self::cut((string) $ex['final_text'], 500) . "\n";
        }
        return $out . "--- نهاية الأمثلة ---\n";
    }

    // =====================================================================
    // قراءة للصفحة
    // =====================================================================

    public function pending(int $userId, int $limit = 50, ?string $feature = null): array
    {
        $params = [$userId];
        $f = '';
        if ($feature) {
            $f = ' AND feature_key = ?';
            $params[] = $feature;
        }
        return $this->db->query(
            "SELECT * FROM automation_decisions WHERE user_id = ? AND status = 'pending'{$f} ORDER BY id DESC LIMIT " . max(1, min(200, $limit)),
            $params
        );
    }

    public function activity(int $userId, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT * FROM automation_decisions WHERE user_id = ? AND status <> 'pending' ORDER BY COALESCE(executed_at, decided_at, created_at) DESC, id DESC LIMIT " . max(1, min(200, $limit)),
            [$userId]
        );
    }

    public function counts(int $userId): array
    {
        $out = [];
        $rows = $this->db->query(
            "SELECT feature_key,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'auto_executed' AND created_at >= NOW() - INTERVAL 30 DAY) AS auto_30d,
                    SUM(status IN ('approved','approved_edited') AND decided_at >= NOW() - INTERVAL 30 DAY) AS approved_30d,
                    SUM(status = 'failed') AS failed
               FROM automation_decisions WHERE user_id = ? GROUP BY feature_key",
            [$userId]
        );
        foreach ($rows as $r) {
            $out[$r['feature_key']] = [
                'pending' => (int) $r['pending'],
                'auto_30d' => (int) $r['auto_30d'],
                'approved_30d' => (int) $r['approved_30d'],
                'failed' => (int) $r['failed'],
            ];
        }
        return $out;
    }

    // =====================================================================
    // أدوات
    // =====================================================================

    /**
     * قرب نصين من بعض 0..1 على مستوى الكلمات (LCS). بيشتغل مع العربي
     * والإنجليزي لأنه بيقطّع بـ mb وبيتجاهل التشكيل وعلامات الترقيم.
     */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        $na = count($ta);
        $nb = count($tb);
        if ($na === 0 && $nb === 0) {
            return 1.0;
        }
        if ($na === 0 || $nb === 0) {
            return 0.0;
        }
        // حد أقصى عشان الأداء مع المقالات الطويلة
        $ta = array_slice($ta, 0, 400);
        $tb = array_slice($tb, 0, 400);
        $na = count($ta);
        $nb = count($tb);
        $prev = array_fill(0, $nb + 1, 0);
        for ($i = 1; $i <= $na; $i++) {
            $cur = [0];
            for ($j = 1; $j <= $nb; $j++) {
                $cur[$j] = ($ta[$i - 1] === $tb[$j - 1])
                    ? $prev[$j - 1] + 1
                    : max($prev[$j], $cur[$j - 1]);
            }
            $prev = $cur;
        }
        return (2.0 * $prev[$nb]) / ($na + $nb);
    }

    private static function tokens(string $s): array
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $s); // تشكيل
        $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $s);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
        $parts = preg_split('/\s+/u', trim((string) $s));
        return array_values(array_filter($parts ?: [], static function ($t) {
            return $t !== '';
        }));
    }

    private function find(int $id): ?array
    {
        $rows = $this->db->query('SELECT * FROM automation_decisions WHERE id = ? LIMIT 1', [$id]);
        return $rows[0] ?? null;
    }

    public function findBySubject(string $feature, string $subjectType, string $subjectId): ?array
    {
        try {
            $rows = $this->db->query(
                'SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_updated, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_created
                   FROM automation_decisions WHERE feature_key = ? AND subject_type = ? AND subject_id = ? LIMIT 1',
                [$feature, $subjectType, $subjectId]
            );
            return $rows[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** إشعار واحد كل 30 دقيقة كحد أقصى لنفس الخدمة، عشان مانزعجش العميل */
    private function notifyPending(int $userId, string $feature): void
    {
        if (!class_exists('Notification')) {
            return;
        }
        try {
            $recent = $this->db->query(
                "SELECT COUNT(*) AS c FROM automation_decisions
                  WHERE user_id = ? AND feature_key = ? AND status = 'pending' AND created_at >= NOW() - INTERVAL 30 MINUTE",
                [$userId, $feature]
            );
            if ((int) ($recent[0]['c'] ?? 0) > 1) {
                return;
            }
            $def = AutomationCatalog::get($feature);
            $name = $def ? AutomationCatalog::label($def['name']) : $feature;
            Notification::notify($userId, 'automation_pending', "{$name}: فيه حاجة مستنية موافقتك", 'المنصة جهّزت إجراء جديد ومستنية رأيك قبل ما تنفّذه.', '/automation');
        } catch (Throwable $e) {
        }
    }

    private static function decodeMap($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    private static function cut(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') : $s;
    }
}

