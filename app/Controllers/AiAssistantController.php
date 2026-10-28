<?php

/**
 * Tourfecto - AI Assistant Controller
 * مساعد ذكاء اصطناعي عام بواجهة شات، بهوية المنصة بالكامل
 * @version 1.0.0
 */
class AiAssistantController extends Controller
{
    /** @var AiAssistantService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AiAssistantService();
    }

    /** GET /ai-assistant */
    public function index(array $params = []): array
    {
        $tNewConv = $this->tr('assistant.new_conversation');
        $tLoading = $this->tr('common.loading');
        $tWelcomeDesc = $this->tr('assistant.welcome_desc');
        $tPlaceholder = $this->tr('assistant.input_placeholder');
        $tSearchPlaceholder = $this->tr('assistant.search_placeholder');
        $tQuickStart = $this->tr('assistant.quick_start_title');
        $tInputHint = $this->tr('assistant.input_hint');
        $planBadge = class_exists('PlanUsage') ? PlanUsage::badgeHtml((int) ($this->user['id'] ?? 0), 'ai_assistant_message') : '';
        $tChipPackage = $this->tr('assistant.chip_package');
        $tChipReview = $this->tr('assistant.chip_review');
        $tChipSocial = $this->tr('assistant.chip_social');
        $tChipTranslate = $this->tr('assistant.chip_translate');

        $body = <<<HTML
        <div class="assistant-shell">
            <aside class="assistant-sidebar">
                <button class="p-btn primary btn-block" onclick="newConversation()">+ {$tNewConv}</button>
                <div class="assistant-search">
                    <span class="assistant-search-icon">🔍</span>
                    <input type="text" id="convSearchInput" placeholder="{$tSearchPlaceholder}" oninput="filterConversations()">
                </div>
                <div id="conversationsList" class="assistant-conv-list"><div class="p-empty">{$tLoading}</div></div>
            </aside>
            <main class="assistant-main">
                <div id="assistantWelcome" class="assistant-welcome">
                    <div class="assistant-welcome-icon">✨</div>
                    <h2>{$this->tr('assistant.title')}</h2>
                    <p>{$tWelcomeDesc}</p>
                    <div class="assistant-chips">
                        <div class="assistant-chips-title">{$tQuickStart}</div>
                        <div class="assistant-chips-grid">
                            <button type="button" class="assistant-chip" onclick="sendQuickPrompt(this)" data-prompt="{$tChipPackage}"><span class="assistant-chip-ic">📦</span>{$tChipPackage}</button>
                            <button type="button" class="assistant-chip" onclick="sendQuickPrompt(this)" data-prompt="{$tChipReview}"><span class="assistant-chip-ic">⭐</span>{$tChipReview}</button>
                            <button type="button" class="assistant-chip" onclick="sendQuickPrompt(this)" data-prompt="{$tChipSocial}"><span class="assistant-chip-ic">📱</span>{$tChipSocial}</button>
                            <button type="button" class="assistant-chip" onclick="sendQuickPrompt(this)" data-prompt="{$tChipTranslate}"><span class="assistant-chip-ic">🌐</span>{$tChipTranslate}</button>
                        </div>
                    </div>
                </div>
                <div id="messagesArea" class="assistant-messages" style="display:none;"></div>
                <div class="assistant-input-box">
                    <textarea id="messageInput" rows="1" placeholder="{$tPlaceholder}" onkeydown="handleInputKeydown(event)" oninput="autoResizeInput(this)"></textarea>
                    <button id="sendBtn" class="p-btn primary" onclick="handleSendBtnClick()">➤</button>
                </div>
                <div class="assistant-input-hint">{$tInputHint}</div>
                {$planBadge}
                <div id="assistantAlert" class="alert alert-danger" style="display:none;margin-top:10px;"></div>
            </main>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let currentConversationId = null;
    let isBusy = false;
    let cachedConversations = [];
    let activeAbortController = null;
    let revealTimer = null;
    let pendingRevealEl = null;
    let pendingRevealText = null;

    // ============ تنسيق Markdown خفيف وآمن (بيهرب HTML الأول دايمًا) ============
    function mdToHtml(raw) {
        const text0 = raw == null ? '' : String(raw);
        const codeBlocks = [];

        // اسحب كتل الكود الأول قبل أي معالجة تانية عشان مبنكسرش لو فيها أسطر/رموز خاصة
        let text = text0.replace(/```[a-zA-Z0-9_-]*\n?([\s\S]*?)```/g, function (_, code) {
            const idx = codeBlocks.length;
            codeBlocks.push(esc(code.replace(/\n$/, '')));
            return '\u0000CB' + idx + '\u0000';
        });

        text = esc(text);
        text = text.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/(^|\s)_([^_\n]+)_(?=\s|$)/g, '$1<em>$2</em>');
        text = text.replace(/(https?:\/\/[^\s<]+)/g, function (url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
        });

        const lines = text.split('\n');
        let html = '';
        let inUl = false, inOl = false;
        lines.forEach(function (line) {
            const ulMatch = line.match(/^\s*[-*]\s+(.+)$/);
            const olMatch = line.match(/^\s*\d+[.)]\s+(.+)$/);
            const headingMatch = line.match(/^#{1,4}\s+(.+)$/);
            if (ulMatch) {
                if (inOl) { html += '</ol>'; inOl = false; }
                if (!inUl) { html += '<ul>'; inUl = true; }
                html += '<li>' + ulMatch[1] + '</li>';
            } else if (olMatch) {
                if (inUl) { html += '</ul>'; inUl = false; }
                if (!inOl) { html += '<ol>'; inOl = true; }
                html += '<li>' + olMatch[1] + '</li>';
            } else {
                if (inUl) { html += '</ul>'; inUl = false; }
                if (inOl) { html += '</ol>'; inOl = false; }
                if (headingMatch) {
                    html += '<div class="assistant-md-heading">' + headingMatch[1] + '</div>';
                } else if (line.trim() === '') {
                    html += '<br>';
                } else {
                    html += line + '<br>';
                }
            }
        });
        if (inUl) html += '</ul>';
        if (inOl) html += '</ol>';

        html = html.replace(/\u0000CB(\d+)\u0000/g, function (_, idx) {
            return '<pre class="assistant-code-block"><code>' + codeBlocks[idx] + '</code></pre>';
        });

        return html;
    }

