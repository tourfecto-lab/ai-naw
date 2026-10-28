<?php

/**
 * Tourfecto - AI Chat Platform
 * In-Chat Quotes Controller: بيع داخل الشات (عروض أسعار تُبنى وتُرسل
 * وتُتتبّع جوه المحادثة). مستوحى من عروض أسعار Intercom/Zendesk —
 * الموظف يبني عرضًا (بنود + أسعار)، يبعته للعميل عبر القناة الحالية،
 * ويتتبع حالته (مُرسل/مقبول/مرفوض/منتهي/ملغي).
 *
 * @version 1.0.0
 */

class AiQuoteController extends Controller
{
    /** @var AiQuote */
    private $quoteModel;

    public function __construct()
    {
        parent::__construct();
        $this->quoteModel = new AiQuote();
    }

    /**
     * قائمة عروض أسعار الموقع (فلاتر: conversation_id, status).
     * GET /api/ai-chat/websites/{id}/quotes
     */
    public function index(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $filters = array_filter([
            'conversation_id' => $this->get('conversation_id'),
            'status' => $this->get('status'),
        ], function ($v) {
            return $v !== null && $v !== '';
        });

        $quotes = $this->quoteModel->forWebsite((int) $website->getAttribute('id'), $filters);

        return $this->success([
            'quotes' => array_map([$this, 'serialize'], $quotes),
        ]);
    }

    /**
     * إنشاء عرض سعر جديد جوه محادثة.
     * POST /api/ai-chat/websites/{id}/quotes
     * Body: conversation_id, items [{name, qty, unit_price}], discount, currency, notes
     */
    public function store(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversationId = (int) $this->get('conversation_id', 0);
        $conversation = $this->authorizedConversation($conversationId, (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $items = $this->get('items', []);
        if (!is_array($items) || count($items) === 0) {
            return $this->error('items is required (array of {name, qty, unit_price})', 422);
        }

        $cleanItems = [];
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['name'])) {
                continue;
            }
            $qty = max(1, (int) ($it['qty'] ?? 1));
            $unitPrice = (float) ($it['unit_price'] ?? 0);
            $cleanItems[] = [
                'name' => trim((string) $it['name']),
                'qty' => $qty,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($unitPrice * $qty, 2),
            ];
        }
        if (count($cleanItems) === 0) {
            return $this->error('items must contain at least one valid item with a name', 422);
        }

        $subtotal = round(array_sum(array_column($cleanItems, 'line_total')), 2);
        $discount = round(max(0, (float) $this->get('discount', 0)), 2);
        $total = round($subtotal - $discount, 2);

        $websiteId = (int) $website->getAttribute('id');
        $quoteNumber = $this->quoteModel->nextQuoteNumber($websiteId);

        $lead = null;
        try {
            $leads = (new AiLead())->forWebsite($websiteId, ['conversation_id' => $conversationId]);
            if (is_array($leads) && count($leads) > 0) {
                $lead = $leads[0];
            }
        } catch (Throwable $e) {
            $lead = null;
        }

        $quote = new AiQuote();
        $quote->fill([
            'website_id' => $websiteId,
            'conversation_id' => $conversationId,
            'lead_id' => $lead ? (int) $lead->getAttribute('id') : null,
            'quote_number' => $quoteNumber,
            'customer_name' => $conversation->getAttribute('customer_name'),
            'customer_phone' => $conversation->getAttribute('customer_phone'),
            'customer_email' => $conversation->getAttribute('customer_email'),
            'channel' => $conversation->getAttribute('channel'),
            'items' => json_encode($cleanItems),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'currency' => strtoupper((string) $this->get('currency', 'USD')),
            'status' => 'draft',
            'notes' => $this->get('notes'),
            'created_by_user_id' => (int) ($this->user['id'] ?? 0),
        ]);
        $id = $quote->save();

        if (!$id) {
            return $this->error('Failed to create quote', 500);
        }

