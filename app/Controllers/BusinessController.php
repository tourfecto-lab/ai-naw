<?php

/**
 * Tourfecto - Business Controller
 * Business Profile (منفصل عن User Profile) - Business Control Center Phase 2
 * @version 1.0.0
 */
class BusinessController extends Controller
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

    /**
     * تحميل الـBusiness المرتبط بالمستخدم الحالي، مع فحص ملكية صريح.
     * دايمًا بيرجع null لو المستخدم مش Owner - حتى لو الـID موجود فعلًا
     * لمستخدم تاني (منع IDOR: محدش يقدر يشوف/يعدّل Business غير بتاعه
     * بمجرد تخمين ID، حتى لو الفحص ده مش Policy/Gate حقيقي بمعنى Laravel
     * - المعمارية دي مفهاش نظام Policies جاهز، فالفحص هنا Server-side
     * صريح بدل ما يعتمد على إخفاء الـID فقط).
     *
     * Phase 10-11 (Team Management + RBAC): الفحص بقى بيعدي عبر
     * BusinessAccessService (نقطة الفحص المركزية الوحيدة) بدل isOwnedBy()
     * - فبيدعم إن فريق كامل (owner/admin/member/viewer) يشتغل على نفس
     * الـBusiness، مش المالك بس.
     */
    private function loadOwnedBusiness(int $businessId, int $userId): ?Business
    {
        return $this->access()->getAccessibleBusiness($businessId, $userId);
    }

    /** GET /api/business - الـBusiness (أو أول واحد) بتاع المستخدم الحالي */
    public function show(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->access()->resolveUserBusiness((int) $this->user['id']);
        if (!$business) {
            // مفيش Business لسه - مش خطأ، حالة طبيعية (لسه مكمّلش Onboarding)
            return $this->success(['business' => null]);
        }

        return $this->success(['business' => $business->toArray()]);
    }

    /**
     * POST /api/business - إنشاء Business جديد.
     * حاليًا: مستخدم واحد = Business واحد بس (قبل ما يتبني Team
     * Management في مرحلة لاحقة). لو عنده واحد بالفعل، بنرفض إنشاء
     * تاني هنا - التعديل بيبقى عن طريق update() مش إنشاء نسخة تانية.
     */
    public function store(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) $this->user['id'];

        $existing = (new Business())->where(['owner_user_id' => $userId], [], 1);
        if (!empty($existing)) {
            return $this->error('عندك Business Profile موجود بالفعل - استخدم التحديث بدل الإنشاء', 409, ['business_id' => $existing[0]->getAttribute('id')]);
        }

        $validationError = $this->validateBusinessInput(false);
        if ($validationError !== null) {
            return $validationError;
        }

        $business = new Business();
        $business->setAttribute('owner_user_id', $userId);
        $this->applyBusinessFields($business);

        if ($business->save() === false) {
            return $this->error('تعذر إنشاء Business Profile', 500);
        }

        BusinessAuditLog::record((int) $business->getAttribute('id'), $userId, 'business_created', 'success', 'business', (string) $business->getAttribute('id'));

        return $this->success(['business' => $business->toArray()], 'تم إنشاء Business Profile', 201);
    }

    /**
     * GET /api/business/overview - لوحة ملخص موحدة للـBusiness (نفس فكرة
     * SOCi Visibility Dashboard / Yext single pane). استجابة واحدة بتجمع:
     * السياق الكامل (BusinessContextService - الـSingle Source of Truth)،
     * درجة الجاهزية (AI Audit score)، وإحصائيات سريعة (عدد المواقع/
     * الخدمات/الأسواق)، وأهم 5 خطوات تالية. ده بيمثل الربط مع الـDashboard
     * المطلوب في الـSpec (Phase 19) - من غير ما يحتاج الـFrontend يلم
     * 6 Endpoints مختلفة ويعيد تركيب الصورة بنفسه.
     */
    public function overview(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->access()->resolveUserBusiness((int) $this->user['id']);
        if (!$business) {
            return $this->success([
                'business' => null,
                'readiness' => null,
                'stats' => null,
                'next_steps' => [],
            ]);
        }

        $businessId = (int) $business->getAttribute('id');
        $context = (new BusinessContextService())->getContext($businessId);
        $readiness = (new BusinessReadinessService())->scoreFromContext($context);

        $stats = [
            'locations_count' => count($context['locations'] ?? []),
            'active_services_count' => count($context['services'] ?? []),
            'target_countries_count' => count($context['target_markets']['countries'] ?? []),
            'target_cities_count' => count($context['target_markets']['cities'] ?? []),
            'target_languages_count' => count($context['target_markets']['languages'] ?? []),
            'competitors_count' => count($context['ai_context']['competitors'] ?? []),
            'has_ai_context' => !empty($context['ai_context']['business_summary']),
            'has_brand_settings' => !empty($context['brand_settings']),
        ];

        return $this->success([
            'business' => $context['business'],
            'readiness' => $readiness,
            'stats' => $stats,
            'next_steps' => array_slice($readiness['recommendations'], 0, 5),
        ]);
    }

    /** PUT /api/business/{id} - تحديث. Authorization: Owner فقط */
    public function update(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $access = $this->access();
        $business = $access->getAccessibleBusiness((int) ($params['id'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            // 404 مش 403 عمدًا: منمنعش معلومة "الـID ده موجود لكن مش بتاعك"
            // - نفس مبدأ عدم كشف وجود موارد لمستخدمين تانيين (IDOR-safe).
            return $this->error('Business Profile غير موجود', 404);
        }
        // viewer (عضو للعرض بس) - يشوف لكن مش بيعدّل.
        if (!$access->canEdit((int) $business->getAttribute('id'), (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية تعديل بيانات الـBusiness', 403);
        }

        $validationError = $this->validateBusinessInput(true);
        if ($validationError !== null) {
            return $validationError;
        }

        $this->applyBusinessFields($business);

        if ($business->save() === false) {
            return $this->error('تعذر تحديث Business Profile', 500);
        }

        // لازم عشان BusinessContextService::getContext() متفضلش تسيّب
        // نسخة قديمة من بيانات الـBusiness نفسها لأي AI Module بعد
        // التعديل - راجع التعليق الكامل جوه BusinessContextService.
        (new BusinessContextService())->invalidate((int) $business->getAttribute('id'));

        BusinessAuditLog::record((int) $business->getAttribute('id'), (int) $this->user['id'], 'business_updated', 'success', 'business', (string) $business->getAttribute('id'));

        return $this->success(['business' => $business->toArray()], 'تم تحديث Business Profile');
    }

    /**
     * Validation حقيقي من الباك إند - Backend هو مصدر الحقيقة، نفس مبدأ
     * كل الـValidation في UserController::updateProfile(). $isUpdate
     * بيفرّق بين Create (كل الحقول المطلوبة اختيارية التحقق من وجودها)
     * و Update (زي ما هي، مفيش حقول Required إجبارية غير legal_name).
     */
    private function validateBusinessInput(bool $isUpdate): ?array
    {
        $rules = [
            'legal_name' => ($isUpdate ? '' : 'required|') . 'max_length:255',
            'trade_name' => 'max_length:255',
            'description' => 'max_length:2000',
            'website_url' => 'max_length:500',
            'business_email' => 'email|max_length:255',
            'business_phone' => 'max_length:50',
            'whatsapp_number' => 'max_length:50',
            'city' => 'max_length:150',
            'address' => 'max_length:500',
            'postal_code' => 'max_length:20',
            'tourism_license_number' => 'max_length:100',
            'tax_number' => 'max_length:100',
        ];
        // إزالة أي قاعدة فاضية (لو الحقل مش required عند التحديث)
        $rules = array_filter($rules, fn ($r) => trim($r, '|') !== '');

        if (!$this->validate($rules)) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if ($this->has('business_type') && $this->get('business_type') !== '') {
            if (!array_key_exists($this->get('business_type'), Business::allowedBusinessTypes())) {
                return $this->error('نوع الشركة غير صحيح', 422, ['business_type' => ['قيمة غير معروفة']]);
            }
        }

        if ($this->has('country_code') && $this->get('country_code') !== '') {
            $code = strtoupper((string) $this->get('country_code'));
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                return $this->error('كود الدولة غير صحيح', 422, ['country_code' => ['يجب أن يكون كود ISO 3166-1 من حرفين']]);
            }
        }

        if ($this->has('default_currency') && $this->get('default_currency') !== '') {
            $currency = strtoupper((string) $this->get('default_currency'));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                return $this->error('كود العملة غير صحيح', 422, ['default_currency' => ['يجب أن يكون كود ISO 4217 من 3 حروف']]);
            }
        }

        if ($this->has('year_established') && $this->get('year_established') !== '') {
            $year = (int) $this->get('year_established');
            if ($year < 1800 || $year > (int) date('Y')) {
                return $this->error('سنة التأسيس غير منطقية', 422, ['year_established' => ['خارج النطاق المسموح']]);
            }
        }

        // روابط URL: الموقع والشعار - بنسمح بالصيغة من غير scheme (بنسيب
        // الـhttps:// للـFrontend وقت العرض)، فبنضيفها مؤقتًا للفحص بس.
        foreach (['website_url', 'logo_url'] as $urlField) {
            if ($this->has($urlField) && $this->get($urlField) !== '') {
                $raw = (string) $this->get($urlField);
                $candidate = preg_match('#^https?://#i', $raw) ? $raw : 'https://' . $raw;
                if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                    return $this->error('رابط غير صحيح', 422, [$urlField => ['يجب أن يكون رابطًا صحيحًا']]);
                }
            }
        }

        // اللغة الأساسية: ISO 639-1 (حرفين) أو ISO 639-2 (ثلاثة) - أحرف فقط
        if ($this->has('primary_language') && $this->get('primary_language') !== '') {
            if (!preg_match('/^[A-Za-z]{2,3}$/', (string) $this->get('primary_language'))) {
                return $this->error('اللغة الأساسية غير صحيحة', 422, ['primary_language' => ['يجب أن يكون كود ISO 639 من حرفين أو ثلاثة']]);
            }
        }

        // supported_languages: نفس معيار primary_language لكل عنصر
        if ($this->has('supported_languages') && $this->get('supported_languages') !== null) {
            $langs = $this->get('supported_languages');
            if (!is_array($langs)) {
                return $this->error('اللغات المدعومة غير صحيحة', 422, ['supported_languages' => ['يجب أن تكون قائمة (Array)']]);
            }
            foreach ($langs as $lang) {
                if (!is_string($lang) || !preg_match('/^[A-Za-z]{2,3}$/', $lang)) {
                    return $this->error('لغة غير صحيحة في supported_languages', 422, ['supported_languages' => ['كل قيمة لازم تكون كود ISO 639 من حرفين أو ثلاثة']]);
                }
            }
        }

        // المنطقة الزمنية: لازم تكون IANA صحيحة (نفس قائمة UserController
        // اللي بتتولد من DateTimeZone نفسها)
        if ($this->has('timezone') && $this->get('timezone') !== '') {
            if (!in_array($this->get('timezone'), timezone_identifiers_list(), true)) {
                return $this->error('المنطقة الزمنية غير صحيحة', 422, ['timezone' => ['يجب أن تكون معرف IANA صحيحًا (مثل Africa/Cairo)']]);
            }
        }

        return null;
    }

    /** يطبّق كل الحقول اللي اتبعتت فعليًا (has()) على الـBusiness Model - نفس نمط UserController::updateProfile() */
    private function applyBusinessFields(Business $business): void
    {
        $fields = [
            'legal_name', 'trade_name', 'logo_url', 'description', 'website_url',
            'business_email', 'business_phone', 'whatsapp_number', 'city', 'address',
            'postal_code', 'tourism_license_number', 'tax_number', 'business_type',
            'year_established', 'primary_language', 'default_currency', 'timezone',
        ];
        foreach ($fields as $field) {
            if ($this->has($field)) {
                $value = $this->get($field);
                $value = is_string($value) ? trim($value) : $value;
                $business->setAttribute($field, $value);
            }
        }

        if ($this->has('country_code') && $this->get('country_code') !== '') {
            $business->setAttribute('country_code', strtoupper((string) $this->get('country_code')));
        }
        if ($this->has('default_currency') && $this->get('default_currency') !== '') {
            $business->setAttribute('default_currency', strtoupper((string) $this->get('default_currency')));
        }

        // supported_languages: مصفوفة من الـFrontend -> JSON للتخزين.
        // لازم يتحقق إنها فعلًا Array قبل الترميز، مش نص عشوائي.
        if ($this->has('supported_languages')) {
            $langs = $this->get('supported_languages');
            if (is_array($langs)) {
                $business->setAttribute('supported_languages', json_encode(array_values(array_unique($langs))));
            }
        }
    }
}
