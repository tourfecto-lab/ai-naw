<?php

/**
 * Tourfecto - Partner API Key Model
 * إدارة مفاتيح API الخاصة بالشركاء الخارجيين (Partners) - منفصلة عن
 * مصادقة المستخدم العادي (Session/api_token في جدول users).
 * @version 1.0.0
 */

class PartnerApiKey extends Model
{
    /** @var string $table */
    protected $table = 'partner_api_keys';

    /** @var array $fillable */
    protected $fillable = [
        'partner_name',
        'contact_email',
        'key_prefix',
        'key_hash',
        'scopes',
        'rate_limit_per_minute',
        'status',
        'last_used_at',
        'last_used_ip',
        'created_by_admin_id',
        'revoked_at',
    ];

    /** طول المفتاح الخام (قبل الـ hashing) */
    private const RAW_KEY_LENGTH = 40;

    /** طول الـ prefix المعروض في لوحة الأدمن */
    private const PREFIX_LENGTH = 8;

    /**
     * توليد مفتاح جديد للشريك، تخزين الـ hash بتاعه، وإرجاع المفتاح
     * الخام مرة واحدة فقط (زي أي نظام API Key احترافي - المفتاح
     * الكامل ميتخزنش أبدًا نص صريح، ومش هيتعرض تاني بعد الإنشاء).
     *
     * @param string $partnerName
     * @param array $scopes مثال: ['reputation:read', 'reviews:read']
     * @param string|null $contactEmail
     * @param int $rateLimitPerMinute
     * @param int|null $createdByAdminId
     * @return array{model: PartnerApiKey, raw_key: string}
     */
    public static function generate(
        string $partnerName,
        array $scopes,
        ?string $contactEmail = null,
        int $rateLimitPerMinute = 60,
        ?int $createdByAdminId = null
    ): array {
        // tf_live_ prefix يسهّل التعرف على نوع المفتاح فورًا (زي stripe/sk_live_)
        $rawKey = 'tf_live_' . bin2hex(random_bytes((int) ceil(self::RAW_KEY_LENGTH / 2)));
        $rawKey = substr($rawKey, 0, self::RAW_KEY_LENGTH + 8);

        $model = new self([
            'partner_name' => $partnerName,
            'contact_email' => $contactEmail,
            'key_prefix' => substr($rawKey, 0, self::PREFIX_LENGTH),
            'key_hash' => password_hash($rawKey, PASSWORD_DEFAULT),
            'scopes' => json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE),
            'rate_limit_per_minute' => max(1, $rateLimitPerMinute),
            'status' => 'active',
            'created_by_admin_id' => $createdByAdminId,
        ]);
        $model->save();

        return ['model' => $model, 'raw_key' => $rawKey];
    }

    /**
     * التحقق من مفتاح خام واردة من طلب Partner، وإرجاع السجل المطابق
     * لو صالح ونشط. بيستخدم password_verify (مقاوم لـ timing attacks)
     * بدل مقارنة hash مباشرة، وبيدور بس على المفاتيح النشطة اللي عندها
     * نفس الـ prefix (تحسين أداء - مش لازم يعمل password_verify على كل
     * مفتاح مسجّل في الجدول).
     *
     * @param string $rawKey
     * @return self|null
     */
    public static function verify(string $rawKey): ?self
    {
        if (strlen($rawKey) < self::PREFIX_LENGTH) {
            return null;
        }

        $prefix = substr($rawKey, 0, self::PREFIX_LENGTH);
        $candidates = (new self())->where(['key_prefix' => $prefix, 'status' => 'active']);

        foreach ($candidates as $candidate) {
            if (password_verify($rawKey, $candidate->getAttribute('key_hash'))) {
                return $candidate;
            }
        }

        return null;
    }

    /** الصلاحيات كمصفوفة PHP بدل JSON خام */
    public function getScopes(): array
    {
        $raw = $this->getAttribute('scopes');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($decoded) ? $decoded : [];
    }

    /** هل المفتاح ده معاه صلاحية معينة؟ (أو صلاحية '*' الشاملة) */
    public function hasScope(string $scope): bool
    {
        $scopes = $this->getScopes();
        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    /** تحديث وقت وIP آخر استخدام - بدون ما يفشل الطلب لو حصل خطأ بسيط */
    public function touchUsage(string $ip): void
    {
        try {
            $this->setAttribute('last_used_at', date('Y-m-d H:i:s'));
            $this->setAttribute('last_used_ip', $ip);
            $this->save();
        } catch (Throwable $e) {
            // تجاهل - تحديث "آخر استخدام" مش حرج بما يكفي يوقف طلب الشريك
        }
    }

    /** إلغاء المفتاح فورًا */
    public function revoke(): bool
    {
        $this->setAttribute('status', 'revoked');
        $this->setAttribute('revoked_at', date('Y-m-d H:i:s'));
        return (bool) $this->save();
    }

    /** تمثيل آمن للعرض في لوحة الأدمن - بدون أي جزء من الـ hash */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->getAttribute('id'),
            'partner_name' => $this->getAttribute('partner_name'),
            'contact_email' => $this->getAttribute('contact_email'),
            'key_preview' => $this->getAttribute('key_prefix') . '••••••••••••',
            'scopes' => $this->getScopes(),
            'rate_limit_per_minute' => $this->getAttribute('rate_limit_per_minute'),
            'status' => $this->getAttribute('status'),
            'last_used_at' => $this->getAttribute('last_used_at'),
            'created_at' => $this->getAttribute('created_at'),
        ];
    }
}
