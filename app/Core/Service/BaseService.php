<?php

/**
 * Tourfecto - Base Service
 * الأساس المشترك لأي Service (Service Layer)
 * @version 1.0.0
 *
 * الفكرة: الـ Controller يستقبل الطلب ويرجّع الرد بس (HTTP concerns)،
 * والـ Repository يعرف يتكلم مع قاعدة البيانات بس (Data Access)، أما
 * "منطق العمل" الفعلي (business logic) - قواعد، تنسيق بين أكتر من
 * Repository، إطلاق Events - فمكانه هنا في Service.
 *
 * مثال قبل/بعد:
 *   قبل: كل منطق التحقق من الاشتراك + استهلاك الكريديت + استدعاء Gemini
 *        كانوا في نفس الملف (TourfectoAIEngine) وده شغال تمام، بس صعب
 *        تختبره أو تعيد استخدام جزء منه لوحده.
 *   بعد (تدريجيًا): AIAnalysisService يستقبل WebsiteRepository +
 *        ReportRepository + EventDispatcher عن طريق الـ constructor
 *        (Dependency Injection)، فتقدر تستبدل أي واحد فيهم بنسخة وهمية
 *        (mock) وقت الاختبار من غير ما تلمس باقي المنطق.
 */
abstract class BaseService implements ServiceInterface
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var CacheInterface|null */
    protected $cache;

    /** @var EventDispatcher|null */
    protected $events;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        ?EventDispatcher $events = null
    ) {
        $container = Container::getInstance();

        $this->logger = $logger ?? ($container->has(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null);
        $this->cache = $cache ?? ($container->has(CacheInterface::class) ? $container->make(CacheInterface::class) : null);
        $this->events = $events ?? ($container->has(EventDispatcher::class) ? $container->make(EventDispatcher::class) : null);
    }

    /**
     * اختصار لإطلاق Event لو نظام الأحداث متاح، بدون ما تنهار الخدمة لو مش متاح.
     */
    protected function emit(AppEvent $event): void
    {
        if ($this->events) {
            $this->events->dispatch($event);
        }
    }

    /**
     * اختصار للتسجيل، بيتجاهل بأمان لو الـ logger مش متاح.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
