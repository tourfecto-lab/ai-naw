<?php

/**
 * Tourfecto - callbacks الخصوصية العامة (بتتسجل في Meta for Developers > App settings / Facebook Login > Settings):
 *   POST /api/meta/deauthorize       - Deauthorize Callback URL
 *   POST /api/meta/data-deletion     - Data Deletion Request URL (لازم يرجّع JSON {url, confirmation_code})
 *   GET  /meta/data-deletion-status  - صفحة حالة الطلب (?code=...)
 * ⚠️ لازم تتسجل كراوتس عامة: من غير تسجيل دخول ومن غير CSRF (الأمان من توقيع signed_request).
 */
require_once __DIR__ . '/../Services/SocialMedia/MetaSignedRequest.php';
require_once __DIR__ . '/../Services/SocialMedia/MetaDataPrivacyService.php';
class MetaDataCallbackController extends Controller
{
    public function deauthorize(array $params = []): array
    {
        $uid = $this->verifiedUserId();
        if ($uid === null) {
            return $this->error('Invalid signed_request', 400);
        }
        try {
            $r = MetaDataPrivacyService::deauthorize($uid);
            Logger::info('Meta deauthorize processed', ['connections' => $r['connections'], 'status' => $r['status']]);
        } catch (Throwable $e) {
            Logger::error('Meta deauthorize failed', ['message' => $e->getMessage()]);
            return $this->error('Could not process', 500); // Meta بتعيد المحاولة
        }
        return $this->success([]);
    }

    public function dataDeletion(array $params = []): array
    {
        $uid = $this->verifiedUserId();
        if ($uid === null) {
            return $this->error('Invalid signed_request', 400);
        }
        try {
            $r = MetaDataPrivacyService::requestDeletion($uid);
        } catch (Throwable $e) {
            Logger::error('Meta data deletion failed', ['message' => $e->getMessage()]);
            return $this->error('Could not process', 500);
        }
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        // Meta بتطلب JSON بالشكل ده بالظبط (من غير غلاف success/data)
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        return ['_raw_text' => json_encode(['url' => $base . '/meta/data-deletion-status?code=' . $r['code'], 'confirmation_code' => $r['code']])];
    }

    public function status(array $params = []): array
    {
        $code = (string) ($_GET['code'] ?? '');
        $row = MetaDataPrivacyService::status($code);
        $msg = !$row
            ? 'مفيش طلب بالكود ده.'
            : ($row['status'] === 'completed'
                ? 'اتنفّذ طلبك: فصلنا اتصالات حسابك ومسحنا التوكنات المخزنة.'
                : 'لم نجد اتصالات مرتبطة بحسابك في سجلاتنا. لو فيه بيانات متبقية تواصل مع الدعم وسيب كود الطلب.');
        $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>حالة طلب حذف البيانات</title>'
            . '<body style="font-family:system-ui,sans-serif;max-width:560px;margin:60px auto;padding:0 16px;line-height:1.8" dir="rtl"><h2>حالة طلب حذف البيانات</h2><p>'
            . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p style="color:#888;font-size:13px">كود الطلب: ' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p></body>';
        return ['_raw_text' => $html];
    }

    private function verifiedUserId(): ?string
    {
        $signed = (string) ($_POST['signed_request'] ?? '');
        $data = MetaSignedRequest::parse($signed, (string) MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET'));
        $uid = $data['user_id'] ?? null;
        return ($uid !== null && preg_match('/^[0-9]{1,40}$/', (string) $uid)) ? (string) $uid : null;
    }
}
