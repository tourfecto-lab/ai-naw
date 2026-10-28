<?php

/**
 * Tourfecto - AI Ads Autopilot Engine
 * القلب المسؤول عن: قراءة أداء حقيقي من ad_performance_reports، اقتراح
 * تحسين (Budget Optimizer)، ثم تنفيذه حسب وضع العميل (manual/approval/
 * autopilot) مع Guardrails صارمة لا يمكن تجاوزها، وتسجيل كل قرار في
 * ad_optimization_logs (قبل/بعد) مع إمكانية Rollback.
 *
 * مبدأ أساسي (طلب المستخدم صراحة): مفيش أي بيانات مُختلقة. لو مفيش أداء
 * كافٍ مسحوب فعليًا من المنصة (عبر AdsController::syncMetaCampaigns وما
 * يماثله لـ Google)، الدالة بترجع 'insufficient_data' بدل ما تخترع رقم.
 * @version 1.0.0
 */
class AdAutopilotEngine
{
    /** أقل عدد أيام بيانات أداء متاحة قبل ما نسمح بأي توصية على الإطلاق */
    private const MIN_DATA_POINTS = 3;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // إعدادات Guardrails
    // ================================================================

    public function getSettings(int $userId): AdAutopilotSetting
    {
        return AdAutopilotSetting::forUser($userId);
    }

    /**
     * حفظ إعدادات Autopilot. كل الحدود اختيارية عدا max_changes_per_day
     * و نسب الزيادة/التخفيض (لها قيم افتراضية آمنة لو العميل سابهم فاضيين).
     */
    public function saveSettings(int $userId, array $data): AdAutopilotSetting
    {
        $existing = (new AdAutopilotSetting())->where(['user_id' => $userId], [], 1);
        $settings = !empty($existing) ? $existing[0] : new AdAutopilotSetting(['user_id' => $userId]);

        // دمج جزئي: أي حقل موجود في $data بيستبدل قيمته، وأي حقل مش موجود
        // بيفضل زي ما هو (مش بيترجع لقيمة افتراضية). ده مهم عشان تحديث
        // جزئي بسيط (زي تغيير الوضع بس من الشات أو زرار سريع) ما يمحيش
        // حدود تانية العميل ضبطها بعناية زي max_allowed_cpa - كان ده فعليًا
        // Bug حقيقي قبل التصحيح ده (اكتشفناه من اختبار حقيقي فشل بسببه).
        if (array_key_exists('optimization_mode', $data)) {
            $settings->setAttribute('optimization_mode', in_array($data['optimization_mode'], ['manual', 'approval', 'autopilot'], true) ? $data['optimization_mode'] : 'manual');
        } elseif (!$settings->getAttribute('optimization_mode')) {
            $settings->setAttribute('optimization_mode', 'manual');
        }

        if (array_key_exists('max_daily_budget', $data)) {
            $settings->setAttribute('max_daily_budget', $this->nullableFloat($data['max_daily_budget']));
        }
        if (array_key_exists('max_budget_increase_pct', $data)) {
            $settings->setAttribute('max_budget_increase_pct', (float) $data['max_budget_increase_pct']);
        } elseif ($settings->getAttribute('max_budget_increase_pct') === null) {
            $settings->setAttribute('max_budget_increase_pct', 20.0);
        }
        if (array_key_exists('max_budget_decrease_pct', $data)) {
            $settings->setAttribute('max_budget_decrease_pct', (float) $data['max_budget_decrease_pct']);
        } elseif ($settings->getAttribute('max_budget_decrease_pct') === null) {
            $settings->setAttribute('max_budget_decrease_pct', 50.0);
        }
        if (array_key_exists('max_allowed_cpa', $data)) {
            $settings->setAttribute('max_allowed_cpa', $this->nullableFloat($data['max_allowed_cpa']));
        }
        if (array_key_exists('min_required_roas', $data)) {
            $settings->setAttribute('min_required_roas', $this->nullableFloat($data['min_required_roas']));
        }
        if (array_key_exists('max_changes_per_day', $data)) {
            $settings->setAttribute('max_changes_per_day', max(0, (int) $data['max_changes_per_day']));
        } elseif ($settings->getAttribute('max_changes_per_day') === null) {
            $settings->setAttribute('max_changes_per_day', 3);
        }
        if (array_key_exists('allowed_campaign_ids', $data)) {
            $settings->setAttribute('allowed_campaign_ids_json', is_array($data['allowed_campaign_ids']) ? json_encode(array_map('intval', $data['allowed_campaign_ids'])) : null);
        }
        if (array_key_exists('allowed_platforms', $data)) {
            $settings->setAttribute('allowed_platforms_json', is_array($data['allowed_platforms']) ? json_encode(array_values($data['allowed_platforms'])) : null);
        }
        if (array_key_exists('allowed_countries', $data)) {
            $settings->setAttribute('allowed_countries_json', is_array($data['allowed_countries']) ? json_encode(array_values($data['allowed_countries'])) : null);
        }
        if ($settings->getAttribute('is_active') === null) {
            $settings->setAttribute('is_active', 1);
        }

        $settings->save();

        ActivityLog::record('ads_autopilot', 'settings.updated', [
            'user_id' => $userId, 'subject_type' => 'ad_autopilot_settings',
            'subject_id' => (int) $settings->getAttribute('id'),
            'meta' => ['mode' => $settings->getAttribute('optimization_mode')],
        ]);

        return $settings;
    }

