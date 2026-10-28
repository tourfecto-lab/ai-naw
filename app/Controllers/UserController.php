<?php

/**
 * Tourfecto - User Controller
 * الملف الشخصي وإعدادات المستخدم
 * @version 1.0.0
 *
 * ملاحظة: كان هذا الملف مفقودًا بالكامل. تمت إعادة بنائه اعتمادًا على User Model
 * الموجود فعليًا في app/Models/User.php.
 */

class UserController extends Controller
{
    /**
     * الحصول على المستخدم الحالي من الجلسة، ويُرجع null إن لم يكن مسجل دخول
     */
    private function currentUser(): ?User
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /**
     * هل الطلب الحالي API (JSON) ولا صفحة ويب عادية؟
     */
    private function isApiRequest(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return strpos($path, '/api/') === 0;
    }

    /** GET /profile و GET /api/user/profile */
    public function showProfile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['user' => $user->toArray()]);
        }

        // توحيد: /profile كانت صفحة منفصلة بنفس بيانات تبويب "الملف الشخصي"
        // في /profile/settings بالظبط (نفس الحقول: الاسم، الشركة، الهاتف)
        // - الفرق إن /profile/settings أشمل (فيها الصورة والأمان والإشعارات
        // وAPI كمان في نفس الصفحة). بدل ما نصون نسخة مكررة، نوجّه هنا.
        header('Location: /profile/settings');
        exit;
    }

    private function renderProfilePage(User $user): void
    {
        $data = $user->toArray();
        $firstName = htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName = htmlspecialchars((string) ($data['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCode = htmlspecialchars((string) ($data['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role = htmlspecialchars((string) ($data['role'] ?? 'user'), ENT_QUOTES, 'UTF-8');
        $memberSince = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : '-';

        $body = <<<HTML
        <div class="p-grid cols-2">
            <div class="p-card">
                <div class="p-card-head"><h3>معلومات الحساب</h3></div>
                <div class="p-kv"><span class="k">البريد الإلكتروني</span><span class="v">{$email}</span></div>
                <div class="p-kv"><span class="k">الدور</span><span class="v">{$role}</span></div>
                <div class="p-kv"><span class="k">عضو منذ</span><span class="v">{$memberSince}</span></div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>تعديل البيانات</h3></div>
                <form id="profileForm">
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="first_name">الاسم الأول</label>
                            <input type="text" id="first_name" class="form-control" value="{$firstName}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="last_name">اسم العائلة</label>
                            <input type="text" id="last_name" class="form-control" value="{$lastName}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="company_name">اسم الشركة</label>
                        <input type="text" id="company_name" class="form-control" value="{$companyName}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">رقم الهاتف</label>
                        <input type="text" id="phone" class="form-control" value="{$phone}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="country_code">كود الدولة</label>
                        <input type="text" id="country_code" class="form-control" value="{$countryCode}" placeholder="مثال: EG">
                    </div>
                    <div id="profileAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">حفظ التعديلات</button>
                </form>
            </div>
        </div>
        <div class="p-grid cols-3" style="margin-top:16px;">
            <a href="/profile/settings" class="p-card quick-tile"><span class="ic">⚙️</span>الإعدادات (اللغة والتوقيت)</a>
            <a href="/profile/settings?tab=security" class="p-card quick-tile"><span class="ic">🔒</span>الأمان (كلمة المرور)</a>
            <a href="/profile/api" class="p-card quick-tile"><span class="ic">🔑</span>مفتاح الـ API</a>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, toast = P.toast;

    document.getElementById('profileForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('profileAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: document.getElementById('first_name').value.trim(),
                last_name: document.getElementById('last_name').value.trim(),
                company_name: document.getElementById('company_name').value.trim(),
                phone: document.getElementById('phone').value.trim(),
                country_code: document.getElementById('country_code').value.trim(),
            }),
        });

        if (res.success) {
            toast('تم حفظ التعديلات', 'success');
        } else {
            alertBox.textContent = res.error || 'تعذر الحفظ';
            alertBox.style.display = 'block';
        }
    });
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_profile', 'الملف الشخصي', 'بيانات حسابك', $body, $script);
    }

    public function profile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['user' => $user->toArray()]);
    }

    /** GET /profile/edit -> نفس صفحة /profile (الفورم متضمّن فيها بالفعل) */
    public function showEditProfile(array $params = []): array
    {
        if ($this->isApiRequest()) {
            return $this->profile($params);
        }
        header('Location: /profile');
        exit;
    }

    /** POST /profile/update و PUT /api/user/profile */
    public function updateProfile(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        // Server-side validation (لا نعتمد على Client Validation وحدها -
        // كل حقل عنده حد أقصى مطابق للـ maxlength في الفورم ومطابق لطول
        // العمود في قاعدة البيانات، عشان مانرميش SQL truncation error).
        if (!$this->validate([
            'first_name' => 'max:100',
            'last_name' => 'max:100',
            'display_name' => 'max:120',
            'company_name' => 'max:150',
            'job_title' => 'max:120',
            'bio' => 'max:500',
            'phone' => 'max:20',
            'country_code' => 'max:5',
            'currency' => 'max:3',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // Profile Center Phase 1: country_code و currency (لو مبعوتين
        // ومش فاضيين) لازم يكونوا فعليًا داخل قوائم ISO 3166-1/4217
        // معروفة - الفورم بقى select مقفول بالقيم دي، لكن الـbackend هو
        // مصدر الحقيقة الحقيقي مش الفرونت إند بس.
        if ($this->has('country_code') && $this->get('country_code') !== '') {
            $code = strtoupper((string) $this->get('country_code'));
            if (!array_key_exists($code, self::isoCountries())) {
                return $this->error('كود الدولة غير صحيح', 422, ['country_code' => ['قيمة غير معروفة في ISO 3166-1']]);
            }
        }

        if ($this->has('currency') && $this->get('currency') !== '') {
            $currency = strtoupper((string) $this->get('currency'));
            if (!array_key_exists($currency, self::isoCurrencies())) {
                return $this->error('كود العملة غير صحيح', 422, ['currency' => ['قيمة غير معروفة في ISO 4217 - ملحوظة: دي عملة عرض الملف الشخصي فقط، مش عملة الفوترة']]);
            }
        }

        try{$localeInput=[];foreach(['language','timezone'] as $key)if($this->has($key))$localeInput[$key]=$this->get($key);InternationalSettingsService::accountValues($localeInput);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}

        // تصحيح: 'country' مش موجود في fillable الخاصة بـ User (الاسم
        // الحقيقي 'country_code')، فكان setAttribute('country', ...)
        // بيتجاهل التحديث بصمت من غير أي خطأ ظاهر للمستخدم.
        foreach (['company_name', 'phone', 'country_code', 'language', 'timezone', 'first_name', 'last_name', 'display_name', 'job_title', 'currency', 'bio'] as $field) {
            if ($this->has($field)) {
                $value = $this->get($field);
                // تنظيف بسيط: امسح أي وسوم HTML من الحقول النصية الحرة
                // (bio تحديدًا) عشان نمنع تخزين/عرض محتوى غير موثوق.
                if (is_string($value)) {
                    $value = trim(strip_tags($value));
                }
                if (in_array($field, ['country_code', 'currency'], true) && is_string($value) && $value !== '') {
                    $value = strtoupper($value);
                }
                $user->setAttribute($field, $value === '' ? null : $value);
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث البيانات', 500);
        }

        $_SESSION['user'] = $user->toArray();

        AuditLog::record((int) $user->getAttribute('id'), 'profile_updated');

        return $this->success(['user' => $user->toArray()], 'تم تحديث الملف الشخصي');
    }

    /** PUT /api/user/password */
    public function updatePassword(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$this->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'password_change_failed', 'failed');
            return $this->error('كلمة المرور الحالية غير صحيحة', 401, ['current_password' => ['كلمة المرور الحالية غير صحيحة']]);
        }

        if (!$user->updatePassword((string) $this->get('new_password'))) {
            return $this->error('تعذر تحديث كلمة المرور', 500);
        }

        // أمان: بعد تغيير كلمة المرور بنسجّل خروج من كل الأجهزة التانية
        // فورًا (نحتفظ بالجهاز الحالي بس) - نفس سلوك GitHub/Stripe. أي
        // جلسة مسروقة/قديمة بكلمة مرور قديمة متبقاش صالحة. الاستثناء:
        // لو مفيش refresh token حالي معروف (جلسات قديمة بدون JWT) بنخلي
        // الوظيفة تسجّل خروج من كل الأجهزة عشان أقوى أمان.
        $currentRefreshTokenId = $_SESSION['current_refresh_token_id'] ?? null;
        RefreshToken::revokeAllForUserExcept((int) $user->getAttribute('id'), $currentRefreshTokenId);

        AuditLog::record((int) $user->getAttribute('id'), 'password_changed', 'success', null, null, ['other_sessions_revoked' => true]);

        return $this->success([], 'تم تحديث كلمة المرور بنجاح - تم تسجيل الخروج من جميع الأجهزة الأخرى');
    }

    /** GET /profile/settings و GET /api/user/settings */
    public function showSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/settings'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->getSettings($params);
        }

        $this->renderSettingsPage($user);
        exit;
    }

    private function renderSettingsPage(User $user): void
    {
        $data = $user->toArray();
        // created_at/last_login مش ضمن fillable فـ toArray() مابيرجعهمش، فكان
        // "عضو منذ" دايمًا "-". بنقراهم من الجدول مباشرة.
        try {
            $meta = $this->db->query('SELECT created_at, last_login FROM users WHERE id = ? LIMIT 1', [(int) ($data['id'] ?? 0)])[0] ?? [];
            $data['created_at'] = $data['created_at'] ?? ($meta['created_at'] ?? null);
            $data['last_login'] = $data['last_login'] ?? ($meta['last_login'] ?? null);
        } catch (Throwable $e) {
            // مش حرج - بنعرض "-"
        }

        // Connected Accounts (Profile Center Phase 2): بيانات حقيقية من
        // oauth_accounts، مفيش أي Access Token أو Refresh Token أو
        // Client Secret بيتعرض - provider_user_id نفسه كمان متعرضش
        // (معرّف خارجي حساس).
        $connectedProviders = [];
        try {
            $rows = $this->db->query(
                'SELECT provider, email, created_at FROM oauth_accounts WHERE user_id = ?',
                [(int) $data['id']]
            );
            foreach ((array) $rows as $row) {
                $connectedProviders[$row['provider']] = $row;
            }
        } catch (\Throwable $e) {
            $connectedProviders = [];
        }

        $providerLabels = ['google' => 'Google', 'facebook' => 'Facebook', 'microsoft' => 'Microsoft', 'apple' => 'Apple'];
        $providersWithConnectFlow = ['google', 'facebook', 'microsoft']; // apple عبر appleCallback (POST) - غير مربوط بـ ?link=1 حاليًا
        $connectedAccountsHtml = '';
        foreach ($providerLabels as $providerKey => $label) {
            $isConnected = isset($connectedProviders[$providerKey]);
            $connectedEmail = $isConnected ? htmlspecialchars((string) ($connectedProviders[$providerKey]['email'] ?? ''), ENT_QUOTES, 'UTF-8') : '';
            $connectedAccountsHtml .= '<div class="p-row" style="display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid #eee;">';
            $connectedAccountsHtml .= '<div><strong>' . $label . '</strong>';
            $connectedAccountsHtml .= $isConnected
                ? '<div class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.connected_as') . ' ' . $connectedEmail . '</div>'
                : '<div class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.not_connected') . '</div>';
            $connectedAccountsHtml .= '</div>';
            if ($isConnected) {
                $connectedAccountsHtml .= '<button type="button" class="p-btn outline" onclick="disconnectOAuth(\'' . $providerKey . '\')">' . $this->tr('settings.disconnect') . '</button>';
            } elseif (in_array($providerKey, $providersWithConnectFlow, true)) {
                $connectedAccountsHtml .= '<a class="p-btn outline" href="/auth/' . $providerKey . '?link=1">' . $this->tr('settings.connect') . '</a>';
            } else {
                $connectedAccountsHtml .= '<span class="p-cell-muted" style="font-size:12.5px;">' . $this->tr('settings.not_available') . '</span>';
            }
            $connectedAccountsHtml .= '</div>';
        }

        // Role & Permissions (Read-only) - النظام مفهوش جدول Permissions
        // منفصل، لكن فيه نظام Feature Flags حقيقي (feature_flags +
        // user_feature_overrides) بيتحكم فعليًا في ظهور صفحات اللوحة
        // الجانبية لكل مستخدم - أقرب حاجة حقيقية لـ"Permission Details".
        // العرض هنا Read-only بالكامل - مفيش تغيير Role من صفحة البروفايل.
        $roleLabels = [
            'super_admin' => $this->tr('settings.role_super_admin'),
            'admin' => $this->tr('settings.role_admin'),
            'manager' => $this->tr('settings.role_manager'),
            'agent' => $this->tr('settings.role_agent'),
            'user' => $this->tr('settings.role_user'),
        ];
        $currentRoleKey = (string) ($data['role'] ?? 'user');
        $currentRoleLabel = $roleLabels[$currentRoleKey] ?? $currentRoleKey;

        $permissionsHtml = '';
        try {
            $featureMap = (new FeatureFlagService())->getEnabledMap((int) $data['id']);
            ksort($featureMap);
            foreach ($featureMap as $featureKey => $isEnabled) {
                $statusIcon = $isEnabled
                    ? '<span style="color:#2e7d32;">✔</span>'
                    : '<span style="color:#c0392b;">✖</span>';
                $permissionsHtml .= '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f0f0;">'
                    . '<span>' . htmlspecialchars($featureKey, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<span>' . $statusIcon . '</span></div>';
            }
        } catch (\Throwable $e) {
            $permissionsHtml = '<p class="p-cell-muted">' . $this->tr('settings.not_available') . '</p>';
        }
        if ($permissionsHtml === '') {
            $permissionsHtml = '<p class="p-cell-muted">' . $this->tr('settings.no_permissions_data') . '</p>';
        }

        // Login Activity (Read-only) - آخر 20 محاولة دخول للمستخدم الحالي
        // فقط (WHERE user_id = ?)، مفيش session_id ولا is_impersonation
        // ولا إحداثيات دقيقة (latitude/longitude) بتتعرض للمستخدم - دي
        // بيانات إدارية داخلية مش لازم تظهر في واجهة المستخدم نفسه.
        $loginActivityHtml = '';
        try {
            $history = $this->db->query(
                'SELECT status, ip_address, device_type, browser, platform, country, city, created_at
                 FROM login_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
                [(int) $data['id']]
            );
        } catch (\Throwable $e) {
            $history = [];
        }

        if (empty($history)) {
            $loginActivityHtml = '<p class="p-cell-muted">' . $this->tr('settings.no_login_activity') . '</p>';
        } else {
            $tSuccess = $this->tr('settings.login_success');
            $tFailed = $this->tr('settings.login_failed');
            $alignSide = current_dir() === 'rtl' ? 'right' : 'left';
            $loginActivityHtml .= '<table class="p-table" style="width:100%;border-collapse:collapse;">';
            $loginActivityHtml .= '<thead><tr>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_date') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_device') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_location') . '</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">IP</th>'
                . '<th style="text-align:' . $alignSide . ';padding:8px;">' . $this->tr('settings.col_status') . '</th>'
                . '</tr></thead><tbody>';
            foreach ((array) $history as $entry) {
                $when = !empty($entry['created_at']) ? date('Y-m-d H:i', strtotime($entry['created_at'])) : '-';
                $device = trim(htmlspecialchars((string) ($entry['browser'] ?? '-'), ENT_QUOTES, 'UTF-8')
                    . ' / ' . htmlspecialchars((string) ($entry['platform'] ?? '-'), ENT_QUOTES, 'UTF-8'));
                $location = trim(htmlspecialchars((string) ($entry['city'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . ' ' . htmlspecialchars((string) ($entry['country'] ?? ''), ENT_QUOTES, 'UTF-8'));
                $location = $location !== '' ? $location : $this->tr('settings.not_available');
                $ip = htmlspecialchars((string) ($entry['ip_address'] ?? '-'), ENT_QUOTES, 'UTF-8');
                $isSuccess = ($entry['status'] ?? '') === 'success';
                $statusLabel = $isSuccess
                    ? '<span style="color:#2e7d32;">✔ ' . $tSuccess . '</span>'
                    : '<span style="color:#c0392b;">✖ ' . $tFailed . '</span>';
                $loginActivityHtml .= '<tr style="border-top:1px solid #eee;">'
                    . '<td style="padding:8px;white-space:nowrap;">' . $when . '</td>'
                    . '<td style="padding:8px;">' . $device . '</td>'
                    . '<td style="padding:8px;">' . $location . '</td>'
                    . '<td style="padding:8px;direction:ltr;text-align:' . $alignSide . ';">' . $ip . '</td>'
                    . '<td style="padding:8px;">' . $statusLabel . '</td>'
                    . '</tr>';
            }
            $loginActivityHtml .= '</tbody></table>';
        }

        $firstName = htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars((string) ($data['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName = htmlspecialchars((string) ($data['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $jobTitle = htmlspecialchars((string) ($data['job_title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $bio = htmlspecialchars((string) ($data['bio'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCode = htmlspecialchars((string) ($data['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        // اسم الدور الداخلي (manager...) كان بيظهر للعميل بالإنجليزي - نعرض اسم مفهوم
        $roleLabels = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'manager' => 'صاحب الحساب', 'agent' => 'موظف', 'agency_owner' => 'صاحب وكالة', 'client' => 'عميل'];
        $role = htmlspecialchars($roleLabels[$data['role'] ?? ''] ?? (string) ($data['role'] ?? ''), ENT_QUOTES, 'UTF-8');
        $accountId = htmlspecialchars((string) ($data['id'] ?? '-'), ENT_QUOTES, 'UTF-8');
        $accountStatusRaw = preg_replace('/[^a-z_]/', '', strtolower((string) ($data['status'] ?? (($data['is_active'] ?? 1) ? 'active' : 'suspended')))) ?: 'active';
        $accountStatus = htmlspecialchars($accountStatusRaw, ENT_QUOTES, 'UTF-8');
        $memberSince = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : '-';
        $lastLogin = !empty($data['last_login']) ? date('Y-m-d H:i', strtotime($data['last_login'])) : $this->tr('settings.never_logged_in');
        $lastPasswordChange = !empty($data['password_changed_at']) ? date('Y-m-d', strtotime($data['password_changed_at'])) : $this->tr('settings.unknown');
        $avatarUrl = htmlspecialchars((string) ($data['avatar_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $initials = htmlspecialchars(mb_strtoupper(mb_substr($data['first_name'] ?? ($data['company_name'] ?? '؟'), 0, 1)), ENT_QUOTES, 'UTF-8');
        $language = htmlspecialchars((string) ($data['language'] ?: UI_DEFAULT_LANGUAGE), ENT_QUOTES, 'UTF-8');
        $timezone = htmlspecialchars((string) ($data['timezone'] ?: 'UTC'), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars((string) ($data['api_token'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCodeRaw = strtoupper((string) ($data['country_code'] ?? ''));
        $currencyRaw = strtoupper((string) ($data['currency'] ?? ''));

        // Global-first (Profile Center Phase 1): قائمة اللغات المعروضة =
        // اللغات اللي فعليًا عندها ملف ترجمة (UI_SUPPORTED_LANGUAGES)، مش
        // قيم ثابتة (ar/en بس زي الكود القديم) رغم وجود fr.php و de.php
        // فعليًا في app/Lang/.
        $langLabels = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'de' => 'Deutsch'];
        $languageOptionsHtml = '';
        foreach (UI_SUPPORTED_LANGUAGES as $langCode) {
            $label = htmlspecialchars($langLabels[$langCode] ?? strtoupper($langCode), ENT_QUOTES, 'UTF-8');
            $languageOptionsHtml .= '<option value="' . $langCode . '"' . $this->selected($language, $langCode) . '>' . $label . '</option>';
        }

        // Global-first: كل IANA timezones الحقيقية من PHP نفسها (DateTimeZone)،
        // مجمّعة حسب المنطقة (Africa/, Asia/, Europe/...) بدل 4 خيارات ثابتة.
        $timezoneOptionsHtml = '<option value="UTC"' . $this->selected($timezone, 'UTC') . '>UTC</option>';
        $tzByRegion = [];
        foreach (self::ianaTimezones() as $tz) {
            $parts = explode('/', $tz, 2);
            $region = count($parts) > 1 ? $parts[0] : 'Other';
            $tzByRegion[$region][] = $tz;
        }
        ksort($tzByRegion);
        foreach ($tzByRegion as $region => $zones) {
            $timezoneOptionsHtml .= '<optgroup label="' . htmlspecialchars($region, ENT_QUOTES, 'UTF-8') . '">';
            foreach ($zones as $tz) {
                $timezoneOptionsHtml .= '<option value="' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '"' . $this->selected($timezone, $tz) . '>' . htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            $timezoneOptionsHtml .= '</optgroup>';
        }

        // Global-first: قائمة دول ISO 3166-1 حقيقية بدل مربع نص حر
        $countryOptionsHtml = '<option value="">-</option>';
        foreach (self::isoCountries() as $code => $name) {
            $countryOptionsHtml .= '<option value="' . $code . '"' . $this->selected($countryCodeRaw, $code) . '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' (' . $code . ')</option>';
        }

        // Currency Preference (Profile) - قائمة ISO 4217، مش عملة الفوترة
        $currencyOptionsHtml = '<option value="">-</option>';
        foreach (self::isoCurrencies() as $code => $name) {
            $currencyOptionsHtml .= '<option value="' . $code . '"' . $this->selected($currencyRaw, $code) . '>' . $code . ' - ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        // نصوص الصفحة كلها عن طريق نظام الترجمة
        $tTabProfile = $this->tr('settings.tab.profile');
        $tTabSecurity = $this->tr('settings.tab.security');
        $tTabNotifications = $this->tr('settings.tab.notifications');
        $tTabApi = $this->tr('settings.tab.api');
        $tTabIntegrations = $this->tr('settings.tab.integrations');
        $tTabsAriaLabel = $this->tr('settings.tabs_aria_label');
        $tIntegrationsTitle = $this->tr('settings.integrations_title');
        $tIntegrationsDesc = $this->tr('settings.integrations_desc');
        $tIntegrationsListHint = $this->tr('settings.integrations_list_hint');
        $tIntegrationsManageBtn = $this->tr('settings.integrations_manage_btn');
        $tTabBilling = $this->tr('settings.tab.billing');
        $tTabAudit = $this->tr('settings.tab.audit');
        $tTabWorkspace = $this->tr('settings.tab.workspace');
        $tTabTeam = $this->tr('settings.tab.team');
        $tTabGeneral = $this->tr('settings.tab.general');
        $tTabConnected = $this->tr('settings.tab.connected');
        $tTabActivity = $this->tr('settings.tab.activity');
        $tTabPermissions = $this->tr('settings.tab.permissions');
        $tConnectedTitle = $this->tr('settings.tab.connected');
        $tActivityTitle = $this->tr('settings.tab.activity');
        $tPermissionsTitle = $this->tr('settings.tab.permissions');
        $tCurrentRoleLabel = $this->tr('settings.current_role');
        $tFeatureAccessLabel = $this->tr('settings.feature_access');
        $tFeatureAccessDesc = $this->tr('settings.feature_access_desc');
        $tPrivacyTitle = $this->tr('settings.privacy_title');
        $tPrivacyDesc = $this->tr('settings.privacy_desc');
        $tViewPrivacyPolicy = $this->tr('settings.view_privacy_policy');
        $tDataExportTitle = $this->tr('settings.data_export_title');
        $tDataExportDesc = $this->tr('settings.data_export_desc');
        $tRequestExport = $this->tr('settings.request_export');
        $tAvatarTitle = $this->tr('settings.avatar.title');
        $tChangePhoto = $this->tr('settings.avatar.change');
        $tAvatarHint = $this->tr('settings.avatar.hint');
        $tAccountInfo = $this->tr('settings.account_info');
        $tEmail = $this->tr('settings.email');
        $tRole = $this->tr('settings.role');
        $tAccountId = $this->tr('settings.account_id');
        $tAccountStatus = $this->tr('settings.account_status');
        $tLastLogin = $this->tr('settings.last_login');
        $tMemberSince = $this->tr('settings.member_since');
        $tEditData = $this->tr('settings.edit_data');
        $tFirstName = $this->tr('settings.first_name');
        $tLastName = $this->tr('settings.last_name');
        $tDisplayName = $this->tr('settings.display_name');
        $tDisplayNameHint = $this->tr('settings.display_name_hint');
        $tCompanyName = $this->tr('settings.company_name');
        $tJobTitle = $this->tr('settings.job_title');
        $tBio = $this->tr('settings.bio');
        $tBioHint = $this->tr('settings.bio_hint');
        $tPhone = $this->tr('settings.phone');
        $tCountryCode = $this->tr('settings.country_code');
        $tCurrency = $this->tr('settings.currency');
        $tSaveChanges = $this->tr('settings.save_changes');
        $tCancel = $this->tr('settings.cancel');
        $tSaving = $this->tr('settings.saving');
        $tChangePassword = $this->tr('settings.change_password');
        $tCurrentPassword = $this->tr('settings.current_password');
        $tNewPassword = $this->tr('settings.new_password');
        $tUpdatePassword = $this->tr('settings.update_password');
        $tPasswordStatus = $this->tr('settings.password_status');
        $tPasswordSet = $this->tr('settings.password_set');
        $tLastPasswordChange = $this->tr('settings.last_password_change');
        $t2FATitle = $this->tr('settings.2fa_title');
        $t2FANotConfigured = $this->tr('settings.2fa_not_configured');
        $tfaEnabled = (bool) ($data['two_factor_enabled'] ?? false);
        $tfaDisabledDisplay = $tfaEnabled ? 'none' : 'block';
        $tfaEnabledDisplay = $tfaEnabled ? 'block' : 'none';
        $tTwoFactorDesc = $this->tr('settings.tfa_desc');
        $tEnableTwoFactor = $this->tr('settings.tfa_enable');
        $tDisableTwoFactor = $this->tr('settings.tfa_disable');
        $tTwoFactorSetupHint = $this->tr('settings.tfa_setup_hint');
        $tSetupKeyLabel = $this->tr('settings.tfa_setup_key');
        $tConfirmCodeLabel = $this->tr('settings.tfa_confirm_code');
        $tConfirmAndEnable = $this->tr('settings.tfa_confirm_enable');
        $tRecoveryCodesHint = $this->tr('settings.tfa_recovery_hint');
        $tSavedRecoveryCodes = $this->tr('settings.tfa_saved_codes');
        $tTwoFactorEnabledLabel = $this->tr('settings.tfa_enabled_label');
        $tConfirmPasswordFor2FA = $this->tr('settings.tfa_confirm_password');
        $tRegenerateRecoveryCodes = $this->tr('settings.regenerate_recovery_codes');
        $tRegenRecoveryHint = $this->tr('settings.recovery_codes_hint');
        $tRegenRecoveryConfirm = $this->trJs('settings.js.regenerate_recovery_confirm');
        $tRegenRecoveryDone = $this->trJs('settings.js.recovery_codes_regenerated');
        $tRegenRecoveryFailed = $this->trJs('settings.js.recovery_codes_failed');
        $tRecoveryNeedTotp = $this->trJs('settings.js.recovery_need_totp');
        $t2FANotConfiguredHint = $this->tr('settings.2fa_not_configured_hint');
        $tReEnroll2FA = $this->tr('settings.tfa_re_enroll');
        $tReEnrollHint = $this->tr('settings.tfa_re_enroll_hint');
        $tReEnrollBtn = $this->tr('settings.tfa_re_enroll_btn');
        $tReEnrollPasswordLabel = $this->tr('settings.tfa_re_enroll_password');
        $tReEnrollCodeLabel = $this->tr('settings.tfa_re_enroll_code');
        $tReEnrollStart = $this->tr('settings.tfa_re_enroll_start');
        $tReEnrollConfirm = $this->trJs('settings.js.tfa_re_enroll_confirm');
        $tReEnrollRequired = $this->trJs('settings.js.tfa_re_enroll_required');
        $tReEnrollStarted = $this->trJs('settings.js.tfa_re_enroll_started');
        $tSessionsTitle = $this->tr('settings.sessions_title');
        $tSessionsHint = $this->tr('settings.sessions_hint');
        $tLogoutOthers = $this->tr('settings.logout_others');
        $tCurrentDevice = $this->trJs('settings.current_device');
        $tLogoutDevice = $this->trJs('settings.logout_device');
        $tRenameDevice = $this->tr('settings.rename_device');
        $tRenameDevicePlaceholder = $this->tr('settings.rename_device_placeholder');
        $tRenameDeviceRequired = $this->trJs('settings.js.rename_device_required');
        $tDeviceRenamed = $this->trJs('settings.js.device_renamed');
        $tSessionsEmpty = $this->trJs('settings.sessions_empty');
        $tSessionsLoading = $this->tr('settings.sessions_loading');
        $tNotifPrefs = $this->tr('settings.notif_prefs');
        $tNotifReviewsCat = $this->tr('settings.notif_cat_reviews');
        $tNotifContentCat = $this->tr('settings.notif_cat_content');
        $tNotifLeadsCat = $this->tr('settings.notif_cat_leads');
        $tNotifSystemCat = $this->tr('settings.notif_cat_system');
        $tNotifDigestDaily = $this->tr('settings.notif_digest_daily');
        $tNotifDigestWeekly = $this->tr('settings.notif_digest_weekly');
        $tNotifDigestHint = $this->tr('settings.notif_digest_hint');
        $tNotifUnavailableCats = $this->tr('settings.notif_unavailable_cats');
        $tNotifChannelNote = $this->tr('settings.notif_channel_note');
        $tSavePrefs = $this->tr('settings.save_prefs');
        $tApiKeyTitle = $this->tr('settings.api_key_title');
        $tApiKeyDesc = $this->tr('settings.api_key_desc');
        $tCopyKey = $this->tr('settings.copy_key');
        $tRegenerateKey = $this->tr('settings.regenerate_key');
        $tRegenerateWarning = $this->tr('settings.regenerate_warning');
        $tPersonalKeysTitle = $this->tr('settings.personal_keys_title');
        $tPersonalKeysDesc = $this->tr('settings.personal_keys_desc');
        $tKeyNamePlaceholder = $this->tr('settings.key_name_placeholder');
        $tCreateKey = $this->tr('settings.create_key');
        $tKeyExpiryDaysPlaceholder = $this->tr('settings.key_expiry_days_placeholder');
        $tKeyExpiryLabel = $this->tr('settings.key_expiry_label');
        $tKeyExpiresNever = $this->tr('settings.key_expires_never');
        $tKeyScopesTitle = $this->tr('settings.key_scopes_title');
        // نسخ JSON للاستخدام جوه JavaScript (Views/Settings/script.php) - النسخ العادية
        // فوق بتتحط في HTML. من غير كده النص العربي كان بيتكتب في الـ JS من غير
        // علامات تنصيص (esc(لا ينتهي أبدًا)) فكل سكريبت صفحة الإعدادات كان بيقع.
        $jsKeyExpiryLabel = $this->trJs('settings.key_expiry_label');
        $jsKeyExpiresNever = $this->trJs('settings.key_expires_never');
        $jsKeyScopesTitle = $this->trJs('settings.key_scopes_title');
        $tKeyScopesHint = $this->tr('settings.key_scopes_hint');
        $tKeysLoading = $this->tr('settings.keys_loading');
        $tKeysEmpty = $this->trJs('settings.keys_empty');
        $tRevoke = $this->trJs('settings.revoke');
        $tRevoked = $this->trJs('settings.revoked');
        $tNeverUsed = $this->trJs('settings.never_used');
        $tAccountPrefs = $this->tr('settings.account_prefs');
        $tBillingPlanTitle = $this->tr('settings.billing_plan_title');
        $tBillingLoading = $this->tr('settings.billing_loading');
        $tBillingNoPlan = $this->trJs('settings.billing_no_plan');
        $tBillingCancelPlan = $this->trJs('settings.billing_cancel_plan');
        $tBillingInvoicesTitle = $this->tr('settings.billing_invoices_title');
        $tBillingInvoicesEmpty = $this->trJs('settings.billing_invoices_empty');
        $tBillingWalletTitle = $this->tr('settings.billing_wallet_title');
        $tBillingWalletEmpty = $this->tr('settings.billing_wallet_empty');
        $tAuditTitle = $this->tr('settings.audit_title');
        $tAuditDesc = $this->tr('settings.audit_desc');
        $tAuditSearchPlaceholder = $this->tr('settings.audit_search_placeholder');
        $tAuditFrom = $this->tr('settings.audit_from');
        $tAuditTo = $this->tr('settings.audit_to');
        $tAuditFilterBtn = $this->tr('settings.audit_filter_btn');
        $tAuditColAction = $this->tr('settings.audit_col_action');
        $tAuditColObject = $this->tr('settings.audit_col_object');
        $tAuditColTime = $this->tr('settings.audit_col_time');
        $tAuditColResult = $this->tr('settings.audit_col_result');
        $tAuditAllResults = $this->tr('settings.audit_all_results');
        $tAuditResultSuccess = $this->tr('settings.audit_result_success');
        $tAuditResultFailed = $this->tr('settings.audit_result_failed');
        $tAuditActionPlaceholder = $this->tr('settings.audit_action_placeholder');
        $tAuditExportBtn = $this->tr('settings.audit_export_btn');
        $tAuditExporting = $this->trJs('settings.audit_exporting');
        $tAuditExportFailed = $this->trJs('settings.js.audit_export_failed');
        $tAuditEmpty = $this->trJs('settings.audit_empty');
        $tAuditPrev = $this->tr('settings.audit_prev');
        $tAuditNext = $this->tr('settings.audit_next');
        $tWorkspaceTitle = $this->tr('settings.workspace_title');
        $tWorkspaceScopeNote = $this->tr('settings.workspace_scope_note');
        $tWorkspaceName = $this->tr('settings.workspace_name');
        $tWorkspaceIndustry = $this->tr('settings.workspace_industry');
        $tWorkspaceCountry = $this->tr('settings.workspace_country');
        $tWorkspaceTimezone = $this->tr('settings.workspace_timezone');
        $tWorkspaceLanguage = $this->tr('settings.workspace_language');
        $tWorkspaceLogo = $this->tr('settings.workspace_logo');
        $tWorkspaceReadOnlyNote = $this->tr('settings.workspace_readonly_note');
        $tTeamTitle = $this->tr('settings.team_title');
        $tTeamPermissionNote = $this->tr('settings.team_permission_note');
        $tInviteEmail = $this->tr('settings.invite_email');
        $tInviteRole = $this->tr('settings.invite_role');
        $tInviteSend = $this->tr('settings.invite_send');
        $tPendingInvitesTitle = $this->tr('settings.pending_invites_title');
        $tMembersTitle = $this->tr('settings.members_title');
        $tPermissionMatrixTitle = $this->tr('settings.permission_matrix_title');
        $tInterfaceLang = $this->tr('settings.interface_lang');
        $tTimezone = $this->tr('settings.timezone');
        $tSaveSettings = $this->tr('settings.save_settings');
        $tDangerZone = $this->tr('settings.danger_zone');
        $tDeleteWarning = $this->tr('settings.delete_warning');
        $tDeleteAccount = $this->tr('settings.delete_account');
        $tDeactivateTitle = $this->tr('settings.deactivate_title');
        $tDeactivateWarning = $this->tr('settings.deactivate_warning');
        $tDeactivateAccount = $this->tr('settings.deactivate_account');
        $tConfirmPasswordLabel = $this->tr('settings.confirm_password_label');
        $tConfirmEmailLabel = $this->tr('settings.confirm_email_label');
        $tConfirmEmailHint = $this->tr('settings.confirm_email_hint');
        $tLeaveWorkspaceTitle = $this->tr('settings.leave_workspace_title');
        $tLeaveWorkspaceWarning = $this->tr('settings.leave_workspace_warning');
        $tLeaveWorkspaceBtn = $this->tr('settings.leave_workspace_btn');

        // Phase 12 (Scoped CSRF): التوكن اللي بيتبعت مع كل طلب JSON من
        // أي تاب في الصفحة - بيتبني فوق كائن Csrf الموجود بالفعل.
        $csrfToken = class_exists('Csrf') ? Csrf::token() : '';

        // Phase 16A (API Key Scopes): صفّ من checkbox لكل صلاحية مدعومة.
        // كلها متحددة افتراضيًا عشان المستخدم يظبطها زي ما يحب (GitHub
        // Fine-grained PAT style). لو المستخدم سابها كلها، المفتاح بينشأ
        // بكل الصلاحيات (نفس سلوك المفاتيح القديمة).
        $keyScopesCheckboxes = '';
        foreach (UserApiKey::SCOPES as $scopeKey => $scopeLabel) {
            $scopeLabelEscaped = htmlspecialchars($scopeLabel, ENT_QUOTES, 'UTF-8');
            $keyScopesCheckboxes .= '<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">'
                . '<input type="checkbox" class="key-scope-cb" value="' . $scopeKey . '" checked> '
                . '<code style="direction:ltr;font-size:12px;">' . $scopeKey . '</code>'
                . '<span class="p-cell-muted" style="font-size:12px;">— ' . $scopeLabelEscaped . '</span>'
                . '</label>';
        }

        $body = include APP_PATH . '/Views/Settings/header.php';
        $body .= include APP_PATH . '/Views/Settings/profile.php';
        $body .= include APP_PATH . '/Views/Settings/security.php';
        $body .= include APP_PATH . '/Views/Settings/notifications.php';
        $body .= include APP_PATH . '/Views/Settings/api.php';
        $body .= include APP_PATH . '/Views/Settings/integrations.php';
        $body .= include APP_PATH . '/Views/Settings/billing.php';
        $body .= include APP_PATH . '/Views/Settings/audit.php';
        $body .= include APP_PATH . '/Views/Settings/workspace.php';
        $body .= include APP_PATH . '/Views/Settings/team.php';
        $body .= include APP_PATH . '/Views/Settings/general.php';
        $body .= include APP_PATH . '/Views/Settings/connected.php';
        $body .= include APP_PATH . '/Views/Settings/activity.php';
        $body .= include APP_PATH . '/Views/Settings/permissions.php';

        $tUploading = $this->trJs('settings.js.uploading');
        $tPhotoChanged = $this->trJs('settings.js.photo_changed');
        $tPhotoUploadFailed = $this->trJs('settings.js.photo_upload_failed');
        $tConnectionFailed = $this->trJs('settings.js.connection_failed');
        $tChangesSaved = $this->trJs('settings.js.changes_saved');
        $tSaveFailed = $this->trJs('settings.js.save_failed');
        $tPasswordUpdated = $this->trJs('settings.js.password_updated');
        $tUpdateFailed = $this->trJs('settings.js.update_failed');
        $tSessionsLoadFailed = $this->trJs('settings.js.sessions_load_failed');
        $tPasswordRequired = $this->trJs('settings.js.password_required');
        $tTfaEnabledToast = $this->trJs('settings.js.tfa_enabled');
        $tTfaDisabledToast = $this->trJs('settings.js.tfa_disabled');
        $tTfaDisableConfirm = $this->trJs('settings.js.tfa_disable_confirm');
        $tDisconnectConfirm = $this->trJs('settings.js.disconnect_confirm');
        $tConnectedSuccess = $this->trJs('settings.js.connected_success');
        $tDisconnectFailed = $this->trJs('settings.js.disconnect_failed');
        $tAccountDisconnected = $this->trJs('settings.js.account_disconnected');
        $tExportRequested = $this->trJs('settings.js.export_requested');
        $tExportRequestedStatus = $this->trJs('settings.export_status_requested');
        $tExportProcessingStatus = $this->trJs('settings.export_status_processing');
        $tExportReadyStatus = $this->trJs('settings.export_status_ready');
        $tExportFailedStatus = $this->trJs('settings.export_status_failed');
        $tDownload = $this->trJs('settings.download');
        $tLogoutDeviceConfirm = $this->trJs('settings.js.logout_device_confirm');
        $tLogoutOthersConfirm = $this->trJs('settings.js.logout_others_confirm');
        $tDeviceLoggedOut = $this->trJs('settings.js.device_logged_out');
        $tOthersLoggedOut = $this->trJs('settings.js.others_logged_out');
        $tKeyCreated = $this->trJs('settings.js.key_created');
        $tKeysLoadFailed = $this->trJs('settings.js.keys_load_failed');
        $tRevokeConfirm = $this->trJs('settings.js.revoke_confirm');
        $tKeyRevoked = $this->trJs('settings.js.key_revoked');
        $tBillingLoadFailed = $this->trJs('settings.js.billing_load_failed');
        $tCancelPlanConfirm = $this->trJs('settings.js.cancel_plan_confirm');
        $tPlanCancelled = $this->trJs('settings.js.plan_cancelled');
        $tCancelFailed = $this->trJs('settings.js.cancel_failed');
        $tPlanNameLabel = $this->trJs('settings.js.plan_name_label');
        $tPlanStatusLabel = $this->trJs('settings.js.plan_status_label');
        $tPlanPriceLabel = $this->trJs('settings.js.plan_price_label');
        $tPlanExpiryLabel = $this->trJs('settings.js.plan_expiry_label');
        $tWalletBalanceLabel = $this->trJs('settings.js.wallet_balance_label');
        $tAuditLoadFailed = $this->trJs('settings.js.audit_load_failed');
        $tAuditPageOf = $this->trJs('settings.js.audit_page_of');
        $tLoadingJs = $this->trJs('settings.keys_loading');
        $tWorkspaceSaved = $this->trJs('settings.js.workspace_saved');
        $tWorkspaceSaveFailed = $this->trJs('settings.js.workspace_save_failed');
        $tLogoUpdated = $this->trJs('settings.js.logo_updated');
        $tLogoFailed = $this->trJs('settings.js.logo_failed');
        $tInviteSent = $this->trJs('settings.js.invite_sent');
        $tInviteFailed = $this->trJs('settings.js.invite_failed');
        $tInviteRevoked = $this->trJs('settings.js.invite_revoked');
        $tRevokeInviteConfirm = $this->trJs('settings.js.revoke_invite_confirm');
        $tRoleChanged = $this->trJs('settings.js.role_changed');
        $tRoleChangeFailed = $this->trJs('settings.js.role_change_failed');
        $tMemberDeactivated = $this->trJs('settings.js.member_deactivated');
        $tMemberReactivated = $this->trJs('settings.js.member_reactivated');
        $tMemberRemoved = $this->trJs('settings.js.member_removed');
        $tRemoveMemberConfirm = $this->trJs('settings.js.remove_member_confirm');
        $tDeactivateMemberConfirm = $this->trJs('settings.js.deactivate_member_confirm');
        $tCopyLink = $this->trJs('settings.js.copy_link');
        $tLinkCopied = $this->trJs('settings.js.link_copied');
        $tNoInvites = $this->trJs('settings.js.no_invites');
        $tWorkspaceLoadFailed = $this->trJs('settings.js.workspace_load_failed');
        $tMembersLoadFailed = $this->trJs('settings.js.members_load_failed');
        $tLeaveWorkspaceConfirm = $this->trJs('settings.js.leave_workspace_confirm');
        $tLeftWorkspace = $this->trJs('settings.js.left_workspace');
        $tLeaveFailed = $this->trJs('settings.js.leave_failed');
        $tDeactivateBtnLabel = $this->trJs('settings.js.deactivate_btn_label');
        $tRemoveBtnLabel = $this->trJs('settings.js.remove_btn_label');
        $tPrefsSaved = $this->trJs('settings.js.prefs_saved');
        $tKeyCopied = $this->trJs('settings.js.key_copied');
        $tRegenerateConfirm = $this->trJs('settings.js.regenerate_confirm');
        $tKeyRegenerated = $this->trJs('settings.js.key_regenerated');
        $tGenerateFailed = $this->trJs('settings.js.generate_failed');
        $tSettingsSaved = $this->trJs('settings.js.settings_saved');
        $tDeleteConfirm1 = $this->trJs('settings.js.delete_confirm1');
        $tDeleteConfirm2 = $this->trJs('settings.js.delete_confirm2');
        $tDeleteConfirmSubscription = $this->trJs('settings.js.delete_confirm_subscription');
        $tAccountDeleted = $this->trJs('settings.js.account_deleted');
        $tDeleteFailed = $this->trJs('settings.js.delete_failed');
        $tDeactivateConfirm = $this->trJs('settings.js.deactivate_confirm');
        $tAccountDeactivated = $this->trJs('settings.js.account_deactivated');
        $tDeactivateFailed = $this->trJs('settings.js.deactivate_failed');

        $script = include APP_PATH . '/Views/Settings/script.php';

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_settings', $this->tr('sidebar.settings'), $this->tr('settings.page_subtitle'), $body, $script);
    }

    /** يبني HTML الصورة الشخصية الحالية (صورة حقيقية لو موجودة، وإلا حرف أول الاسم) */
    private function avatarInnerHtml(string $avatarUrl, string $initials): string
    {
        if ($avatarUrl !== '') {
            return '<img src="' . $avatarUrl . '" alt="الصورة الشخصية" style="width:100%;height:100%;object-fit:cover;">';
        }
        return $initials;
    }

    /** POST /api/user/avatar - رفع/تغيير الصورة الشخصية */
    public function uploadAvatar(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (empty($_FILES['avatar'])) {
            return $this->error('لم يتم اختيار أي صورة', 422);
        }

        $handler = new AvatarUploadHandler();
        $result = $handler->upload($_FILES['avatar'], (int) $user->getAttribute('id'), $user->getAttribute('avatar_url'));

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر رفع الصورة', 422);
        }

        $user->setAttribute('avatar_url', $result['url']);
        if ($user->save() === false) {
            return $this->error('اترفعت الصورة بس تعذر حفظ الرابط', 500);
        }

        return $this->success(['avatar_url' => $result['url']], 'تم تحديث الصورة الشخصية');
    }

    /** يرجّع ' selected' لو القيمتين متطابقتين، مفيدة لبناء HTML بسيط */
    private function selected(string $current, string $option): string
    {
        return $current === $option ? ' selected' : '';
    }

    private static function isoCountries(): array
    {
        return InternationalSettingsService::countries();
    }

    /**
     * قائمة مرجعية ISO 4217 (العملات الأكثر استخدامًا عالميًا وإقليميًا).
     * هذه Currency Preference خاصة بعرض الملف الشخصي فقط، ولا تُغيّر
     * أو تتقاطع مع عملة الفوترة/الاشتراك في subscriptions/invoices.
     * @return array<string,string>
     */
    public static function isoCurrencies(): array
    {
        return [
            'USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'EGP' => 'Egyptian Pound',
            'SAR' => 'Saudi Riyal', 'AED' => 'UAE Dirham', 'QAR' => 'Qatari Riyal', 'KWD' => 'Kuwaiti Dinar',
            'BHD' => 'Bahraini Dinar', 'OMR' => 'Omani Rial', 'JOD' => 'Jordanian Dinar', 'LBP' => 'Lebanese Pound',
            'IQD' => 'Iraqi Dinar', 'MAD' => 'Moroccan Dirham', 'DZD' => 'Algerian Dinar', 'TND' => 'Tunisian Dinar',
            'LYD' => 'Libyan Dinar', 'TRY' => 'Turkish Lira', 'ILS' => 'Israeli New Shekel', 'JPY' => 'Japanese Yen',
            'CNY' => 'Chinese Yuan', 'HKD' => 'Hong Kong Dollar', 'SGD' => 'Singapore Dollar', 'KRW' => 'South Korean Won',
            'INR' => 'Indian Rupee', 'PKR' => 'Pakistani Rupee', 'BDT' => 'Bangladeshi Taka', 'IDR' => 'Indonesian Rupiah',
            'MYR' => 'Malaysian Ringgit', 'THB' => 'Thai Baht', 'VND' => 'Vietnamese Dong', 'PHP' => 'Philippine Peso',
            'AUD' => 'Australian Dollar', 'NZD' => 'New Zealand Dollar', 'CAD' => 'Canadian Dollar', 'CHF' => 'Swiss Franc',
            'SEK' => 'Swedish Krona', 'NOK' => 'Norwegian Krone', 'DKK' => 'Danish Krone', 'PLN' => 'Polish Zloty',
            'CZK' => 'Czech Koruna', 'HUF' => 'Hungarian Forint', 'RON' => 'Romanian Leu', 'RUB' => 'Russian Ruble',
            'UAH' => 'Ukrainian Hryvnia', 'ZAR' => 'South African Rand', 'NGN' => 'Nigerian Naira', 'KES' => 'Kenyan Shilling',
            'GHS' => 'Ghanaian Cedi', 'ETB' => 'Ethiopian Birr', 'BRL' => 'Brazilian Real', 'MXN' => 'Mexican Peso',
            'ARS' => 'Argentine Peso', 'CLP' => 'Chilean Peso', 'COP' => 'Colombian Peso', 'PEN' => 'Peruvian Sol',
        ];
    }

    /**
     * قائمة كاملة IANA Time Zones من PHP نفسه (DateTimeZone) - مش قائمة
     * مكتوبة يدويًا، فتبقى دايمًا مطابقة لبيانات tzdata الفعلية ومحدّثة
     * تلقائيًا مع أي تحديث PHP/OS، وتراعي Daylight Saving Time تلقائيًا
     * لأنها بتستخدم identifier حقيقي (زي Africa/Cairo) مش UTC+2 ثابت.
     * @return string[]
     */
    private static function ianaTimezones(): array
    {
        return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
    }


    public function getSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            'language' => $user->getAttribute('language'),
            'timezone' => $user->getAttribute('timezone'),
        ]);
    }

    /** POST /profile/settings و PUT /api/user/settings */
    public function updateSettings(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if ($this->has('language') && $this->get('language') !== '' && !in_array($this->get('language'), UI_SUPPORTED_LANGUAGES, true)) {
            return $this->error('لغة غير مدعومة', 422, ['language' => ['يجب اختيار لغة من القائمة المتاحة فعليًا']]);
        }

        // IANA timezone حقيقي بدل قائمة يدوية ممكن تبقى قديمة - نتحقق
        // منها عن طريق PHP نفسه (DateTimeZone).
        if ($this->has('timezone') && $this->get('timezone') !== '' && !in_array($this->get('timezone'), self::ianaTimezones(), true)) {
            return $this->error('منطقة زمنية غير صحيحة', 422, ['timezone' => ['يجب اختيار IANA Timezone صحيحة']]);
        }

        foreach (['language', 'timezone'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث الإعدادات', 500);
        }

        $_SESSION['user']=$user->toArray();
        if($this->has('language') && $this->get('language')!==''){$_COOKIE['tf_lang']=$this->get('language');if(!headers_sent())setcookie('tf_lang',$this->get('language'),['expires'=>time()+31536000,'path'=>'/','samesite'=>'Lax']);}
        return $this->success([], 'تم تحديث الإعدادات');
    }

    /**
     * DELETE /api/user/oauth/{provider} - فصل حساب OAuth مربوط بالمستخدم
     * الحالي فقط (مفيش أي user_id بييجي من الـFrontend، بنستخدم المستخدم
     * الحالي من الـSession زي باقي الكونترولر). لا يوجد Access/Refresh
     * Token مخزّن في oauth_accounts أصلاً، فمفيش حاجة حساسة نحذفها غير
     * الربط نفسه.
     */
    public function disconnectOAuth(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $provider = (string) ($params['provider'] ?? '');
        if (!in_array($provider, ['google', 'apple', 'facebook', 'microsoft'], true)) {
            return $this->error('منصة غير معروفة', 422);
        }

        $this->db->exec(
            'DELETE FROM oauth_accounts WHERE user_id = ? AND provider = ?',
            [(int) $user->getAttribute('id'), $provider]
        );

        return $this->success([], 'تم فصل الحساب');
    }

    /**
     * POST /api/user/2fa/setup - الخطوة الأولى: توليد secret جديد وحفظه
     * (لكن two_factor_enabled يفضل 0 لحد ما يتأكد بكود صحيح في enable()).
     * ده بيمنع حالة إن مستخدم يولّد secret وينساه من غير ما يفعّل 2FA
     * فعليًا، ومحدش يقدر يقفل حسابه بالغلط.
     */
    public function setupTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $secret = AuthController::generateTotpSecret();
        $user->setAttribute('two_factor_secret', $secret);
        $user->setAttribute('two_factor_enabled', 0);
        if ($user->save() === false) {
            return $this->error('تعذر بدء إعداد التحقق بخطوتين', 500);
        }

        $issuer = 'Tourfecto';
        $email = (string) $user->getAttribute('email');
        $otpauthUri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';

        return $this->success(['secret' => $secret, 'otpauth_uri' => $otpauthUri]);
    }

    /**
     * POST /api/user/2fa/enable - الخطوة الثانية: تأكيد إن المستخدم فعلًا
     * ضبط الـsecret صح في تطبيق Authenticator بتاعه (بإدخال كود حقيقي)،
     * قبل ما نفعّل 2FA فعليًا على تسجيل الدخول. بعد كده بنولّد Recovery
     * Codes وبنعرضهم مرة واحدة بس (نص عادي) - بعدها بيتخزنوا مُشفّرين فقط.
     */
    public function enableTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $secret = (string) $user->getAttribute('two_factor_secret');
        if ($secret === '') {
            return $this->error('لازم تبدأ الإعداد أولًا', 422);
        }

        if (!$this->validate(['code' => 'required'])) {
            return $this->error('أدخل كود التحقق', 422, $this->getErrors());
        }

        if (!AuthController::verifyTotpCode($secret, (string) $this->get('code'))) {
            return $this->error('كود التحقق غير صحيح', 401);
        }

        $recoveryCodes = AuthController::generateRecoveryCodes();
        $hashedCodes = array_map(static fn ($code) => password_hash($code, PASSWORD_DEFAULT), $recoveryCodes);

        $user->setAttribute('two_factor_enabled', 1);
        $user->setAttribute('two_factor_recovery_codes', json_encode($hashedCodes));
        $user->setAttribute('two_factor_confirmed_at', date('Y-m-d H:i:s'));
        if ($user->save() === false) {
            return $this->error('تعذر تفعيل التحقق بخطوتين', 500);
        }

        // Recovery Codes بتترجع نص عادي هنا مرة واحدة بس - مش هيبقى فيه
        // طريقة تانية تسترجعها تاني، بالظبط زي أي SaaS تاني.
        return $this->success(['recovery_codes' => $recoveryCodes], 'تم تفعيل التحقق بخطوتين');
    }

    /** POST /api/user/2fa/disable - لازم كلمة المرور، مش بس زرار */
    public function disableTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['password' => 'required'])) {
            return $this->error('يجب إدخال كلمة المرور', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('password'))) {
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        $user->setAttribute('two_factor_enabled', 0);
        $user->setAttribute('two_factor_secret', null);
        $user->setAttribute('two_factor_recovery_codes', null);
        $user->setAttribute('two_factor_confirmed_at', null);
        if ($user->save() === false) {
            return $this->error('تعذر إلغاء التحقق بخطوتين', 500);
        }

        return $this->success([], 'تم إلغاء التحقق بخطوتين');
    }

    /**
     * POST /api/user/2fa/recovery-codes/regenerate
     *
     * إعادة توليد أكواد الطوارئ (Recovery Codes) مع إبطال الدفعة القديمة
     * بالكامل - نفس سلوك GitHub/Stripe. ليه حد يطلبها؟ لو المستخدم شك إن
     * الأكواد القديمة اتكشفت (جهاز مسروق/مخترق مثلًا) أو فقدها، يعمل
     * توليد جديد يحل محل القديم فورًا فلا تصلح أكواد قديمة تاني.
     *
     * المتطلبات (أقوى من مجرد زر): كلمة المرور الحالية + كود TOTP صالح
     * (أو Recovery Code قديم صالح - عشان لو فقد التطبيق نفسه برضه يقدر
     * يسترجع). من غير كده أي شخص عنده جلسة مسروقة يقدر يبطل أكواد
     * طوارئ صاحبها وياخد أكواد جديدة بدلها.
     */
    public function regenerateRecoveryCodes(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!(bool) $user->getAttribute('two_factor_enabled')) {
            return $this->error('التحقق بخطوتين غير مُفعّل أصلًا', 422);
        }

        if (!$this->validate(['password' => 'required'])) {
            return $this->error('يجب إدخال كلمة المرور', 422, $this->getErrors());
        }
        if (!$user->verifyPassword((string) $this->get('password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerate_failed', 'failed');
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        // تحقق ثانٍ بكود TOTP صالح أو Recovery Code قديم صالح (بدل ما
        // نعتمد على كلمة المرور لوحدها).
        $verified = false;
        $secret = (string) $user->getAttribute('two_factor_secret');
        if ($secret !== '' && AuthController::verifyTotpCode($secret, (string) $this->get('code', ''))) {
            $verified = true;
        } else {
            $oldJson = $user->getAttribute('two_factor_recovery_codes');
            $oldCodes = $oldJson ? (json_decode((string) $oldJson, true) ?: []) : [];
            if (TotpService::verifyRecoveryCode($oldCodes, (string) $this->get('code', '')) !== null) {
                $verified = true;
            }
        }
        if (!$verified) {
            AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerate_failed', 'failed');
            return $this->error('كود التحقق غير صحيح - أدخل كود التطبيق أو أحد أكواد الطوارئ القديمة', 401);
        }

        $newCodes = AuthController::generateRecoveryCodes();
        $hashed = array_map(static fn ($code) => password_hash($code, PASSWORD_DEFAULT), $newCodes);

        $user->setAttribute('two_factor_recovery_codes', json_encode($hashed));
        if ($user->save() === false) {
            return $this->error('تعذر توليد أكواد طوارئ جديدة', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'recovery_codes_regenerated', 'success', 'two_factor', null, ['count' => count($newCodes)]);

        return $this->success(['recovery_codes' => $newCodes], 'تم توليد أكواد طوارئ جديدة - الأكواد القديمة أُبطلت نهائيًا');
    }

    /**
     * POST /api/user/2fa/re-enroll - إعادة ربط جهاز جديد (Lost-Device Path).
     *
     * سيناريو: المستخدم فعّل 2FA قبل كده بس ضاع منه الجهاز/التطبيق اللي
     * فيه الـ Authenticator (نزل جهاز جديد مثلًا) ومش معاه كود TOTP حالي.
     * كان الضغط الأمني الأصلي: مفيش طريق إلا كلمة المرور في disable()،
     * وده كان بيخلي أي حد معاه الجلسة + كلمة المرور يلغي 2FA من غير
     * تحقق ثانٍ إطلاقًا. هنا بقينا نطلب (كلمة المرور + Recovery Code قديم
     * صالح أو كود TOTP لو الجهاز لسه شغّال) قبل ما نولّد secret جديد -
     * نفس توازن GitHub/Stripe: التغيير محتاج دليل ملكية أقوى من مجرد
     * الجلسة.
     *
     * بعد النجاح: بنفضي الـ secret القديم والـ recovery codes، ونرجّع
     * secret جديد يدخل على نفس مرحلة Setup (زي setupTwoFactor بالظبط) -
     * وتفعيل فعلي مش بيحصل إلا بعد كود TOTP حقيقي من الجهاز الجديد في
     * enableTwoFactor().
     */
    public function reEnrollTwoFactor(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!(bool) $user->getAttribute('two_factor_enabled')) {
            return $this->error('التحقق بخطوتين غير مُفعّل أصلًا', 422);
        }

        if (!$this->validate(['password' => 'required', 'code' => 'required'])) {
            return $this->error('أدخل كلمة المرور وكود الطوارئ', 422, $this->getErrors());
        }
        if (!$user->verifyPassword((string) $this->get('password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'two_factor_re_enroll_failed', 'failed', 'two_factor');
            return $this->error('كلمة المرور غير صحيحة', 401);
        }

        // حماية من تخمين الـ Recovery Code (Brute Force) - نفس إعدادات
        // تسجيل الدخول: 5 محاولات / 15 دقيقة على المستخدم نفسه.
        if ($this->isTwoFactorRateLimited((int) $user->getAttribute('id'))) {
            return $this->error('محاولات فاشلة كتير لإعادة ربط التحقق بخطوتين. استنى 15 دقيقة.', 429);
        }

        // الدليل الثاني: Recovery Code قديم صالح، أو كود TOTP لو الجهاز
        // القديم لسه موجود (مش بنفرض إنه ضاع دايمًا).
        $verified = false;
        $secret = (string) $user->getAttribute('two_factor_secret');
        if ($secret !== '' && AuthController::verifyTotpCode($secret, (string) $this->get('code'))) {
            $verified = true;
        } else {
            $oldJson = $user->getAttribute('two_factor_recovery_codes');
            $oldCodes = $oldJson ? (json_decode((string) $oldJson, true) ?: []) : [];
            if (TotpService::verifyRecoveryCode($oldCodes, (string) $this->get('code')) !== null) {
                $verified = true;
            }
        }
        if (!$verified) {
            AuditLog::record((int) $user->getAttribute('id'), 'two_factor_re_enroll_failed', 'failed', 'two_factor');
            return $this->error('كود الطوارئ غير صحيح - أدخل كود التطبيق الحالي أو أحد أكواد الطوارئ القديمة', 401);
        }

        // فك الحظر بعد النجاح، ونفضي كل حالة 2FA القديمة عشان نبدأ نظيف
        $this->resetTwoFactorRateLimit((int) $user->getAttribute('id'));
        $newSecret = AuthController::generateTotpSecret();
        $user->setAttribute('two_factor_secret', $newSecret);
        $user->setAttribute('two_factor_enabled', 0);
        $user->setAttribute('two_factor_recovery_codes', null);
        $user->setAttribute('two_factor_confirmed_at', null);
        if ($user->save() === false) {
            return $this->error('تعذر بدء إعادة ربط التحقق بخطوتين', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'two_factor_re_enrolled', 'success', 'two_factor');

        $issuer = 'Tourfecto';
        $email = (string) $user->getAttribute('email');
        $otpauthUri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $email)
            . '?secret=' . $newSecret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';

        return $this->success(['secret' => $newSecret, 'otpauth_uri' => $otpauthUri], 'امسح الكود الجديد بالجهاز الجديد ثم أدخل كود التحقق');
    }

    /** هل المستخدم متجاوز حد محاولات 2FA (5 / 15 دقيقة)؟ */
    private function isTwoFactorRateLimited(int $userId): bool
    {
        try {
            $limiter = new RateLimiter();
            return !$limiter->check('2fa_user_' . $userId, '2fa_re_enroll', 5, 900);
        } catch (\Throwable $e) {
            Logger::error('isTwoFactorRateLimited Error', ['message' => $e->getMessage()]);
            return false; // لو فشل الفحص لأي سبب، منمنعش الاستخدام بسببه
        }
    }

    /** فك الحظر عن محاولات 2FA بعد نجاح إعادة الربط */
    private function resetTwoFactorRateLimit(int $userId): void
    {
        try {
            $limiter = new RateLimiter();
            $limiter->unblockIdentifier('2fa_user_' . $userId);
        } catch (\Throwable $e) {
            Logger::error('resetTwoFactorRateLimit Error', ['message' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/user/data-export
     * Profile Center Phase 9: طلب تصدير بيانات الحساب (Profile + Login
     * History + Connected Accounts + Sessions metadata - مش كل بيانات
     * العمل زي المواقع/الاشتراكات/CRM). بيتنفّذ Async عن طريق الـQueue
     * الموجود بالفعل، مش هنا مباشرة.
     */
    public function requestDataExport(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = (int) $user->getAttribute('id');

        // منع تكديس طلبات كتير مع بعض - لو فيه طلب لسه requested/processing، منرجعش نعمل واحد جديد
        $pending = $this->db->query(
            "SELECT id FROM data_export_requests WHERE user_id = ? AND status IN ('requested', 'processing') LIMIT 1",
            [$userId]
        );
        if (!empty($pending)) {
            return $this->error('عندك طلب تصدير بيانات قيد التنفيذ بالفعل - استنى لحد ما يخلص', 409);
        }

        $requestId = $this->db->query(
            "INSERT INTO data_export_requests (user_id, status, requested_at) VALUES (?, 'requested', NOW())",
            [$userId]
        );

        if (!$requestId) {
            return $this->error('تعذر إنشاء طلب التصدير', 500);
        }

        try {
            $queue = new QueueManager();
            $queue->push('ExportUserDataJob', ['export_request_id' => $requestId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            // لو الـQueue نفسه فشل لأي سبب، سجّل الطلب فاشل فورًا بدل ما
            // يفضل عالق على "requested" للأبد من غير أي تفسير للمستخدم
            $this->db->exec("UPDATE data_export_requests SET status = 'failed', error_message = ? WHERE id = ?", [substr($e->getMessage(), 0, 500), $requestId]);
            return $this->error('تعذر جدولة طلب التصدير', 500);
        }

        return $this->success(['request_id' => $requestId], 'تم استلام طلب التصدير - هيوصلك إشعار لما يخلص');
    }

    /** GET /api/user/data-export - طلبات المستخدم الحالي فقط */
    public function listDataExports(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $rows = $this->db->query(
            'SELECT id, status, requested_at, completed_at, expires_at, error_message
             FROM data_export_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 10',
            [(int) $user->getAttribute('id')]
        );

        foreach ($rows as &$row) {
            $row['error_message'] = !empty($row['error_message']) ? 'تعذر تجهيز الملف. حاول مرة أخرى أو تواصل مع الدعم.' : null;
            if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) <= time() && $row['status'] === 'ready') $row['status'] = 'expired';
        }
        unset($row);
        return $this->success(['exports' => $rows ?: []]);
    }

    /**
     * GET /profile/data-export/download/{id}
     * تحميل موثّق - مفيش رابط عام مباشر للملف خالص (الملف أصلًا بره
     * public_html في TOURFECTO_STORAGE). لازم يتحقق من ملكية الطلب
     * وإن حالته "ready" وإنه لسه ما انتهتش صلاحيته قبل ما يسمح بالتحميل.
     */
    public function downloadDataExport(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }

        $requestId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT * FROM data_export_requests WHERE id = ? AND user_id = ? LIMIT 1",
            [$requestId, (int) $user->getAttribute('id')]
        );

        if (empty($rows)) {
            http_response_code(404);
            echo 'طلب التصدير غير موجود';
            exit;
        }

        $export = $rows[0];
        if ($export['status'] !== 'ready' || empty($export['file_path']) || !is_file($export['file_path'])) {
            http_response_code(404);
            echo 'الملف غير جاهز أو منتهي الصلاحية';
            exit;
        }

        if (!empty($export['expires_at']) && strtotime($export['expires_at']) < time()) {
            http_response_code(410);
            echo 'انتهت صلاحية رابط التحميل';
            exit;
        }

        $safePath = ExportRetentionService::safePath((string)$export['file_path'], (int)$user->getAttribute('id'));
        if ($safePath === null) { http_response_code(404); echo 'الملف غير متاح'; exit; }
        $export['file_path'] = $safePath;
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="tourfecto-data-export.json"');
        header('Content-Length: ' . filesize($export['file_path']));
        readfile($export['file_path']);
        exit;
    }

    /** GET /profile/security */
    public function showSecurity(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/security'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['section' => 'security']);
        }

        // توحيد: نفس فورم تغيير كلمة المرور موجود في تبويب "الأمان"
        // بصفحة /profile/settings بالظبط.
        header('Location: /profile/settings?tab=security');
        exit;
    }

    /** POST /profile/security */
    public function updateSecurity(array $params = []): array
    {
        return $this->updatePassword($params);
    }

    /** GET /profile/api */
    public function showAPI(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            if ($this->isApiRequest()) {
                return $this->error('غير مسجل دخول', 401);
            }
            header('Location: /login?redirect=' . urlencode('/profile/api'));
            exit;
        }

        if ($this->isApiRequest()) {
            return $this->success(['api_token' => $user->getAttribute('api_token')]);
        }

        // توحيد: نفس المفتاح وزرار النسخ (وكمان توليد مفتاح جديد) موجودين
        // في تبويب "API" بصفحة /profile/settings.
        header('Location: /profile/settings');
        exit;
    }

    /** GET /api/user/sessions - قائمة الجلسات النشطة (أجهزة سجّلت دخول فعليًا) */
    public function listSessions(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $currentId = $_SESSION['current_refresh_token_id'] ?? null;
        $tokenModel = new RefreshToken();
        $now = time();
        $sessions = [];

        foreach ($tokenModel->where(['user_id' => (int) $user->getAttribute('id')]) as $token) {
            if ($token->getAttribute('revoked_at')) {
                continue; // ملغي - متعرضش في القائمة
            }
            if (strtotime((string) $token->getAttribute('expires_at')) <= $now) {
                continue; // منتهي فعليًا - مش جلسة نشطة
            }

            $ua = (string) ($token->getAttribute('user_agent') ?? '');
            $ip = (string) ($token->getAttribute('ip_address') ?? '');

            $sessions[] = [
                'id' => (int) $token->getAttribute('id'),
                'device_name' => $token->getAttribute('device_name') ?: 'جهاز غير معروف',
                'browser' => $this->guessBrowser($ua),
                'os' => $this->guessOS($ua),
                'ip_masked' => $this->maskIp($ip),
                'last_active' => $token->getAttribute('last_used_at'),
                'created_at' => $token->getAttribute('created_at'),
                'is_current' => $currentId !== null && (int) $token->getAttribute('id') === (int) $currentId,
            ];
        }

        // الأحدث نشاطًا الأول
        usort($sessions, fn ($a, $b) => strcmp((string) $b['last_active'], (string) $a['last_active']));

        return $this->success(['sessions' => $sessions]);
    }

    /** POST /api/user/sessions/{id}/logout - تسجيل خروج من جهاز واحد بعينه */
    public function logoutSession(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $id = (int) ($params['id'] ?? 0);
        $tokenModel = new RefreshToken();
        $token = $tokenModel->find($id);

        // لازم يكون التوكن موجود، ومملوك فعليًا للمستخدم الحالي - وإلا
        // أي مستخدم يقدر يسجّل خروج جلسات مستخدمين تانيين لمجرد تخمين ID.
        if (!$token || (int) $token->getAttribute('user_id') !== (int) $user->getAttribute('id')) {
            return $this->error('الجلسة غير موجودة', 404);
        }

        $token->revoke();

        AuditLog::record((int) $user->getAttribute('id'), 'session_logged_out', 'success', 'session', (string) $id);

        return $this->success([], 'تم تسجيل الخروج من هذا الجهاز');
    }

    /**
     * PATCH /api/user/sessions/{id}/name - إعادة تسمية جهاز/جلسة معيّنة.
     * المستخدم يقدر يسمّي الأجهزة بنفسه (بدل "جهاز غير معروف") عشان
     * يعرفها بسهولة - نفس GitHub/Apple trusted devices. الاسم بيتبعت
     * من الـFrontend، واحنا بننضّفه ونقتصر على 60 حرف.
     */
    public function renameSession(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $id = (int) ($params['id'] ?? 0);
        $tokenModel = new RefreshToken();
        $token = $tokenModel->find($id);

        // لازم يكون التوكن موجود ومملوك فعليًا للمستخدم الحالي - نفس
        // مبدأ logoutSession() لمنع IDOR.
        if (!$token || (int) $token->getAttribute('user_id') !== (int) $user->getAttribute('id')) {
            return $this->error('الجلسة غير موجودة', 404);
        }

        if ($token->getAttribute('revoked_at')) {
            return $this->error('الجلسة ملغاة', 422);
        }

        $name = trim(strip_tags((string) $this->get('device_name', '')));
        if ($name === '' || mb_strlen($name) > 60) {
            return $this->error('اسم الجهاز لازم يكون بين 1 و60 حرف', 422, ['device_name' => ['أدخل اسمًا من 1 إلى 60 حرفًا']]);
        }

        if ($token->renameDevice($name) === false) {
            return $this->error('تعذر تحديث اسم الجهاز', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'session_renamed', 'success', 'session', (string) $id);

        return $this->success([], 'تم تحديث اسم الجهاز');
    }

    /** POST /api/user/sessions/logout-others - تسجيل خروج من كل الأجهزة عدا الحالي */
    public function logoutOtherSessions(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $currentId = $_SESSION['current_refresh_token_id'] ?? null;
        $tokenModel = new RefreshToken();

        foreach ($tokenModel->where(['user_id' => (int) $user->getAttribute('id')]) as $token) {
            if ($token->getAttribute('revoked_at')) {
                continue;
            }
            if ($currentId !== null && (int) $token->getAttribute('id') === (int) $currentId) {
                continue; // متمسّش الجلسة الحالية
            }
            $token->revoke();
        }

        AuditLog::record((int) $user->getAttribute('id'), 'sessions_logged_out_others');

        return $this->success([], 'تم تسجيل الخروج من كل الأجهزة الأخرى');
    }

    /** تخمين مبسّط لاسم المتصفح من الـ User-Agent - عرض فقط، مش قرار أمني */
    private function guessBrowser(string $ua): string
    {
        if ($ua === '') {
            return '-';
        }
        if (stripos($ua, 'Edg/') !== false) {
            return 'Edge';
        }
        if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
            return 'Opera';
        }
        if (stripos($ua, 'Chrome/') !== false) {
            return 'Chrome';
        }
        if (stripos($ua, 'CriOS') !== false) {
            return 'Chrome (iOS)';
        }
        if (stripos($ua, 'Firefox/') !== false) {
            return 'Firefox';
        }
        if (stripos($ua, 'Safari/') !== false) {
            return 'Safari';
        }
        return 'غير معروف';
    }

    /** تخمين مبسّط لنظام التشغيل من الـ User-Agent - عرض فقط، مش قرار أمني */
    private function guessOS(string $ua): string
    {
        if ($ua === '') {
            return '-';
        }
        if (stripos($ua, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) {
            return 'iOS';
        }
        if (stripos($ua, 'Mac OS X') !== false) {
            return 'macOS';
        }
        if (stripos($ua, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($ua, 'Linux') !== false) {
            return 'Linux';
        }
        return 'غير معروف';
    }

    /** يخفي جزء من الـ IP قبل عرضه (مثال: 41.238.xx.xx) - مش محتاج IP كامل في الواجهة */
    private function maskIp(string $ip): string
    {
        if ($ip === '') {
            return '-';
        }
        if (strpos($ip, '.') !== false) { // IPv4
            $parts = explode('.', $ip);
            return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.xx.xx" : 'xx.xx.xx.xx';
        }
        if (strpos($ip, ':') !== false) { // IPv6
            $parts = explode(':', $ip);
            return (count($parts) >= 2 ? $parts[0] . ':' . $parts[1] : 'xx') . ':xxxx:xxxx';
        }
        return 'xx.xx.xx.xx';
    }

    /** GET /api/user/api-keys - قائمة مفاتيح API الشخصية للمستخدم الحالي */
    public function listApiKeys(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $keyModel = new UserApiKey();
        $keys = array_map(
            fn ($key) => $key->toSafeArray(),
            $keyModel->where(['user_id' => (int) $user->getAttribute('id')], [], 0)
        );

        usort($keys, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $this->success(['keys' => $keys]);
    }

    /** POST /api/user/api-keys - إنشاء مفتاح جديد (المفتاح الخام بيترجع مرة واحدة بس هنا) */
    public function createApiKey(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['name' => 'required|max:120'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        // صلاحية اختيارية (بالأيام): لو المستخدم حددها، المفتاح ينتهي
        // بعد المدة دي تلقائيًا - نفس Vercel/Stripe. مش إجباري أبدًا:
        // لو مترسلتش، المفتاح يفضل صالح لغاية ما يُلغى يدويًا.
        $expiresInDays = (int) ($this->get('expires_in_days', 0));
        if ($expiresInDays < 0 || $expiresInDays > 365) {
            return $this->error('فترة الصلاحية لازم تكون بين 1 و365 يوم، أو متترسلش خالص للمفتاح الدائم', 422);
        }

        // الصلاحيات (Scopes) - GitHub Fine-grained PAT style: لو المستخدم
        // بعت قائمة صلاحيات، المفتاح بيتبني بيها بس. لو مترسلتش، المفتاح
        // بينشأ بكل الصلاحيات (السلوك القديم للتوافق الخلفي). أي scope
        // مش معروف بيتتجاهل بصمت جوه generateFor - هنا بنتحقق إن اللي
        // وصل على الأقل معروف.
        $incomingScopes = $this->get('scopes');
        $scopes = null;
        if (is_array($incomingScopes) && !empty($incomingScopes)) {
            $known = array_keys(UserApiKey::SCOPES);
            $invalid = array_diff($incomingScopes, $known);
            if (!empty($invalid)) {
                return $this->error('صلاحية غير معروفة: ' . implode(', ', array_map('htmlspecialchars', $invalid)), 422);
            }
            $scopes = array_values(array_unique($incomingScopes));
        }

        // حد أقصى معقول لعدد المفاتيح الفعّالة لكل مستخدم - يمنع إنشاء
        // آلاف المفاتيح بالخطأ أو بسكريبت من غير ما يفيد أي استخدام حقيقي.
        $keyModel = new UserApiKey();
        $activeCount = count(array_filter(
            $keyModel->where(['user_id' => (int) $user->getAttribute('id')], [], 0),
            fn ($k) => !$k->getAttribute('revoked_at')
        ));
        if ($activeCount >= 10) {
            return $this->error('وصلت للحد الأقصى (10 مفاتيح فعّالة) - ألغِ مفتاح قديم أولًا', 422);
        }

        $name = trim(strip_tags((string) $this->get('name')));
        $result = UserApiKey::generateFor((int) $user->getAttribute('id'), $name, $expiresInDays > 0 ? date('Y-m-d H:i:s', time() + ($expiresInDays * 86400)) : null, $scopes);

        AuditLog::record((int) $user->getAttribute('id'), 'api_key_created', 'success', 'api_key', (string) $result['model']->getAttribute('id'));

        return $this->success([
            'key' => $result['model']->toSafeArray(),
            // المفتاح الخام الوحيد اللي هيترجع في حياة الطلب ده - الواجهة
            // لازم تعرضه مرة واحدة وتقول للمستخدم يحفظه فورًا.
            'raw_key' => $result['raw_key'],
        ], 'تم إنشاء المفتاح بنجاح - احفظه الآن، لن يظهر كاملًا مرة أخرى');
    }

    /** POST /api/user/api-keys/{id}/revoke */
    public function revokeApiKey(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        $id = (int) ($params['id'] ?? 0);
        $key = (new UserApiKey())->find($id);

        // لازم يكون المفتاح موجود ومملوك فعليًا للمستخدم الحالي - نفس
        // مبدأ logoutSession() بالظبط، عشان نمنع IDOR.
        if (!$key || (int) $key->getAttribute('user_id') !== (int) $user->getAttribute('id')) {
            return $this->error('المفتاح غير موجود', 404);
        }

        if ($key->getAttribute('revoked_at')) {
            return $this->success([], 'المفتاح ملغي بالفعل');
        }

        $key->revoke();

        AuditLog::record((int) $user->getAttribute('id'), 'api_key_revoked', 'success', 'api_key', (string) $id);

        return $this->success([], 'تم إلغاء المفتاح');
    }

    /** POST /api/user/deactivate - إيقاف مؤقت وقابل للتراجع (البديل الآمن للحذف النهائي) */
    public function deactivateAccount(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate(['current_password' => 'required'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_deactivate_failed', 'failed');
            return $this->error('كلمة المرور غير صحيحة', 401, ['current_password' => ['كلمة المرور غير صحيحة']]);
        }

        $user->setAttribute('status', 'suspended');
        if ($user->save() === false) {
            return $this->error('تعذر إيقاف الحساب', 500);
        }

        // نسجّل خروج من كل الأجهزة والمفاتيح فورًا - إيقاف الحساب لازم
        // يعني توقف فعلي، مش بس علم status متغيّر والجلسات لسه شغالة.
        RefreshToken::revokeAllForUser((int) $user->getAttribute('id'));
        foreach ((new UserApiKey())->where(['user_id' => (int) $user->getAttribute('id')]) as $key) {
            if (!$key->getAttribute('revoked_at')) {
                $key->revoke();
            }
        }

        AuditLog::record((int) $user->getAttribute('id'), 'account_deactivated');

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);

        return $this->success([], 'تم إيقاف الحساب مؤقتًا - تواصل مع الدعم لإعادة تفعيله في أي وقت');
    }

    /** GET /api/user/audit-log - سجل نشاط الحساب (Read-Only) */
    public function listAuditLog(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $page = max(1, (int) $this->get('page', 1));
        $result = AuditLog::listFor(
            (int) $user->getAttribute('id'),
            [
                'search' => (string) $this->get('search', ''),
                'action' => (string) $this->get('action', ''),
                'result' => (string) $this->get('result', ''),
                'from' => (string) $this->get('from', ''),
                'to' => (string) $this->get('to', ''),
            ],
            $page,
            20
        );

        return $this->success([
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => 20,
        ]);
    }

    /**
     * GET /api/user/audit-log/export
     *
     * تصدير سجل النشاط كملف CSV (نفس فلاتر القائمة: search/action/result/
     * from/to). مش بيكشف أي بيانات حساسة - نفس الأعمدة المعروضة في
     * الواجهة بالظبط. التصدير بيحصل مباشرة (بحد أقصى 5000 صف لكل دفعة)
     * من غير ما يعدي على الـ Queue. لو في أكتر من 5000 صف مطابق، الـ
     * frontend بيطلب دفعات متتالية بـ offset لحد ما يوصل للنهاية
     * (Phase 16D - Audit Export Pagination) وبيجمّعها في CSV واحد.
     */
    public function exportAuditLog(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $filters = [
            'search' => (string) $this->get('search', ''),
            'action' => (string) $this->get('action', ''),
            'result' => (string) $this->get('result', ''),
            'from' => (string) $this->get('from', ''),
            'to' => (string) $this->get('to', ''),
        ];

        $offset = max(0, (int) $this->get('offset', 0));
        $limit = max(1, min(5000, (int) $this->get('limit', 5000)));
        $total = AuditLog::countFor((int) $user->getAttribute('id'), $filters);
        $rows = AuditLog::exportFor((int) $user->getAttribute('id'), $filters, $limit, $offset);

        // CSV بسيط يدوي - مفيش أي مكتبة خارجية (قيد السيرفر).
        $out = fopen('php://temp', 'r+');
        if ($offset === 0) {
            fputcsv($out, ['time', 'action', 'object_type', 'object_id', 'result', 'ip']);
        }
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'] ?? '',
                $row['action'] ?? '',
                $row['object_type'] ?? '',
                $row['object_id'] ?? '',
                $row['result'] ?? '',
                $row['ip_address'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $filename = 'tourfecto-audit-log-' . date('Y-m-d-His') . '.csv';
        $hasMore = ($offset + count($rows)) < $total;

        if ($this->isApiRequest()) {
            // واجهة API: نرجّع الـ CSV كنص مع اسم الملف والـ pagination info
            // عشان الـ frontend يقدر يكمّل الدفعات اللي بعده ويجمّع الملف.
            return $this->success([
                'filename' => $filename,
                'csv' => $csv,
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => $hasMore,
            ]);
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF" . $csv; // BOM لـ Excel
        exit;
    }

    /**
     * DELETE /api/user/account
     *
     * ⚠️ تحذير مهم من الأوديت: جدول users مرتبط بـ ON DELETE CASCADE مع
     * أكتر من 18 جدول تاني (subscriptions, websites, ai_reports, reviews,
     * chat_messages, api_usage_logs...إلخ - شوف CHANGELOG.md لتفاصيل
     * أكتر). يعني نداء واحد لـ delete() هنا بيمسح كل بيانات المستخدم في
     * المنصة كلها فورًا وبلا رجعة - مش مجرد صف واحد في جدول users.
     * الكود الأصلي (قبل الأوديت ده) كان بينفّذ الحذف ده من غير أي تحقق
     * من كلمة المرور خالص - أي حد لسه Session بتاعته شغالة (حتى لو
     * مسروقة) كان يقدر يمسح الحساب بالكامل بضغطة واحدة. الحد الأدنى
     * المُضاف هنا: التحقق من كلمة المرور + تأكيد مكتوب بالبريد
     * الإلكتروني الحالي، قبل ما ننفّذ أي حاجة. القرار الأشمل (هل نغيّر
     * الحذف ده لـ Soft Delete بدل الاعتماد على CASCADE) قرار منتج/عمل
     * محتاج مراجعتك، مش حاجة غيّرتها من نفسي.
     */
    public function deleteAccount(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        if (!$this->validate([
            'current_password' => 'required',
            'confirm_email' => 'required|email',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if (!$user->verifyPassword((string) $this->get('current_password'))) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_delete_failed', 'failed', null, null, ['reason' => 'wrong_password']);
            return $this->error('كلمة المرور غير صحيحة', 401, ['current_password' => ['كلمة المرور غير صحيحة']]);
        }

        $confirmEmail = trim((string) $this->get('confirm_email'));
        if (strcasecmp($confirmEmail, (string) $user->getAttribute('email')) !== 0) {
            AuditLog::record((int) $user->getAttribute('id'), 'account_delete_failed', 'failed', null, null, ['reason' => 'email_mismatch']);
            return $this->error('البريد الإلكتروني للتأكيد غير مطابق', 422, ['confirm_email' => ['اكتب بريدك الإلكتروني الحالي بالضبط للتأكيد']]);
        }

        // Profile Center Phase 3: فحص اشتراك مدفوع فعّال قبل الحذف. الحذف
        // مش هيلغي أي اشتراك فعليًا عند مزوّد الدفع (Stripe/PayPal) - ده
        // منطق منفصل تمامًا وخارج نطاق الحذف نفسه. فبدل ما نمسح ونسيب
        // اشتراك شغال بره النظام من غير ما حد يعرف، لازم المستخدم يلغي
        // الاشتراك بنفسه الأول، أو يأكد بوعي إنه فاهم إن الاشتراك مش
        // هيتلغي تلقائيًا.
        $activeSubscription = $this->db->query(
            "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' LIMIT 1",
            [(int) $user->getAttribute('id')]
        );
        if (!empty($activeSubscription) && $this->get('acknowledge_active_subscription') !== '1') {
            return $this->error(
                'عندك اشتراك مدفوع فعّال. حذف الحساب مش هيلغي الاشتراك تلقائيًا عند مزوّد الدفع - لازم تلغيه الأول من صفحة الفوترة، أو تأكد إنك فاهم كده وعايز تكمل.',
                409,
                ['subscription' => ['active_subscription_found']]
            );
        }

        $userId = (int) $user->getAttribute('id');

        // Business Control Center (Phase 16): قبل الـCASCADE اللي هياخد
        // كل الـBusinesses بتاعت المستخدم معاه، بنجهّز استمرارية الأعمال:
        // لو أي Business ليه فريق نشط، بتحوّل ملكيته لأعلى عضو رتبة بدل ما
        // البيانات تندفن كلها. وبنوثّق مفاتيح الـAPI اللي حتندمج.
        try {
            $deleted=$this->db->transaction(function()use($user,$userId){
                $pdo=$this->db->getConnection();$q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);
                (new BusinessAccountClosureService())->assertDeletionSafe($userId);
                return $user->delete();
            });
        }catch(RuntimeException $e){return $this->error($e->getMessage(),409);}
        if(!$deleted)return $this->error('Unable to delete account',500);
        BrowserSessionService::revoke();

        // نسجّل الحدث ده بعد نجاح الحذف فعليًا - وبتصميم: جدول
        // audit_logs مالوش Foreign Key على users عمدًا (خلافًا لكل
        // الجداول التانية اللي فيها CASCADE) - عشان سجل "الحساب اتحذف"
        // ده بالذات يفضل موجود حتى بعد ما صف المستخدم نفسه يتمسح.
        AuditLog::record($userId, 'account_deleted');

        unset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['current_refresh_token_id']);

        return $this->success([], 'تم حذف الحساب');
    }
}
