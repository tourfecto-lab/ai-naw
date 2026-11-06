<?php

/**
 * Tourfecto - منع نشر مقال على موقع غير الموقع اللي اتولّد له.
 * المقال بيتكتب باسم الشركة وروابط الموقع اللي اتولّد له، فنشره على موقع تاني بيطلّع محتوى بهوية غلط.
 */
class ArticleSiteGuard
{
    /** true لو المقال مربوط بموقع معروف والهدف موقع تاني */
    public static function isMismatch($articleWebsiteId, int $targetWebsiteId): bool
    {
        $owner = (int) $articleWebsiteId;
        return $owner > 0 && $targetWebsiteId > 0 && $owner !== $targetWebsiteId;
    }
}
