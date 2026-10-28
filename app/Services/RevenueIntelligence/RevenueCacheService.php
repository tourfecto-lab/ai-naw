<?php

/**
 * Tourfecto - Revenue Intelligence Cache Service
 * @version 1.1.0
 *
 * Section 18: PERFORMANCE (Caching)
 *
 * غلاف رفيع فوق Cache الموجود فعلاً في المشروع (app/Core/Cache.php -
 * بيستخدم نفس CACHE_DRIVER/CACHE_PREFIX/CACHE_LIFETIME من .env، ملفات
 * محليًا أو Redis لو متاح - لا نعيد بناء نظام كاش جديد). يغطي الأقسام
 * الأكتر تكلفة حسابيًا (Overview/Forecast/Executive Summary) بمفاتيح
 * واضحة قابلة للإبطال (invalidate) وقت حدوث Event حقيقي (إيراد جديد/
 * صفقة اتقفلت) - Section 25 و18 متكاملين مع بعض هنا.
 */
class RevenueCacheService
{
    /** ثواني - كافي لتخفيف الحمل وقت تنقل المستخدم بين التابات بسرعة، وقصير كفاية عشان البيانات تفضل حديثة. */
    public const DEFAULT_TTL = 180;

    /**
     * TTL متدرّج (v1.3.0): الفترات الأقصر (daily/weekly) تتغير أسرع فيلزم
     * كاش أقصر، والفترات الأطول (quarterly/yearly) أغلى حسابيًا فيلزم
     * كاش أطول - بدون ما نفقد حداثة البيانات على مستوى الفترة.
     */
    public static function ttlForPeriod(string $period): int
    {
        switch ($period) {
            case 'daily': return 30;
            case 'weekly': return 90;
            case 'quarterly': return 600;
            case 'yearly': return 900;
            default: return self::DEFAULT_TTL; // monthly
        }
    }

    /** @var Cache|null */
    private $cache;

    public function __construct()
    {
        $this->cache = class_exists('Cache') ? new Cache() : null;
    }

    public function rememberOverview(int $userId, string $period, callable $callback)
    {
        return $this->remember(self::overviewKey($userId, $period), $callback, self::ttlForPeriod($period));
    }

    public function rememberForecast(int $userId, string $period, callable $callback)
    {
        return $this->remember(self::forecastKey($userId, $period), $callback, self::ttlForPeriod($period));
    }

    public function rememberExecutiveSummary(int $userId, callable $callback)
    {
        return $this->remember(self::executiveKey($userId), $callback);
    }

    /**
     * Section 17 (Audit Log) + Section 18 (Performance): بيضمن إن
     * $callback (عادة عملية تسجيل Insight في revai_insights) تتنفذ مرة
     * واحدة بس لكل نافذة كاش (نفس مدة DEFAULT_TTL)، مش في كل Request -
     * بدون ما يأثر على الـ response المحسوب لحظيًا (اللي بيتحسب دايمًا
     * بره الكاش ده). ده يمنع تضخم جدول الـ Audit Log بصفوف مكررة من مجرد
     * ما المستخدم بيفتح/يحدّث التاب.
     */
    public function rememberOncePerWindow(string $namespace, int $userId, callable $callback): void
    {
        if ($this->cache === null) {
            $callback(); // مفيش كاش متاح - ننفذ على طول بدل ما نمنع التسجيل خالص
            return;
        }
        $key = "revai:once:{$namespace}:{$userId}";
        $already = null;
        try {
            $already = $this->cache->get($key);
        } catch (Throwable $e) {
            $already = null;
        }
        if ($already !== null) {
            return; // اتسجّل قبل كده في نفس النافذة - متكررش
        }
        $callback();
        try {
            $this->cache->set($key, true, self::DEFAULT_TTL);
        } catch (Throwable $e) {
            // لو الكتابة في الكاش فشلت، أسوأ حالة إننا نسجّل تاني في الطلب الجاي - مش خطر
        }
    }

    /**
     * Section 25 (Events) - إضافة Notifications: بيمنع تكرار نفس الإشعار
     * لنفس السبب لنفس المستخدم أكتر من مرة كل 24 ساعة (افتراضيًا) - عشان
     * لو الـ Job اتشغّل أكتر من مرة في نفس اليوم (صفقتين اتقفلوا مثلاً)،
     * المستخدم ميتقصفش بنفس التنبيه عن نفس المخاطرة/الشذوذ.
     */
    public function shouldNotify(string $dedupKey, int $ttlSeconds = 86400): bool
    {
        if ($this->cache === null) {
            return true; // مفيش كاش متاح - نفضّل نبعت الإشعار على إننا نمنعه بالغلط
        }
        $key = "revai:notify:{$dedupKey}";
        try {
            if ($this->cache->get($key) !== null) {
                return false; // اتبعت قبل كده في نفس النافذة
            }
        } catch (Throwable $e) {
            // متاكدناش - نكمل ونبعت، أأمن من إننا نمنع إشعار مهم بالغلط
        }
        try {
            $this->cache->set($key, true, $ttlSeconds);
        } catch (Throwable $e) {
            // لو الكتابة فشلت، أسوأ حالة إننا نبعت تاني بدري - مش خطر
        }
        return true;
    }

    private function remember(string $key, callable $callback, int $ttlSeconds = self::DEFAULT_TTL)
    {
        if ($this->cache === null) {
            return $callback(); // مفيش كاش متاح - نحسب مباشرة بدل ما نكسر الطلب
        }
        return $this->cache->remember($key, $callback, $ttlSeconds);
    }

    /** يبطّل كل الكاش الخاص بمستخدم معيّن - بينادى عليها من listeners الأحداث (revenue.updated / crm.deal.won / crm.deal.lost). */
    public function invalidateForUser(int $userId): void
    {
        if ($this->cache === null) {
            return;
        }
        foreach (['daily', 'weekly', 'monthly', 'quarterly', 'yearly'] as $period) {
            $this->cache->delete(self::overviewKey($userId, $period));
            $this->cache->delete(self::forecastKey($userId, $period));
        }
        $this->cache->delete(self::executiveKey($userId));
        foreach (['opportunities', 'risks', 'anomalies'] as $ns) {
            $this->cache->delete("revai:once:{$ns}:{$userId}");
        }
    }

    public static function overviewKey(int $userId, string $period): string
    {
        return "revai:overview:{$userId}:{$period}";
    }

    public static function forecastKey(int $userId, string $period): string
    {
        return "revai:forecast:{$userId}:{$period}";
    }

    public static function executiveKey(int $userId): string
    {
        return "revai:executive:{$userId}";
    }
}