    private function nullableFloat($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    // ================================================================
    // Budget Optimizer - توصية مبنية على بيانات حقيقية فقط
    // ================================================================

    /**
     * يحلل آخر 7 أيام بيانات مُزامنة فعليًا لحملة، ويرجّع توصية واحدة
     * (أو null لو الأداء طبيعي ومفيش داعي لأي تغيير).
     * @return array{action_type:string, after_daily_budget:?float, reasoning:string, confidence_level:string}|array{insufficient_data:true}|null
     */
    public function evaluateCampaign(AdCampaign $campaign, AdAutopilotSetting $settings): ?array
    {
        $campaignId = (int) $campaign->getAttribute('id');

        $reports = (new AdPerformanceReport())->where(
            ['campaign_id' => $campaignId],
            ['date_start' => 'DESC'],
            14
        );

        if (count($reports) < self::MIN_DATA_POINTS) {
            return ['insufficient_data' => true, 'reason' => 'not enough synced performance data (need at least ' . self::MIN_DATA_POINTS . ' reports)'];
        }

        $spend = 0.0;
        $conversions = 0.0;
        $revenue = 0.0;
        $hasRevenue = false;
        foreach ($reports as $r) {
            $spend += (float) ($r->getAttribute('spend') ?? 0);
            $conversions += (float) ($r->getAttribute('conversions') ?? 0);
            $rowRevenue = $r->getAttribute('revenue');
            if ($rowRevenue !== null) {
                $hasRevenue = true;
                $revenue += (float) $rowRevenue;
            }
        }

        if ($spend <= 0) {
            return null; // مفيش إنفاق فعلي بعد - مفيش أساس لأي قرار
        }

        $cpa = $conversions > 0 ? ($spend / $conversions) : null;
        $roas = ($hasRevenue && $spend > 0) ? ($revenue / $spend) : null;
        $currentBudget = (float) ($campaign->getAttribute('daily_budget') ?? 0);

        $maxCpa = $settings->getAttribute('max_allowed_cpa') !== null ? (float) $settings->getAttribute('max_allowed_cpa') : null;
        $minRoas = $settings->getAttribute('min_required_roas') !== null ? (float) $settings->getAttribute('min_required_roas') : null;

        // 1) إشارة مؤكدة: تجاوز صريح لسقف CPA اللي حدده العميل نفسه
        if ($maxCpa !== null && $cpa !== null && $cpa > $maxCpa) {
            return $this->buildBudgetRecommendation(
                'decrease_budget',
                $currentBudget,
                0.25,
                sprintf('تكلفة الاكتساب الفعلية %.2f تجاوزت الحد الأقصى المحدد %.2f خلال آخر %d يوم', $cpa, $maxCpa, count($reports)),
                'confirmed_signal'
            );
        }

        // 2) إشارة مؤكدة: ROAS فعلي أقل من الحد الأدنى اللي حدده العميل
        if ($minRoas !== null && $roas !== null && $roas < $minRoas) {
            return $this->buildBudgetRecommendation(
                'decrease_budget',
                $currentBudget,
                0.30,
                sprintf('العائد على الإنفاق الإعلاني الفعلي %.2fx أقل من الحد الأدنى المطلوب %.2fx', $roas, $minRoas),
                'confirmed_signal'
            );
        }

        // 3) سبب مرجّح: إنفاق حقيقي بدون أي تحويل خلال فترة كافية من البيانات
        if ($conversions == 0.0 && count($reports) >= self::MIN_DATA_POINTS) {
            return $this->buildBudgetRecommendation(
                'decrease_budget',
                $currentBudget,
                0.40,
                sprintf('صفر تحويلات رغم إنفاق %.2f خلال %d يوم متتالي - سبب مرجّح: تعب الإعلان/عدم ملاءمة الجمهور أو صفحة الهبوط. يُنصح بمراجعة الاستهداف والنصوص الإعلانية.', $spend, count($reports)),
                'likely_cause'
            );
        }

        // 4) سبب مرجّح: أداء أفضل من الحد المسموح بمسافة مريحة -> فرصة توسّع
        if ($maxCpa !== null && $cpa !== null && $cpa < ($maxCpa * 0.7)) {
            return $this->buildBudgetRecommendation(
                'increase_budget',
                $currentBudget,
                0.15,
                sprintf('تكلفة الاكتساب الفعلية %.2f أقل بوضوح من الحد الأقصى %.2f - إشارة مرجّحة إن الحملة تقدر تستوعب ميزانية أكبر', $cpa, $maxCpa),
                'likely_cause'
            );
        }

        return null; // no_action_recommended - الأداء ضمن النطاق الطبيعي
    }

    private function buildBudgetRecommendation(string $actionType, float $currentBudget, float $pct, string $reasoning, string $confidence): array
    {
        $delta = $currentBudget * $pct;
        $after = $actionType === 'increase_budget' ? ($currentBudget + $delta) : max(0.0, $currentBudget - $delta);

        return [
            'action_type' => $actionType,
            'current_daily_budget' => $currentBudget,
            'after_daily_budget' => round($after, 2),
            'change_pct' => round($pct * 100, 1),
            'reasoning' => $reasoning,
            'confidence_level' => $confidence,
        ];
    }

    // ================================================================
    // التوجيه حسب الوضع (Manual / Approval / Autopilot) + Guardrails
    // ================================================================

    /**
     * نقطة الدخول الرئيسية: تقيّم حملة وتوجّه القرار حسب وضع العميل.
     * @return array ['status'=>'no_action'|'logged_recommendation'|'pending_approval'|'executed'|'insufficient_data', ...]
     */
    /**
     * مثل processCampaign() لكن للتوصيات الصريحة (مثلًا طلب مباشر من
     * AdsCopilotService بناءً على أمر العميل نفسه) بدل توصية ناتجة من
     * تحليل أداء تلقائي. بتاخد نفس مسار الوضع/الـGuardrails بالظبط - مفيش
     * أي طريق مختصر يتجاوز الحدود المحفوظة حتى لو الطلب جاي من الشات.
     */
    public function applyExplicitRecommendation(int $userId, AdCampaign $campaign, array $rec): array
    {
        $settings = $this->getSettings($userId);
        $mode = $this->automationAwareMode($userId, (string) $settings->getAttribute('optimization_mode'), (string) ($rec['action_type'] ?? ''));

        if ($mode === 'manual') {
            return ['status' => 'recommendation_only', 'reasoning' => $rec['reasoning']];
        }

        if ($mode === 'approval') {
            $pending = $this->queueForApproval($userId, $campaign, $rec, null);
            return ['status' => 'pending_approval', 'pending_action_id' => $pending->getAttribute('id'), 'reasoning' => $rec['reasoning']];
        }

        $blockReason = $this->checkGuardrails($userId, $campaign, $settings, $rec);
        if ($blockReason !== null) {
            $pending = $this->queueForApproval($userId, $campaign, $rec, $blockReason);
            return ['status' => 'pending_approval', 'pending_action_id' => $pending->getAttribute('id'), 'blocked_reason' => $blockReason, 'reasoning' => $rec['reasoning']];
        }

        return $this->execute($userId, $campaign, $rec, 'autopilot');
    }

    public function processCampaign(int $userId, AdCampaign $campaign): array
    {
        $settings = $this->getSettings($userId);
        $recommendation = $this->evaluateCampaign($campaign, $settings);

        if ($recommendation === null) {
            return ['status' => 'no_action'];
        }
        if (!empty($recommendation['insufficient_data'])) {
            return ['status' => 'insufficient_data', 'reason' => $recommendation['reason']];
        }

        $mode = $this->automationAwareMode($userId, (string) $settings->getAttribute('optimization_mode'), (string) ($recommendation['action_type'] ?? ''));

        if ($mode === 'manual') {
            $log = $this->writeLog($userId, $campaign, $recommendation, 'manual', false, null, null);
            return ['status' => 'logged_recommendation', 'log_id' => $log->getAttribute('id')];
        }

        if ($mode === 'approval') {
            $pending = $this->queueForApproval($userId, $campaign, $recommendation, null);
            return ['status' => 'pending_approval', 'pending_action_id' => $pending->getAttribute('id')];
        }

        // autopilot
        $blockReason = $this->checkGuardrails($userId, $campaign, $settings, $recommendation);
        if ($blockReason !== null) {
            $pending = $this->queueForApproval($userId, $campaign, $recommendation, $blockReason);
            return ['status' => 'pending_approval', 'pending_action_id' => $pending->getAttribute('id'), 'blocked_reason' => $blockReason];
        }

        return $this->execute($userId, $campaign, $recommendation, 'autopilot');
    }

    /**
     * يتحقق من كل الحدود المحفوظة في ad_autopilot_settings. يرجّع null
     * لو القرار مسموح ينفّذ تلقائيًا، أو نص يوضّح سبب الرفض عشان يتحوّل
     * القرار لموافقة بدل ما يتنفذ أو يتجاهل بصمت.
     */
    private function checkGuardrails(int $userId, AdCampaign $campaign, AdAutopilotSetting $settings, array $rec): ?string
    {
        $campaignId = (int) $campaign->getAttribute('id');

        $allowedCampaigns = $settings->allowedCampaignIds();
        if ($allowedCampaigns !== null && !in_array($campaignId, $allowedCampaigns, true)) {
            return 'الحملة غير مدرجة ضمن allowed_campaigns في إعدادات Autopilot';
        }

        $platform = $this->campaignPlatform($campaign);
        $allowedPlatforms = $settings->allowedPlatforms();
        if ($allowedPlatforms !== null && $platform && !in_array($platform, $allowedPlatforms, true)) {
            return "منصة {$platform} غير مدرجة ضمن allowed_platforms في إعدادات Autopilot";
        }

        $allowedCountries = $settings->allowedCountries();
        if ($allowedCountries !== null) {
            $campaignCountries = $this->campaignTargetCountries($campaign);
            // لو الحملة أصلًا مالهاش دول استهداف مسجّلة، مانقدرش نتأكد إنها
            // ضمن الحدود المسموحة - نمنع بدل ما نفترض إنها مسموحة (Fail-safe،
            // مش Fail-open، مطابق لروح "لا تسمح للـAI بتجاوز الحدود" في البند 13).
            if (empty($campaignCountries)) {
                return 'لا يمكن التأكد من دول استهداف الحملة (target_countries_json فاضي) مقابل allowed_countries المحفوظة';
            }
            $notAllowed = array_diff($campaignCountries, $allowedCountries);
            if (!empty($notAllowed)) {
                return 'الحملة تستهدف دول خارج allowed_countries المسموحة في إعدادات Autopilot: ' . implode(', ', $notAllowed);
            }
        }

        if (in_array($rec['action_type'], ['increase_budget', 'decrease_budget'], true)) {
            $maxPct = $rec['action_type'] === 'increase_budget'
                ? (float) $settings->getAttribute('max_budget_increase_pct')
                : (float) $settings->getAttribute('max_budget_decrease_pct');

            if ((float) $rec['change_pct'] > $maxPct) {
                return sprintf('نسبة التغيير المقترحة %.1f%% تتجاوز الحد الأقصى المسموح %.1f%%', $rec['change_pct'], $maxPct);
            }

            $maxDaily = $settings->getAttribute('max_daily_budget');
            if ($maxDaily !== null && (float) $rec['after_daily_budget'] > (float) $maxDaily) {
                return sprintf('الميزانية المقترحة بعد التعديل (%.2f) تتجاوز max_daily_budget (%.2f)', $rec['after_daily_budget'], (float) $maxDaily);
            }
        }

        if ($this->changesUsedToday($userId) >= (int) $settings->getAttribute('max_changes_per_day')) {
            return 'تم الوصول للحد الأقصى المسموح به من التغييرات التلقائية اليوم (max_changes_per_day)';
        }

        return null;
    }

    /**
     * قائمة أكواد/أسماء دول الاستهداف الفعلية لحملة، من عمود الحملة
     * المباشر أولًا (target_countries_json)، ولو فاضي بيرجع لأول Audience
     * مرتبطة بالحملة (ad_audiences.locations_json) - عشان الحملات القديمة
     * اللي اتعملت قبل إضافة العمود ده لسه تقدر تتفحص صح بدل ما تتمنع
     * بالغلط كأن مفيش بيانات خالص.
     */
    private function campaignTargetCountries(AdCampaign $campaign): array
    {
        $raw = $campaign->getAttribute('target_countries_json');
        $list = $raw ? json_decode((string) $raw, true) : null;
        if (is_array($list) && !empty($list)) {
            return array_values($list);
        }

        $audiences = (new AdAudience())->where(['campaign_id' => (int) $campaign->getAttribute('id')], [], 1);
        if (!empty($audiences) && $audiences[0]->getAttribute('locations_json')) {
            $locations = json_decode((string) $audiences[0]->getAttribute('locations_json'), true);
            if (is_array($locations)) {
                return array_values($locations);
            }
        }

        return [];
    }

    private function campaignPlatform(AdCampaign $campaign): ?string
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        if (!$connId) {
            return null;
        }
        $conn = (new PlatformConnection())->find((int) $connId);
        return $conn ? (string) $conn->getAttribute('platform') : null;
    }

