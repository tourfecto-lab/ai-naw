<?php

/**
 * Tourfecto - Vendor Mask
 *
 * العميل ما يشوفش أبدًا مين المزوّد اللي بيولّد المحتوى (Gemini, OpenAI, Claude...) ولا مواقعهم (Google AI Studio...)
 * ولا أكواد الأخطاء التقنية (HTTP 429, GEM-503...). ده كان بيظهر في رسائل الأخطاء وفي نصوص الواجهة.
 *
 * بيتطبّق مركزيًا في Controller::error() / success(): أي رسالة لمستخدم عادي بتتنضّف مهما كان مصدرها (حتى لو استثناء خام).
 * الأدمن بيشوف التفاصيل التقنية عشان يشخّص، لكن مفاتيح الـ API (sk-..., AIza...) بتتخفي عن الكل بما فيهم الأدمن.
 *
 * @version 1.0.0
 */
class VendorMask
{
    /** مفاتيح على الجميع */
    private const SECRET_PATTERNS = [
        '/sk-(?:proj-|ant-api\d+-)?[A-Za-z0-9_\-]{16,}/',
        '/AIza[0-9A-Za-z_\-]{20,}/',
        '/\bAQ\.[A-Za-z0-9_\-]{20,}/',
        '/GOCSPX-[A-Za-z0-9_\-]{10,}/',
        '/\bBearer\s+[A-Za-z0-9._\-]{20,}/',
    ];

    /** مفاتيح تفاصيل فنية بتتشال من details للمستخدم العادي */
    private const TECH_KEYS = ['http', 'http_code', 'api_status', 'technical', 'technical_error', 'provider', 'model', 'checks', 'diag_code', 'wp_code'];

    public static function scrubSecrets(string $s): string
    {
        foreach (self::SECRET_PATTERNS as $p) {
            $s = preg_replace($p, '[hidden]', $s) ?? $s;
        }
        return $s;
    }

    /** نص لمستخدم عادي: من غير أي ذكر لمزوّد أو موقع أو كود تقني */
    public static function scrub(string $s): string
    {
        $s = self::scrubSecrets($s);
        $ar = (bool) preg_match('/\p{Arabic}/u', $s);
        $generic = $ar ? 'خدمة الذكاء الاصطناعي' : 'the AI service';

        // رسائل API الخام: "Gemini Image API Error (HTTP 429): ..." / "API Error (HTTP 503): ..."
        $s = preg_replace('/[^\n.]*\bAPI\s+Error\s*\(HTTP\s*\d{3}\)\s*:?[^\n]*/iu', $ar ? 'حصلت مشكلة في خدمة الذكاء الاصطناعي. جرّب تاني بعد شوية.' : 'The AI service had a problem. Please try again shortly.', $s) ?? $s;
        // "HTTP 503 from generativelanguage.googleapis.com" و أي مضيف مزوّد من غير http://
        $s = preg_replace('#(?:\(?HTTP\s*\d{3}\)?\s+)?(?:from|at|to|via)\s+[\w.\-]*(?:googleapis|openai|anthropic|deepseek|moonshot)[\w.\-/?=&%]*#i', '', $s) ?? $s;
        $s = preg_replace('#\b[\w.\-]*(?:googleapis|openai|anthropic|deepseek|moonshot)\.[a-z]{2,}[\w.\-/?=&%]*#i', '', $s) ?? $s;
        // روابط المزوّدين
        $s = preg_replace('#https?://[^\s"\'<>]*(?:googleapis|openai|anthropic|deepseek|moonshot|aistudio)[^\s"\'<>]*#i', '', $s) ?? $s;
        // أكواد تشخيص بين أقواس مربعة
        $s = preg_replace('/\s*\[(?:GEM|OPE|ANT|DEE|KIM|HTTP)[^\]]*\]/u', '', $s) ?? $s;
        $s = preg_replace('/\s*\[[A-Z]{3}\]\s*$/u', '', $s) ?? $s;
        // أسماء المزوّدين والمواقع والموديلات
        $names = 'Google\s+AI\s+Studio|AI\s+Studio|Generative\s+Language\s+API|Vertex\s*AI|Gemini(?:[-\s]?(?:\d[\w.]*|pro|flash|ultra|nano|image|lite|preview|latest|exp)[\w.\-]*)*|OpenAI|Open\s+AI|ChatGPT|GPT-?[\w.\-]+|gpt-image-\d+|DALL[·\-\s]?E[\s\-]?\d*|Anthropic|Claude(?:[-\s]?(?:\d[\w.]*|sonnet|opus|haiku|instant)[\w.\-]*)*|DeepSeek|Kimi|Moonshot(?:\s*AI)?';
        $replaced = preg_replace('/\b(?:' . $names . ')\b/iu', $generic, $s);
        if ($replaced === null) {
            // regex اتكسر لأي سبب: أمان قبل كل شيء. بنرجّع رسالة عامة بدل ما نسرّب النص.
            return $ar ? 'حصلت مشكلة في خدمة الذكاء الاصطناعي. جرّب تاني بعد شوية.' : 'The AI service had a problem. Please try again shortly.';
        }
        $s = $replaced;
        // أسماء متغيرات الإعدادات
        $s = preg_replace('/\b(?:GEMINI|OPENAI|ANTHROPIC|DEEPSEEK|KIMI|ARTICLE_IMAGE)[A-Z_]*\b/u', '', $s) ?? $s;
        // تكرار: "خدمة الذكاء الاصطناعي خدمة الذكاء الاصطناعي"
        $s = preg_replace('/(خدمة الذكاء الاصطناعي|the AI service)(\s+\1)+/u', '$1', $s) ?? $s;
        $s = preg_replace('/\b(the|a|an)\s+the AI service/iu', 'the AI service', $s) ?? $s;
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** details لمستخدم عادي: من غير أي حقل فني */
    public static function scrubDetails(array $details): array
    {
        foreach (self::TECH_KEYS as $k) {
            unset($details[$k]);
        }
        foreach ($details as $k => $v) {
            if (is_string($v)) {
                $details[$k] = self::scrub($v);
            } elseif (is_array($v)) {
                $details[$k] = self::scrubDetails($v);
            }
        }
        return $details;
    }

    public static function isStaff($user): bool
    {
        $role = is_array($user) ? (string) ($user['role'] ?? '') : '';
        return in_array($role, ['admin', 'super_admin'], true);
    }
}
