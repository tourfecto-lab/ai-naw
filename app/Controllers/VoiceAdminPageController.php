<?php

/** Tourfecto - AI Voice & Sales Agent: the platform administration page (shell only; data comes from /api/admin/voice/*, admin-only). */
class VoiceAdminPageController extends Controller
{
    public function index(array $params = []): void
    {
        if (!$this->isAuthenticated() || !in_array((string) ($this->user['role'] ?? ''), ['admin', 'super_admin'], true)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $v = (string) @filemtime(__DIR__ . '/../../public_html/assets/voice/voice-admin.js');
        $body = '<link rel="stylesheet" href="/assets/voice/voice-agent.css?v=' . $v . '"><div id="voice-admin" class="va" aria-live="polite"><p class="va-muted">Loading…</p></div>'
            . '<script src="/assets/voice/voice-admin.js?v=' . $v . '" defer></script>';
        echo $this->renderPanelPage('voice', 'AI Voice Administration', 'Providers, switches, companies and safety controls', $body);
    }
}