    // ================================================================
    // تنفيذ فعلي عبر API المنصة الرسمي
    // ================================================================

    private function execute(int $userId, AdCampaign $campaign, array $rec, string $mode): array
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        if (!$connId) {
            $log = $this->writeLog($userId, $campaign, $rec, $mode, false, null, 'failed: no platform_connection_id');
            return ['status' => 'execution_failed', 'log_id' => $log->getAttribute('id')];
        }

        $conn = (new PlatformConnection())->find((int) $connId);
        if (!$conn || $conn->getAttribute('status') !== 'connected') {
            $log = $this->writeLog($userId, $campaign, $rec, $mode, false, null, 'failed: platform not connected');
            return ['status' => 'execution_failed', 'log_id' => $log->getAttribute('id')];
        }

        $externalCampaignId = (string) $campaign->getAttribute('external_campaign_id');
        if ($externalCampaignId === '') {
            $log = $this->writeLog($userId, $campaign, $rec, $mode, false, null, 'failed: campaign not synced with platform yet');
            return ['status' => 'execution_failed', 'log_id' => $log->getAttribute('id')];
        }

        $encryption = new Encryption();
        $accessToken = $encryption->decrypt((string) $conn->getAttribute('access_token'));
        $platform = (string) $conn->getAttribute('platform');
        $before = (float) $campaign->getAttribute('daily_budget');
        $after = (float) $rec['after_daily_budget'];

