<?php

/**
 * Tourfecto - Cache Class
 * نظام كاش متقدم مع دعم ملفات و Redis
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Cache
{
    /**
     * @var string $driver - نوع الكاش (file, redis, memcached)
     */
    private $driver;

    /**
     * @var string $prefix - بادئة المفاتيح
     */
    private $prefix;

    /**
     * @var int $defaultLifetime - مدة الصلاحية الافتراضية (ثانية)
     */
    private $defaultLifetime;

    /**
     * @var array $redis - اتصال Redis
     */
    private $redis = null;

    /**
     * @var string $path - مسار كاش الملفات
     */
    private $path;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Memcached methods in this version are placeholders; use the working file backend.
        $this->driver = CACHE_DRIVER === 'memcached' ? 'file' : CACHE_DRIVER;
        $this->prefix = CACHE_PREFIX;
        $this->defaultLifetime = CACHE_LIFETIME;
        $this->path = TOURFECTO_STORAGE . '/cache';

        // التأكد من وجود مجلد الكاش
        if ($this->driver === 'file' && !is_dir($this->path)) {
            mkdir($this->path, 0755, true);
        }

        if ($this->driver === 'redis' && !extension_loaded('redis')) {
            $this->driver = 'file';
            if (!is_dir($this->path)) {
                @mkdir($this->path, 0755, true);
            }
        }

        // تهيئة Redis
        if ($this->driver === 'redis' && extension_loaded('redis')) {
            $this->initRedis();
        }
    }

    /**
     * تهيئة Redis
     */
    private function initRedis(): void
    {
        try {
            $this->redis = new Redis();
            $host = env('REDIS_HOST') ?: 'localhost';
            $port = env('REDIS_PORT') ?: 6379;
            $password = env('REDIS_PASSWORD') ?: null;

            $this->redis->connect($host, $port);

            if ($password) {
                $this->redis->auth($password);
            }

            // استخدام قاعدة بيانات مخصصة
            $database = env('REDIS_DATABASE') ?: 0;
            $this->redis->select($database);

        } catch (Exception $e) {
            Logger::error('Redis connection failed', [
                'error' => $e->getMessage()
            ]);
            $this->driver = 'file';
            $this->redis = null;
            if (!is_dir($this->path)) {
                @mkdir($this->path, 0755, true);
            }
        }
    }

    /**
     * تخزين قيمة في الكاش
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $key = $this->prefix . $key;
        $ttl = $ttl ?? $this->defaultLifetime;

        $serialized = serialize($value);

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    return $this->redis->setex($key, $ttl, $serialized);
                }
                return false;

            case 'memcached':
                return $this->setMemcached($key, $serialized, $ttl);

            default:
                return $this->setFile($key, $serialized, $ttl);
        }
    }

    /**
     * الحصول على قيمة من الكاش
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $key = $this->prefix . $key;

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    $value = $this->redis->get($key);
                    if ($value !== false) {
                        return unserialize($value);
                    }
                }
                break;

            case 'memcached':
                $value = $this->getMemcached($key);
                return $value === null ? $default : $value;

            default:
                $value = $this->getFile($key);
                return $value === null ? $default : $value;
        }

        return $default;
    }

    /**
     * حذف قيمة من الكاش
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $key = $this->prefix . $key;

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    return (bool) $this->redis->del($key);
                }
                return false;

            case 'memcached':
                return $this->deleteMemcached($key);

            default:
                return $this->deleteFile($key);
        }
    }

    /**
     * التحقق من وجود قيمة في الكاش
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $key = $this->prefix . $key;

        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    return (bool) $this->redis->exists($key);
                }
                return false;

            case 'memcached':
                return $this->hasMemcached($key);

            default:
                return $this->hasFile($key);
        }
    }

    /**
     * تخزين في الكاش مع وقت انتهاء (توليد تلقائي)
     * @param string $key
     * @param callable $callback
     * @param int $ttl
     * @return mixed
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * تخزين في ملف
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @return bool
     */
    private function setFile(string $key, string $value, int $ttl): bool
    {
        $filename = $this->getFilename($key);
        $content = [
            'value' => $value,
            'expires' => time() + $ttl
        ];

        // Atomic rename prevents readers from seeing a partially written cache entry.
        $temp = tempnam(dirname($filename), '.cache-');
        if ($temp === false) {
            return false;
        }
        $ok = file_put_contents($temp, serialize($content), LOCK_EX) !== false
            && rename($temp, $filename);
        if (!$ok) {
            @unlink($temp);
        }
        return $ok;
    }

    /**
     * الحصول من ملف
     * @param string $key
     * @return mixed
     */
    private function getFile(string $key)
    {
        $filename = $this->getFilename($key);

        // The file may be removed by another request between existence/read checks.
        $raw = @file_get_contents($filename);
        if ($raw === false) {
            return null;
        }

        $content = @unserialize($raw);
        if (!is_array($content) || !array_key_exists('expires', $content) || !array_key_exists('value', $content)) {
            @unlink($filename);
            return null;
        }

        if ((int) $content['expires'] < time()) {
            @unlink($filename);
            return null;
        }

        return @unserialize($content['value']);
    }

    /**
     * حذف من ملف
     * @param string $key
     * @return bool
     */
    private function deleteFile(string $key): bool
    {
        $filename = $this->getFilename($key);

        if (!is_file($filename)) {
            return false;
        }

        // Concurrent cache cleanup is normal; suppress the TOCTOU warning if another
        // request removed the file after our check.
        return @unlink($filename) || !file_exists($filename);
    }

    /**
     * التحقق من وجود ملف
     * @param string $key
     * @return bool
     */
    private function hasFile(string $key): bool
    {
        $filename = $this->getFilename($key);

        $raw = @file_get_contents($filename);
        if ($raw === false) {
            return false;
        }

        $content = @unserialize($raw);
        if (!is_array($content) || !isset($content['expires'])) {
            @unlink($filename);
            return false;
        }

        if ((int) $content['expires'] < time()) {
            @unlink($filename);
            return false;
        }

        return true;
    }

    /**
     * الحصول على اسم الملف
     * @param string $key
     * @return string
     */
    private function getFilename(string $key): string
    {
        $hash = md5($key);
        $dir = $this->path . '/' . substr($hash, 0, 2);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $hash . '.cache';
    }

    /**
     * مسح الكاش بالكامل
     * @return bool
     */
    public function clear(): bool
    {
        switch ($this->driver) {
            case 'redis':
                if ($this->redis) {
                    $keys = $this->redis->keys($this->prefix . '*');
                    if (!empty($keys)) {
                        return (bool) $this->redis->del($keys);
                    }
                }
                return false;

            case 'memcached':
                return $this->clearMemcached();

            default:
                return $this->clearFiles();
        }
    }

    /**
     * مسح ملفات الكاش
     * @return bool
     */
    private function clearFiles(): bool
    {
        $files = glob($this->path . '/*/*.cache');
        $success = true;

        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file) && file_exists($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * تخزين في Memcached
     * @param string $key
     * @param string $value
     * @param int $ttl
     * @return bool
     */
    private function setMemcached(string $key, string $value, int $ttl): bool
    {
        // تنفيذ Memcached
        return false;
    }

    /**
     * الحصول من Memcached
     * @param string $key
     * @return mixed
     */
    private function getMemcached(string $key)
    {
        // تنفيذ Memcached
        return null;
    }

    /**
     * حذف من Memcached
     * @param string $key
     * @return bool
     */
    private function deleteMemcached(string $key): bool
    {
        // تنفيذ Memcached
        return false;
    }

    /**
     * التحقق من وجود في Memcached
     * @param string $key
     * @return bool
     */
    private function hasMemcached(string $key): bool
    {
        // تنفيذ Memcached
        return false;
    }

    /**
     * مسح Memcached
     * @return bool
     */
    private function clearMemcached(): bool
    {
        // تنفيذ Memcached
        return false;
    }

    /**
     * الحصول على إحصائيات الكاش
     * @return array
     */
    public function getStats(): array
    {
        $stats = [
            'driver' => $this->driver,
            'prefix' => $this->prefix,
            'default_lifetime' => $this->defaultLifetime
        ];

        if ($this->driver === 'file') {
            $files = glob($this->path . '/*/*.cache');
            $stats['items'] = count($files);

            $size = 0;
            foreach ($files as $file) {
                $fileSize = @filesize($file);
                if ($fileSize !== false) {
                    $size += $fileSize;
                }
            }
            $stats['size'] = $size;
        }

        return $stats;
    }
}
