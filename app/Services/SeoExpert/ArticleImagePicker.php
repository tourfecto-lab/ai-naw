<?php

/**
 * Tourfecto - اختيار صورة حقيقية للمقال من موقع العميل نفسه
 *
 * في السياحة الصورة الحقيقية للمكان أهم من صورة مولّدة بالذكاء الاصطناعي
 * (ممكن تطلع معبد شكله غلط). فبنختار الصفحة الأقرب لموضوع المقال من
 * صفحات الموقع اللي اتفحصت، وناخد صورتها: صورة المشاركة (og:image) الأول
 * ثم أول صورة محتوى كبيرة. منطق خالص بدون DB أو شبكة.
 */
class ArticleImagePicker
{
    private const STOP = ['with', 'from', 'your', 'tour', 'tours', 'trip', 'trips', 'travel', 'egypt', 'best', 'the', 'and', 'for', 'our', 'discover', 'guide', 'tourfecto'];

    private static function words(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) as $w) {
            if (mb_strlen($w) >= 4 && !in_array($w, self::STOP, true)) {
                $out[$w] = true;
            }
        }
        return $out;
    }

    /**
     * الصفحات مرتبة حسب قربها من موضوع المقال (الأقرب الأول). الصفحة
     * الرئيسية في الآخر كبديل.
     * @param array $pages [['url'=>..., 'title'=>...], ...]
     * @return string[] روابط
     */
    public static function rankPages(string $title, string $body, array $pages, int $limit = 3): array
    {
        $topic = self::words($title . ' ' . mb_substr($body, 0, 600));
        $titleWords = self::words($title);
        $scored = [];
        foreach ($pages as $i => $p) {
            $url = (string) ($p['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            $pageWords = self::words((string) ($p['title'] ?? '') . ' ' . str_replace(['/', '-'], ' ', $path));
            $score = 0;
            foreach ($pageWords as $w => $_) {
                if (isset($titleWords[$w])) {
                    $score += 3;
                } elseif (isset($topic[$w])) {
                    $score += 1;
                }
            }
            if ($path === '') {
                $score = -1; // الرئيسية: آخر اختيار
            }
            $scored[] = ['url' => $url, 'score' => $score, 'i' => $i];
        }
        usort($scored, static fn ($a, $b) => [$b['score'], $a['i']] <=> [$a['score'], $b['i']]);
        $urls = array_column(array_filter($scored, static fn ($s) => $s['score'] > 0), 'url');
        foreach ($scored as $s) {
            if ($s['score'] === -1) {
                $urls[] = $s['url'];
                break;
            }
        }
        return array_slice($urls, 0, $limit);
    }

    /** صورة صفحة: og:image ثم أول صورة محتوى. HTTPS بس. */
    public static function fromContext(array $ctx): ?array
    {
        $og = (string) ($ctx['og']['og:image'] ?? '');
        $alt = trim((string) ($ctx['h1'] ?? ($ctx['title'] ?? '')));
        if (preg_match('#^https://#i', $og)) {
            return ['src' => mb_substr($og, 0, 500), 'alt' => mb_substr($alt, 0, 150)];
        }
        foreach ($ctx['content_images'] ?? [] as $img) {
            $src = (string) ($img['src'] ?? '');
            if (preg_match('#^https://#i', $src)) {
                $a = trim((string) ($img['alt'] ?? '')) ?: $alt;
                return ['src' => mb_substr($src, 0, 500), 'alt' => mb_substr($a, 0, 150)];
            }
        }
        return null;
    }
}
