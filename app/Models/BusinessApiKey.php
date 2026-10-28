<?php

/**
 * Tourfecto - Business API Key Model
 * Business-scoped API Keys - Business Control Center Phase 12
 * @version 1.0.0
 *
 * مفاتيح برمجية مرتبطة بالـBusiness (مش بالمستخدم) - للـIntegrations و
 * التكاملات الخارجية مع بيانات الشركة. منفصلة تمامًا عن UserApiKey
 * (اللي بيخص حساب المستخدم نفسه) وعن users.api_token (مصادقة الويب).
 *
 * نفس فلسفة UserApiKey الأمنية: الـhash بس هو اللي بيتخزن، والمفتاح
 * الخام بيترجع مرة واحدة وقت الإنشاء. البادئة (prefix) بتخلي البحث
 * سريع من غير password_verify على كل الصفوف.
 */

class BusinessApiKey extends Model
{
    protected $table = 'business_api_keys';

    protected $fillable = [
        'business_id',
        'created_by_user_id',
        'name',
        'key_prefix',
        'key_hash',
        'scope',
        'last_used_at',
        'revoked_at',
    ];

    /** بادئة تميّز مفاتيح الـBusiness عن tf_pk_ (المستخدم) و tf_live_ (الشركاء) */
    private const KEY_PREFIX_TAG = 'tf_bk_';

    /** طول الجزء العشوائي بالمفتاح الخام (بالبايت قبل hex) */
    private const RAW_RANDOM_BYTES = 16;

    /** طول الـprefix المعروض للتعرف على المفتاح (tf_bk_ + 8 حروف) */
    private const DISPLAY_PREFIX_LENGTH = 13;

    /** النطاقات المسموحة - كود مش DB ENUM عمدًا (إضافة scope جديد = سطر هنا) */
    public static function allowedScopes(): array
    {
        return ['read', 'write'];
    }

    /**
     * إنشاء مفتاح جديد لـBusiness، وتخزين الـhash فقط.
     * المفتاح الخام بيترجع مرة واحدة هنا فقط - النظام مايخزّنوش أبدًا.
     *
     * @return array{model: BusinessApiKey, raw_key: string}
     */
    public static function generateFor(int $businessId, int $createdByUserId, string $name, string $scope = 'read'): array
    {
        $scope = in_array($scope, self::allowedScopes(), true) ? $scope : 'read';
        $rawKey = self::KEY_PREFIX_TAG . bin2hex(random_bytes(self::RAW_RANDOM_BYTES));

        $model = new self([
            'business_id' => $businessId,
            'created_by_user_id' => $createdByUserId,
            'name' => $name,
            'key_prefix' => substr($rawKey, 0, self::DISPLAY_PREFIX_LENGTH),
            'key_hash' => password_hash($rawKey, PASSWORD_DEFAULT),
            'scope' => $scope,
        ]);
        $model->save();

        return ['model' => $model, 'raw_key' => $rawKey];
    }

    /** هل التوكن الخام ده شكله مفتاح Business أصلاً؟ (فحص قبل أي DB) */
    public static function looksLikeBusinessApiKey(string $rawKey): bool
    {
        return strpos($rawKey, self::KEY_PREFIX_TAG) === 0;
    }

    /**
     * التحقق من مفتاح خام، وإرجاع السجل المطابق لو موجود وسليم وغير ملغي.
     * بتدوّر بس على المفاتيح اللي عندها نفس الـprefix (أداء أفضل).
     */
    public static function verify(string $rawKey): ?self
    {
        if (!self::looksLikeBusinessApiKey($rawKey) || strlen($rawKey) < self::DISPLAY_PREFIX_LENGTH) {
            return null;
        }

        $prefix = substr($rawKey, 0, self::DISPLAY_PREFIX_LENGTH);
        $candidates = (new self())->where(['key_prefix' => $prefix]);

        foreach ($candidates as $candidate) {
            if ($candidate->getAttribute('revoked_at')) {
                continue;
            }
            if (password_verify($rawKey, $candidate->getAttribute('key_hash'))) {
                return $candidate;
            }
        }

        return null;
    }

    /** هل المفتاح (نطاقه) بيسمح بالصلاحية المطلوبة؟ pure */
    public static function scopeAllows(string $keyScope, string $required): bool
    {
        if ($required === 'read') {
            return in_array($keyScope, ['read', 'write'], true);
        }
        if ($required === 'write') {
            return $keyScope === 'write';
        }
        return false;
    }

    /** تحديث وقت آخر استخدام - بدون ما يفشل الطلب لو حصل خطأ بسيط */
    public function touchUsage(): void
    {
        try {
            $this->setAttribute('last_used_at', date('Y-m-d H:i:s'));
            $this->save();
        } catch (Throwable $e) {
            // تجاهل - مش حرج بما يكفي يوقف الطلب
        }
    }

    /** إلغاء المفتاح فورًا */
    public function revoke(): bool
    {
        $this->setAttribute('revoked_at', date('Y-m-d H:i:s'));
        return (bool) $this->save();
    }

    /** تمثيل آمن للعرض في الواجهة - بدون أي جزء من الـhash */
    public function toSafeArray(): array
    {
        return [
            'id' => (int) $this->getAttribute('id'),
            'business_id' => (int) $this->getAttribute('business_id'),
            'name' => $this->getAttribute('name'),
            'scope' => (string) $this->getAttribute('scope'),
            'key_prefix' => $this->getAttribute('key_prefix'),
            'last_used_at' => $this->getAttribute('last_used_at'),
            'created_at' => $this->getAttribute('created_at'),
            'revoked' => (bool) $this->getAttribute('revoked_at'),
        ];
    }
}
