<?php

/**
 * Tourfecto - AI Orchestrator
 *
 * طبقة توزيع المهام على مزودي الذكاء الاصطناعي. 5 خدمات في المشروع (تحليل المنافسين، خطة النمو،
 * مستشار الـ CEO، الـ Outreach، توليد المقالات) متكتبة عشان تستخدم الكلاس ده لو موجود، لكنه ما كانش
 * موجود فكانت بتروح لـ GeminiClient مباشرة. نفس التوقيع بالظبط: generateContent($prompt, $options): array.
 *
 * السلوك الافتراضي من غير أي إعداد = Gemini لكل المهام (نفس اللي كان شغّال)، يعني تركيبه آمن.
 * التوزيع اختياري من .env:
 *
 *   AI_TASK_ROUTES=strategic_seo_plan=anthropic>gemini;ceo_advisor=anthropic>gemini;article_generation=anthropic>gemini
 *
 * الصيغة: اسم_المهمة=مزود1>مزود2 (الأول هو الأساسي والباقي بدائل بالترتيب). "default=" للمهام اللي مش مذكورة.
 *
 * ضمانات:
 *  - Gemini بيتضاف دايمًا في آخر السلسلة كشبكة أمان (AI_ROUTE_GEMINI_FALLBACK=false لإلغائه).
 *  - المزود اللي مفتاحه مش متظبّط بيتخطّى بصمت.
 *  - DeepSeek و Kimi (خوادم خارج الشرق الأوسط وأوروبا) مقفولين افتراضيًا. بيانات العملاء (محادثات، مراجعات، CRM)
 *    ما ينفعش تروح لهم من غير قرارك: AI_ALLOW_CN_PROVIDERS=true.
 *  - قاطع دايرة: مزود فشل 3 مرات في 5 دقايق بيتخطّى لحد ما الفترة تعدّي (إلا لو هو الوحيد المتبقي).
 *  - الاستخدام (الميزة، العميل، الموديل، التكلفة) بيتسجّل في api_usage_logs فتظهر في صفحة "استهلاك AI" في الأدمن.
 *
 * @version 1.0.0
 */
class AIOrchestrator
{
    /** المزودين المعروفين */
    public const PROVIDERS = ['gemini', 'anthropic', 'openai', 'deepseek', 'kimi'];

    /** مزودين بنقفلهم افتراضيًا (بيانات العملاء) */
    private const RESTRICTED = ['deepseek', 'kimi'];

    private const CIRCUIT_FAILS = 3;
    private const CIRCUIT_WINDOW = 300;

    /** @var array<string,string[]> */
    private $routes;

    public function __construct(?array $routes = null)
    {
        $this->routes = $routes ?? self::parseRoutes((string) self::setting('AI_TASK_ROUTES'));
    }

    // ------------------------------------------------------------------ الإعداد

    private static function setting(string $key)
    {
        if (defined($key)) {
            return constant($key);
        }
        if (function_exists('env')) {
            $v = env($key);
            if ($v !== null) {
                return $v;      // false = القيمة "false" في .env (env() بتحوّلها)، وده قرار صريح مش "مش متظبّط"
            }
        }
        $g = getenv($key);
        return $g === false ? null : $g;
    }

