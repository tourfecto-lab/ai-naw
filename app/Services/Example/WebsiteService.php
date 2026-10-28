<?php

/**
 * Tourfecto - Website Service (مثال توضيحي)
 * @version 1.0.0
 *
 * تنويه: مثال يوضح إزاي Service بتستخدم Repository + Events + Logger
 * كلهم عن طريق Dependency Injection بدل ما تنشئهم بنفسها (`new ...`)
 * جوه كل method - ده اللي بيخلي اختبار الكلاس ده بـ mock سهل.
 * لسه مش متصل بأي Controller حالي.
 */
class WebsiteService extends BaseService
{
    /** @var WebsiteRepository */
    private $websites;

    public function __construct(
        ?WebsiteRepository $websites = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        ?EventDispatcher $events = null
    ) {
        parent::__construct($logger, $cache, $events);
        $this->websites = $websites ?? new WebsiteRepository();
    }

    /**
     * مثال: تسجيل موقع جديد + إطلاق حدث 'website.created' لأي listener
     * مهتم (مثلاً: إرسال إشعار ترحيبي، أو جدولة أول تحليل AI تلقائي عن
     * طريق QueueManager).
     */
    public function registerWebsite(int $userId, array $data): int
    {
        $id = $this->websites->create(array_merge($data, ['user_id' => $userId, 'is_verified' => 0]));

        $this->log('info', 'Website registered via WebsiteService', ['user_id' => $userId, 'website_id' => $id]);
        $this->emit(new AppEvent('website.created', ['website_id' => $id, 'user_id' => $userId]));

        return $id;
    }

    public function verifiedCountForUser(int $userId): int
    {
        return $this->websites->countVerifiedForUser($userId);
    }
}
