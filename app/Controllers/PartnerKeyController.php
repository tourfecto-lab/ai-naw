<?php

/**
 * Tourfecto - Partner Key Controller (Admin)
 * إدارة مفاتيح API الخاصة بالشركاء الخارجيين من لوحة الأدمن: إنشاء،
 * عرض، وإلغاء. كل المسارات هنا محمية بـ AuthMiddleware + AdminMiddleware
 * (مسجّلة جوه مجموعة /api/admin الموجودة أصلاً).
 * @version 1.0.0
 */

class PartnerKeyController extends Controller
{
    /**
     * قائمة كل مفاتيح الشركاء (بدون كشف أي جزء من المفتاح الفعلي)
     * GET /api/admin/partner-keys
     */
    public function list(array $params = []): array
    {
        try {
            $keys = (new PartnerApiKey())->all(['created_at' => 'DESC']);
            return $this->success([
                'keys' => array_map(fn (PartnerApiKey $k) => $k->toPublicArray(), $keys),
            ]);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('PartnerKey list error', ['message' => $e->getMessage()]);
            }
            return $this->error('Failed to load partner keys', 500);
        }
    }

    /**
     * إنشاء مفتاح جديد لشريك - المفتاح الخام بيترجع مرة واحدة بس في
     * الرد على الطلب ده، ومش هيبقى متاح تاني بعدها (زي أي نظام API key
     * احترافي). لازم الأدمن يوصّله للشريك دلوقتي أو يعمل مفتاح جديد.
     * POST /api/admin/partner-keys
     * body: { partner_name, contact_email?, scopes: string[], rate_limit_per_minute? }
     */
    public function create(array $params = []): array
    {
        $partnerName = trim((string) $this->get('partner_name', ''));
        $scopes = $this->get('scopes', []);
        $contactEmail = $this->get('contact_email');
        $rateLimit = (int) $this->get('rate_limit_per_minute', 60);

        if ($partnerName === '') {
            return $this->error('partner_name is required', 400);
        }
        if (!is_array($scopes) || empty($scopes)) {
            return $this->error('scopes must be a non-empty array, e.g. ["reputation:read"]', 400);
        }

        try {
            $adminId = $this->user['id'] ?? null;
            $result = PartnerApiKey::generate($partnerName, $scopes, $contactEmail, $rateLimit, $adminId);

            return $this->success([
                'key' => $result['model']->toPublicArray(),
                // المفتاح الخام - يظهر مرة واحدة بس، الأدمن لازم ينسخه دلوقتي
                'raw_key' => $result['raw_key'],
                'warning' => 'احفظ هذا المفتاح الآن - لن يُعرض مرة أخرى.',
            ], 'Partner API key created', 201);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('PartnerKey create error', ['message' => $e->getMessage()]);
            }
            return $this->error('Failed to create partner key', 500);
        }
    }

    /**
     * إلغاء مفتاح فورًا - أي طلب جاي بيه بعد كده هيترفض بـ 401
     * DELETE /api/admin/partner-keys/{id}
     */
    public function revoke(array $params): array
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return $this->error('Invalid id', 400);
        }

        try {
            $key = (new PartnerApiKey())->find($id);
            if (!$key) {
                return $this->error('Partner key not found', 404);
            }

            $key->revoke();
            return $this->success([], 'Partner API key revoked');
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('PartnerKey revoke error', ['message' => $e->getMessage()]);
            }
            return $this->error('Failed to revoke partner key', 500);
        }
    }
}
