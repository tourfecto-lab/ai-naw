<?php

/**
 * Tourfecto - Business Brand Settings Controller
 * Business Control Center - Phase 7
 * @version 1.0.0
 */
class BusinessBrandSettingsController extends Controller
{
    /**
     * نفس نسخة BusinessAccessService جوه الطلب الواحد - عشان الـroleCache
     * الجوه الـService يشتغل (بدل استعلامات متكررة لكل فحص). Phase 27.
     */
    private ?BusinessAccessService $accessService = null;

    private function access(): BusinessAccessService
    {
        if ($this->accessService === null) {
            $this->accessService = new BusinessAccessService();
        }
        return $this->accessService;
    }

    private function loadOwnedBusiness(int $businessId, int $userId): ?Business
    {
        return $this->access()->getAccessibleBusiness($businessId, $userId);
    }

    /** GET /api/business/{businessId}/brand */
    public function show(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $rows = (new BusinessBrandSettings())->where(['business_id' => (int) $business->getAttribute('id')], [], 1);
        if (empty($rows)) {
            return $this->success(['brand_settings' => null]);
        }

        return $this->success(['brand_settings' => $rows[0]->toArray()]);
    }

    /** PUT /api/business/{businessId}/brand */
    public function upsert(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $businessId = (int) ($params['businessId'] ?? 0);
        $business = $this->loadOwnedBusiness($businessId, (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }
        if (!$this->access()->canEdit($businessId, (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية تعديل البيانات', 403);
        }

        if (!$this->validate([
            'favicon_url' => 'max_length:500',
            'font_preference' => 'max_length:100',
            'writing_style' => 'max_length:2000',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // brand_colors: لازم Object فيه مفاتيح معروفة بس (primary/
        // secondary/accent)، وكل قيمة لازم تكون Hex color حقيقي - مش
        // أي نص عشوائي ممكن يكسر CSS لو استُخدم مباشرة في الواجهة.
        if ($this->has('brand_colors')) {
            $colors = $this->get('brand_colors');
            if (!is_array($colors)) {
                return $this->error('بيانات غير صحيحة', 422, ['brand_colors' => ['يجب أن تكون Object']]);
            }
            $allowedKeys = ['primary', 'secondary', 'accent'];
            foreach ($colors as $key => $value) {
                if (!in_array($key, $allowedKeys, true)) {
                    return $this->error('مفتاح لون غير معروف', 422, ['brand_colors' => ["المفاتيح المسموحة: " . implode(', ', $allowedKeys)]]);
                }
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $value)) {
                    return $this->error('كود لون غير صحيح', 422, ['brand_colors' => ["قيمة {$key} يجب أن تكون Hex color بصيغة #RRGGBB"]]);
                }
            }
        }

        foreach (['preferred_terminology', 'prohibited_terminology'] as $field) {
            if ($this->has($field) && !is_array($this->get($field))) {
                return $this->error('بيانات غير صحيحة', 422, [$field => ['يجب أن تكون قائمة (Array)']]);
            }
            // كل عنصر لازم يكون نص (مصطلح) أو Object {term, use_instead} -
            // مش قيمة scalar غير مفهومة بيتم تخزينها بصمت.
            if ($this->has($field) && is_array($this->get($field))) {
                foreach ($this->get($field) as $entry) {
                    if (is_array($entry)) {
                        if (!isset($entry['term']) || !is_string($entry['term']) || trim($entry['term']) === '') {
                            return $this->error('شكل مصطلح غير صحيح', 422, [$field => ['كل Object لازم يحتوي على term نصي غير فارغ']]);
                        }
                    } elseif (!is_string($entry) || trim($entry) === '') {
                        return $this->error('شكل مصطلح غير صحيح', 422, [$field => ['كل عنصر لازم يكون نصًا أو Object {term, use_instead}']]);
                    }
                }
            }
        }

        // favicon_url: رابط - نفس معالجة website_url/logo_url في BusinessController
        if ($this->has('favicon_url') && $this->get('favicon_url') !== '') {
            $raw = (string) $this->get('favicon_url');
            $candidate = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
            if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                return $this->error('رابط الأيقونة غير صحيح', 422, ['favicon_url' => ['يجب أن يكون رابطًا صحيحًا']]);
            }
        }

        $existing = (new BusinessBrandSettings())->where(['business_id' => $businessId], [], 1);
        $record = !empty($existing) ? $existing[0] : new BusinessBrandSettings();
        if (empty($existing)) {
            $record->setAttribute('business_id', $businessId);
        }

        foreach (['favicon_url', 'font_preference', 'writing_style'] as $field) {
            if ($this->has($field)) {
                $record->setAttribute($field, trim((string) $this->get($field)));
            }
        }
        if ($this->has('brand_colors')) {
            $record->setAttribute('brand_colors', json_encode($this->get('brand_colors')));
        }
        foreach (['preferred_terminology', 'prohibited_terminology'] as $field) {
            if ($this->has($field)) {
                $record->setAttribute($field, json_encode(array_values($this->get($field))));
            }
        }

        if ($record->save() === false) {
            return $this->error('تعذر حفظ إعدادات العلامة التجارية', 500);
        }

        // brand_colors/writing_style/terminology متضمّنين فعليًا في
        // BusinessContextService (buildContext + toPromptContext) - راجع
        // Services/BusinessContextService.php. Invalidate هنا زي أي
        // تعديل تاني على بيانات الـBusiness.
        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $this->user['id'], 'brand_settings_updated', 'success', 'business', (string) $businessId);

        return $this->success(['brand_settings' => $record->toArray()], 'تم حفظ إعدادات العلامة التجارية');
    }
}
