<?php

/**
 * Tourfecto - Ad Creative Service (بند 1: إدارة الأصول الإعلانية)
 * إدارة أصول الإعلانات (نص/صورة/فيديو) وتنويعاتها (A/B/C) مع أداء حقيقي.
 *
 * مبدأ أساسي: عزل تينانت صارم عبر `user_id` (المالك) - كل استعلام وكل
 * وصول لأي سجل يمر بفحص ملكية؛ لا يُعرَض أبدًا سجل مستخدم لمستخدم آخر.
 * مبدأ "لا اختراع بيانات": أداء الـ Variant (ظهور/نقرات/إنفاق/تحويلات/
 * إيرادات) لا يُقدَّر ولا يُولَّد - يُحدَّث فقط بأرقام فعلية عبر
 * recordPerformance() من بيانات المزامنة/الإدخال. CTR/CPC يُحسبان من
 * البيانات الخام عند القراءة.
 *
 * @version 1.0.0
 */
class AdCreativeService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // القراءة
    // ================================================================

    /** كل الأصول غير المؤرشفة (active/paused) لحملة يملكها $ownerUserId */
    public function listForCampaign(int $ownerUserId, int $campaignId): array
    {
        if (!$this->campaignOwnedBy($campaignId, $ownerUserId)) {
            return [];
        }
        $creatives = array_values(array_filter(
            (new AdCreative())->where(['user_id' => $ownerUserId, 'campaign_id' => $campaignId], ['id' => 'DESC']),
            fn ($c) => $c->getAttribute('status') !== 'archived'
        ));
        return array_map(fn ($c) => $this->decorate($c->toArray()), $creatives);
    }

    /** أصل واحد بعد التحقق من ملكيته (null لو مش موجود/غير مملوك/مؤرشف) */
    public function get(int $ownerUserId, int $creativeId): ?array
    {
        $creative = $this->findOwnedActive($ownerUserId, $creativeId);
        if (!$creative) {
            return null;
        }
        $data = $this->decorate($creative->toArray());
        $data['variants'] = $this->variantsFor($ownerUserId, $creativeId);
        return $data;
    }

    /** كل تنويعات أصل غير مؤرشف مملوك + CTR/CPC محسوبة */
    public function variantsFor(int $ownerUserId, int $creativeId): array
    {
        if (!$this->creativeOwnedBy($ownerUserId, $creativeId)) {
            return [];
        }
        $variants = (new AdCreativeVariant())
            ->where(['user_id' => $ownerUserId, 'creative_id' => $creativeId], ['id' => 'ASC']);
        return array_map(fn ($v) => $this->decorateVariant($v->toArray()), $variants);
    }

    // ================================================================
    // الكتابة
    // ================================================================

    /**
     * إنشاء أصل إعلاني. بيانات المرور:
     * name, creative_type(text|image|video), headline, primary_text,
     * media_url, status.
     * @throws InvalidArgumentException لو البيانات الأساسية ناقصة/غير صالحة
     */
    public function create(int $ownerUserId, int $campaignId, array $data): ?array
    {
        if (!$this->campaignOwnedBy($campaignId, $ownerUserId)) {
            return null;
        }
        $creativeType = $data['creative_type'] ?? 'text';
        if (!in_array($creativeType, ['text', 'image', 'video'], true)) {
            throw new InvalidArgumentException('نوع الأصول الإعلانية غير صالح');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('اسم الأصول الإعلانية مطلوب');
        }

        $status = $data['status'] ?? 'active';
        if (!in_array($status, ['active', 'paused'], true)) {
            $status = 'active';
        }

        $creative = new AdCreative();
        $creative->fill([
            'user_id' => $ownerUserId,
            'campaign_id' => $campaignId,
            'name' => $name,
            'creative_type' => $creativeType,
            'headline' => $data['headline'] ?? null,
            'primary_text' => $data['primary_text'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'status' => $status,
        ]);
        $creative->save();

        ActivityLog::record('ads', 'creative.created', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_creatives',
            'subject_id' => (int) $creative->getAttribute('id'),
            'meta' => ['campaign_id' => $campaignId, 'creative_type' => $creativeType],
        ]);

        return $this->get($ownerUserId, (int) $creative->getAttribute('id'));
    }

    /** تحديث أصل غير مؤرشف مملوك (الحقول المسموح تحديثها فقط) */
    public function update(int $ownerUserId, int $creativeId, array $data): ?array
    {
        $creative = $this->findOwnedActive($ownerUserId, $creativeId);
        if (!$creative) {
            return null;
        }
        $updatable = ['name', 'headline', 'primary_text', 'media_url'];
        $payload = array_intersect_key($data, array_flip($updatable));
        if (isset($data['creative_type']) && in_array($data['creative_type'], ['text', 'image', 'video'], true)) {
            $payload['creative_type'] = $data['creative_type'];
        }
        if (isset($data['status']) && in_array($data['status'], ['active', 'paused'], true)) {
            $payload['status'] = $data['status'];
        }
        if (!empty($payload)) {
            $creative->fill($payload);
            $creative->save();
        }
        return $this->get($ownerUserId, $creativeId);
    }

    /** تغيير حالة الأصل (active/paused) */
    public function setStatus(int $ownerUserId, int $creativeId, string $status): ?array
    {
        if (!in_array($status, ['active', 'paused'], true)) {
            return null;
        }
        return $this->update($ownerUserId, $creativeId, ['status' => $status]);
    }

    /**
     * أرشفة أصل (حذف منطقي - يحفظ السجل ولا يُحذف من قاعدة البيانات).
     * هذه الأرشفة تحافظ على أي Variants/أداء مرتبط دون مسحه.
     */
    public function archive(int $ownerUserId, int $creativeId): bool
    {
        $creative = $this->findOwned($ownerUserId, $creativeId);
        if (!$creative || $creative->getAttribute('status') === 'archived') {
            return false;
        }
        $creative->setAttribute('status', 'archived');
        $creative->save();
        ActivityLog::record('ads', 'creative.archived', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_creatives', 'subject_id' => $creativeId,
        ]);
        return true;
    }

    // ================================================================
    // التنويعات
    // ================================================================

    /** إضافة Variant (A/B/C...) لأصل غير مؤرشف مملوك - تسمية تلقائية لو لم تُمرَّر */
    public function addVariant(int $ownerUserId, int $creativeId, array $data): ?array
    {
        if (!$this->creativeOwnedBy($ownerUserId, $creativeId)) {
            return null;
        }
        $label = trim((string) ($data['variant_label'] ?? ''));
        if ($label === '') {
            $label = $this->nextVariantLabel($ownerUserId, $creativeId);
        }

        $variant = new AdCreativeVariant();
        $variant->fill([
            'user_id' => $ownerUserId,
            'creative_id' => $creativeId,
            'variant_label' => mb_substr($label, 0, 20),
            'headline' => $data['headline'] ?? null,
            'primary_text' => $data['primary_text'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'is_control' => (int) (($data['is_control'] ?? false) ? 1 : 0),
        ]);
        $variant->save();

        ActivityLog::record('ads', 'creative.variant_added', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_creative_variants',
            'subject_id' => (int) $variant->getAttribute('id'),
            'meta' => ['creative_id' => $creativeId, 'variant_label' => $variant->getAttribute('variant_label')],
        ]);

        return $this->decorateVariant($variant->toArray());
    }

    /** تحديث Variant مملوك (نص/رابط/تسمية/control) */
    public function updateVariant(int $ownerUserId, int $variantId, array $data): ?array
    {
        $variant = $this->findOwnedVariant($ownerUserId, $variantId);
        if (!$variant || $this->variantCreativeArchived($variant)) {
            return null;
        }
        $updatable = ['headline', 'primary_text', 'media_url'];
        $payload = array_intersect_key($data, array_flip($updatable));
        if (isset($data['variant_label']) && trim((string) $data['variant_label']) !== '') {
            $payload['variant_label'] = mb_substr(trim((string) $data['variant_label']), 0, 20);
        }
        if (isset($data['is_control'])) {
            $payload['is_control'] = (int) (($data['is_control'] ? true : false) ? 1 : 0);
        }
        if (!empty($payload)) {
            $variant->fill($payload);
            $variant->save();
        }
        return $this->decorateVariant($variant->toArray());
    }

    /**
     * تحديث أداء Variant بأرقام فعلية فقط (من بيانات المزامنة/الإدخال).
     * لا يوجد أي حساب تقديري هنا: القيم تمر كما هي، وأي قيمة غير رقمية
     * تُرفض. ميزة النسخ عمدًا بسيطة: تسجل آخر قيم مُزامَنة للـ Variant.
     */
    public function recordPerformance(int $ownerUserId, int $variantId, array $metrics): ?array
    {
        $variant = $this->findOwnedVariant($ownerUserId, $variantId);
        if (!$variant || $this->variantCreativeArchived($variant)) {
            return null;
        }
        $numeric = ['impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0];
        foreach ($numeric as $key => $default) {
            if (isset($metrics[$key])) {
                if (!is_numeric($metrics[$key])) {
                    throw new InvalidArgumentException("قيمة {$key} يجب أن تكون رقمية");
                }
                $numeric[$key] = (float) $metrics[$key];
            }
        }
        $variant->fill($numeric);

        // بند 3: تاريخ الأداء (recorded_on) اختياري - لو اتحط، لازم يكون
        // تاريخ صالح YYYY-MM-DD؛ وإلا بياخد تاريخ اليوم. بيدي تقارير
        // الفترة (weekly/monthly) نافذة زمنية حقيقية لبيانات التنويعات.
        if (isset($metrics['recorded_on']) && $metrics['recorded_on'] !== '') {
            $recordedOn = (string) $metrics['recorded_on'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recordedOn)) {
                throw new InvalidArgumentException('recorded_on يجب أن يكون تاريخ YYYY-MM-DD صالح');
            }
            $variant->setAttribute('recorded_on', $recordedOn);
        } elseif ($variant->getAttribute('recorded_on') === null) {
            $variant->setAttribute('recorded_on', date('Y-m-d'));
        }
        $variant->save();

        ActivityLog::record('ads', 'creative.performance_updated', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_creative_variants', 'subject_id' => $variantId,
        ]);

        return $this->decorateVariant($variant->toArray());
    }

    /**
     * أفضل Variant أداءً لأصل معيّن - CTR مع كفاية حد أدنى من الانطباعات
     * (نفس منهجية إعادة الترتيب التنافسية، لكن على أرقام فعلية فقط).
     * @return array|null
     */
    public function bestVariant(int $ownerUserId, int $creativeId, int $minImpressions = 50): ?array
    {
        if (!$this->creativeOwnedBy($ownerUserId, $creativeId)) {
            return null;
        }
        $variants = $this->variantsFor($ownerUserId, $creativeId);
        $eligible = array_values(array_filter($variants, function ($v) use ($minImpressions) {
            return (int) $v['impressions'] >= $minImpressions;
        }));
        if (empty($eligible)) {
            return null;
        }
        usort($eligible, function ($a, $b) {
            $aScore = $a['ctr'] ?? 0;
            $bScore = $b['ctr'] ?? 0;
            return $bScore <=> $aScore;
        });
        return $eligible[0];
    }

    // ================================================================
    // مساعدون (عزل التينانت)
    // ================================================================

    private function findOwned(int $ownerUserId, int $creativeId): ?AdCreative
    {
        $creative = (new AdCreative())->find($creativeId);
        if (!$creative || (int) $creative->getAttribute('user_id') !== $ownerUserId) {
            return null;
        }
        return $creative;
    }

    /** نسخة findOwned التي تستثني الأصول المؤرشفة (لا تُفتح ولا تُعدَّل) */
    private function findOwnedActive(int $ownerUserId, int $creativeId): ?AdCreative
    {
        $creative = $this->findOwned($ownerUserId, $creativeId);
        if (!$creative || $creative->getAttribute('status') === 'archived') {
            return null;
        }
        return $creative;
    }

    private function findOwnedVariant(int $ownerUserId, int $variantId): ?AdCreativeVariant
    {
        $variant = (new AdCreativeVariant())->find($variantId);
        if (!$variant || (int) $variant->getAttribute('user_id') !== $ownerUserId) {
            return null;
        }
        return $variant;
    }

    /** هل الأصل الأب لهذا الـ Variant مؤرشف؟ (يمنع التعديل على تنويعات أصل مؤرشف) */
    private function variantCreativeArchived(AdCreativeVariant $variant): bool
    {
        $creative = (new AdCreative())->find((int) $variant->getAttribute('creative_id'));
        return $creative !== null && $creative->getAttribute('status') === 'archived';
    }

    private function campaignOwnedBy(int $campaignId, int $ownerUserId): bool
    {
        $campaign = (new AdCampaign())->find($campaignId);
        return $campaign !== null && (int) $campaign->getAttribute('user_id') === $ownerUserId;
    }

    private function creativeOwnedBy(int $ownerUserId, int $creativeId): bool
    {
        return $this->findOwnedActive($ownerUserId, $creativeId) !== null;
    }

    /** التسمية الحرفية التالية (A ثم B ثم C...) بناءً على الموجود فعليًا */
    private function nextVariantLabel(int $ownerUserId, int $creativeId): string
    {
        $existing = (new AdCreativeVariant())->where(['user_id' => $ownerUserId, 'creative_id' => $creativeId]);
        $labels = array_map(fn ($v) => strtoupper((string) $v->getAttribute('variant_label')), $existing);
        for ($i = 0; $i < 26; $i++) {
            $candidate = chr(65 + $i);
            if (!in_array($candidate, $labels, true)) {
                return $candidate;
            }
        }
        return 'V' . (count($existing) + 1);
    }

    /** تزيين بيانات الأصل بمعلومات إضافية مفيدة للـ UI */
    private function decorate(array $creative): array
    {
        $creative['variants_count'] = (int) $this->scalar(
            "SELECT COUNT(*) FROM ad_creative_variants WHERE user_id = ? AND creative_id = ?",
            [(int) $creative['user_id'], (int) $creative['id']]
        );
        return $creative;
    }

    /** تزيين بيانات الـ Variant بحساب CTR/CPC من البيانات الخام (أو null لو غير ممكن) */
    private function decorateVariant(array $variant): array
    {
        $impressions = (int) ($variant['impressions'] ?? 0);
        $clicks = (int) ($variant['clicks'] ?? 0);
        $spend = (float) ($variant['spend'] ?? 0);
        $conversions = (float) ($variant['conversions'] ?? 0);
        $revenue = (float) ($variant['revenue'] ?? 0);

        $variant['ctr'] = $impressions > 0 ? round(($clicks / $impressions) * 100, 3) : null;
        $variant['cpc'] = $clicks > 0 ? round($spend / $clicks, 2) : null;
        $variant['cpa'] = $conversions > 0 ? round($spend / $conversions, 2) : null;
        $variant['roas'] = $spend > 0 ? round($revenue / $spend, 2) : null;

        return $variant;
    }

    private function scalar(string $sql, array $params)
    {
        $rows = $this->db->query($sql, $params);
        if (empty($rows)) {
            return 0;
        }
        return reset($rows[0]);
    }
}
