<?php

/** Idempotent FAQ presentation, preserving original prose and stored records. */
final class ArticleFaqContent
{
    public static function hasQuestion(string $content, string $question): bool
    {
        $q = preg_quote(trim($question), '~');
        return $q !== '' && preg_match('~^\h*(?:\#{1,6}\h+)?(?:\*\*)?' . $q . '(?:\*\*)?\h*$~imu', $content) === 1;
    }

    /** Remove only exact legacy-generated FAQ blocks at the end of an article. */
    public static function clean(string $content, array $faqs): string
    {
        foreach (array_reverse($faqs) as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string) ($faq['question'] ?? ''));
            $a = trim((string) ($faq['answer'] ?? ''));
            if ($q === '' || $a === '') continue;
            $block = "\n\n## " . $q . "\n\n" . $a;
            while (($pos = strrpos($content, $block)) !== false) {
                $prefix = substr($content, 0, $pos);
                $suffix = substr($content, $pos + strlen($block));
                if (trim($suffix) !== '' || !self::hasQuestion($prefix, $q)) break;
                $content = $prefix;
            }
        }
        return $content;
    }

    public static function ensureVisible(string $content, array $faqs): string
    {
        $content = self::clean($content, $faqs);
        foreach ($faqs as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string) ($faq['question'] ?? ''));
            $a = trim((string) ($faq['answer'] ?? ''));
            if ($q !== '' && $a !== '' && !self::hasQuestion($content, $q)) {
                $content .= "\n\n## " . $q . "\n\n" . $a;
            }
        }
        return $content;
    }
}
