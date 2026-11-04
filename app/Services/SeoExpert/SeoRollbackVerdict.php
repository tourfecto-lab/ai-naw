<?php
/**
 * بعد التراجع: الصفحة المنشورة إما رجعت للقيمة المسجلة قبل تعديلنا، أو لسه فيها تعديلنا (كاش أو تأخر)،
 * أو فيها قيمة تالتة كتبها صاحب الصفحة بعد تعديلنا (ما نطمسهاش).
 */
final class SeoRollbackVerdict
{
    public const VERIFIED = 'rollback_verified';
    public const NOT_SEEN = 'rollback_not_seen';
    public const CLIENT_EDIT = 'client_edit_detected';

    public static function evaluate(array $before, array $actual, bool $oursStillPresent, bool $pageReadable): string
    {
        if (!$pageReadable) return self::NOT_SEEN;
        if ($before === $actual) return self::VERIFIED;
        if ($oursStillPresent) return self::NOT_SEEN;
        return $actual !== [] ? self::CLIENT_EDIT : self::NOT_SEEN;
    }
}
