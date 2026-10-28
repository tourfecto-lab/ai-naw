<?php

/**
 * Tourfecto - Content Formatter
 * تحويل مبسّط من Markdown (صيغة توليد المقالات) لـ HTML صالح للنشر.
 * @version 1.0.0
 */
class ContentFormatter
{
    public static function markdownToHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        $escaped = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $escaped);
        $escaped = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $escaped);
        $escaped = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $escaped);
        $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $escaped);
        // روابط [نص](https://…) بس (مفيش javascript: ولا غيره)
        $escaped = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $escaped);

        $blocks = preg_split('/\n{2,}/', $escaped);
        $html = array_map(function ($block) {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            if (strpos($block, '<h') === 0) {
                return $block;
            }
            $lines = preg_split('/\n/', $block);
            // قوايم (- / * / 1.) واقتباسات (>) - كانت بتطلع كرموز خام في المقال المنشور
            if (preg_match('/^\s*[-*•] /', $lines[0]) && count(preg_grep('/^\s*[-*•] /', $lines)) === count($lines)) {
                return '<ul>' . implode('', array_map(fn ($l) => '<li>' . preg_replace('/^\s*[-*•] /', '', $l) . '</li>', $lines)) . '</ul>';
            }
            if (preg_match('/^\s*\d+[.)] /', $lines[0]) && count(preg_grep('/^\s*\d+[.)] /', $lines)) === count($lines)) {
                return '<ol>' . implode('', array_map(fn ($l) => '<li>' . preg_replace('/^\s*\d+[.)] /', '', $l) . '</li>', $lines)) . '</ol>';
            }
            if (count(preg_grep('/^&gt;\s?/', $lines)) === count($lines)) {
                return '<blockquote><p>' . implode('<br>', array_map(fn ($l) => preg_replace('/^&gt;\s?/', '', $l), $lines)) . '</p></blockquote>';
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks);

        return implode("\n", array_filter($html));
    }
}
