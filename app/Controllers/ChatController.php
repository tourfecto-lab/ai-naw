<?php

/**
 * Tourfecto - Chat Controller
 * متحكم الشات الذكي مع نظام الموافقات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatController extends Controller
{
    /**
     * @var ChatManager $chatManager - مدير الشات
     */
    private $chatManager;

    /**
     * @var SubscriptionValidator $subscription - مدقق الاشتراكات
     */
    private $subscription;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->chatManager = new ChatManager();
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * مجموعة أيقونات SVG موحّدة (Lucide-style) تُستخدم في كل صفحات موديول
     * الشات بدل الإيموجي. بنعرضها كـ sprite مخفي جوه الصفحة، وأي `<svg>`
     * بيشاور عليها بـ `<use href="#i-{name}">`. كده الصفحة كلها (PHP + JS)
     * بتيجي من مصدر واحد، والأيقونات vector => شاربحة على أي DPI.
     *
     * ملاحظة تصميم: الإيموجي كأيقونات عامل "غير احترافي" في مراجعات UI،
     * واستبدالها بـ SVG icons (Heroicons/Lucide) هو أول نقطة إصلاح.
     */
    private function chatIcons(): string
    {
        return '<svg style="display:none" xmlns="http://www.w3.org/2000/svg">'
            . '<symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></symbol>'
            . '<symbol id="i-inbox" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></symbol>'
            . '<symbol id="i-chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/><path d="M2 20h20"/></symbol>'
            . '<symbol id="i-book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></symbol>'
            . '<symbol id="i-sparkles" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/></symbol>'
            . '<symbol id="i-target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></symbol>'
            . '<symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></symbol>'
            . '<symbol id="i-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>'
            . '<symbol id="i-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></symbol>'
            . '<symbol id="i-handoff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/></symbol>'
            . '<symbol id="i-pause" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"/><rect x="6" y="4" width="4" height="16" rx="1"/></symbol>'
            . '<symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></symbol>'
            . '<symbol id="i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>'
            . '<symbol id="i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>'
            . '<symbol id="i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></symbol>'
            . '<symbol id="i-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></symbol>'
            . '<symbol id="i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></symbol>'
            . '<symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>'
            . '<symbol id="i-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>'
            . '<symbol id="i-user-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></symbol>'
            . '<symbol id="i-phone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>'
            . '<symbol id="i-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></symbol>'
            . '<symbol id="i-globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><line x1="2" y1="12" x2="22" y2="12"/></symbol>'
            . '<symbol id="i-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol>'
            . '<symbol id="i-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2H2v10l9.29 9.29a1 1 0 0 0 1.42 0l8.58-8.58a1 1 0 0 0 0-1.42Z"/><line x1="7" y1="7" x2="7.01" y2="7"/></symbol>'
            . '<symbol id="i-flag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></symbol>'
            . '<symbol id="i-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></symbol>'
            . '<symbol id="i-external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>'
            . '<symbol id="i-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></symbol>'
            . '<symbol id="i-fire" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></symbol>'
            . '<symbol id="i-dollar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></symbol>'
            . '<symbol id="i-phone-call" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.05 5A5 5 0 0 1 19 8.95"/><path d="M15.05 1A9 9 0 0 1 23 8.94"/><path d="m22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>'
            . '</svg>';
    }

    /**
     * دالة تولّد `<svg><use href="#i-{name}">` من السبريت أعلاه.
     * @param string $name اسم الأيقونة
     * @param string $extraClass كلاسات إضافية
     * @return string
     */
    private function ic(string $name, string $extraClass = ''): string
    {
        $cls = 'ic ' . $extraClass;
        return '<svg class="' . trim($cls) . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
    }

    /**
     * CSS مشترك لنظام الأيقونات الجديد + تحسينات الاحترافية
     * (حالة hover/focus/transition على كل العناصر التفاعلية).
     */
    private function chatUiCss(): string
    {
        return '<style>
            .ic { width:16px; height:16px; display:inline-block; vertical-align:-3px; }
            .p-btn .ic, button .ic { margin-inline-end:6px; }
            .p-btn { transition: background .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
            .p-btn:not(:disabled):hover { box-shadow: var(--panel-shadow-hover); }
            .p-btn:not(:disabled):active { transform: translateY(1px); }
            .p-btn:disabled { opacity:.55; cursor:not-allowed; }
            button, .p-btn, .ai-chat-item, .pill, select.p-select, input, textarea { outline:none; }
            button:focus-visible, .p-btn:focus-visible, select.p-select:focus-visible, input:focus-visible, textarea:focus-visible,
            .ai-chat-item:focus-visible { box-shadow: 0 0 0 3px var(--panel-accent-rgb, rgba(196,158,63,.35)); }
            .p-card { transition: box-shadow .2s ease; }
            .p-empty-icon { width:44px; height:44px; margin:0 auto 10px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                background:var(--panel-sidebar-bg-hover); color:var(--panel-text-muted); }
            .p-empty-icon svg { width:22px; height:22px; }
            .skeleton { position:relative; overflow:hidden; background:var(--panel-sidebar-bg-hover); border-radius:6px; min-height:16px; }
            .skeleton::after { content:""; position:absolute; inset:0; background:linear-gradient(90deg,transparent,rgba(255,255,255,.06),transparent); animation:chatShimmer 1.4s infinite; }
            @keyframes chatShimmer { 0%{transform:translateX(-100%);} 100%{transform:translateX(100%);} }
            @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; } }
        </style>';
    }

    /**
     * استبدال كل الـ placeholders التانية ({IC_*}، {ICON_SPRITE}، {CHAT_UI_CSS})
     * في الـ body النهائي قبل ما يتعرض. كده الصفحة تقدر تكتب الأيقونات
     * بنص رمزي readable في الـ heredoc بدل لزق الـ SVG في كل حتة.
     * @param string $html
     * @return string
     */
    private function applyChatUi(string $html): string
    {
        $html = str_replace('{ICON_SPRITE}', $this->chatIcons(), $html);
        $html = str_replace('{CHAT_UI_CSS}', $this->chatUiCss(), $html);

        $map = [
            'IC_SEARCH' => $this->ic('search'),
            'IC_INBOX' => $this->ic('inbox'),
            'IC_CHART' => $this->ic('chart'),
            'IC_BOOK' => $this->ic('book'),
            'IC_SPARKLES' => $this->ic('sparkles'),
            'IC_TARGET' => $this->ic('target'),
            'IC_CLOCK' => $this->ic('clock'),
            'IC_GEAR' => $this->ic('gear'),
            'IC_SEND' => $this->ic('send'),
            'IC_HANDOFF' => $this->ic('handoff'),
            'IC_PAUSE' => $this->ic('pause'),
            'IC_CHECK' => $this->ic('check'),
            'IC_X' => $this->ic('x'),
            'IC_PLUS' => $this->ic('plus'),
            'IC_TRASH' => $this->ic('trash'),
            'IC_EDIT' => $this->ic('edit'),
            'IC_REFRESH' => $this->ic('refresh'),
            'IC_ALERT' => $this->ic('alert'),
            'IC_USER' => $this->ic('user'),
            'IC_USER_PLUS' => $this->ic('user-plus'),
            'IC_PHONE' => $this->ic('phone'),
            'IC_MAIL' => $this->ic('mail'),
            'IC_GLOBE' => $this->ic('globe'),
            'IC_CHAT' => $this->ic('chat'),
            'IC_TAG' => $this->ic('tag'),
            'IC_FLAG' => $this->ic('flag'),
            'IC_EXTERNAL' => $this->ic('external'),
            'IC_WALLET' => $this->ic('wallet'),
            'IC_FIRE' => $this->ic('fire'),
            'IC_DOLLAR' => $this->ic('dollar'),
            'IC_CALL' => $this->ic('phone-call'),
        ];
        foreach ($map as $ph => $svg) {
            $html = str_replace('{' . $ph . '}', $svg, $html);
        }
        return $html;
    }

    /**
     * معالجة رسالة واردة (Webhook)
     * POST /api/chat/webhook
     * @param array $params
     * @return array
     */
    public function webhook(array $params = []): array
    {
        // مسار قديم: بيثق في user_id/api_key الجايين في الـ payload، وحمايته توكن مشترك واحد
        // للمنصة كلها (من غير توقيع). مقفول افتراضيًا؛ استخدم /api/whatsapp-cloud/webhook
        // و/api/meta/webhook (موقّعين بـ App Secret). للتفعيل المؤقت: LEGACY_CHAT_WEBHOOK_ENABLED=1
        if (!filter_var(env('LEGACY_CHAT_WEBHOOK_ENABLED') ?: '', FILTER_VALIDATE_BOOLEAN)) {
            Logger::warning('Legacy /api/chat/webhook blocked', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return $this->error('Gone: use the signed WhatsApp Cloud webhook', 410);
        }

        try {
            // التحقق من صحة الـ Webhook
            $verifyToken = $this->get('verify_token');
            if (!$this->validateWebhook($verifyToken)) {
                return $this->error('Invalid webhook token', 401);
            }

            // معالجة الرسالة
            $result = $this->chatManager->processIncomingMessage($this->all());

            return $result;

        } catch (Exception $e) {
            Logger::error('Chat Webhook Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    /**
     * التحقق من Webhook (لـ WhatsApp)
     * GET /api/chat/webhook
     * @param array $params
     * @return array
     */
    public function verifyWebhook(array $params = []): array
    {
        $mode = $this->get('hub.mode');
        $token = $this->get('hub.verify_token');
        $challenge = $this->get('hub.challenge');

        $verifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token ?? '')) {
            return [
                'success' => true,
                'challenge' => $challenge
            ];
        }

        return $this->error('Invalid verification token', 401);
    }

    /**
     * الحصول على رسائل المحادثة
     * GET /api/chat/messages
     * @param array $params
     * @return array
     */
    public function getMessages(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $websiteId = $this->get('website_id');
            $sessionId = $this->get('session_id');
            $status = $this->get('status');
            $page = (int) ($this->get('page', 1));
            $limit = (int) ($this->get('limit', 20));
            $offset = ($page - 1) * $limit;

            $sql = "SELECT * FROM chat_messages WHERE user_id = ?";
            $params = [$this->user['id']];

            if ($websiteId) {
                $sql .= " AND website_id = ?";
                $params[] = $websiteId;
            }

            if ($sessionId) {
                $sql .= " AND session_id = ?";
                $params[] = $sessionId;
            }

            if ($status) {
                $sql .= " AND bot_status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $messages = $this->db->query($sql, $params);

            // فك تشفير البيانات الحساسة
            foreach ($messages as &$msg) {
                if (!empty($msg['encrypted_phone'])) {
                    $encryption = new Encryption();
                    $msg['customer_phone'] = $encryption->decryptCustomerData(
                        $msg['encrypted_phone'],
                        $msg['customer_phone'] ?? ''
                    );
                }
                unset($msg['encrypted_phone']);
                unset($msg['encrypted_email']);
            }

            // جلب العدد الإجمالي
            $sqlCount = str_replace("SELECT *", "SELECT COUNT(*) as total", $sql);
            $sqlCount = substr($sqlCount, 0, strpos($sqlCount, "ORDER BY"));
            $countResult = $this->db->query($sqlCount, array_slice($params, 0, -2));
            $total = (int) ($countResult[0]['total'] ?? 0);

            return $this->success([
                'messages' => $messages,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (Exception $e) {
            Logger::error('Get Messages Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get messages', 500);
        }
    }

    /**
     * GET /api/chat/channels-strip - "status" strip at the top of the inbox: the owner's connected pages with their
     * profile pictures and a ring when a story went out in the last 24h. Read-only, DB only (no call to Meta).
     */
    public function channelsStrip(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) ($this->user['id'] ?? 0);
        $items = [];
        try {
            $rows = $this->db->query(
                "SELECT id, platform, external_location_name AS name FROM platform_connections
                  WHERE (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?))
                    AND status = 'connected' AND platform IN ('facebook', 'instagram', 'whatsapp_cloud')
                  ORDER BY id DESC LIMIT 12",
                [$uid, $uid]
            ) ?: [];
            $avatars = [];
            $stories = [];
            $ids = array_values(array_filter(array_map(static fn ($r) => (int) $r['id'], $rows)));
            if ($ids) {
                $in = implode(',', $ids);
                try {
                    foreach ($this->db->query("SELECT platform_connection_id, avatar_url, display_name FROM social_account_profiles WHERE platform_connection_id IN ($in)") ?: [] as $a) {
                        $avatars[(int) $a['platform_connection_id']] = $a;
                    }
                } catch (Throwable $e) {
                    error_log('[ChatController] channelsStrip avatars: ' . $e->getMessage());
                }
                try {
                    foreach ($this->db->query(
                        "SELECT DISTINCT t.platform_connection_id AS cid FROM social_post_targets t
                           JOIN social_posts p ON p.id = t.social_post_id
                          WHERE t.platform_connection_id IN ($in) AND t.status = 'published' AND p.post_format = 'story'
                            AND t.published_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
                    ) ?: [] as $st) {
                        $stories[(int) $st['cid']] = true;
                    }
                } catch (Throwable $e) {
                    // post_format needs the social-pro migration; without it there are simply no stories to show.
                }
            }
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $avatar = (string) ($avatars[$id]['avatar_url'] ?? '');
                $items[] = [
                    'id' => $id,
                    'platform' => (string) $r['platform'],
                    'name' => (string) (($avatars[$id]['display_name'] ?? '') ?: ($r['name'] ?? '')),
                    // Only https image URLs are ever rendered.
                    'avatar_url' => preg_match('#^https://#i', $avatar) ? $avatar : '',
                    'has_story' => !empty($stories[$id]),
                ];
            }
        } catch (Throwable $e) {
            error_log('[ChatController] channelsStrip: ' . $e->getMessage());
        }
        return $this->success(['items' => $items]);
    }

    /**
     * الحصول على رسائل في انتظار الموافقة
     * GET /api/chat/pending
     * @param array $params
     * @return array
     */
    public function getPendingApprovals(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $limit = (int) ($this->get('limit', 50));
            // Staff see and approve the replies of the account they work for.
            $ownerId = class_exists('TeamAccess') ? TeamAccess::billingOwner($this->user) : (int) $this->user['id'];
            $messages = ChatMessage::getPendingApprovals($ownerId, $limit);

            return $this->success([
                // تصحيح: كانت بترجع array من ChatMessage objects مباشرة -
                // json_encode بيحوّل أي object لـ "{}" فاضي لأن كل الخصائص
                // protected، يعني الـ frontend كان بيستلم صفوف فاضية تمامًا
                // (مفيش message_text ولا ai_reply_generated ولا أي حاجة).
                'pending' => array_map(fn ($m) => $m->toArray(), $messages),
                'count' => count($messages),
                'total_unread' => ChatMessage::getUnreadCount($ownerId)
            ]);

        } catch (Exception $e) {
            Logger::error('Get Pending Approvals Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get pending approvals', 500);
        }
    }

    /**
     * الموافقة على رد البوت
     * POST /api/chat/approve
     * @param array $params
     * @return array
     */
    public function approveReply(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $messageId = $this->get('message_id');
            $action = $this->get('action', 'approve');
            $editedReply = $this->get('edited_reply');

            if (!$messageId) {
                return $this->error('Message ID is required', 400);
            }
            $ownerId = class_exists('TeamAccess') ? TeamAccess::billingOwner($this->user) : (int) $this->user['id'];
            if ($ownerId !== (int) $this->user['id'] && in_array(TeamAccess::inboxRole($ownerId, (int) $this->user['id']), [null, 'viewer'], true)) {
                return $this->error('Your team role can view conversations but not reply or change them', 403);
            }

            // مركز الأتمتة: نحتفظ بالمسودة الأصلية قبل أي تعديل عشان نتعلم منه
            $draftBefore = null;
            $customerText = null;
            $msgRow = $this->db->query('SELECT ai_reply_generated, message_text FROM chat_messages WHERE id = ? AND user_id = ? LIMIT 1', [(int) $messageId, $ownerId]);
            if (!empty($msgRow)) {
                $draftBefore = (string) ($msgRow[0]['ai_reply_generated'] ?? '');
                $customerText = (string) ($msgRow[0]['message_text'] ?? '');
            }

            // لو المستخدم عدّل نص الرد المقترح قبل الموافقة، نحفظ التعديل
            // الأول قبل ما نوافق - عشان اللي يتبعت فعليًا يبقى النص المُعدّل
            // مش المسودة الأصلية اللي ولّدها الذكاء الاصطناعي.
            if ($csrfError=$this->verifyCsrf())return $csrfError;
            $lock='approved-reply:'.$ownerId.':'.(int)$messageId;
            if(!ExternalExecution::lock($lock))return $this->error('Reply approval is busy',409);
            try{
                if ($action === 'approve' && $editedReply !== null && trim((string)$editedReply) !== '') {
                    $this->db->query("UPDATE chat_messages SET ai_reply_generated=?,updated_at=NOW() WHERE id=? AND user_id=? AND bot_status='pending_approval'",[trim((string)$editedReply),(int)$messageId,$ownerId]);
                }
                $result=$this->chatManager->approveBotReply($messageId,$ownerId,$action);
            }finally{ExternalExecution::unlock($lock);}

            if (!$result['success']) {
                return $this->error($result['error'], 400);
            }

            if ($draftBefore !== null && class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                if ($action === 'approve') {
                    $finalText = ($editedReply !== null && trim((string) $editedReply) !== '') ? trim((string) $editedReply) : $draftBefore;
                    $engine->recordManualAction($ownerId, 'chat_replies', 'default', 'chat_message', (string) $messageId, $finalText, $customerText, $draftBefore);
                } else {
                    $engine->recordManualRejection($ownerId, 'chat_replies', 'chat_message', (string) $messageId);
                }
            }

            $this->log('Chat Approval', [
                'message_id' => $messageId,
                'action' => $action
            ]);

            return $this->success($result, $result['message'] ?? ($action === 'approve' ? 'Approved' : 'Rejected'));

        } catch (Exception $e) {
            Logger::error('Approve Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to process approval', 500);
        }
    }

    /**
     * إرسال رسالة يدوياً
     * POST /api/chat/send
     * @param array $params
     * @return array
     */
    public function sendMessage(array $params = []): array
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $phoneNumber = $this->get('phone_number');
            $message = $this->get('message');
            $websiteId = $this->get('website_id');
            $sessionId = $this->get('session_id');
            $platform = $this->get('platform', 'ultramsg');

            if (!$phoneNumber || !$message) {
                return $this->error('Phone number and message are required', 400);
            }

            // التحقق من صلاحية الاشتراك
            $subscription = $this->subscription->validateSubscription($this->user['id']);
            if (!$subscription['valid']) {
                return $this->error('No active subscription', 403);
            }

            // الموقع لازم يكون بتاع صاحب الحساب - مايتبعتش من رقم عميل تاني
            if ($websiteId) {
                $ownerId = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
                if (empty($this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [(int) $websiteId, $ownerId]))) {
                    return $this->error('Website not found', 404);
                }
            }

