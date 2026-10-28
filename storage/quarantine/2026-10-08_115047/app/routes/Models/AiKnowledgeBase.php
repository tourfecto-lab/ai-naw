<?php
/**
 * Tourfecto - AI Chat Platform
 * قاعدة معرفة الشركة الخاصة بـ AI Chat (بند 4).
 * @version 1.0.0
 */
class AiKnowledgeBase extends Model {
    protected $table = 'ai_knowledge_base';
    protected $fillable = [
        'website_id', 'section', 'title', 'content', 'structured_data',
        'language', 'tone', 'priority', 'is_active', 'created_by_user_id',
    ];

    /**
     * كل عناصر قاعدة المعرفة الفعّالة لموقع معيّن، الأحدث أولوية أولاً.
     * @param int $websiteId
     * @param string|null $language فلترة اختيارية باللغة
     * @return array
     */
    public function activeFor(int $websiteId, ?string $language = null): array {
        $conditions = ['website_id' => $websiteId, 'is_active' => 1];
        if ($language) {
            $conditions['language'] = $language;
        }
        return $this->where($conditions, ['priority' => 'DESC', 'id' => 'ASC']);
    }

    /**
     * جلب عناصر قسم معيّن فقط (مثال: كل الأسئلة الشائعة).
     * @param int $websiteId
     * @param string $section
     * @return array
     */
    public function forSection(int $websiteId, string $section): array {
        return $this->where(
            ['website_id' => $websiteId, 'section' => $section, 'is_active' => 1],
            ['priority' => 'DESC', 'id' => 'ASC']
        );
    }
}
