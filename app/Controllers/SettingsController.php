<?php

/**
 * Tourfecto - Settings Controller
 * إعدادات المستخدم العامة والإشعارات ومفتاح API
 * @version 1.0.0
 */

class SettingsController extends Controller
{
    private function currentUser(): ?User
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /** GET /api/settings/general */
    public function getGeneral(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            'company_name' => $user->getAttribute('company_name'),
            'language' => $user->getAttribute('language'),
            'timezone' => $user->getAttribute('timezone'),
            'country'=>$user->getAttribute('country_code'), 'country_code'=>$user->getAttribute('country_code'), 'currency'=>$user->getAttribute('currency'),
        ]);
    }

    /** PUT /api/settings/general */
    public function updateGeneral(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        if($csrfError=$this->verifyCsrf())return $csrfError;
        try { $input=[];foreach(['language','timezone','country_code','currency'] as $key)if($this->has($key))$input[$key]=$this->get($key);if($this->has('country'))$input['country_code']=$this->get('country');$locale=InternationalSettingsService::accountValues($input); } catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        foreach($locale as $field=>$value)$user->setAttribute($field,$value);
        foreach (['company_name'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث الإعدادات', 500);
        }

        $_SESSION['user']=$user->toArray();
        if(isset($locale['language'])){$_COOKIE['tf_lang']=$locale['language'];if(!headers_sent())setcookie('tf_lang',$locale['language'],['expires'=>time()+31536000,'path'=>'/','samesite'=>'Lax']);}
        return $this->success([], 'تم تحديث الإعدادات العامة');
    }

    /** GET /api/settings/notifications */
    public function getNotifications(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            // الحقول القديمة الثلاثة فضلانة للتوافق الخلفي مع أي كود
            // قديم بيقراها لسه، لكن المصدر الحقيقي بقى preferences تحت.
            'email_notifications' => (bool) $user->getAttribute('notify_email'),
            'chat_notifications' => (bool) $user->getAttribute('notify_chat'),
            'review_notifications' => (bool) $user->getAttribute('notify_reviews'),
            // ⚠️ حقل billing_usage_notifications ده مش من شغلي - كان مضاف
            // بالتوازي في نسخة تانية من الملف ده، بس رجّعته هنا عشان
            // ما نضيّعش شغل حد تاني وقت الدمج.
            'billing_usage_notifications' => (bool) $user->getAttribute('notify_billing_usage'),
            'preferences' => Notification::preferencesFor($user),
        ]);
    }

    /** PUT /api/settings/notifications */
    public function updateNotifications(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($csrfError = $this->verifyCsrf()) {
            return $csrfError;
        }

        // التوافق الخلفي: الحقول القديمة الثلاثة لسه بتتحفظ لو اتبعتت.
        $legacyMap = [
            'email_notifications' => 'notify_email',
            'chat_notifications' => 'notify_chat',
            'review_notifications' => 'notify_reviews',
            'billing_usage_notifications' => 'notify_billing_usage',
        ];
        foreach ($legacyMap as $input => $column) {
            if ($this->has($input)) {
                $user->setAttribute($column, $this->get($input) ? 1 : 0);
            }
        }

        // الفئات الحقيقية الجديدة - preferences[category] => bool
        $incomingPrefs = $this->get('preferences');
        if (is_array($incomingPrefs)) {
            $current = Notification::preferencesFor($user);
            foreach (array_keys(Notification::defaultPreferences()) as $category) {
                if (array_key_exists($category, $incomingPrefs)) {
                    $current[$category] = (bool) $incomingPrefs[$category];
                }
            }
            $user->setAttribute('notification_preferences', json_encode($current, JSON_UNESCAPED_UNICODE));

            // نبقي notify_reviews / notify_chat متزامنة مع الفئات المقابلة
            // ليها، عشان أي كود قديم لسه بيقرا العمود القديم يفضل صحيح.
            $user->setAttribute('notify_reviews', $current['reviews'] ? 1 : 0);
            $user->setAttribute('notify_chat', $current['leads'] ? 1 : 0);
        }

        if ($user->save() === false) {
            return $this->error('تعذر حفظ تفضيلات الإشعارات', 500);
        }

        AuditLog::record((int) $user->getAttribute('id'), 'notification_preferences_updated');

        return $this->success(['preferences' => Notification::preferencesFor($user)], 'تم حفظ تفضيلات الإشعارات');
    }

    /** GET /api/settings/api */
    public function getAPI(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['api_token' => $user->getAttribute('api_token')]);
    }

    /** POST /api/settings/api/regenerate */
    public function regenerateAPIKey(array $params = []): array
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $newToken = User::generateApiToken();
        $user->setAttribute('api_token', $newToken);

        if ($user->save() === false) {
            return $this->error('تعذر توليد مفتاح جديد', 500);
        }

        return $this->success(['api_token' => $newToken], 'تم توليد مفتاح API جديد');
    }

    /** GET /api/settings/languages */
    public function getLanguages(array $params = []): array
    {
        return $this->success(['languages' => defined('SUPPORTED_LANGUAGES') ? SUPPORTED_LANGUAGES : []]);
    }

    /** GET /api/settings/timezones */
    public function getTimezones(array $params = []): array
    {
        return $this->success(['timezones' => DateTimeZone::listIdentifiers(DateTimeZone::ALL)]);
    }
}
