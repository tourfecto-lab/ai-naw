<?php
/**
 * Tourfecto - User Controller
 * الملف الشخصي وإعدادات المستخدم
 * @version 1.0.0
 *
 * ملاحظة: كان هذا الملف مفقودًا بالكامل. تمت إعادة بنائه اعتمادًا على User Model
 * الموجود فعليًا في app/Models/User.php.
 */

class UserController extends Controller {

    /**
     * الحصول على المستخدم الحالي من الجلسة، ويُرجع null إن لم يكن مسجل دخول
     */
    private function currentUser(): ?User {
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
    private function isApiRequest(): bool {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return strpos($path, '/api/') === 0;
    }

    /** GET /profile و GET /api/user/profile */
    public function showProfile(array $params = []): array {
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

        $this->renderProfilePage($user);
        exit;
    }

    private function renderProfilePage(User $user): void {
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
            <a href="/profile/security" class="p-card quick-tile"><span class="ic">🔒</span>الأمان (كلمة المرور)</a>
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

    public function profile(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['user' => $user->toArray()]);
    }

    /** GET /profile/edit -> نفس صفحة /profile (الفورم متضمّن فيها بالفعل) */
    public function showEditProfile(array $params = []): array {
        if ($this->isApiRequest()) {
            return $this->profile($params);
        }
        header('Location: /profile');
        exit;
    }

    /** POST /profile/update و PUT /api/user/profile */
    public function updateProfile(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        // تصحيح: 'country' مش موجود في fillable الخاصة بـ User (الاسم
        // الحقيقي 'country_code')، فكان setAttribute('country', ...)
        // بيتجاهل التحديث بصمت من غير أي خطأ ظاهر للمستخدم.
        foreach (['company_name', 'phone', 'country_code', 'language', 'timezone', 'first_name', 'last_name'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث البيانات', 500);
        }

        $_SESSION['user'] = $user->toArray();

        return $this->success(['user' => $user->toArray()], 'تم تحديث الملف الشخصي');
    }

    /** PUT /api/user/password */
    public function updatePassword(array $params = []): array {
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
            return $this->error('كلمة المرور الحالية غير صحيحة', 401);
        }

        if (!$user->updatePassword((string) $this->get('new_password'))) {
            return $this->error('تعذر تحديث كلمة المرور', 500);
        }

        return $this->success([], 'تم تحديث كلمة المرور بنجاح');
    }

    /** GET /profile/settings و GET /api/user/settings */
    public function showSettings(array $params = []): array {
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

    private function renderSettingsPage(User $user): void {
        $data = $user->toArray();
        $firstName = htmlspecialchars((string) ($data['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars((string) ($data['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $companyName = htmlspecialchars((string) ($data['company_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars((string) ($data['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
        $countryCode = htmlspecialchars((string) ($data['country_code'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars((string) ($data['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role = htmlspecialchars((string) ($data['role'] ?? 'user'), ENT_QUOTES, 'UTF-8');
        $memberSince = !empty($data['created_at']) ? date('Y-m-d', strtotime($data['created_at'])) : '-';
        $avatarUrl = htmlspecialchars((string) ($data['avatar_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $initials = htmlspecialchars(mb_strtoupper(mb_substr($data['first_name'] ?? ($data['company_name'] ?? '؟'), 0, 1)), ENT_QUOTES, 'UTF-8');
        $language = htmlspecialchars((string) ($data['language'] ?: 'ar'), ENT_QUOTES, 'UTF-8');
        $timezone = htmlspecialchars((string) ($data['timezone'] ?: 'UTC'), ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars((string) ($data['api_token'] ?? ''), ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <div class="p-tabs" id="settingsTabs">
            <button class="p-tab active" data-section="profile">👤 الملف الشخصي</button>
            <button class="p-tab" data-section="security">🔒 الأمان</button>
            <button class="p-tab" data-section="notifications">🔔 الإشعارات</button>
            <button class="p-tab" data-section="api">🔑 API</button>
            <button class="p-tab" data-section="general">🌐 اللغة والمنطقة</button>
        </div>

        <!-- الملف الشخصي -->
        <div class="settings-section" id="section_profile">
            <div class="p-card">
                <div class="p-card-head"><h3>الصورة الشخصية</h3></div>
                <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap;">
                    <div id="avatarPreviewWrap" style="width:84px;height:84px;border-radius:50%;overflow:hidden;background:var(--panel-accent,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;color:#fff;flex-shrink:0;">
                        {$this->avatarInnerHtml($avatarUrl, $initials)}
                    </div>
                    <div>
                        <input type="file" id="avatarInput" accept="image/png,image/jpeg,image/webp" style="display:none;">
                        <button type="button" class="p-btn outline xs" onclick="document.getElementById('avatarInput').click()">📷 تغيير الصورة</button>
                        <p class="p-cell-muted" style="font-size:12.5px;margin-top:6px;">JPG أو PNG أو WEBP، أقل من 3 ميجا.</p>
                    </div>
                </div>
            </div>

            <div class="p-card" style="margin-top:16px;">
                <div class="p-card-head"><h3>معلومات الحساب</h3></div>
                <div class="p-kv"><span class="k">البريد الإلكتروني</span><span class="v" style="direction:ltr;display:inline-block;">{$email}</span></div>
                <div class="p-kv"><span class="k">الدور</span><span class="v">{$role}</span></div>
                <div class="p-kv"><span class="k">عضو منذ</span><span class="v">{$memberSince}</span></div>
            </div>

            <div class="p-card" style="margin-top:16px;">
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
                    <div class="p-grid cols-2">
                        <div class="form-group">
                            <label class="form-label" for="phone">رقم الهاتف</label>
                            <input type="text" id="phone" class="form-control" value="{$phone}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="country_code">كود الدولة</label>
                            <input type="text" id="country_code" class="form-control" value="{$countryCode}" placeholder="مثال: EG">
                        </div>
                    </div>
                    <div id="profileAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">حفظ التعديلات</button>
                </form>
            </div>
        </div>

        <!-- الأمان -->
        <div class="settings-section" id="section_security" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>تغيير كلمة المرور</h3></div>
                <form id="securityForm">
                    <div class="form-group">
                        <label class="form-label" for="current_password">كلمة المرور الحالية</label>
                        <input type="password" id="current_password" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="new_password">كلمة المرور الجديدة (8 أحرف على الأقل)</label>
                        <input type="password" id="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div id="securityAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">تحديث كلمة المرور</button>
                </form>
            </div>
        </div>

        <!-- الإشعارات -->
        <div class="settings-section" id="section_notifications" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>تفضيلات الإشعارات</h3></div>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notify_email"> إشعارات البريد الإلكتروني (تقارير، تحديثات مهمة)
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notify_chat"> إشعارات المحادثات (رسائل عملاء جديدة تحتاج موافقتك)
                </label>
                <label style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <input type="checkbox" id="notify_reviews"> إشعارات المراجعات الجديدة
                </label>
                <div id="notifAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="button" class="p-btn primary" onclick="saveNotifications()">حفظ التفضيلات</button>
            </div>
        </div>

        <!-- API -->
        <div class="settings-section" id="section_api" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>مفتاح الـ API الخاص بك</h3></div>
                <p class="p-cell-muted">استخدم المفتاح ده لو حبيت تتكامل مع Tourfecto من تطبيق تاني. متشاركهوش مع حد.</p>
                <code id="apiToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:14px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;margin:14px 0;">{$token}</code>
                <div style="display:flex;gap:10px;">
                    <button class="p-btn outline" onclick="copyToken()">📋 نسخ المفتاح</button>
                    <button class="p-btn outline" onclick="regenerateToken()">🔄 توليد مفتاح جديد</button>
                </div>
                <p class="p-cell-muted" style="font-size:12.5px;margin-top:10px;">⚠️ توليد مفتاح جديد بيلغي القديم فورًا - أي تكامل بيستخدمه هيتوقف.</p>
            </div>
        </div>

        <!-- اللغة والمنطقة -->
        <div class="settings-section" id="section_general" style="display:none;">
            <div class="p-card">
                <div class="p-card-head"><h3>تفضيلات الحساب</h3></div>
                <form id="generalForm">
                    <div class="form-group">
                        <label class="form-label" for="language">لغة الواجهة</label>
                        <select id="language" class="form-control">
                            <option value="ar"{$this->selected($language, 'ar')}>العربية</option>
                            <option value="en"{$this->selected($language, 'en')}>English</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="timezone">المنطقة الزمنية</label>
                        <select id="timezone" class="form-control">
                            <option value="UTC"{$this->selected($timezone, 'UTC')}>UTC</option>
                            <option value="Africa/Cairo"{$this->selected($timezone, 'Africa/Cairo')}>القاهرة (Africa/Cairo)</option>
                            <option value="Asia/Riyadh"{$this->selected($timezone, 'Asia/Riyadh')}>الرياض (Asia/Riyadh)</option>
                            <option value="Asia/Dubai"{$this->selected($timezone, 'Asia/Dubai')}>دبي (Asia/Dubai)</option>
                        </select>
                    </div>
                    <div id="generalAlert" class="alert alert-danger" style="display:none;"></div>
                    <button type="submit" class="p-btn primary">حفظ الإعدادات</button>
                </form>
            </div>

            <div class="p-card" style="margin-top:16px;border:1px solid #f3c6c6;">
                <div class="p-card-head"><h3 style="color:#c0392b;">⚠️ منطقة الخطر</h3></div>
                <p class="p-cell-muted">حذف حسابك نهائي ومش هيتراجع، وهيمسح بياناتك من المنصة.</p>
                <button type="button" class="p-btn danger" onclick="deleteAccount()">حذف الحساب نهائيًا</button>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    // ============ التابات ============
    document.querySelectorAll('#settingsTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#settingsTabs .p-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const section = btn.dataset.section;
            document.querySelectorAll('.settings-section').forEach(s => s.style.display = 'none');
            document.getElementById('section_' + section).style.display = 'block';
        });
    });

    // ============ الملف الشخصي + الصورة ============
    document.getElementById('avatarInput').addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('avatar', file);

        const wrap = document.getElementById('avatarPreviewWrap');
        const originalHtml = wrap.innerHTML;
        wrap.innerHTML = '<span style="font-size:13px;">جارِ الرفع...</span>';

        try {
            const res = await fetch('/api/user/avatar', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                wrap.innerHTML = `<img src="${esc(data.data.avatar_url)}" style="width:100%;height:100%;object-fit:cover;">`;
                toast('اتغيّرت الصورة ✔', 'success');
            } else {
                wrap.innerHTML = originalHtml;
                toast(data.error || 'تعذر رفع الصورة', 'error');
            }
        } catch (err) {
            wrap.innerHTML = originalHtml;
            toast('تعذر الاتصال بالخادم', 'error');
        }
    });

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

    // ============ الأمان ============
    document.getElementById('securityForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('securityAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/password', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: document.getElementById('current_password').value,
                new_password: document.getElementById('new_password').value,
            }),
        });

        if (res.success) {
            toast('تم تحديث كلمة المرور', 'success');
            document.getElementById('securityForm').reset();
        } else {
            alertBox.textContent = res.error || 'تعذر التحديث';
            alertBox.style.display = 'block';
        }
    });

    // ============ الإشعارات ============
    async function loadNotifications() {
        const res = await fetchJSON('/api/settings/notifications');
        if (res.success) {
            document.getElementById('notify_email').checked = !!res.data.email_notifications;
            document.getElementById('notify_chat').checked = !!res.data.chat_notifications;
            document.getElementById('notify_reviews').checked = !!res.data.review_notifications;
        }
    }

    window.saveNotifications = async function () {
        const alertBox = document.getElementById('notifAlert');
        alertBox.style.display = 'none';
        const res = await fetchJSON('/api/settings/notifications', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email_notifications: document.getElementById('notify_email').checked,
                chat_notifications: document.getElementById('notify_chat').checked,
                review_notifications: document.getElementById('notify_reviews').checked,
            }),
        });
        if (res.success) toast('تم حفظ التفضيلات', 'success');
        else { alertBox.textContent = res.error || 'تعذر الحفظ'; alertBox.style.display = 'block'; }
    };

    // ============ API ============
    window.copyToken = function () {
        const text = document.getElementById('apiToken').textContent;
        navigator.clipboard.writeText(text).then(() => toast('اتنسخ المفتاح ✔', 'success'));
    };

    window.regenerateToken = async function () {
        if (!confirm('متأكد؟ أي تكامل بيستخدم المفتاح الحالي هيتوقف فورًا.')) return;
        const res = await fetchJSON('/api/settings/api/regenerate', { method: 'POST' });
        if (res.success) {
            document.getElementById('apiToken').textContent = res.data.api_token;
            toast('اتولّد مفتاح جديد ✔', 'success');
        } else {
            toast(res.error || 'تعذر التوليد', 'error');
        }
    };

    // ============ اللغة والمنطقة ============
    document.getElementById('generalForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('generalAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                language: document.getElementById('language').value,
                timezone: document.getElementById('timezone').value,
            }),
        });

        if (res.success) {
            toast('تم حفظ الإعدادات', 'success');
        } else {
            alertBox.textContent = res.error || 'تعذر الحفظ';
            alertBox.style.display = 'block';
        }
    });

    window.deleteAccount = async function () {
        if (!confirm('متأكد إنك عايز تحذف حسابك نهائيًا؟ الخطوة دي مش هترجع.')) return;
        if (!confirm('تأكيد أخير: هيتم حذف كل بياناتك. متأكد؟')) return;
        const res = await fetchJSON('/api/user/account', { method: 'DELETE' });
        if (res.success) {
            toast('تم حذف الحساب', 'success');
            setTimeout(() => window.location.href = '/login', 1200);
        } else {
            toast(res.error || 'تعذر الحذف', 'error');
        }
    };

    loadNotifications();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_settings', 'الإعدادات', 'كل حاجة تخص حسابك في مكان واحد', $body, $script);
    }

    /** يبني HTML الصورة الشخصية الحالية (صورة حقيقية لو موجودة، وإلا حرف أول الاسم) */
    private function avatarInnerHtml(string $avatarUrl, string $initials): string {
        if ($avatarUrl !== '') {
            return '<img src="' . $avatarUrl . '" style="width:100%;height:100%;object-fit:cover;">';
        }
        return $initials;
    }

    /** POST /api/user/avatar - رفع/تغيير الصورة الشخصية */
    public function uploadAvatar(array $params = []): array {
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
    private function selected(string $current, string $option): string {
        return $current === $option ? ' selected' : '';
    }

    public function getSettings(array $params = []): array {
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
    public function updateSettings(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        foreach (['language', 'timezone'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث الإعدادات', 500);
        }

        return $this->success([], 'تم تحديث الإعدادات');
    }

    /** GET /profile/security */
    public function showSecurity(array $params = []): array {
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

        $body = <<<'HTML'
        <div class="p-card">
            <div class="p-card-head"><h3>تغيير كلمة المرور</h3></div>
            <form id="securityForm">
                <div class="form-group">
                    <label class="form-label" for="current_password">كلمة المرور الحالية</label>
                    <input type="password" id="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_password">كلمة المرور الجديدة (8 أحرف على الأقل)</label>
                    <input type="password" id="new_password" class="form-control" minlength="8" required>
                </div>
                <div id="securityAlert" class="alert alert-danger" style="display:none;"></div>
                <button type="submit" class="p-btn primary">تحديث كلمة المرور</button>
            </form>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, toast = P.toast;

    document.getElementById('securityForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const alertBox = document.getElementById('securityAlert');
        alertBox.style.display = 'none';

        const res = await fetchJSON('/api/user/password', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: document.getElementById('current_password').value,
                new_password: document.getElementById('new_password').value,
            }),
        });

        if (res.success) {
            toast('تم تحديث كلمة المرور', 'success');
            document.getElementById('securityForm').reset();
        } else {
            alertBox.textContent = res.error || 'تعذر التحديث';
            alertBox.style.display = 'block';
        }
    });
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_settings', 'الأمان', 'تغيير كلمة المرور', $body, $script);
        exit;
    }

