<?php
/**
 * Tourfecto - AI Chat Platform
 * ذاكرة العميل طويلة المدى (بند 3). كل صف = حقيقة واحدة عن عميل معيّن.
 * @version 1.0.0
 */
class AiCustomerMemory extends Model {
    protected $table = 'ai_customer_memory';
    protected $fillable = [
        'website_id', 'customer_key', 'memory_key', 'memory_value',
        'source_conversation_id', 'confidence',
    ];

    /**
     * كل ما يتذكره النظام عن عميل معيّن، كمصفوفة key => value جاهزة للحقن
     * في الـ AI System Prompt.
     * @param int $websiteId
     * @param string $customerKey
     * @return array
     */
    public function memoryFor(int $websiteId, string $customerKey): array {
        $rows = $this->where(['website_id' => $websiteId, 'customer_key' => $customerKey]);
        $memory = [];
        foreach ($rows as $row) {
            $memory[$row->getAttribute('memory_key')] = $row->getAttribute('memory_value');
        }
        return $memory;
    }

    /**
     * تحديث/إضافة حقيقة واحدة (Upsert) - لا يخزّن بيانات حساسة بدون ضرورة،
     * القرار بما يُحفظ يُترك لطبقة الـAI Conversation Engine في المرحلة القادمة.
     * @param int $websiteId
     * @param string $customerKey
     * @param string $memoryKey
     * @param string $memoryValue
     * @param int|null $sourceConversationId
     * @return bool
     */
    public function remember(int $websiteId, string $customerKey, string $memoryKey, string $memoryValue, ?int $sourceConversationId = null): bool {
        $existing = $this->where([
            'website_id' => $websiteId,
            'customer_key' => $customerKey,
            'memory_key' => $memoryKey,
        ], [], 1);

        if (!empty($existing)) {
            $existing[0]->fill(['memory_value' => $memoryValue, 'source_conversation_id' => $sourceConversationId]);
            return $existing[0]->save() !== false;
        }

        $entry = new AiCustomerMemory();
        $entry->fill([
            'website_id' => $websiteId,
            'customer_key' => $customerKey,
            'memory_key' => $memoryKey,
            'memory_value' => $memoryValue,
            'source_conversation_id' => $sourceConversationId,
        ]);

        return $entry->save() !== false;
    }
}
