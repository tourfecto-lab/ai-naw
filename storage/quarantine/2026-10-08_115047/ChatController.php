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
            $messages = ChatMessage::getPendingApprovals($this->user['id'], $limit);

            return $this->success([
                // تصحيح: كانت بترجع array من ChatMessage objects مباشرة -
                // json_encode بيحوّل أي object لـ "{}" فاضي لأن كل الخصائص
                // protected، يعني الـ frontend كان بيستلم صفوف فاضية تمامًا
                // (مفيش message_text ولا ai_reply_generated ولا أي حاجة).
                'pending' => array_map(fn ($m) => $m->toArray(), $messages),
                'count' => count($messages),
                'total_unread' => ChatMessage::getUnreadCount($this->user['id'])
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

            // مركز الأتمتة: نحتفظ بالمسودة الأصلية قبل أي تعديل عشان نتعلم منه
            $draftBefore = null;
            $customerText = null;
            $msgRow = $this->db->query('SELECT ai_reply_generated, message_text FROM chat_messages WHERE id = ? AND user_id = ? LIMIT 1', [(int) $messageId, (int) $this->user['id']]);
            if (!empty($msgRow)) {
                $draftBefore = (string) ($msgRow[0]['ai_reply_generated'] ?? '');
                $customerText = (string) ($msgRow[0]['message_text'] ?? '');
            }

            // لو المستخدم عدّل نص الرد المقترح قبل الموافقة، نحفظ التعديل
            // الأول قبل ما نوافق - عشان اللي يتبعت فعليًا يبقى النص المُعدّل
            // مش المسودة الأصلية اللي ولّدها الذكاء الاصطناعي.
            if ($action === 'approve' && $editedReply !== null && trim((string) $editedReply) !== '') {
                $message = (new ChatMessage())->find((int) $messageId);
                if ($message && (int) $message->getAttribute('user_id') === (int) $this->user['id']) {
                    $message->setAttribute('ai_reply_generated', trim((string) $editedReply));
                    $message->save();
                }
            }

            $result = $this->chatManager->approveBotReply(
                $messageId,
                $this->user['id'],
                $action
            );

            if (!$result['success']) {
                return $this->error($result['error'], 400);
            }

            if ($draftBefore !== null && class_exists('AutomationEngine')) {
                $engine = AutomationEngine::instance();
                if ($action === 'approve') {
                    $finalText = ($editedReply !== null && trim((string) $editedReply) !== '') ? trim((string) $editedReply) : $draftBefore;
                    $engine->recordManualAction((int) $this->user['id'], 'chat_replies', 'default', 'chat_message', (string) $messageId, $finalText, $customerText, $draftBefore);
                } else {
                    $engine->recordManualRejection((int) $this->user['id'], 'chat_replies', 'chat_message', (string) $messageId);
                }
            }

            $this->log('Chat Approval', [
                'message_id' => $messageId,
                'action' => $action
            ]);

            return $this->success($result, $result['message'] ?? ($action === 'approve' ? 'تمت الموافقة' : 'تم الرفض'));

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

            // إرسال الرسالة
            if ($platform === 'ultramsg' && $websiteId) {
                $sent = $this->chatManager->sendMessageForWebsite((int) $websiteId, $phoneNumber, $message);
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
            $creditsCheck = $this->subscription->checkChatCredits((int) $this->user['id'], 1);
            $viaWallet = $creditsCheck['source'] === 'wallet';
            $this->subscription->consumeChatCredits((int) $this->user['id'], 1, $viaWallet);

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

        } catch (Exception $e) {
            Logger::error('Send Message Error', [
                'message' => $e->getMessage()
            ]);
            return $this->error('Failed to send message', 500);
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
            @media (max-width: 960px) { .ai-chat-split { grid-template-columns: 1fr; } .ai-chat-list { max-height: 320px; } }
            @media (max-width: 560px) { .q-row { grid-template-columns: 1fr 64px 90px; } .q-row .q-del { grid-column:3; } }
        </style>

        <div class="p-toolbar" style="flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="text" id="ucSearch" class="form-control" placeholder="ابحث بالاسم أو الهاتف أو الإيميل..." style="max-width:230px;flex:1;min-width:170px;">
            <select id="ucStatus" class="p-select">
                <option value="">كل الحالات</option>
                <option value="open">مفتوحة</option>
                <option value="pending">قيد الانتظار</option>
                <option value="resolved">تم الحل</option>
                <option value="closed">مغلقة</option>
            </select>
            <select id="ucAiStatus" class="p-select">
                <option value="">AI أو موظف</option>
                <option value="ai">🤖 الذكاء الاصطناعي</option>
                <option value="human">👤 موظف</option>
                <option value="paused">⏸ متوقف</option>
            </select>
            <select id="ucLeadStatus" class="p-select">
                <option value="">كل حالات Lead</option>
                <option value="new_inquiry">استفسار جديد</option>
                <option value="qualifying">قيد التأهيل</option>
                <option value="qualified">مؤهّل</option>
                <option value="hot_lead">🔥 Lead ساخن</option>
                <option value="converted">تم التحويل</option>
                <option value="lost">فاقد</option>
            </select>
            <select id="ucChannel" class="p-select">
                <option value="">كل القنوات</option>
                <option value="whatsapp">واتساب</option>
                <option value="website_chat">شات الموقع</option>
                <option value="messenger">Messenger</option>
                <option value="instagram">Instagram</option>
                <option value="email">إيميل</option>
            </select>
            <select id="ucTag" class="p-select">
                <option value="">كل الوسوم</option>
                <option value="HOT_LEAD">HOT_LEAD</option>
                <option value="NEW_INQUIRY">NEW_INQUIRY</option>
                <option value="PRICE_REQUEST">PRICE_REQUEST</option>
                <option value="COMPLAINT">COMPLAINT</option>
                <option value="FOLLOW_UP">FOLLOW_UP</option>
                <option value="BOOKING_INTENT">BOOKING_INTENT</option>
                <option value="VIP">VIP</option>
                <option value="HUMAN_REQUIRED">HUMAN_REQUIRED</option>
            </select>
            <button class="p-btn outline xs" onclick="ucApplyFilters()">{IC_SEARCH}بحث</button>
            <div style="flex:1 1 0;min-width:8px;"></div>
            <a href="/chat/pending" class="p-btn outline xs">{IC_CLOCK}المعلّقة</a>
            <a href="/chat/learning" class="p-btn outline xs">{IC_SPARKLES}الفجوات</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">{IC_BOOK}المعرفة</a>
            <a href="/chat/analytics" class="p-btn outline xs">{IC_CHART}التحليلات</a>
            <a href="/chat/settings" class="p-btn primary xs">{IC_GEAR}الإعدادات</a>
        </div>

        <div id="ucNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">{IC_GLOBE}</div>اختر موقعًا من القائمة أعلى الصفحة أولًا لعرض محادثاته.</div>
        </div>

        <div class="ai-chat-split" id="ucBody" style="display:none;">
            <div class="p-card no-pad ai-chat-list-card">
                <div class="p-card-head" style="padding:14px 16px 10px;">
                    <h3>{IC_INBOX} المحادثات</h3>
                    <span class="p-card-sub" id="ucCount"></span>
                </div>
                <div class="ai-chat-list" id="ucList">
                    <div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">{IC_CLOCK}</div>جاري التحميل...</div>
                </div>
            </div>

            <div>
                <div id="ucEmptyState" class="p-card">
                    <div class="p-empty"><div class="p-empty-icon">{IC_CHAT}</div>اختر محادثة من القائمة لعرضها هنا</div>
                </div>

                <div id="ucThreadPanel" style="display:none;">
                    <div class="p-card" id="convHeader" style="margin-bottom:14px;"></div>
                    <div class="p-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="p-card" id="convThread" style="max-height:calc(100vh - 420px);min-height:260px;overflow-y:auto;"></div>
                    <div class="p-card" style="margin-top:14px;">
                        <div class="p-card-head" style="display:flex;align-items:center;justify-content:space-between;">
                            <h3>{IC_SEND} الرد</h3>
                            <button class="p-btn outline xs" onclick="quoteToggleComposer()">{IC_WALLET}عرض سعر</button>
                        </div>
                        <div id="quoteComposer" style="display:none;margin-bottom:12px;"></div>
                        <div id="quoteList" style="display:none;margin-bottom:12px;"></div>
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">{IC_SEND}إرسال</button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">{IC_SPARKLES}اقتراح رد AI</button>
                            <div style="flex:1;"></div>
                            <a href="/chat/leads" class="p-btn outline xs" style="align-self:center;">{IC_TARGET}عرض الـLeads</a>
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
    let websiteId = null;
    let currentConversation = null;
    let activeConvId = null;

    const CHANNEL_LABEL = {
        whatsapp: 'واتساب', website_chat: 'شات الموقع', webchat: 'شات الموقع',
        messenger: 'Messenger', instagram: 'Instagram', email: 'إيميل',
    };
    const STATUS_OPTIONS = [
        ['open', 'مفتوحة'], ['pending', 'قيد الانتظار'], ['resolved', 'تم الحل'], ['closed', 'مغلقة'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'عالية'], ['urgent', 'عاجلة'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];

    function ic(name, cls) {
        return '<svg class="ic ' + (cls || '') + '" aria-hidden="true"><use href="#i-' + name + '"/></svg>';
    }

    const QUOTE_STATUS_LABEL = {
        draft: 'مسودة', sent: 'مُرسل', accepted: 'مقبول', declined: 'مرفوض', expired: 'منتهي', cancelled: 'ملغي',
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
        return c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
    }

    function avatarClass(ch) {
        return (ch === 'whatsapp' || ch === 'email') ? ' ' + ch : '';
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

    async function loadList() {
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

        const listEl = document.getElementById('ucList');
        listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>جاري التحميل...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(id) + '/conversations?' + qs.toString());
        if (!res.success) {
            listEl.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('alert') + '</div>' + esc(res.error || 'تعذر تحميل المحادثات') + '</div>';
            return;
        }

        const list = (res.data && Array.isArray(res.data.conversations)) ? res.data.conversations : [];
        document.getElementById('ucCount').textContent = list.length + ' محادثة';
        if (!list.length) {
            listEl.innerHTML = '<div class="p-empty" style="padding:26px 0;"><div class="p-empty-icon">' + ic('inbox') + '</div>لا توجد محادثات تطابق الفلاتر</div>';
            return;
        }

        listEl.innerHTML = list.map(c => {
            const customer = customerLabel(c);
            const initial = (customer || '?').trim().charAt(0).toUpperCase();
            const active = c.id === activeConvId ? ' active' : '';
            return `
                <div class="ai-chat-item${active}" data-id="${c.id}" onclick="window.selectConversation(${c.id})">
                    <div class="r1">
                        <div class="ai-chat-avatar${avatarClass(c.channel)}">${esc(initial)}</div>
                        <div class="nm">
                            <b>${esc(customer)} ${c.unread_count > 0 ? '<span class="ub">' + c.unread_count + '</span>' : ''}</b>
                            <small>${CHANNEL_LABEL[c.channel] || esc(c.channel || '-')} · ${esc(c.customer_phone || c.customer_email || '-')}</small>
                        </div>
                        <div class="ch">${statusLine(c)}</div>
                    </div>
                    <div class="r1" style="margin-top:6px;">
                        <small class="tm" style="flex:1;">${timeAgo(c.last_message_at)}</small>
                    </div>
                </div>`;
        }).join('');
    }

    window.selectConversation = async function (id) {
        if (!websiteId) websiteId = P.getCurrentWebsiteId();
        if (!websiteId) return;
        activeConvId = id;
        document.querySelectorAll('.ai-chat-item').forEach(el => {
            el.classList.toggle('active', el.dataset.id === String(id));
        });

        document.getElementById('ucEmptyState').style.display = 'none';
        document.getElementById('ucThreadPanel').style.display = 'block';
        document.getElementById('convHeader').innerHTML = '<div class="p-empty" style="padding:20px 0;"><div class="p-empty-icon">' + ic('clock') + '</div>جاري تحميل المحادثة...</div>';
        document.getElementById('convThread').innerHTML = '';

        const res = await fetchJSON('/api/ai-chat/websites/' + encodeURIComponent(websiteId) + '/conversations/' + id);
        if (!res.success || !res.data || !res.data.conversation) {
            toast(res.error || 'تعذر تحميل المحادثة', 'error');
            return;
        }

        currentConversation = res.data.conversation;
        renderHeader(currentConversation);
        renderThread(res.data.messages || []);

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
        if (res.success) { toast(isAi ? 'تم تحويل المحادثة لك' : 'تم استرجاع الرد الآلي', 'success'); loadList(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'تم إلغاء التعيين' : 'تم تعيين المحادثة لك', 'success'); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('تم التحديث', 'success'); loadList(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    window.sendManual = async function () {
        const message = document.getElementById('manualMessage').value.trim();
        if (!message) { toast('اكتب رسالة أولاً', 'error'); return; }
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'اتحفظت الرسالة لكن فشل الإرسال الفعلي للعميل' : 'تم الإرسال', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            selectConversation(currentConversation.id);
        } else {
            toast(res.error || 'فشل الإرسال', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">' + ic('sparkles', 'ic-sm') + ' جاري توليد اقتراحات...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + currentConversation.id + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">' + ic('alert', 'ic-sm') + ' ' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card ai-sugg" style="padding:10px 12px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">اقتراح ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;color:var(--panel-text);">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = customerLabel(c);
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${t}</span>`;
        }).join(' ');

        const statusSelect = '<select class="p-select" onchange="updateField(\'status\', this.value)">' +
            STATUS_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.status === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';
        const prioritySelect = '<select class="p-select" onchange="updateField(\'priority\', this.value)">' +
            PRIORITY_OPTIONS.map(([v, l]) => `<option value="${v}" ${c.priority === v ? 'selected' : ''}>${l}</option>`).join('') + '</select>';

        const badges = [];
        if (c.language) badges.push('<span class="pill gray">' + ic('globe', 'ic-sm') + ' ' + esc(c.language === 'ar' ? 'عربي' : 'English') + '</span>');
        if (c.ai_confidence_score !== null && c.ai_confidence_score !== undefined) {
            badges.push('<span class="pill ' + (c.ai_confidence_score >= 0.7 ? 'green' : (c.ai_confidence_score >= 0.4 ? '' : 'red')) + '">' + ic('sparkles', 'ic-sm') + ' ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>');
        }

        document.getElementById('convHeader').innerHTML = `
            <div class="p-card-head">
                <h3>${esc(customer)} <span class="pill gray">${CHANNEL_LABEL[c.channel] || esc(c.channel || '')}</span></h3>
                <span class="p-card-sub">${esc(c.customer_phone || c.customer_email || '')}</span>
            </div>
            <div class="ai-conv-head">
                ${isAi ? '<span class="pill green">' + ic('sparkles', 'ic-sm') + ' يرد الآن: الذكاء الاصطناعي</span>' : '<span class="pill">' + ic('user', 'ic-sm') + ' يرد الآن: موظف</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${ic('handoff')}${isAi ? 'تحويل لموظف' : 'استرجاع الرد الآلي'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? ic('x') + 'إلغاء التعيين مني' : ic('user-plus') + 'تعيين لي'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${badges.join('')}
            </div>
            <div style="margin:10px 0 4px;display:flex;flex-wrap:wrap;gap:4px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-sidebar-bg-hover);padding:10px 14px;margin-top:8px;"><strong>' + ic('sparkles', 'ic-sm') + ' ملخص AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">' + ic('chat') + '</div>لا توجد رسائل في هذه المحادثة بعد</div>';
            return;
        }
        thread.innerHTML = '<div class="ai-chat-bubbles">' + messages.map(m => {
            const mine = m.message_direction === 'outgoing';
            const text = m.message_text || m.ai_reply_generated || '';
            const tag = (m.ai_reply_generated && !mine) ? '<span class="ai-tag">' + ic('sparkles', 'ic-sm') + ' رد تلقائي' + (m.ai_confidence_score != null ? ' · ' + Math.round(m.ai_confidence_score * 100) + '%' : '') + '</span>' : '';
            return `
                <div class="ai-bubble ${mine ? 'out' : 'in'}">
                    ${tag}
                    <span>${esc(text)}</span>
                    <div class="bt">${P.formatDate(m.sent_at || m.created_at)}</div>
                </div>`;
        }).join('') + '</div>';
        thread.scrollTop = thread.scrollHeight;
    }

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
            <div class="p-card-head"><h3>${ic('target')} معلومات Lead</h3></div>
            <div class="p-kv"><span class="k">الدرجة</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;"><strong>الخطوة التالية المقترحة:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
        `;
    }

    async function refreshActive() {
        if (activeConvId) selectConversation(activeConvId);
    }

    // ===== In-Chat Quotes (بيع داخل الشات) =====
    function quoteBase() {
        return '/api/ai-chat/websites/' + websiteId + '/quotes';
    }

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
                <div class="p-card-head"><h3>${ic('wallet')} عرض سعر جديد</h3></div>
                ${quoteItems.map((it, i) => `
                    <div class="q-row">
                        <input class="form-control" placeholder="اسم الخدمة/البند" value="${esc(it.name)}" oninput="quoteField(${i},'name',this.value)">
                        <input class="form-control" type="number" min="1" value="${it.qty}" oninput="quoteField(${i},'qty',this.value)" title="الكمية">
                        <input class="form-control" type="number" min="0" step="0.01" value="${it.unit_price}" oninput="quoteField(${i},'unit_price',this.value)" title="سعر الوحدة">
                        <button class="p-btn outline xs q-del" onclick="quoteRemoveItem(${i})">${ic('trash')}</button>
                    </div>`).join('')}
                <button class="p-btn outline xs" onclick="quoteAddItem()">${ic('plus')} إضافة بند</button>
                <div class="form-group" style="margin-top:10px;">
                    <label>الخصم</label>
                    <input class="form-control" id="qDiscount" type="number" min="0" step="0.01" value="0" oninput="quoteRenderTotals()">
                </div>
                <div class="form-group">
                    <label>العملة</label>
                    <select class="p-select" id="qCurrency" onchange="quoteRenderTotals()">
                        <option value="USD">USD</option><option value="EGP">EGP</option><option value="EUR">EUR</option><option value="SAR">SAR</option><option value="AED">AED</option><option value="GBP">GBP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>ملاحظات داخلية (اختياري)</label>
                    <textarea class="form-control" id="qNotes" rows="2" placeholder="أي ملاحظات للموظف..."></textarea>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                    <strong>الإجمالي:</strong> <span id="qTotals">0.00 USD</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn primary" onclick="quoteCreate()">${ic('check')} إنشاء العرض</button>
                </div>
            </div>`;
        quoteRenderTotals();
    }

    window.quoteCreate = async function () {
        const items = quoteItems
            .filter(it => (it.name || '').trim() !== '')
            .map(it => ({ name: it.name.trim(), qty: it.qty || 1, unit_price: it.unit_price || 0 }));
        if (!items.length) { toast('أضِف بندًا واحدًا على الأقل', 'error'); return; }
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
            toast('تم إنشاء عرض السعر', 'success');
            document.getElementById('quoteComposer').style.display = 'none';
            quoteItems = [];
            quoteLoad();
        } else {
            toast(res.error || 'فشل إنشاء عرض السعر', 'error');
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
            actions.push(`<button class="p-btn primary xs" onclick="quoteSend(${q.id})">${ic('send')} إرسال للعميل</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'cancelled')">${ic('x')} إلغاء</button>`);
        } else if (q.status === 'sent') {
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'accepted')">${ic('check')} قبول</button>`);
            actions.push(`<button class="p-btn outline xs" onclick="quoteSetStatus(${q.id},'declined')">${ic('x')} رفض</button>`);
        }
        return `
            <div class="ai-quote-card">
                <div class="q-head">
                    <span><strong>${ic('wallet')} ${esc(q.quote_number || 'عرض سعر')}</strong></span>
                    <span class="pill ${QUOTE_STATUS_CLASS[q.status] || 'gray'}">${esc(QUOTE_STATUS_LABEL[q.status] || q.status)}</span>
                </div>
                <div style="padding:8px 0;">${items}</div>
                <div class="ai-quote-row total"><span>الإجمالي</span><span>${Number(q.total).toFixed(2)} ${esc(q.currency)}</span></div>
                ${actions.length ? '<div class="ai-quote-actions">' + actions.join('') + '</div>' : ''}
            </div>`;
    }

    window.quoteSend = async function (quoteId) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId + '/send', { method: 'POST' });
        if (res.success) { toast('تم إرسال عرض السعر للعميل', 'success'); quoteLoad(); selectConversation(currentConversation.id); }
        else { toast(res.error || 'فشل الإرسال', 'error'); }
    };

    window.quoteSetStatus = async function (quoteId, status) {
        const res = await fetchJSON(quoteBase() + '/' + quoteId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: status }),
        });
        if (res.success) {
            toast('تم تحديث حالة العرض', 'success');
            quoteLoad();
            if (status === 'accepted') {
                updateField('lead_status', 'converted');
                updateField('status', 'resolved');
            }
        }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    document.getElementById('ucSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadList();
    });
    window.addEventListener('tourfecto:website-changed', function () {
        activeConvId = null;
        currentConversation = null;
        document.getElementById('ucEmptyState').style.display = 'block';
        document.getElementById('ucThreadPanel').style.display = 'none';
        loadList();
    });

    loadList();
    setInterval(loadList, 20000);
    setInterval(refreshActive, 30000);
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
        <div id="loadingConv" class="p-empty"><div class="p-empty-icon">{IC_CLOCK}</div>جاري تحميل المحادثة...</div>
        <div id="convNotFound" class="p-empty" style="display:none;"><div class="p-empty-icon">{IC_ALERT}</div>المحادثة غير موجودة أو مش تابعة للموقع الحالي.</div>

        <div id="convBody" style="display:none;">
            <div class="p-card" id="convHeader" style="margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;">
                <div style="flex:2 1 420px;min-width:280px;">
                    <div class="p-card" id="convThread" style="max-height:480px;overflow-y:auto;"></div>

                    <div class="p-card" style="margin-top:14px;">
                        <div class="p-card-head"><h3>الرد</h3></div>
                        <div id="aiSuggestions" style="display:none;margin-bottom:10px;"></div>
                        <div class="form-group">
                            <textarea id="manualMessage" class="form-control" rows="3" placeholder="اكتب ردك هنا..."></textarea>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="p-btn primary" id="sendManualBtn" onclick="sendManual()">➤ إرسال</button>
                            <button class="p-btn outline" id="suggestBtn" onclick="loadSuggestions()">💡 اقتراح رد AI</button>
                        </div>
                    </div>
                </div>

                <div style="flex:1 1 260px;min-width:240px;">
                    <div class="p-card" id="leadPanel" style="margin-bottom:14px;"></div>
                    <div class="p-card">
                        <div class="p-card-head"><h3>ملاحظات وصفقات</h3></div>
                        <div class="p-empty" style="padding:16px 0;">
                            <div class="p-empty-icon">🧩</div>
                            ميزة الملاحظات والصفقات المرتبطة غير متاحة حاليًا في AI Chat Backend.
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
        ['open', 'مفتوحة'], ['pending', 'قيد الانتظار'], ['resolved', 'تم الحل'], ['closed', 'مغلقة'],
    ];
    const PRIORITY_OPTIONS = [
        ['low', 'منخفضة'], ['normal', 'عادية'], ['high', 'عالية'], ['urgent', 'عاجلة'],
    ];
    const STANDARD_TAGS = ['HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT', 'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED'];
    const CHANNEL_LABEL = {
        whatsapp: '📱 واتساب', website_chat: '🌐 شات الموقع', webchat: '🌐 شات الموقع',
        messenger: '📘 Messenger', instagram: '📷 Instagram', email: '✉️ إيميل',
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
        if (res.success) { toast(isAi ? 'تم تحويل المحادثة لك' : 'تم استرجاع الرد الآلي', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.assignToggle = async function () {
        const isMine = currentConversation.assigned_agent_id == currentUserId;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assigned_agent_id: isMine ? null : currentUserId }),
        });
        if (res.success) { toast(isMine ? 'تم إلغاء التعيين' : 'تم تعيين المحادثة لك', 'success'); load(); }
        else { toast(res.error || 'فشلت العملية', 'error'); }
    };

    window.updateField = async function (field, value) {
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ [field]: value }),
        });
        if (res.success) { toast('تم التحديث', 'success'); load(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.toggleTag = async function (tag) {
        const tags = currentConversation.tags || [];
        const has = tags.includes(tag);
        const newTags = has ? tags.filter(t => t !== tag) : tags.concat([tag]);
        await updateField('tags', newTags);
    };

    window.sendManual = async function () {
        const message = document.getElementById('manualMessage').value.trim();
        if (!message) { toast('اكتب رسالة أولاً', 'error'); return; }
        const btn = document.getElementById('sendManualBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: message }),
        });
        btn.disabled = false;
        if (res.success) {
            toast(res.data && res.data.sent === false ? 'اتحفظت الرسالة لكن فشل الإرسال الفعلي للعميل' : 'تم الإرسال', res.data && res.data.sent === false ? 'error' : 'success');
            document.getElementById('manualMessage').value = '';
            load();
        } else {
            toast(res.error || 'فشل الإرسال', 'error');
        }
    };

    window.loadSuggestions = async function () {
        const box = document.getElementById('aiSuggestions');
        const btn = document.getElementById('suggestBtn');
        btn.disabled = true;
        box.style.display = 'block';
        box.innerHTML = '<div class="p-cell-muted">🤖 جاري توليد اقتراحات...</div>';

        const res = await fetchJSON('/api/ai-chat/websites/' + websiteId + '/conversations/' + conversationId + '/reply-suggestions');
        btn.disabled = false;

        if (!res.success || !res.data || !Array.isArray(res.data.suggestions) || !res.data.suggestions.length) {
            box.innerHTML = '<div class="p-cell-muted">⚠️ ' + esc((res.data && res.data.error) || res.error || 'لا توجد اقتراحات متاحة الآن') + '</div>';
            return;
        }

        box.innerHTML = res.data.suggestions.map((s, i) => `
            <div class="p-card" style="padding:10px;margin-bottom:6px;cursor:pointer;" onclick="document.getElementById('manualMessage').value = this.dataset.text;">
                <span class="pill blue">اقتراح ${i + 1}</span>
                <span data-text="${esc(s).replace(/"/g, '&quot;')}" style="display:block;margin-top:6px;">${esc(s)}</span>
            </div>`).join('');
    };

    function renderHeader(c) {
        const customer = c.customer_name || c.customer_phone || c.customer_email || 'عميل غير معروف';
        const isAi = c.ai_status === 'ai';
        const isMine = c.assigned_agent_id == currentUserId;

        const tagsHtml = STANDARD_TAGS.map(t => {
            const active = (c.tags || []).includes(t);
            return `<span class="pill ${active ? 'blue' : 'gray'}" style="cursor:pointer;" onclick="toggleTag('${t}')">${active ? '✓ ' : ''}${t}</span>`;
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
                ${isAi ? '<span class="pill green">🤖 يرد الآن: الذكاء الاصطناعي</span>' : '<span class="pill">👤 يرد الآن: موظف</span>'}
                <button class="p-btn ${isAi ? 'outline' : 'primary'} xs" onclick="toggleHandoff()">${isAi ? '⇄ تحويل لموظف' : '⇄ استرجاع الرد الآلي'}</button>
                <button class="p-btn outline xs" onclick="assignToggle()">${isMine ? '✖ إلغاء التعيين مني' : '👤 تعيين لي'}</button>
                ${statusSelect}
                ${prioritySelect}
                ${c.ai_confidence_score !== null && c.ai_confidence_score !== undefined ? '<span class="pill">ثقة AI: ' + Math.round(c.ai_confidence_score * 100) + '%</span>' : ''}
            </div>
            <div style="margin-bottom:8px;">${tagsHtml}</div>
            ${c.ai_summary ? '<div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:10px 14px;"><strong>ملخص AI:</strong> ' + esc(c.ai_summary) + '</div>' : ''}
        `;
    }

    function renderThread(messages) {
        const thread = document.getElementById('convThread');
        if (!messages.length) {
            thread.innerHTML = '<div class="p-empty"><div class="p-empty-icon">💬</div>لا توجد رسائل في هذه المحادثة بعد</div>';
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
            panel.innerHTML = '<div class="p-card-head"><h3>معلومات Lead</h3></div><div class="p-empty" style="padding:16px 0;"><div class="p-empty-icon">📋</div>لا يوجد Lead مرتبط بهذه المحادثة بعد.</div>';
            return;
        }
        panel.innerHTML = `
            <div class="p-card-head"><h3>معلومات Lead</h3></div>
            <div class="p-kv"><span class="k">الدرجة</span><span class="v">${lead.lead_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">نية الشراء</span><span class="v">${lead.intent_score ?? '-'} / 100</span></div>
            <div class="p-kv"><span class="k">الوجهة</span><span class="v">${esc(lead.destination || '-')}</span></div>
            <div class="p-kv"><span class="k">الاهتمام</span><span class="v">${esc(lead.interest || '-')}</span></div>
            <div class="p-kv"><span class="k">الحالة</span><span class="v">${esc(lead.status || '-')}</span></div>
            ${lead.next_recommended_action ? '<div style="margin-top:10px;padding:10px;background:var(--panel-bg,#f7f8fa);border-radius:8px;"><strong>الخطوة التالية المقترحة:</strong><br>' + esc(lead.next_recommended_action) + '</div>' : ''}
            <a href="/chat/leads" class="p-btn outline xs" style="margin-top:10px;display:inline-block;">عرض كل الـLeads</a>
        `;
    }

    async function load() {
        websiteId = P.getCurrentWebsiteId();
        if (!websiteId) {
            document.getElementById('loadingConv').style.display = 'none';
            document.getElementById('convNotFound').style.display = 'block';
            document.getElementById('convNotFound').innerHTML = '<div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.';
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
    setInterval(load, 20000);
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
            return $this->error('معرف المحادثة مطلوب', 422);
        }

        try {
            $sql = "SELECT * FROM chat_messages WHERE user_id = ? AND session_id = ? ORDER BY created_at ASC";
            $messages = $this->db->query($sql, [$this->user['id'], $sessionId]);
            return $this->success(['messages' => $messages]);
        } catch (Exception $e) {
            Logger::error('Get Conversation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المحادثة', 500);
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
            <a href="/chat" class="p-btn outline xs">← صندوق الوارد</a>
            <a href="/chat/knowledge-base" class="p-btn outline xs">📚 قاعدة المعرفة</a>
        </div>
        <div class="p-card">
            <div class="form-group">
                <label class="form-label" for="websiteSelect">{$tSelectWebsite}</label>
                <select id="websiteSelect" class="form-control" onchange="loadSettings()">
                    <option value="">{$tLoadingWebsites}</option>
                </select>
            </div>
        </div>
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
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
            </div>
            <div id="settingsAlert" class="alert alert-danger" style="display:none;"></div>
            <button class="p-btn primary" onclick="saveSettings()">{$tSaveSettings}</button>
        </div>
        <div id="messengerCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📘 Messenger</h3><span class="p-card-sub">اربط صفحة فيسبوك الخاصة بالشركة</span></div>
            <div id="messengerConnected" style="display:none;">
                <div class="alert alert-success">✔ Messenger مربوط بالفعل</div>
                <p class="p-cell-muted">Webhook URL:</p>
                <code id="msgWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                <code id="msgVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            </div>
            <div id="messengerForm">
                <p class="p-cell-muted">أدخل Page Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>، ثم استخدم الرابط والـVerify Token اللي هيظهرلك لتسجيل الـWebhook هناك.</p>
                <div class="form-group">
                    <input type="text" id="msgPageId" class="form-control" placeholder="Page ID" style="margin-bottom:8px;">
                </div>
                <div class="form-group">
                    <input type="text" id="msgAccessToken" class="form-control" placeholder="Page Access Token">
                </div>
                <button class="p-btn primary" style="margin-top:10px;" onclick="connectMessenger()">ربط Messenger</button>
            </div>
        </div>
        <div id="instagramCard" class="p-card" style="display:none;margin-top:14px;">
            <div class="p-card-head"><h3>📷 Instagram</h3><span class="p-card-sub">اربط حساب انستجرام التجاري الخاص بالشركة</span></div>
            <div id="instagramConnected" style="display:none;">
                <div class="alert alert-success">✔ Instagram مربوط بالفعل</div>
                <p class="p-cell-muted">Webhook URL:</p>
                <code id="igWebhookUrl" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
                <p class="p-cell-muted" style="margin-top:8px;">Verify Token:</p>
                <code id="igVerifyToken" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:10px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;"></code>
            </div>
            <div id="instagramForm">
                <p class="p-cell-muted">أدخل IG Business Account Access Token من <a href="https://developers.facebook.com" target="_blank" rel="noopener">Meta for Developers</a>.</p>
                <div class="form-group">
                    <input type="text" id="igAccountId" class="form-control" placeholder="Instagram Business Account ID" style="margin-bottom:8px;">
                </div>
                <div class="form-group">
                    <input type="text" id="igAccessToken" class="form-control" placeholder="Access Token">
                </div>
                <button class="p-btn primary" style="margin-top:10px;" onclick="connectInstagram()">ربط Instagram</button>
            </div>
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
            document.getElementById('messengerCard').style.display = 'none';
            document.getElementById('instagramCard').style.display = 'none';
            return;
        }

        document.getElementById('ultramsgCard').style.display = 'block';
        await loadUltraMsgStatus(websiteId);
        document.getElementById('messengerCard').style.display = 'block';
        document.getElementById('instagramCard').style.display = 'block';

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
            connectedBox.style.display = 'none';
            disconnectedBox.style.display = 'block';
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

    window.connectMessenger = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const pageId = document.getElementById('msgPageId').value.trim();
        const accessToken = document.getElementById('msgAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/messenger', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: pageId }),
        });

        if (res.success) {
            toast('تم ربط Messenger - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('messengerForm').style.display = 'none';
            document.getElementById('messengerConnected').style.display = 'block';
            document.getElementById('msgWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('msgVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
    };

    window.connectInstagram = async function () {
        const websiteId = document.getElementById('websiteSelect').value;
        const accountId = document.getElementById('igAccountId').value.trim();
        const accessToken = document.getElementById('igAccessToken').value.trim();
        if (!accessToken) { toast('اكتب Access Token أولاً', 'error'); return; }

        const res = await fetchJSON('/api/chat/connect/instagram', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ website_id: websiteId, access_token: accessToken, page_id: accountId }),
        });

        if (res.success) {
            toast('تم ربط Instagram - سجّل الـWebhook تحت في Meta for Developers', 'success');
            document.getElementById('instagramForm').style.display = 'none';
            document.getElementById('instagramConnected').style.display = 'block';
            document.getElementById('igWebhookUrl').textContent = res.data.webhook_url || '';
            document.getElementById('igVerifyToken').textContent = res.data.verify_token || '';
        } else {
            toast(res.error || 'فشل الربط', 'error');
        }
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
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}الرجوع لصندوق الوارد</a>
            <div style="flex:1;"></div>
            <button class="p-btn outline xs" onclick="kbPreview()">{IC_SPARKLES}معاينة السياق المُرسَل للـAI</button>
        </div>

        <div id="kbNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>

        <div id="kbBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>🎙 نبرة الشركة (Brand Voice)</h3><span class="p-card-sub">تُستخدم في كل ردود الذكاء الاصطناعي</span></div>
                <div class="form-group">
                    <label class="form-label">النبرة</label>
                    <select id="bvTone" class="form-control">
                        <option value="professional">احترافية</option>
                        <option value="friendly">ودّية</option>
                        <option value="luxury">فاخرة</option>
                        <option value="casual">غير رسمية</option>
                        <option value="formal">رسمية جدًا</option>
                        <option value="sales_focused">مُركّزة على البيع</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">تعليمات إضافية (اختياري)</label>
                    <textarea id="bvInstructions" class="form-control" rows="2" placeholder="مثال: دائمًا اذكر سياسة الإلغاء المجاني قبل 48 ساعة"></textarea>
                </div>
                <button class="p-btn primary xs" onclick="kbSaveBrandVoice()">حفظ نبرة الشركة</button>
            </div>

            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>➕ إضافة معلومة جديدة</h3></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">القسم</label>
                        <select id="kbSection" class="form-control">
                            <option value="company_info">معلومات الشركة</option>
                            <option value="service">خدمة</option>
                            <option value="tour">رحلة/جولة</option>
                            <option value="destination">وجهة</option>
                            <option value="pricing">سعر</option>
                            <option value="faq">سؤال شائع</option>
                            <option value="policy">سياسة</option>
                            <option value="cancellation_policy">سياسة الإلغاء</option>
                            <option value="contact_info">بيانات التواصل</option>
                            <option value="business_hours">ساعات العمل</option>
                            <option value="custom_instructions">تعليمات مخصصة</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">اللغة</label>
                        <select id="kbLanguage" class="form-control">
                            <option value="en">English</option>
                            <option value="ar">العربية</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;min-width:180px;">
                        <label class="form-label">الأولوية (لترتيب الرد)</label>
                        <select id="kbPriority" class="form-control">
                            <option value="0">عادية (0)</option>
                            <option value="1">مرتفعة (1)</option>
                            <option value="2">أولوية قصوى (2)</option>
                            <option value="-1">منخفضة (-1)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">العنوان (اختياري، مثال: اسم الرحلة أو نص السؤال)</label>
                    <input type="text" id="kbTitle" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">المحتوى</label>
                    <textarea id="kbContent" class="form-control" rows="3" placeholder="اكتب المعلومة كاملة وواضحة - الذكاء الاصطناعي هيعتمد على النص ده حرفيًا"></textarea>
                </div>
                <button class="p-btn primary" onclick="kbAddEntry()">➕ إضافة</button>
            </div>

            <div id="kbSectionsContainer"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, formatDate = P.formatDate;

    const SECTION_LABELS = {
        company_info: 'معلومات الشركة', service: 'الخدمات', tour: 'الرحلات/الجولات',
        destination: 'الوجهات', pricing: 'الأسعار', faq: 'الأسئلة الشائعة',
        policy: 'السياسات', cancellation_policy: 'سياسة الإلغاء',
        contact_info: 'بيانات التواصل', business_hours: 'ساعات العمل',
        custom_instructions: 'تعليمات مخصصة',
    };

    function websiteId() { return P.getCurrentWebsiteId(); }

    function ensureWebsite() {
        const id = websiteId();
        document.getElementById('kbNoWebsite').style.display = id ? 'none' : 'block';
        document.getElementById('kbBody').style.display = id ? 'block' : 'none';
        return id;
    }

    window.kbAddEntry = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const section = document.getElementById('kbSection').value;
        const language = document.getElementById('kbLanguage').value;
        const priority = parseInt(document.getElementById('kbPriority').value, 10) || 0;
        const title = document.getElementById('kbTitle').value.trim();
        const content = document.getElementById('kbContent').value.trim();
        if (!content) { toast('اكتب المحتوى أولاً', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: section, language: language, priority: priority, title: title || null, content: content }),
        });

        if (res.success) {
            toast('تمت الإضافة', 'success');
            document.getElementById('kbTitle').value = '';
            document.getElementById('kbContent').value = '';
            load();
        } else {
            toast(res.error || 'فشلت الإضافة', 'error');
        }
    };

    window.kbDeleteEntry = async function (entryId) {
        const id = websiteId();
        if (!confirm('تأكيد حذف هذه المعلومة؟')) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, { method: 'DELETE' });
        if (res.success) { toast('تم الحذف', 'success'); load(); }
        else { toast(res.error || 'فشل الحذف', 'error'); }
    };

    window.kbEditEntry = async function (entryId, currentContent, currentPriority) {
        const id = websiteId();
        const content = prompt('عدّل المحتوى:', currentContent || '');
        if (content === null) return;
        if (!content.trim()) { toast('المحتوى مطلوب', 'error'); return; }
        const priorityInput = prompt('الأولوية (0 عادية، 1 مرتفعة، 2 قصوى، -1 منخفضة):', String(currentPriority ?? 0));
        if (priorityInput === null) return;
        const priority = parseInt(priorityInput, 10);
        const priorityVal = isNaN(priority) ? 0 : priority;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/' + entryId, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content: content.trim(), priority: priorityVal }),
        });
        if (res.success) { toast('تم التعديل', 'success'); load(); }
        else { toast(res.error || 'فشل التعديل', 'error'); }
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

        if (res.success) { toast('تم حفظ نبرة الشركة', 'success'); }
        else { toast(res.error || 'فشل الحفظ', 'error'); }
    };

    window.kbPreview = async function () {
        const id = websiteId();
        if (!id) { toast('اختر موقعًا أولاً', 'error'); return; }
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base/preview');
        if (!res.success) { toast(res.error || 'فشلت المعاينة', 'error'); return; }
        alert(res.data.context_preview || 'لا يوجد محتوى بعد');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/knowledge-base');
        const container = document.getElementById('kbSectionsContainer');

        if (!res.success) {
            container.innerHTML = '<div class="p-card"><div class="p-empty">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</div></div>';
            return;
        }

        if (res.data.brand_voice) {
            document.getElementById('bvTone').value = res.data.brand_voice.tone || 'professional';
            document.getElementById('bvInstructions').value = res.data.brand_voice.custom_instructions || '';
        }

        const sections = res.data.sections || {};
        const sectionKeys = Object.keys(sections);
        if (!sectionKeys.length) {
            container.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">📚</div>مفيش أي معلومات مضافة لقاعدة المعرفة بعد. أضف أول معلومة من الفورم أعلاه - من غيرها الذكاء الاصطناعي مش هيقدر يجاوب على أي سؤال محدد عن شركتك.</div></div>';
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
                        ${e.priority ? '<span class="pill blue">أولوية ' + e.priority + '</span>' : ''}
                    </span>
                    <span style="display:flex;gap:6px;">
                        <button class="p-btn outline xs" onclick="kbEditEntry(${e.id}, ${JSON.stringify(e.content || '').replace(/"/g, '&quot;')}, ${e.priority ?? 0})">تعديل</button>
                        <button class="p-btn danger xs" onclick="kbDeleteEntry(${e.id})">حذف</button>
                    </span>
                </div>`).join('');
            return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head"><h3>${SECTION_LABELS[section] || esc(section)}</h3><span class="p-card-sub">${entries.length} عنصر</span></div>
                    ${rows}
                </div>`;
        }).join('');
    }

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_knowledge', 'قاعدة المعرفة', 'المعلومات التي يعتمد عليها الذكاء الاصطناعي في الرد على عملائك', $this->applyChatUi($body), $script);
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
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
        </div>
        <div id="fuNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div id="fuBody" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>⏰ المتابعة التلقائية</h3><span class="p-card-sub">لو العميل سأل ثم اختفى، النظام يقدر يبعتله متابعة تلقائية حسب الخطوات تحت</span></div>
                <div class="form-group">
                    <label class="form-label"><input type="checkbox" id="fuEnabled"> تفعيل المتابعة التلقائية لهذا الموقع</label>
                </div>
                <div class="form-group">
                    <label class="form-label">الحد الأقصى لعدد المتابعات لكل عميل</label>
                    <input type="number" id="fuMax" class="form-control" min="1" max="10" style="max-width:120px;">
                </div>
            </div>
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>خطوات المتابعة</h3></div>
                <div id="fuSteps"></div>
                <button class="p-btn outline xs" style="margin-top:10px;" onclick="fuAddStep()">➕ إضافة خطوة</button>
            </div>
            <button class="p-btn primary" onclick="fuSave()">💾 حفظ الإعدادات</button>
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
            container.innerHTML = '<div class="p-cell-muted">مفيش خطوات لسه - أضف خطوة عشان المتابعة التلقائية تشتغل.</div>';
            return;
        }
        container.innerHTML = steps.map((s, i) => `
            <div class="p-card" style="background:var(--panel-bg,#f7f8fa);padding:12px;margin-bottom:10px;">
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <strong>الخطوة ${i + 1}</strong>
                    <label class="form-label" style="margin:0;">بعد</label>
                    <input type="number" class="form-control" style="max-width:90px;" value="${s.after_hours}" onchange="fuUpdateStep(${i}, 'after_hours', this.value)">
                    <span class="p-cell-muted">ساعة من آخر رسالة للعميل</span>
                    <div style="flex:1;"></div>
                    <button class="p-btn danger xs" onclick="fuRemoveStep(${i})">حذف</button>
                </div>
                <div class="form-group" style="margin-top:8px;margin-bottom:0;">
                    <label class="form-label">نص الرسالة (استخدم {name} لاسم العميل)</label>
                    <textarea class="form-control" rows="2" onchange="fuUpdateStep(${i}, 'template', this.value)">${esc(s.template || '')}</textarea>
                </div>
            </div>`).join('');
    }

    window.fuAddStep = function () {
        steps.push({ after_hours: 24, template: 'مرحبًا {name}، مجرد تذكير - هل ما زلت مهتمًا؟' });
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
        if (res.success) toast('تم حفظ الإعدادات', 'success');
        else toast(res.error || 'فشل الحفظ', 'error');
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/followup-settings');
        if (!res.success) { toast(res.error || 'تعذر التحميل', 'error'); return; }
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
        echo $this->renderPanelPage('ai_chat_followup', 'المتابعة التلقائية', 'إعدادات الرسائل التلقائية للعملاء الذين لم يردّوا', $this->applyChatUi($body), $script);
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
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
            <div style="flex:1;"></div>
            <select id="anSince" class="p-select" onchange="load()">
                <option value="7">آخر 7 أيام</option>
                <option value="30" selected>آخر 30 يوم</option>
                <option value="90">آخر 90 يوم</option>
            </select>
        </div>
        <div id="anNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">{IC_GLOBE}</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div id="anBody" style="display:none;">

            <div id="anStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;"></div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_CHART} توزيع المحادثات</h3></div>
                    <div style="padding:6px 4px;"><canvas id="anConvChart" height="120"></canvas></div>
                </div>
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_SPARKLES} صحة مزودي الذكاء الاصطناعي</h3></div>
                    <div id="anHealth" style="padding:4px 2px;"></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_TAG} أكثر الوسوم تكرارًا</h3></div>
                    <div id="anTags"></div>
                </div>
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_TARGET} أكثر الخدمات طلبًا</h3></div>
                    <div id="anServices"></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_SPARKLES} حلقة التعلّم: نتائج المحادثات</h3></div>
                    <div id="anLearning"></div>
                </div>
                <div class="p-card" style="flex:1 1 360px;min-width:280px;">
                    <div class="p-card-head"><h3>{IC_HANDOFF} أسباب التحويل للموظف</h3></div>
                    <div id="anEscalations"></div>
                </div>
            </div>

            <div class="p-card">
                <div class="p-card-head"><h3>{IC_CHART} استخدام مزودي الذكاء الاصطناعي (آخر 24 ساعة)</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="anProviders">
                    <thead><tr><th>المزود</th><th>النموذج</th><th>الطلبات</th><th>ناجحة</th><th>فاشلة</th><th>Fallback</th><th>Tokens</th><th>التكلفة التقديرية</th></tr></thead>
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
        if (!configured) return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v"><span class="pill gray">غير مهيّأ</span></span></div>';
        let pill;
        if (status24h === 'healthy') pill = '<span class="pill green">' + ic('check','ic-sm') + ' سليم</span>';
        else if (status24h === 'degraded') pill = '<span class="pill red">' + ic('alert','ic-sm') + ' متدهور</span>';
        else pill = '<span class="pill">لا بيانات بعد</span>';
        return '<div class="p-kv"><span class="k">' + esc(provider) + '</span><span class="v">' + pill + '</span></div>';
    }

    function renderHealth(health) {
        const box = document.getElementById('anHealth');
        if (!health || !Array.isArray(health.providers)) {
            box.innerHTML = '<div class="p-cell-muted">لا توجد بيانات صحة متاحة</div>';
            return;
        }
        const per = {};
        (health.summary_last_24h && health.summary_last_24h.per_provider || []).forEach(p => { per[p.provider] = p; });

        let html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
        if (health.status === 'healthy') html += '<span class="pill green">✓ الحالة العامة: سليمة</span>';
        else if (health.status === 'degraded') html += '<span class="pill red">⚠ الحالة العامة: متدهورة (فشل في بعض الطلبات)</span>';
        else html += '<span class="pill">الحالة العامة: لا استخدام مسجّل بعد</span>';
        html += '</div>';

        health.providers.forEach(p => {
            const s = per[p.provider];
            const status24h = s ? (s.failed_requests > 0 ? 'degraded' : 'healthy') : 'no_data';
            html += healthPill(p.provider, !!p.configured, status24h);
            if (p.configured) {
                html += '<div class="p-kv"><span class="k">النموذج</span><span class="v">' + esc(p.model || '-') + '</span></div>';
                html += '<div class="p-kv"><span class="k">ترتيب الأفضلية</span><span class="v">#' + (p.priority_position || '-') + '</span></div>';
                if (s) {
                    html += '<div class="p-kv"><span class="k">طلبات (24h)</span><span class="v">' + s.total_requests + ' · ' + s.failed_requests + ' فاشلة</span></div>';
                }
            }
        });

        const s = health.summary_last_24h;
        if (s && s.total_requests > 0) {
            html += '<div style="margin-top:10px;padding:10px;background:var(--panel-sidebar-bg-hover);border-radius:8px;">'
                + '<strong>إجمالي آخر 24 ساعة:</strong> ' + s.total_requests + ' طلب · '
                + s.failed_requests + ' فاشل · ' + (s.fallback_used_count || 0) + ' Fallback · '
                + (s.total_tokens || 0).toLocaleString() + ' token · $' + parseFloat(s.total_cost_usd || 0).toFixed(4)
                + '</div>';
        }
        box.innerHTML = html;
    }

    function renderLearning(learning) {
        const box = document.getElementById('anLearning');
        if (!learning) { box.innerHTML = '<div class="p-cell-muted">لا توجد بيانات حلقة تعلّم بعد</div>'; return; }

        const breakdown = learning.resolution_events || {};
        const total = Object.values(breakdown).reduce((a, b) => a + b, 0);
        let html = '<div class="p-kv"><span class="k">معدّل حلّ الذكاء الاصطناعي</span><span class="v"><strong>' + (learning.ai_resolution_rate_percent || 0) + '%</strong></span></div>';
        html += '<div class="p-kv"><span class="k">أحداث مسجّلة</span><span class="v">' + total + '</span></div>';
        if (total > 0) {
            const labels = {
                ai_resolved: 'حلّها الذكاء الاصطناعي',
                human_resolved: 'حلّها موظف',
                abandoned: 'ترَك العميل',
                reopened: 'أُعيد فتحها',
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
            html += '<div style="margin-top:10px;"><strong>أبرز فجوات المعرفة:</strong></div>';
            html += gaps.slice(0, 3).map(g =>
                '<div class="p-kv"><span class="k">' + esc(g.question || g.normalized_question || '-') + '</span><span class="v">×' + (g.occurrence_count || 1) + '</span></div>'
            ).join('');
            html += '<a href="/chat/learning" class="p-btn outline xs" style="margin-top:8px;">مراجعة الفجوات كلها</a>';
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
                    outside_knowledge_base: 'خارج قاعدة المعرفة',
                    low_ai_confidence: 'ثقة AI منخفضة',
                    ai_requested_handoff: 'طلب الـAI التحويل',
                    manual_takeover: 'تدخل يدوي',
                    customer_escalated: 'طلب العميل',
                    'no_provider_configured': 'لا يوجد مزود مهيّأ',
                };
                label = map[r] || r;
                return '<div class="p-kv"><span class="k">' + esc(label) + '</span><span class="v">' + reasons[r] + '</span></div>';
            }).join('')
            : '<div class="p-cell-muted">لا توجد أسباب تحويل مسجّلة</div>';
    }

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('anSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/analytics?since=' + since);
        if (!res.success) {
            document.getElementById('anStats').innerHTML = '<div class="p-cell-muted">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</div>';
            return;
        }

        const d = res.data.dashboard;
        document.getElementById('anStats').innerHTML = [
            statTile('إجمالي المحادثات', d.total_conversations),
            statTile('ردّ الذكاء الاصطناعي', d.ai_conversations),
            statTile('تحويل لموظف', d.human_conversations),
            statTile('Leads جديدة', d.leads_generated),
            statTile('Leads ساخنة 🔥', d.hot_leads),
            statTile('نسبة التحويل', d.conversion_rate_percent + '%'),
            statTile('معدّل حلّ الذكاء الاصطناعي', d.ai_resolution_rate_percent + '%'),
            statTile('نسبة التحويل لموظف', d.human_handoff_rate_percent + '%'),
            statTile('نجاح المتابعات', d.followup_success_rate_percent + '%'),
        ].join('');

        const tags = d.top_tags || {};
        const tagKeys = Object.keys(tags);
        document.getElementById('anTags').innerHTML = tagKeys.length
            ? tagKeys.map(t => `<div class="p-kv"><span class="k">${esc(t)}</span><span class="v">${tags[t]}</span></div>`).join('')
            : '<div class="p-cell-muted">لا توجد بيانات كافية بعد</div>';

        const services = d.most_popular_services || {};
        const serviceKeys = Object.keys(services);
        document.getElementById('anServices').innerHTML = serviceKeys.length
            ? serviceKeys.map(s => `<div class="p-kv"><span class="k">${esc(s)}</span><span class="v">${services[s]}</span></div>`).join('')
            : '<div class="p-cell-muted">لا توجد بيانات كافية بعد</div>';

        if (typeof Chart !== 'undefined') {
            const conv = { ai: d.ai_conversations || 0, human: d.human_conversations || 0 };
            if (convChart) convChart.destroy();
            convChart = new Chart(document.getElementById('anConvChart'), {
                type: 'doughnut',
                data: {
                    labels: ['ردّ الذكاء الاصطناعي', 'تحويل لموظف'],
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
            : '<tr><td colspan="8" class="p-cell-muted text-center">لا يوجد استخدام مسجَّل بعد</td></tr>';
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->        renderPanelPage('ai_chat_analytics', 'تحليلات AI Chat', 'أداء الذكاء الاصطناعي، صحة المزودين، وحلقة التعلّم', $this->applyChatUi($body), $script);
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
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
            <div style="flex:1;"></div>
            <button class="p-btn outline xs" onclick="lnScan()">{IC_REFRESH}إعادة مسح الفجوات</button>
            <select id="lnSince" class="p-select" onchange="load()">
                <option value="7">آخر 7 أيام</option>
                <option value="30" selected>آخر 30 يوم</option>
                <option value="90">آخر 90 يوم</option>
            </select>
        </div>
        <div id="lnNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div id="lnBody" style="display:none;">
            <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:14px;">
                <div class="p-card" style="flex:1 1 300px;min-width:240px;text-align:center;">
                    <div style="font-size:30px;font-weight:800;font-family:var(--font-mono-num);color:var(--panel-teal);" id="lnResRate">-</div>
                    <div class="p-cell-muted">معدّل حلّ الذكاء الاصطناعي</div>
                </div>
                <div class="p-card" style="flex:1 1 300px;min-width:240px;text-align:center;">
                    <div style="font-size:30px;font-weight:800;font-family:var(--font-mono-num);color:var(--panel-accent);" id="lnGapCount">-</div>
                    <div class="p-cell-muted">فجوات معرفة غير معالجة</div>
                </div>
                <div class="p-card" style="flex:1 1 100%;min-width:240px;">
                    <div class="p-card-head"><h3>ℹ️ كيف تعمل حلقة التعلّم؟</h3></div>
                    <div class="p-cell-muted" style="font-size:13px;line-height:1.8;">
                        عندما يحوّل الذكاء الاصطناعي محادثة لموظف لأن السؤال خارج قاعدة المعرفة أو الثقة منخفضة،
                        تُسجَّل السؤال تلقائيًا كـ"فجوة معرفة". أضف إجابة الفجوة لقاعدة المعرفة ليردّ عليها الـAI
                        في المرة القادمة — هكذا يتحسّن النظام تدريجيًا (Flywheel).
                    </div>
                </div>
            </div>

            <div class="p-card no-pad">
                <div class="p-card-head" style="padding:18px 20px 0;"><h3>🧠 فجوات المعرفة</h3></div>
                <div class="p-table-scroll"><table class="p-table" id="lnTable">
                    <thead><tr>
                        <th>السؤال</th><th>اللغة</th><th>سبب التحويل</th><th>عدد المرات</th><th>الحالة</th><th>آخر ظهور</th><th></th>
                    </tr></thead>
                    <tbody><tr class="p-loading-row"><td colspan="7">جاري التحميل...</td></tr></tbody>
                </table></div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;

    const STATUS_PILL = {
        new: '<span class="pill red">جديدة</span>',
        acknowledged: '<span class="pill">تمت الملاحظة</span>',
        added_to_kb: '<span class="pill green">أُضيفت للمعرفة</span>',
        dismissed: '<span class="pill gray">متجاهلة</span>',
    };
    const REASON_LABEL = {
        outside_knowledge_base: 'خارج قاعدة المعرفة',
        low_ai_confidence: 'ثقة AI منخفضة',
        ai_requested_handoff: 'طلب الـAI التحويل',
        manual_takeover: 'تدخل يدوي',
        customer_escalated: 'طلب العميل',
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
            toast('تم المسح: ' + (res.data && res.data.new_gaps_recorded != null ? res.data.new_gaps_recorded + ' فجوة جديدة' : 'بدون فجوات جديدة'), 'success');
            load();
        } else {
            toast(res.error || 'فشل المسح', 'error');
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
        if (res.success) { toast('تم تحديث حالة الفجوة', 'success'); load(); }
        else { toast(res.error || 'فشل التحديث', 'error'); }
    };

    window.lnAddToKb = async function (gapId, question) {
        const id = websiteId();
        if (!id) return;
        const content = prompt('اكتب إجابة الفجوة لتُضاف لقاعدة المعرفة:', '');
        if (content === null) return;
        if (!content.trim()) { toast('الإجابة مطلوبة', 'error'); return; }

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
        if (!addRes.success) { toast(addRes.error || 'فشلت الإضافة', 'error'); return; }

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps/' + gapId + '/status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: 'added_to_kb' }),
        });
        if (res.success) { toast('تمت إضافة الإجابة لقاعدة المعرفة وإغلاق الفجوة', 'success'); load(); }
        else { toast('أُضيفت للمعرفة لكن فشل تحديث الحالة', 'error'); load(); }
    };

    async function load() {
        const id = ensureWebsite();
        if (!id) return;
        const days = document.getElementById('lnSince').value;
        const since = new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/learning/gaps?since=' + since);
        if (!res.success) {
            document.querySelector('#lnTable tbody').innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</td></tr>';
            return;
        }

        const gaps = (res.data && Array.isArray(res.data.knowledge_gaps)) ? res.data.knowledge_gaps : [];
        const summary = (res.data && res.data.summary) || {};

        document.getElementById('lnResRate').textContent = (summary.ai_resolution_rate_percent != null ? summary.ai_resolution_rate_percent : '-') + '%';
        const unresolved = gaps.filter(g => g.status === 'new' || g.status === 'acknowledged').length;
        document.getElementById('lnGapCount').textContent = unresolved || 0;

        const tbody = document.querySelector('#lnTable tbody');
        if (!gaps.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="p-cell-muted text-center">لا توجد فجوات معرفة في هذه الفترة — الـAI يرد على كل الأسئلة 🎉</td></tr>';
            return;
        }

        tbody.innerHTML = gaps.map(g => {
            const actions = [];
            if (g.status === 'new' || g.status === 'acknowledged') {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'acknowledged\')">👁 ملاحظة</button>');
                actions.push('<button class="p-btn primary xs" onclick="lnAddToKb(' + g.id + ', \'' + esc(String(g.question || '')).replace(/'/g, "\\'") + '\')">📚 أضِف للمعرفة</button>');
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'dismissed\')">✖ تجاهل</button>');
            } else {
                actions.push('<button class="p-btn outline xs" onclick="lnSetStatus(' + g.id + ', \'new\')">↺ إعادة فتح</button>');
            }
            return `<tr>
                <td><div style="max-width:340px;">${esc(g.question || g.normalized_question || '-')}</div></td>
                <td><span class="pill gray">${esc(g.language === 'ar' ? 'عربي' : (g.language || '-'))}</span></td>
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
        echo $this->renderPanelPage('ai_chat_learning', 'التعلّم وفجوات المعرفة', 'حلقة تعلّم الذكاء الاصطناعي: لاحظ الفجوات وعلّم النظام ليردّ أفضل في المرة القادمة', $this->applyChatUi($body), $script);
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
            <a href="/chat" class="p-btn outline xs">{IC_INBOX}صندوق الوارد</a>
            <div style="flex:1;"></div>
            <select id="ldStatus" class="p-select" onchange="load()">
                <option value="">كل الحالات</option>
                <option value="new">جديد</option>
                <option value="contacted">تم التواصل</option>
                <option value="qualified">مؤهّل</option>
                <option value="proposal_sent">تم إرسال عرض سعر</option>
                <option value="won">تم الفوز به</option>
                <option value="lost">فاقد</option>
            </select>
        </div>
        <div id="ldNoWebsite" class="p-card" style="display:none;">
            <div class="p-empty"><div class="p-empty-icon">🌐</div>اختر موقعًا من القائمة أعلى الصفحة أولًا.</div>
        </div>
        <div class="p-card no-pad" id="ldTableWrap">
            <div class="p-table-scroll"><table class="p-table" id="leadsTable">
                <thead><tr>
                    <th>العميل</th><th>القناة</th><th>الاهتمام</th><th>الوجهة</th>
                    <th>Lead Score</th><th>النية</th><th>الحالة</th><th>آخر تفاعل</th><th></th>
                </tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr></tbody>
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
        ['new', 'جديد'], ['contacted', 'تم التواصل'], ['qualified', 'مؤهّل'],
        ['proposal_sent', 'تم إرسال عرض سعر'], ['won', 'تم الفوز به'], ['lost', 'فاقد'],
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
        if (res.success) toast('تم تحديث الحالة', 'success');
        else { toast(res.error || 'فشل التحديث', 'error'); load(); }
    };

    window.load = async function () {
        const id = ensureWebsite();
        if (!id) return;

        const status = document.getElementById('ldStatus').value;
        const qs = status ? ('?status=' + encodeURIComponent(status)) : '';

        const tbody = document.querySelector('#leadsTable tbody');
        tbody.innerHTML = '<tr class="p-loading-row"><td colspan="9">جاري التحميل...</td></tr>';

        const res = await fetchJSON('/api/ai-chat/websites/' + id + '/leads' + qs);
        if (!res.success) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">⚠️ ' + esc(res.error || 'تعذر التحميل') + '</td></tr>';
            return;
        }

        const leads = res.data.leads || [];
        if (!leads.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-cell-muted text-center">لا توجد Leads بعد</td></tr>';
            return;
        }

        tbody.innerHTML = leads.map(l => {
            const statusSelect = '<select class="p-select" style="min-width:130px;" onchange="ldUpdateStatus(' + l.id + ', this.value)">' +
                STATUS_OPTIONS.map(([v, label]) => `<option value="${v}" ${l.status === v ? 'selected' : ''}>${label}</option>`).join('') + '</select>';
            return `
                <tr>
                    <td>${esc(l.name || l.phone || 'غير معروف')}</td>
                    <td>${esc(l.channel || '-')}</td>
                    <td>${esc(l.interest || '-')}</td>
                    <td>${esc(l.destination || '-')}</td>
                    <td><span class="pill ${l.lead_score >= 70 ? 'red' : l.lead_score >= 40 ? 'blue' : 'gray'}">${l.lead_score ?? '-'}</span></td>
                    <td>${l.intent_score ?? '-'}</td>
                    <td>${statusSelect}</td>
                    <td class="p-cell-muted">${P.timeAgo(l.last_interaction_at)}</td>
                    <td><a href="/chat/conversation/${l.conversation_id}" class="p-btn outline xs">فتح المحادثة</a></td>
                </tr>`;
        }).join('');
    };

    window.addEventListener('tourfecto:website-changed', load);
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_chat_leads', 'Leads', 'كل العملاء المحتملين مرتّبين حسب الأولوية', $this->applyChatUi($body), $script);
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
            return $this->success([], 'تم حذف الرسالة');
        } catch (Exception $e) {
            Logger::error('Delete Message Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حذف الرسالة', 500);
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
            return $this->error('معرف الرسالة مطلوب', 422);
        }

        try {
            $message = (new ChatMessage())->find($messageId);
            if (!$message || (int) $message->getAttribute('user_id') !== (int) $this->user['id']) {
                return $this->error('الرسالة غير موجودة', 404);
            }

            $engine = new AutoReplyEngine();
            $reply = $engine->generateReply(
                (string) $message->getAttribute('message_text'),
                (int) $this->user['id'],
                ['customer_name' => $message->getAttribute('customer_name')],
                ['auto_pilot' => false]
            );

            if (!$reply) {
                return $this->error('تعذر توليد رد - جرّب تاني بعد شوية', 502);
            }

            $message->setAttribute('ai_reply_generated', $reply);
            $message->save();

            return $this->success(['reply' => $reply], 'تم توليد رد جديد');
        } catch (Exception $e) {
            Logger::error('Generate Chat Reply Error', ['message_id' => $messageId, 'message' => $e->getMessage()]);
            return $this->error('تعذر توليد الرد', 500);
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
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
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
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $instanceId = trim((string) $this->get('instance_id', ''));
        $apiKey = trim((string) $this->get('api_key', ''));

        if (!$websiteId || !$instanceId || !$apiKey) {
            return $this->error('الموقع ومعرف المثيل والتوكن مطلوبين', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        // تحقق فعلي إن البيانات صحيحة قبل الحفظ (بدل ما نحفظ ونكتشف بعدين إنها غلط)
        $api = new UltraMsgAPI($instanceId, $apiKey);
        $statusResult = $api->getInstanceStatus();
        if (!$statusResult['success']) {
            return $this->error('تعذر التحقق من بيانات UltraMsg: ' . ($statusResult['error'] ?? ''), 422);
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
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log('UltraMsg Connected', ['website_id' => $websiteId, 'instance_id' => $instanceId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/ultramsg/' . $websiteId . '?secret=' . $this->ultraMsgWebhookSecret($websiteId),
            ], 'تم ربط UltraMsg بنجاح');
        } catch (Exception $e) {
            Logger::error('Connect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    /** POST /api/chat/disconnect/ultramsg/{website_id} */
    public function disconnectUltraMsg(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);

        try {
            $connections = (new PlatformConnection())->where(['website_id' => $websiteId, 'platform' => 'ultramsg']);
            foreach ($connections as $conn) {
                if ((int) $conn->getAttribute('user_id') === (int) $this->user['id']) {
                    $conn->delete();
                }
            }
            return $this->success([], 'تم فصل الربط');
        } catch (Exception $e) {
            Logger::error('Disconnect UltraMsg Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر فصل الربط', 500);
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
                return $this->success([], 'تم التجاهل (رسالة صادرة)');
            }

            $phone = $data['from'] ?? null;
            $text = $data['body'] ?? null;

            if (!$phone || !$text) {
                return $this->error('بيانات الرسالة غير مكتملة', 422);
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
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $accessToken = trim((string) $this->get('access_token', ''));
        $externalAccountId = trim((string) $this->get('page_id', ''));

        if (!$websiteId || !$accessToken) {
            return $this->error('الموقع والـaccess_token مطلوبين', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
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
                $connection->setAttribute('id', $existing[0]->getAttribute('id'));
            }
            $connection->save();

            $this->log(ucfirst($platform) . ' Connected', ['website_id' => $websiteId]);

            return $this->success([
                'webhook_url' => rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/api/chat/webhook/' . $platform . '/' . $websiteId
                    . '?secret=' . $this->channelWebhookSecret($websiteId, $platform),
                'verify_token' => $this->channelWebhookSecret($websiteId, $platform),
            ], 'تم ربط ' . $platform . ' بنجاح - استخدم webhook_url وverify_token عند إعداد الـWebhook في Meta for Developers');
        } catch (Exception $e) {
            Logger::error('Connect ' . $platform . ' Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
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
                return $this->success([], 'تم التجاهل (رسالة صادرة)');
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
                return $this->error('بيانات الرسالة غير مكتملة', 422);
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
