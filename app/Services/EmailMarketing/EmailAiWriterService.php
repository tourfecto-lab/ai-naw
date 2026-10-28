<?php

/**
 * Tourfecto - Email AI Writer (مساعد كتابة الحملات)
 * @version 1.0.0
 *
 * بيستخدم نفس طبقة الذكاء الاصطناعي في المنصة (GeminiClient → AiServiceRouting
 * task: email_marketing) فالأدمن يقدر يحوّل المهمة لأي مزوّد من لوحة التوجيه.
 * النتيجة دايمًا "اقتراح" بيرجع للمحرر والعميل هو اللي بيقرر — مفيش إرسال تلقائي.
 *
 * الإجراءات: subjects (5 عناوين)، preheader، body (HTML بسيط آمن)، rewrite، translate.
 */
class EmailAiWriterService
{
    public const ACTIONS = ['subjects', 'preheader', 'body', 'rewrite', 'translate'];

    /** وسوم HTML المسموحة في جسم الإيميل الناتج (من غير سكربت/ستايل/فورم) */
    private const ALLOWED_TAGS = '<h1><h2><h3><p><br><strong><b><em><i><ul><ol><li><a><table><tr><td><tbody><thead><th><span><div><hr><img>';

    /** @var callable|null للاختبار: fn(string $prompt): array{success:bool,data?:string,error?:string} */
    private $generator;

    public function __construct(?callable $generator = null)
    {
        $this->generator = $generator;
    }

    public function assist(string $action, string $input, array $context = []): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            return ['success' => false, 'error' => 'إجراء غير مدعوم'];
        }
        $input = trim(strip_tags($input));
        if ($input === '' && $action !== 'subjects') {
            return ['success' => false, 'error' => 'اكتب فكرة الحملة أو النص الأول'];
        }
        if (mb_strlen($input) > 6000) {
            $input = mb_substr($input, 0, 6000);
        }
        $prompt = self::buildPrompt($action, $input, $context);
        $result = $this->generate($prompt);
        if (empty($result['success']) || trim((string) ($result['data'] ?? '')) === '') {
            return ['success' => false, 'error' => $result['error'] ?? 'تعذر توليد النص حاليًا'];
        }
        $text = trim((string) $result['data']);
        if ($action === 'subjects') {
            return ['success' => true, 'suggestions' => self::parseLines($text, 5)];
        }
        if ($action === 'body') {
            return ['success' => true, 'html' => self::sanitizeHtml(self::stripCodeFence($text))];
        }
        return ['success' => true, 'text' => trim(strip_tags(self::stripCodeFence($text)))];
    }

    public static function buildPrompt(string $action, string $input, array $context): string
    {
        $company = trim(strip_tags((string) ($context['company_name'] ?? '')));
        $language = preg_match('/^[a-z]{2}$/', (string) ($context['language'] ?? '')) ? $context['language'] : 'ar';
        $rules = "أنت كاتب تسويق بالبريد لشركة سياحة" . ($company !== '' ? " اسمها \"{$company}\"" : '') . ". "
            . "اكتب باللغة ({$language}). ممنوع الوعود الكاذبة أو أسعار/خصومات مش مذكورة في المدخلات، وممنوع كلمات السبام "
            . "(مجاني!!!، اضغط الآن!!!، أحرف كبيرة كاملة). سيب متغيرات التخصيص زي {{first_name}} و{{trip_name}} زي ما هي.";
        switch ($action) {
            case 'subjects':
                return "{$rules}\nاقترح 5 عناوين (subject) مختلفة لإيميل، كل عنوان في سطر لوحده بدون ترقيم، أقل من 60 حرف، "
                    . "واحد منهم على الأقل فيه {{first_name}}.\nفكرة الحملة: " . ($input !== '' ? $input : 'عرض موسمي لرحلات الشركة');
            case 'preheader':
                return "{$rules}\nاكتب نص معاينة (preheader) واحد أقل من 90 حرف يكمّل العنوان ده بدون تكراره. النص فقط:\n{$input}";
            case 'body':
                return "{$rules}\nاكتب جسم إيميل تسويقي بصيغة HTML بسيطة (h2 وp وul وa بس، من غير style أو script أو head/body). "
                    . "افتتاحية فيها {{first_name}}، 2-3 فقرات قصيرة، ونداء واحد واضح للفعل كرابط <a href=\"#\">. HTML فقط بدون شرح.\nالفكرة: {$input}";
            case 'rewrite':
                return "{$rules}\nأعد صياغة النص ده بأسلوب أوضح وأقنع مع نفس المعنى والمتغيرات. النص فقط:\n{$input}";
            case 'translate':
                return "{$rules}\nترجم النص ده للغة ({$language}) مع الحفاظ على المتغيرات وأي HTML زي ما هو. الترجمة فقط:\n{$input}";
        }
        return $rules;
    }

    /** سطور نظيفة: من غير ترقيم/نقط/علامات تنصيص */
    public static function parseLines(string $text, int $max): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', self::stripCodeFence($text)) as $line) {
            $line = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)\-]|[\x{0660}-\x{0669}]+[.)\-])\s*/u', '', $line));
            $line = trim($line, " \t\"'“”«»");
            if ($line !== '' && mb_strlen($line) <= 150) {
                $out[] = strip_tags($line);
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    public static function stripCodeFence(string $text): string
    {
        return trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text)));
    }

    /** HTML آمن: وسوم محددة بس، من غير on*=، ولا javascript: في الروابط */
    public static function sanitizeHtml(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, self::ALLOWED_TAGS);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/(href|src)\s*=\s*(["\'])\s*(?:javascript|data|vbscript):[^"\']*\2/i', '$1="#"', $html);
        return trim($html);
    }

    private function generate(string $prompt): array
    {
        if ($this->generator) {
            return ($this->generator)($prompt);
        }
        if (!class_exists('GeminiClient')) {
            return ['success' => false, 'error' => 'خدمة الذكاء الاصطناعي غير متاحة'];
        }
        try {
            return (new GeminiClient())->generateContent($prompt, ['task' => 'email_marketing']);
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Email AI writer failed', ['message' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => 'تعذر توليد النص حاليًا'];
        }
    }
}
