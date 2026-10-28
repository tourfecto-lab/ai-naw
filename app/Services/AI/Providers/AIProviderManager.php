<?php

/**
 * Tourfecto - AI Chat Platform
 * AI Provider Manager - نقطة الدخول الوحيدة لتوليد ردود الذكاء الاصطناعي
 * في كل ميزات AI Chat. لا يجب على أي كود آخر استدعاء GeminiClient أو أي
 * مزود مباشرة؛ يجب المرور من هنا فقط (بند 20: AI Provider Abstraction).
 *
 * المسؤوليات:
 *   - بناء قائمة المزودين المتاحين والمهيّئين فقط.
 *   - تجربة المزود المفضّل، وعند الفشل تجربة التالي بالترتيب (Fallback).
 *   - تسجيل كل محاولة (نجاح/فشل) في ai_usage_logs (بند 21).
 *   - عدم كشف أي API Key للـ Frontend مطلقًا (كل الاستدعاءات من السيرفر فقط).
 *
 * الاستخدام:
 *   $manager = new AIProviderManager();
 *   $result = $manager->generateReply($systemPrompt, $messages, [
 *       'website_id' => $websiteId,
 *       'user_id' => $userId,
 *       'conversation_id' => $conversationId,
 *       'feature' => 'chat_reply',
 *   ]);
 *
 * @version 1.0.0
 */

class AIProviderManager
{
    /** @var AIProviderInterface[] */
    private $providers = [];

    /** @var Database */
    private $db;

    /**
     * @param array $preferredOrder ترتيب مخصص اختياري لأسماء المزودين، مثال: ['openai', 'gemini']
     */
    public function __construct(array $preferredOrder = [])
    {
        $this->db = Database::getInstance();

        $registry = [
            'gemini' => function () {
                return new GeminiProvider();
            },
            'openai' => function () {
                return new OpenAIProvider();
            },
            'deepseek' => function () {
                return new DeepSeekProvider();
            },
            'kimi' => function () {
                return new KimiProvider();
            },
            'anthropic' => function () {
                return new AnthropicProvider();
            },
        ];

        $order = !empty($preferredOrder) ? $preferredOrder : $this->getDefaultOrder();

        foreach ($order as $providerName) {
            if (isset($registry[$providerName])) {
                $this->providers[] = $registry[$providerName]();
            }
        }
    }

    /**
     * الترتيب الافتراضي لمحاولة المزودين، قابل للتحكم عبر AI_PROVIDER_PRIORITY
     * في .env (مثال: "openai,gemini,deepseek,kimi"). Gemini أولاً افتراضيًا
     * لأنه المزود الوحيد المُفعّل فعليًا في هذا المشروع حاليًا.
     * @return array
     */
    private function getDefaultOrder(): array
    {
        $configured = defined('AI_PROVIDER_PRIORITY') ? AI_PROVIDER_PRIORITY : (env('AI_PROVIDER_PRIORITY') ?: '');
        if (!empty($configured)) {
            return array_map('trim', explode(',', $configured));
        }
        return ['gemini', 'anthropic', 'openai', 'deepseek', 'kimi'];
    }

