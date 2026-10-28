<?php

/**
 * Tourfecto - AI Chat Platform
 * نموذج "عرض السعر" الذي يُبنى ويُرسل ويُتتبّع داخل المحادثة (بيع داخل الشات).
 * إضافة 2026-08-16: نفس نمط عروض أسعار Intercom/Zendesk — من غير ما يسيب
 * الموظف سياق المحادثة ليبني عرضًا ويبعته للعميل ويتتبع قبوله.
 *
 * @version 1.0.0
 */
class AiQuote extends Model
{
    protected $table = 'ai_quotes';

    protected $fillable = [
        'website_id', 'conversation_id', 'lead_id', 'quote_number',
        'customer_name', 'customer_phone', 'customer_email', 'channel',
        'items', 'subtotal', 'discount', 'total', 'currency',
        'status', 'notes', 'customer_message',
        'sent_at', 'responded_at', 'created_by_user_id',
    ];

    /**
     * قائمة عروض أسعار موقع (مع فلترة حسب المحادثة اختياريًا).
     * @param int $websiteId
     * @param array $filters ['conversation_id', 'status']
     * @return array
     */
    public function forWebsite(int $websiteId, array $filters = []): array
    {
        $conditions = ['website_id' => $websiteId];
        if (!empty($filters['conversation_id'])) {
            $conditions['conversation_id'] = (int) $filters['conversation_id'];
        }
        if (!empty($filters['status'])) {
            $conditions['status'] = $filters['status'];
        }
        return $this->where($conditions, ['created_at' => 'DESC']);
    }

    /**
     * توليد رقم مرجعي بشري فريد لعرض سعر.
     * @param int $websiteId
     * @return string
     */
    public function nextQuoteNumber(int $websiteId): string
    {
        $prefix = 'QT-' . strtoupper(substr((string) $websiteId, 0, 4));
        $db = Database::getInstance();
        $last = $db->query("SELECT quote_number FROM ai_quotes WHERE website_id = ? AND quote_number LIKE ? ORDER BY id DESC LIMIT 1", [$websiteId, $prefix . '-%']);
        if (is_array($last) && count($last) > 0 && !empty($last[0]['quote_number'])) {
            $n = (int) substr($last[0]['quote_number'], strlen($prefix) + 1);
            return $prefix . '-' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT);
        }
        return $prefix . '-001';
    }
}
