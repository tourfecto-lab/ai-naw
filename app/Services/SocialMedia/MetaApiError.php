<?php

/**
 * Tourfecto - تصنيف أخطاء Meta Graph API بالأكواد (مش بالنص).
 *
 * قبل كده كان الرد بيرجّع رسالة نصية بس، فمكنّاش نفرق بين:
 *  - خطأ مؤقت (نعيد المحاولة)    - خطأ دايم (نفشل فورًا)
 *  - توكن منتهي (العميل يعيد الربط) - حد استخدام (نأجّل)
 * وكل فشل شبكة كان بيتحوّل لـ uncertain.
 *
 * @version 1.0.0
 */
final class MetaApiError
{
    public const TOKEN = 'token';
    public const PERMISSION = 'permission';
    public const RATE_LIMIT = 'rate_limit';
    public const BLOCKED = 'blocked';
    public const MEDIA = 'media';
    public const PARAM = 'param';
    public const TRANSIENT = 'transient';
    public const UNKNOWN = 'unknown';

    /**
     * يحوّل رد خطأ Graph (الـ body المفكوك) لحقول منظّمة.
     * @return array{message:string,user_message:?string,code:?int,subcode:?int,type:?string,fbtrace_id:?string,transient:bool,category:string,http_code:int}
     */
    public static function parse(?array $decoded, int $httpCode): array
    {
        $e = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $code = isset($e['code']) ? (int) $e['code'] : null;
        $sub = isset($e['error_subcode']) ? (int) $e['error_subcode'] : null;
        $metaTransient = !empty($e['is_transient']);

        return [
            'message' => (string) ($e['message'] ?? "Meta API error (HTTP {$httpCode})"),
            'user_message' => isset($e['error_user_msg']) ? (string) $e['error_user_msg'] : null,
            'code' => $code,
            'subcode' => $sub,
            'type' => isset($e['type']) ? (string) $e['type'] : null,
            'fbtrace_id' => isset($e['fbtrace_id']) ? (string) $e['fbtrace_id'] : null,
            'transient' => $metaTransient || self::categorize($code, $sub, $httpCode) === self::TRANSIENT
                || self::categorize($code, $sub, $httpCode) === self::RATE_LIMIT,
            'category' => self::categorize($code, $sub, $httpCode, $metaTransient),
            'http_code' => $httpCode,
        ];
    }

    public static function categorize(?int $code, ?int $sub, int $http = 0, bool $metaTransient = false): string
    {
        // توكن: 190 (+ 458/460/463/467) و 102 (session) و 2500
        if ($code === 190 || $code === 102 || in_array($sub, [458, 459, 460, 463, 467], true)) {
            return self::TOKEN;
        }
        // حد الاستخدام: التطبيق/المستخدم/الصفحة/الـ Business Use Case
        if (in_array($code, [4, 17, 32, 613, 341], true) || ($code !== null && $code >= 80000 && $code <= 80014)) {
            return self::RATE_LIMIT;
        }
        // إنستجرام: حد النشر اليومي (25 مع subcode 2207042)
        if ($sub === 2207042) {
            return self::RATE_LIMIT;
        }
        // حظر مؤقت على الإجراء (spam / action blocked)
        if ($code === 368) {
            return self::BLOCKED;
        }
        if ($code === 10 || ($code !== null && $code >= 200 && $code <= 299) || $code === 3) {
            return self::PERMISSION;
        }
        // مشاكل الوسائط في إنستجرام (مقاس/فورمات/تحميل/انتهاء)
        if (in_array($sub, [2207001, 2207003, 2207004, 2207005, 2207006, 2207007, 2207008, 2207009, 2207010, 2207011, 2207020, 2207023, 2207024, 2207026, 2207028, 2207032, 2207033, 2207034, 2207035, 2207036, 2207037], true)) {
            return self::MEDIA;
        }
        if ($code === 100 || $code === 9004 || $code === 36000 || $code === 36001) {
            return self::PARAM;
        }
        // 1 = unknown, 2 = service temporarily unavailable, 5xx
        if ($code === 1 || $code === 2 || $metaTransient || ($http >= 500 && $http < 600)) {
            return self::TRANSIENT;
        }
        return self::UNKNOWN;
    }

    /**
     * هل الخطأ ده معناه "Meta رفضت الطلب قبل ما تنفّذه" (يعني المنشور أكيد ماتنشرش)؟
     * أكواد حد الاستخدام والتوكن والصلاحيات والبارامترات = رفض صريح، فآمن نعيد المحاولة أو نفشل.
     * 1/2 و5xx = غامضة: ممكن Meta نفّذت وبعدين وقعت، فبنسيبها uncertain في POST.
     */
    public static function isDefinitiveRejection(string $category, ?int $code): bool
    {
        if (in_array($category, [self::TOKEN, self::PERMISSION, self::RATE_LIMIT, self::BLOCKED, self::PARAM, self::MEDIA], true)) {
            return true;
        }
        return false;
    }

    /** رسالة عربي واضحة مبنية على الكود (مش على نص الرسالة) */
    public static function hint(string $category, ?int $code = null, ?int $sub = null): string
    {
        if ($sub === 2207042) {
            return 'وصلت للحد اليومي للنشر على إنستجرام (100 منشور/24 ساعة). هيتنشر تلقائيًا لما الحد يتجدد.';
        }
        if ($sub === 463 || ($code === 190 && $sub === null)) {
            return 'انتهت صلاحية ربط الحساب. اربط فيسبوك وإنستجرام تاني من صفحة السوشيال.';
        }
        return match ($category) {
            self::TOKEN => 'الربط بحساب فيسبوك مش صالح (انتهى أو اتغيّرت كلمة السر أو اتلغت الصلاحية). اربط الحساب تاني من صفحة السوشيال.',
            self::PERMISSION => 'صلاحية ناقصة أو مش متاحة للتطبيق. اربط الحساب تاني ووافق على كل الصلاحيات، وتأكد إنك أدمن على الصفحة.',
            self::RATE_LIMIT => 'Meta موقفة الطلبات مؤقتًا (حد الاستخدام). المنصة هتعيد المحاولة تلقائيًا بعد شوية.',
            self::BLOCKED => 'فيسبوك حظر النشر مؤقتًا على الحساب/الصفحة بسبب نشاط متكرر. استنى كام ساعة قبل المحاولة.',
            self::MEDIA => 'Meta رفضت الصورة/الفيديو (مقاس أو صيغة أو تعذّر التحميل). تأكد إن الملف JPG/MP4 صالح ومتاح على https.',
            self::PARAM => 'بيانات المنشور غير صالحة عند Meta (نص أو رابط أو معرّف). راجع المنشور وحاول تاني.',
            self::TRANSIENT => 'مشكلة مؤقتة عند Meta. ممكن يكون المنشور اتنشر فعلًا - المنصة هتتحقق تلقائيًا قبل ما تعيد النشر.',
            default => '',
        };
    }
}
