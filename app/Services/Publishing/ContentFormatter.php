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

        // مستويات العناوين نسبية: أعلى مستوى مستخدم في المقال بيبقى <h2> (الـ <h1> هو عنوان الصفحة في ووردبريس)،
        // وكل مستوى تحته بيزيد واحد. كده مفيش تخطي من h1 لـ h3 سواء المولّد كتب # أو ##.
        $minLevel = 6;
        if (preg_match_all('/^(#{1,6}) \S/m', $escaped, $levels)) {
            $minLevel = min(array_map('strlen', $levels[1]));
        }
        $escaped = preg_replace_callback('/^(#{1,6}) (.*)$/m', static function ($m) use ($minLevel) {
            $level = min(6, 2 + (strlen($m[1]) - $minLevel));
            // سطر فاضي قبل وبعد العنوان عشان الفقرة اللي بعده تتلف في <p> مش تفضل نص خام
            return "

<h{$level}>" . trim($m[2]) . "</h{$level}>

";
        }, $escaped);
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