    function scrollToBottom() {
        const area = document.getElementById('messagesArea');
        area.scrollTop = area.scrollHeight;
    }

    function scrollToBottomIfNear() {
        const area = document.getElementById('messagesArea');
        if (area.scrollHeight - area.scrollTop - area.clientHeight < 140) {
            area.scrollTop = area.scrollHeight;
        }
    }

    function setBusyState(busy) {
        isBusy = busy;
        const btn = document.getElementById('sendBtn');
        const input = document.getElementById('messageInput');
        if (busy) {
            btn.innerHTML = '⏹';
            btn.classList.add('is-stop');
        } else {
            btn.innerHTML = '➤';
            btn.classList.remove('is-stop');
            input.disabled = false;
        }
    }

    // بيكشف الرد بتدريجيًا (تأثير كتابة حي) بدل ما يظهر مرة واحدة - إحساس
    // "حي" حتى لو الطلب فعليًا كان استجابة واحدة كاملة من السيرفر.
    function revealText(el, rawText, onDone) {
        let i = 0;
        const total = rawText.length;
        const chunkSize = Math.max(1, Math.round(total / 90));
        pendingRevealEl = el;
        pendingRevealText = rawText;

        revealTimer = setInterval(function () {
            i += chunkSize;
            if (i >= total) {
                clearInterval(revealTimer);
                revealTimer = null;
                pendingRevealEl = null;
                pendingRevealText = null;
                el.innerHTML = mdToHtml(rawText);
                scrollToBottomIfNear();
                if (onDone) onDone();
                return;
            }
            el.innerHTML = mdToHtml(rawText.slice(0, i)) + '<span class="assistant-caret"></span>';
            scrollToBottomIfNear();
        }, 14);
    }

    function stopGeneration() {
        if (revealTimer) {
            clearInterval(revealTimer);
            revealTimer = null;
            if (pendingRevealEl && pendingRevealText != null) {
                pendingRevealEl.innerHTML = mdToHtml(pendingRevealText);
            }
            pendingRevealEl = null;
            pendingRevealText = null;
            setBusyState(false);
        } else if (activeAbortController) {
            activeAbortController.abort();
        }
    }

    window.handleSendBtnClick = function () {
        if (isBusy) {
            stopGeneration();
        } else {
            sendMessage();
        }
    };

    // ============ المحادثات (القائمة الجانبية) ============
    async function loadConversations() {
        const res = await fetchJSON('/api/ai-assistant/conversations');
        const box = document.getElementById('conversationsList');
        if (!res.success || !res.data.conversations || !res.data.conversations.length) {
            cachedConversations = [];
            box.innerHTML = '<div class="p-cell-muted" style="padding:10px;font-size:12px;">' + I18N['assistant.no_conversations'] + '</div>';
            return;
        }
        cachedConversations = res.data.conversations;
        const searchInput = document.getElementById('convSearchInput');
        if (searchInput && searchInput.value.trim()) {
            filterConversations();
        } else {
            renderConversationsList(cachedConversations);
        }
    }