        $apiResult = $this->callPlatformWrite($platform, $accessToken, $conn, $campaign, $externalCampaignId, $rec, $after);

        if (!$apiResult['success']) {
            $log = $this->writeLog($userId, $campaign, $rec, $mode, false, (string) $before, 'failed: ' . ($apiResult['error'] ?? 'unknown'));
            return ['status' => 'execution_failed', 'error' => $apiResult['error'] ?? null, 'log_id' => $log->getAttribute('id')];
        }

        // نجاح فعلي -> نحدّث الحملة محليًا + نسجّل Audit كامل قابل للتراجع
        $campaign->setAttribute('daily_budget', $after);
        $campaign->save();

        $log = $this->writeLog($userId, $campaign, $rec, $mode, true, (string) $before, null, (string) $after);
        $this->incrementDailyCounter($userId);

        ActivityLog::record('ads_autopilot', 'action.executed', [
            'user_id' => $userId, 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['action_type' => $rec['action_type'], 'before' => $before, 'after' => $after, 'reasoning' => $rec['reasoning']],
        ]);

        if (class_exists('Notification')) {
            Notification::notify(
                $userId,
                'ads_autopilot_action',
                'Autopilot نفّذ إجراء على حملة "' . $campaign->getAttribute('name') . '"',
                $rec['reasoning'] . " ({$before} ← {$after})",
                '/ads/campaigns/' . $campaign->getAttribute('id')
            );
        }