        $quote = $this->quoteModel->find($id);
        $this->inbox()->updateConversation($conversationId, ['last_message_at' => date('Y-m-d H:i:s')]);

        return $this->success(['quote' => $this->serialize($quote)], 'Quote created');
    }

    /**
     * تحديث عرض سعر (بنود/خصم/ملاحظات) أو حالته (accept/decline/cancel/expire).
     * PUT /api/ai-chat/websites/{id}/quotes/{quoteId}
     * Body: items[], discount, notes, status
     */
    public function update(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $quote = $this->authorizedQuote((int) ($params['quoteId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$quote) {
            return $this->error('Quote not found', 404);
        }

        $allowedStatuses = ['draft', 'sent', 'accepted', 'declined', 'expired', 'cancelled'];
        $status = $this->get('status');
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            return $this->error('Invalid quote status', 422);
        }

        $data = [];
        if ($status !== null) {
            $data['status'] = $status;
            if ($status === 'accepted' || $status === 'declined') {
                $data['responded_at'] = date('Y-m-d H:i:s');
            }
            if ($status === 'sent' && empty($quote->getAttribute('sent_at'))) {
                $data['sent_at'] = date('Y-m-d H:i:s');
            }
        }

        $items = $this->get('items');
        if (is_array($items) && count($items) > 0) {
            $cleanItems = [];
            foreach ($items as $it) {
                if (!is_array($it) || empty($it['name'])) {
                    continue;
                }
                $qty = max(1, (int) ($it['qty'] ?? 1));
                $unitPrice = (float) ($it['unit_price'] ?? 0);
                $cleanItems[] = [
                    'name' => trim((string) $it['name']),
                    'qty' => $qty,
                    'unit_price' => round($unitPrice, 2),
                    'line_total' => round($unitPrice * $qty, 2),
                ];
            }
            if (count($cleanItems) > 0) {
                $data['items'] = json_encode($cleanItems);
                $data['subtotal'] = round(array_sum(array_column($cleanItems, 'line_total')), 2);
                $data['discount'] = round(max(0, (float) $this->get('discount', (float) $quote->getAttribute('discount'))), 2);
                $data['total'] = round($data['subtotal'] - $data['discount'], 2);
            }
        } elseif ($this->get('discount') !== null) {
            $subtotal = (float) $quote->getAttribute('subtotal');
            $data['discount'] = round(max(0, (float) $this->get('discount')), 2);
            $data['total'] = round($subtotal - $data['discount'], 2);
        }

        $notes = $this->get('notes');
        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        if (empty($data)) {
            return $this->error('Nothing to update', 422);
        }

        $quote->fill($data);
        $quote->save();
        $updated = $this->quoteModel->find((int) $quote->getAttribute('id'));

        return $this->success(['quote' => $this->serialize($updated)], 'Quote updated');
    }

    /**
     * إرسال عرض السعر للعميل عبر القناة الحالية للمحادثة (WhatsApp/Email...)
     * وبناء رسالة نصية منسّقة بالبنود والإجمالي. يُسجَّل العرض كرسالة
     * outgoing في نفس المحادثة عشان يظهر جوه الثريد.
     * POST /api/ai-chat/websites/{id}/quotes/{quoteId}/send
     */
    public function send(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $quote = $this->authorizedQuote((int) ($params['quoteId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$quote) {
            return $this->error('Quote not found', 404);
        }

        $conversationId = (int) $quote->getAttribute('conversation_id');
        $conversation = $this->authorizedConversation($conversationId, (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $items = json_decode((string) $quote->getAttribute('items'), true) ?: [];
        $currency = (string) $quote->getAttribute('currency');
        $lines = [];
        foreach ($items as $it) {
            $lines[] = '- ' . $it['name'] . ' x' . $it['qty'] . ' = ' . number_format((float) $it['line_total'], 2) . ' ' . $currency;
        }
        $message = '🛎️ *عرض سعر ' . $quote->getAttribute('quote_number') . '*' . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . 'الإجمالي: *' . number_format((float) $quote->getAttribute('total'), 2) . ' ' . $currency . '*' . "\n\n"
            . 'إذا حابب الحجز أو عندك أي استفسار، رد علينا هنا مباشرة.';

        $channel = (string) $conversation->getAttribute('channel');
        $recipient = $channel === 'email'
            ? $conversation->getAttribute('customer_email')
            : $conversation->getAttribute('customer_phone');

        $sent = false;
        if ($recipient) {
            $chatManager = new ChatManager();
            $sent = $chatManager->sendMessageForWebsite((int) $website->getAttribute('id'), $recipient, $message, $channel);
        }

        // تسجيل العرض كرسالة outgoing داخل المحادثة
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform,
                customer_name, customer_phone, customer_email, message_direction, message_text,
                bot_status, is_auto_pilot, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'outgoing', ?, 'sent', 0, NOW(), NOW())",
            [
                $website->getAttribute('id'),
                $conversationId,
                (int) ($this->user['id'] ?? 0),
                'agent_' . $conversationId,
                $channel,
                $conversation->getAttribute('customer_name'),
                $conversation->getAttribute('customer_phone'),
                $conversation->getAttribute('customer_email'),
                $message,
            ]
        );

        $quote->fill([
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'customer_message' => $message,
        ]);
        $quote->save();
        $this->inbox()->updateConversation($conversationId, ['last_message_at' => date('Y-m-d H:i:s')]);

        $updated = $this->quoteModel->find((int) $quote->getAttribute('id'));

        return $this->success(['quote' => $this->serialize($updated), 'sent' => $sent], $sent ? 'Quote sent to customer' : 'Quote saved but delivery to channel failed');
    }

    /**
     * تسطيح كائن العرض لـ JSON آمن للعرض.
     * @param AiQuote $q
     * @return array
     */
    private function serialize($q): array
    {
        return [
            'id' => (int) $q->getAttribute('id'),
            'quote_number' => $q->getAttribute('quote_number'),
            'conversation_id' => (int) $q->getAttribute('conversation_id'),
            'channel' => $q->getAttribute('channel'),
            'items' => json_decode((string) $q->getAttribute('items'), true) ?: [],
            'subtotal' => (float) $q->getAttribute('subtotal'),
            'discount' => (float) $q->getAttribute('discount'),
            'total' => (float) $q->getAttribute('total'),
            'currency' => (string) $q->getAttribute('currency'),
            'status' => (string) $q->getAttribute('status'),
            'notes' => $q->getAttribute('notes'),
            'customer_message' => $q->getAttribute('customer_message'),
            'sent_at' => $q->getAttribute('sent_at'),
            'responded_at' => $q->getAttribute('responded_at'),
            'created_at' => $q->getAttribute('created_at'),
        ];
    }

    /**
     * @return UnifiedInboxService
     */
    private function inbox()
    {
        return new UnifiedInboxService();
    }

    /**
     * @param int $websiteId
     * @return Website|null
     */
    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }

    /**
     * @param int $conversationId
     * @param int $websiteId
     * @return AiChatConversation|null
     */
    private function authorizedConversation(int $conversationId, int $websiteId): ?AiChatConversation
    {
        if ($conversationId <= 0) {
            return null;
        }
        $conversation = (new AiChatConversation())->find($conversationId);
        if (!$conversation || (int) $conversation->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $conversation;
    }

    /**
     * جلب عرض سعر يخص الموقع المحدد.
     * @param int $quoteId
     * @param int $websiteId
     * @return AiQuote|null
     */
    private function authorizedQuote(int $quoteId, int $websiteId)
    {
        if ($quoteId <= 0) {
            return null;
        }
        $quote = $this->quoteModel->find($quoteId);
        if (!$quote || (int) $quote->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $quote;
    }
}
