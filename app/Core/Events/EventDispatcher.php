<?php

/**
 * Tourfecto - Event Dispatcher
 * @version 1.0.0
 *
 * ملاحظة بنية استضافة: ده Dispatcher "متزامن" (synchronous) — الـ
 * listeners بتتنفذ فورًا جوه نفس الطلب، مش في الخلفية. ده مناسب تمامًا
 * لاستضافة مشتركة (Hostinger) من غير message broker حقيقي (Redis/RabbitMQ).
 * لو listener معيّن محتاج وقت طويل (زي بعت إيميلات كتير)، سيبه يعمل
 * enqueue() على QueueManager بدل ما ينفّذ مباشرة (شوف QueueManager.php).
 *
 * الاستخدام:
 *   $dispatcher = Container::getInstance()->make(EventDispatcher::class);
 *   $dispatcher->listen('website.verified', function (AppEvent $e) { ... });
 *   $dispatcher->listen('website.verified', SomeListenerClass::class);
 *   $dispatcher->dispatch(new AppEvent('website.verified', ['website_id' => 5]));
 */
class EventDispatcher
{
    /** @var array<string, array<callable|string>> */
    private $listeners = [];

    /**
     * تسجيل مستمع لحدث معيّن.
     * @param string $eventName
     * @param callable|string $listener دالة، أو اسم كلاس بيعمل implements EventListenerInterface
     */
    public function listen(string $eventName, $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * إطلاق الحدث لكل المستمعين المسجلين له. أي listener بيفشل (Exception)
     * بيتسجل في الـ log ومكمّلش يمنع باقي الـ listeners من التنفيذ.
     */
    public function dispatch(AppEvent $event): void
    {
        $listeners = $this->listeners[$event->name] ?? [];

        foreach ($listeners as $listener) {
            try {
                if (is_string($listener) && class_exists($listener)) {
                    $instance = Container::getInstance()->make($listener);
                    if ($instance instanceof EventListenerInterface) {
                        $instance->handle($event);
                    }
                } elseif (is_callable($listener)) {
                    $listener($event);
                }
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Event listener failed', [
                        'event' => $event->name,
                        'listener' => is_string($listener) ? $listener : 'closure',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function hasListeners(string $eventName): bool
    {
        return !empty($this->listeners[$eventName]);
    }
}
