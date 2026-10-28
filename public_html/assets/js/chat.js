/**
 * Tourfecto - Chat JavaScript
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

(function() {
    'use strict';

    // ============================================
    // 1. Chat State
    // ============================================
    const ChatState = {
        messages: [],
        currentSessionId: null,
        isConnected: false,
        isTyping: false,
        unreadCount: 0,
        pendingApprovals: []
    };

    // ============================================
    // 2. DOM Ready
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        initChat();
    });

    // ============================================
    // 3. Initialize Chat
    // ============================================
    function initChat() {
        initChatUI();
        initMessageHandling();
        initAutoReply();
        initApprovalSystem();
        initWebSocket();
        loadChatHistory();
    }

    // ============================================
    // 4. Chat UI
    // ============================================
    function initChatUI() {
        const chatContainer = document.querySelector('.chat-container');
        const messageInput = document.querySelector('.chat-input textarea');
        const sendButton = document.querySelector('.chat-send-btn');
        const chatMessages = document.querySelector('.chat-messages');

        // Auto-resize textarea
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });

            // Send on Enter (Shift+Enter for new line)
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    if (sendButton) sendButton.click();
                }
            });
        }

        // Send button
        if (sendButton && messageInput) {
            sendButton.addEventListener('click', function() {
                const message = messageInput.value.trim();
                if (message) {
                    sendMessage(message);
                    messageInput.value = '';
                    messageInput.style.height = 'auto';
                }
            });
        }

        // Scroll to bottom on new message
        if (chatMessages) {
            const observer = new MutationObserver(() => {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            });
            observer.observe(chatMessages, { childList: true });
        }
    }

    // ============================================
    // 5. Message Handling
    // ============================================
    function initMessageHandling() {
        // Message templates
        window.ChatTemplates = {
            userMessage: function(text, time) {
                return `
                    <div class="message message-user">
                        <div class="message-content">${escapeHtml(text)}</div>
                        <div class="message-time">${time || 'الآن'}</div>
                    </div>
                `;
            },
            botMessage: function(text, time, status = 'sent') {
                const statusClass = status === 'pending_approval' ? 'pending' : status;
                return `
                    <div class="message message-bot ${statusClass}">
                        <div class="message-content">${escapeHtml(text)}</div>
                        <div class="message-time">${time || 'الآن'}</div>
                        ${status === 'pending_approval' ? '<div class="message-status">⏳ في انتظار الموافقة</div>' : ''}
                    </div>
                `;
            },
            typingIndicator: function() {
                return `
                    <div class="message message-bot typing">
                        <div class="message-content">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </div>
                    </div>
                `;
            },
            approvalButtons: function(messageId) {
                return `
                    <div class="approval-buttons" data-message-id="${messageId}">
                        <button class="btn btn-success btn-sm approve-btn">✅ موافقة</button>
                        <button class="btn btn-danger btn-sm reject-btn">❌ رفض</button>
                    </div>
                `;
            }
        };
    }

    // ============================================
    // 6. Send Message
    // ============================================
    function sendMessage(message) {
        const chatMessages = document.querySelector('.chat-messages');
        if (!chatMessages) return;

        // Add user message to UI
        const userMessage = ChatTemplates.userMessage(message);
        chatMessages.insertAdjacentHTML('beforeend', userMessage);

        // Show typing indicator
        const typingIndicator = ChatTemplates.typingIndicator();
        chatMessages.insertAdjacentHTML('beforeend', typingIndicator);

        // Send to server
        fetch('/api/chat/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                message: message,
                session_id: ChatState.currentSessionId
            })
        })
        .then(response => response.json())
        .then(data => {
            // Remove typing indicator
            const typing = chatMessages.querySelector('.typing');
            if (typing) typing.remove();

            if (data.success) {
                // Add bot response
                const botMessage = ChatTemplates.botMessage(
                    data.reply,
                    null,
                    data.bot_status || 'sent'
                );
                chatMessages.insertAdjacentHTML('beforeend', botMessage);

                if (data.bot_status === 'pending_approval') {
                    // Add approval buttons
                    const approvalBtns = ChatTemplates.approvalButtons(data.message_id);
                    chatMessages.insertAdjacentHTML('beforeend', approvalBtns);
                    initApprovalButtons();
                }
            } else {
                // Show error
                Tourfecto.showToast(data.error || 'حدث خطأ في الإرسال', 'error');
            }
        })
        .catch(error => {
            const typing = chatMessages.querySelector('.typing');
            if (typing) typing.remove();
            Tourfecto.showToast('حدث خطأ في الاتصال', 'error');
            console.error('Send message error:', error);
        });
    }

    // ============================================
    // 7. Auto Reply
    // ============================================
    function initAutoReply() {
        const autoReplyToggle = document.querySelector('.auto-reply-toggle');
        if (autoReplyToggle) {
            autoReplyToggle.addEventListener('change', function() {
                fetch('/api/chat/settings', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken()
                    },
                    body: JSON.stringify({
                        auto_pilot: this.checked
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Tourfecto.showToast('تم تحديث إعدادات الرد التلقائي', 'success');
                    }
                })
                .catch(error => {
                    console.error('Update auto reply error:', error);
                });
            });
        }
    }

    // ============================================
    // 8. Approval System
    // ============================================
    function initApprovalSystem() {
        initApprovalButtons();
        loadPendingApprovals();
    }

    function initApprovalButtons() {
        document.querySelectorAll('.approve-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const messageId = this.closest('.approval-buttons').dataset.messageId;
                handleApproval(messageId, 'approve');
            });
        });

        document.querySelectorAll('.reject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const messageId = this.closest('.approval-buttons').dataset.messageId;
                handleApproval(messageId, 'reject');
            });
        });
    }

    function handleApproval(messageId, action) {
        const buttons = document.querySelector(`.approval-buttons[data-message-id="${messageId}"]`);
        if (buttons) {
            buttons.innerHTML = '<span class="text-muted">جاري المعالجة...</span>';
        }

        fetch('/api/chat/approve', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                message_id: messageId,
                action: action
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Tourfecto.showToast(
                    action === 'approve' ? 'تمت الموافقة على الرد' : 'تم رفض الرد',
                    'success'
                );
                if (buttons) buttons.remove();
                loadPendingApprovals();
            } else {
                Tourfecto.showToast(data.error || 'حدث خطأ', 'error');
                if (buttons) {
                    buttons.innerHTML = `
                        <button class="btn btn-success btn-sm approve-btn">✅ موافقة</button>
                        <button class="btn btn-danger btn-sm reject-btn">❌ رفض</button>
                    `;
                    initApprovalButtons();
                }
            }
        })
        .catch(error => {
            console.error('Approval error:', error);
            Tourfecto.showToast('حدث خطأ في الاتصال', 'error');
        });
    }

    function loadPendingApprovals() {
        const container = document.querySelector('.pending-approvals');
        if (!container) return;

        fetch('/api/chat/pending')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    ChatState.pendingApprovals = data.pending;
                    renderPendingApprovals(data.pending);
                    
                    const count = data.pending.length || 0;
                    const badge = document.querySelector('.pending-badge');
                    if (badge) {
                        badge.textContent = count;
                        badge.style.display = count > 0 ? 'inline' : 'none';
                    }
                }
            })
            .catch(error => {
                console.error('Load pending approvals error:', error);
            });
    }

    function renderPendingApprovals(approvals) {
        const container = document.querySelector('.pending-approvals');
        if (!container) return;

        if (approvals.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">لا توجد رسائل في انتظار الموافقة</p>';
            return;
        }

        let html = '';
        approvals.forEach(approval => {
            html += `
                <div class="pending-item" data-message-id="${approval.id}">
                    <div class="pending-header">
                        <strong>${escapeHtml(approval.customer_name || 'عميل')}</strong>
                        <span class="text-muted">${Tourfecto.timeAgo(approval.created_at)}</span>
                    </div>
                    <div class="pending-message">${escapeHtml(approval.message_text)}</div>
                    <div class="pending-reply">🤖 ${escapeHtml(approval.ai_reply_generated)}</div>
                    <div class="pending-actions">
                        <button class="btn btn-success btn-sm approve-btn">✅ موافقة</button>
                        <button class="btn btn-danger btn-sm reject-btn">❌ رفض</button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
        initApprovalButtons();
    }

    // ============================================
    // 9. WebSocket (Real-time)
    // ============================================
    function initWebSocket() {
        // WebSocket connection for real-time updates
        if (window.WebSocket && document.querySelector('.chat-websocket')) {
            const ws = new WebSocket('wss://' + window.location.host + '/ws/chat');

            ws.onopen = function() {
                ChatState.isConnected = true;
                console.log('WebSocket connected');
            };

            ws.onmessage = function(event) {
                const data = JSON.parse(event.data);
                handleWebSocketMessage(data);
            };

            ws.onclose = function() {
                ChatState.isConnected = false;
                console.log('WebSocket disconnected');
                // Try to reconnect after 5 seconds
                setTimeout(initWebSocket, 5000);
            };

            ws.onerror = function(error) {
                console.error('WebSocket error:', error);
            };
        }
    }

    function handleWebSocketMessage(data) {
        switch (data.type) {
            case 'new_message':
                // Add new message to chat
                if (data.message) {
                    const chatMessages = document.querySelector('.chat-messages');
                    if (chatMessages) {
                        const msg = ChatTemplates.botMessage(
                            data.message,
                            null,
                            data.status || 'sent'
                        );
                        chatMessages.insertAdjacentHTML('beforeend', msg);
                    }
                }
                break;

            case 'pending_approval':
                // Update pending approvals
                loadPendingApprovals();
                Tourfecto.showToast('📩 رسالة جديدة في انتظار الموافقة', 'info');
                break;

            case 'typing':
                // Show typing indicator
                if (data.is_typing) {
                    const chatMessages = document.querySelector('.chat-messages');
                    if (chatMessages && !chatMessages.querySelector('.typing')) {
                        chatMessages.insertAdjacentHTML('beforeend', ChatTemplates.typingIndicator());
                    }
                } else {
                    const typing = document.querySelector('.typing');
                    if (typing) typing.remove();
                }
                break;

            default:
                console.log('Unknown WebSocket message:', data);
        }
    }

    // ============================================
    // 10. Load Chat History
    // ============================================
    function loadChatHistory() {
        const sessionId = getUrlParam('session_id');
        if (sessionId) {
            ChatState.currentSessionId = sessionId;
            fetch(`/api/chat/conversation/${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.messages) {
                        renderChatHistory(data.messages);
                    }
                })
                .catch(error => {
                    console.error('Load chat history error:', error);
                });
        }
    }

    function renderChatHistory(messages) {
        const chatMessages = document.querySelector('.chat-messages');
        if (!chatMessages) return;

        chatMessages.innerHTML = '';
        messages.forEach(msg => {
            if (msg.message_direction === 'incoming') {
                chatMessages.insertAdjacentHTML('beforeend', 
                    ChatTemplates.botMessage(msg.message_text, msg.created_at, msg.bot_status)
                );
            } else {
                chatMessages.insertAdjacentHTML('beforeend', 
                    ChatTemplates.userMessage(msg.message_text, msg.created_at)
                );
            }
        });

        // Scroll to bottom
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // ============================================
    // 11. Utility Functions
    // ============================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function getUrlParam(name) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get(name);
    }

    // ============================================
    // 12. Export Chat Functions
    // ============================================
    window.Chat = {
        sendMessage: sendMessage,
        loadPendingApprovals: loadPendingApprovals,
        getState: function() { return ChatState; },
        setSessionId: function(id) { ChatState.currentSessionId = id; }
    };

})();