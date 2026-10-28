<?php
/**
 * Tourfecto - Settings Controller
 * إعدادات المستخدم العامة والإشعارات ومفتاح API
 * @version 1.0.0
 */

class SettingsController extends Controller {

    private function currentUser(): ?User {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $model = new User();
        return $model->find($id);
    }

    /** GET /api/settings/general */
    public function getGeneral(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            'company_name' => $user->getAttribute('company_name'),
            'language' => $user->getAttribute('language'),
            'timezone' => $user->getAttribute('timezone'),
            'country' => $user->getAttribute('country'),
        ]);
    }

    /** PUT /api/settings/general */
    public function updateGeneral(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        foreach (['company_name', 'language', 'timezone', 'country'] as $field) {
            if ($this->has($field)) {
                $user->setAttribute($field, $this->get($field));
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر تحديث الإعدادات', 500);
        }

        return $this->success([], 'تم تحديث الإعدادات العامة');
    }

    /** GET /api/settings/notifications */
    /** GET /api/settings/notifications */
    public function getNotifications(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success([
            'email_notifications' => (bool) $user->getAttribute('notify_email'),
            'chat_notifications' => (bool) $user->getAttribute('notify_chat'),
            'review_notifications' => (bool) $user->getAttribute('notify_reviews'),
        ]);
    }

    /** PUT /api/settings/notifications */
    public function updateNotifications(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }

        $map = [
            'email_notifications' => 'notify_email',
            'chat_notifications' => 'notify_chat',
            'review_notifications' => 'notify_reviews',
        ];
        foreach ($map as $input => $column) {
            if ($this->has($input)) {
                $user->setAttribute($column, $this->get($input) ? 1 : 0);
            }
        }

        if ($user->save() === false) {
            return $this->error('تعذر حفظ تفضيلات الإشعارات', 500);
        }

        return $this->success([], 'تم حفظ تفضيلات الإشعارات');
    }

    /** GET /api/settings/api */
    public function getAPI(array $params = []): array {
        $user = $this->currentUser();
        if (!$user) {
            return $this->error('غير مسجل دخول', 401);
        }
        return $this->success(['api_token' => $user->getAttribute('api_token')]);
    }

    /** POST /api/settings/api/regenerate */
    public function regenerateAPIKey(array $params = []): array {
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
    public function getLanguages(array $params = []): array {
        return $this->success(['languages' => defined('SUPPORTED_LANGUAGES') ? SUPPORTED_LANGUAGES : []]);
    }

    /** GET /api/settings/timezones */
    public function getTimezones(array $params = []): array {
        return $this->success(['timezones' => defined('SUPPORTED_TIMEZONES') ? SUPPORTED_TIMEZONES : []]);
    }
}
