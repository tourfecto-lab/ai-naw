<?php
/**
 * Tourfecto - ربط واتساب بمسح QR (للعميل) + استقبال رسايله
 *
 *  GET  /api/whatsapp-qr/status?website_id=     الحالة (متاح؟ متربط؟ الرقم)
 *  POST /api/whatsapp-qr/start                   يجهّز الجلسة ويرجّع كود QR
 *  GET  /api/whatsapp-qr/poll?website_id=        كود جديد + الحالة (الصفحة بتسأل كل كام ثانية)
 *  POST /api/whatsapp-qr/disconnect              فك الربط
 *  POST /api/whatsapp-qr/webhook/{website_id}?secret=   الرسايل الواردة من البوابة
 */
class WhatsAppQrController extends Controller
{
    private function gateway(): WhatsAppQrGateway
    {
        return new WhatsAppQrGateway();
    }

    /** الموقع لازم يكون ملك صاحب الحساب (أو صاحب مساحة العمل لعضو الفريق) */
    private function ownedWebsite(int $wid): ?array
    {
        if ($wid <= 0 || empty($this->user['id'])) return null;
        $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
        return $this->db->query('SELECT id, user_id, company_name FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, $owner])[0] ?? null;
    }

    private function connection(int $wid): ?array
    {
        return $this->db->query("SELECT * FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_qr' ORDER BY id DESC LIMIT 1", [$wid])[0] ?? null;
    }

    private function webhookUrl(int $wid): string
    {
        return rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/api/whatsapp-qr/webhook/' . $wid . '?secret=' . WhatsAppQrGateway::webhookSecret($wid);
    }

    private function view(int $wid, ?array $extra = []): array
    {
        $c = $this->connection($wid);
        return [
            'available' => WhatsAppQrGateway::enabled(),
            'connected' => $c && $c['status'] === 'connected',
            'phone' => $c['external_location_id'] ?? null,
            'name' => $c['external_location_name'] ?? null,
            'connected_at' => $c['connected_at'] ?? null,
            'last_error' => ($c && $c['status'] !== 'connected') ? ($c['last_error'] ?? null) : null,
        ] + (array) $extra;
    }

    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) return $this->error('Website not found', 404);
        return $this->success($this->view($wid));
    }

    public function start(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'whatsapp_qr_start', 10, 60)) return $e;
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) return $this->error('Website not found', 404);
        if (!WhatsAppQrGateway::enabled()) return $this->error('WhatsApp QR connection is not available yet. Please contact support.', 409);
        $gw = $this->gateway();
        $name = WhatsAppQrGateway::instanceName($wid);
        $made = $gw->ensureInstance($name, $this->webhookUrl($wid));
        if (empty($made['ok'])) {
            Logger::warning('WhatsApp QR instance failed', ['website_id' => $wid, 'error' => $made['error'] ?? '']);
            return $this->error('Could not start the WhatsApp connection. Try again in a minute.', 409);
        }
        return $this->poll($params);
    }

    /** كود QR جديد + الحالة. لما الحالة تبقى open بنسجّل الربط. */
    public function poll(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $wid = (int) $this->get('website_id');
        $site = $this->ownedWebsite($wid);
        if (!$site) return $this->error('Website not found', 404);
        if (!WhatsAppQrGateway::enabled()) return $this->error('WhatsApp QR connection is not available yet.', 409);
        $gw = $this->gateway();
        $name = WhatsAppQrGateway::instanceName($wid);
        $st = $gw->state($name);
        if (empty($st['ok'])) return $this->error('WhatsApp gateway is not responding. Try again shortly.', 409);
        if ($st['state'] === 'open') {
            $this->markConnected($wid, (int) $site['user_id'], $name, $gw->profile($name));
            return $this->success($this->view($wid, ['state' => 'open', 'qr' => null]));
        }
        if ($st['state'] === 'missing') return $this->success($this->view($wid, ['state' => 'close', 'qr' => null]));
        $qr = $gw->qr($name);
        if (empty($qr['ok'])) return $this->error('Could not load the QR code. Press "Connect" again.', 409);
        if (($qr['state'] ?? '') === 'open') {
            $this->markConnected($wid, (int) $site['user_id'], $name, $gw->profile($name));
            return $this->success($this->view($wid, ['state' => 'open', 'qr' => null]));
        }
        return $this->success($this->view($wid, ['state' => 'connecting', 'qr' => $qr['qr'], 'pairing_code' => $qr['pairing_code']]));
    }

    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) return $this->error('Website not found', 404);
        if (WhatsAppQrGateway::enabled()) $this->gateway()->logout(WhatsAppQrGateway::instanceName($wid));
        $this->db->query("UPDATE platform_connections SET status = 'disconnected', last_error = NULL, updated_at = NOW() WHERE website_id = ? AND platform = 'whatsapp_qr'", [$wid]);
        $this->log('WhatsApp QR disconnected', ['website_id' => $wid]);
        return $this->success($this->view($wid), 'WhatsApp disconnected');
    }

    /** الرسايل الواردة وتغيّر حالة الربط من البوابة */
    public function webhook(array $params = []): array
    {
        $wid = (int) ($params['website_id'] ?? 0);
        $secret = (string) $this->get('secret', '');
        if ($wid <= 0 || $secret === '' || !hash_equals(WhatsAppQrGateway::webhookSecret($wid), $secret)) return $this->error('Unauthorized webhook', 401);
        $payload = $this->all();
        unset($payload['secret']);
        $site = $this->db->query('SELECT id, user_id FROM websites WHERE id = ? LIMIT 1', [$wid])[0] ?? null;
        if (!$site) return $this->success([], 'Ignored');
        // الجلسة لازم تكون بتاعة الموقع ده بالظبط
        if (isset($payload['instance']) && is_string($payload['instance']) && !hash_equals(WhatsAppQrGateway::instanceName($wid), $payload['instance'])) return $this->success([], 'Ignored');

        $state = WhatsAppQrGateway::parseConnectionEvent($payload);
        if ($state === 'close') {
            $this->db->query("UPDATE platform_connections SET status = 'disconnected', last_error = ?, updated_at = NOW() WHERE website_id = ? AND platform = 'whatsapp_qr' AND status = 'connected'", ['WhatsApp was logged out from the phone. Scan the QR code again.', $wid]);
            try { Notification::notify((int) $site['user_id'], 'whatsapp_qr_disconnected', 'WhatsApp disconnected', 'Your WhatsApp number was logged out. Open Chat settings and scan the QR code again.', '/chat/settings'); } catch (Throwable $e) {}
            return $this->success([], 'State recorded');
        }
        if ($state === 'open') {
            $this->markConnected($wid, (int) $site['user_id'], WhatsAppQrGateway::instanceName($wid), []);
            return $this->success([], 'State recorded');
        }

        $msg = WhatsAppQrGateway::parseIncoming($payload);
        if (!$msg) return $this->success([], 'Ignored');
        $conn = $this->connection($wid);
        if (!$conn || $conn['status'] !== 'connected') return $this->success([], 'Ignored (not connected)');
        if ($msg['id'] !== '') {
            // البوابة ممكن تبعت نفس الرسالة أكتر من مرة: فحص صريح (مش معتمد على فهرس unique بس)
            try {
                if ($this->db->query("SELECT id FROM ai_webhook_events WHERE channel = 'whatsapp_qr' AND website_id = ? AND external_event_id = ? LIMIT 1", [$wid, $msg['id']])) {
                    return $this->success([], 'Duplicate ignored');
                }
                $this->db->query("INSERT INTO ai_webhook_events (website_id, channel, external_event_id, status, received_at) VALUES (?, 'whatsapp_qr', ?, 'received', NOW())", [$wid, $msg['id']]);
            } catch (Throwable $e) {
                return $this->success([], 'Duplicate ignored');
            }
        }
        try {
            $result = (new ChatManager())->processIncomingMessage([
                'user_id' => (int) $site['user_id'],
                'website_id' => $wid,
                'platform' => 'whatsapp',
                'phone_number' => $msg['phone'],
                'sender_name' => $msg['name'],
                'message' => $msg['text'],
            ]);
            return $this->success(['processed' => !empty($result['success'])]);
        } catch (Throwable $e) {
            Logger::error('WhatsApp QR webhook failed', ['website_id' => $wid, 'error' => $e->getMessage()]);
            // 200 عشان البوابة ماتعيدش نفس الرسالة في حلقة؛ الخطأ متسجل
            return $this->success(['processed' => false]);
        }
    }

    private function markConnected(int $wid, int $uid, string $name, array $profile): void
    {
        $c = $this->connection($wid);
        $phone = $profile['phone'] ?? ($c['external_location_id'] ?? null);
        $label = $profile['name'] ?? ($c['external_location_name'] ?? 'WhatsApp');
        if ($c) {
            $this->db->query("UPDATE platform_connections SET status = 'connected', external_account_id = ?, external_location_id = ?, external_location_name = ?, last_error = NULL, updated_at = NOW() WHERE id = ?", [$name, $phone, $label, (int) $c['id']]);
        } else {
            $this->db->query("INSERT INTO platform_connections (website_id, user_id, platform, external_account_id, external_location_id, external_location_name, status, connected_at, updated_at) VALUES (?, ?, 'whatsapp_qr', ?, ?, ?, 'connected', NOW(), NOW())", [$wid, $uid, $name, $phone, $label]);
        }
        if (!$c || $c['status'] !== 'connected') $this->log('WhatsApp QR connected', ['website_id' => $wid]);
    }
}
