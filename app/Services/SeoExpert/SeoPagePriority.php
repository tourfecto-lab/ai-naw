<?php
/**
 * أولوية الصفحة للتوصيات: الصفحة التجارية (رحلة/نشاط/وجهة) قبل المقالات وصفحات المعلومات والقانونية.
 * منطق خالص: بيرجّع درجة وسبب مفهوم للعميل، من غير ما يخترع شيء.
 */
final class SeoPagePriority
{
    private const UTILITY = '#/(?:privacy|privacy-policy|terms|terms-and-conditions|cookies?|cookie-policy|disclaimer|legal|contact(?:-us)?|about(?:-us)?|faq|sitemap|accessibility)(?:/|$)#i';

    /** @return array{score:int,tier:string,reason:string} */
    public static function score(array $page, bool $isTrip = false): array
    {
        $path = (string) ($page['page_path'] ?? parse_url((string) ($page['url'] ?? ''), PHP_URL_PATH));
        $path = strtolower(rawurldecode($path));
        if ($path === '' || $path === '/') {
            return ['score' => 80, 'tier' => 'homepage', 'reason' => 'The home page is the first page visitors and search engines meet.'];
        }
        if (preg_match(self::UTILITY, $path)) {
            return ['score' => 5, 'tier' => 'utility', 'reason' => 'An information or legal page: it rarely brings bookings, so it is not a priority.'];
        }
        if ($isTrip) {
            return ['score' => 100, 'tier' => 'trip', 'reason' => 'A bookable trip page: improving it can bring enquiries and bookings directly.'];
        }
        if (preg_match('#/(?:tours?|st_tours?|activity|activities|st_activity|excursions?|packages?|trips?)(?:/|$)#', $path)) {
            return ['score' => 90, 'tier' => 'service', 'reason' => 'A trip or activity page: it is where visitors decide to book.'];
        }
        if (preg_match('#/(?:destinations?|locations?|st_location|cities|city)(?:/|$)#', $path)) {
            return ['score' => 70, 'tier' => 'destination', 'reason' => 'A destination page: it brings visitors searching for where to go.'];
        }
        if (preg_match('#/(?:blog|news|guides?|articles?)(?:/|$)#', $path)) {
            return ['score' => 40, 'tier' => 'content', 'reason' => 'A guide or article: useful for visibility, lower priority than booking pages.'];
        }
        return ['score' => 30, 'tier' => 'other', 'reason' => 'A general page without a direct booking role.'];
    }

    /** Pages ordered by priority (stable: equal scores keep their crawl order). */
    public static function order(array $pages, callable $isTrip): array
    {
        $rows = [];
        foreach (array_values($pages) as $i => $p) $rows[] = [self::score($p, (bool) $isTrip($p))['score'], $i, $p];
        usort($rows, static fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        return array_column($rows, 2);
    }
}
