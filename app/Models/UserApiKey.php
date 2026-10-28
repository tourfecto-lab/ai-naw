<?php

/**
 * Tourfecto - User API Key Model
 * مفاتيح API شخصية للمستخدم - منفصلة تمامًا عن users.api_token
 * (اللي بيُستخدم لمصادقة الكوكي/الجلسة العادية للموقع).
 * @version 1.0.0
 */

class UserApiKey extends Model
{
    protected $table = 'user_api_keys';

    protected $fillable = [
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'last_used_at',
        'expires_at',
        'scopes',
        'revoked_at',
    ];

    /** البادئة اللي تميّز مفاتيح المستخدم الشخصية عن partner_api_keys (tf_live_) وJWT */
    private const KEY_PREFIX_TAG = 'tf_pk_';

    /** طول الجزء العشوائي من المفتاح الخام (بالبايت قبل hex) */
    private const RAW_RANDOM_BYTES = 16;

    /** طول الـ prefix المعروض في الواجهة للتمييز بدون كشف المفتاح كامل */
    private const DISPLAY_PREFIX_LENGTH = 14; // "tf_pk_" + 8 حروف

    /**
     * الصلاحيات المدعومة لمفاتيح API - نفس فلسفة GitHub Fine-grained PAT:
     * كل مفتاح بيتقيّد بصلاحيات محددة بدل ما يكون عنده وصول كامل دائمًا.
     * لما الـ scopes = null (مفتاح قديم/افتراضي) يبقى وصول كامل.
     */
    public const SCOPES = [
        'profile:read' => 'قراءة الملف الشخصي والإعدادات',
        'profile:write' => 'تعديل الملف الشخصي والإعدادات',
        'billing:read' => 'قراءة الفواتير والمحفظة والاشتراك',
        'workspace:read' => 'قراءة بيانات الشركة والأعضاء',
        'workspace:write' => 'إدارة الشركة والأعضاء والأدوار',
        'audit:read' => 'قراءة سجل التدقيق',
        'data:export' => 'تصدير البيانات والسجل',
    ];

    /**
     * هل المفتاح (منتهي من أي جهة) مسموح له يexecute صلاحية معينة؟
     * null/فاضي = وصول كامل (توافق خلفي مع المفاتيح القديمة).
     */
    public static function hasScope(?string $scopesJson, string $scope): bool
    {
        if ($scopesJson === null || $scopesJson === '') {
            return true; // مفتاح قديم من غير scopes = وصول كامل
        }
        $scopes = json_decode($scopesJson, true);
        if (!is_array($scopes)) {
            return true; // JSON غير صالح - نسامح ونسمح (نفس قيمة null)
        }
        return in_array($scope, $scopes, true);
    }

    /**
     * إنشاء مفتاح جديد لمستخدم معيّن، وتخزين الـ hash بتاعه فقط.
     * المفتاح الخام بيترجع مرة واحدة هنا بس - النظام مايخزّنهوش نص صريح
     * أبدًا، ومش هيتعرض تاني بعد كده حتى لصاحبه.
     *
     * @param array|null $scopes قائمة الصلاحيات (null = وصول كامل)
     * @return array{model: UserApiKey, raw_key: string}
     */
    public static function generateFor(int $userId, string $name, ?string $expiresAt = null, ?array $scopes = null): array
    {
        $rawKey = self::KEY_PREFIX_TAG . bin2hex(random_bytes(self::RAW_RANDOM_BYTES));

        // نضيف expires_at للأعمدة بس لو فيه صلاحية فعليًا (مش null) -
        // عشان لو الـ migration لسه متعملش على السيرفر، الـ INSERT
        // مفيش فيه عمود غير موجود أصلاً ومايقعش (Backward Compatible).
        $attrs = [
            'user_id' => $userId,
            'name' => $name,
            'key_prefix' => substr($rawKey, 0, self::DISPLAY_PREFIX_LENGTH),
            'key_hash' => password_hash($rawKey, PASSWORD_DEFAULT),
        ];
        if ($expiresAt !== null) {
            $attrs['expires_at'] = $expiresAt;
        }
        if ($scopes !== null) {
            // ننضّف القيم: ندخل فقط الصلاحيات المعروفة فعليًا، عشان
            // مفيش حد يكتب scope مش موجود في النظام.
            $clean = array_values(array_intersect($scopes, array_keys(self::SCOPES)));
            if (!empty($clean)) {
                $attrs['scopes'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            }
        }

        $model = new self($attrs);
        $model->save();

        return ['model' => $model, 'raw_key' => $rawKey];
    }

    /** هل التوكن الخام ده شكله مفتاح شخصي أصلًا؟ (قبل حتى ما نروح لقاعدة البيانات) */
    public static function looksLikeUserApiKey(string $rawKey): bool
    {
        return strpos($rawKey, self::KEY_PREFIX_TAG) === 0;
    }

    /** هل المفتاح منتهي الصلاحية بناءً على expires_at؟ (null = لا ينتهي أبدًا) */
    public static function isExpired(?string $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }
        return strtotime($expiresAt) <= time();
    }

    /**
     * التحقق من مفتاح خام، وإرجاع السجل المطابق لو موجود وسليم وغير ملغي.
     * بيدوّر بس على المفاتيح اللي عندها نفس الـ prefix (أداء أفضل من
     * password_verify على كل صف في الجدول).
     */
    public static function verify(string $rawKey): ?self
    {
        if (!self::looksLikeUserApiKey($rawKey) || strlen($rawKey) < self::DISPLAY_PREFIX_LENGTH) {
            return null;
        }

        $prefix = substr($rawKey, 0, self::DISPLAY_PREFIX_LENGTH);
        $candidates = (new self())->where(['key_prefix' => $prefix]);

        foreach ($candidates as $candidate) {
            if ($candidate->getAttribute('revoked_at')) {
                continue;
            }
            if (self::isExpired($candidate->getAttribute('expires_at'))) {
                continue; // منتهي الصلاحية - مش صالح
            }
            if (password_verify($rawKey, $candidate->getAttribute('key_hash'))) {
                return $candidate;
            }
        }

        return null;
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

    /** تمثيل آمن للعرض في الواجهة - بدون أي جزء من الـ hash */
    public function toSafeArray(): array
    {
        $rawScopes = $this->getAttribute('scopes');
        return [
            'id' => (int) $this->getAttribute('id'),
            'name' => $this->getAttribute('name'),
            'key_prefix' => $this->getAttribute('key_prefix'),
            'last_used_at' => $this->getAttribute('last_used_at'),
            'created_at' => $this->getAttribute('created_at'),
            'expires_at' => $this->getAttribute('expires_at'),
            'scopes' => $rawScopes === null || $rawScopes === ''
                ? array_keys(self::SCOPES)
                : (is_array(json_decode($rawScopes, true)) ? json_decode($rawScopes, true) : []),
            'revoked' => (bool) $this->getAttribute('revoked_at'),
        ];
    }
}
