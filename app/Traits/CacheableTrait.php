<?php

/**
 * Tourfecto - Cacheable Trait
 * @version 1.0.0
 *
 * أي Repository أو Service عايز يضيف كاش لعملية معيّنة (زي إحصائيات
 * تجميعية غالية، أو نتيجة API خارجي) يقدر يستخدم الـ trait ده بدل ما
 * يكرر منطق remember() يدويًا في كل مكان.
 *
 * الاستخدام:
 *   class StatsRepository extends BaseRepository {
 *       use CacheableTrait;
 *       public function getMonthlyStats(int $userId): array {
 *           return $this->cached("stats:monthly:{$userId}", 300, function () use ($userId) {
 *               // استعلام غالي...
 *           });
 *       }
 *   }
 */
trait CacheableTrait
{
    /** @var CacheInterface|null */
    private $cacheableCacheInstance = null;

    protected function cache(): ?CacheInterface
    {
        if ($this->cacheableCacheInstance === null) {
            $container = Container::getInstance();
            $this->cacheableCacheInstance = $container->has(CacheInterface::class)
                ? $container->make(CacheInterface::class)
                : null;
        }
        return $this->cacheableCacheInstance;
    }

    /**
     * نفّذ $callback واحفظ ناتجه في الكاش، أو رجّع المحفوظ لو موجود.
     * لو الكاش مش متاح لأي سبب، بينفّذ $callback مباشرة (fail-open، مش fail-closed).
     */
    protected function cached(string $key, ?int $ttl, callable $callback)
    {
        $cache = $this->cache();
        if (!$cache) {
            return $callback();
        }
        return $cache->remember($key, $ttl, $callback);
    }

    protected function forgetCache(string $key): void
    {
        $cache = $this->cache();
        if ($cache) {
            $cache->delete($key);
        }
    }
}
