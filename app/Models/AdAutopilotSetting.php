<?php

/**
 * Tourfecto - Ad Autopilot Settings Model
 * إعدادات حماية الـAutopilot لكل مستخدم - كل الحدود هنا بتتحول لـGuardrails
 * صارمة في AdAutopilotEngine::checkGuardrails() (راجع migration
 * 2026_08_15_000050 لتفاصيل الأعمدة).
 * @version 1.0.0
 */
class AdAutopilotSetting extends Model
{
    protected $table = 'ad_autopilot_settings';
    protected $fillable = [
        'user_id', 'optimization_mode', 'is_active',
        'max_daily_budget', 'max_budget_increase_pct', 'max_budget_decrease_pct',
        'max_allowed_cpa', 'min_required_roas', 'max_changes_per_day',
        'allowed_campaign_ids_json', 'allowed_platforms_json', 'allowed_countries_json',
    ];

    /**
     * يرجع إعدادات Autopilot لمستخدم (أول صف مسجّل)، أو نسخة جديدة غير
     * محفوظة بقيم افتراضية آمنة لو لسه مفيش إعدادات. مش بيحفظ تلقائيًا -
     * الحفظ بيحصل من AdAutopilotEngine::saveSettings() لما العميل يضبط.
     */
    public static function forUser(int $userId): self
    {
        $existing = (new self())->where(['user_id' => $userId], [], 1);
        if (!empty($existing)) {
            return $existing[0];
        }

        $defaults = new self(['user_id' => $userId]);
        $defaults->setAttribute('optimization_mode', 'manual');
        $defaults->setAttribute('is_active', 1);
        $defaults->setAttribute('max_budget_increase_pct', 20.0);
        $defaults->setAttribute('max_budget_decrease_pct', 50.0);
        $defaults->setAttribute('max_changes_per_day', 3);
        return $defaults;
    }

    /** @return int[]|null قائمة الحملات المسموحة، أو null لو مفيش قيد (كل الحملات مسموحة) */
    public function allowedCampaignIds(): ?array
    {
        $raw = $this->getAttribute('allowed_campaign_ids_json');
        if (!$raw) {
            return null;
        }
        $ids = json_decode((string) $raw, true);
        return is_array($ids) ? array_map('intval', $ids) : null;
    }

    /** @return string[]|null قائمة المنصات المسموحة، أو null لو مفيش قيد */
    public function allowedPlatforms(): ?array
    {
        $raw = $this->getAttribute('allowed_platforms_json');
        if (!$raw) {
            return null;
        }
        $platforms = json_decode((string) $raw, true);
        return is_array($platforms) ? array_values(array_map('strval', $platforms)) : null;
    }

    /** @return string[]|null قائمة الدول المسموحة، أو null لو مفيش قيد */
    public function allowedCountries(): ?array
    {
        $raw = $this->getAttribute('allowed_countries_json');
        if (!$raw) {
            return null;
        }
        $countries = json_decode((string) $raw, true);
        return is_array($countries) ? array_values(array_map('strval', $countries)) : null;
    }
}