    /** POST /profile/security */
    public function updateSecurity(array $params = []): array {
        return $this->updatePassword($params);
    }

    /** GET /profile/api */
    public function showAPI(array $params = []): array {
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

        $token = htmlspecialchars((string) $user->getAttribute('api_token'), ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
        <div class="p-card">
            <div class="p-card-head"><h3>مفتاح الـ API الخاص بك</h3></div>
            <p class="p-cell-muted">استخدم المفتاح ده لو حبيت تتكامل مع Tourfecto من تطبيق تاني. متشاركهوش مع حد.</p>
            <code id="apiToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:14px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;margin:14px 0;">{$token}</code>
            <button class="p-btn outline" onclick="copyToken()">📋 نسخ المفتاح</button>
        </div>
HTML;

        $script = <<<'JS'
window.copyToken = function () {
    const text = document.getElementById('apiToken').textContent;
    navigator.clipboard.writeText(text).then(() => window.Panel.toast('اتنسخ المفتاح ✔', 'success'));
};
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_settings', 'مفتاح API', 'الوصول البرمجي لحسابك', $body, $script);
        exit;
    }

    /** DELETE /api/user/account */
    public function deleteAccount(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if (!$user->delete()) {
            return $this->error('تعذر حذف الحساب', 500);
        }

        unset($_SESSION['user_id'], $_SESSION['user']);

        return $this->success([], 'تم حذف الحساب');
    }
}