    function renderConversationsList(list) {
        const box = document.getElementById('conversationsList');
        if (!list.length) {
            box.innerHTML = '<div class="p-cell-muted" style="padding:10px;font-size:12px;">' + I18N['assistant.no_search_results'] + '</div>';
            return;
        }
        box.innerHTML = list.map(function (c) {
            return '<div class="assistant-conv-item' + (c.id == currentConversationId ? ' active' : '') + '" onclick="openConversation(' + c.id + ')">' +
                '<span class="assistant-conv-title">' + esc(c.title) + '</span>' +
                '<span class="assistant-conv-actions">' +
                    '<button class="conv-action conv-rename" onclick="event.stopPropagation();renameConversationPrompt(' + c.id + ')" title="' + esc(I18N['assistant.rename'] || '') + '">✎</button>' +
                    '<button class="conv-action conv-delete" onclick="event.stopPropagation();deleteConversation(' + c.id + ')">×</button>' +
                '</span>' +
            '</div>';
        }).join('');
    }

    window.filterConversations = function () {
        const input = document.getElementById('convSearchInput');
        const q = input.value.trim().toLowerCase();
        if (!q) { renderConversationsList(cachedConversations); return; }
        const filtered = cachedConversations.filter(function (c) {
            return (c.title || '').toLowerCase().indexOf(q) !== -1;
        });
        renderConversationsList(filtered);
    };