        return ['status' => 'executed', 'log_id' => $log->getAttribute('id'), 'before' => $before, 'after' => $after];
    }

    private function callPlatformWrite(string $platform, string $accessToken, PlatformConnection $conn, AdCampaign $campaign, string $externalCampaignId, array $rec, float $afterBudget): array
    {
        if ($platform === 'meta_ads') {
            $api = new MetaAdsAPI($accessToken);
            if ($rec['action_type'] === 'decrease_budget' && $afterBudget <= 0) {
                return $api->pauseCampaign($externalCampaignId);
            }

            $adSetId = (string) $campaign->getAttribute('external_adset_id');
            if ($adSetId === '') {
                return ['success' => false, 'error' => 'Meta Ad Set ID غير متاح - شغّل مزامنة Meta الأول (syncMetaCampaigns) عشان يتحفظ'];
            }
            return $api->updateCampaignBudget($adSetId, $afterBudget);
        }

        if ($platform === 'google_ads') {
            $api = new GoogleAdsAPI($accessToken);
            $customerId = (string) $conn->getAttribute('external_account_id');
            $budgetResourceName = (string) $campaign->getAttribute('external_budget_resource_name');

            if ($budgetResourceName === '') {
                return ['success' => false, 'error' => 'campaign_budget resource name غير متاح - شغّل مزامنة Google Ads الأول (syncGoogleAdsCampaigns) عشان يتحفظ'];
            }
            if ($rec['action_type'] === 'decrease_budget' && $afterBudget <= 0) {
                $campaignResourceName = "customers/{$customerId}/campaigns/{$externalCampaignId}";
                return $api->pauseCampaign($customerId, $campaignResourceName);
            }

            return $api->updateCampaignBudget($customerId, $budgetResourceName, $afterBudget);
        }

        return ['success' => false, 'error' => "منصة غير مدعومة للتنفيذ التلقائي: {$platform}"];
    }

    private function writeLog(int $userId, AdCampaign $campaign, array $rec, string $mode, bool $applied, ?string $before, ?string $externalResult, ?string $after = null): AdOptimizationLog
    {
        $log = new AdOptimizationLog([
            'campaign_id' => (int) $campaign->getAttribute('id'),
            'user_id' => $userId,
            'action_type' => $rec['action_type'],
            'mode' => $mode,
            'description' => $rec['reasoning'],
            'before_value' => $before,
            'after_value' => $after,
            'ai_confidence' => $this->confidenceToScore($rec['confidence_level'] ?? 'possible_cause'),
            'applied_automatically' => $applied ? 1 : 0,
            'external_result' => $applied ? 'success' : $externalResult,
            'can_rollback' => ($applied && $before !== null) ? 1 : 0,
        ]);
        $log->save();
        return $log;
    }

    private function confidenceToScore(string $level): float
    {
        return ['confirmed_signal' => 90.0, 'likely_cause' => 65.0, 'possible_cause' => 40.0][$level] ?? 40.0;
    }

    // ================================================================
    // طابور الموافقات (Approval Mode)
    // ================================================================

    private function queueForApproval(int $userId, AdCampaign $campaign, array $rec, ?string $blockedReason): AdPendingAction
    {
        $action = new AdPendingAction([
            'user_id' => $userId,
            'campaign_id' => (int) $campaign->getAttribute('id'),
            'action_type' => $rec['action_type'],
            'before_value' => (string) $rec['current_daily_budget'],
            'after_value' => (string) $rec['after_daily_budget'],
            'reasoning' => $rec['reasoning'],
            'confidence_level' => $rec['confidence_level'],
            'blocked_reason' => $blockedReason,
            'status' => 'pending',
        ]);
        $action->save();

        // مركز الأتمتة: كل إجراء مستني موافقة بيظهر في صندوق الموافقات الموحّد
        if (class_exists('AutomationEngine')) {
            AutomationEngine::instance()->gate(
                $userId,
                null,
                'ads_optimizations',
                AutomationCatalog::adBucket((string) $rec['action_type']),
                'ad_pending_action',
                (string) $action->getAttribute('id'),
                [
                    'title' => (string) $campaign->getAttribute('name') . ' · ' . $rec['action_type'],
                    'context' => (string) $rec['reasoning'] . ($blockedReason ? "\nتجاوز الحدود: " . $blockedReason : ''),
                    'draft' => $rec['action_type'] . ': ' . $rec['current_daily_budget'] . ' → ' . $rec['after_daily_budget'],
                    'force_pending' => true,
                ]
            );
        }

        ActivityLog::record('ads_autopilot', 'action.queued_for_approval', [
            'user_id' => $userId, 'subject_type' => 'ad_pending_actions', 'subject_id' => (int) $action->getAttribute('id'),
            'meta' => ['action_type' => $rec['action_type'], 'blocked_reason' => $blockedReason],
        ]);

        if (class_exists('Notification')) {
            $title = $blockedReason
                ? 'قرار Autopilot تجاوز الحدود المسموحة وبانتظار موافقتك'
                : 'توصية جديدة بانتظار موافقتك على حملة "' . $campaign->getAttribute('name') . '"';
            Notification::notify($userId, 'ads_pending_approval', $title, $rec['reasoning'], '/ads/autopilot');
        }

        return $action;
    }

    /**
     * لو العميل اختار وضع للإعلانات في مركز الأتمتة، هو اللي بيحكم (وكل نوع
     * إجراء بيتعلم لوحده). لو ماختارش، بنفضل على إعداد صفحة الإعلانات القديم.
     */
    private function automationAwareMode(int $userId, string $legacyMode, string $actionType): string
    {
        if (!class_exists('AutomationEngine')) {
            return $legacyMode;
        }
        $engine = AutomationEngine::instance();
        $policy = $engine->getPolicy($userId, 'ads_optimizations');
        if (!empty($policy['is_default'])) {
            return $legacyMode;
        }
        $m = $engine->effectiveMode($userId, 'ads_optimizations', AutomationCatalog::adBucket($actionType));
        return $m === 'auto' ? 'autopilot' : ($m === 'manual' ? 'manual' : 'approval');
    }

    /** العميل بيوافق على قرار معلّق -> يتنفذ فعليًا الآن عبر نفس مسار autopilot execute() */
    public function approvePendingAction(int $userId, int $pendingActionId, bool $fromAutomationCenter = false): array
    {
        $pending = (new AdPendingAction())->find($pendingActionId);
        if (!$pending || (int) $pending->getAttribute('user_id') !== $userId || $pending->getAttribute('status') !== 'pending') {
            return ['status' => 'not_found'];
        }

        $campaign = (new AdCampaign())->find((int) $pending->getAttribute('campaign_id'));
        if (!$campaign) {
            return ['status' => 'not_found'];
        }

        $rec = [
            'action_type' => $pending->getAttribute('action_type'),
            'current_daily_budget' => (float) $pending->getAttribute('before_value'),
            'after_daily_budget' => (float) $pending->getAttribute('after_value'),
            'reasoning' => $pending->getAttribute('reasoning'),
            'confidence_level' => $pending->getAttribute('confidence_level'),
        ];

        $result = $this->execute($userId, $campaign, $rec, 'approval');

        // موافقة من صفحة الإعلانات نفسها بتعلّم مركز الأتمتة برضه
        if (!$fromAutomationCenter && class_exists('AutomationEngine')) {
            $engine = AutomationEngine::instance();
            $d = $engine->findBySubject('ads_optimizations', 'ad_pending_action', (string) $pendingActionId);
            if ($d && $d['status'] === 'pending') {
                $engine->recordManualAction($userId, 'ads_optimizations', $d['bucket'], 'ad_pending_action', (string) $pendingActionId, (string) $d['draft_text'], null, (string) $d['draft_text']);
            }
        }

        $pending->setAttribute('status', $result['status'] === 'executed' ? 'approved' : 'approved');
        $pending->setAttribute('decided_by_user_id', $userId);
        $pending->setAttribute('decided_at', date('Y-m-d H:i:s'));
        $pending->setAttribute('executed_log_id', $result['log_id'] ?? null);
        $pending->save();

        return $result;
    }

    public function rejectPendingAction(int $userId, int $pendingActionId): bool
    {
        $pending = (new AdPendingAction())->find($pendingActionId);
        if (!$pending || (int) $pending->getAttribute('user_id') !== $userId || $pending->getAttribute('status') !== 'pending') {
            return false;
        }

        $pending->setAttribute('status', 'rejected');
        $pending->setAttribute('decided_by_user_id', $userId);
        $pending->setAttribute('decided_at', date('Y-m-d H:i:s'));
        $pending->save();

        if (class_exists('AutomationEngine')) {
            AutomationEngine::instance()->recordManualRejection($userId, 'ads_optimizations', 'ad_pending_action', (string) $pendingActionId);
        }

        ActivityLog::record('ads_autopilot', 'action.rejected', [
            'user_id' => $userId, 'subject_type' => 'ad_pending_actions', 'subject_id' => $pendingActionId,
        ]);

        return true;
    }

    // ================================================================
    // Rollback
    // ================================================================

    /**
     * يتراجع عن تغيير سبق تنفيذه فعليًا (can_rollback=1 فقط) عن طريق
     * إعادة تنفيذ القيمة السابقة (before_value) عبر نفس API المنصة،
     * وتسجيل صف Audit جديد يشير لصف الأصل عبر rollback_of_log_id.
     */
    public function rollback(int $userId, int $logId): array
    {
        $log = (new AdOptimizationLog())->find($logId);
        if (!$log || (int) $log->getAttribute('user_id') !== $userId) {
            return ['status' => 'not_found'];
        }
        if (!$log->getAttribute('can_rollback') || $log->getAttribute('rolled_back_at')) {
            return ['status' => 'not_rollbackable'];
        }

        $campaign = (new AdCampaign())->find((int) $log->getAttribute('campaign_id'));
        if (!$campaign) {
            return ['status' => 'not_found'];
        }

        $rec = [
            'action_type' => 'decrease_budget', // يُتجاهل - القيمة الفعلية جاية من before_value مباشرة
            'current_daily_budget' => (float) $campaign->getAttribute('daily_budget'),
            'after_daily_budget' => (float) $log->getAttribute('before_value'),
            'reasoning' => 'Rollback لتغيير سابق رقم #' . $logId,
            'confidence_level' => 'confirmed_signal',
        ];

        $result = $this->execute($userId, $campaign, $rec, (string) $log->getAttribute('mode'));

        if ($result['status'] === 'executed') {
            $log->setAttribute('rolled_back_at', date('Y-m-d H:i:s'));
            $log->save();

            $newLog = (new AdOptimizationLog())->find((int) $result['log_id']);
            if ($newLog) {
                $newLog->setAttribute('rollback_of_log_id', $logId);
                $newLog->setAttribute('action_type', 'rollback');
                $newLog->save();
            }
        }

        return $result;
    }

    // ================================================================
    // عداد التغييرات اليومي (max_changes_per_day)
    // ================================================================

    private function changesUsedToday(int $userId): int
    {
        $rows = $this->db->query(
            "SELECT changes_executed FROM ad_autopilot_daily_counters WHERE user_id = ? AND counter_date = CURDATE() LIMIT 1",
            [$userId]
        );
        return !empty($rows) ? (int) $rows[0]['changes_executed'] : 0;
    }

    private function incrementDailyCounter(int $userId): void
    {
        $this->db->exec(
            "INSERT INTO ad_autopilot_daily_counters (user_id, counter_date, changes_executed)
             VALUES (?, CURDATE(), 1)
             ON DUPLICATE KEY UPDATE changes_executed = changes_executed + 1",
            [$userId]
        );
    }

    // ================================================================
    // نقطة تشغيل دورية (تُستدعى من cron/run_ads_autopilot.php)
    // ================================================================

    /**
     * يعالج كل حملات كل العملاء المفعّلين auto_optimize=1 والحملة status=active.
     * @return array ملخّص للـ cron log
     */
    public function runForAllUsers(): array
    {
        $summary = ['processed' => 0, 'no_action' => 0, 'logged' => 0, 'pending' => 0, 'executed' => 0, 'insufficient_data' => 0, 'errors' => 0];

        $campaigns = (new AdCampaign())->where(['status' => 'active', 'auto_optimize' => 1]);

        foreach ($campaigns as $campaign) {
            $userId = (int) $campaign->getAttribute('user_id');

            try {
                $result = $this->processCampaign($userId, $campaign);
                $summary['processed']++;
                $key = $result['status'] === 'logged_recommendation' ? 'logged'
                    : ($result['status'] === 'pending_approval' ? 'pending'
                    : ($result['status'] === 'executed' ? 'executed'
                    : ($result['status'] === 'insufficient_data' ? 'insufficient_data' : 'no_action')));
                $summary[$key] = ($summary[$key] ?? 0) + 1;
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('AdAutopilotEngine::runForAllUsers campaign failed', [
                        'campaign_id' => $campaign->getAttribute('id'), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }
}
