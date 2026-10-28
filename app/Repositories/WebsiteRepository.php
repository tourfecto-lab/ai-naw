<?php

/**
 * Tourfecto - Website Repository (مثال توضيحي)
 * @version 1.0.0
 *
 * تنويه مهم: الكلاس ده *مثال* يوضح إزاي تُبنى Repository حقيقية فوق
 * BaseRepository. *لسه مش متصل بـ WebsiteController الحالي* - الكنترولر
 * لسه شغال بالضبط زي ما هو (عن طريق كلاس Website الأصلي/Model). ده متعمد
 * حسب طلب "ابني الأساس بس من غير ما تغيّر أي كود شغال".
 *
 * لاحظ إزاي resolveUrlColumn() بيحل نفس مشكلة "main_url مش موجود في
 * قاعدة البيانات الفعلية" اللي واجهناها لما بنينا صفحة /websites، لكن
 * بشكل عام وقابل لإعادة الاستخدام (مش تصحيح يدوي مرة واحدة).
 */
class WebsiteRepository extends BaseRepository
{
    use HasTimestampsTrait;
    use CacheableTrait;

    protected $table = 'websites';

    public function __construct(?Database $db = null)
    {
        parent::__construct($db);
        $this->columnMap['main_url'] = $this->resolveUrlColumn();
    }

    private function resolveUrlColumn(): string
    {
        return $this->detectColumn(['main_url', 'url', 'website_url', 'site_url', 'domain'], 'main_url');
    }

    /**
     * كل مواقع مستخدم معيّن.
     */
    public function forUser(int $userId): array
    {
        return $this->findBy(['user_id' => $userId], ['created_at' => 'DESC']);
    }

    /**
     * البحث بالرابط (مستخدم في عدة أماكن بالمشروع: AIController،
     * ReputationManager، ChatManager - كلهم بيدوروا على نفس المنطق).
     */
    public function findByUrl(int $userId, string $url): ?array
    {
        $results = $this->findBy(['user_id' => $userId, 'main_url' => $url], [], 1);
        return $results[0] ?? null;
    }

    /**
     * إحصائية بسيطة (كمثال على استخدام CacheableTrait): عدد المواقع
     * الموثّقة لمستخدم، متخزنة لمدة دقيقة.
     */
    public function countVerifiedForUser(int $userId): int
    {
        return (int) $this->cached("websites:verified_count:{$userId}", 60, function () use ($userId) {
            $rows = $this->findBy(['user_id' => $userId, 'is_verified' => 1]);
            return count($rows);
        });
    }
}
