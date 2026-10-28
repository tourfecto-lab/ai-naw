<?php

/**
 * Tourfecto - قوالب واتساب الرسمي اللي المنصة محتاجاها (عربي + إنجليزي)
 *
 * المنصة بتعمل القوالب دي في حساب واتساب الشركة بضغطة زرار، وMeta بتراجعها (دقايق لـ 24 ساعة).
 * لحد ما تتوافق: التذكير وطلب التقييم بيروحوا بالإيميل أو UltraMsg لو موجود.
 *
 * قواعد Meta اللي القوالب ماشية عليها: المتغير مايبقاش أول أو آخر النص، مفيش متغيرين ورا بعض،
 * ولازم مثال لكل متغير.
 */
class WhatsAppTemplates
{
    public const DEFS = [
        'tf_trip_reminder' => [
            'category' => 'UTILITY',
            'label' => 'تذكير الرحلة',
            'params' => ['اسم العميل', 'اسم شركتك', 'اسم الرحلة', 'الميعاد'],
            'ar' => [
                'text' => "أهلاً {{1}}،\nده تذكير من {{2}} برحلة {{3}}، وميعادها {{4}}.\nلو عندك أي سؤال رد على الرسالة دي وهنساعدك.",
                'example' => ['أحمد', 'نايل تورز', 'رحلة الأقصر يوم كامل', 'بكرة الساعة 08:00'],
            ],
            'en' => [
                'text' => "Hi {{1}},\nThis is a reminder from {{2}} about your trip {{3}}, scheduled for {{4}}.\nIf you have any questions, just reply to this message.",
                'example' => ['Sarah', 'Nile Tours', 'Luxor full day tour', 'tomorrow at 08:00'],
            ],
        ],
        'tf_review_request' => [
            'category' => 'UTILITY',
            'label' => 'طلب تقييم بعد الرحلة',
            'params' => ['اسم العميل', 'اسم شركتك', 'لينك التقييم'],
            'ar' => [
                'text' => "أهلاً {{1}}، شكرًا إنك سافرت مع {{2}}.\nرأيك يفرق معانا جدًا، ممكن تقيّم تجربتك من هنا: {{3}}\nشكرًا لوقتك!",
                'example' => ['أحمد', 'نايل تورز', 'https://tourfecto.pro/rr/abc123'],
            ],
            'en' => [
                'text' => "Hi {{1}}, thank you for travelling with {{2}}.\nYour feedback means a lot to us. Could you rate your experience here: {{3}}\nThank you for your time!",
                'example' => ['Sarah', 'Nile Tours', 'https://tourfecto.pro/rr/abc123'],
            ],
        ],
        'tf_follow_up' => [
            'category' => 'MARKETING',
            'label' => 'متابعة العميل اللي سأل وماحجزش',
            'params' => ['اسم العميل', 'اسم شركتك'],
            'ar' => [
                'text' => "أهلاً {{1}}، معاك فريق {{2}}.\nلسه حابب نساعدك تحجز رحلتك؟ رد على الرسالة دي وهنكمّل معاك.",
                'example' => ['أحمد', 'نايل تورز'],
            ],
            'en' => [
                'text' => "Hi {{1}}, this is the {{2}} team.\nWould you still like help booking your trip? Just reply to this message and we'll take it from there.",
                'example' => ['Sarah', 'Nile Tours'],
            ],
        ],
    ];

    /** شكل الطلب اللي Meta مستنياه لإنشاء قالب */
    public static function payload(string $name, string $lang): array
    {
        $d = self::DEFS[$name];
        return [
            'name' => $name,
            'language' => $lang,
            'category' => $d['category'],
            'components' => [[
                'type' => 'BODY',
                'text' => $d[$lang]['text'],
                'example' => ['body_text' => [$d[$lang]['example']]],
            ]],
        ];
    }

    /** حالة القوالب المحفوظة على الاتصال: [name][lang] => APPROVED|PENDING|REJECTED|... */
    public static function statuses(array $connRow): array
    {
        $m = json_decode((string) ($connRow['meta_json'] ?? ''), true) ?: [];
        return is_array($m['templates'] ?? null) ? $m['templates'] : [];
    }

    /**
     * يختار اللغة المطلوبة فقط عند موافقة Meta، دون التحويل إلى لغة جمهور آخر.
     * @return array{lang:?string, reason:?string} lang=null يعني مفيش قالب ينفع دلوقتي
     */
    public static function pickLanguage(array $connRow, string $name, string $wantLang): array
    {
        $status=self::statuses($connRow)[$name][$wantLang]??null;
        if($status==='APPROVED')return ['lang'=>$wantLang,'reason'=>null];
        return ['lang'=>null,'reason'=>'No approved template is available in the requested language. Refresh templates and wait for Meta approval.'];
    }

    /** يسحب حالة كل القوالب من Meta ويحفظها على الاتصال @return array{ok:bool, templates:array, error:?string} */
    public static function sync(WhatsAppCloudAPI $api, int $connectionId): array
    {
        $r = $api->listTemplates();
        if (!$r['ok']) {
            return ['ok' => false, 'templates' => [], 'error' => $r['error']];
        }
        $out = [];
        $extra = [];
        foreach ($r['items'] as $t) {
            $n = (string) ($t['name'] ?? '');
            $lang = (string) ($t['language'] ?? '');
            if (isset(self::DEFS[$n])) {
                $out[$n][$lang] = (string) ($t['status'] ?? '');
                if (!empty($t['rejected_reason']) && $t['rejected_reason'] !== 'NONE') {
                    $extra[$n][$lang] = (string) $t['rejected_reason'];
                }
            }
        }
        self::saveMeta($connectionId, ['templates' => $out, 'rejected' => $extra, 'templates_checked_at' => gmdate('Y-m-d H:i:s')]);
        return ['ok' => true, 'templates' => $out, 'error' => null];
    }

    /** يعمل القوالب الناقصة (عربي + إنجليزي) في حساب الشركة */
    public static function createMissing(WhatsAppCloudAPI $api, int $connectionId): array
    {
        $sync = self::sync($api, $connectionId);
        $have = $sync['templates'] ?? [];
        $created = [];
        $errors = [];
        foreach (self::DEFS as $name => $d) {
            foreach (['ar', 'en'] as $lang) {
                if (isset($have[$name][$lang]) && $have[$name][$lang] !== 'REJECTED') {
                    continue;
                }
                $r = $api->createTemplate(self::payload($name, $lang));
                if ($r['ok']) {
                    $created[] = "{$name}/{$lang}";
                } elseif ((int) ($r['error_code'] ?? 0) !== 100 || stripos((string) $r['error'], 'already') === false) {
                    $errors[] = "{$d['label']} ({$lang}): " . $r['error'];
                }
            }
        }
        $after = self::sync($api, $connectionId);
        return ['ok' => !$errors, 'created' => $created, 'errors' => $errors, 'templates' => $after['templates'] ?? []];
    }

    /** دمج قيم في meta_json بتاع الاتصال */
    public static function saveMeta(int $connectionId, array $patch): void
    {
        $db = Database::getInstance();
        $row = $db->query('SELECT meta_json FROM platform_connections WHERE id = ?', [$connectionId])[0] ?? null;
        $m = json_decode((string) ($row['meta_json'] ?? ''), true) ?: [];
        $db->query('UPDATE platform_connections SET meta_json = ? WHERE id = ?', [json_encode(array_merge($m, $patch), JSON_UNESCAPED_UNICODE), $connectionId]);
    }
}