    private static function flag(string $key, bool $default): bool
    {
        $v = self::setting($key);
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * "task=a>b;task2=c" → ['task' => ['a','b'], 'task2' => ['c']]
     * بيتجاهل الأسماء الغلط والصيغ الناقصة بدل ما يوقّع النظام.
     * @return array<string,string[]>
     */
    public static function parseRoutes(string $spec): array
    {
        $routes = [];
        foreach (preg_split('/[;\r\n]+/', $spec) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, '=') === false) {
                continue;
            }
            [$task, $chain] = array_map('trim', explode('=', $part, 2));
            $task = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $task));
            if ($task === '') {
                continue;
            }
            $names = [];
            foreach (explode('>', $chain) as $p) {
                $p = strtolower(trim($p));
                if (in_array($p, self::PROVIDERS, true) && !in_array($p, $names, true)) {
                    $names[] = $p;
                }
            }
            if ($names) {
                $routes[$task] = $names;
            }
        }
        return $routes;
    }

    /**
     * السلسلة الفعلية لمهمة معيّنة بعد تطبيق الحماية.
     * @return string[]
     */
    public function chainFor(string $task): array
    {
        $task = strtolower($task);
        if(class_exists('AiServiceRouting') && ($policy=AiServiceRouting::policy($task))!==null)return $policy['enabled']?$policy['chain']:[];
        $chain = $this->routes[$task] ?? $this->routes['default'] ?? ['gemini'];

        if (!self::flag('AI_ALLOW_CN_PROVIDERS', false)) {
            $chain = array_values(array_diff($chain, self::RESTRICTED));
        }
        $chain = array_values(array_filter($chain, function ($p) {
            return $p === 'gemini' || $this->isConfigured($p);
        }));
        if (self::flag('AI_ROUTE_GEMINI_FALLBACK', true) && !in_array('gemini', $chain, true)) {
            $chain[] = 'gemini';
        }
        return $chain ?: ['gemini'];
    }

    /** @return array<string,array> لوحة الأدمن: التوزيع الحالي بدون أي مفاتيح */
    public function describe(): array
    {
        $tasks = array_unique(array_merge(['competitor_analysis', 'strategic_seo_plan', 'ceo_advisor', 'content_generation', 'article_generation', 'default'], array_keys($this->routes)));
        $out = ['tasks' => [], 'providers' => [], 'cn_allowed' => self::flag('AI_ALLOW_CN_PROVIDERS', false), 'gemini_fallback' => self::flag('AI_ROUTE_GEMINI_FALLBACK', true)];
        foreach ($tasks as $t) {
            $out['tasks'][$t] = $t === 'default' ? ($this->routes['default'] ?? ['gemini']) : $this->chainFor($t);
        }
        foreach (self::PROVIDERS as $p) {
            $out['providers'][$p] = ['configured' => $p === 'gemini' ? $this->geminiConfigured() : $this->isConfigured($p), 'restricted' => in_array($p, self::RESTRICTED, true)];
        }
        if(class_exists('AiServiceRouting')){$state=AiServiceRouting::state();$out['admin_routing']=['ready'=>$state['ready'],'revision'=>$state['revision'],'routes'=>$state['routes'],'url'=>'/admin/ai-routing'];}
        return $out;
    }

    // ------------------------------------------------------------------ المزودين

    protected function geminiConfigured(): bool
    {
        if(class_exists('AiServiceRouting'))return AiServiceRouting::apiKey('gemini')!=='';
        return defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY !== '' : (string) self::setting('GEMINI_API_KEY') !== '';
    }

    protected function makeProvider(string $name)
    {
        switch ($name) {
            case 'anthropic':
                return class_exists('AnthropicProvider') ? new AnthropicProvider() : null;
            case 'openai':
                return class_exists('OpenAIProvider') ? new OpenAIProvider() : null;
            case 'deepseek':
                return class_exists('DeepSeekProvider') ? new DeepSeekProvider() : null;
            case 'kimi':
                return class_exists('KimiProvider') ? new KimiProvider() : null;
        }
        return null;
    }

    protected function makeGemini()
    {
        return new GeminiClient();
    }

    private function isConfigured(string $name): bool
    {
        try {
            $p = $this->makeProvider($name);
            return $p !== null && $p->isConfigured();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ التنفيذ

    /**
     * نفس توقيع وشكل رد GeminiClient::generateContent.
     * @return array{success:bool, data?:string, error?:string, tokens_used?:int, cost?:float, model?:string, provider?:string, fallback_used?:bool}
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        $prompt = ContentLanguageService::apply($prompt, $options);
        $task = isset($options['task']) ? strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $options['task'])) : 'default';
        try {
            $policy=class_exists('AiServiceRouting')?AiServiceRouting::policy($task ?: 'default'):null;
            $chain=$policy!==null?($policy['enabled']?$policy['chain']:[]):$this->chainFor($task ?: 'default');
        } catch(Throwable $e){Logger::error('AI routing unavailable',['task'=>$task]);return AiServiceRouting::failure();}
        if($policy!==null&&!$policy['enabled'])return AiServiceRouting::failure();
        if($policy!==null&&class_exists('AIGovernor')){$options['user_id']=AIGovernor::resolveUserId($options['user_id']??null);if($blocked=AIGovernor::check($options['user_id'],$task))return ['success'=>false,'error'=>$blocked['message'],'error_kind'=>'limit','http_code'=>429];}

        $json = (($options['responseMimeType'] ?? '') === 'application/json');

        $last = null;
        foreach ($chain as $i => $name) {
            $isLast = $i === count($chain) - 1;
            if (!$isLast && $this->circuitOpen($name)) {
                continue;     // مزود واقع حاليًا: نعدّي عليه طالما فيه بديل
            }

            $providerOptions=$options;
            if($policy!==null){$providerOptions['_routing_exact']=true;$providerOptions['model']=$policy['models'][$name];}
            $result = $name === 'gemini' ? $this->callGemini($prompt, $providerOptions) : $this->callProvider($name, $prompt, $providerOptions, $json);
            if (!empty($result['success'])) {
                $this->circuitReset($name);
                $result['provider'] = $name;
                $result['fallback_used'] = $i > 0;
                return $result;
            }
            $this->circuitFail($name);
            $last = $result;
            if (class_exists('Logger')) {
                Logger::warning('AIOrchestrator: provider failed', ['provider' => $name, 'task' => $task, 'error' => substr((string) ($result['technical_error'] ?? $result['error'] ?? ''), 0, 200)]);
            }
        }
        if($policy!==null)return AiServiceRouting::failure();
        return $last ?? ['success' => false, 'error' => 'AI is unavailable'];
    }

    private function callGemini(string $prompt, array $options): array
    {
        try {
            $options['_routing_bypass']=true;
            return $this->makeGemini()->generateContent($prompt, $options);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Gemini error', 'technical_error' => $e->getMessage()];
        }
    }

    private function callProvider(string $name, string $prompt, array $options, bool $json): array
    {
        $started = microtime(true);
        try {
            $provider = $this->makeProvider($name);
            if ($provider === null || !$provider->isConfigured()) {
                return ['success' => false, 'error' => 'not configured', 'technical_error' => $name . ' not configured'];
            }
            if ($json) {
                $prompt .= "\n\nReturn ONLY valid JSON. No markdown fences, no commentary.";
            }
            $res = $provider->generateReply('', [['role' => 'user', 'content' => $prompt]], [
                'max_tokens' => (int) ($options['maxOutputTokens'] ?? 4096),
                'temperature' => $options['temperature'] ?? null,
                'model' => $options['model'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'provider error', 'technical_error' => $e->getMessage()];
        }

        if (empty($res['success'])) {
            return ['success' => false, 'error' => $this->friendly($name), 'technical_error' => (string) ($res['error'] ?? 'unknown')];
        }

        $text = (string) ($res['content'] ?? '');
        if ($json) {
            $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)));
        }
        $out = [
            'success' => true,
            'data' => $text,
            'tokens_used' => (int) ($res['tokens_total'] ?? 0),
            'cost' => (float) ($res['estimated_cost_usd'] ?? 0),
            'http_code' => 200,
            'model' => $res['model'] ?? null,
        ];
        $this->logUsage($name, $options, $out, (int) round((microtime(true) - $started) * 1000));
        return $out;
    }

    private function friendly(string $provider): string
    {
        $msg = function_exists('t') ? t('ai.error.generic') : 'Something went wrong with the AI service. Please try again.';
        return $msg === 'ai.error.generic' ? 'Something went wrong with the AI service. Please try again.' : $msg;
    }

    /** يسجّل في api_usage_logs (نفس جدول Gemini) فتظهر كل المزودين في صفحة استهلاك AI */
    protected function logUsage(string $provider, array $options, array $out, int $durationMs): void
    {
        $feature = isset($options['task']) ? substr(preg_replace('/[^a-z0-9_\-]/i', '', (string) $options['task']), 0, 60) : null;
        $row = [
            ':u' => !empty($options['user_id']) ? (int) $options['user_id'] : null,
            ':f' => $feature,
            ':m' => $out['model'] ?? null,
            ':s' => 200,
            ':t' => (int) $out['tokens_used'],
            ':c' => (float) $out['cost'],
            ':d' => $durationMs,
        ];
        $sql = 'INSERT INTO api_usage_logs (user_id, api_type, feature, model, endpoint, status_code, tokens_used, cost_in_usd, duration_ms)
                VALUES (:u, :p, :f, :m, :e, :s, :t, :c, :d)';
        try {
            Database::getInstance()->query($sql, $row + [':p' => $provider, ':e' => $out['model'] ?? $provider]);
        } catch (\Throwable $e) {
            // عمود api_type ENUM وممكن مايكونش فيه اسم المزود (anthropic مثلًا) قبل تشغيل TOURFECTO_FIX_AI_USAGE_ENUM.sql:
            // نسجّل تحت "other" وبنحط اسم المزود في endpoint عشان التتبّع ما يضيعش.
            try {
                Database::getInstance()->query($sql, $row + [':p' => 'other', ':e' => $provider . ':' . ($out['model'] ?? '')]);
            } catch (\Throwable $e2) {
                // التتبّع مش لازم يكسر الرد
            }
        }
    }

    // ------------------------------------------------------------------ قاطع الدايرة

    protected function circuitFile(): string
    {
        $base = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE : sys_get_temp_dir();
        $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'ai_circuit.json';
    }

    private function circuitRead(): array
    {
        $f = $this->circuitFile();
        $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
        return is_array($d) ? $d : [];
    }

    private function circuitWrite(array $d): void
    {
        @file_put_contents($this->circuitFile(), json_encode($d), LOCK_EX);
    }

    private function circuitOpen(string $name): bool
    {
        $now = time();
        $fails = array_filter($this->circuitRead()[$name] ?? [], function ($t) use ($now) {
            return $t > $now - self::CIRCUIT_WINDOW;
        });
        return count($fails) >= self::CIRCUIT_FAILS;
    }

    private function circuitFail(string $name): void
    {
        $now = time();
        $d = $this->circuitRead();
        $d[$name] = array_values(array_filter(array_merge($d[$name] ?? [], [$now]), function ($t) use ($now) {
            return $t > $now - self::CIRCUIT_WINDOW;
        }));
        $this->circuitWrite($d);
    }

    private function circuitReset(string $name): void
    {
        $d = $this->circuitRead();
        if (!empty($d[$name])) {
            unset($d[$name]);
            $this->circuitWrite($d);
        }
    }
}
