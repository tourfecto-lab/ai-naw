<?php

/**
 * Tourfecto - Refresh Token Model
 * إدارة توكنات تحديث JWT لكل جهاز/تسجيل دخول على حدة.
 * @version 1.0.0
 */

class RefreshToken extends Model
{
    protected $table = 'user_refresh_tokens';

    protected $fillable = [
        'user_id', 'token_hash', 'device_name', 'ip_address',
        'user_agent', 'expires_at', 'revoked_at', 'last_used_at',
    ];

    /**
     * توليد refresh token خام جديد لمستخدم معيّن، وتخزين الـ hash بتاعه.
     * @return array{model: RefreshToken, raw_token: string}
     */
    public static function issueFor(int $userId, ?string $deviceName, string $ip, ?string $userAgent): array
    {
        $rawToken = bin2hex(random_bytes(40));
        $ttl = defined('JWT_REFRESH_TOKEN_TTL') ? JWT_REFRESH_TOKEN_TTL : 2592000;

        $model = new self([
            'user_id' => $userId,
            'token_hash' => password_hash($rawToken, PASSWORD_DEFAULT),
            'device_name' => $deviceName ?: 'جهاز غير معروف',
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);
        $model->save();

        return ['model' => $model, 'raw_token' => $rawToken];
    }

    /**
     * التحقق من refresh token خام: لازم يكون موجود، مش منتهي، ومش ملغي.
     * ملاحظة مهمة: تفادينا عمدًا where(['revoked_at' => null]) لأن
     * Model::where() الحالي بيبني `col` = ? بمعامل NULL، وده في SQL
     * بيترجم لـ `col = NULL` (دايمًا false) مش `col IS NULL` - فكانت
     * هتفشل تتحقق من أي توكن سليم أبدًا. الحل: نجيب توكنات المستخدم
     * ونفلتر revoked_at في PHP بدل الاعتماد على الشرط ده في SQL.
     */
    public static function verify(string $rawToken, ?int $userId = null): ?self
    {
        $model = new self();
        $conditions = $userId !== null ? ['user_id' => $userId] : [];
        $candidates = $userId !== null ? $model->where($conditions) : $model->all();
        $now = time();

        foreach ($candidates as $candidate) {
            if ($candidate->getAttribute('revoked_at')) {
                continue; // ملغي
            }
            if (strtotime((string) $candidate->getAttribute('expires_at')) <= $now) {
                continue; // منتهي
            }
            if (password_verify($rawToken, $candidate->getAttribute('token_hash'))) {
                return $candidate;
            }
        }

        return null;
    }

    /** إلغاء توكن واحد فقط (تسجيل خروج من جهاز واحد) */
    public function revoke(): bool
    {
        $this->setAttribute('revoked_at', date('Y-m-d H:i:s'));
        return (bool) $this->save();
    }

    /**
     * إعادة تسمية جهاز/جلسة معيّنة - المستخدم يختار اسم يسهل يتعرف
     * بيه على الجهاز (مثلًا "لابتوب الشغل" بدل "جهاز غير معروف").
     * نفس سلوك GitHub/Apple في تسمية الأجهزة الموثوقة.
     */
    public function renameDevice(string $deviceName): bool
    {
        $name = trim(strip_tags($deviceName));
        if ($name === '' || mb_strlen($name) > 60) {
            return false;
        }
        $this->setAttribute('device_name', mb_substr($name, 0, 60));
        return (bool) $this->save();
    }

    /** إلغاء كل توكنات مستخدم (تسجيل خروج من كل الأجهزة) */
    public static function revokeAllForUser(int $userId): void
    {
        $model = new self();
        $tokens = $model->where(['user_id' => $userId]);
        foreach ($tokens as $token) {
            if (!$token->getAttribute('revoked_at')) {
                $token->revoke();
            }
        }
    }

    /**
     * إلغاء كل توكنات مستخدم باستثناء توكن واحد (الجهاز الحالي).
     * تُستخدم بعد تغيير كلمة المرور: تسجيل خروج من كل الأجهزة عدا
     * الجهاز اللي نفّذ التغيير - نفس سلوك GitHub/Stripe عند تغيير
     * كلمة المرور (أي جهاز تاني كان مسجل دخول بيتخرج فورًا).
     */
    public static function revokeAllForUserExcept(int $userId, ?int $exceptTokenId): void
    {
        $model = new self();
        $tokens = $model->where(['user_id' => $userId]);
        foreach ($tokens as $token) {
            if (!$token->getAttribute('revoked_at')) {
                $tokenId = (int) $token->getAttribute('id');
                if ($exceptTokenId !== null && $tokenId === (int) $exceptTokenId) {
                    continue; // نحتفظ بالجلسة الحالية
                }
                $token->revoke();
            }
        }
    }

    public function touchUsage(): void
    {
        try {
            $this->setAttribute('last_used_at', date('Y-m-d H:i:s'));
            $this->save();
        } catch (Throwable $e) {
            // غير حرج
        }
    }
}
