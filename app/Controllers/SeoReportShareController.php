<?php
/** مشاركة تقرير الظهور: إنشاء/عرض/إلغاء الروابط (للمالك)، وصفحة التقرير العامة للقراءة بالتوكن (قابلة للطباعة PDF). */
final class SeoReportShareController extends Controller
{
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        if ($err = $this->rateLimitGuard('user', 'seo_report_share_create', 10, 60)) return $err;
        $wid = (int) $this->get('website_id');
        try {
            $r = (new SeoReportShareService($this->db))->create((int) $this->user['id'], $wid, (string) $this->get('lang', 'ar'));
        } catch (RuntimeException $e) {
            return $this->error('مشاركة التقارير محتاجة تفعيل جدول جديد من الأدمن (migration).', 409);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $base = defined('APP_URL') && str_starts_with((string) APP_URL, 'https://') ? rtrim((string) APP_URL, '/') : 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
        return $this->success(['id' => $r['id'], 'url' => $base . '/r/' . $r['token'], 'expires_at' => $r['expires_at'], 'lang' => $r['lang'],
            'note' => 'الرابط ده بيظهر مرة واحدة. أي حد معاه الرابط يشوف التقرير لحد ما ينتهي أو تلغيه.']);
    }

    public function links(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            $svc = new SeoReportShareService($this->db);
            return $this->success(['ready' => $svc->ready(), 'links' => $svc->list((int) $this->user['id'], (int) $this->get('website_id'))]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function revoke(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        $ok = (new SeoReportShareService($this->db))->revoke((int) $this->user['id'], (int) ($params['id'] ?? $this->get('id')));
        return $ok ? $this->success(['revoked' => true]) : $this->error('الرابط مش موجود', 422);
    }

    /** GET /r/{token}: صفحة التقرير العامة. مفيش أي تفاصيل صفحات أو إجراءات، أرقام وملخصات فقط. */
    public function show(array $params = []): array
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        if ($err = $this->rateLimitGuard('ip', 'seo_report_view', 60, 60)) {
            http_response_code(429);
            echo 'Too many requests / طلبات كتير';
            exit;
        }
        $grant = (new SeoReportShareService($this->db))->resolve((string) ($params['token'] ?? ''));
        if (!$grant) {
            http_response_code(410); // 404 بيتغطى بصفحة nginx العامة فالعميل مش بيشوف السبب
            echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Link unavailable</title><p style="font-family:sans-serif;padding:2rem">الرابط غير صالح أو انتهى أو اتلغى. / This link is invalid, expired or revoked.</p>';
            exit;
        }
        $site = $this->db->query('SELECT company_name, brand_name, main_url FROM websites WHERE id = ? AND user_id = ?', [$grant['website_id'], $grant['user_id']]);
        $overview = (new VisibilityScoreService($this->db))->overview($grant['user_id'], $grant['website_id']);
        $lang = $grant['lang'];
        $siteName = (string) ($site[0]['brand_name'] ?: $site[0]['company_name'] ?: parse_url((string) $site[0]['main_url'], PHP_URL_HOST));
        require dirname(__DIR__) . '/Views/ReportShare/report.php';
        exit;
    }
}
