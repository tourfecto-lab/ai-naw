<?php

/**
 * Tourfecto - توليد نص بمزوّد احتياطي
 * بيجرّب Gemini الأول (بمهلة قصيرة) ولو فشل/اتأخر/المفتاح غلط بيكمّل على OpenAI تلقائيًا،
 * عشان أزرار "توليد بالذكاء الاصطناعي" ماتبقاش ميتة لما مزوّد واحد يقع.
 * الترتيب: TEXT_AI_PROVIDERS في .env (افتراضي "gemini>openai").
 * بيرجّع نفس شكل GeminiClient::generateContent: ['success'=>bool, 'data'=>string, 'error'=>string]
 * @version 1.0.0
 */
class AiText
{
    /**
     * مهلة طلب Gemini بالثواني: قصيرة للردود القصيرة لما فيه بديل، وأطول للإخراج الكبير (الموديلات اللي بتفكّر بطيئة).
     * التراكم محمي بميزانية إعادة المحاولة داخل GeminiClient (GEMINI_RETRY_BUDGET).
     */
    public static function geminiTimeout(int $maxOutputTokens, bool $hasFallback): int
    {
        $base = $hasFallback ? 25 : 60;
        $extra = $maxOutputTokens > 4000 ? (int) ceil($maxOutputTokens / 150) : 0;
        return min(90, $base + $extra);
    }

    /**
     * سقف الإخراج لمسار OpenAI: موديلات gpt-4o/gpt-4.1/o* بتدعم 16k توكن إخراج، والقديمة 4096.
     * قبل كده كان السقف 4096 ثابت، وخطة السيو (12000) كانت ممكن تتقطع وتفشل الدورة على المواقع الكبيرة.
     */
    public static function openAiOutputCap(string $model, int $requested): int
    {
        $limit = preg_match('/^(?:gpt-4o|gpt-4\.1|o\d)/i', trim($model)) === 1 ? 16000 : 4096;
        return max(256, min($limit, $requested));
    }

    public static function generate(string $prompt, array $options = [], ?GeminiClient $gemini = null): array
    {
        $prompt = ContentLanguageService::apply($prompt, $options);
        if(class_exists('AiServiceRouting')){try{if(AiServiceRouting::policy((string)($options['task']??'default'))!==null)return (new AIOrchestrator())->generateContent($prompt,$options);}catch(Throwable $e){Logger::error('AI routing unavailable');return AiServiceRouting::failure();}}
        $spec = function_exists('env') ? env('TEXT_AI_PROVIDERS') : null;
        $spec = is_string($spec) && $spec !== '' ? $spec : 'gemini>openai';
        $chain = [];
        foreach (explode('>', strtolower($spec)) as $p) {
            $p = trim($p);
            if (in_array($p, ['gemini', 'openai'], true) && !in_array($p, $chain, true)) {
                $chain[] = $p;
            }
        }
        $chain = $chain ?: ['gemini', 'openai'];

        $last = ['success' => false, 'error' => 'لا يوجد مزوّد ذكاء اصطناعي متاح'];
        foreach ($chain as $i => $provider) {
            $hasNext = $i < count($chain) - 1;
            try {
                if ($provider === 'gemini') {
                    $client = $gemini ?? new GeminiClient();
                    // المهلة حسب حجم الإخراج المطلوب: 25 ثانية كانت بتقطع خطة السيو (12000 توكن) وبتحوّلها لـOpenAI كل مرة
                    $client->setTimeout(self::geminiTimeout((int) ($options['maxOutputTokens'] ?? 0), $hasNext));
                    $r = $client->generateContent($prompt, $options);
                    if (!empty($r['success']) && trim((string) ($r['data'] ?? '')) !== '') {
                        return $r;
                    }
                    $last = ['success' => false, 'error' => (string) ($r['error'] ?? 'فشل Gemini')];
                } else {
                    if (!class_exists('OpenAIProvider')) {
                        continue;
                    }
                    $oa = new OpenAIProvider();
                    if (!$oa->isConfigured()) {
                        continue;
                    }
                    $r = $oa->generateReply('', [['role' => 'user', 'content' => $prompt]], [
                        'temperature' => (float) ($options['temperature'] ?? 0.7),
                        'max_tokens' => self::openAiOutputCap(defined('OPENAI_MODEL') ? (string) OPENAI_MODEL : 'gpt-4o-mini', (int) ($options['maxOutputTokens'] ?? 2000)),
                    ]);
                    if (!empty($r['success']) && trim((string) ($r['content'] ?? '')) !== '') {
                        return ['success' => true, 'data' => (string) $r['content'], 'provider' => 'openai', 'model' => (string) ($r['model'] ?? ''), 'tokens_used' => (int) ($r['tokens_total'] ?? 0), 'cost' => (float) ($r['estimated_cost_usd'] ?? 0)];
                    }
                    $last = ['success' => false, 'error' => (string) ($r['error'] ?? 'فشل OpenAI')];
                }
            } catch (Throwable $e) {
                $last = ['success' => false, 'error' => $e->getMessage()];
            }
            Logger::warning('AiText provider failed', ['provider' => $provider, 'error' => substr($last['error'], 0, 200)]);
        }
        return $last;
    }
}
