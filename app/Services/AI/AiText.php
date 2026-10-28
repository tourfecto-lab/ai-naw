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
                    if ($hasNext) {
                        $client->setTimeout(25);          // لو فيه بديل منستناش 60 ثانية × إعادة محاولات
                    }
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
                        'max_tokens' => (int) min(4096, ($options['maxOutputTokens'] ?? 2000)),
                    ]);
                    if (!empty($r['success']) && trim((string) ($r['content'] ?? '')) !== '') {
                        return ['success' => true, 'data' => (string) $r['content'], 'provider' => 'openai'];
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