    window.renameConversationPrompt = async function (id) {
        const conv = cachedConversations.find(function (c) { return c.id == id; });
        const newTitle = window.prompt(I18N['assistant.rename_prompt'], conv ? conv.title : '');
        if (newTitle === null) return;
        const trimmed = newTitle.trim();
        if (!trimmed) return;

        const res = await fetchJSON('/api/ai-assistant/conversations/' + id, {
            method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ title: trimmed }),
        });
        if (res.success) {
            loadConversations();
        } else {
            toast(res.error || I18N['assistant.rename_failed'], 'error');
        }
    };

    window.newConversation = async function () {
        const res = await fetchJSON('/api/ai-assistant/conversations', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) {
            currentConversationId = res.data.conversation.id;
            document.getElementById('assistantWelcome').style.display = 'none';
            document.getElementById('messagesArea').style.display = 'flex';
            document.getElementById('messagesArea').innerHTML = '';
            document.getElementById('assistantAlert').style.display = 'none';
            loadConversations();
            document.getElementById('messageInput').focus();
        }
    };

    window.openConversation = async function (id) {
        if (isBusy) stopGeneration();
        currentConversationId = id;
        document.getElementById('assistantWelcome').style.display = 'none';
        document.getElementById('assistantAlert').style.display = 'none';
        const area = document.getElementById('messagesArea');
        area.style.display = 'flex';
        area.innerHTML = '<div class="p-empty">' + I18N['common.loading'] + '</div>';
        loadConversations();

        const res = await fetchJSON('/api/ai-assistant/conversations/' + id + '/messages');
        if (res.success && res.data.messages) {
            renderMessages(res.data.messages);
        }
    };

    window.deleteConversation = async function (id) {
        if (!confirm(I18N['assistant.delete_confirm'])) return;
        const res = await fetchJSON('/api/ai-assistant/conversations/' + id, { method: 'DELETE' });
        if (res.success) {
            if (id == currentConversationId) {
                currentConversationId = null;
                document.getElementById('assistantWelcome').style.display = 'flex';
                document.getElementById('messagesArea').style.display = 'none';
            }
            loadConversations();
        }
    };

    // ============ الرسائل ============
    function renderUserBubble(text) {
        return '<div class="assistant-msg user"><div class="assistant-msg-bubble">' + esc(text).replace(/\n/g, '<br>') + '</div></div>';
    }

    function renderTypingIndicator(id) {
        return '<div class="assistant-msg assistant" id="' + id + '">' +
            '<div class="assistant-msg-bubble assistant-typing"><span></span><span></span><span></span></div>' +
        '</div>';
    }

    function renderMessages(messages) {
        const area = document.getElementById('messagesArea');
        let lastAssistantIndex = -1;
        messages.forEach(function (m, idx) { if (m.role === 'assistant') lastAssistantIndex = idx; });
        const isLastMessageAssistant = messages.length > 0 && messages[messages.length - 1].role === 'assistant';

        area.innerHTML = messages.map(function (m, idx) {
            if (m.role === 'user') {
                return renderUserBubble(m.content);
            }
            const showRegenerate = isLastMessageAssistant && idx === lastAssistantIndex;
            return '<div class="assistant-msg assistant">' +
                '<div class="assistant-msg-bubble" data-raw="' + esc(m.content) + '">' + mdToHtml(m.content) + '</div>' +
                '<div class="assistant-msg-actions">' +
                    '<button class="assistant-action-btn" onclick="copyMessage(this)" title="' + esc(I18N['assistant.copy'] || '') + '">📋</button>' +
                    (showRegenerate ? '<button class="assistant-action-btn assistant-action-regenerate" onclick="regenerateMessage()" title="' + esc(I18N['assistant.regenerate'] || '') + '">🔄</button>' : '') +
                '</div>' +
            '</div>';
        }).join('');
        area.scrollTop = area.scrollHeight;
    }

    function appendAssistantBubbleWithReveal(rawText) {
        const area = document.getElementById('messagesArea');
        // زرار "إعادة توليد" متاح بس لآخر رد - امسحه من أي رد قديم
        area.querySelectorAll('.assistant-action-regenerate').forEach(function (b) { b.remove(); });

        const wrapId = 'msg_' + Date.now();
        area.insertAdjacentHTML('beforeend',
            '<div class="assistant-msg assistant" id="' + wrapId + '">' +
                '<div class="assistant-msg-bubble" id="' + wrapId + '_content"></div>' +
                '<div class="assistant-msg-actions">' +
                    '<button class="assistant-action-btn" onclick="copyMessage(this)" title="' + esc(I18N['assistant.copy'] || '') + '">📋</button>' +
                    '<button class="assistant-action-btn assistant-action-regenerate" onclick="regenerateMessage()" title="' + esc(I18N['assistant.regenerate'] || '') + '">🔄</button>' +
                '</div>' +
            '</div>');

        const contentEl = document.getElementById(wrapId + '_content');
        contentEl.dataset.raw = rawText;
        scrollToBottom();
        revealText(contentEl, rawText, function () { setBusyState(false); });
    }

    window.copyMessage = function (btn) {
        const bubble = btn.closest('.assistant-msg').querySelector('.assistant-msg-bubble');
        const text = bubble.dataset.raw || bubble.innerText;
        navigator.clipboard.writeText(text).then(function () {
            toast(I18N['assistant.copied'], 'success');
        }).catch(function () {
            toast(I18N['assistant.copy'], 'error');
        });
    };

    window.regenerateMessage = async function () {
        if (isBusy || !currentConversationId) return;
        setBusyState(true);
        document.getElementById('messageInput').disabled = true;

        const area = document.getElementById('messagesArea');
        const bubbles = area.querySelectorAll('.assistant-msg.assistant');
        const lastBubble = bubbles[bubbles.length - 1];
        if (lastBubble) lastBubble.remove();

        const typingId = 'typing_' + Date.now();
        area.insertAdjacentHTML('beforeend', renderTypingIndicator(typingId));
        scrollToBottom();

        const res = await fetchJSON('/api/ai-assistant/conversations/' + currentConversationId + '/regenerate', { method: 'POST' });
        document.getElementById(typingId)?.remove();

        if (res.success) {
            appendAssistantBubbleWithReveal(res.data.reply);
            if (window.refreshPlanUsage) window.refreshPlanUsage();
        } else {
            setBusyState(false);
            const alertBox = document.getElementById('assistantAlert');
            if (res.data && res.data.shortfall) {
                alertBox.textContent = I18N['assistant.insufficient_balance'].replace('{amount}', res.data.shortfall);
            } else {
                alertBox.textContent = res.error || I18N['assistant.regenerate_failed'];
            }
            alertBox.style.display = 'block';
        }
    };

    window.handleInputKeydown = function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    };

    window.autoResizeInput = function (el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    };

    window.sendQuickPrompt = function (btn) {
        if (isBusy) return;
        const prompt = btn.dataset.prompt || btn.textContent.trim();
        sendMessage(prompt);
    };

    window.sendMessage = async function (overrideText) {
        const input = document.getElementById('messageInput');
        const text = (typeof overrideText === 'string' ? overrideText : input.value.trim());
        if (!text || isBusy) return;

        if (!currentConversationId) {
            await newConversation();
        }

        const alertBox = document.getElementById('assistantAlert');
        alertBox.style.display = 'none';
        setBusyState(true);
        input.disabled = true;

        const area = document.getElementById('messagesArea');
        area.insertAdjacentHTML('beforeend', renderUserBubble(text));
        const typingId = 'typing_' + Date.now();
        area.insertAdjacentHTML('beforeend', renderTypingIndicator(typingId));
        scrollToBottom();
        input.value = '';
        autoResizeInput(input);

        activeAbortController = new AbortController();
        let res;
        try {
            res = await fetchJSON('/api/ai-assistant/conversations/' + currentConversationId + '/messages', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text }), signal: activeAbortController.signal,
            });
        } catch (e) {
            res = { success: false, error: I18N['assistant.send_failed'] };
        }
        activeAbortController = null;
        document.getElementById(typingId)?.remove();

        if (res.success) {
            appendAssistantBubbleWithReveal(res.data.reply);
            if (window.refreshPlanUsage) window.refreshPlanUsage();
        } else {
            setBusyState(false);
            if (res.data && res.data.shortfall) {
                alertBox.textContent = I18N['assistant.insufficient_balance'].replace('{amount}', res.data.shortfall);
            } else {
                alertBox.textContent = res.error || I18N['assistant.send_failed'];
            }
            alertBox.style.display = 'block';
        }

        loadConversations();
    };

    loadConversations();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_assistant', $this->tr('assistant.title'), $this->tr('assistant.subtitle'), $body, $script);
        exit;
    }

    /** GET /api/ai-assistant/conversations */
    public function listConversations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $conversations = $this->service->getConversations((int) $this->user['id']);
            return $this->success(['conversations' => array_map(fn ($c) => $c->toArray(), $conversations)]);
        } catch (Exception $e) {
            Logger::error('listConversations Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب المحادثات', 500);
        }
    }

    /** POST /api/ai-assistant/conversations */
    public function createConversation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $conversation = $this->service->createConversation((int) $this->user['id']);
            return $this->success(['conversation' => $conversation->toArray()], 'تم الإنشاء', 201);
        } catch (Exception $e) {
            Logger::error('createConversation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الإنشاء', 500);
        }
    }

    /** GET /api/ai-assistant/conversations/{id}/messages */
    public function getMessages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->ownsConversation($conversationId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $messages = $this->service->getMessages($conversationId);
            return $this->success(['messages' => array_map(fn ($m) => $m->toArray(), $messages)]);
        } catch (Exception $e) {
            Logger::error('getMessages Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب الرسائل', 500);
        }
    }

    /** POST /api/ai-assistant/conversations/{id}/messages */
    public function sendMessage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->ownsConversation($conversationId)) {
            return $this->error('غير مصرح', 403);
        }

        if (!$this->validate(['message' => 'required'])) {
            return $this->error('اكتب رسالة الأول', 422);
        }

        $result = $this->service->sendMessage((int) $this->user['id'], $conversationId, (string) $this->get('message'));

        if (!$result['success']) {
            return $this->error($result['error'], 402, $result);
        }

        return $this->success($result);
    }

    /** POST /api/ai-assistant/conversations/{id}/regenerate */
    public function regenerateMessage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->ownsConversation($conversationId)) {
            return $this->error('غير مصرح', 403);
        }

        $result = $this->service->regenerateLastResponse((int) $this->user['id'], $conversationId);

        if (!$result['success']) {
            $code = isset($result['shortfall']) ? 402 : 400;
            return $this->error($result['error'], $code, $result);
        }

        return $this->success($result);
    }

    /** PATCH /api/ai-assistant/conversations/{id} */
    public function renameConversation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->ownsConversation($conversationId)) {
            return $this->error('غير مصرح', 403);
        }

        if (!$this->validate(['title' => 'required'])) {
            return $this->error('اكتب اسم صحيح للمحادثة', 422);
        }

        try {
            $conversation = $this->service->renameConversation($conversationId, (string) $this->get('title'));
            return $this->success(['conversation' => $conversation->toArray()], 'تم التعديل');
        } catch (Exception $e) {
            Logger::error('renameConversation Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تعديل الاسم', 500);
        }
    }

    /** DELETE /api/ai-assistant/conversations/{id} */
    public function deleteConversation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $conversationId = (int) ($params['id'] ?? 0);
        if (!$this->ownsConversation($conversationId)) {
            return $this->error('غير مصرح', 403);
        }

        try {
            $this->service->deleteConversation($conversationId);
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->error('تعذر الحذف', 500);
        }
    }

    private function ownsConversation(int $conversationId): bool
    {
        if (!$conversationId) {
            return false;
        }
        $conversation = (new AiConversation())->find($conversationId);
        return $conversation && (int) $conversation->getAttribute('user_id') === (int) $this->user['id'];
    }
}
