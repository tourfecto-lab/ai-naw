<?php

/**
 * Tourfecto - Cache Adapter
 * @version 1.0.0
 *
 * Adapter Pattern: بيلف كلاس Cache الموجود فعلاً (وشغال) من غير ما يغيّر
 * فيه حرف واحد، وبيعرضه خلف CacheInterface الجديد. كده أي كود جديد
 * (Repositories/Services) بيعتمد على الـ interface بس، مش على تفاصيل
 * تنفيذ Cache (file/redis).
 */
class CacheAdapter implements CacheInterface
{
    /** @var Cache */
    private $cache;

    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache ?? new Cache();
    }

    public function get(string $key)
    {
        return $this->cache->get($key);
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return $this->cache->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function remember(string $key, ?int $ttl, callable $callback)
    {
        // ترتيب المعاملات في Cache الأصلي مختلف (callback قبل ttl) - بنترجمها هنا
        return $this->cache->remember($key, $callback, $ttl);
    }
}