$creditsCheck = $this->subscription->reserveChatCredits((int) $this->user['id'], 1);
            if (empty($creditsCheck['available'])) return $this->error($creditsCheck['message'] ?? 'رصيدك غير كافٍ',402);
            // لو مفيش website_id صريح، نحاول نستنتجه من جلسة موجودة (نفس
            // session_id) - عشان الإرسال يستخدم اتصال UltraMsg الصح بتاع
            // العميل ده تحديدًا، مش مثيل واحد ثابت للموقع كله.
            if (!$websiteId && $sessionId) {
                $existing = $this->db->query(
                    "SELECT website_id FROM chat_messages WHERE session_id = ? AND user_id = ? LIMIT 1",
                    [$sessionId, $this->user['id']]
                );
                if (!empty($existing)) {
                    $websiteId = $existing[0]['website_id'];
                }
            }

            // إرسال الرسالة (واتساب بأي ربط: رسمي، QR، أو UltraMsg القديم - من رقم الموقع نفسه)
            if (in_array($platform, ['ultramsg', 'whatsapp'], true) && $websiteId) {
                $sent = $this->chatManager->sendMessageForWebsite((int) $websiteId, $phoneNumber, $message);
            } elseif ($platform === 'webchat' && $websiteId) {
                $sent = $this->chatManager->sendMessageForWebsite((int) $websiteId, $phoneNumber, $message, 'webchat');
            } else {
                $sent = $this->chatManager->sendMessage($phoneNumber, $message, $platform);
            }

            if (!$sent) {
                return $this->error('Failed to send message', 500);
            }

            // تصحيح (2026-08-25): كان بيتحقق من الاشتراك بس بدون استهلاك
            // الرصيد - يعني حد رسائل الشات المعروض في الباقات (100/500/2000)
            // مكنش بيتفرض خالص. دلوقتي بنستهلك رصيدًا بعد نجاح الإرسال،
            // مع الرجوع للمحفظة لو الاستخدام "ادفع حسب الاستخدام".
            
            $viaWallet = $creditsCheck['source'] === 'wallet';
            if (!$this->subscription->consumeChatCredits((int) $this->user['id'], 1, $viaWallet)) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            // حفظ الرسالة الصادرة
            $encryption = new Encryption();
            $encryptedPhone = $encryption->encryptCustomerData($phoneNumber, $phoneNumber);

            $sql = "INSERT INTO chat_messages (
                        website_id, user_id, platform, customer_phone, encrypted_phone,
                        message_direction, message_text, bot_status, sent_at, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, 'outgoing', ?, 'sent', NOW(), NOW()
                    )";

            $this->db->query($sql, [
                $websiteId ?? 0,
                $this->user['id'],
                $platform,
                $phoneNumber,
                $encryptedPhone,
                $message
            ]);

            $this->log('Manual Message Sent', [
                'phone' => $phoneNumber,
                'platform' => $platform
            ]);

            return $this->success([
                'phone_number' => $phoneNumber,
                'message' => $message
            ], 'Message sent successfully');

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Send Message Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to send message', 500);
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * الحصول على إعدادات البوت
     * GET /api/chat/settings
     * @param array $params
     * @return array
     */
    public function getSettings(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $websiteId = $this->get('website_id');
            $platform = $this->get('platform', 'all');

            if (!$websiteId) {
                return $this->error('Website ID is required', 400);
            }

            $settings = BotSetting::getSettings($this->user['id'], $websiteId, $platform);
            $settingsArr = $settings->toArray();
            if (class_exists('AutomationEngine')) {
                $settingsArr['auto_pilot'] = AutomationEngine::instance()->effectiveMode((int) $this->user['id'], 'chat_replies', 'default') === 'auto' ? 1 : 0;
            }

            return $this->success([
                'settings' => $settingsArr
            ]);

        } catch (Exception $e) {
            Logger::error('Get Settings Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to get settings', 500);
        }
    }

    /**
     * تحديث إعدادات البوت
     * PUT /api/chat/settings
     * @param array $params
     * @return array
     */
    public function updateSettings(array $params = []): array
    {
        try {
            if (!$this->isAuthenticated()) {
                return $this->error('Unauthorized', 401);
            }

            $websiteId = $this->get('website_id');
            $platform = $this->get('platform', 'all');
            $settings = $this->get('settings');

            if (!$websiteId || !$settings) {
                return $this->error('Website ID and settings are required', 400);
            }

            $botSettings = BotSetting::getSettings($this->user['id'], $websiteId, $platform);
            $botSettings->updateSettings($settings);

            // مفتاح "الرد التلقائي" هنا بقى اختصار لوضع الشات في مركز الأتمتة
            if (is_array($settings) && array_key_exists('auto_pilot', $settings) && class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                $current = $engine->getPolicy((int) $this->user['id'], 'chat_replies')['mode'];
                $wantAuto = (int) $settings['auto_pilot'] === 1;
                if ($wantAuto && $current !== 'auto') {
                    $engine->setPolicy((int) $this->user['id'], 'chat_replies', 'auto');
                } elseif (!$wantAuto && $current === 'auto') {
                    $engine->setPolicy((int) $this->user['id'], 'chat_replies', 'approval');
                }
            }

            $this->log('Bot Settings Updated', [
                'website_id' => $websiteId,
                'platform' => $platform
            ]);

            return $this->success([
                'settings' => $botSettings->toArray()
            ], 'Settings updated successfully');

        } catch (Exception $e) {
            Logger::error('Update Settings Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to update settings', 500);
        }
    }

    /**
     * التحقق من صحة Webhook
     * @param string|null $token
     * @return bool
     */
    private function validateWebhook(?string $token): bool
    {
        $verifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
        if ($verifyToken === '') {
            return false;
        }
        return hash_equals($verifyToken, $token ?? '');
    }

    // ============================================
    // صفحات الويب الفعلية (كانت بترجع JSON فاضي بدل صفحة حقيقية)
    // ============================================

    /**
     * GET /chat/
     *
     * ⚠️ ملاحظة توثيق مهمة (AI Chat Platform - توحيد Frontend/Backend):
     * الصفحة دي كانت بتعرض قائمة مسطّحة من `chat_messages` (بيانات قديمة/
     * Legacy) وبتفتح `/chat/conversation/{session_id}`. دلوقتي بقت Unified
     * Inbox حقيقية: بتجيب المحادثات من `/api/ai-chat/websites/{id}/conversations`
     * (بند 1، 15، 16 من AI Chat Backend) بدل الجدول القديم مباشرة، وكل
     * صف بيوَدّي لـ `/chat/conversation/{ai_conversation_id}` (نفس الـRoute
     * الموجود، بس الـID دلوقتي هو معرّف المحادثة الموحّدة مش session_id).
     *
     * التصميم والـCSS classes وطريقة الصفحة (Panel/panel.js) اتحافظ
     * عليها 100% زي ما هي - نفس نظام `.p-toolbar`/`.p-card`/`.p-table`.
     */
    public function index(array $params = []): array
    {
        $currentUserId = (int) ($this->user['id'] ?? 0);
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <style>
            .ai-chat-split { display:grid; grid-template-columns: 372px minmax(0,1fr); gap:14px; align-items:start; }
            .ai-chat-list-card { overflow:hidden; }
            .ai-chat-list { max-height: calc(100vh - 235px); overflow-y:auto; scrollbar-width:thin; }
            .ai-chat-item { padding:12px 14px; border-bottom:1px solid var(--panel-border); cursor:pointer; transition:background .18s ease, box-shadow .18s ease; }
            .ai-chat-item:hover { background: var(--panel-sidebar-bg-hover); }
            .ai-chat-item.active { background: var(--panel-accent-light); box-shadow: 3px 0 0 var(--panel-accent) inset; }
            .ai-chat-item .r1 { display:flex; align-items:center; gap:10px; }
            .ai-chat-avatar { width:38px; height:38px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:15px; background:var(--panel-info-light); color:var(--panel-info); }
            .ai-chat-avatar.whatsapp { background:var(--panel-success-light); color:var(--panel-success); }
            .ai-chat-avatar.email { background:var(--panel-warning-light); color:var(--panel-warning); }
            .ai-chat-item .nm { flex:1; min-width:0; }
            .ai-chat-item .nm b { display:block; font-size:13.5px; color:var(--panel-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .ai-chat-item .nm small { display:block; color:var(--panel-text-muted); font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .ai-chat-item .ch { font-size:11px; white-space:nowrap; }
            .ai-chat-item .tm { color:var(--panel-text-muted); font-size:11px; white-space:nowrap; }
            .ai-chat-item .ub { min-width:20px; height:20px; border-radius:10px; background:var(--panel-danger); color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; padding:0 6px; }
            .ai-chat-bubbles { display:flex; flex-direction:column; gap:8px; }
            .ai-bubble { max-width:78%; padding:10px 14px; border-radius:14px; font-size:13.5px; line-height:1.6; word-break:break-word; }
            .ai-bubble.in { background:var(--panel-card-bg-2); border:1px solid var(--panel-border); align-self:flex-start; border-bottom-left-radius:4px; }
            .ai-bubble.out { background:var(--panel-accent); color:#14100a; align-self:flex-end; border-bottom-right-radius:4px; }
            .ai-bubble .ai-tag { font-size:10px; opacity:.8; display:block; margin-bottom:2px; }
            .ai-bubble .ai-tag svg { width:12px; height:12px; vertical-align:-2px; }
            .ai-bubble .bt { font-size:10px; opacity:.65; margin-top:4px; text-align:left; }
            .ai-sugg { border:1px solid var(--panel-info); background:var(--panel-info-light); }
            .ai-sugg:hover { border-color:var(--panel-info); }
            .ai-conv-head { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
            .ai-quote-card { border:1px solid var(--panel-accent); border-radius:var(--panel-radius-sm); overflow:hidden; margin-top:10px; }
            .ai-quote-card .q-head { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:var(--panel-accent-light); }
            .ai-quote-card .q-head svg { width:16px; height:16px; vertical-align:-3px; margin-inline-end:6px; }
            .ai-quote-row { display:flex; justify-content:space-between; gap:10px; padding:6px 14px; font-size:13px; }
            .ai-quote-row.total { border-top:1px dashed var(--panel-border); font-weight:700; margin-top:4px; padding-top:8px; }
            .ai-quote-actions { display:flex; gap:6px; padding:10px 14px; flex-wrap:wrap; }
            .q-row { display:grid; grid-template-columns: 1fr 64px 90px 26px; gap:6px; margin-bottom:6px; align-items:center; }
            .ai-day-sep { align-self:center; font-size:11px; padding:3px 12px; border-radius:10px; background:var(--panel-sidebar-bg-hover); color:var(--panel-text-muted); margin:6px 0; }
            .ai-bubble .bt { display:flex; gap:6px; justify-content:flex-end; align-items:center; }
            .ai-bubble .who { font-size:10px; opacity:.7; display:block; margin-bottom:2px; }
            .ai-tick { font-size:12.5px; letter-spacing:-3px; padding-inline-end:3px; }
            .ai-bubble.out .ai-tick.read { color:#fff; font-weight:700; }
            .ai-bubble.in .ai-tick.read { color:var(--panel-info); }
            .ai-tick.failed { color:var(--panel-danger); font-weight:700; letter-spacing:0; }
            .ai-bubble.failed { box-shadow:0 0 0 1.5px var(--panel-danger); }
            .ai-bubble.pending { opacity:.65; }
            .ai-bubble.ghost { background:transparent; border:1px dashed var(--panel-border); color:var(--panel-text-muted); align-self:flex-end; }
            .ai-bubble .ai-retry { border:0; background:none; color:inherit; text-decoration:underline; cursor:pointer; font-size:11px; padding:0; font-weight:700; }
            .ai-media-img { max-width:min(240px,100%); max-height:280px; border-radius:10px; display:block; margin-bottom:4px; background:rgba(0,0,0,.06); }
            .ai-media-audio { width:240px; max-width:100%; display:block; margin-bottom:2px; }
            .ai-media-video { max-width:min(260px,100%); border-radius:10px; display:block; margin-bottom:4px; }
            .ai-media-doc { display:inline-flex; align-items:center; gap:6px; padding:8px 10px; margin-bottom:4px; border-radius:8px; background:rgba(0,0,0,.08); color:inherit; text-decoration:none; word-break:break-all; }
            .wa-window { font-size:12.5px; padding:7px 10px; border-radius:8px; margin-bottom:8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
            .wa-window.ok { background:var(--panel-success-light); color:var(--panel-success); }
            .wa-window.warn { background:var(--panel-warning-light); color:var(--panel-warning); }
            .wa-window.closed { background:var(--panel-danger-light, rgba(220,38,38,.12)); color:var(--panel-danger); }
            .tpl-item { border:1px solid var(--panel-border); border-radius:8px; padding:8px 10px; margin-bottom:6px; cursor:pointer; }
            .tpl-item:hover, .tpl-item.sel { border-color:var(--panel-accent); }
            .tpl-item.off { opacity:.5; cursor:not-allowed; }
            .cat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; max-height:320px; overflow-y:auto; }
            .cat-item { border:1px solid var(--panel-border); border-radius:8px; overflow:hidden; cursor:pointer; font-size:12.5px; }
            .cat-item:hover { border-color:var(--panel-accent); }
            .cat-item img { width:100%; height:80px; object-fit:cover; display:block; }
            .cat-item div { padding:6px 8px; }
            .tpl-preview { white-space:pre-wrap; font-size:13px; padding:10px; border-radius:8px; background:var(--panel-sidebar-bg-hover); margin:8px 0; }
            @media (max-width: 960px) { .ai-chat-split { grid-template-columns: 1fr; } .ai-chat-list { max-height: 320px; } }
            @media (max-width: 560px) { .q-row { grid-template-columns: 1fr 64px 90px; } .q-row .q-del { grid-column:3; } }
            /* ===== WhatsApp-style inbox (colours/shape follow the official app; markup and JS are unchanged) ===== */
            .ai-chat-split{--wa-bg:#efeae2;--wa-panel:#fff;--wa-header:#f0f2f5;--wa-line:#e9edef;--wa-in:#fff;--wa-out:#d9fdd3;--wa-text:#111b21;--wa-sub:#54656f;--wa-accent:#00a884;--wa-active:#f0f2f5;--wa-tick:#53bdeb;--wa-pill:#fff;--wa-shadow:0 1px .5px rgba(11,20,26,.13)}
            html[data-theme="dark"] .ai-chat-split{--wa-bg:#0b141a;--wa-panel:#111b21;--wa-header:#202c33;--wa-line:#222d34;--wa-in:#202c33;--wa-out:#005c4b;--wa-text:#e9edef;--wa-sub:#8696a0;--wa-accent:#00a884;--wa-active:#2a3942;--wa-tick:#53bdeb;--wa-pill:#182229;--wa-shadow:0 1px .5px rgba(11,20,26,.4)}
            .ai-chat-split{gap:0;border:1px solid var(--wa-line);border-radius:12px;overflow:hidden;background:var(--wa-panel);align-items:stretch;min-height:540px;box-shadow:0 6px 24px -12px rgba(11,20,26,.35)}
            .ai-chat-split .ai-chat-list-card{border:0;border-radius:0;background:var(--wa-panel);border-inline-end:1px solid var(--wa-line);box-shadow:none}
            .ai-chat-split .ai-chat-list-card .p-card-head{background:var(--wa-header);margin:0;padding:14px 16px;border-bottom:1px solid var(--wa-line)}
            .ai-chat-split .ai-chat-list-card .p-card-head h3{color:var(--wa-text);font-size:16px;font-weight:600}
            .ai-chat-split .ai-chat-list-card .p-card-sub{color:var(--wa-sub)}
            .ai-chat-split .ai-chat-list{max-height:calc(100vh - 290px);min-height:420px}
            .ai-chat-split .ai-chat-item{padding:0 16px;min-height:72px;display:flex;align-items:center;border-bottom:0;background:transparent;box-shadow:none;position:relative}
            .ai-chat-split .ai-chat-item::after{content:"";position:absolute;inset-inline-start:77px;inset-inline-end:0;bottom:0;border-bottom:1px solid var(--wa-line)}
            .ai-chat-split .ai-chat-item:hover{background:var(--wa-header)}
            .ai-chat-split .ai-chat-item.active{background:var(--wa-active);box-shadow:none}
            .ai-chat-split .ai-chat-item .r1{width:100%;gap:14px;padding:11px 0}
            .ai-chat-split .ai-chat-avatar{width:49px;height:49px;font-size:19px;font-weight:600;background:#6a7175;color:#fff}
            .ai-chat-split .ai-chat-avatar.whatsapp{background:#00a884;color:#fff}
            .ai-chat-split .ai-chat-avatar.email{background:#8b7cf6;color:#fff}
            .ai-chat-split .ai-chat-item .nm b{font-size:16.5px;font-weight:400;color:var(--wa-text)}
            .ai-chat-split .ai-chat-item .nm small{font-size:14px;color:var(--wa-sub)}
            .ai-chat-split .ai-chat-item .tm{font-size:12px;color:var(--wa-sub)}
            .ai-chat-split .ai-chat-item .ub{background:#25d366;color:#0b141a;min-width:20px;height:20px;border-radius:10px;font-size:12px;font-weight:600}
            .ai-chat-split .ai-chat-item .ch{color:var(--wa-sub)}
            
            #ucEmptyState{border:0;border-radius:0;height:100%;display:flex;align-items:center;justify-content:center;background:var(--wa-header);box-shadow:none;border-bottom:6px solid var(--wa-accent)}
            #ucEmptyState .p-empty{color:var(--wa-sub)}
            #convHeader{border:0;border-radius:0;margin:0!important;background:var(--wa-header);padding:10px 16px;border-bottom:1px solid var(--wa-line);box-shadow:none;color:var(--wa-text)}
            #leadPanel{border:0;border-radius:0;margin:0!important;background:var(--wa-panel);border-bottom:1px solid var(--wa-line);box-shadow:none}
            #convThread{flex:1;border:0;border-radius:0;box-shadow:none;margin:0!important;padding:18px 7%;background-color:var(--wa-bg);background-image:radial-gradient(rgba(134,150,160,.10) 1.2px,transparent 1.4px),radial-gradient(rgba(134,150,160,.07) 1px,transparent 1.2px);background-size:26px 26px,40px 40px;background-position:0 0,13px 13px}
            .ai-chat-split .ai-chat-bubbles{gap:2px}
            .ai-chat-split .ai-bubble{max-width:65%;padding:6px 9px 7px;border-radius:8px;font-size:14.2px;line-height:1.45;color:var(--wa-text);box-shadow:var(--wa-shadow);position:relative;border:0;margin-top:8px}
            .ai-chat-split .ai-bubble.in{background:var(--wa-in);border-start-start-radius:0}
            .ai-chat-split .ai-bubble.out{background:var(--wa-out);color:var(--wa-text);border-start-end-radius:0}
            .ai-chat-split .ai-bubble.in + .ai-bubble.in,.ai-chat-split .ai-bubble.out + .ai-bubble.out{margin-top:2px;border-radius:8px}
            .ai-chat-split .ai-bubble.in::before,.ai-chat-split .ai-bubble.out::before{content:"";position:absolute;top:0;width:8px;height:13px;background:inherit}
            .ai-chat-split .ai-bubble.in::before{inset-inline-start:-8px;clip-path:polygon(100% 0,100% 100%,0 0)}
            .ai-chat-split .ai-bubble.out::before{inset-inline-end:-8px;clip-path:polygon(0 0,0 100%,100% 0)}
            [dir="rtl"] .ai-chat-split .ai-bubble.in::before{clip-path:polygon(0 0,0 100%,100% 0)}
            [dir="rtl"] .ai-chat-split .ai-bubble.out::before{clip-path:polygon(100% 0,100% 100%,0 0)}
            .ai-chat-split .ai-bubble.in + .ai-bubble.in::before,.ai-chat-split .ai-bubble.out + .ai-bubble.out::before{display:none}
            .ai-chat-split .ai-bubble .bt{font-size:11px;color:var(--wa-sub);opacity:1;margin-top:2px;text-align:end}
            .ai-chat-split .ai-bubble .ai-tag,.ai-chat-split .ai-bubble .who{color:var(--wa-accent);opacity:1;font-weight:600;font-size:12px}
            .ai-chat-split .ai-bubble.out .ai-tick.read,.ai-chat-split .ai-bubble.in .ai-tick.read{color:var(--wa-tick)}
            .ai-chat-split .ai-day-sep{background:var(--wa-pill);color:var(--wa-sub);box-shadow:var(--wa-shadow);border-radius:8px;padding:5px 12px;font-size:12.5px;margin:12px 0 6px}
            #ucReplyCard{border:0;border-radius:0;margin:0!important;background:var(--wa-header);padding:10px 16px 12px;box-shadow:none;border-top:1px solid var(--wa-line)}
            #ucReplyCard .p-card-head{padding:0 0 8px;margin:0;border:0}
            #ucReplyCard .p-card-head h3{color:var(--wa-sub);font-size:12.5px;font-weight:500}
            #ucReplyCard #manualMessage{background:var(--wa-panel);color:var(--wa-text);border:0;border-radius:22px;padding:11px 18px;min-height:44px;resize:none;box-shadow:none;font-size:15px}
            #ucReplyCard #manualMessage:focus{outline:none;box-shadow:0 0 0 2px rgba(0,168,132,.35)}
            #ucReplyCard .form-group{margin-bottom:8px}
            #ucReplyCard #sendManualBtn{background:#008069;color:#fff;border:0;border-radius:50%;width:46px;height:46px;padding:0;justify-content:center}#ucReplyCard #sendManualBtn .wa-lbl{display:none}#ucReplyCard #sendManualBtn .ic{width:20px;height:20px}#ucReplyCard #sendManualBtn{order:9;margin-inline-start:auto}#ucReplyCard #suggestBtn{order:5}#ucReplyCard #attachBtn,#ucReplyCard #templateBtn,#ucReplyCard #catalogBtn,#ucReplyCard #interactiveBtn{border-radius:50%;width:42px;height:42px;padding:0;justify-content:center;font-size:18px}#ucReplyCard #attachBtn .wa-lbl,#ucReplyCard #templateBtn .wa-lbl,#ucReplyCard #catalogBtn .wa-lbl,#ucReplyCard #interactiveBtn .wa-lbl{display:none}
            #ucReplyCard #sendManualBtn:hover{filter:brightness(1.08)}
            #ucReplyCard .p-btn.outline{background:transparent;border-color:transparent;color:var(--wa-sub)}
            #ucReplyCard .p-btn.outline:hover{background:var(--wa-active);color:var(--wa-text)}
            @media (max-width:960px){.ai-chat-split{min-height:0;border-radius:10px}.ai-chat-split .ai-chat-list{max-height:340px;min-height:0}.ai-chat-split .ai-chat-list-card{border-inline-end:0;border-bottom:1px solid var(--wa-line)}.ai-chat-split .ai-bubble{max-width:82%}#convThread{padding:14px 10px}}
            .ai-chat-split .ai-chat-item .r1 + .r1{position:absolute;top:11px;inset-inline-end:16px;width:auto;padding:0;margin:0!important;gap:6px}
            .ai-chat-split .ai-chat-item .r1 + .r1 .tm{flex:0 0 auto!important}
            .ai-chat-split .ai-chat-item .nm{padding-inline-end:46px}
            .ai-chat-split .ai-chat-item .ch{font-size:10px;max-width:64px;overflow:hidden}
            #ucThreadPanel[style*="block"]{display:flex!important;flex-direction:column;height:100%}
            #ucThreadPanel{height:100%}
            #ucReplyCard .p-card-head{flex-wrap:wrap;gap:6px}
            #ucReplyCard .p-card-head h3{flex:1 1 100%}
            #ucReplyCard .p-card-head .p-btn{white-space:nowrap}
            .wa-back{display:none}
            @media (max-width:960px){.ai-chat-split:not(.wa-chat-open)>div:last-child{display:none}.ai-chat-split.wa-chat-open .ai-chat-list-card{display:none}.wa-back{display:flex;align-items:center;gap:6px;width:100%;padding:10px 14px;background:var(--wa-header);border:0;border-bottom:1px solid var(--wa-line);color:var(--wa-accent);font:inherit;font-weight:600;cursor:pointer}.ai-chat-split.wa-chat-open #convThread{max-height:calc(100vh - 340px)!important}}
        </style>

        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;align-items:center;">
            <select id="ucSite" class="p-select" style="display:none;" title="Website"></select>
            <input type="text" id="ucSearch" class="form-control" placeholder="Search by name, phone or email..." style="max-width:230px;flex:1;min-width:170px;">
            <select id="ucAssigned" class="p-select" onchange="ucApplyFilters()">
                <option value="">All conversations</option>
                <option value="me">Assigned to me</option>
                <option value="none">Unassigned</option>
            </select>
            <select id="ucStatus" class="p-select">
                <option value="">All statuses</option>
                <option value="open">Open</option>
                <option value="pending">Pending</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
            </select>
            <select id="ucAiStatus" class="p-select">
                <option value="">AI or agent</option>
                <option value="ai">🤖 AI</option>
                <option value="human">👤 Employee</option>
                <option value="paused">⏸ Paused</option>
            </select>
            <details class="uc-more" style="position:relative;">
            <summary class="p-btn outline xs" style="list-style:none;cursor:pointer;">More filters ▾</summary>
            <div style="position:absolute;z-index:20;inset-inline-start:0;top:110%;display:flex;flex-direction:column;gap:6px;padding:10px;background:var(--panel-card-bg,#fff);border:1px solid var(--panel-border,#e5e7eb);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.12);min-width:200px;">
            <select id="ucLeadStatus" class="p-select">
                <option value="">All statuses of Lead</option>
                <option value="new_inquiry">New inquiry</option>
                <option value="qualifying">Qualifying</option>
                <option value="qualified">Qualified</option>
                <option value="hot_lead">🔥 Lead Hot</option>
                <option value="converted">Handed off</option>
                <option value="lost">Lost</option>
            </select>
            <select id="ucChannel" class="p-select">
                <option value="">All channels</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="website_chat">Website chat</option>
                <option value="messenger">Messenger</option>
                <option value="instagram">Instagram</option>
                <option value="email">Email</option>
            </select>
            <select id="ucTag" class="p-select">
                <option value="">All tags</option>
                <option value="HOT_LEAD">🔥 Hot lead</option>
                <option value="NEW_INQUIRY">New inquiry</option>
                <option value="PRICE_REQUEST">Price request</option>
                <option value="COMPLAINT">Complaint</option>
                <option value="FOLLOW_UP">Needs follow-up</option>
                <option value="BOOKING_INTENT">Wants to book</option>
                <option value="VIP">VIP</option>
                <option value="HUMAN_REQUIRED">Needs a human</option>
            </select>
            </div>
            </details>
            <button class="p-btn outline xs" onclick="ucApplyFilters()">{IC_SEARCH}Search</button>
            <div style="flex:1 1 0;min-width:8px;"></div>
            <button type="button" class="p-btn outline xs" id="ucSoundBtn" onclick="toggleSound()" title="Sound and desktop alert for new messages">🔔</button>
            <a href="/chat/pending" class="p-btn outline xs">{IC_CLOCK}Pending</a>
        </div>

        <div id="ucNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">{IC_GLOBE}</div>Choose a website from the list at the top of the page first to view its conversations.</div>
        </div>

        <div class="ai-chat-split" id="ucBody" style="display:none;">
            <div class="p-card no-pad ai-chat-list-card">
                <div class="p-card-head" style="padding:14px 16px 10px;">
                    <h3>{IC_INBOX} Conversations</h3>
                    <span class="p-card-sub" id="ucCount"></span>
                </div>
                <div class="ai-chat-list" id="ucList">
                    <div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">{IC_CLOCK}</div>Loading...</div>
                </div>
            </div>

            <div>
                <div id="ucEmptyState" class="p-card">
                    <div class="p-empty"><div class="p-empty-icon">{IC_CHAT}</div>Choose a conversation from the list to view it here</div>
                </div>

                <div id="ucThreadPanel" style="display:none;">
                    <button type="button" class="wa-back" onclick="document.getElementById('ucBody').classList.remove('wa-chat-open');document.body.classList.remove('wa-in-chat')">&#8592; Conversations</button>
                    <div class="p-card" id="convHeader" style="margin-bottom:14px;"></div>
                    <div class="p-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="p-card" id="convThread" style="max-height:calc(100vh - 420px);min-height:260px;overflow-y:auto;"></div>
                    <div class="p-card" id="ucReadOnly" style="margin-top:14px;display:none;">
                        <div class="p-cell-muted">👁 Your team role can view conversations but not reply. Ask the account owner to change your role to Member to reply.</div>
                    </div>
                    <div class="p-card" id="ucReplyCard" style="margin-top:14px;">
                        <div class="p-card-head" style="display:flex;align-items:center;justify-content:space-between;">
                            <h3>{IC_SEND} Reply</h3>
                            <button class="p-btn outline xs" onclick="quoteToggleComposer()">{IC_WALLET}Price quote</button>
                            <button class="p-btn outline xs" onclick="payLinkToggle()">💳 Payment link</button>
                        </div>
                        <div id="payLinkBox" style="display:none;margin-bottom:12px;"></div>
                        <div id="quoteComposer" style="display:none;margin-bottom:12px;"></div>
                        <div id="quoteList" style="display:none;margin-bottom:12px;"></div>
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div id="waWindow" class="wa-window" style="display:none;"></div>
                        <div id="templateBox" style="display:none;margin-bottom:12px;"></div>
                        <div id="catalogBox" style="display:none;margin-bottom:12px;"></div>
                        <div id="interactiveBox" style="display:none;margin-bottom:12px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="Write your reply here... (Enter to send, Shift+Enter for a new line)"></textarea>
                        </div>
                        <input type="file" id="attachInput" style="display:none;" accept="image/jpeg,image/png,video/mp4,video/3gpp,audio/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" onchange="sendAttachment(this)">
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()" aria-label="Send" title="Send">{IC_SEND}<span class="wa-lbl">Send</span></button>
                            <button class="p-btn outline" id="attachBtn" style="display:none;" onclick="document.getElementById('attachInput').click()" title="Send an image, voice note, video or file" aria-label="File">📎<span class="wa-lbl"> File</span></button>
                            <button class="p-btn outline" id="templateBtn" style="display:none;" onclick="templateToggle()" title="Approved WhatsApp templates can be sent any time" aria-label="Template">📋<span class="wa-lbl"> Template</span></button>
                            <button class="p-btn outline" id="interactiveBtn" style="display:none;" onclick="interactiveToggle()" title="Send quick-reply buttons or an option list" aria-label="Buttons">&#128280;<span class="wa-lbl"> Buttons</span></button>
                            <button class="p-btn outline" id="catalogBtn" style="display:none;" onclick="catalogToggle()" title="Send your WhatsApp catalog or one trip" aria-label="Catalog">🛍<span class="wa-lbl"> Catalog</span></button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">{IC_SPARKLES}Suggest a reply AI</button>
                            <div style="flex:1;"></div>
                            <a href="/chat/leads" class="p-btn outline xs" style="align-self:center;">{IC_TARGET}View theLeads</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo;
    const currentUserId = __CURRENT_USER_ID__;
    // WhatsApp-style shell: filters live inside the list column, and a "status" strip of the connected pages sits on top.
    document.body.classList.add('wa-app');
    (function waShell() {
        const list = document.getElementById('ucList');
        if (!list) return;
        const card = list.parentNode;
        const strip = document.createElement('div');
        strip.id = 'waStrip';
        strip.className = 'wa-strip';
        strip.style.display = 'none';
        card.insertBefore(strip, list);
        const search = document.getElementById('ucSearch');
        const bar = search && search.closest('.p-toolbar');
        if (bar) card.insertBefore(bar, list);

        function node(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
        function item(href, label, ring, avatar, glyph) {
            const a = node('a', 'wa-st');
            a.href = href;
            const r = node('span', 'wa-ring' + (ring ? ' has-story' : ''));
            if (avatar && /^https:\/\//i.test(avatar)) {
                const img = node('img');
                img.src = avatar; img.alt = ''; img.loading = 'lazy'; img.referrerPolicy = 'no-referrer';
                img.onerror = function () { img.remove(); r.appendChild(node('b', '', (label || '?').trim().charAt(0).toUpperCase())); };
                r.appendChild(img);
            } else {
                r.appendChild(node('b', '', glyph || (label || '?').trim().charAt(0).toUpperCase()));
            }
            a.appendChild(r);
            a.appendChild(node('small', '', label));
            return a;
        }
        fetchJSON('/api/chat/channels-strip').then(function (res) {
            const items = (res && res.success && res.data && res.data.items) || [];
            if (!items.length) return;
            strip.appendChild(item('/social', 'My story', false, '', '+'));
            items.forEach(function (i) {
                const a = item('/social', i.name || i.platform, i.has_story, i.avatar_url, '');
                a.classList.add('pf-' + String(i.platform).replace(/[^a-z_]/g, ''));
                strip.appendChild(a);
            });
            strip.style.display = '';
        }).catch(function () { /* the strip is decoration: the inbox works without it */ });
    })();

    let websiteId = null;
    let currentConversation = null;
    let activeConvId = null;

    const CHANNEL_LABEL = {
        whatsapp: 'WhatsApp', website_chat: 'Website chat', webchat: 'Website chat',
        messenger: 'Messenger', instagram: 'Instagram', email: 'Email',
    };
    const STATUS_OPTIONS = [
        ['open', 'Open'], ['pending', 'Pending'], ['resolved', 'Resolved'], ['closed', 'Closed'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    const QUOTE_STATUS_LABEL = {
        draft: 'Draft', sent: 'Sent', accepted: 'Accepted', declined: 'Rejected', expired: 'Expired', cancelled: 'Cancelled',
    };
    const QUOTE_STATUS_CLASS = {
        draft: 'gray', sent: 'blue', accepted: 'green', declined: 'red', expired: 'gray', cancelled: 'red',
    };
    let quoteItems = [];

    function ensureWebsiteSelected() {
        const id = P.getCurrentWebsiteId();
        document.getElementById('ucNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ucBody').style.display = id ? 'grid' : 'none';
        return id;
    }

    function customerLabel(c) {
        return c.customer_name || c.customer_phone || c.customer_email || 'Unknown customer';
    }

    function avatarClass(ch) {
        return ['whatsapp', 'email', 'messenger', 'instagram', 'website_chat', 'webchat'].indexOf(ch) !== -1 ? ' ' + ch : '';
    }

    function statusLine(c) {
        const parts = [];
        if (c.lead_status === 'hot_lead') parts.push('<span class="pill red" style="font-size:10px;">' + ic('fire', 'ic-sm') + '</span>');
        if (c.priority === 'urgent' || c.priority === 'high') parts.push('<span class="pill red" style="font-size:10px;">' + ic('flag', 'ic-sm') + '</span>');
        if (c.ai_status === 'ai') parts.push('<span class="pill green" style="font-size:10px;">' + ic('sparkles', 'ic-sm') + ' AI</span>');
        else if (c.ai_status === 'paused') parts.push('<span class="pill red" style="font-size:10px;">' + ic('pause', 'ic-sm') + '</span>');
        return parts.join(' ');
    }

    window.ucApplyFilters = function () { loadList(); };

    async function loadList(silent) {
        const id = ensureWebsiteSelected();
        if (!id) return;

        const qs = new URLSearchParams();
        const search = document.getElementById('ucSearch').value.trim();
        const status = document.getElementById('ucStatus').value;
        const aiStatus = document.getElementById('ucAiStatus').value;
        const leadStatus = document.getElementById('ucLeadStatus').value;
        const channel = document.getElementById('ucChannel').value;
        const tag = document.getElementById('ucTag').value;
        if (search) qs.set('search', search);
        if (status) qs.set('status', status);
        if (aiStatus) qs.set('ai_status', aiStatus);
        if (leadStatus) qs.set('lead_status', leadStatus);
        if (channel) qs.set('channel', channel);
        if (tag) qs.set('tag', tag);
        const assigned = document.getElementById('ucAssigned').value;
        if (assigned) qs.set('assigned_agent_id', assigned);

        const listEl = document.getElementById('ucList');
        const keepScroll = listEl.scrollTop;
        if (silent !== true) {
            listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>Loading...</div>';
        }

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(id) + '/conversations?' + qs.toString());
        if (!res.success) {
            listEl.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('alert') + '</div>' + esc(res.error || 'Could not load conversations') + '</div>';
            return;
        }

        const list = (res.data && Array.isArray(res.data.conversations)) ? res.data.conversations : [];
        // العدد جوه الجملة كان بيمنع القاموس يترجمها («Conversation 0» في الواجهة العربي)
        const ucAr = (document.documentElement.lang || '').startsWith('ar');
        document.getElementById('ucCount').textContent = ucAr ? list.length + ' محادثة' : list.length + (list.length === 1 ? ' conversation' : ' conversations');
        if (!list.length) {
            listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('inbox') + '</div>No conversations match the filters</div>';
            return;
        }

        listEl.innerHTML = list.map(c => {
            const customer = customerLabel(c);
            const initial = (customer || '?').trim().charAt(0).toUpperCase();
            const active = c.id === activeConvId ? ' active' : '';
            return `
                <div class="ai-chat-item${active}" data-id="${c.id}" data-photo="${c.photo_v || 0}" onclick="window.selectConversation(${c.id})">
                    <div class="r1">
                        <div class="ai-chat-avatar${avatarClass(c.channel)}">${c.photo_v ? '<img class="wa-av-img" alt="" src="/api/ai-chat/websites/' + websiteId + '/conversations/' + c.id + '/photo?v=' + c.photo_v + '">' : esc(initial)}</div>
                        <div class="nm">
                            <b>${esc(customer)} ${c.unread_count > 0 ? '<span class="ub">' + c.unread_count + '</span>' : ''}</b>
                            <small>${CHANNEL_LABEL[c.channel] || esc(c.channel || '-')} · ${esc(c.customer_phone || c.customer_email || '-')}</small>
                        </div>
                        <div class="ch">${statusLine(c)}</div>
                    </div>
                    <div class="r1" style="margin-top:6px;">
                        <small class="tm" style="flex:1;">${timeAgo(c.last_message_at)}</small>
                        ${c.assigned_agent_name ? '<span class="pill gray" style="font-size:10px;" title="Assigned to">👤 ' + esc(c.assigned_agent_id == currentUserId ? 'You' : c.assigned_agent_name) + '</span>' : ''}
                    </div>
                </div>`;
        }).join('');
        if (silent === true) listEl.scrollTop = keepScroll;
    }

    window.selectConversation = async function (id, silent) {
        if (!websiteId) websiteId = P.getCurrentWebsiteId();
        if (!websiteId) return;
        if (silent !== true) { waClearReply(); waOlder = []; waOlderConv = null; }
        activeConvId = id;
        document.querySelectorAll('.ai-chat-item').forEach(el => {
            el.classList.toggle('active', el.dataset.id === String(id));
        });

        document.getElementById('ucEmptyState').style.display = 'none';
        document.getElementById('ucThreadPanel').style.display = 'block';
        if (silent !== true) { document.getElementById('ucBody').classList.add('wa-chat-open'); document.body.classList.add('wa-in-chat'); }
        const threadEl = document.getElementById('convThread');
        const nearBottom = threadEl.scrollHeight - threadEl.scrollTop - threadEl.clientHeight < 80;
        if (silent !== true) {
            document.getElementById('convHeader').innerHTML = '<div class="p-empty" style="padding:20px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>Loading conversation...</div>';
            threadEl.innerHTML = '';
        }

        // Don't cut off a voice note or video that is playing; the next refresh picks the new messages up.
        if (silent === true && threadIsPlaying()) return false;
        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations/' + id);
        if (!res.success || !res.data || !res.data.conversation) {
            if (silent !== true) toast(res.error || 'Could not load the conversation', 'error');
            return;
        }
        if (String(id) !== String(activeConvId)) return; // the user opened another conversation meanwhile

        currentConversation = res.data.conversation;
        await loadAgents();
        renderHeader(currentConversation);
        renderComposer(currentConversation);
        const prevScroll = threadEl.scrollTop;
        waHasMore = !!res.data.has_more;
        renderThread(res.data.messages || []);
        if (silent === true) {
            // تحديث تلقائي: لو كنت تحت ننزل للجديد، لو بتقرا فوق نسيبك مكانك
            threadEl.scrollTop = nearBottom ? threadEl.scrollHeight : prevScroll;
            return true;
        }
        document.getElementById('templateBox').style.display = 'none';
        document.getElementById('catalogBox').style.display = 'none';

        const leadRes = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/leads?conversation_id=' + id);
        renderLeadPanel(leadRes.success ? leadRes.data.leads : []);
        quoteLoad();
    };

    window.toggleHandoff = async function () {
        const isAi = currentConversation.ai_status === 'ai';
        const url = '/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + (isAi ? '/handoff' : '/resume-ai');
        const res = await fetchJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: isAi ? JSON.stringify({ reason: 'manual_takeover' }) : null,
        });
        if (res.success) { toast(isAi ? 'The conversation was handed off to you' : 'Auto reply restored', 'success'); loadList(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'Operation failed', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'Unassigned' : 'The conversation was assigned to you', 'success'); selectConversation(currentConversation.id); }
        else { toast(res.error || 'Operation failed', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('Updated', 'success'); loadList(); }
        else { toast(res.error || 'Update failed', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    function sendFailReason(reason) {
        const reasons = {
            outside_24h: 'the customer has not messaged in the last 24 hours (WhatsApp only allows a template message now)',
            no_recipient: 'no valid WhatsApp number or ID for this customer',
            not_connected: 'WhatsApp is not connected for this website',
            provider_rejected: 'WhatsApp (Meta) rejected the message; check the connection and the logs',
        };
        return reasons[reason] ? ': ' + reasons[reason] : '';
    }

    let sending = false;
    window.waLoadOlder = async function () {
        const first = threadMessages[0];
        if (!first || !currentConversation) return;
        const btn = document.querySelector('.wa-older');
        if (btn) btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '?before=' + encodeURIComponent(first.id));
        if (!res.success) { toast(res.error || 'Could not load earlier messages', 'error'); if (btn) btn.disabled = false; return; }
        waOlder = (res.data.messages || []).concat(waOlder);
        waOlderConv = activeConvId;
        waHasMore = !!res.data.has_more;
        const t = document.getElementById('convThread');
        const prev = t.scrollHeight;
        renderThread(waLastRaw);
        t.scrollTop = t.scrollHeight - prev; // stay on the message you were reading
    };

    // ===== Reply to a message and react (WhatsApp official API) =====
    let replyTo = null;
    function waClearReply() {
        replyTo = null;
        const chip = document.getElementById('waReplyChip');
        if (chip) chip.remove();
    }
    window.waReply = function (id) {
        const m = threadMessages.find(x => x.id === id);
        if (!m) return;
        waClearReply();
        const text = (m.message_text || '').trim() || '📎';
        replyTo = { id: id };
        const chip = document.createElement('div');
        chip.id = 'waReplyChip';
        chip.className = 'wa-reply-chip';
        const body = document.createElement('div');
        const who = document.createElement('b');
        who.textContent = m.message_direction === 'outgoing' ? 'You' : (currentConversation && currentConversation.customer_name) || 'Customer';
        const t = document.createElement('span');
        t.textContent = text.length > 140 ? text.slice(0, 140) + '…' : text;
        body.appendChild(who); body.appendChild(t);
        const x = document.createElement('button');
        x.type = 'button'; x.setAttribute('aria-label', 'Cancel reply'); x.textContent = '✕';
        x.onclick = waClearReply;
        chip.appendChild(body); chip.appendChild(x);
        const card = document.getElementById('ucReplyCard');
        card.insertBefore(chip, card.querySelector('.wa-bar') || card.querySelector('.form-group'));
        document.getElementById('manualMessage').focus();
    };
    window.waReactMenu = function (id, btn) {
        const old = document.getElementById('waReactMenu');
        if (old) { old.remove(); if (old.dataset.id === String(id)) return; }
        const menu = document.createElement('div');
        menu.id = 'waReactMenu';
        menu.className = 'wa-react-menu';
        menu.dataset.id = String(id);
        ['👍', '❤️', '😂', '😮', '😢', '🙏', ''].forEach(function (em) {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = em || '✕';
            b.title = em ? 'React' : 'Remove reaction';
            b.onclick = async function () {
                menu.remove();
                const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/messages/' + id + '/react', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ emoji: em }),
                });
                if (!res.success) toast(res.error || 'The reaction was not delivered', 'error');
                selectConversation(currentConversation.id, true);
            };
            menu.appendChild(b);
        });
        const r = btn.getBoundingClientRect();
        menu.style.top = Math.max(8, r.top - 46) + 'px';
        menu.style.left = Math.min(window.innerWidth - 270, Math.max(8, r.left - 100)) + 'px';
        document.body.appendChild(menu);
        setTimeout(function () { document.addEventListener('click', function close() { menu.remove(); document.removeEventListener('click', close); }, { once: true }); }, 0);
    };

    window.sendManual = async function () {
        const msgEl = document.getElementById('manualMessage');
        const message = msgEl.value.trim();
        if (!message) { toast('Write a message first', 'error'); return; }
        if (sending || !currentConversation) return;
        sending = true;
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        // Show the message right away, like WhatsApp; the refresh below replaces it with the saved one.
        appendPendingBubble(message);
        msgEl.value = '';
        document.getElementById('aiSuggestions').style.display = 'none';
        const convId = currentConversation.id;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + convId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(replyTo ? { message: message, reply_to_message_id: replyTo.id } : { message: message }),
        });
        waClearReply();
        sending = false;
        btn.disabled = false;
        if (res.success) {
            if (res.data && res.data.sent === false) toast('The message was saved but actually sending it to the customer failed' + sendFailReason(res.data.reason), 'error');
        } else {
            toast(res.error || 'Submission failed', 'error');
            if (!msgEl.value) msgEl.value = message; // keep the text so it isn't lost
        }
        if (String(activeConvId) === String(convId)) {
            await selectConversation(convId, true);
            const t = document.getElementById('convThread');
            t.scrollTop = t.scrollHeight;
        }
        msgEl.focus();
    };

    document.getElementById('manualMessage').addEventListener('keydown', function (e) {
        // Enter sends, Shift+Enter adds a line. On phones Enter stays a new line, like WhatsApp.
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && !window.matchMedia('(pointer: coarse)').matches) {
            e.preventDefault();
            sendManual();
        }
    });

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">' + ic('sparkles', 'ic-sm') + ' Generating suggestions...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">' + ic('alert', 'ic-sm') + ' ' + esc((res.data && res.data.error) || res.error || 'No suggestions available right now') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card ai-sugg" style="padding:10px 12px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">Suggestion ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;color:var(--panel-text);">${esc(s)}</span>
            </div>`).join('');
    };

    let agentsCache = {}, myRole = 'owner';
    const BLOCK_LABEL = 'Block', UNBLOCK_LABEL = 'Unblock', BLOCKED_LABEL = 'Blocked', UNASSIGNED_LABEL = 'Unassigned', ME_LABEL = 'Me', TEAM_SUFFIX = 'Team';
    async function loadAgents() {
        if (!websiteId || agentsCache[websiteId]) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/agents');
        agentsCache[websiteId] = res.success ? (res.data.agents || []) : [];
    }
    function agentSelect(c) {
        const agents = agentsCache[websiteId] || [];
        const readOnly = c.my_role === 'viewer';
        if (agents.length <= 1) {
            return readOnly ? '' : '<button class="p-btn outline xs" onclick="assignToggle()">' + (c.assigned_agent_id == currentUserId ? ic('x') + 'Unassign me' : ic('user-plus') + 'Assign to me') + '</button>';
        }
        return '<select class="p-select" title="Assigned to" onchange="assignTo(this.value)"' + (readOnly ? ' disabled' : '') + '>'
            + '<option value="">👤 ' + esc(UNASSIGNED_LABEL) + '</option>'
            + agents.map(a => '<option value="' + a.id + '"' + (String(c.assigned_agent_id) === String(a.id) ? ' selected' : '') + '>👤 ' + esc(a.id == currentUserId ? ME_LABEL + ' (' + a.name + ')' : a.name) + '</option>').join('')
            + '</select>';
    }
    window.assignTo = async function (agentId) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: agentId ? Number(agentId) : null }),
        });
        if (res.success) { toast(agentId ? 'Conversation assigned' : 'Unassigned', 'success'); loadList(true); selectConversation(currentConversation.id, true); }
        else toast(res.error || 'Operation failed', 'error');
    };

    function renderHeader(c) {
        const customer = customerLabel(c);
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const TAG_LABEL = {HOT_LEAD: '🔥 Hot lead', NEW_INQUIRY: 'New inquiry', PRICE_REQUEST: 'Price request', COMPLAINT: 'Complaint', FOLLOW_UP: 'Needs follow-up', BOOKING_INTENT: 'Wants to book', VIP: 'VIP', HUMAN_REQUIRED: 'Needs a human'};

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${TAG_LABEL[t] || t}</span>`;
        }).join(' ');

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        const badges = [];
        if (c.language) badges.push('<span class="pill gray">' + ic('globe', 'ic-sm') + ' ' + esc(c.language === 'ar' ? 'Arabic' : 'English') + '</span>');
        if (c.ai_confidence_score !== null && c.ai_confidence_score !== undefined) {
            badges.push('<span class="pill ' + (c.ai_confidence_score >= 0.7 ? 'green' : (c.ai_confidence_score >= 0.4 ? '' : 'red')) + '">' + ic('sparkles', 'ic-sm') + ' Trust AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>');
        }

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} <span class="pill gray">${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</span></h3>
                <span class="p-card-sub">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div class="ai-conv-head">
                ${isAi ? '<span class="pill green">' + ic('sparkles', 'ic-sm') + ' Replying now: AI</span>' : '<span class="pill">' + ic('user', 'ic-sm') + ' Replying now: agent</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${ic('handoff')}${isAi ? 'Hand off to agent' : 'Restore the auto reply'}</button>
                ${agentSelect(c)}
                ${c.channel === 'whatsapp' && c.whatsapp && c.whatsapp.cloud ? '<button class="p-btn outline xs" onclick="blockToggle()">' + ((c.tags || []).includes('BLOCKED') ? '✅ ' + esc(UNBLOCK_LABEL) : '🚫 ' + esc(BLOCK_LABEL)) + '</button>' : ''}
                ${(c.tags || []).includes('BLOCKED') ? '<span class="pill red">🚫 ' + esc(BLOCKED_LABEL) + '</span>' : ''}
                ${statusSelect}
                ${prioritySelect}
                ${badges.join('')}
            </div>
            <div style="margin:10px 0 4px;display:flex;flex-wrap:wrap;gap:4px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-sidebar-bg-hover);padding:10px 14px;margin-top:8px;"><strong>' + ic('sparkles', 'ic-sm') + ' Summary AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    const TICK_LABEL = { pending: 'Sending...', accepted: 'Sent', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Not delivered' };
    function ticks(status, err) {
        if (!status) return '';
        const sym = status === 'pending' ? '🕓' : status === 'failed' ? '!' : (status === 'delivered' || status === 'read') ? '✓✓' : '✓';
        const title = (TICK_LABEL[status] || status) + (err ? ': ' + err : '');
        return '<span class="ai-tick ' + esc(status) + '" title="' + esc(title) + '" aria-label="' + esc(title) + '">' + sym + '</span>';
    }
    function toDate(s) { return s ? new Date(String(s).replace(' ', 'T')) : null; }
    function timeOnly(s) {
        const d = toDate(s);
        return d && !isNaN(d) ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
    }
    function dayLabel(s) {
        const d = toDate(s);
        if (!d || isNaN(d)) return '';
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const day = new Date(d); day.setHours(0, 0, 0, 0);
        const diff = Math.round((today - day) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Yesterday';
        return d.toLocaleDateString([], { weekday: diff < 7 ? 'long' : undefined, day: 'numeric', month: 'short', year: day.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
    }
    function mediaUrl(messageId) {
        return '/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations/' + activeConvId + '/messages/' + messageId + '/media';
    }
    const MEDIA_FAIL_TEXT = '⚠ Media no longer available';
    window.mediaFail = function (el) { const sp = document.createElement('span'); sp.textContent = MEDIA_FAIL_TEXT; el.replaceWith(sp); };
    const MEDIA_FAIL = 'mediaFail(this)';
    function mediaHtml(m) {
        if (!m.media) return '';
        const url = mediaUrl(m.id);
        switch (m.media.type) {
            case 'image':
            case 'sticker':
                return '<a href="' + url + '" target="_blank" rel="noopener"><img class="ai-media-img" src="' + url + '" loading="lazy" alt="" onerror="' + MEDIA_FAIL + '"></a>';
            case 'audio':
                return '<audio class="ai-media-audio" controls preload="none" src="' + url + '"></audio>';
            case 'video':
                return '<video class="ai-media-video" controls preload="metadata" src="' + url + '"></video>';
            default:
                return '<a class="ai-media-doc" href="' + url + '" target="_blank" rel="noopener">📄 ' + esc(m.media.filename || FILE_LABEL) + '</a>';
        }
    }
    const FILE_LABEL = 'File';
    // A shared location or contact arrives as plain text ("[Location] lat,lng" / "[Contact] Name · phone"): show it as a card.
    function richText(text) {
        const loc = /^\[Location\]\s*(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/.exec(text);
        if (loc && Math.abs(+loc[1]) <= 90 && Math.abs(+loc[2]) <= 180) {
            return '<a class="wa-loc" href="https://www.google.com/maps?q=' + loc[1] + ',' + loc[2] + '" target="_blank" rel="noopener noreferrer"><b>&#128205; Location</b><small>' + loc[1] + ', ' + loc[2] + '</small><span>Open in Maps</span></a>';
        }
        if (text.indexOf('[Contact]') === 0) {
            return text.slice(9).split(' | ').filter(Boolean).map(function (c) {
                const p = c.split(' · ');
                return '<div class="wa-contact"><b>&#128100; ' + esc(p[0] || '') + '</b>' + (p[1] ? '<small>' + esc(p[1]) + '</small>' : '') + '</div>';
            }).join('') || '<div class="wa-contact"><b>&#128100; Contact</b></div>';
        }
        return esc(text);
    }
    function bodyText(m, text) {
        if (!m.media) return text;
        let t = text.replace(/^\[(Image|Video|Voice message|Audio|Sticker|Document)\]\s*/, '');
        if (m.media.type === 'document' && t === (m.media.filename || '')) t = '';
        return t;
    }
    function bubble(dir, inner, time, extra, cls, meta) {
        let pre = '', post = '', attr = '';
        if (meta) {
            attr = meta.id ? ' data-mid="' + meta.id + '"' : '';
            if (meta.quote && meta.quote.text) {
                pre += '<div class="ai-quote" data-qid="' + meta.quote.id + '"><b>' + esc(meta.quote.who === 'you' ? 'You' : (currentConversation && currentConversation.customer_name) || 'Customer') + '</b><span>' + esc(meta.quote.text) + '</span></div>';
            }
            if (meta.reaction) post += '<span class="wa-react-badge" title="Your reaction">' + esc(meta.reaction) + '</span>';
            if (meta.canAct) {
                pre = '<div class="wa-actions"><button type="button" class="wa-act" title="Reply" aria-label="Reply" onclick="waReply(' + meta.id + ')">&#8617;</button>'
                    + (meta.canReact ? '<button type="button" class="wa-act" title="React" aria-label="React" onclick="waReactMenu(' + meta.id + ', this)">&#128578;</button>' : '')
                    + '</div>' + pre;
            }
        }
        return '<div class="ai-bubble ' + dir + (cls ? ' ' + cls : '') + '"' + attr + '>' + pre + inner + '<div class="bt">' + (extra || '') + '<span>' + esc(time) + '</span></div>' + post + '</div>';
    }
    let threadMessages = [], waOlder = [], waOlderConv = null, waHasMore = false, waLastRaw = [];
    function metaOf(m, canAct, canReact) {
        // The badge shows the other side's reaction on our bubbles, and ours on the customer's.
        const reaction = m.message_direction === 'outgoing' ? (m.customer_reaction || '') : (m.our_reaction || '');
        return { id: m.id, quote: m.quote || null, reaction: reaction, canAct: !!canAct && !!m.id, canReact: !!canReact && !!m.id };
    }


    const RETRY_LABEL = 'Retry', AI_REPLY_LABEL = 'AI reply';
    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('chat') + '</div>No messages in this conversation yet</div>';
            return;
        }
        const isWa = currentConversation && currentConversation.channel === 'whatsapp';
        waLastRaw = messages;
        if (waOlder.length && waOlderConv === activeConvId) messages = waOlder.concat(messages);
        threadMessages = messages;
        let lastDay = '';
        const out = [];
        if (waHasMore) out.push('<button type="button" class="wa-older" onclick="waLoadOlder()">Load earlier messages</button>');
        messages.forEach(m => {
            const day = dayLabel(m.created_at);
            if (day && day !== lastDay) { out.push('<div class="ai-day-sep">' + esc(day) + '</div>'); lastDay = day; }
            const media = mediaHtml(m);
            if (m.message_direction === 'outgoing') {
                let text = bodyText(m, m.message_text || '');
                const failed = m.bot_status === 'failed';
                const status = failed ? 'failed' : (isWa ? (m.delivery || 'accepted') : 'sent');
                const who = m.template ? '📋 Template' : m.catalog ? '🛍 Catalog' : (m.by_me ? 'You' : (m.sender_name || 'Team'));
                let extra = ticks(status, m.delivery_error);
                if (failed && !m.media && !m.template && !m.catalog) extra = '<button type="button" class="ai-retry" onclick="retryMessage(' + m.id + ')">' + esc(RETRY_LABEL) + '</button>' + extra;
                const opts = m.interactive && m.interactive.options && m.interactive.options.length ? '<div class="wa-opts">' + m.interactive.options.map(o => '<span>' + (m.interactive.kind === 'list' ? '&#9776; ' : '') + esc(o) + '</span>').join('') + '</div>' : '';
                out.push(bubble('out', '<span class="who' + (m.by_me && !m.template && !m.catalog ? ' me' : '') + '">' + esc(who) + '</span>' + media + (text ? '<span>' + esc(text) + '</span>' : '') + opts, timeOnly(m.created_at), extra, failed || m.delivery === 'failed' ? 'failed' : '', metaOf(m, isWa && !failed, false)));
                return;
            }
            const text = bodyText(m, m.message_text || '');
            out.push(bubble('in', media + (text ? '<span>' + richText(text) + '</span>' : ''), timeOnly(m.created_at), '', '', metaOf(m, isWa, isWa)));
            // The AI reply is stored on the customer's message row; show it as its own bubble.
            const reply = (m.ai_reply_generated || '').trim();
            if (!reply) return;
            const conf = m.ai_confidence_score != null ? ' · ' + Math.round(m.ai_confidence_score * 100) + '%' : '';
            const tag = '<span class="ai-tag">' + ic('sparkles', 'ic-sm') + ' ' + esc(AI_REPLY_LABEL) + conf + '</span>';
            if (m.bot_status === 'sent') {
                out.push(bubble('out', tag + '<span>' + esc(reply) + '</span>', timeOnly(m.sent_at || m.created_at), ticks(isWa ? (m.reply_delivery || 'accepted') : 'sent'), '', { id: 0, reaction: m.customer_reaction_reply || '' }));
            } else if (m.bot_status === 'pending_approval' || m.bot_status === 'approved') {
                out.push(bubble('out', tag + '<span>' + esc(reply) + '</span>', m.bot_status === 'approved' ? 'Approved, sending...' : 'Waiting for approval', '', 'ghost'));
            } else if (m.bot_status === 'failed') {
                out.push(bubble('out', tag + '<span>' + esc(reply) + '</span>', 'AI reply was not delivered', ticks('failed'), 'ghost failed'));
            }
        });
        thread.innerHTML = '<div class="ai-chat-bubbles">' + out.join('') + '</div>';
        thread.scrollTop = thread.scrollHeight;
    }

    function threadIsPlaying() {
        return Array.from(document.querySelectorAll('#convThread audio, #convThread video')).some(el => !el.paused && !el.ended);
    }

    function appendPendingBubble(text) {
        const thread = document.getElementById('convThread');
        let box = thread.querySelector('.ai-chat-bubbles');
        if (!box) { thread.innerHTML = '<div class="ai-chat-bubbles"></div>'; box = thread.querySelector('.ai-chat-bubbles'); }
        box.insertAdjacentHTML('beforeend', bubble('out', '<span class="who">You</span><span>' + esc(text) + '</span>', new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }), ticks('pending'), 'pending'));
        thread.scrollTop = thread.scrollHeight;
    }

    // ===== WhatsApp 24-hour window, files and templates =====
    let windowDeadline = null;
    function renderComposer(c) {
        const readOnly = c.my_role === 'viewer';
        document.getElementById('ucReplyCard').style.display = readOnly ? 'none' : '';
        document.getElementById('ucReadOnly').style.display = readOnly ? '' : 'none';
        const wa = c.channel === 'whatsapp' && c.whatsapp && c.whatsapp.cloud;
        document.getElementById('attachBtn').style.display = wa ? '' : 'none';
        document.getElementById('templateBtn').style.display = wa ? '' : 'none';
        document.getElementById('catalogBtn').style.display = wa ? '' : 'none';
        document.getElementById('interactiveBtn').style.display = wa ? '' : 'none';
        if (!wa) { document.getElementById('templateBox').style.display = 'none'; document.getElementById('catalogBox').style.display = 'none'; }
        windowDeadline = wa ? Date.now() + (c.whatsapp.window_seconds_left || 0) * 1000 : null;
        renderWindowBar();
    }
    function renderWindowBar() {
        const bar = document.getElementById('waWindow');
        const msg = document.getElementById('manualMessage');
        const sendBtn = document.getElementById('sendManualBtn');
        const attachBtn = document.getElementById('attachBtn');
        if (windowDeadline === null) {
            bar.style.display = 'none';
            msg.disabled = false; sendBtn.disabled = false;
            return;
        }
        const left = Math.max(0, Math.floor((windowDeadline - Date.now()) / 1000));
        bar.style.display = 'flex';
        if (left > 0) {
            const h = Math.floor(left / 3600), mnt = Math.floor((left % 3600) / 60);
            bar.className = 'wa-window ' + (left < 7200 ? 'warn' : 'ok');
            bar.innerHTML = '<span>⏱ ' + esc('Free reply window:') + ' <b dir="ltr">' + (h ? h + 'h ' : '') + mnt + 'm</b> ' + esc('left. After that WhatsApp only allows an approved template.') + '</span>';
            msg.disabled = false; sendBtn.disabled = false; attachBtn.disabled = false; document.getElementById('catalogBtn').disabled = false; document.getElementById('interactiveBtn').disabled = false;
        } else {
            bar.className = 'wa-window closed';
            bar.innerHTML = '<span>🔒 ' + esc('The 24-hour window is closed: the customer has not written in the last 24 hours. WhatsApp only allows an approved template until they reply.') + '</span> <button type="button" class="p-btn primary xs" onclick="templateToggle(true)">📋 ' + esc('Send a template') + '</button>';
            msg.disabled = true; sendBtn.disabled = true; attachBtn.disabled = true; document.getElementById('catalogBtn').disabled = true; document.getElementById('interactiveBtn').disabled = true;
        }
    }
    setInterval(function () { if (windowDeadline !== null) renderWindowBar(); }, 30000);

    let templatesCache = null, templatesSite = null, tplSelected = null;
    const SEND_TEMPLATE_LABEL = 'Send template', FIELD_LABEL = 'Field', TPL_UNSUPPORTED = 'Needs media or link: send from the templates page', TPL_CHOOSE = 'Choose an approved template';
    window.templateToggle = async function (forceOpen) {
        const box = document.getElementById('templateBox');
        if (box.style.display === 'block' && forceOpen !== true) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        if (!templatesCache || templatesSite !== websiteId) {
            box.innerHTML = '<div class="p-cell-muted">⏳ ' + esc('Loading approved templates...') + '</div>';
            const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/whatsapp-templates');
            if (!res.success) { box.innerHTML = '<div class="p-cell-muted">' + esc(res.error || 'Could not load templates') + '</div>'; return; }
            templatesCache = res.data.templates || [];
            templatesSite = websiteId;
        }
        tplSelected = null;
        if (!templatesCache.length) {
            box.innerHTML = '<div class="p-cell-muted">' + esc('No approved templates yet. Create one from the WhatsApp templates page and wait for Meta approval.') + '</div>';
            return;
        }
        box.innerHTML = '<div style="font-weight:600;margin-bottom:6px;">' + esc(TPL_CHOOSE) + '</div>' + templatesCache.map((t, i) =>
            '<div class="tpl-item' + (t.supported ? '' : ' off') + '" data-i="' + i + '" onclick="templatePick(' + i + ')">' +
            '<b>' + esc(t.name) + '</b> <span class="pill gray">' + esc(t.language) + '</span> <span class="pill gray">' + esc(t.category) + '</span>' +
            (t.supported ? '' : ' <span class="pill red">' + esc(TPL_UNSUPPORTED) + '</span>') +
            '<div class="p-cell-muted" style="margin-top:4px;white-space:pre-wrap;">' + esc(t.body.length > 160 ? t.body.slice(0, 160) + '…' : t.body) + '</div></div>'
        ).join('') + '<div id="tplForm"></div>';
    };
    window.templatePick = function (i) {
        const t = templatesCache[i];
        if (!t || !t.supported) return;
        tplSelected = t;
        document.querySelectorAll('#templateBox .tpl-item').forEach(el => el.classList.toggle('sel', el.dataset.i === String(i)));
        const name = currentConversation ? (currentConversation.customer_name || '') : '';
        document.getElementById('tplForm').innerHTML =
            t.params.map(p => '<div class="form-group" style="margin-bottom:6px;"><label style="font-size:12px;">' + esc(FIELD_LABEL) + ' {{' + esc(p) + '}}</label><input class="form-control tpl-param" data-p="' + esc(p) + '" oninput="templatePreview()" value="' + (p === '1' || p === 'name' || p === 'customer_name' ? esc(name) : '') + '"></div>').join('') +
            '<div class="tpl-preview" id="tplPreview"></div>' +
            '<button type="button" class="p-btn primary" id="tplSendBtn" onclick="templateSend()">' + ic('send') + esc(SEND_TEMPLATE_LABEL) + '</button>';
        templatePreview();
    };
    window.templatePreview = function () {
        if (!tplSelected) return;
        let text = (tplSelected.header ? tplSelected.header + '\n' : '') + tplSelected.body;
        document.querySelectorAll('#tplForm .tpl-param').forEach(inp => {
            text = text.split('{{' + inp.dataset.p + '}}').join(inp.value || '{{' + inp.dataset.p + '}}');
        });
        document.getElementById('tplPreview').textContent = text;
    };
    window.templateSend = async function () {
        if (!tplSelected || !currentConversation) return;
        const params = {};
        let missing = false;
        document.querySelectorAll('#tplForm .tpl-param').forEach(inp => { params[inp.dataset.p] = inp.value.trim(); if (!params[inp.dataset.p]) missing = true; });
        if (missing) { toast('Fill in every template field', 'error'); return; }
        const btn = document.getElementById('tplSendBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/template', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: tplSelected.name, language: tplSelected.language, params: params }),
        });
        btn.disabled = false;
        if (!res.success) { toast(res.error || 'Submission failed', 'error'); return; }
        if (res.data && res.data.sent === false) {
            toast('The template was not delivered' + (res.data.error ? ': ' + res.data.error : sendFailReason(res.data.reason)), 'error');
        } else {
            toast('Template sent', 'success');
            document.getElementById('templateBox').style.display = 'none';
        }
        selectConversation(currentConversation.id, true);
    };

    window.sendAttachment = async function (input) {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file || !currentConversation) return;
        if (file.size > 100 * 1048576) { toast('The file is too large for WhatsApp', 'error'); return; }
        const msgEl = document.getElementById('manualMessage');
        const caption = msgEl.value.trim();
        const fd = new FormData();
        fd.append('file', file);
        fd.append('caption', caption);
        appendPendingBubble('📎 ' + file.name + (caption ? ' — ' + caption : ''));
        msgEl.value = '';
        const btn = document.getElementById('attachBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/media', { method: 'POST', body: fd });
        btn.disabled = false;
        if (!res.success) {
            toast(res.error || 'Submission failed', 'error');
            if (!msgEl.value) msgEl.value = caption;
        } else if (res.data && res.data.sent === false) {
            toast('The file was not delivered' + sendFailReason(res.data.reason), 'error');
        }
        selectConversation(currentConversation.id, true).then(() => { const t = document.getElementById('convThread'); t.scrollTop = t.scrollHeight; });
    };


    // ===== Quick-reply buttons / option list (official WhatsApp, inside the 24-hour window) =====
    window.interactiveToggle = function () {
        const box = document.getElementById('interactiveBox');
        if (box.style.display === 'block') { box.style.display = 'none'; return; }
        box.style.display = 'block';
        box.textContent = '';
        const mk = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; };
        const wrap = mk('div', 'wa-inter');
        const head = mk('div', 'wa-inter-head');
        const kind = document.createElement('select');
        kind.className = 'p-select';
        [['buttons', 'Buttons (up to 3)'], ['list', 'Option list (up to 10)']].forEach(function (o) { const op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; kind.appendChild(op); });
        head.appendChild(kind);
        const body = document.createElement('textarea');
        body.className = 'form-control'; body.rows = 2; body.maxLength = 1024; body.placeholder = 'Message shown above the options';
        body.setAttribute('aria-label', 'Message');
        const opts = mk('div', 'wa-inter-opts');
        const label = document.createElement('input');
        label.className = 'form-control'; label.maxLength = 20; label.placeholder = 'List button text (e.g. Choose)'; label.setAttribute('aria-label', 'List button text'); label.style.display = 'none';
        const inputs = [];
        function addOption() {
            const max = kind.value === 'list' ? 10 : 3;
            if (inputs.length >= max) return;
            const i = document.createElement('input');
            i.className = 'form-control'; i.maxLength = kind.value === 'list' ? 24 : 20;
            i.placeholder = (kind.value === 'list' ? 'Option ' : 'Button ') + (inputs.length + 1);
            i.setAttribute('aria-label', i.placeholder);
            inputs.push(i); opts.appendChild(i);
        }
        function reset() { inputs.length = 0; opts.textContent = ''; label.style.display = kind.value === 'list' ? '' : 'none'; addOption(); addOption(); }
        kind.onchange = reset;
        const add = mk('button', 'p-btn outline xs', '+ Add option'); add.type = 'button'; add.onclick = addOption;
        const send = mk('button', 'p-btn primary xs', 'Send'); send.type = 'button';
        const cancel = mk('button', 'p-btn outline xs', 'Cancel'); cancel.type = 'button'; cancel.onclick = function () { box.style.display = 'none'; };
        const row = mk('div', 'wa-inter-actions'); [add, cancel, send].forEach(function (b) { row.appendChild(b); });
        send.onclick = async function () {
            if (!currentConversation) return;
            const values = inputs.map(function (i) { return i.value.trim(); }).filter(Boolean);
            if (!body.value.trim() || !values.length) { toast('Write the message and at least one option', 'error'); return; }
            const payload = { kind: kind.value, body: body.value.trim() };
            if (kind.value === 'list') { payload.rows = values; payload.button_label = label.value.trim(); } else { payload.buttons = values; }
            send.disabled = true;
            const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/interactive', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
            });
            send.disabled = false;
            if (!res.success) { toast(res.error || 'Submission failed', 'error'); return; }
            if (res.data && res.data.sent === false) toast('The buttons were not delivered' + sendFailReason(res.data.reason), 'error');
            else box.style.display = 'none';
            selectConversation(currentConversation.id, true).then(function () { const t = document.getElementById('convThread'); t.scrollTop = t.scrollHeight; });
        };
        [head, body, label, opts, row].forEach(function (n) { wrap.appendChild(n); });
        box.appendChild(wrap);
        reset();
    };

    // ===== WhatsApp catalog and blocking =====
    let catalogCache = null, catalogSite = null;
    window.catalogToggle = async function () {
        const box = document.getElementById('catalogBox');
        if (box.style.display === 'block') { box.style.display = 'none'; return; }
        box.style.display = 'block';
        if (!catalogCache || catalogSite !== websiteId) {
            box.innerHTML = '<div class="p-cell-muted">⏳ ' + esc('Loading the catalog...') + '</div>';
            const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/whatsapp-catalog');
            if (!res.success) { box.innerHTML = '<div class="p-cell-muted">' + esc(res.error || 'Could not load the catalog') + '</div>'; return; }
            catalogCache = res.data;
            catalogSite = websiteId;
        }
        const d = catalogCache;
        if (!d.catalog_id) {
            box.innerHTML = '<div class="p-cell-muted">' + esc(d.error || 'No catalog is connected') + ' <a href="/chat/settings">' + esc('WhatsApp settings → Catalog') + '</a></div>';
            return;
        }
        box.innerHTML = '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px;"><b style="flex:1;">' + esc('Send to the customer') + '</b>'
            + '<button type="button" class="p-btn primary xs" onclick="catalogSend(\'\')">🛍 ' + esc('Send the whole catalog') + '</button></div>'
            + (d.products.length ? '<div class="cat-grid">' + d.products.map((p, i) =>
                '<div class="cat-item" onclick="catalogSend(' + i + ')">' + (p.image_url ? '<img src="' + esc(p.image_url) + '" alt="" loading="lazy">' : '') + '<div><b>' + esc(p.name) + '</b><br><span class="p-cell-muted">' + esc(p.price) + '</span></div></div>').join('') + '</div>'
              : '<div class="p-cell-muted">' + esc(d.error || 'No products in the catalog yet') + '</div>')
            + '<div class="p-cell-muted" style="font-size:12px;margin-top:6px;">' + esc('Text in the reply box is sent with it.') + '</div>';
    };
    window.catalogSend = async function (i) {
        if (!currentConversation) return;
        const p = i === '' ? null : catalogCache.products[i];
        const msgEl = document.getElementById('manualMessage');
        const text = msgEl.value.trim();
        appendPendingBubble((p ? '🛍 ' + p.name : '🛍 Catalog') + (text ? ' — ' + text : ''));
        msgEl.value = '';
        document.getElementById('catalogBox').style.display = 'none';
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/catalog', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ retailer_id: p ? p.retailer_id : '', name: p ? p.name : '', text: text }),
        });
        if (!res.success) { toast(res.error || 'Submission failed', 'error'); if (!msgEl.value) msgEl.value = text; }
        else if (res.data && res.data.sent === false) toast('Not delivered' + (res.data.error ? ': ' + res.data.error : sendFailReason(res.data.reason)), 'error');
        selectConversation(currentConversation.id, true).then(() => { const t = document.getElementById('convThread'); t.scrollTop = t.scrollHeight; });
    };
    window.blockToggle = async function () {
        if (!currentConversation) return;
        const blocked = (currentConversation.tags || []).includes('BLOCKED');
        if (!confirm(blocked ? 'Unblock this customer on WhatsApp?' : 'Block this customer on WhatsApp? They will not be able to message you, and auto replies stop.')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/block', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ block: !blocked }),
        });
        toast(res.success ? (blocked ? 'Customer unblocked' : 'Customer blocked') : (res.error || 'Operation failed'), res.success ? 'success' : 'error');
        if (res.success) { loadList(true); selectConversation(currentConversation.id, true); }
    };

    window.retryMessage = async function (messageId) {
        if (!currentConversation) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/messages/' + messageId + '/retry', { method: 'POST' });
        if (!res.success) toast(res.error || 'Retry failed', 'error');
        else toast(res.data && res.data.sent ? 'Sent' : 'Still not delivered' + sendFailReason(res.data && res.data.reason), res.data && res.data.sent ? 'success' : 'error');
        selectConversation(currentConversation.id, true);
    };

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        if (!panel) return;
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.style.display = 'none';
            return;
        }
        panel.style.display = 'block';
        panel.innerHTML = `
            <div class="p-card-head"><h3>${ic('target')} Information Lead</h3></div>
            <div class="p-kv"><span class="k">Score</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">Purchase intent</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">Destination</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">Interest</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">Status</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;"><strong>Suggested next step:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
        `;
    }

    async function refreshActive() {
        if (activeConvId) selectConversation(activeConvId);
    }

    // ===== In-Chat Quotes (بيع داخل الشات) =====
    function quoteBase() {
        return '/api/ai-chat/websites/' + websiteId + '/quotes';
    }

    // ===== لينك دفع عربون من الشات (لحجز موجود للعميل ده) =====
    window.payLinkToggle = async function () {
        const box = document.getElementById('payLinkBox');
        if (box.style.display === 'block') { box.style.display = 'none'; return; }
        box.style.display = 'block';
        const c = currentConversation || {};
        box.innerHTML = '<div class="p-cell-muted" style="font-size:13px;">⏳ Looking up the customer’s bookings...</div>';
        const qs = new URLSearchParams({ phone: c.customer_phone || '', email: c.customer_email || '' });
        const r = await fetchJSON('/api/chat/customer-bookings?' + qs.toString());
        const list = (r.success && r.data.bookings) ? r.data.bookings : [];
        if (!list.length) {
            payLinkBookingForm('مالقيناش حجز للعميل ده - اعمله هنا في ثانية، وبعدها نبعتله لينك العربون:');
            return;
        }
        box.innerHTML = '<div class="p-card" style="padding:10px 12px;"><div style="display:flex;align-items:center;gap:8px;"><b style="font-size:13px;flex:1;">اختار الحجز ونوع الدفع:</b><button class="p-btn outline xs" onclick="payLinkBookingForm()">+ New booking</button></div>' + list.map(function (b) {
            const due = Number(b.total_amount) - Number(b.amount_paid || 0);
            return '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px;font-size:13px;">'
                + '<span style="flex:1;min-width:160px;">' + esc((b.product_name || 'Booking') + ' · ' + b.start_date + ' · ') + '<span dir="ltr">' + esc(due.toFixed(2) + ' ' + b.currency) + '</span></span>'
                + '<button class="p-btn primary xs" onclick="payLinkMake(' + b.id + ',&quot;deposit_pct&quot;,30)">Deposit 30%</button>'
                + '<button class="p-btn outline xs" onclick="payLinkMake(' + b.id + ',&quot;full&quot;,0)">Full amount</button></div>';
        }).join('') + '</div>';
    };
    // ===== حجز جديد من الشات لعميل المحادثة =====
    window.payLinkBookingForm = async function (intro) {
        const box = document.getElementById('payLinkBox');
        const c = currentConversation || {};
        const pr = await fetchJSON('/api/crm/products?only_active=1');
        const products = (pr.success && pr.data.products) ? pr.data.products : [];
        if (!products.length) {
            box.innerHTML = '<div class="p-card" style="padding:10px 12px;font-size:13px;">مفيش رحلات متسجّلة لسه. ضيف رحلاتك في <a href="/website-builder">website Booking</a> الأول.</div>';
            return;
        }
        const t = new Date(); t.setDate(t.getDate() + 1);
        const d = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
        box.innerHTML = '<div class="p-card" style="padding:12px;">'
            + (intro ? '<div style="font-size:13px;margin-bottom:8px;">' + esc(intro) + '</div>' : '<b style="font-size:13px;">New booking</b>')
            + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:6px;">'
            + '<label class="form-label" style="margin:0;">Trip<select id="cbProduct" class="form-control">' + products.map(p => '<option value="' + p.id + '" data-price="' + Number(p.price || 0) + '" data-cur="' + esc(p.currency || '') + '">' + esc(p.name) + '</option>').join('') + '</select></label>'
            + '<label class="form-label" style="margin:0;">Date<input type="date" id="cbDate" class="form-control" value="' + d + '"></label>'
            + '<label class="form-label" style="margin:0;">Adults<input type="number" id="cbAdults" class="form-control" min="1" value="1"></label>'
            + '<label class="form-label" style="margin:0;">Children<input type="number" id="cbChildren" class="form-control" min="0" value="0"></label>'
            + '<label class="form-label" style="margin:0;">Name<input id="cbName" class="form-control" value="' + esc(c.customer_name || '') + '"></label>'
            + '<label class="form-label" style="margin:0;">Mobile<input id="cbPhone" class="form-control" dir="ltr" value="' + esc(c.customer_phone || '') + '"></label>'
            + '<label class="form-label" style="margin:0;">Email<input id="cbEmail" class="form-control" dir="ltr" value="' + esc(c.customer_email || '') + '"></label>'
            + '<label class="form-label" style="margin:0;">Total (Optional)<input type="number" id="cbTotal" class="form-control" min="0" step="0.01" placeholder="Price Trip × البالغين"></label>'
            + '</div><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">'
            + '<button class="p-btn primary xs" onclick="chatCreateBooking(true)">احجز وابعت لينك عربون 30%</button>'
            + '<button class="p-btn outline xs" onclick="chatCreateBooking(false)">Book only</button></div></div>';
    };
    window.chatCreateBooking = async function (withLink) {
        const v = id => (document.getElementById(id) || {}).value || '';
        const body = {
            product_id: parseInt(v('cbProduct'), 10), start_date: v('cbDate'), adults_count: parseInt(v('cbAdults'), 10) || 1,
            children_count: parseInt(v('cbChildren'), 10) || 0, customer_name: v('cbName').trim(), customer_phone: v('cbPhone').trim(),
            customer_email: v('cbEmail').trim(), source: 'chat',
        };
        if (v('cbTotal')) body.total_amount = Number(v('cbTotal'));
        if (!body.customer_name) { toast('Enter the customer name', 'error'); return; }
        const r = await fetchJSON('/api/bookings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        if (!r.success) { toast(r.error || 'تعذر عمل الحجز', 'error'); return; }
        const id = (r.data && (r.data.id || (r.data.booking && r.data.booking.id))) || null;
        toast('Booking created ' + ((r.data && r.data.booking_reference) || ''), 'success');
        if (withLink && id) { payLinkMake(id, 'deposit_pct', 30); return; }
        document.getElementById('payLinkBox').style.display = 'none';
    };

    window.payLinkMake = async function (bookingId, kind, value) {
        const r = await fetchJSON('/api/bookings/' + bookingId + '/payment-links', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ kind: kind, value: value, hours: 48 }) });
        if (!r.success) { toast(r.error || 'تعذر عمل اللينك', 'error'); return; }
        const ta = document.getElementById('manualMessage');
        ta.value = (ta.value ? ta.value.trim() + '\n\n' : '') + r.data.link.message;
        ta.focus();
        document.getElementById('payLinkBox').style.display = 'none';
        toast('The link was added to your reply - review it and press Send', 'success');
    };

    window.quoteToggleComposer = function () {
        const el = document.getElementById('quoteComposer');
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
        if (el.style.display === 'block') {
            quoteItems = [];
            quoteRenderComposer();
        }
    };

    window.quoteAddItem = function () {
        quoteItems.push({ name: '', qty: 1, unit_price: 0 });
        quoteRenderComposer();
    };

    window.quoteRemoveItem = function (i) {
        quoteItems.splice(i, 1);
        quoteRenderComposer();
    };

    window.quoteField = function (i, field, value) {
        quoteItems[i][field] = field === 'qty' ? Math.max(1, parseInt(value || '1', 10) || 1) : (field === 'unit_price' ? (parseFloat(value) || 0) : value);
        quoteRenderTotals();
    };

    function quoteSubtotal() {
        return quoteItems.reduce((sum, it) => sum + ((it.unit_price || 0) * (it.qty || 1)), 0);
    }

    window.quoteRenderTotals = quoteRenderTotals;
    function quoteRenderTotals() {
        const sub = quoteSubtotal();
        const disc = parseFloat(document.getElementById('qDiscount')?.value || '0') || 0;
        const el = document.getElementById('qTotals');
        if (el) el.textContent = (sub - Math.max(0, disc)).toFixed(2) + ' ' + (document.getElementById('qCurrency')?.value || 'USD');
    }

    function quoteRenderComposer() {
        const el = document.getElementById('quoteComposer');
        if (quoteItems.length === 0) quoteItems.push({ name: '', qty: 1, unit_price: 0 });
        el.innerHTML = `
            <div class="p-card" style="padding:12px;border:1px solid var(--panel-accent);">
                <div class="p-card-head"><h3>${ic('wallet')} New quote</h3></div>
                ${quoteItems.map((it, i) => `
                    <div class="q-row">
                        <input class="form-control" placeholder="Service/item name" value="${esc(it.name)}" oninput="quoteField(${i},'name',this.value)">
                        <input class="form-control" type="number" min="1" value="${it.qty}" oninput="quoteField(${i},'qty',this.value)" title="Quantity">
                        <input class="form-control" type="number" min="0" step="0.01" value="${it.unit_price}" oninput="quoteField(${i},'unit_price',this.value)" title="Unit price">
                        <button class="p-btn outline xs q-del" onclick="quoteRemoveItem(${i})">${ic('trash')}</button>
                    </div>`).join('')}
                <button class="p-btn outline xs" onclick="quoteAddItem()">${ic('plus')} Add item</button>
                <div class="form-group" style="margin-top:10px;">
                    <label>Discount</label>
                    <input class="form-control" id="qDiscount" type="number" min="0" step="0.01" value="0" oninput="quoteRenderTotals()">
                </div>
                <div class="form-group">
                    <label>Currency</label>
                    <select class="p-select" id="qCurrency" onchange="quoteRenderTotals()">
                        <option value="USD">USD</option><option value="EGP">EGP</option><option value="EUR">EUR</option><option value="SAR">SAR</option><option value="AED">AED</option><option value="GBP">GBP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Internal notes (optional)</label>
                    <textarea class="form-control" id="qNotes" rows="2" placeholder="Any notes for the agent..."></textarea>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                    <strong>Total:</strong> <span id="qTotals">0.00 USD</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn primary" onclick="quoteCreate()">${ic('check')} Create quote</button>
                </div>
            </div>`;
        quoteRenderTotals();
    }

    window.quoteCreate = async function () {
        const items = quoteItems
            .filter(it => (it.name || '').trim() !== '')
            .map(it => ({ name: it.name.trim(), qty: it.qty || 1, unit_price: it.unit_price || 0 }));
        if (!items.length) { toast('Add at least one item', 'error'); return; }
        const btn = event.target;
        btn.disabled = true;
        const res = await fetchJSON(quoteBase(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: currentConversation.id,
                items: items,
                discount: parseFloat(document.getElementById('qDiscount').value) || 0,
                currency: document.getElementById('qCurrency').value,
                notes: document.getElementById('qNotes').value.trim() || null,
            }),
        });
        btn.disabled = false;
        if (res.success) {
            toast('Quote created', 'success');
            document.getElementById('quoteComposer').style.display = 'none';
            quoteItems = [];
            quoteLoad();
        } else {
            toast(res.error || 'Failed to create the quote', 'error');
        }
    };

    window.quoteLoad = async function () {
        if (!activeConvId) return;
        const res = await fetchJSON(quoteBase() + '?conversation_id=' + activeConvId);
        const wrap = document.getElementById('quoteList');
        if (!res.success || !res.data || !res.data.quotes.length) {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = 'block';
        wrap.innerHTML = res.data.quotes.map(q => quoteCardHtml(q)).join('');
    };

    function quoteCardHtml(q) {
        const items = (q.items || []).map(it => `
            <div class="ai-quote-row"><span>${esc(it.name)} × ${it.qty}</span><span>${Number(it.line_total).toFixed(2)} ${esc(q.currency)}</span></div>`).join('');
        const actions = [];
        if (q.status === 'draft') {
            actions.push(`<button class="p-btn primary xs" onclick="quoteSend(${q.id})">${ic('send')} Send to customer</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'cancelled')">${ic('x')} Cancel</button>`);
        } else if (q.status === 'sent') {
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'accepted')">${ic('check')} Accept</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'declined')">${ic('x')} Reject</button>`);
        }
        return `
            <div class="ai-quote-card">
                <div class="q-head">
                    <span><strong>${ic('wallet')} ${esc(q.quote_number || 'Price quote')}</strong></span>
                    <span class="pill ${QUOTE_STATUS_CLASS[q.status] || 'gray'}">${esc(QUOTE_STATUS_LABEL[q.status] || q.status)}</span>
                </div>
                <div style="padding:8px 0;">${items}</div>
                <div class="ai-quote-row total"><span>Total</span><span>${Number(q.total).toFixed(2)} ${esc(q.currency)}</span></div>
                ${actions.length ? '<div class="ai-quote-actions">' + actions.join('') + '</div>' : ''}
            </div>`;
    }

    window.quoteSend = async function (quoteId) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId + '/send', { method: 'POST' });
        if (res.success) { toast('Quote sent to the customer', 'success'); quoteLoad(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'Submission failed', 'error'); }
    };

    window.quoteSetStatus = async function (quoteId, status) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) {
            toast('Quote status updated', 'success');
            quoteLoad();
            if (status === 'accepted') {
                updateField('lead_status', 'converted');
                updateField('status', 'resolved');
            }
        }
        else { toast(res.error || 'Update failed', 'error'); }
    };

    document.getElementById('ucSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadList();
    });
    window.addEventListener('tourfecto:website-changed', function () {
        activeConvId = null;
        currentConversation = null;
        document.getElementById('ucEmptyState').style.display = 'block';
        document.getElementById('ucThreadPanel').style.display = 'none';
        document.getElementById('ucBody').classList.remove('wa-chat-open'); document.body.classList.remove('wa-in-chat');
        loadList();
    });

    // ===== Websites this user can open: own + team =====
    async function initSites() {
        const res = await fetchJSON('/api/ai-chat/inbox-websites');
        const sites = res.success ? (res.data.websites || []) : [];
        const sel = document.getElementById('ucSite');
        if (!sites.length) return;
        let current = String(P.getCurrentWebsiteId() || '');
        if (!sites.some(w => String(w.id) === current)) {
            current = String(sites[0].id);
            // Set quietly: loadList below picks it up.
            try { localStorage.setItem('tourfecto_current_website_id', current); } catch (e) {}
        }
        const team = sites.some(w => w.role !== 'owner');
        if (sites.length > 1 || team) {
            sel.innerHTML = sites.map(w => '<option value="' + w.id + '">' + esc(w.name || ('#' + w.id)) + (w.role !== 'owner' ? ' · ' + esc(TEAM_SUFFIX) : '') + '</option>').join('');
            sel.value = current;
            sel.style.display = '';
            sel.addEventListener('change', function () { P.setCurrentWebsiteId(sel.value); });
        }
    }
    window.addEventListener('tourfecto:website-changed', function (e) {
        const sel = document.getElementById('ucSite');
        if (sel && e.detail && e.detail.websiteId) sel.value = String(e.detail.websiteId);
        websiteId = P.getCurrentWebsiteId();
    });

    initSites().then(function () { websiteId = P.getCurrentWebsiteId(); loadList(); });

    // ===== تحديث لحظي خفيف =====
    // بنسأل السيرفر "in New?" كل 4 ثواني (والصفحة ظاهرة بس)، وبنحدّث القائمة/المحادثة
    // من غير وميض لما فعلاً يجي جديد. العنوان بيبيّن عدد غير المقروء.
    let lastFp = null, lastActiveFp = null, pulseBusy = false, lastUnread = null, lastHiddenPoll = 0;
    const baseTitle = document.title;

    // ===== New-message alert (sound + desktop notification) =====
    let soundOn = true, audioCtx = null;
    const NOTIF_TITLE = 'New WhatsApp/chat message', NOTIF_BODY = 'unread message(s) in your inbox', SOUND_ON = 'New-message alerts are on (click to mute)', SOUND_OFF = 'New-message alerts are muted (click to turn on)';
    try { soundOn = localStorage.getItem('tf_chat_sound') !== 'off'; } catch (e) {}
    function paintSoundBtn() {
        const b = document.getElementById('ucSoundBtn');
        if (b) { b.textContent = soundOn ? '🔔' : '🔕'; b.title = soundOn ? SOUND_ON : SOUND_OFF; }
    }
    paintSoundBtn();
    function unlockAudio() {
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) {}
    }
    document.addEventListener('pointerdown', unlockAudio, { once: true });
    window.toggleSound = function () {
        soundOn = !soundOn;
        try { localStorage.setItem('tf_chat_sound', soundOn ? 'on' : 'off'); } catch (e) {}
        paintSoundBtn();
        unlockAudio();
        if (soundOn && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    };
    function alertNewMessage(count) {
        if (!soundOn) return;
        try {
            if (audioCtx && audioCtx.state === 'running') {
                const o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime;
                o.type = 'sine';
                o.frequency.setValueAtTime(880, t);
                o.frequency.setValueAtTime(1175, t + 0.12);
                g.gain.setValueAtTime(0.0001, t);
                g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
                o.connect(g); g.connect(audioCtx.destination);
                o.start(t); o.stop(t + 0.4);
            }
        } catch (e) {}
        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') {
            try {
                const n = new Notification(NOTIF_TITLE, { body: count + ' ' + NOTIF_BODY, tag: 'tf-chat', renotify: true });
                n.onclick = function () { window.focus(); n.close(); };
            } catch (e) {}
        }
    }

    async function pulse() {
        if (pulseBusy) return;
        // In a background tab: check every 15 seconds so alerts still arrive.
        if (document.hidden) {
            if (Date.now() - lastHiddenPoll < 15000) return;
            lastHiddenPoll = Date.now();
        }
        const id = P.getCurrentWebsiteId && P.getCurrentWebsiteId();
        if (!id) return;
        pulseBusy = true;
        try {
            const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(id) + '/conversations/pulse' + (activeConvId ? '?active=' + activeConvId : ''));
            if (!res.success || !res.data) return;
            const d = res.data;
            document.title = (d.unread > 0 ? '(' + d.unread + ') ' : '') + baseTitle;
            if (lastUnread !== null && d.unread > lastUnread) alertNewMessage(d.unread);
            lastUnread = d.unread;
            if (document.hidden) return; // refresh the screen when the user comes back
            if (lastFp !== null && d.fp !== lastFp) {
                loadList(true);
            }
            let activeDone = true;
            if (activeConvId && lastActiveFp !== null && d.active_fp && d.active_fp !== lastActiveFp) {
                activeDone = (await selectConversation(activeConvId, true)) !== false;
            }
            lastFp = d.fp;
            if (activeDone) lastActiveFp = d.active_fp || null;
        } finally {
            pulseBusy = false;
        }
    }
    setInterval(pulse, 4000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pulse(); });
    // احتياطي لو البصمة ماتغيرتش لأي سبب
    setInterval(function () { if (!document.hidden) loadList(true); }, 60000);
})();
JS;
        $script = str_replace('__CURRENT_USER_ID__', (string) $currentUserId, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('sidebar.chat'), $this->tr('chat.page_subtitle'), $this->applyChatUi($body), $script);
        exit;
    }
    /**
     * GET /chat/conversation/{id}
     *
     * ⚠️ ملاحظة توثيق مهمة (AI Chat Platform - توحيد Frontend/Backend):
     * قبل كده الـ{id} في الرابط كان `chat_messages.session_id` (نص قديم)
     * والبيانات جايه من `/api/chat/conversation/{session_id}`. دلوقتي
     * الـ{id} هو معرّف المحادثة الموحّدة (`ai_conversations.id`) والبيانات
     * جايه من `/api/ai-chat/websites/{website}/conversations/{id}` (بند 1،
     * 2، 3، 8، 9، 10، 11 من AI Chat Backend) - نفس الـRoute بالظبط، لكن
     * معنى الـID اتغيّر ليواكب Unified Inbox الحقيقي. أي رابط قديم فيه
     * session_id نصّي مش رقمي هيوصل لصفحة "المحادثة غير موجودة" بدل ما
     * يفشل بصمت.
     *
     * حقول واضح إنها غير متاحة حاليًا في AI Chat Backend (Notes، Related
     * Deal، وقائمة موظفين للتعيين) اتوثّقت بمكانها بدل ما تُختلق - راجع
     * CHANGELOG.
     */
    public function showConversation(array $params): array
    {
        $conversationId = (int) ($params['id'] ?? $params['session_id'] ?? 0);
        $currentUserId = (int) ($this->user['id'] ?? 0);

        $body = <<<HTML
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div id="loadingConv" class="p-empty"><div class="p-empty-icon">{IC_CLOCK}</div>Loading conversation...</div>
        <div id="convNotFound" class="p-empty" style="display:none;"><div class="p-empty-icon">{IC_ALERT}</div>The conversation does not exist or does not belong to the current website.</div>

        <div id="convBody" style="display:none;">
            <div class="p-card" id="convHeader" style="margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2 1 420px;min-width:280px;">
                    <div class="p-card" id="convThread" style="max-height:480px;overflow-y:auto;"></div>

                    <div class="p-card" style="margin-top:14px;">
                        <div class="p-card-head"><h3>Reply</h3></div>
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="Write your reply here..."></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">➤ Send</button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">💡 Suggest a reply AI</button>
                        </div>
                    </div>
                </div>

                <div style="flex:1 1 260px;min-width:240px;">
                    <div class="p-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>Notes and deals</h3></div>
                        <div class="p-empty" style="padding:16px 0;">
                            <div class="p-empty-icon">🧩</div>
                            The linked notes and deals feature is not available right now in AI Chat Backend.
                        </div>
                    </div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;
    const conversationId = __CONVERSATION_ID__;
    const currentUserId = __CURRENT_USER_ID__;
    let websiteId = null;
    let currentConversation = null;

    const STATUS_OPTIONS = [
        ['open', 'Open'], ['pending', 'Pending'], ['resolved', 'Resolved'], ['closed', 'Closed'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'Low'], ['normal', 'Normal'], ['high', 'High'], ['urgent', 'Urgent'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];
    const CHANNEL_LABEL = {
        whatsapp: '📱 WhatsApp', website_chat: '🌐 Website chat', webchat: '🌐 Website chat',
        messenger: '📘 Messenger', instagram: '📷 Instagram', email: '✉️ Email',
    };

    if (!conversationId) {
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'block';
        return;
    }

    window.toggleHandoff = async function () {
        const isAi = currentConversation.ai_status === 'ai';
        const url = '/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + (isAi ? '/handoff' : '/resume-ai');
        const res = await fetchJSON(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: isAi ? JSON.stringify({ reason: 'manual_takeover' }) : null,
        });
        if (res.success) { toast(isAi ? 'The conversation was handed off to you' : 'Auto reply restored', 'success'); load(); }
        else { toast(res.error || 'Operation failed', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'Unassigned' : 'The conversation was assigned to you', 'success'); load(); }
        else { toast(res.error || 'Operation failed', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('Updated', 'success'); load(); }
        else { toast(res.error || 'Update failed', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    function sendFailReason(reason) {
        const reasons = {
            outside_24h: 'the customer has not messaged in the last 24 hours (WhatsApp only allows a template message now)',
            no_recipient: 'no valid WhatsApp number or ID for this customer',
            not_connected: 'WhatsApp is not connected for this website',
            provider_rejected: 'WhatsApp (Meta) rejected the message; check the connection and the logs',
        };
        return reasons[reason] ? ': ' + reasons[reason] : '';
    }

    window.sendManual = async function () {
        const message = document.getElementById('manualMessage').value.trim();
        if (!message) { toast('Write a message first', 'error'); return; }
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'The message was saved but actually sending it to the customer failed' + sendFailReason(res.data.reason) : 'Sent', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            load();
        } else {
            toast(res.error || 'Submission failed', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">🤖 Generating suggestions...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">⚠️ ' + esc((res.data && res.data.error) || res.error || 'No suggestions available right now') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card" style="padding:10px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">Suggestion ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = c.customer_name || c.customer_phone || c.customer_email || 'Unknown customer';
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const TAG_LABEL = {HOT_LEAD: '🔥 Hot lead', NEW_INQUIRY: 'New inquiry', PRICE_REQUEST: 'Price request', COMPLAINT: 'Complaint', FOLLOW_UP: 'Needs follow-up', BOOKING_INTENT: 'Wants to book', VIP: 'VIP', HUMAN_REQUIRED: 'Needs a human'};

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${TAG_LABEL[t] || t}</span>`;
        }).join(' ');

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} ${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</h3>
                <span class="p-cell-muted">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;">
                ${isAi ? '<span class="pill green">🤖 Replying now: AI</span>' : '<span class="pill">👤 Replying now: agent</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${isAi ? '⇄ Hand off to agent' : '⇄ Restore the auto reply'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? '✖ Unassign me' : '👤 Assign to me'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${c.ai_confidence_score !== null && c.ai_confidence_score !== undefined ? '<span class="pill">Trust AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>' : ''}
            </div>
            <div style="margin-bottom:8px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:10px 14px;"><strong>Summary AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">💬</div>No messages in this conversation yet</div>';
            return;
        }
        thread.innerHTML = messages.map(m => {
            const mine = m.message_direction === 'outgoing';
            return `
                <div style="max-width:70%;margin:${mine ? '8px 0 8px auto' : '8px auto 8px 0'};padding:10px 14px;border-radius:12px;background:${mine ? 'var(--panel-accent)' : 'var(--panel-card-bg,#f1f2f4)'};color:${mine ? '#fff' : 'inherit'};">
                    <div>${esc(m.message_text || m.ai_reply_generated || '')}</div>
                    <div style="font-size:11px;opacity:.7;margin-top:4px;">${formatDate(m.sent_at || m.created_at)}</div>
                </div>`;
        }).join('');
        thread.scrollTop = thread.scrollHeight;
    }

    function renderLeadPanel(leads) {
        const panel = document.getElementById('leadPanel');
        const lead = (leads && leads.length) ? leads[0] : null;
        if (!lead) {
            panel.innerHTML = '<div class="p-card-head"><h3>Information Lead</h3></div><div class="p-empty" style="padding:16px 0;"><div class="p-empty-icon">📋</div>No lead linked to this conversation yet.</div>';
            return;
        }
        panel.innerHTML = `
            <div class="p-card-head"><h3>Information Lead</h3></div>
            <div class="p-kv"><span class="k">Score</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">Purchase intent</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">Destination</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">Interest</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">Status</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-bg,#f7f8fa);border-radius:8px;"><strong>Suggested next step:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
            <a href="/chat/leads" class="p-btn outline xs" style="margin-top:10px;display:inline-block;">View allLeads</a>
        `;
    }

    async function load() {
        websiteId = P.getCurrentWebsiteId();
        if (!websiteId) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            document.getElementById('convNotFound').innerHTML = '<div class="p-empty-icon">🌐</div>Choose a website from the list at the top of the page first.';
            return;
        }

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId);
        if (!res.success || !res.data || !res.data.conversation) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            return;
        }

        currentConversation = res.data.conversation;
        document.getElementById('loadingConv').style.display = 'none';
        document.getElementById('convNotFound').style.display = 'none';
        document.getElementById('convBody').style.display = 'block';

        renderHeader(currentConversation);
        renderThread(res.data.messages || []);

        fetchJSON('/api/ai-chat/websites/' + websiteId + '/leads?conversation_id=' + conversationId).then(leadRes => {
            renderLeadPanel(leadRes.success ? leadRes.data.leads : []);
        });
    }

    load();
    setInterval(function () { if (!document.hidden) load(); }, 5000);
})();
JS;
        $script = str_replace(
            ['__CONVERSATION_ID__', '__CURRENT_USER_ID__'],
            [(string) $conversationId, (string) $currentUserId],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.conv.title'), $this->tr('chat.conv.subtitle'), $this->applyChatUi($body), $script);
        exit;
    }

    public function getConversation(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $sessionId = $params['session_id'] ?? $params['id'] ?? null;
        if (!$sessionId) {
            return $this->error('Conversation ID is required', 422);
        }

        try {
            $sql = "SELECT * FROM chat_messages WHERE user_id = ? AND session_id = ? ORDER BY created_at ASC";
            $messages = $this->db->query($sql, [$this->user['id'], $sessionId]);
            return $this->success(['messages' => $messages]);
        } catch (Exception $e) {
            Logger::error('Get Conversation Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load the conversation', 500);
        }
    }

    /** GET /chat/pending */
    public function showPending(array $params = []): array
    {
        $tLoading = $this->tr('common.loading');

        $body = <<<HTML
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div id="pendingList" class="p-empty">{$tLoading}</div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    window.regenerateReply = async function (id) {
        const box = document.getElementById('reply-' + id);
        const btn = document.getElementById('regenBtn-' + id);
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = '🤖 __GENERATING__';

        const res = await fetchJSON('/api/chat/generate-reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id }),
        });

        btn.disabled = false;
        btn.textContent = original;

        if (res.success) {
            box.value = res.data.reply;
            toast(__NEW_REPLY_GENERATED__, 'success');
        } else {
            toast(res.error || __GENERATE_FAILED__, 'error');
        }
    };

    window.approveMsg = async function (id, action) {
        const editedReply = action === 'approve' ? document.getElementById('reply-' + id).value.trim() : '';
        if (action === 'approve' && !editedReply) {
            toast(__WRITE_OR_GENERATE_FIRST__, 'error');
            return;
        }
        const res = await fetchJSON('/api/chat/approve', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message_id: id, action: action, edited_reply: editedReply }),
        });
        if (res.success) {
            if (action === 'approve' && res.data && res.data.sent === false) {
                toast(res.data.message || __APPROVED_SEND_FAILED__, 'error');
            } else {
                toast(res.data && res.data.message ? res.data.message : (action === 'approve' ? __APPROVED_SENT__ : __REJECTED__), 'success');
            }
            load();
        }
        else { toast(res.error || __ACTION_FAILED__, 'error'); }
    };

    async function load() {
        const res = await fetchJSON('/api/chat/pending');
        const container = document.getElementById('pendingList');
        if (res.success && Array.isArray(res.data.pending) && res.data.pending.length) {
            container.innerHTML = res.data.pending.map(m => `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head">
                        <h3>${esc(m.customer_name || m.customer_phone || __CUSTOMER__)} <span class="pill">${esc(m.platform || '-')}</span></h3>
                        <span class="p-cell-muted">${formatDate(m.created_at)}</span>
                    </div>
                    <div class="p-kv"><span class="k">__CUSTOMER_MESSAGE__</span></div>
                    <p style="background:var(--panel-bg,#f7f8fa);padding:12px 14px;border-radius:8px;margin:6px 0 14px;">${esc(m.message_text || '-')}</p>
                    <label class="form-label">__SUGGESTED_REPLY__</label>
                    <textarea id="reply-${m.id}" class="form-control" style="min-height:90px;margin-bottom:10px;">${esc(m.ai_reply_generated || '')}</textarea>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn success xs" onclick="approveMsg(${m.id}, 'approve')">✔ __APPROVE_SEND__</button>
                        <button class="p-btn outline xs" id="regenBtn-${m.id}" onclick="regenerateReply(${m.id})">🔄 __GENERATE_NEW__</button>
                        <button class="p-btn danger xs" onclick="approveMsg(${m.id}, 'reject')">✖ __REJECT__</button>
                    </div>
                </div>`).join('');
        } else {
            container.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('check') + '</div>__NO_PENDING__</div>';
        }
    }
    load();
})();
JS;
        $script = str_replace(
            [
                '__GENERATING__', '__NEW_REPLY_GENERATED__', '__GENERATE_FAILED__', '__WRITE_OR_GENERATE_FIRST__',
                '__APPROVED_SEND_FAILED__', '__APPROVED_SENT__', '__REJECTED__', '__ACTION_FAILED__', '__CUSTOMER__',
                '__CUSTOMER_MESSAGE__', '__SUGGESTED_REPLY__', '__APPROVE_SEND__', '__GENERATE_NEW__', '__REJECT__', '__NO_PENDING__',
            ],
            [
                $this->trJs('chat.pending.generating'),
                $this->trJs('chat.pending.new_reply_generated'),
                $this->trJs('chat.pending.generate_failed'),
                $this->trJs('chat.pending.write_or_generate_first'),
                $this->trJs('chat.pending.approved_send_failed'),
                $this->trJs('chat.pending.approved_sent'),
                $this->trJs('chat.pending.rejected'),
                $this->trJs('chat.pending.action_failed'),
                $this->tr('chat.pending.customer'),
                $this->tr('chat.pending.customer_message'),
                $this->tr('chat.pending.suggested_reply'),
                $this->tr('chat.pending.approve_send'),
                $this->tr('chat.pending.generate_new'),
                $this->tr('chat.pending.reject'),
                $this->tr('chat.pending.none'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.pending.title'), $this->tr('chat.pending.subtitle'), $this->applyChatUi($body), $script);
        exit;
    }

    /** GET /chat/settings */
    public function showSettings(array $params = []): array
    {
        $tSelectWebsite = $this->tr('chat.settings.select_website');
        $tLoadingWebsites = $this->tr('chat.settings.loading_websites');
        $tWhatsappTitle = $this->tr('chat.settings.whatsapp_title');
        $tWhatsappSub = $this->tr('chat.settings.whatsapp_sub');
        $tConnectedInstance = $this->tr('chat.settings.connected_instance');
        $tWebhookUrlHint = $this->tr('chat.settings.webhook_url_hint');
        $tDisconnectLink = $this->tr('chat.settings.disconnect_link');
        $tFreeAccountHint = $this->tr('chat.settings.free_account_hint');
        $tInstanceIdPlaceholder = $this->tr('chat.settings.instance_id_placeholder');
        $tConnectAccount = $this->tr('chat.settings.connect_account');
        $tBotSettings = $this->tr('chat.settings.bot_settings');
        $tEnableBot = $this->tr('chat.settings.enable_bot');
        $tAutoPilot = $this->tr('chat.settings.auto_pilot');
        $tRequiresApproval = $this->tr('chat.settings.requires_approval');
        $tGreetingMsg = $this->tr('chat.settings.greeting_msg');
        $tFallbackMsg = $this->tr('chat.settings.fallback_msg');
        $tReplyLanguage = $this->tr('chat.settings.reply_language');
        $tSaveSettings = $this->tr('chat.settings.save_settings');
        $tNoWebsitesMsg = $this->tr('chat.settings.no_websites_msg');

        $body = <<<HTML
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">← Inbox</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">📚 Knowledge base</a>
        </div>
        <div class="p-card">
            <div class="form-group">
                <label class="form-label" for="websiteSelect">{$tSelectWebsite}</label>
                <select id="websiteSelect" class="form-control" onchange="loadSettings()">
                    <option value="">{$tLoadingWebsites}</option>
                </select>
            </div>
        </div>
        <div id="waCloudCard" class="p-card" style="display:none;margin-top:14px;"></div>
        <script src="/assets/js/wa-cloud.js?v=10" defer></script>
        <div id="waQrCard" class="p-card" style="display:none;margin-top:14px;"></div>
        <div id="metaChatCard" class="p-card" style="display:none;margin-top:14px;"></div>
        <script src="/assets/js/chat-connect.js?v=1" defer></script>
        <div id="ultramsgCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📱 {$tWhatsappTitle}</h3><span class="p-card-sub">{$tWhatsappSub}</span></div>
            <div id="ultramsgConnected" style="display:none;">
                <div class="alert alert-success">✔ {$tConnectedInstance} <span id="umInstanceId"></span></div>
                <p class="p-cell-muted">{$tWebhookUrlHint}</p>
                <code id="umWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="disconnectUltraMsg()">{$tDisconnectLink}</button>
            </div>
            <div id="ultramsgDisconnected" style="display:none;">
                <p class="p-cell-muted">{$tFreeAccountHint} <a href="https://ultramsg.com" target="_blank" rel="noopener">ultramsg.com</a></p>
                <div class="form-group">
                    <input type="text" id="umInstanceInput" class="form-control" placeholder="{$tInstanceIdPlaceholder}" style="margin-bottom:8px;">
                </div>
                <div class="form-group">
                    <input type="text" id="umTokenInput" class="form-control" placeholder="API Token">
                </div>
                <div id="ultramsgAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
                <button class="p-btn primary" style="margin-top:10px;" onclick="connectUltraMsg()">{$tConnectAccount}</button>
            </div>
        </div>
        <div id="settingsFormCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>{$tBotSettings}</h3></div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="isEnabled"> {$tEnableBot}</label>
            </div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="autoPilot"> {$tAutoPilot}</label>
            </div>
            <div class="form-group">
                <label class="form-label"><input type="checkbox" id="requiresApproval"> {$tRequiresApproval}</label>
            </div>
            <div class="form-group">
                <label class="form-label" for="greetingMsg">{$tGreetingMsg}</label>
                <textarea id="greetingMsg" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" for="fallbackMsg">{$tFallbackMsg}</label>
                <textarea id="fallbackMsg" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" for="aiLanguage">{$tReplyLanguage}</label>
                <select id="aiLanguage" class="form-control">
                    <option value="ar">Arabic</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div id="settingsAlert" class="alert alert-danger" style="display:none;"></div>
            <button class="p-btn primary" onclick="saveSettings()">{$tSaveSettings}</button>
        </div>
        <div class="p-card" id="noWebsitesCard" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">{IC_GLOBE}</div>{$tNoWebsitesMsg}</div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let websites = [];

    window.loadSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        if (!websiteId) {
            document.getElementById('settingsFormCard').style.display = 'none';
            document.getElementById('ultramsgCard').style.display = 'none';
            if (window.ChatConnect) window.ChatConnect.load(null);
            return;
        }

        document.getElementById('ultramsgCard').style.display = 'block';
        await loadUltraMsgStatus(websiteId);
        if (window.ChatConnect) window.ChatConnect.load(websiteId);

        const res = await fetchJSON('/api/chat/settings?website_id=' + websiteId + '&platform=all');
        document.getElementById('settingsFormCard').style.display = 'block';
        if (!res.success) { toast(res.error || __LOAD_SETTINGS_FAILED__, 'error'); return; }

        const s = res.data.settings || {};
        document.getElementById('isEnabled').checked = !!(s.is_enabled == 1);
        document.getElementById('autoPilot').checked = !!(s.auto_pilot == 1);
        document.getElementById('requiresApproval').checked = !!(s.requires_approval == 1);
        document.getElementById('greetingMsg').value = s.greeting_message || '';
        document.getElementById('fallbackMsg').value = s.fallback_message || '';
        document.getElementById('aiLanguage').value = s.ai_language || 'ar';
    };

    async function loadUltraMsgStatus(websiteId) {
        const res = await fetchJSON('/api/chat/ultramsg/status?website_id=' + websiteId);
        const connectedBox = document.getElementById('ultramsgConnected');
        const disconnectedBox = document.getElementById('ultramsgDisconnected');

        if (res.success && res.data.connected) {
            connectedBox.style.display = 'block';
            disconnectedBox.style.display = 'none';
            document.getElementById('umInstanceId').textContent = res.data.instance_id || '';
            document.getElementById('umWebhookUrl').textContent = res.data.webhook_url || '';
        } else {
            // مفيش إدخال مفاتيح للعميل: ربط واتساب بقى بمسح QR. الكارت ده بيظهر بس لاتصال UltraMsg قديم.
            connectedBox.style.display = 'none';
            disconnectedBox.style.display = 'none';
            document.getElementById('ultramsgCard').style.display = 'none';
        }
    }

    window.connectUltraMsg = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const instanceId = document.getElementById('umInstanceInput').value.trim();
        const apiKey = document.getElementById('umTokenInput').value.trim();
        const alertBox = document.getElementById('ultramsgAlert');
        alertBox.style.display = 'none';

        if (!instanceId || !apiKey) { toast(__WRITE_INSTANCE_TOKEN__, 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/ultramsg', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, instance_id: instanceId, api_key: apiKey }),
        });

        if (res.success) {
            toast(__CONNECTED_SUCCESS__, 'success');
            loadUltraMsgStatus(websiteId);
        } else {
            alertBox.textContent = res.error || __CONNECT_FAILED__;
            alertBox.style.display = 'block';
        }
    };

    window.disconnectUltraMsg = async function () {
        if (!confirm(__DISCONNECT_CONFIRM__)) return;
        const websiteId = document.getElementById('websiteSelect').value;
        const res = await fetchJSON('/api/chat/disconnect/ultramsg/' + websiteId, { method: 'POST' });
        if (res.success) { toast(__DISCONNECTED__, 'success'); loadUltraMsgStatus(websiteId); }
        else { toast(res.error || __DISCONNECT_FAILED__, 'error'); }
    };

    window.saveSettings = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const alertBox = document.getElementById('settingsAlert');
        alertBox.style.display = 'none';

        const settings = {
            is_enabled: document.getElementById('isEnabled').checked ? 1 : 0,
            auto_pilot: document.getElementById('autoPilot').checked ? 1 : 0,
            requires_approval: document.getElementById('requiresApproval').checked ? 1 : 0,
            greeting_message: document.getElementById('greetingMsg').value,
            fallback_message: document.getElementById('fallbackMsg').value,
            ai_language: document.getElementById('aiLanguage').value,
        };

        const res = await fetchJSON('/api/chat/settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, platform: 'all', settings: settings }),
        });

        if (res.success) {
            toast(__SETTINGS_SAVED__, 'success');
        } else {
            alertBox.textContent = res.error || __SAVE_SETTINGS_FAILED__;
            alertBox.style.display = 'block';
        }
    };

    async function init() {
        const res = await fetchJSON('/api/websites');
        const select = document.getElementById('websiteSelect');
        websites = (res.success && Array.isArray(res.data.websites)) ? res.data.websites : [];

        if (!websites.length) {
            document.getElementById('noWebsitesCard').style.display = 'block';
            select.innerHTML = '<option value="">__NO_WEBSITES__</option>';
            return;
        }

        select.innerHTML = '<option value="">__CHOOSE_WEBSITE__</option>' + websites.map(w => `<option value="${w.id}">${esc(w.main_url || w.company_name || (__WEBSITE_HASH__ + w.id))}</option>`).join('');
        window.Panel.syncWebsiteSelect('websiteSelect');
        if (select.value) loadSettings();
    }
    init();
})();
JS;
        $script = str_replace(
            [
                '__LOAD_SETTINGS_FAILED__', '__WRITE_INSTANCE_TOKEN__', '__CONNECTED_SUCCESS__', '__CONNECT_FAILED__',
                '__DISCONNECT_CONFIRM__', '__DISCONNECTED__', '__DISCONNECT_FAILED__', '__SETTINGS_SAVED__',
                '__SAVE_SETTINGS_FAILED__', '__NO_WEBSITES__', '__CHOOSE_WEBSITE__', '__WEBSITE_HASH__',
            ],
            [
                $this->trJs('chat.settings.load_failed'),
                $this->trJs('chat.settings.write_instance_token'),
                $this->trJs('chat.settings.connected_success'),
                $this->trJs('chat.settings.connect_failed'),
                $this->trJs('chat.settings.disconnect_confirm'),
                $this->trJs('common.disconnected'),
                $this->trJs('common.disconnect_failed'),
                $this->trJs('common.saved'),
                $this->trJs('chat.settings.save_settings_failed'),
                $this->trJs('chat.settings.no_websites'),
                $this->trJs('chat.settings.choose_website'),
                $this->trJs('chat.settings.website_hash'),
            ],
            $script
        );

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_inbox', $this->tr('chat.settings.title'), $this->tr('chat.settings.subtitle'), $this->applyChatUi($body), $script);
        exit;
    }

    /**
     * GET /chat/knowledge-base
     *
     * واجهة إدارة قاعدة معرفة الشركة (بند 4 + 13 Brand Voice) - أول
     * واجهة إدارية حقيقية لـAI Chat Backend. من غيرها، صاحب الشركة
     * (غير التقني) معندوش أي طريقة يدخّل بيها معلومات شركته/خدماته/
     * أسعاره غير عن طريق استدعاء الـAPI مباشرة.
     *
     * تستخدم الـEndpoints الموجودة بالفعل من المرحلة 1 حرفيًا
     * (AiKnowledgeBaseController: index/store/update/destroy/preview) -
     * صفر Endpoint جديد.
     */
    public function showKnowledgeBase(array $params = []): array
    {
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}Back to inbox</a>
            <div style="flex:1;"></div>
            <button class="p-btn outline xs" onclick="kbPreview()">{IC_SPARKLES}Preview of the context sent to AI</button>
        </div>

        <div id="kbNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>Choose a website from the list at the top of the page first.</div>
        </div>

        <div id="kbBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;border:1px solid rgba(37,99,235,.35);">
                <div class="p-card-head"><h3>🧪 Try your chat as a traveller</h3><span class="p-card-sub">Nothing is saved and no customer receives anything</span></div>
                <div class="p-cell-muted" style="font-size:13px;margin-bottom:10px;line-height:1.7;">Write a question the way a tourist would (in any language). The assistant answers using exactly what is in this knowledge base. If the answer is wrong or missing, add the information below and try again.</div>
                <div id="pgLog" style="display:flex;flex-direction:column;gap:8px;max-height:360px;overflow:auto;margin-bottom:10px;"></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;" id="pgSamples"></div>
                <div style="display:flex;gap:8px;">
                    <input id="pgInput" class="form-control" maxlength="1500" placeholder="e.g. How much is the Luxor day trip for 2 adults?" onkeydown="if(event.key==='Enter'){event.preventDefault();pgSend();}">
                    <button class="p-btn primary" id="pgBtn" onclick="pgSend()">Send</button>
                    <button class="p-btn outline" onclick="pgReset()" title="Start over">↺</button>
                </div>
            </div>
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>🎙 Company tone (Brand Voice)</h3><span class="p-card-sub">Used in all AI replies</span></div>
                <div class="form-group">
                    <label class="form-label">Tone</label>
                    <select id="bvTone" class="form-control">
                        <option value="professional">Professional</option>
                        <option value="friendly">Friendly</option>
                        <option value="luxury">Luxury</option>
                        <option value="casual">Informal</option>
                        <option value="formal">Very formal</option>
                        <option value="sales_focused">Sales focused</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Additional instructions (optional)</label>
                    <textarea id="bvInstructions" class="form-control" rows="2" placeholder="Example: always mention the free cancellation policy up to 48 hours before"></textarea>
                </div>
                <button class="p-btn primary xs" onclick="kbSaveBrandVoice()">Save company tone</button>
            </div>

            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>📥 Auto-import from a link or file</h3><span class="p-card-sub">Paste a page link from your site or upload a PDF brochure; AI extracts the facts and you pick what to save</span></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:2;min-width:220px;margin:0;">
                        <label class="form-label">Page link</label>
                        <input type="url" id="kbImpUrl" class="form-control" dir="ltr" placeholder="https://yoursite.com/about">
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;margin:0;">
                        <label class="form-label">Or a file (PDF / TXT)</label>
                        <input type="file" id="kbImpFile" class="form-control" accept=".pdf,.txt,application/pdf,text/plain">
                    </div>
                    <div class="form-group" style="flex:0 0 120px;margin:0;">
                        <label class="form-label">Language</label>
                        <select id="kbImpLang" class="form-control"><option value="en">English</option><option value="ar">Arabic</option><option value="fr">French</option><option value="de">German</option><option value="es">Spanish</option></select><label><input id="kbUseOcr" type="checkbox"> Local OCR for scanned PDFs (first six pages)</label>
                    </div>
                    <button class="p-btn primary" id="kbImpBtn" onclick="kbImport()">✨ Extract information</button>
                </div>
                <div id="kbImpResult" style="display:none;margin-top:14px;"></div>
            </div>

            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>➕ Add new information</h3></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Section</label>
                        <select id="kbSection" class="form-control">
                            <option value="company_info">Company information</option>
                            <option value="service">Service</option>
                            <option value="tour">Trip/tour</option>
                            <option value="destination">Destination</option>
                            <option value="pricing">Price</option>
                            <option value="faq">FAQ</option>
                            <option value="policy">Policy</option>
                            <option value="cancellation_policy">Cancellation policy</option>
                            <option value="contact_info">Contact details</option>
                            <option value="business_hours">Working hours</option>
                            <option value="custom_instructions">Custom instructions</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Language</label>
                        <select id="kbLanguage" class="form-control">
                            <option value="en">English</option>
                            <option value="ar">Arabic</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">Priority (for reply ordering)</label>
                        <select id="kbPriority" class="form-control">
                            <option value="0">Normal (0)</option>
                            <option value="1">High (1)</option>
                            <option value="2">Top priority (2)</option>
                            <option value="-1">Low (-1)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Title (optional, example: trip name or question text)</label>
                    <input type="text" id="kbTitle" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Content</label>
                    <textarea id="kbContent" class="form-control" rows="3" placeholder="Write the information completely and clearly - the AI will rely on this text literally"></textarea>
                </div>
                <button class="p-btn primary" onclick="kbAddEntry()">➕ Add</button>
            </div>

            <div id="kbSectionsContainer"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const SECTION_LABELS = {
        company_info: 'Company information', service: 'Services', tour: 'Trips/tours',
        destination: 'Destinations', pricing: 'Prices', faq: 'Frequently asked questions',
        policy: 'Policies', cancellation_policy: 'Cancellation policy',
        contact_info: 'Contact details', business_hours: 'Working hours',
        custom_instructions: 'Custom instructions',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('kbNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('kbBody').style.display = id ? 'block' : 'none';
        return id;
    }

    let kbImpEntries = [], kbImpLang = 'ar';
    window.kbImport = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const url = document.getElementById('kbImpUrl').value.trim();
        const fileEl = document.getElementById('kbImpFile');
        const file = fileEl.files && fileEl.files[0];
        if (!url && !file) { toast('Add a link or choose a file', 'error'); return; }
        const btn = document.getElementById('kbImpBtn');
        const box = document.getElementById('kbImpResult');
        btn.disabled = true; btn.textContent = '⏳ Reading and extracting... (may take a minute)';
        const fd = new FormData();
        if (file) fd.append('file', file); else fd.append('url', url);
        fd.append('language', document.getElementById('kbImpLang').value);
        fd.append('use_ocr',document.getElementById('kbUseOcr').checked?'true':'false');
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/import', { method: 'POST', body: fd });
        btn.disabled = false; btn.textContent = '✨ Extract information';
        if (!res.success) { toast(res.error || 'Could not import', 'error'); return; }
        kbImpEntries = res.data.entries || [];
        kbImpLang = res.data.language || 'ar';
        box.style.display = 'block';
        box.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">'
            + '<b>' + esc(res.message || '') + '</b><div style="flex:1"></div>'
            + '<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" id="kbImpAll" checked onchange="kbImpToggleAll(this.checked)"> Select all</label>'
            + '<button class="p-btn primary xs" onclick="kbImportSave()">💾 Save selected</button></div>'
            + kbImpEntries.map(function (e, n) {
                return '<div class="p-card" style="padding:10px 12px;margin-bottom:8px;">'
                    + '<label style="display:flex;gap:8px;align-items:flex-start;">'
                    + '<input type="checkbox" class="kbImpChk" data-n="' + n + '" checked style="margin-top:4px;">'
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">'
                    + '<span class="pill blue">' + esc(SECTION_LABELS[e.section] || e.section) + '</span>'
                    + '<input class="form-control kbImpTitle" data-n="' + n + '" value="' + esc(e.title || '') + '" style="flex:1;min-width:160px;padding:4px 8px;">'
                    + '</div>'
                    + '<textarea class="form-control kbImpContent" data-n="' + n + '" rows="3" style="width:100%;">' + esc(e.content) + '</textarea>'
                    + '</div></label></div>';
            }).join('');
    };
    window.kbImpToggleAll = function (on) { document.querySelectorAll('.kbImpChk').forEach(function (c) { c.checked = on; }); };
    window.kbImportSave = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const picked = [];
        document.querySelectorAll('.kbImpChk').forEach(function (c) {
            if (!c.checked) return;
            const n = c.dataset.n;
            picked.push({
                section: kbImpEntries[n].section,
                title: document.querySelector('.kbImpTitle[data-n="' + n + '"]').value.trim(),
                content: document.querySelector('.kbImpContent[data-n="' + n + '"]').value.trim(),
            });
        });
        if (!picked.length) { toast('Pick at least one item', 'error'); return; }
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/import/save', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ entries: picked, language: kbImpLang }),
        });
        if (!res.success) { toast(res.error || 'Could not save', 'error'); return; }
        toast(res.message, 'success');
        document.getElementById('kbImpResult').style.display = 'none';
        document.getElementById('kbImpUrl').value = '';
        document.getElementById('kbImpFile').value = '';
        load();
    };

    // «جرّب الشات»: نفس محرك الرد على السياح، من غير ما يتسجل أي حاجة
    let pgHistory = [];
    const pgAr = (document.documentElement.lang || '').startsWith('ar');
    const pgT = (ar, en) => pgAr ? ar : en;
    const pgReasons = pgAr
        ? { customer_requested_human: 'السائح طلب يكلّم حد', complaint: 'دي شكوى', payment_issue: 'الموضوع عن الدفع', sensitive_request: 'طلب حساس', outside_knowledge_base: 'الإجابة مش موجودة في قاعدة معرفتك', high_value_lead: 'عميل مهم', low_ai_confidence: 'المساعد مكانش متأكد من الإجابة' }
        : { customer_requested_human: 'the traveller asked for a person', complaint: 'it is a complaint', payment_issue: 'it is about payment', sensitive_request: 'sensitive request', outside_knowledge_base: 'the answer is not in your knowledge base', high_value_lead: 'high-value lead', low_ai_confidence: 'the assistant was not sure of the answer' };
    function pgBubble(role, html) {
        const log = document.getElementById('pgLog');
        const d = document.createElement('div');
        d.style.cssText = 'max-width:85%;padding:9px 12px;border-radius:12px;line-height:1.7;font-size:13.5px;white-space:pre-wrap;' + (role === 'user' ? 'align-self:flex-end;background:var(--panel-accent,#2563eb);color:#fff;' : 'align-self:flex-start;background:var(--panel-bg,#f3f4f6);');
        d.setAttribute('dir', 'auto');
        d.innerHTML = html;
        log.appendChild(d); log.scrollTop = log.scrollHeight;
        return d;
    }
    window.pgReset = function () { pgHistory = []; document.getElementById('pgLog').innerHTML = ''; };
    window.pgSend = async function (preset) {
        const id = ensureWebsite(); if (!id) return;
        const input = document.getElementById('pgInput');
        const text = (preset || input.value).trim(); if (!text) return;
        input.value = '';
        pgBubble('user', esc(text));
        const wait = pgBubble('assistant', '⏳');
        const btn = document.getElementById('pgBtn'); btn.disabled = true;
        try {
            const r = await fetchJSON('/api/ai-chat/websites/' + id + '/playground', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ message: text, history: pgHistory }) });
            if (!r.success) { wait.innerHTML = '⚠️ ' + esc(r.error || pgT('تعذّر', 'Failed')); return; }
            const d = r.data;
            let html = d.reply ? esc(d.reply) : '<i>' + pgT('(مفيش رد تلقائي)', '(no automatic reply)') + '</i>';
            const notes = [];
            if (d.handoff) notes.push('👤 ' + pgT('في الشات الحقيقي المحادثة دي هتتحوّل لفريقك لأن ', 'In a real chat this goes to your team because ') + esc(pgReasons[d.handoff_reason] || pgT('المساعد محتاج حد من الفريق', 'the assistant needs a person')) + '.');
            if (!d.knowledge_used) notes.push('📚 ' + pgT('المساعد مالقاش أي معلومات عن شركتك - ضيف رحلاتك وأسعارها من «الحجوزات ← رحلاتي» (بتوصل للمساعد لوحدها)، وسياساتك وأسئلتك المتكررة هنا تحت.', 'The assistant found no information about your company - add your trips and prices in Bookings → My trips (they reach the assistant automatically), and your policies and FAQs below.'));
            if (typeof d.confidence === 'number') notes.push(pgT('درجة الثقة: ', 'Confidence: ') + Math.round(d.confidence * 100) + '%');
            wait.innerHTML = html + (notes.length ? '<div style="margin-top:6px;font-size:12px;opacity:.75;">' + notes.join('<br>') + '</div>' : '');
            pgHistory.push({ role: 'user', content: text });
            if (d.reply) pgHistory.push({ role: 'assistant', content: d.reply });
        } finally { btn.disabled = false; input.focus(); }
    };
    (function () {
        const samples = ['How much is a day trip to Luxor for 2 adults?', 'Was kostet die Schnorcheltour?', 'هل في استلام من الفندق؟', 'Can I cancel and get a refund?'];
        const box = document.getElementById('pgSamples');
        if (box) box.innerHTML = samples.map(q => '<button type="button" class="p-btn ghost xs" dir="auto" data-pg="' + esc(q) + '">' + esc(q) + '</button>').join('');
        if (box) box.addEventListener('click', e => { const b = e.target.closest('[data-pg]'); if (b) pgSend(b.dataset.pg); });
    })();

    window.kbAddEntry = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const section = document.getElementById('kbSection').value;
        const language = document.getElementById('kbLanguage').value;
        const priority = parseInt(document.getElementById('kbPriority').value, 10) || 0;
        const title = document.getElementById('kbTitle').value.trim();
        const content = document.getElementById('kbContent').value.trim();
        if (!content) { toast('Write the content first', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: section, language: language, priority: priority, title: title || null, content: content }),
        });

        if (res.success) {
            toast('Added', 'success');
            document.getElementById('kbTitle').value = '';
            document.getElementById('kbContent').value = '';
            load();
        } else {
            toast(res.error || 'Add failed', 'error');
        }
    };

    window.kbDeleteEntry = async function (entryId) {
        const id = websiteId();
        if (!confirm('Confirm deleting this information?')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, { method: 'DELETE' });
        if (res.success) { toast('Deleted', 'success'); load(); }
        else { toast(res.error || 'Delete failed', 'error'); }
    };

    window.kbEditEntry = async function (entryId, currentContent, currentPriority) {
        const id = websiteId();
        const content = prompt('Edit the content:', currentContent || '');
        if (content === null) return;
        if (!content.trim()) { toast('Content is required', 'error'); return; }
        const priorityInput = prompt('Priority (0 normal, 1 high, 2 top, -1 low):', String(currentPriority ?? 0));
        if (priorityInput === null) return;
        const priority = parseInt(priorityInput, 10);
        const priorityVal = isNaN(priority) ? 0 : priority;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: content.trim(), priority: priorityVal }),
        });
        if (res.success) { toast('Updated', 'success'); load(); }
        else { toast(res.error || 'Edit failed', 'error'); }
    };

    window.kbSaveBrandVoice = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const tone = document.getElementById('bvTone').value;
        const instructions = document.getElementById('bvInstructions').value.trim();

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: 'brand_voice', tone: tone, content: instructions || null }),
        });

        if (res.success) { toast('Company tone saved', 'success'); }
        else { toast(res.error || 'Save failed', 'error'); }
    };

    window.kbPreview = async function () {
        const id = websiteId();
        if (!id) { toast('Choose a website first', 'error'); return; }
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/preview');
        if (!res.success) { toast(res.error || 'Preview failed', 'error'); return; }
        alert(res.data.context_preview || 'No content yet');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base');
        const container = document.getElementById('kbSectionsContainer');

        if (!res.success) {
            container.innerHTML = '<div class="p-card"><div class="p-empty">⚠️ ' + esc(res.error || 'Could not load') + '</div></div>';
            return;
        }

        if (res.data.brand_voice) {
            document.getElementById('bvTone').value = res.data.brand_voice.tone || 'professional';
            document.getElementById('bvInstructions').value = res.data.brand_voice.custom_instructions || '';
        }

        const sections = res.data.sections || {};
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.length) {
            container.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">📚</div>No information has been added to the knowledge base yet. Add the first item from the form above - without it the AI will not be able to answer any specific question about your company.</div></div>';
            return;
        }

        container.innerHTML = sectionKeys.map(section => {
            const entries = sections[section];
            const rows = entries.map(e => `
                <div class="p-kv" style="align-items:flex-start;">
                    <span class="k" style="max-width:70%;">
                        ${e.title ? '<strong>' + esc(e.title) + '</strong><br>' : ''}
                        ${esc(e.content || '')}
                        <span class="p-cell-muted"> · ${e.language === 'en' ? 'EN' : 'AR'}</span>
                        ${e.priority ? '<span class="pill blue">Priority ' + e.priority + '</span>' : ''}
                    </span>
                    <span style="display:flex;gap:6px;">
                        <button class="p-btn outline xs" data-content="${esc(e.content || '')}" onclick="kbEditEntry(${Number(e.id)}, this.dataset.content, ${Number(e.priority) || 0})">Edit</button>
                        <button class="p-btn danger xs" onclick="kbDeleteEntry(${e.id})">Delete</button>
                    </span>
                </div>`).join('');
            return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head"><h3>${SECTION_LABELS[section] || esc(section)}</h3><span class="p-card-sub">${entries.length} item</span></div>
                    ${rows}
                </div>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_knowledge', 'Knowledge base', 'The information the AI relies on when replying to your customers', $this->applyChatUi($body), $script);
        exit;
    }

    /**
     * GET /chat/followup-settings
     * واجهة إعدادات المتابعة التلقائية (بند 7) - تستخدم Endpoint موجود
     * من المرحلة 3 (AiFollowupSettingsController) - صفر Endpoint جديد.
     */
    public function showFollowupSettings(array $params = []): array
    {
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}Inbox</a>
        </div>
        <div id="fuNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>Choose a website from the list at the top of the page first.</div>
        </div>
        <div id="fuBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>⏰ Auto follow-up</h3><span class="p-card-sub">If a customer asked and then disappeared, the system can send them an automatic follow-up based on the steps below</span></div>
                <div class="form-group">
                    <label class="form-label"><input type="checkbox" id="fuEnabled"> Enable auto follow-up for this website</label>
                </div>
                <div class="form-group">
                    <label class="form-label">Maximum number of follow-ups per customer</label>
                    <input type="number" id="fuMax" class="form-control" min="1" max="10" style="max-width:120px;">
                </div>
            </div>
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>Follow-up steps</h3></div>
                <div id="fuSteps"></div>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="fuAddStep()">➕ Add step</button>
            </div>
            <button class="p-btn primary" onclick="fuSave()">💾 Save settings</button>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let steps = [];

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('fuNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('fuBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function renderSteps() {
        const container = document.getElementById('fuSteps');
        if (!steps.length) {
            container.innerHTML = '<div class="p-cell-muted">No steps yet - add a step for auto follow-up to work.</div>';
            return;
        }
        container.innerHTML = steps.map((s, i) => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:12px;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <strong>Step ${i + 1}</strong>
                    <label class="form-label" style="margin:0;">After</label>
                    <input type="number" class="form-control" style="max-width:90px;" value="${s.after_hours}" onchange="fuUpdateStep(${i}, 'after_hours', this.value)">
                    <span class="p-cell-muted">hours after the last customer message</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn danger xs" onclick="fuRemoveStep(${i})">Delete</button>
                </div>
                <div class="form-group" style="margin-top:8px;margin-bottom:0;">
                    <label class="form-label">Message text (use {name} for the customer name)</label>
                    <textarea class="form-control" rows="2" onchange="fuUpdateStep(${i}, 'template', this.value)">${esc(s.template || '')}</textarea>
                </div>
            </div>`).join('');
    }

    window.fuAddStep = function () {
        steps.push({ after_hours: 24, template: 'Hello {name}, just a reminder - are you still interested?' });
        renderSteps();
    };
    window.fuRemoveStep = function (i) { steps.splice(i, 1); renderSteps(); };
    window.fuUpdateStep = function (i, field, value) {
        steps[i][field] = field === 'after_hours' ? parseFloat(value) : value;
    };

    window.fuSave = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/followup-settings', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                is_enabled: document.getElementById('fuEnabled').checked,
                max_followups: parseInt(document.getElementById('fuMax').value, 10) || 3,
                steps: steps,
            }),
        });
        if (res.success) toast('Settings saved', 'success');
        else toast(res.error || 'Save failed', 'error');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/followup-settings');
        if (!res.success) { toast(res.error || 'Could not load', 'error'); return; }
        const s = res.data.settings || {};
        document.getElementById('fuEnabled').checked = !!s.is_enabled;
        document.getElementById('fuMax').value = s.max_followups || 3;
        steps = Array.isArray(s.steps) ? s.steps : [];
        renderSteps();
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_followup', 'Auto follow-up', 'Automatic message settings for customers who did not reply', $this->applyChatUi($body), $script);
        exit;
    }

    /**
     * GET /chat/analytics
     * لوحة تحليلات AI Chat (بند 18) - تستخدم Endpoint موجود من المرحلة 4
     * (AiAnalyticsController) - صفر Endpoint جديد.
     */
    public function showAnalytics(array $params = []): array
    {
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}Inbox</a>
            <div style="flex:1;"></div>
            <select id="anSince" class="p-select" onchange="load()">
                <option value="7">Last 7 days</option>
                <option value="30" selected>Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
        <div id="anNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">{IC_GLOBE}</div>Choose a website from the list at the top of the page first.</div>
        </div>
        <div id="anBody" style="display:none;">

            <div id="anStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_CHART} Conversation distribution</h3></div>
                    <div style="padding:6px 4px;"><canvas id="anConvChart" height="120"></canvas></div>
                </div>
                <div class="p-card an-admin-only" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_SPARKLES} AI provider health</h3></div>
                    <div id="anHealth" style="padding:4px 2px;"></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_TAG} Most frequent tags</h3></div>
                    <div id="anTags"></div>
                </div>
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_TARGET} Most requested services</h3></div>
                    <div id="anServices"></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_SPARKLES} Learning loop: conversation outcomes</h3></div>
                    <div id="anLearning"></div>
                </div>
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_HANDOFF} Handoff reasons</h3></div>
                    <div id="anEscalations"></div>
                </div>
            </div>

            <div class="p-card an-admin-only">
                <div class="p-card-head"><h3>{IC_CHART} AI provider usage (last 24 hours)</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="anProviders">
                    <thead><tr><th>Provider</th><th>Model</th><th>Requests</th><th>Successful</th><th>Failed</th><th>Fallback</th><th>Tokens</th><th>Estimated cost</th></tr></thead>
                    <tbody></tbody>
                </table></div>
            </div>

        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    let convChart = null;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('anNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('anBody').style.display = id ? 'block' : 'none';
        return id;
    }

    function statTile(label, value) {
        return `<div class="p-card" style="text-align:center;padding:16px;">
            <div style="font-size:22px;font-weight:800;">${value}</div>
            <div class="p-cell-muted" style="font-size:11.5px;">${label}</div>
        </div>`;
    }

    function healthPill(provider, configured, status24h) {
        if (!configured) return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v"><span class="pill gray">Not configured</span></span></div>';
        let pill;
        if (status24h === 'healthy') pill = '<span class="pill green">' + ic('check','ic-sm') + ' Healthy</span>';
        else if (status24h === 'degraded') pill = '<span class="pill red">' + ic('alert','ic-sm') + ' Degraded</span>';
        else pill = '<span class="pill">No data yet</span>';
        return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v">' + pill + '</span></div>';
    }

    function renderHealth(health) {
        const box = document.getElementById('anHealth');
        if (!health || !Array.isArray(health.providers)) {
            box.innerHTML = '<div class="p-cell-muted">No health data available</div>';
            return;
        }
        const per = {};
        (health.summary_last_24h && health.summary_last_24h.per_provider || []).forEach(p => { per[p.provider] = p; });

        let html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
        if (health.status === 'healthy') html += '<span class="pill green">✓ Overall status: healthy</span>';
        else if (health.status === 'degraded') html += '<span class="pill red">⚠ Overall status: degraded (some requests failed)</span>';
        else html += '<span class="pill">Overall status: no usage recorded yet</span>';
        html += '</div>';

        health.providers.forEach(p => {
            const s = per[p.provider];
            const status24h = s ? (s.failed_requests > 0 ? 'degraded' : 'healthy') : 'no_data';
            html += healthPill(p.provider, !!p.configured, status24h);
            if (p.configured) {
                html += '<div class="p-kv"><span class="k">Model</span><span class="v">' + esc(p.model || '-') + '</span></div>';
                html += '<div class="p-kv"><span class="k">Preference order</span><span class="v">#' + (p.priority_position || '-') + '</span></div>';
                if (s) {
                    html += '<div class="p-kv"><span class="k">Requests (24h)</span><span class="v">' + s.total_requests + ' · ' + s.failed_requests + ' Failed</span></div>';
                }
            }
        });

        const s = health.summary_last_24h;
        if (s && s.total_requests > 0) {
            html += '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;">'
                + '<strong>Total last 24 hours:</strong> ' + s.total_requests + ' Request · '
                + s.failed_requests + ' Failed · ' + (s.fallback_used_count || 0) + ' Fallback · '
                + (s.total_tokens || 0).toLocaleString() + ' token · $' + parseFloat(s.total_cost_usd || 0).toFixed(4)
                + '</div>';
        }
        box.innerHTML = html;
    }

    function renderLearning(learning) {
        const box = document.getElementById('anLearning');
        if (!learning) { box.innerHTML = '<div class="p-cell-muted">No learning loop data yet</div>'; return; }

        const breakdown = learning.resolution_events || {};
        const total = Object.values(breakdown).reduce((a, b) => a + b, 0);
        let html = '<div class="p-kv"><span class="k">AI resolution rate</span><span class="v"><strong>' + (learning.ai_resolution_rate_percent || 0) + '%</strong></span></div>';
        html += '<div class="p-kv"><span class="k">Recorded events</span><span class="v">' + total + '</span></div>';
        if (total > 0) {
            const labels = {
                ai_resolved: 'Resolved by AI',
                human_resolved: 'Resolved by an agent',
                abandoned: 'Customer left',
                reopened: 'Reopened',
            };
            const keys = Object.keys(labels);
            html += '<div style="display:flex;flex-direction:column;gap:6px;margin-top:8px;">';
            keys.forEach(k => {
                const n = breakdown[k] || 0;
                if (n <= 0) return;
                const pct = Math.round((n / total) * 100);
                html += '<div style="display:flex;align-items:center;gap:8px;">'
                    + '<span style="width:130px;font-size:12px;">' + labels[k] + '</span>'
                    + '<div style="flex:1;background:var(--panel-sidebar-bg-hover);border-radius:6px;height:8px;overflow:hidden;">'
                    + '<div style="width:' + pct + '%;height:100%;background:var(--panel-teal);border-radius:6px;"></div></div>'
                    + '<span style="width:44px;text-align:left;font-size:12px;">' + n + '</span></div>';
            });
            html += '</div>';
        }
        const gaps = learning.knowledge_gaps || [];
        if (gaps.length) {
            html += '<div style="margin-top:10px;"><strong>Top knowledge gaps:</strong></div>';
            html += gaps.slice(0, 3).map(g =>
                '<div class="p-kv"><span class="k">' + esc(g.question || g.normalized_question || '-') + '</span><span class="v">×' + (g.occurrence_count || 1) + '</span></div>'
            ).join('');
            html += '<a href="/chat/learning" class="p-btn outline xs" style="margin-top:8px;">Review all gaps</a>';
        }
        box.innerHTML = html;
    }

    function renderEscalations(escalations) {
        const box = document.getElementById('anEscalations');
        const reasons = escalations || {};
        const keys = Object.keys(reasons);
        box.innerHTML = keys.length
            ? keys.map(r => {
                let label = r;
                const map = {
                    outside_knowledge_base: 'Outside the knowledge base',
                    low_ai_confidence: 'Low AI confidence',
                    ai_requested_handoff: 'AI requested handoff',
                    manual_takeover: 'Manual intervention',
                    customer_escalated: 'Customer request',
                    'no_provider_configured': 'No provider configured',
                };
                label = map[r] || r;
                return '<div class="p-kv"><span class="k">' + esc(label) + '</span><span class="v">' + reasons[r] + '</span></div>';
            }).join('')
            : '<div class="p-cell-muted">No handoff reasons recorded</div>';
    }

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('anSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/analytics?since=' + since);
        if (!res.success) {
            document.getElementById('anStats').innerHTML = '<div class="p-cell-muted">⚠️ ' + esc(res.error || 'Could not load') + '</div>';
            return;
        }

        const d = res.data.dashboard;
        document.getElementById('anStats').innerHTML = [
            statTile('Total conversations', d.total_conversations),
            statTile('AI reply', d.ai_conversations),
            statTile('Hand off to agent', d.human_conversations),
            statTile('Leads New', d.leads_generated),
            statTile('Leads Hot 🔥', d.hot_leads),
            statTile('Conversion rate', d.conversion_rate_percent + '%'),
            statTile('AI resolution rate', d.ai_resolution_rate_percent + '%'),
            statTile('Handoff rate', d.human_handoff_rate_percent + '%'),
            statTile('Follow-up success', d.followup_success_rate_percent + '%'),
        ].join('');

        const tags = d.top_tags || {};
        const tagKeys = Object.keys(tags);
        document.getElementById('anTags').innerHTML = tagKeys.length
            ? tagKeys.map(t => `<div class="p-kv"><span class="k">${esc(({HOT_LEAD: '🔥 Hot lead', NEW_INQUIRY: 'New inquiry', PRICE_REQUEST: 'Price request', COMPLAINT: 'Complaint', FOLLOW_UP: 'Needs follow-up', BOOKING_INTENT: 'Wants to book', HUMAN_REQUIRED: 'Needs a human'})[t] || t)}</span><span class="v">${tags[t]}</span></div>`).join('')
            : '<div class="p-cell-muted">Not enough data yet</div>';

        const services = d.most_popular_services || {};
        const serviceKeys = Object.keys(services);
        document.getElementById('anServices').innerHTML = serviceKeys.length
            ? serviceKeys.map(s => `<div class="p-kv"><span class="k">${esc(s)}</span><span class="v">${services[s]}</span></div>`).join('')
            : '<div class="p-cell-muted">Not enough data yet</div>';

        if (typeof Chart !== 'undefined') {
            const conv = { ai: d.ai_conversations || 0, human: d.human_conversations || 0 };
            if (convChart) convChart.destroy();
            convChart = new Chart(document.getElementById('anConvChart'), {
                type: 'doughnut',
                data: {
                    labels: ['AI reply', 'Hand off to agent'],
                    datasets: [{
                        data: [conv.ai, conv.human],
                        backgroundColor: ['#4ECDC4', '#EFB05E'],
                        borderColor: '#0F1A2C',
                        borderWidth: 2,
                    }],
                },
                options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { color: '#8996AC' } } } },
            });
        }

        renderHealth(res.data.provider_health);
        renderLearning(res.data.learning_loop);
        renderEscalations(res.data.learning_loop ? res.data.learning_loop.escalation_reasons : {});

        const providers = d.ai_usage_by_provider || [];
        const tbody = document.querySelector('#anProviders tbody');
        tbody.innerHTML = providers.length
            ? providers.map(p => `<tr>
                <td>${esc(p.provider)}</td>
                <td class="p-cell-muted">${esc(p.model || '-')}</td>
                <td>${p.total_requests}</td>
                <td>${p.total_requests - (p.failed_requests || 0)}</td>
                <td>${p.failed_requests || 0}</td>
                <td>${p.fallback_used_count || 0}</td>
                <td>${(p.total_tokens || 0).toLocaleString()}</td>
                <td>$${parseFloat(p.total_cost_usd || 0).toFixed(4)}</td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="p-cell-muted text-center">No usage recorded yet</td></tr>';
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        if (!in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true)) {
            $body = '<style>.an-admin-only{display:none!important}</style>' . $body;
        }
        echo $this->renderPanelPage('ai_chat_analytics', 'Chat performance', 'How the AI handles your customers and what it is learning', $this->applyChatUi($body), $script);
        exit;
    }
    /**
     * GET /chat/learning
     * حلقة التعلّم (Learning Loop): مراجعة فجوات المعرفة اللي الـAI
     * مش قادر يرد عليها، إعادة مسح المحادثات المحوّلة، وتحديث حالة
     * كل فجوة (تمت الملاحظة / أُضيفت لقاعدة المعرفة / تجاهل).
     * تستخدم Endpoints موجودة (AiLearningController) - صفر Endpoint جديد.
     */
    public function showLearning(array $params = []): array
    {
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}Inbox</a>
            <div style="flex:1;"></div>
            <button class="p-btn outline xs" onclick="lnScan()">{IC_REFRESH}Rescan gaps</button>
            <select id="lnSince" class="p-select" onchange="load()">
                <option value="7">Last 7 days</option>
                <option value="30" selected>Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>
        <div id="lnNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>Choose a website from the list at the top of the page first.</div>
        </div>
        <div id="lnBody" style="display:none;">
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 300px;min-width:240px;text-align:center;">
                    <div style="font-size:30px;font-weight:800;font-family:var(--font-mono-num);color:var(--panel-teal);" id="lnResRate">-</div>
                    <div class="p-cell-muted">AI resolution rate</div>
                </div>
                <div class="p-card" style="flex:1 1 300px;min-width:240px;text-align:center;">
                    <div style="font-size:30px;font-weight:800;font-family:var(--font-mono-num);color:var(--panel-accent);" id="lnGapCount">-</div>
                    <div class="p-cell-muted">Unhandled knowledge gaps</div>
                </div>
                <div class="p-card" style="flex:1 1 100%;min-width:240px;">
                    <div class="p-card-head"><h3>ℹ️ How does the learning loop work?</h3></div>
                    <div class="p-cell-muted" style="font-size:13px;line-height:1.8;">
                        When the AI hands a conversation to your team because the question is not in the knowledge base or it is not sure, the question is saved as a knowledge gap. Add the answer to the knowledge base and the AI will answer it next time. This is how replies get better every week.
                    </div>
                </div>
            </div>

            <div class="p-card no-pad">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>🧠 Knowledge gaps</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="lnTable">
                    <thead><tr>
                        <th>Question</th><th>Language</th><th>Handoff reason</th><th>Count</th><th>Status</th><th>Last seen</th><th></th>
                    </tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="7">Loading...</td></tr></tbody>
                </table></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_PILL = {
        new: '<span class="pill red">New</span>',
        acknowledged: '<span class="pill">Noted</span>',
        added_to_kb: '<span class="pill green">Added to knowledge</span>',
        dismissed: '<span class="pill gray">Ignored</span>',
    };
    const REASON_LABEL = {
        outside_knowledge_base: 'Outside the knowledge base',
        low_ai_confidence: 'Low AI confidence',
        ai_requested_handoff: 'AI requested handoff',
        manual_takeover: 'Manual intervention',
        customer_escalated: 'Customer request',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('lnNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('lnBody').style.display = id ? 'block' : 'none';
        return id;
    }

    window.lnScan = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/scan', { method: 'POST' });
        if (res.success) {
            toast('Cleared: ' + (res.data && res.data.new_gaps_recorded != null ? res.data.new_gaps_recorded + ' New gap' : 'No new gaps'), 'success');
            load();
        } else {
            toast(res.error || 'Clear failed', 'error');
        }
    };

    window.lnSetStatus = async function (gapId, status) {
        const id = websiteId();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/' + gapId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) { toast('Gap status updated', 'success'); load(); }
        else { toast(res.error || 'Update failed', 'error'); }
    };

    window.lnAddToKb = async function (gapId, question) {
        const id = websiteId();
        if (!id) return;
        const content = prompt('Write the gap answer to be added to the knowledge base:', '');
        if (content === null) return;
        if (!content.trim()) { toast('The answer is required', 'error'); return; }

        const addRes = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                section: 'faq',
                title: question,
                content: content.trim(),
                language: 'en',
                priority: 1,
            }),
        });
        if (!addRes.success) { toast(addRes.error || 'Add failed', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/' + gapId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: 'added_to_kb' }),
        });
        if (res.success) { toast('The answer was added to the knowledge base and the gap closed', 'success'); load(); }
        else { toast('Added to knowledge but failed to update the status', 'error'); load(); }
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('lnSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps?since=' + since);
        if (!res.success) {
            document.querySelector('#lnTable tbody').innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'Could not load') + '</td></tr>';
            return;
        }

        const gaps = (res.data && Array.isArray(res.data.knowledge_gaps)) ? res.data.knowledge_gaps : [];
        const summary = (res.data && res.data.summary) || {};

        document.getElementById('lnResRate').textContent = (summary.ai_resolution_rate_percent != null ? summary.ai_resolution_rate_percent : '-') + '%';
        const unresolved = gaps.filter(g => g.status === 'new' || g.status === 'acknowledged').length;
        document.getElementById('lnGapCount').textContent = unresolved || 0;

        const tbody = document.querySelector('#lnTable tbody');
        if (!gaps.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">No knowledge gaps in this period - the AI answers all questions 🎉</td></tr>';
            return;
        }

        tbody.innerHTML = gaps.map(g => {
            const actions = [];
            if (g.status === 'new' || g.status === 'acknowledged') {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'acknowledged\')">👁 Note</button>');
                actions.push('<button class="p-btn primary xs" onclick="lnAddToKb(' + g.id + ', \'' + esc(String(g.question || '')).replace(/'/g, "\\'") + '\')">📚 Add to knowledge</button>');
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'dismissed\')">✖ Ignore</button>');
            } else {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'new\')">↺ Reopen</button>');
            }
            return `<tr>
                <td><div style="max-width:340px;">${esc(g.question || g.normalized_question || '-')}</div></td>
                <td><span class="pill gray">${esc(g.language === 'ar' ? 'Arabic' : (g.language || '-'))}</span></td>
                <td class="p-cell-muted">${esc(REASON_LABEL[g.handoff_reason] || g.handoff_reason || '-')}</td>
                <td><strong>×${g.occurrence_count || 1}</strong></td>
                <td>${STATUS_PILL[g.status] || esc(g.status || '-')}</td>
                <td class="p-cell-muted">${P.formatDate(g.last_seen_at)}</td>
                <td style="white-space:nowrap;">${actions.join(' ')}</td>
            </tr>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_learning', 'Learning and knowledge gaps', 'AI learning loop: spot the gaps and teach the system to reply better next time', $this->applyChatUi($body), $script);
        exit;
    }
    /**
     * GET /chat/leads
     * قائمة Leads مستقلة (بند 5، 6) - مرتّبة حسب Lead Score، قابلة
     * للفلترة بالحالة. تستخدم Endpoint موجود من المرحلة 3
     * (AiLeadController) - صفر Endpoint جديد.
     */
    public function showLeads(array $params = []): array
    {
        $body = <<<'HTML'
        {ICON_SPRITE}
        {CHAT_UI_CSS}
        <div class="p-toolbar">
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}Inbox</a>
            <div style="flex:1;"></div>
            <select id="ldStatus" class="p-select" onchange="load()">
                <option value="">All statuses</option>
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="qualified">Qualified</option>
                <option value="proposal_sent">Quote sent</option>
                <option value="won">Won</option>
                <option value="lost">Lost</option>
            </select>
        </div>
        <div id="ldNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>Choose a website from the list at the top of the page first.</div>
        </div>
        <div class="p-card no-pad" id="ldTableWrap">
            <div class="p-table-scroll"><table class="p-table" id="leadsTable">
                <thead><tr>
                    <th>Customer</th><th>Channel</th><th>Interest</th><th>Destination</th>
                    <th>Lead Score</th><th>Intent</th><th>Status</th><th>Last interaction</th><th></th>
                </tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="9">Loading...</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_OPTIONS = [
        ['new', 'New'], ['contacted', 'Contacted'], ['qualified', 'Qualified'],
        ['proposal_sent', 'Quote sent'], ['won', 'Won'], ['lost', 'Lost'],
    ];

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('ldNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('ldTableWrap').style.display = id ? 'block' : 'none';
        return id;
    }

    window.ldUpdateStatus = async function (leadId, status) {
        const id = websiteId();
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads/' + leadId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) toast('Status updated', 'success');
        else { toast(res.error || 'Update failed', 'error'); load(); }
    };

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;

        const status = document.getElementById('ldStatus').value;
        const qs = status ? ('?status=' + encodeURIComponent(status)) : '';

        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="9">Loading...</td></tr>';

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads' + qs);
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'Could not load') + '</td></tr>';
            return;
        }

        const leads = res.data.leads || [];
        if (!leads.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">No leads yet</td></tr>';
            return;
        }

        tbody.innerHTML = leads.map(l => {
            const statusSelect = '<select class="p-select" style="min-width:130px;" onchange="ldUpdateStatus(' + l.id + ', this.value)">' +
                STATUS_OPTIONS.map(([v, label]) => `<option value="${v}" ${l.status === v ? 'selected' : ''}>${label}</option>`).join('') + '</select>';
            return `
                <tr>
                    <td>${esc(l.name || l.phone || 'Unknown')}</td>
                    <td>${esc(l.channel || '-')}</td>
                    <td>${esc(l.interest || '-')}</td>
                    <td>${esc(l.destination || '-')}</td>
                    <td><span class="pill ${l.lead_score >= 70 ? 'red' : l.lead_score >= 40 ? 'blue' : 'gray'}">${l.lead_score ?? '-'}</span></td>
                    <td>${l.intent_score ?? '-'}</td>
                    <td>${statusSelect}</td>
                    <td class="p-cell-muted">${P.timeAgo(l.last_interaction_at)}</td>
                    <td><a href="/chat/conversation/${l.conversation_id}" class="p-btn outline xs">Open conversation</a></td>
                </tr>`;
        }).join('');
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_leads', 'Leads', 'All potential customers sorted by priority', $this->applyChatUi($body), $script);
        exit;
    }

    /** DELETE /api/chat/message/{id} */
    public function deleteMessage(array $params): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $sql = "DELETE FROM chat_messages WHERE id = ? AND user_id = ?";
            $this->db->exec($sql, [(int) ($params['id'] ?? 0), $this->user['id']]);
            return $this->success([], 'Message deleted');
        } catch (Exception $e) {
            Logger::error('Delete Message Error', ['message' => $e->getMessage()]);
            return $this->error('Could not delete the message', 500);
        }
    }

    /**
     * توليد/إعادة توليد رد بالذكاء الاصطناعي لرسالة معلّقة معيّنة.
     * بيستخدم AutoReplyEngine الحقيقي (اللي بيتكلم مع Gemini فعليًا) بدل
     * ما يرجّع 501. مفيد لما العميل عايز "يجرّب رد تاني" قبل ما يوافق.
     * POST /api/chat/generate-reply  { message_id: number }
     */
    public function generateReply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }

        $messageId = (int) $this->get('message_id', 0);
        if (!$messageId) {
            return $this->error('Message ID is required', 422);
        }

        try {
            $message = (new ChatMessage())->find($messageId);
            if (!$message || (int) $message->getAttribute('user_id') !== (int) $this->user['id']) {
                return $this->error('Message not found', 404);
            }

            $engine = new AutoReplyEngine();
            $reply = $engine->generateReply(
                (string) $message->getAttribute('message_text'),
                (int) $this->user['id'],
                ['customer_name' => $message->getAttribute('customer_name')],
                ['auto_pilot' => false]
            );

            if (!$reply) {
                return $this->error('Could not generate a reply - try again in a moment', 502);
            }

            $message->setAttribute('ai_reply_generated', $reply);
            $message->save();

            return $this->success(['reply' => $reply], 'New reply generated');
        } catch (Exception $e) {
            Logger::error('Generate Chat Reply Error', ['message_id' => $messageId, 'message' => $e->getMessage()]);
            return $this->error('Could not generate the reply', 500);
        }
    }

    // ============================================
    // ربط UltraMsg (واتساب) - كل عميل بحسابه الخاص
    // ============================================

    /**
     * توكن سري لكل موقع خاص برابط الـ webhook، عشان محدش يقدر يبعت
     * رسائل واردة مزيّفة لموقع مش بتاعه لو عرف رقم الـ website_id بس.
     */
    private function ultraMsgWebhookSecret(int $websiteId): string
    {
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : '';
        if ($secretKey === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'ultramsg-webhook:' . $websiteId, $secretKey), 0, 24);
    }

    /** GET /api/chat/ultramsg/status?website_id= */
    public function getUltraMsgStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Not logged in', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id Required', 422);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'ultramsg',
            'status' => 'connected',
        ], [], 1);

        $webhookUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/')
            . '/api/chat/webhook/ultramsg/' . $websiteId
            . '?secret=' . $this->ultraMsgWebhookSecret($websiteId);

        return $this->success([
            'connected' => !empty($connections),
            'instance_id' => !empty($connections) ? $connections[0]->getAttribute('external_account_id') : null,
            'webhook_url' => $webhookUrl,
        ]);
    }

    /** POST /api/chat/connect/ultramsg */
    public function connectUltraMsg(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Not logged in', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $instanceId = trim((string) $this->get('instance_id', ''));
        $apiKey = trim((string) $this->get('api_key', ''));

        if (!$websiteId || !$instanceId || !$apiKey) {
            return $this->error('Website, instance ID and token are required', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('Website not found', 404);
        }

        // تحقق فعلي إن البيانات صحيحة قبل الحفظ (بدل ما نحفظ ونكتشف بعدين إنها غلط)
        $api = new UltraMsgAPI($instanceId, $apiKey);
        $statusResult = $api->getInstanceStatus();
        if (!$statusResult['success']) {
            return $this->error('Could not verify the data of UltraMsg: ' . ($statusResult['error'] ?? ''), 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => 'ultramsg',
            ], [], 1);

            $connection = new PlatformConnection([
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => 'ultramsg',
                'access_token' => $encryption->encrypt($apiKey),
                'external_account_id' => $instanceId,
                'external_location_name' => 'UltraMsg Instance ' . $instanceId,
                'status' => 'connected',
                'last_error' => null,
            ]);

            if (!empty($existing)) {
                $connection->setKeyValue($existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log('UltraMsg Connected', ['website_id' => $websiteId, 'instance_id' => $instanceId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/ultramsg/' . $websiteId . '?secret=' . $this->ultraMsgWebhookSecret($websiteId),
            ], 'UltraMsg connected successfully');
        } catch (Exception $e) {
            Logger::error('Connect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('Could not save the connection', 500);
        }
    }

    /** POST /api/chat/disconnect/ultramsg/{website_id} */
    public function disconnectUltraMsg(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Not logged in', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);

        try {
            $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'ultramsg']);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            return $this->success([], 'Disconnected');
        } catch (Exception $e) {
            Logger::error('Disconnect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('Could not disconnect', 500);
        }
    }

    /**
     * POST /api/chat/webhook/ultramsg/{website_id}?secret=...
     * مسار مخصص لكل موقع (مش endpoint عام واحد) عشان نعرف نوجّه الرسالة
     * الواردة لصاحبها الصح فورًا، ونتحقق بـ secret موقّع بدل ما نصدّق أي
     * حد يعرف رقم website_id.
     *
     * ملحوظة: شكل بيانات webhook الحقيقي من UltraMsg اتبنى على أفضل فهم
     * متاح من توثيقهم العام - يُفضّل التأكد منه بإرسال رسالة تجريبية
     * حقيقية بعد الربط والتأكد من اللوج، لأنه معنديش بيئة أختبره فيها فعليًا.
     */
    public function ultraMsgWebhook(array $params = []): array
    {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->ultraMsgWebhookSecret($websiteId), $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $payload = $this->all();
            $data = $payload['data'] ?? $payload;

            // تجاهل رسائلنا إحنا نفسنا (fromMe) عشان منردش على أنفسنا في حلقة
            if (!empty($data['fromMe'])) {
                return $this->success([], 'Ignored (outgoing message)');
            }

            $phone = $data['from'] ?? null;
            $text = $data['body'] ?? null;

            if (!$phone || !$text) {
                return $this->error('Incomplete message data', 422);
            }

            // تنظيف صيغة الرقم القادمة من UltraMsg (بتيجي عادة كـ 201234567890@c.us)
            $phone = preg_replace('/@c\.us$|@g\.us$/', '', $phone);

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => 'ultramsg',
                'phone_number' => $phone,
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error('UltraMsg Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    // ============================================================
    // AI Chat Platform - قنوات إضافية (بند 1): Messenger، Instagram، Email
    //
    // نفس نمط ultraMsgWebhook()/connectUltraMsg() بالضبط: مسار مخصص لكل
    // موقع (secret موقَّع بدل endpoint عام)، website_id/user_id صريحان،
    // وفحص تكرار Webhook (بند 23) عبر جدول ai_webhook_events.
    // ============================================================

    /**
     * @param int $websiteId
     * @param string $platform
     * @return string
     */
    private function channelWebhookSecret(int $websiteId, string $platform): string
    {
        $secretKey = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-secret';
        return substr(hash_hmac('sha256', $platform . '-webhook:' . $websiteId, $secretKey), 0, 24);
    }

    /**
     * فحص وتسجيل حدث Webhook لمنع معالجته مرتين (بند 23). يرجع true لو
     * الحدث جديد (كمّل المعالجة)، أو false لو كان مُعالَجًا بالفعل (تجاهله).
     * @param string $channel
     * @param string $externalEventId
     * @param int|null $websiteId
     * @return bool
     */
    private function isNewWebhookEvent(string $channel, string $externalEventId, ?int $websiteId): bool
    {
        if ($externalEventId === '') {
            return true; // لا معرف فريد نتحقق منه - نكمل المعالجة بدل ما نرفضها بالخطأ
        }

        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO ai_webhook_events (website_id, channel, external_event_id, status, received_at)
                 VALUES (?, ?, ?, 'received', NOW())",
                [$websiteId, $channel, $externalEventId]
            );
            return true;
        } catch (Exception $e) {
            // فشل الإدراج غالبًا بسبب UNIQUE KEY (channel, external_event_id) - يعني الحدث ده اتسجل قبل كده
            Logger::info('Duplicate webhook event ignored', ['channel' => $channel, 'external_event_id' => $externalEventId]);
            return false;
        }
    }

    /** GET /api/chat/meta-pages?website_id= - صفحات فيسبوك المربوطة بتسجيل الدخول ومين بيرد على الرسايل */
    public function metaPages(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Not logged in', 401);
        $wid = (int) $this->get('website_id');
        if (!$this->ownsChatWebsite($wid)) return $this->error('Website not found', 404);
        try {
            return $this->success((new MetaChatPages($this->db))->overview((int) $this->user['id'], $wid));
        } catch (Throwable $e) {
            Logger::error('Meta chat pages failed', ['error' => $e->getMessage()]);
            return $this->error('Could not load your Facebook pages', 503);
        }
    }

    /** POST /api/chat/meta-pages/messaging {website_id, connection_id, enabled} */
    public function metaPageMessaging(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Not logged in', 401);
        if ($e = $this->verifyCsrf()) return $e;
        $wid = (int) $this->get('website_id');
        if (!$this->ownsChatWebsite($wid)) return $this->error('Website not found', 404);
        try {
            $r = (new MetaChatPages($this->db))->setMessaging((int) $this->user['id'], $wid, (int) $this->get('connection_id'), filter_var($this->get('enabled', false), FILTER_VALIDATE_BOOLEAN));
            return $this->success($r, 'Saved');
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Meta chat page toggle failed', ['error' => $e->getMessage()]);
            return $this->error('Could not update the page', 503);
        }
    }

    private function ownsChatWebsite(int $wid): bool
    {
        return $wid > 0 && !empty($this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$wid, (int) $this->user['id']]));
    }

    /** POST /api/chat/connect/messenger */
    public function connectMessenger(array $params = []): array
    {
        return $this->connectMetaChannel('messenger');
    }

    /** POST /api/chat/connect/instagram */
    public function connectInstagram(array $params = []): array
    {
        return $this->connectMetaChannel('instagram');
    }

    /**
     * منطق مشترك لربط Messenger/Instagram - كلاهما يخزّن Page/IG Access
     * Token واحد في PlatformConnection بنفس أسلوب connectUltraMsg().
     * @param string $platform messenger|instagram
     * @return array
     */
    private function connectMetaChannel(string $platform): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Not logged in', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $accessToken = trim((string) $this->get('access_token', ''));
        $externalAccountId = trim((string) $this->get('page_id', ''));

        if (!$websiteId || !$accessToken) {
            return $this->error('Website and access_token are required', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('Website not found', 404);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $websiteId,
                'platform' => $platform,
            ], [], 1);

            $connection = new PlatformConnection([
                'website_id' => $websiteId,
                'user_id' => $this->user['id'],
                'platform' => $platform,
                'access_token' => $encryption->encrypt($accessToken),
                'external_account_id' => $externalAccountId ?: null,
                'external_location_name' => ucfirst($platform) . ' Page',
                'status' => 'connected',
                'last_error' => null,
            ]);

            if (!empty($existing)) {
                $connection->setKeyValue($existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log(ucfirst($platform) . ' Connected', ['website_id' => $websiteId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/' . $platform . '/' . $websiteId
                    . '?secret=' . $this->channelWebhookSecret($websiteId, $platform),
                'verify_token' => $this->channelWebhookSecret($websiteId, $platform),
            ], 'Connected ' . $platform . ' successfully - use webhook_url and verify_token when setting up the Webhook in Meta for Developers');
        } catch (Exception $e) {
            Logger::error('Connect ' . $platform . ' Error', ['message' => $e->getMessage()]);
            return $this->error('Could not save the connection', 500);
        }
    }

    /**
     * تحقّق Meta القياسي لأي Webhook جديد (hub.challenge handshake)، مشترك
     * بين Messenger وInstagram لأن كليهما يستخدمان نفس آلية Meta for Developers.
     * GET /api/chat/webhook/messenger/{website_id}?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
     * GET /api/chat/webhook/instagram/{website_id}?hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
     */
    public function verifyMetaChannelWebhook(array $params = [], string $platform = 'messenger'): array
    {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $mode = $this->get('hub_mode') ?: $this->get('hub.mode');
        $token = $this->get('hub_verify_token') ?: $this->get('hub.verify_token');
        $challenge = $this->get('hub_challenge') ?: $this->get('hub.challenge');

        if ($mode === 'subscribe' && $websiteId && hash_equals($this->channelWebhookSecret($websiteId, $platform), (string) $token)) {
            return ['_raw_text' => (string) $challenge];
        }

        return $this->error('Verification failed', 403);
    }

    /** GET /api/chat/webhook/messenger/{website_id} (Meta verification handshake) */
    public function verifyMessengerWebhook(array $params = []): array
    {
        return $this->verifyMetaChannelWebhook($params, 'messenger');
    }

    /** GET /api/chat/webhook/instagram/{website_id} (Meta verification handshake) */
    public function verifyInstagramWebhook(array $params = []): array
    {
        return $this->verifyMetaChannelWebhook($params, 'instagram');
    }

    /**
     * POST /api/chat/webhook/messenger/{website_id}?secret=...
     * هيكل بيانات Messenger مبني على توثيق Meta Send/Receive API العام -
     * يُفضّل التأكد منه بربط صفحة تجريبية حقيقية بعد الرفع، لأنه لا توجد
     * بيئة اختبار حقيقية متاحة هنا لمزود Meta.
     */
    public function messengerWebhook(array $params = []): array
    {
        return $this->handleMetaChannelWebhook($params, 'messenger');
    }

    /**
     * POST /api/chat/webhook/instagram/{website_id}?secret=...
     * هيكل بيانات Instagram Messaging مطابق تقريبًا لـMessenger عبر نفس
     * Graph API - يُفضّل التأكد منه بربط حساب تجريبي حقيقي بعد الرفع.
     */
    public function instagramWebhook(array $params = []): array
    {
        return $this->handleMetaChannelWebhook($params, 'instagram');
    }

    /**
     * منطق مشترك لاستقبال رسائل Messenger/Instagram (نفس بنية الـPayload
     * تقريبًا في الاثنين لأنهما يمران عبر نفس Meta Graph API).
     * @param array $params
     * @param string $platform messenger|instagram
     * @return array
     */
    private function handleMetaChannelWebhook(array $params, string $platform): array
    {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->channelWebhookSecret($websiteId, $platform), (string) $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $data = $this->all();
            $entry = $data['entry'][0] ?? [];
            $messaging = $entry['messaging'][0] ?? [];
            $message = $messaging['message'] ?? [];
            $sender = $messaging['sender'] ?? [];

            // تجاهل رسائلنا إحنا نفسنا (echo) عشان منردش على أنفسنا في حلقة
            if (!empty($message['is_echo'])) {
                return $this->success([], 'Ignored (outgoing message)');
            }

            $senderId = $sender['id'] ?? null;
            $text = $message['text'] ?? null;
            $externalEventId = $message['mid'] ?? ($messaging['timestamp'] ?? '');

            if (!$senderId || !$text) {
                return $this->success([], 'No text message to process');
            }

            if (!$this->isNewWebhookEvent($platform, (string) $externalEventId, $websiteId)) {
                return $this->success([], 'Duplicate webhook ignored');
            }

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => $platform,
                'phone_number' => $senderId, // معرّف العميل على المنصة (PSID/IGSID) - نفس الحقل يُستخدَم كمعرّف عام لكل القنوات
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error(ucfirst($platform) . ' Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }

    /**
     * POST /api/chat/webhook/email/{website_id}?secret=...
     *
     * بنية Payload عامة موحّدة (from_email, from_name, subject, text,
     * message_id) بدل الالتزام بصيغة مزود بريد وارد معيّن (SendGrid
     * Inbound Parse، Mailgun Routes، إلخ) - لأن الشركة لم تحدد مزودًا
     * بعد. عند اختيار مزود حقيقي، يلزم فقط تعديل استخراج الحقول من
     * $this->all() في بداية الدالة لتطابق صيغته - باقي التدفق
     * (idempotency + processIncomingMessage) يبقى كما هو (بند 1:
     * Integration Architecture كاملة بدون افتراض مزود بعينه).
     */
    public function emailWebhook(array $params = []): array
    {
        $websiteId = (int) ($params['website_id'] ?? 0);
        $secret = $this->get('secret');

        if (!$websiteId || !$secret || !hash_equals($this->channelWebhookSecret($websiteId, 'email'), (string) $secret)) {
            return $this->error('Unauthorized webhook', 401);
        }

        try {
            $website = (new Website())->find($websiteId);
            if (!$website) {
                return $this->error('Website not found', 404);
            }

            $data = $this->all();
            $fromEmail = $data['from_email'] ?? $data['from'] ?? null;
            $fromName = $data['from_name'] ?? null;
            $text = $data['text'] ?? $data['body_text'] ?? $data['body'] ?? null;
            $externalEventId = $data['message_id'] ?? '';

            if (!$fromEmail || !$text) {
                return $this->error('Incomplete message data', 422);
            }

            if (!$this->isNewWebhookEvent('email', (string) $externalEventId, $websiteId)) {
                return $this->success([], 'Duplicate webhook ignored');
            }

            $result = $this->chatManager->processIncomingMessage([
                'user_id' => (int) $website->getAttribute('user_id'),
                'website_id' => $websiteId,
                'platform' => 'email',
                'phone_number' => $fromEmail, // نفس الحقل العام لمعرّف العميل - هنا هو الإيميل
                'email' => $fromEmail,
                'sender_name' => $fromName,
                'message' => $text,
            ]);

            return $result;
        } catch (Exception $e) {
            Logger::error('Email Webhook Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('Webhook processing failed', 500);
        }
    }
}
