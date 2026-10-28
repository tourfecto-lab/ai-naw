<?php

/**
 * Tourfecto - Enterprise Architecture Helpers
 * دوال مساعدة عامة للطبقة المعمارية الجديدة (Container/Events/Queue/Cache)
 * @version 1.0.0
 *
 * بنفس أسلوب باقي ملفات app/Helpers/ (دوال عامة محمية بـ function_exists
 * عشان توافقية كاملة لو حد عرّف دالة بنفس الاسم قبل كده بالغلط).
 */

if (!function_exists('container')) {
    /**
     * اختصار سريع لحاوية الـ DI.
     * @param string|null $abstract لو اتبعت، بيرجع الكائن المطلوب مباشرة
     * @return Container|mixed
     */
    function container(?string $abstract = null)
    {
        $container = Container::getInstance();
        return $abstract === null ? $container : $container->make($abstract);
    }
}

if (!function_exists('event')) {
    /**
     * اختصار لإطلاق حدث من أي مكان في الكود بسطر واحد.
     * @param string $name
     * @param array $payload
     */
    function event(string $name, array $payload = []): void
    {
        if (!class_exists('EventDispatcher')) {
            return;
        }
        container(EventDispatcher::class)->dispatch(new AppEvent($name, $payload));
    }
}

if (!function_exists('listen')) {
    /**
     * اختصار لتسجيل مستمع لحدث.
     * @param string $eventName
     * @param callable|string $listener
     */
    function listen(string $eventName, $listener): void
    {
        if (!class_exists('EventDispatcher')) {
            return;
        }
        container(EventDispatcher::class)->listen($eventName, $listener);
    }
}

if (!function_exists('enqueue')) {
    /**
     * اختصار لإضافة مهمة للطابور الخلفي (يتنفذ لاحقًا عن طريق كرون).
     * @param string $jobClass
     * @param array $payload
     * @param string $queue
     * @param int $delaySeconds
     * @return int|false
     */
    function enqueue(string $jobClass, array $payload = [], string $queue = 'default', int $delaySeconds = 0)
    {
        if (!class_exists('QueueManager')) {
            return false;
        }
        return container(QueueManager::class)->push($jobClass, $payload, $queue, $delaySeconds);
    }
}

if (!function_exists('cache_remember')) {
    /**
     * اختصار للكاش عن طريق CacheInterface الجديد (بديل استخدام Cache
     * مباشرة، عشان أي كود جديد يعتمد على الـ interface).
     * @param string $key
     * @param int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    function cache_remember(string $key, ?int $ttl, callable $callback)
    {
        if (!class_exists('CacheAdapter')) {
            return $callback();
        }
        return container(CacheInterface::class)->remember($key, $ttl, $callback);
    }
}
