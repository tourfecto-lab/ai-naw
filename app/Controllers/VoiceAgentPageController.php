<?php

/** Tourfecto - AI Voice & Sales Agent: the customer page (shell only; every number comes from the /api/voice-agent endpoints, scoped by the session). */
class VoiceAgentPageController extends Controller
{
    public function index(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $isAdmin = in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true);
        $lang = function_exists('current_lang') ? current_lang() : 'en';
        $boot = json_encode(['admin' => $isAdmin, 'lang' => $lang === 'ar' ? 'ar' : 'en'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $v = (string) @filemtime(__DIR__ . '/../../public_html/assets/voice/voice-agent.js');
        $body = '<link rel="stylesheet" href="/assets/voice/voice-agent.css?v=' . $v . '"><div id="voice-app" class="va" aria-live="polite"><p class="va-muted">Loading…</p></div>'
            . '<script>window.VOICE_BOOT=' . $boot . ';</script><script src="/assets/voice/voice-agent.js?v=' . $v . '" defer></script>';
        echo $this->renderPanelPage('voice_agent', 'AI Voice & Sales Agent', 'Answer, qualify and book from every call', $body);
    }
}
