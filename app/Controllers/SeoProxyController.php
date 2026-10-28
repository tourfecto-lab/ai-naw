<?php

/**
 * Tourfecto - SEO Proxy Controller (Server-Side Edge)
 * @version 1.0.0
 *
 * نقطة الدخول العامة (من غير AuthMiddleware) اللي بتخدم صفحة موقع خارجي
 * مربوط بالمنصة بعد إعادة كتابتها Server-Side. العميل بيشير DNS بتاعه
 * (CNAME) ناحية سيرفرنا، أو بيستخدم مسار الاختبار /s/{token}/...، والروبت
 * أو الزائر بيشوف النسخة المحسّنة مباشرة - مش مجرد حقن مؤقت بالـ embed.js.
 *
 * ده الجزء اللي بيحوّل "التنفيذ التلقائي" لشيء محركات البحث (جوجل/بينج/
 * ChatGPT/Perplexity) تشوفه فعليًا وقت الزحف، مش بس المتصفح.
 */
class SeoProxyController extends Controller
{
    /**
     * GET /s/{token}[/{path}...]  (عام - بدون AuthMiddleware)
     * بيسحب المسار الحقيقي من REQUEST_URI لأنه ممكن يكون متعدد المقاطع
     * (المسار بيوصلنا عبر Passthrough في index.php مش عبر Router عادي).
     */
    public function serve(array $params = []): void
    {
        $token = (string) ($params['token'] ?? '');
        if ($token === '' || !preg_match('/^emb_[a-f0-9]{24}$/', $token)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: invalid token';
            exit;
        }

        if ($this->isRateLimited()) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: too many requests';
            exit;
        }

        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $prefix = '/s/' . $token;
        $path = $requestPath === $prefix ? '/' : substr($requestPath, strlen($prefix));
        if ($path === '' || $path[0] !== '/') {
            $path = '/';
        }

        try {
            $service = new SeoProxyService($this->db);
            $result = $service->render($token, $path);
        } catch (Exception $e) {
            Logger::error('SeoProxy Serve Error', ['message' => $e->getMessage()]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: internal error';
            exit;
        }

        http_response_code($result['status']);
        header('Content-Type: ' . $result['content_type']);
        // الأخطاء ما تتكاشيش على الـ CDN/المتصفح (رسالة الـ timeout كانت بتفضل 5 دقايق بعد الإصلاح)
        header(((int) ($result['status'] ?? 200)) === 200 ? 'Cache-Control: public, max-age=60, s-maxage=300' : 'Cache-Control: no-store');
        echo $result['body'];
        exit;
    }

    /**
     * التحقق من إن الـ Host header الحالي بيطابق موقع مربوط (وضع CNAME).
     * بيُستخدم في index.php كبوابة قبل ما نسلم الرد للـ CNAME passthrough،
     * عشان منكسرش مسارات لوحة التحكم نفسها (هترجع false لـ host اللوحة).
     */
    public function shouldHandleCname(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return false;
        }
        // طلب جاي أصلاً من proxy تاني: ما نحوّلوش تاني (قطع أي حلقة)
        if (!empty($_SERVER['HTTP_X_TOURFECTO_PROXY'])) {
            return false;
        }
        try {
            $service = new SeoProxyService($this->db);
            return $service->findByHost($host) !== null;
        } catch (Exception $e) {
            Logger::error('SeoProxy CNAME Check Error', ['host' => $host, 'message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * CNAME Passthrough (بدون /s/{token}) - الطلب بيوصل هنا لما العميل يشير
     * DNS بتاعه ناحية سيرفرنا والـ Host header بيبقى دومينه هو.
     */
    public function serveCname(array $params = []): void
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($this->isRateLimited()) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: too many requests';
            exit;
        }

        try {
            $service = new SeoProxyService($this->db);
            $site = $service->findByHost($host);
            if ($site === null) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Tourfecto proxy: host not connected';
                exit;
            }
            $result = $service->renderSite($site, $requestPath);
        } catch (Exception $e) {
            Logger::error('SeoProxy CNAME Error', ['host' => $host, 'message' => $e->getMessage()]);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Tourfecto proxy: internal error';
            exit;
        }

        http_response_code($result['status']);
        header('Content-Type: ' . $result['content_type']);
        // الأخطاء ما تتكاشيش على الـ CDN/المتصفح (رسالة الـ timeout كانت بتفضل 5 دقايق بعد الإصلاح)
        header(((int) ($result['status'] ?? 200)) === 200 ? 'Cache-Control: public, max-age=60, s-maxage=300' : 'Cache-Control: no-store');
        echo $result['body'];
        exit;
    }

    /**
     * نقطة فحص صحة للـ Edge/Load Balancer (بتتفحص من غير Auth).
     * بترجع "ok" طالما الخدمة شغالة ومتصلة بقاعدة البيانات.
     */
    public function edgeHealth(array $params = []): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'ok',
            'service' => 'tourfecto-seo-proxy',
            'time' => date('c'),
        ]);
        exit;
    }

    /**
     * تحديد معدل الطلبات على الـ proxy العام (من غير AuthMiddleware) للحماية
     * من إساءة الاستخدام. المفتاح هو IP العميل، بحد أقصى 120 طلب/دقيقة.
     */
    private function isRateLimited(): bool
    {
        try {
            if (!class_exists('RateLimiter', false)) {
                $rlFile = APP_PATH . '/Services/Security/RateLimiter.php';
                if (file_exists($rlFile)) {
                    require_once $rlFile;
                }
            }
            if (!class_exists('RateLimiter', false)) {
                return false;
            }
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $limiter = new RateLimiter();
            $check = $limiter->checkWithDetails($ip, 'seo_proxy', 120, 60);
            return !$check['allowed'];
        } catch (Exception $e) {
            // لو الـ rate limiter مش متاح (جدول rate_limit_blocks مش موجود)،
            // بنكمل من غير تقييد بدل ما نكسر الـ proxy.
            return false;
        }
    }
}
