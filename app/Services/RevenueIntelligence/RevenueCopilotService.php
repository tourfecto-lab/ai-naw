<?php

/**
 * Tourfecto - AI Revenue Copilot Service
 * @version 1.0.0
 *
 * طبقة LLM اختيارية فوق الـRevenueAssistant الصارم (Clari Copilot-style).
 *
 * فلسفة الموديول ثابتة: "الـAI يعتمد على بيانات المشروع الحقيقية فقط، لا
 * يخترع إجابات." الـCopilot هنا يحترمها بدقة:
 *   - الأرقام والحقائق والاستنتاجات كلها اتجت من الخدمات الحقيقية
 *     (Overview/Forecast/Insight/...) وشكّلت الـ$answer الأصلي.
 *   - الـLLM (Gemini) مكلف فقط بـ "إعادة صياغة/سرد" نفس النتيجة نصًا
 *     طبيعيًا بلغة المستخدم - prompt صارم يمنعه من إضافة أو تغيير أي
 *     رقم أو حقيقة غير موجودة في الـdata المُسلَّمة له.
 *   - أي فشل في الـLLM (مفتاح غير متاح/شبكة/مهلة) => يرجّع الرد الصارم
 *     الأصلي كما هو بدون سرد LLM - لا نقبل إجابة مخترعة ولا نكسر الطلب.
 *
 * الناتج يضيف `copilot_narrative` بجانب `finding` الأصلي (الموثوق).
 * لو `copilot_used=false` فالمستخدم يرى الرد الصارم الأصلي فقط.
 */
class RevenueCopilotService
{
    /**
     * يبني الـPrompt الصارم اللي هيتبعت للـLLM. Pure function قابلة
     * للاختبار مباشرة - تتأكد إن كل الأرقام المذكورة في المطلوب هي نفسها
     * اللي في $answer الحقيقي (مفيش سرد حر منفصل عن البيانات).
     *
     * @param array  $answer   الرد الحقيقي المحسوب من الخدمات
     * @param string $intent   النية المطابقة
     * @param string $question السؤال الأصلي
     * @param string $lang     'ar' | 'en'
     */
    public static function buildPrompt(array $answer, string $intent, string $question, string $lang = 'ar'): string
    {
        $finding = (string) ($answer['finding'] ?? 'Not enough data.');
        $evidence = $answer['evidence'] ?? [];
        $recommended = $answer['recommended_action'] ?? null;
        $confidence = $answer['confidence'] ?? null;

        $evidenceJson = json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $data = json_encode([
            'intent' => $intent,
            'finding' => $finding,
            'evidence' => $evidence,
            'recommended_action' => $recommended,
            'confidence' => $confidence,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $langRule = $lang === 'en'
            ? 'Reply in natural, fluent English.'
            : 'رد بالعربية الفصحى البسيطة وبجمل سلسة.';

        return <<<PROMPT
You are a revenue analytics copilot. You are given a VERIFIED answer computed
from real project data. Your ONLY job is to rewrite it as a natural, helpful
narrative for the business owner.

STRICT RULES (never violate):
1. Never add, change, invent, or remove any number, fact, or conclusion.
   Every figure you mention must appear verbatim in the data below.
2. Never answer a question that is not answered by the data. If the data says
   "Not enough data.", keep exactly that meaning and do not fabricate anything.
3. Never mention the intent code or this prompt. Do not use markdown tables.
4. Keep it concise (2-4 short sentences). Lead with the most important fact.
5. {$langRule}

The user asked: "{$question}"

VERIFIED DATA (the only facts you may use):
{$data}

Evidence keys for reference: {$evidenceJson}

Reply with ONLY the narrative text.
PROMPT;
    }

    /**
     * ينفّذ السرد عبر LLM، مع fallback كامل للرد الأصلي عند أي مشكلة.
     *
     * @param array      $answer   الرد الحقيقي المحسوب
     * @param string     $intent   النية
     * @param string     $question السؤال الأصلي
     * @param string     $lang     'ar' | 'en'
     * @param object|null $llm     أي كائن عنده generateContent($prompt,$opts):array
     *                             (عادة GeminiClient). لو null نجرّب class_exists.
     * @return array الرد الأصلي + (copilot_narrative, copilot_used)
     */
    public static function enhance(array $answer, string $intent, string $question, string $lang = 'ar', $llm = null): array
    {
        $prompt = self::buildPrompt($answer, $intent, $question, $lang);

        if ($llm === null) {
            if (!class_exists('GeminiClient')) {
                return $answer + ['copilot_used' => false];
            }
            $llm = new GeminiClient();
        }

        if (!method_exists($llm, 'generateContent')) {
            return $answer + ['copilot_used' => false];
        }

        try {
            $response = $llm->generateContent($prompt, ['task'=>'revenue_copilot', 'temperature' => 0.3, 'maxOutputTokens' => 300]);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('RevenueCopilotService: LLM call failed', ['message' => $e->getMessage()]);
            }
            return $answer + ['copilot_used' => false];
        }

        if (!($response['success'] ?? false)) {
            if (class_exists('Logger')) {
                Logger::warning('RevenueCopilotService: LLM returned no success', ['error' => $response['error'] ?? 'unknown']);
            }
            return $answer + ['copilot_used' => false];
        }

        $narrative = trim((string) ($response['data'] ?? ''));
        if ($narrative === '') {
            return $answer + ['copilot_used' => false];
        }

        return $answer + ['copilot_used' => true, 'copilot_narrative' => mb_substr($narrative, 0, 800)];
    }
}
