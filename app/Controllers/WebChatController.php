<?php

/**
 * شات الموقع: سكربت الفقاعة + إرسال/استقبال الزائر (عام، من أي دومين) + تفعيل/كود التضمين من اللوحة.
 * الطلبات العامة مش بتستخدم كوكيز (CORS مفتوح من غير credentials) والتحقق بتوكن HMAC لكل موقع.
 */
class WebChatController extends Controller
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function ip(): string
    {
        return substr(preg_replace('/[^0-9a-f:.]/i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }

    /** موقع + توكن + زائر صالحين؟ */
    private function visitorRequest(): array
    {
        $site = (int) ($_REQUEST['site'] ?? 0);
        $visitor = strtolower((string) ($_REQUEST['v'] ?? ''));
        if (!WebChatService::validToken($site, (string) ($_REQUEST['t'] ?? '')) || !WebChatService::validVisitor($visitor)) {
            $this->json(['success' => false, 'error' => 'invalid'], 403);
        }
        return [$site, $visitor];
    }

    /** GET /widget/chat?site=&t= */
    public function script(array $params = []): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        $site = (int) ($_GET['site'] ?? 0);
        $token = (string) ($_GET['t'] ?? '');
        $svc = new WebChatService();
        if (!WebChatService::validToken($site, $token)) {
            header('Cache-Control: no-store');
            echo "// Tourfecto chat: invalid widget code\n";
            exit;
        }
        // مقفول أو الباقة منتهية: مانظهرش فقاعة مش هترد
        if (!$svc->isLive($site)) {
            header('Cache-Control: public, max-age=300');
            echo "// Tourfecto chat: offline\n";
            exit;
        }
        $info = $svc->site($site);
        $color = '';
        try {
            $bs = $this->db->query('SELECT greeting_message FROM bot_settings WHERE website_id = ? AND is_enabled = 1 ORDER BY id DESC LIMIT 1', [$site]);
            $greeting = trim((string) ($bs[0]['greeting_message'] ?? ''));
        } catch (Throwable $e) {
            $greeting = '';
        }
        if (isset($_GET['c']) && preg_match('/^[0-9a-f]{6}$/i', (string) $_GET['c'])) $color = '#' . $_GET['c'];
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $cfg = [
            'site' => $site, 't' => $token, 'api' => $base . '/api/webchat',
            'name' => mb_substr(preg_replace('~^https?://(www\.)?~i', '', (string) ($info['name'] ?? '')), 0, 60),
            'greeting' => mb_substr($greeting, 0, 300), 'color' => $color,
        ];
        header('Cache-Control: public, max-age=300');
        echo 'window.__TFCHAT=' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n";
        readfile(ROOT_PATH . '/public_html/assets/js/webchat-widget.js');
        exit;
    }

    /** POST /api/webchat/send  (form: site, t, v, text, lang, page) */
    public function send(array $params = []): void
    {
        [$site, $visitor] = $this->visitorRequest();
        $rl = new RateLimiter();
        if (!$rl->check('user_wc_v_' . $visitor, 'webchat_send', 8, 60) || !$rl->check('user_wc_ip_' . $this->ip(), 'webchat_send', 40, 3600)) {
            $this->json(['success' => false, 'error' => 'slow'], 429);
        }
        $r = (new WebChatService())->send($site, $visitor, (string) ($_POST['text'] ?? ''), strtolower(substr((string) ($_POST['lang'] ?? ''), 0, 2)), (string) ($_POST['page'] ?? ''));
        $this->json($r, $r['success'] ? 200 : 422);
    }

    /** GET /api/webchat/poll?site=&t=&v= */
    public function poll(array $params = []): void
    {
        [$site, $visitor] = $this->visitorRequest();
        if (!(new RateLimiter())->check('user_wc_p_' . $visitor, 'webchat_poll', 40, 60)) $this->json(['success' => false, 'error' => 'slow'], 429);
        $this->json(['success' => true, 'messages' => (new WebChatService())->transcript($site, $visitor)]);
    }

    private function ownSite(int $id): ?array
    {
        $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
        $r = $this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [$id, $owner]);
        return $r ? $r[0] : null;
    }

    /** GET /api/websites/{id}/webchat - حالة الشات + كود التضمين */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $id = (int) ($params['id'] ?? 0);
        if (!$this->ownSite($id)) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $svc = new WebChatService();
        $off = $svc->isSwitchedOff($id);
        $built = (int) ($this->db->query("SELECT COUNT(*) c FROM generated_websites WHERE user_id = ? AND status = 'published'", [(int) $this->user['id']])[0]['c'] ?? 0);
        $week = $this->db->query("SELECT COUNT(*) c, COUNT(DISTINCT customer_phone) v FROM chat_messages WHERE website_id = ? AND platform = 'webchat' AND message_direction = 'incoming' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [$id])[0] ?? [];
        return $this->success([
            'on' => !$off,
            'live' => !$off && $svc->isLive($id),
            'snippet' => WebChatService::snippet($id),
            'has_built_site' => $built > 0,
            'week_messages' => (int) ($week['c'] ?? 0),
            'week_visitors' => (int) ($week['v'] ?? 0),
        ]);
    }

    /** POST /api/websites/{id}/webchat {on} */
    public function toggle(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        $id = (int) ($params['id'] ?? 0);
        if (!$this->ownSite($id)) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $on = filter_var($this->get('on'), FILTER_VALIDATE_BOOLEAN);
        (new WebChatService())->setEnabled((int) $this->user['id'], $id, $on);
        return $this->status($params);
    }
}
