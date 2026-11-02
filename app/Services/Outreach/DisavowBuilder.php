<?php

/**
 * تحليل دومينات الروابط الداخلة وتجهيز ملف Disavow لجوجل.
 *
 * القرار هنا للعميل مش للأداة: الإشارات دي من شكل الدومين بس (امتدادات مشبوهة، كلمات سبام، تركيبة الاسم)، بتعمل «اقتراح»
 * مع سبب مكتوب، ومفيش حاجة بتتحط في الملف من غير ما العميل يختارها. جوجل بتتجاهل أغلب الروابط الرديئة لوحدها، والـDisavow
 * بيتستخدم لما يبقى فيه إجراء يدوي أو روابط مشبوهة كتير ومؤكدة، وبيتم رفعه من Search Console بواسطة مالك الموقع المتحقق منه.
 */
final class DisavowBuilder
{
    public const MAX_INPUT_BYTES = 500000;
    public const MAX_DOMAINS = 5000;

    private const BAD_TLDS = ['xyz', 'top', 'click', 'loan', 'work', 'gq', 'ml', 'tk', 'cf', 'ga', 'icu', 'buzz', 'monster', 'rest', 'cyou', 'sbs', 'cfd', 'bid', 'win', 'party', 'review', 'stream', 'download', 'racing', 'date', 'faith', 'men', 'trade', 'zip', 'mov'];
    private const SPAM_WORDS = ['casino', 'poker', 'slots', 'betting', 'porn', 'xxx', 'escort', 'viagra', 'cialis', 'pharma', 'payday', 'replica', 'essaywriting', 'buyfollowers', 'cheapnfl', 'adult'];
    private const LINKFARM_WORDS = ['backlink', 'linkbuilding', 'linkfarm', 'seoservice', 'articlesubmit', 'bookmark', 'webdirectory', 'freedirectory'];

    /** دومين نضيف أو null (بيقبل دومين أو رابط كامل أو سطر CSV بدومين في أول عمود) */
    public static function normalizeDomain(string $raw): ?string
    {
        $d = strtolower(trim($raw));
        $d = trim((string) preg_replace('~[,;\t].*$~', '', $d), " \"'");
        $d = (string) preg_replace('~^domain:~', '', $d);
        $d = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $d);
        $d = (string) preg_replace('~[/?#:].*$~', '', $d);
        $d = rtrim((string) preg_replace('~^www\.~', '', $d), '.');
        if ($d === '' || strlen($d) > 253 || !preg_match('~^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$~', $d)) {
            return null;
        }
        return $d;
    }

    /** @return array{domains:string[],skipped:int,truncated:bool} */
    public static function parse(string $text, string $ownUrl = ''): array
    {
        $own = self::normalizeDomain($ownUrl);
        $domains = [];
        $skipped = 0;
        foreach (preg_split('/\r\n|\r|\n/', substr($text, 0, self::MAX_INPUT_BYTES)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $d = self::normalizeDomain($line);
            if ($d === null) {
                // سطر العناوين في ملف التصدير («Site» / «Linking site» ...) مش بيتعد كأنه دومين غلط
                if (!preg_match('/^["\']?(site|linking|domain|url|top|source)/i', $line)) {
                    $skipped++;
                }
                continue;
            }
            if ($own !== null && ($d === $own || str_ends_with($d, '.' . $own))) {
                continue;
            }
            $domains[$d] = true;
            if (count($domains) >= self::MAX_DOMAINS) {
                return ['domains' => array_keys($domains), 'skipped' => $skipped, 'truncated' => true];
            }
        }
        return ['domains' => array_keys($domains), 'skipped' => $skipped, 'truncated' => false];
    }

    /** @return array{domain:string,score:int,level:string,reasons:string[]} */
    public static function score(string $domain): array
    {
        $parts = explode('.', $domain);
        $tld = end($parts);
        $label = $parts[count($parts) - 2] ?? $domain;
        $flat = str_replace('-', '', $label);
        $score = 0;
        $why = [];
        if (in_array($tld, self::BAD_TLDS, true)) {
            $score += 35;
            $why[] = 'امتداد .' . $tld . ' معروف بكثرة السبام';
        }
        foreach (self::SPAM_WORDS as $w) {
            if (str_contains($flat, $w)) {
                $score += 45;
                $why[] = 'الاسم فيه كلمة سبام («' . $w . '»)';
                break;
            }
        }
        foreach (self::LINKFARM_WORDS as $w) {
            if (str_contains($flat, $w)) {
                $score += 30;
                $why[] = 'الاسم بيدل على موقع روابط/أدلة للتبادل («' . $w . '»)';
                break;
            }
        }
        if (substr_count($label, '-') >= 3) {
            $score += 20;
            $why[] = 'اسم فيه شرطات كتير';
        }
        $digits = preg_match_all('/\d/', $label);
        if (strlen($label) >= 8 && $digits / max(1, strlen($label)) > 0.35) {
            $score += 20;
            $why[] = 'الاسم أغلبه أرقام';
        }
        if (strlen($label) > 30) {
            $score += 15;
            $why[] = 'اسم طويل جدًا';
        }
        $score = min(100, $score);
        return ['domain' => $domain, 'score' => $score, 'level' => $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'), 'reasons' => $why];
    }

    /** @return array<int,array{domain:string,score:int,level:string,reasons:string[]}> الأعلى أولًا؛ بس اللي ليه سبب */
    public static function analyze(array $domains): array
    {
        $rows = [];
        foreach ($domains as $d) {
            $r = self::score((string) $d);
            if ($r['score'] > 0) {
                $rows[] = $r;
            }
        }
        usort($rows, static fn ($a, $b) => [$b['score'], $a['domain']] <=> [$a['score'], $b['domain']]);
        return $rows;
    }

    /** ملف Disavow بصيغة جوجل: سطور `domain:` بس للدومينات اللي العميل اختارها */
    public static function file(array $selected, string $siteHost, string $date): string
    {
        $out = ["# Disavow file prepared by Tourfecto for " . $siteHost . " on " . $date . ".", '# Review every line before uploading. Only a verified Search Console owner can submit it, and most sites never need to.'];
        $seen = [];
        foreach ($selected as $raw) {
            $d = self::normalizeDomain((string) $raw);
            if ($d === null || isset($seen[$d])) {
                continue;
            }
            $seen[$d] = true;
            $out[] = 'domain:' . $d;
            if (count($seen) >= self::MAX_DOMAINS) {
                break;
            }
        }
        return implode("\n", $out) . "\n";
    }
}
