<?php

/**
 * Tourfecto - Cache Contract
 * @version 1.0.0
 *
 * الكود اللي بيستخدم الكاش لازم يعتمد على الـ interface ده (Dependency
 * Inversion) مش على كلاس Cache الحالي مباشرة، عشان لو غيّرنا الـ driver
 * (Redis / Memcached / DB) يوم من الأيام، محدش من الكود اللي بيستخدمه
 * محتاج يتغيّر. الـ CacheAdapter بيربط ده بكلاس Cache الموجود فعلاً.
 */
interface CacheInterface
{
    /**
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key);

    /**
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl بالثواني، null = المدة الافتراضية
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    public function has(string $key): bool;

    public function delete(string $key): bool;

    /**
     * هات القيمة من الكاش، ولو مش موجودة نفّذ $callback واحفظ ناتجها.
     * @param string $key
     * @param int|null $ttl
     * @param callable $callback
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, callable $callback);
}