    /**
     * قائمة أسماء المزودين المهيّئين فعليًا (لهم API key صالح).
     * @return string[]
     */
    public function getConfiguredProviders(): array
    {
        $names = [];
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                $names[] = $provider->getName();
            }
        }
        return $names;
    }

    /**
     * لوحة Health/Status للمزودين (استجابة لتحليل المنافسين: Observability
     * كما في Gorgias/Zendesk "What's working"). تُرجع:
     *   - المزودين المهيّئين + الموديل المستخدم لكل مزود.
     *   - ملخص الاستخدام والأخطاء لآخر 24 ساعة من ai_usage_logs.
     * لا تكشف أي API Key (كل شيء للمراقبة فقط من لوحة الإدارة).
     * @param int|null $websiteId لو محدد، يحصر الملخص في موقع معيّن.
     * @return array
     */
    public function health(?int $websiteId = null): array
    {
        $modelByProvider = [
            'gemini' => defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-flash-latest', // Gemini 1.5 اتقفل من جوجل
            'openai' => defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini',
            'deepseek' => defined('DEEPSEEK_MODEL') ? DEEPSEEK_MODEL : 'deepseek-chat',
            'kimi' => defined('KIMI_MODEL') ? KIMI_MODEL : 'moonshot-v1-8k',
            'anthropic' => defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-sonnet-4-5',
        ];

        $providers = [];
        foreach ($this->providers as $provider) {
            $name = $provider->getName();
            $providers[$name] = [
                'provider' => $name,
                'model' => $modelByProvider[$name] ?? null,
                'configured' => $provider->isConfigured(),
                'priority_position' => null,
            ];
        }

        // تحديد موقع كل مزود في ترتيب الأفضلية (من getDefaultOrder)
        $order = $this->getDefaultOrder();
        foreach ($order as $index => $name) {
            if (isset($providers[$name])) {
                $providers[$name]['priority_position'] = $index + 1;
            }
        }

        // ملخص آخر 24 ساعة من سجل الاستخدام
        $summary = ['total_requests' => 0, 'success_requests' => 0, 'failed_requests' => 0,
                    'total_tokens' => 0, 'total_cost_usd' => 0.0, 'fallback_used_count' => 0,
                    'per_provider' => []];
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $where = 'created_at >= ?';
            $params = [$since];
            if ($websiteId) {
                $where .= ' AND website_id = ?';
                $params[] = $websiteId;
            }
            $rows = $this->db->query(
                "SELECT provider,
                        COUNT(*) AS total_requests,
                        SUM(tokens_total) AS total_tokens,
                        SUM(estimated_cost_usd) AS total_cost_usd,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_requests,
                        SUM(CASE WHEN status = 'fallback_used' THEN 1 ELSE 0 END) AS fallback_used_count
                 FROM ai_usage_logs
                 WHERE {$where}
                 GROUP BY provider",
                $params
            );

            foreach ($rows as $row) {
                $total = (int) ($row['total_requests'] ?? 0);
                $failed = (int) ($row['failed_requests'] ?? 0);
                $perProvider = [
                    'provider' => $row['provider'],
                    'total_requests' => $total,
                    'failed_requests' => $failed,
                    'success_requests' => $total - $failed,
                    'fallback_used_count' => (int) ($row['fallback_used_count'] ?? 0),
                    'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                    'total_cost_usd' => (float) ($row['total_cost_usd'] ?? 0),
                ];
                $summary['total_requests'] += $perProvider['total_requests'];
                $summary['success_requests'] += $perProvider['success_requests'];
                $summary['failed_requests'] += $perProvider['failed_requests'];
                $summary['total_tokens'] += $perProvider['total_tokens'];
                $summary['total_cost_usd'] += $perProvider['total_cost_usd'];
                $summary['fallback_used_count'] += $perProvider['fallback_used_count'];
                $summary['per_provider'][] = $perProvider;
            }
        } catch (Exception $e) {
            // فشل قراءة الملخص لا يكسر لوحة الصحة؛ نسجّل فقط.
            Logger::warning('AIProviderManager: failed to read usage summary for health', ['message' => $e->getMessage()]);
        }

        $status = 'no_data';
        if ($summary['total_requests'] > 0) {
            $status = $summary['failed_requests'] === 0 ? 'healthy' : 'degraded';
        }

        return [
            'providers' => array_values($providers),
            'summary_last_24h' => $summary,
            'status' => $status,
            'note' => 'This endpoint exposes configuration and usage stats only; no API keys are ever returned.',
        ];
    }

    /**
     * توليد رد، مع تجربة المزودين بالترتيب حتى ينجح أحدهم (Fallback mechanism).
     *
     * @param string $systemPrompt
     * @param array $messages
     * @param array $context ['website_id','user_id','conversation_id','feature','temperature','max_tokens','model']
     * @return array نفس شكل AIProviderInterface::generateReply + 'fallback_used' => bool
     */
    public function generateReply(string $systemPrompt, array $messages, array $context = []): array
    {
        $feature = $context['feature'] ?? 'chat_reply';
        $policy=null;
        try{$policy=class_exists('AiServiceRouting')?AiServiceRouting::policy((string)$feature):null;}catch(Throwable $e){Logger::error('AI chat routing unavailable');return AiServiceRouting::failure();}
        if($policy!==null&&!$policy['enabled'])return AiServiceRouting::failure();
        if($policy!==null&&class_exists('AIGovernor')){$context['user_id']=AIGovernor::resolveUserId($context['user_id']??null);if($blocked=AIGovernor::check($context['user_id'],(string)$feature))return AiServiceRouting::failure();}
        $providers=$this->providers;
        if($policy!==null){$providers=[];foreach($policy['chain'] as $name){$class=['gemini'=>'GeminiProvider','openai'=>'OpenAIProvider','anthropic'=>'AnthropicProvider','deepseek'=>'DeepSeekProvider','kimi'=>'KimiProvider'][$name]??null;if($class&&class_exists($class))$providers[]=new $class();}}

        $attempted = [];
        $lastResult = null;

        $available = array_values(array_filter($providers, function ($p) {
            return $p->isConfigured();
        }));

        if (empty($available)) {
            $error = 'No AI provider is configured. Add at least one API key (GEMINI_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY, DEEPSEEK_API_KEY, or KIMI_API_KEY) in .env.';
            Logger::error('AIProviderManager: no configured provider', ['feature' => $feature]);
            return [
                'success' => false, 'content' => null, 'provider' => null, 'model' => null,
                'tokens_input' => 0, 'tokens_output' => 0, 'tokens_total' => 0,
                'estimated_cost_usd' => 0, 'duration_ms' => 0, 'error' => $error, 'fallback_used' => false,
            ];
        }

        foreach ($available as $index => $provider) {
            if($policy!==null)$index=(int)array_search($provider->getName(),$policy['chain'],true);
            $providerContext=$context;if($policy!==null){$providerContext['model']=$policy['models'][$provider->getName()];$providerContext['_routing_exact']=true;}
            $result = $provider->generateReply($systemPrompt, $messages, $providerContext);
            $attempted[] = $provider->getName();
            $lastResult = $result;

            $this->logUsage($result, $context, $result['success'] ? ($index > 0 ? 'fallback_used' : 'success') : 'failed');

            if ($result['success']) {
                $result['fallback_used'] = $index > 0;
                return $result;
            }

            Logger::warning('AIProviderManager: provider failed, trying next', [
                'provider' => $provider->getName(),
                'error' => $result['error'],
                'feature' => $feature,
            ]);
        }

        // كل المزودين فشلوا
        Logger::error('AIProviderManager: all providers failed', ['attempted' => $attempted, 'feature' => $feature]);
        if($policy!==null)return AiServiceRouting::failure();
        $lastResult['fallback_used'] = count($attempted) > 1;
        return $lastResult;
    }

    /**
     * تسجيل محاولة استخدام في ai_usage_logs (بند 21).
     * @param array $result
     * @param array $context
     * @param string $status success|failed|fallback_used
     */
    private function logUsage(array $result, array $context, string $status): void
    {
        try {
            $log = new AiUsageLog();
            $log->fill([
                'website_id' => $context['website_id'] ?? null,
                'user_id' => $context['user_id'] ?? null,
                'conversation_id' => $context['conversation_id'] ?? null,
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? null,
                'feature' => $context['feature'] ?? 'chat_reply',
                'tokens_input' => $result['tokens_input'] ?? 0,
                'tokens_output' => $result['tokens_output'] ?? 0,
                'tokens_total' => $result['tokens_total'] ?? 0,
                'estimated_cost_usd' => $result['estimated_cost_usd'] ?? 0,
                'status' => $status === 'fallback_used' ? 'fallback_used' : ($result['success'] ? 'success' : 'failed'),
                'duration_ms' => $result['duration_ms'] ?? null,
                'error_message' => $result['error'] ?? null,
            ]);
            $log->save();
        } catch (Exception $e) {
            // فشل التسجيل نفسه لا يجب أبدًا أن يوقف رد الـAI للعميل
            Logger::warning('AIProviderManager: failed to write usage log', ['message' => $e->getMessage()]);
        }
    }
}
