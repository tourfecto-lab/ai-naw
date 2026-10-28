<?php

/**
 * Tourfecto - Article Image Service
 *
 * المقالات كانت بتتنشر على WordPress من غير صورة بارزة خالص: مفيش كود بيولّد صورة ولا بيرفعها لمكتبة الوسائط،
 * فالقالب كان بيعرض المربع الرمادي (600x400). الخدمة دي:
 *   1) تبني وصف صورة من المقال (ذكاء اصطناعي، ولو فشل بنبنيه من العنوان) مع قيود: من غير نص ولا شعارات ولا وجوه حقيقية.
 *   2) تولّد الصورة بـ Gemini (16:9) وتتأكد إنها صورة فعلاً، وتصغّرها لـ 1600px كحد أقصى وتحوّلها JPEG (حجم معقول للموقع).
 *   3) تحفظها عندنا (معاينة وإعادة محاولة) وترفعها لمكتبة وسائط WordPress وتحطها كصورة بارزة (featured_media).
 *
 * أي فشل في الصورة مش بيمنع نشر المقال: بيتنشر من غير صورة وبيرجع تحذير واضح.
 * ضبط من .env: ARTICLE_IMAGES_ENABLED (افتراضي true)، ARTICLE_IMAGE_DAILY_LIMIT (افتراضي 30 لكل عميل)،
 * GEMINI_IMAGE_COST_USD (تكلفة تقديرية للصورة في صفحة استهلاك AI، افتراضي 0.04).
 *
 * @version 1.0.0
 */
class ArticleImageService
{
    public const COLUMNS = ['featured_image_path', 'featured_image_alt', 'featured_image_prompt', 'wp_media_id', 'image_error'];
    private const MAX_WIDTH = 1600;
    private const MAX_BYTES = 6000000;

    private static $colsReady = null;

    /** @var object|null */
    private $gemini;
    /** @var object|null */
    private $ai;
    /** @var object|null */
    private $openai;

    public function __construct($gemini = null, $ai = null, $openai = null)
    {
        $this->gemini = $gemini;
        $this->ai = $ai;
        $this->openai = $openai;
    }

    public static function make(): self
    {
        return new self();
    }

    // ------------------------------------------------------------------ جاهزية

    /** هل ملف TOURFECTO_ARTICLE_IMAGES.sql اتشغّل؟ (من غيره بنتخطى الصور من غير ما نكسر النشر) */
    public static function columnsReady(): bool
    {
        if (self::$colsReady !== null) {
            return self::$colsReady;
        }
        $count = function () {
            $in = "'" . implode("','", self::COLUMNS) . "'";
            $r = Database::getInstance()->query(
                "SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_articles' AND column_name IN ($in)"
            );
            return (int) ($r[0]['c'] ?? 0);
        };
        try {
            if ($count() === count(self::COLUMNS)) {
                return self::$colsReady = true;
            }
            // الأعمدة ناقصة (ملف TOURFECTO_ARTICLE_IMAGES.sql ما اتشغّلش): بنضيفها بنفسنا بدل ما الصور تتعطّل بصمت.
            // إضافة أعمدة بس (ADD COLUMN IF NOT EXISTS)، مفيش حذف ولا تعديل لبيانات.
            // ملاحظة: "ADD COLUMN IF NOT EXISTS" موجودة في MariaDB بس ومش
            // مدعومة في MySQL (الاستضافة الجديدة)، فبنضيف كل عمود ناقص لوحده.
            $defs = [
                'featured_image_path' => 'VARCHAR(255) NULL',
                'featured_image_alt' => 'VARCHAR(255) NULL',
                'featured_image_prompt' => 'TEXT NULL',
                'wp_media_id' => 'BIGINT UNSIGNED NULL',
                'image_error' => 'VARCHAR(255) NULL',
            ];
            $existing = array_map(
                static fn ($row) => strtolower((string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? '')),
                Database::getInstance()->query(
                    "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_articles'"
                ) ?: []
            );
            foreach ($defs as $col => $def) {
                if (!in_array($col, $existing, true)) {
                    Database::getInstance()->query("ALTER TABLE `ai_articles` ADD COLUMN `{$col}` {$def}");
                }
            }
            if (class_exists('Logger')) {
                Logger::warning('ArticleImageService: added missing ai_articles image columns automatically');
            }
            return self::$colsReady = ($count() === count(self::COLUMNS));
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('ArticleImageService: columns missing and auto-migration failed', ['message' => $e->getMessage()]);
            }
            return self::$colsReady = false;
        }
    }

    public static function resetCache(): void
    {
        self::$colsReady = null;
    }

    public static function enabled(): bool
    {
        // env() بيحوّل النص "false" في .env لـ boolean false (و null لو مش متظبّط)، فنفرّق بينهم
        if (function_exists('env')) {
            $v = env('ARTICLE_IMAGES_ENABLED');
            if ($v === null || $v === '') {
                return true;
            }
            return is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
        }
        $g = getenv('ARTICLE_IMAGES_ENABLED');
        return $g === false || $g === '' || in_array(strtolower($g), ['1', 'true', 'yes', 'on'], true);
    }

    private static function setting(string $key, int $default): int
    {
        $v = function_exists('env') ? env($key) : getenv($key);
        return ($v !== null && $v !== false && $v !== '' && is_numeric($v)) ? (int) $v : $default;
    }

    // ------------------------------------------------------------------ مسارات

    protected function root(): string
    {
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
    }

    public function absPath(string $rel): string
    {
        return $this->root() . '/public_html' . $rel;
    }

    public static function publicUrl(?string $rel): ?string
    {
        if (!$rel) {
            return null;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : (function_exists('env') ? env('APP_URL') : '')), '/');
        return $base . $rel;
    }

    // ------------------------------------------------------------------ الوصف

    /** @return array{prompt:string, alt:string} */
    public function buildPrompt(array $article, int $userId): array
    {
        $title = trim((string) ($article['title'] ?? ''));
        $topic = trim((string) ($article['topic'] ?? ''));
        $lang = (string) ($article['target_language'] ?? 'ar');
        $subject = $topic !== '' ? $topic : $title;
        $keywords = [];
        $raw = $article['suggested_keywords'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw)) {
            $keywords = array_slice(array_values(array_filter(array_map('strval', $raw))), 0, 4);
        }

        // تصحيح: الموديل كان بيدمج كل الكلمات المفتاحية (luxury + pyramids + camel ...) في مشهد واحد
        // مش موجود في الحقيقة (فيلا وحمام سباحة لازقين في الأهرامات، جمل ماشي على التراس).
        // دلوقتي: موضوع واحد حقيقي، منظور حقيقي ممكن يتصوّر فعلًا، ومن غير تركيب عناصر من أماكن مختلفة.
        $style = ' Documentary-style photorealistic travel photograph, as shot by a professional photographer on location, '
            . 'natural light, true-to-life proportions, real-world geography and scale, wide 16:9 composition. '
            . 'The scene must look like an unedited real photo of an existing view: a single coherent location, '
            . 'landmarks with their correct real shape, number and surroundings, animals and people only where they really appear '
            . '(on sand, streets or paths, never on furniture, terraces or pool decks). '
            . 'No collage, no composite of different places, no invented buildings next to monuments, '
            . 'no text, no captions, no logos, no watermarks, no recognizable real people.';

        $scene = '';
        $alt = '';
        $recent = $this->recentScenes($userId, (int) ($article['id'] ?? 0));
        $ai = $this->ai ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : null);
        if ($ai !== null) {
            $q = "Create a blog hero image brief.\nArticle title: {$title}\nTopic: {$subject}\nKeywords: " . implode(', ', $keywords)
                . "\nArticle language for the alt text: {$lang}\n\n"
                . "Rules for image_prompt:\n"
                . "- Pick ONE main real subject only (one landmark, one street, one hotel interior, one river view...). "
                . "Do NOT try to show every keyword; abstract words like luxury, upscale, legacy, experiences, tours must NOT become extra objects in the scene.\n"
                . "- Describe a view that really exists and could be photographed from a real, accessible spot (say from where, e.g. 'seen from the Giza plateau panoramic point').\n"
                . "- Never place hotels, villas, pools, furniture or modern buildings next to ancient monuments unless they are really there at that exact spot.\n"
                . "- Landmarks must keep their true appearance, count and setting (e.g. the three main Giza pyramids rise from open desert, the city lies behind them).\n"
                . "- At most one secondary element (e.g. a camel caravan on the sand, a felucca on the Nile), placed where it naturally appears.\n"
                . "- No people's faces in focus, no text or signs.\n"
                . ($recent ? "- This blog already used these hero scenes. Do NOT repeat their main subject, viewpoint, time of day or composition; pick a clearly different but still relevant real view:\n  * " . implode("\n  * ", $recent) . "\n" : '')
                . "\n"
                . "Return ONLY JSON: {\"image_prompt\": \"one concrete, photographable real-world scene in English, max 60 words, following the rules\", "
                . "\"alt_text\": \"a plain description of what the image shows, max 110 characters, in the article language, without the words 'image of'\"}";
            try {
                $r = $ai->generateContent($q, ['temperature' => $recent ? 0.6 : 0.3, 'maxOutputTokens' => 500, 'responseMimeType' => 'application/json', 'task' => 'article_image_prompt', 'user_id' => $userId ?: null]);
                if (!empty($r['success'])) {
                    $j = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim((string) $r['data']))), true);
                    if (is_array($j)) {
                        $scene = trim(strip_tags((string) ($j['image_prompt'] ?? '')));
                        $alt = trim(strip_tags((string) ($j['alt_text'] ?? '')));
                    }
                }
            } catch (\Throwable $e) {
                // نكمل بالاحتياطي
            }
        }
        if (mb_strlen($scene) < 15 || mb_strlen($scene) > 600) {
            $scene = 'A real, wide landscape photograph of the main place in this travel topic, shown exactly as it looks in reality: ' . $subject . '.';
        }
        if ($alt === '' || mb_strlen($alt) > 160) {
            $alt = mb_substr($title !== '' ? $title : $subject, 0, 110);
        }
        return ['prompt' => $scene . $style, 'alt' => mb_substr($alt, 0, 125)];
    }

    /** أوصاف آخر صور مقالات العميل (عشان الصورة الجديدة ماتبقاش نسخة من اللي قبلها) */
    protected function recentScenes(int $userId, int $articleId): array
    {
        try {
            if ($userId < 1 || !self::columnsReady()) {
                return [];
            }
            $rows = Database::getInstance()->query(
                "SELECT featured_image_prompt FROM ai_articles WHERE user_id = ? AND id <> ? AND featured_image_prompt IS NOT NULL AND featured_image_prompt <> '' ORDER BY id DESC LIMIT 8",
                [$userId, $articleId]
            );
        } catch (\Throwable $e) {
            return [];
        }
        // صور خطة المقالات (لسه ماتكتبتش) كمان، عشان صور الشهر الجاي ماتطلعش شبه بعض
        try {
            $rows = array_merge((array) $rows, Database::getInstance()->query(
                "SELECT image_prompt AS featured_image_prompt FROM content_calendar WHERE user_id = ? AND image_prompt IS NOT NULL AND image_prompt <> '' ORDER BY id DESC LIMIT 6",
                [$userId]
            ));
        } catch (\Throwable $e) {
        }
        $out = [];
        foreach ((array) $rows as $r) {
            // الجزء الأول بس (المشهد)، من غير جمل الأسلوب الثابتة
            $scene = trim(explode(' Documentary-style', (string) $r['featured_image_prompt'])[0]);
            if ($scene !== '') {
                $out[] = mb_substr($scene, 0, 220);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ توليد

    public function dailyCount(int $userId): int
    {
        try {
            $r = Database::getInstance()->query(
                "SELECT COUNT(*) c FROM api_usage_logs WHERE user_id = ? AND feature = 'article_image' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                [$userId]
            );
            return (int) ($r[0]['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function gemini()
    {
        return $this->gemini ?? new GeminiClient();
    }

    protected function openai()
    {
        return $this->openai ?? (class_exists('OpenAIImageClient') ? new OpenAIImageClient() : null);
    }

    /**
     * ترتيب مزودي الصور: ARTICLE_IMAGE_PROVIDERS في .env (افتراضي "openai>gemini": ChatGPT الأول وGemini بديل صامت).
     * "openai" لوحده = من غير بديل. المزود اللي مفتاحه مش متظبّط بيتخطّى.
     * @return string[]
     */
    public function providerChain(): array
    {
        if(class_exists('AiServiceRouting')&&($policy=AiServiceRouting::policy('article_image'))!==null)return $policy['enabled']?$policy['chain']:[];
        $spec = function_exists('env') ? env('ARTICLE_IMAGE_PROVIDERS') : null;
        $spec = is_string($spec) && $spec !== '' ? $spec : 'openai>gemini';
        $chain = [];
        foreach (explode('>', strtolower($spec)) as $p) {
            $p = trim($p);
            if (in_array($p, ['openai', 'gemini'], true) && !in_array($p, $chain, true)) {
                $chain[] = $p;
            }
        }
        $ready = [];
        foreach ($chain ?: ['openai', 'gemini'] as $p) {
            if ($p === 'openai') {
                $c = $this->openai();
                if ($c !== null && (!method_exists($c, 'isConfigured') || $c->isConfigured())) {
                    $ready[] = 'openai';
                }
            } elseif ($p === 'gemini') {
                if ($this->gemini !== null || (defined('GEMINI_API_KEY') && (string) GEMINI_API_KEY !== '')) {
                    $ready[] = 'gemini';
                }
            }
        }
        return $ready;
    }

    /**
     * يولّد الصورة من أول مزود ينجح. الفشل بيتسجّل (لوج + image_error) لكن العميل ما يشوفش اسم المزود.
     * @return array{success:bool, image_base64?:string, provider?:string, http_code?:int, api_status?:string, error?:string, tried?:array}
     */
    protected function renderImage(string $prompt, int $userId): array
    {
        if(class_exists('AiServiceRouting')&&($policy=AiServiceRouting::policy('article_image'))!==null)return AiServiceRouting::image($prompt,'16:9',['task'=>'article_image','user_id'=>$userId],$policy);
        $chain = $this->providerChain();
        if (!$chain) {
            return ['success' => false, 'error' => 'No image provider configured', 'http_code' => 0, 'api_status' => 'NOT_CONFIGURED', 'tried' => []];
        }
        $tried = [];
        $last = ['success' => false, 'error' => 'unknown', 'http_code' => 0, 'api_status' => ''];
        foreach ($chain as $p) {
            $meta = ['task' => 'article_image', 'user_id' => $userId];
            try {
                $r = $p === 'openai'
                    ? $this->openai()->generateImage($prompt, '16:9', $meta)
                    : $this->gemini()->generateImage($prompt, '16:9', $meta);
            } catch (\Throwable $e) {
                $r = ['success' => false, 'error' => $e->getMessage(), 'http_code' => 0, 'api_status' => ''];
            }
            if (!empty($r['success'])) {
                $r['provider'] = $p;
                $r['tried'] = $tried;
                return $r;
            }
            $tried[] = ['provider' => $p, 'http' => (int) ($r['http_code'] ?? 0), 'status' => (string) ($r['api_status'] ?? '')];
            $last = $r;
            if (class_exists('Logger')) {
                Logger::warning('Article image provider failed', ['provider' => $p, 'http' => $r['http_code'] ?? 0, 'status' => $r['api_status'] ?? '', 'error' => substr((string) ($r['error'] ?? ''), 0, 200)]);
            }
            // الحظر بسبب المحتوى: مزود تاني غالبًا هيرفض برضه، لكن بنجرّب البديل برضه لأن سياسات المزودين بتختلف.
        }
        $last['tried'] = $tried;
        return $last;
    }

    /**
     * @param array $article صف ai_articles
     * @return array{success:bool, reason?:string, rel?:string, url?:string, alt?:string, existing?:bool, technical?:string}
     */
    public function generate(array $article, int $userId, bool $force = false): array
    {
        if (!self::columnsReady()) {
            return ['success' => false, 'reason' => 'columns_missing'];
        }
        $id = (int) ($article['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'reason' => 'article_missing'];
        }
        $existing = (string) ($article['featured_image_path'] ?? '');
        if (!$force && $existing !== '' && is_file($this->absPath($existing))) {
            return ['success' => true, 'existing' => true, 'rel' => $existing, 'url' => self::publicUrl($existing), 'alt' => (string) ($article['featured_image_alt'] ?? '')];
        }
        if ($this->dailyCount($userId) >= self::setting('ARTICLE_IMAGE_DAILY_LIMIT', 30)) {
            return ['success' => false, 'reason' => 'daily_limit'];
        }

        $p = $this->buildPrompt($article, $userId);
        $img = $this->renderImage($p['prompt'], $userId);
        if (empty($img['success'])) {
            $reason = self::reasonFor((int) ($img['http_code'] ?? 0), (string) ($img['api_status'] ?? ''), (string) ($img['error'] ?? ''));
            $this->recordError($id, $userId, $reason);
            return ['success' => false, 'reason' => $reason, 'http' => (int) ($img['http_code'] ?? 0), 'api_status' => (string) ($img['api_status'] ?? ''),
                'technical' => substr((string) ($img['error'] ?? ''), 0, 200), 'tried' => $img['tried'] ?? []];
        }
        $bin = base64_decode((string) ($img['image_base64'] ?? ''), true);
        $norm = $bin === false ? null : $this->normalize($bin);
        if ($norm === null) {
            $this->recordError($id, $userId, 'invalid_image');
            return ['success' => false, 'reason' => 'invalid_image'];
        }

        $dir = $this->absPath('/uploads/articles/' . $userId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->recordError($id, $userId, 'storage_failed');
            return ['success' => false, 'reason' => 'storage_failed'];
        }
        $this->protectDir(dirname($dir));
        $name = 'article-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $norm['ext'];
        if (@file_put_contents($dir . '/' . $name, $norm['bytes']) === false) {
            $this->recordError($id, $userId, 'storage_failed');
            return ['success' => false, 'reason' => 'storage_failed'];
        }
        $rel = '/uploads/articles/' . $userId . '/' . $name;
        if ($existing !== '' && strpos($existing, '/uploads/articles/' . $userId . '/') === 0) {
            @unlink($this->absPath($existing));      // الصورة القديمة (إعادة التوليد)
        }
        Database::getInstance()->query(
            'UPDATE ai_articles SET featured_image_path = ?, featured_image_alt = ?, featured_image_prompt = ?, wp_media_id = NULL, image_error = NULL WHERE id = ? AND user_id = ?',
            [$rel, $p['alt'], mb_substr($p['prompt'], 0, 2000), $id, $userId]
        );
        // صورة المقال تدخل مكتبة الاستوديو: تتعاد في السوشيال/الإعلانات ومايتولّدش زيها تاني
        if (class_exists('CreativeAssetRegistry')) {
            CreativeAssetRegistry::register($userId, $norm['bytes'], 'article', mb_substr((string) ($article['title'] ?? 'Article image'), 0, 300), ['article_id' => $id, 'alt' => $p['alt']]);
        }
        return ['success' => true, 'existing' => false, 'rel' => $rel, 'url' => self::publicUrl($rel), 'alt' => $p['alt'], 'provider' => $img['provider'] ?? null];
    }

    /**
     * صورة لمقال لسه ماتكتبش (خطة المقالات): نفس قواعد الصورة الواقعية ومنع التكرار، من غير صف ai_articles.
     * @return array{success:bool, rel?:string, url?:string, alt?:string, reason?:string}
     */
    public function generateStandalone(string $title, string $keyword, string $lang, int $userId): array
    {
        if (!self::enabled()) {
            return ['success' => false, 'reason' => 'disabled'];
        }
        if ($this->dailyCount($userId) >= self::setting('ARTICLE_IMAGE_DAILY_LIMIT', 30)) {
            return ['success' => false, 'reason' => 'daily_limit'];
        }
        $p = $this->buildPrompt(['id' => 0, 'title' => $title, 'topic' => $title, 'target_language' => $lang, 'suggested_keywords' => $keyword !== '' ? [$keyword] : []], $userId);
        $img = $this->renderImage($p['prompt'], $userId);
        if (empty($img['success'])) {
            return ['success' => false, 'reason' => self::reasonFor((int) ($img['http_code'] ?? 0), (string) ($img['api_status'] ?? ''), (string) ($img['error'] ?? ''))];
        }
        $bin = base64_decode((string) ($img['image_base64'] ?? ''), true);
        $norm = $bin === false ? null : $this->normalize($bin);
        if ($norm === null) {
            return ['success' => false, 'reason' => 'invalid_image'];
        }
        $dir = $this->absPath('/uploads/articles/' . $userId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'reason' => 'storage_failed'];
        }
        $this->protectDir(dirname($dir));
        $name = 'plan-' . bin2hex(random_bytes(6)) . '.' . $norm['ext'];
        if (@file_put_contents($dir . '/' . $name, $norm['bytes']) === false) {
            return ['success' => false, 'reason' => 'storage_failed'];
        }
        if (class_exists('CreativeAssetRegistry')) {
            CreativeAssetRegistry::register($userId, $norm['bytes'], 'article', mb_substr($title, 0, 300), ['alt' => $p['alt']]);
        }
        $rel = '/uploads/articles/' . $userId . '/' . $name;
        return ['success' => true, 'rel' => $rel, 'url' => self::publicUrl($rel), 'alt' => $p['alt'], 'prompt' => $p['prompt']];
    }

    /** يحوّل ردّ جوجل لسبب مفهوم للعميل */
    public static function reasonFor(int $http, string $status, string $error): string
    {
        $e = strtolower($error);
        $st = strtolower($status);
        if ($st === 'moderation_blocked' || $st === 'content_policy_violation' || strpos($e, 'safety system') !== false) {
            return 'blocked';
        }
        if ($st === 'insufficient_quota' || $st === 'billing_hard_limit_reached' || $st === 'rate_limit_exceeded') {
            return 'quota';
        }
        if ($st === 'not_configured') {
            return 'model_unavailable';
        }
        if ($http === 404 || $status === 'NOT_FOUND') {
            return 'model_unavailable';
        }
        if ($http === 401 || $http === 403 || $status === 'PERMISSION_DENIED' || $status === 'UNAUTHENTICATED') {
            return 'access_denied';
        }
        if ($http === 429 || $status === 'RESOURCE_EXHAUSTED') {
            return 'quota';
        }
        if ($http === 503 || $http === 500 || $http === 502 || $http === 504 || $status === 'UNAVAILABLE') {
            return 'busy';
        }
        if ($status === 'FAILED_PRECONDITION' || strpos($e, 'billing') !== false || strpos($e, 'free tier') !== false || strpos($e, 'not available in your country') !== false) {
            return 'billing_required';
        }
        if ($http === 200 || in_array($status, ['SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_SAFETY', 'BLOCKLIST', 'NO_IMAGE', 'OTHER'], true) || strpos($e, 'no image') !== false) {
            return 'blocked';
        }
        if ($http === 0 && (strpos($e, 'curl') !== false || $status === 'NETWORK')) {
            return 'network';
        }
        return 'generation_failed';
    }

    /**
     * فحص شامل من غير ما نصرف على توليد صورة. كل عنصر: [key, ok, detail].
     * @return array<int, array{key:string, ok:bool, detail:string}>
     */
    public function diagnose(int $userId): array
    {
        $checks = [];
        $add = function (string $key, bool $ok, string $detail = '') use (&$checks) {
            $checks[] = ['key' => $key, 'ok' => $ok, 'detail' => $detail];
        };
        $add('enabled', self::enabled());
        $add('columns', self::columnsReady());
        $add('gd', function_exists('imagecreatefromstring') && function_exists('imagejpeg'));
        $dir = $this->absPath('/uploads/articles/' . $userId);
        $okDir = is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
        $add('storage', $okDir && is_writable($dir));
        $chain = $this->providerChain();
        $add('api_key', count($chain) > 0, implode(' > ', $chain));
        $anyOk = false;
        $parts = [];
        foreach ($chain as $p) {
            try {
                $m = $p === 'openai' ? $this->openai()->checkModel() : $this->gemini()->checkImageModel();
                $ok = !empty($m['ok']);
                $anyOk = $anyOk || $ok;
                $parts[] = $p . ': ' . ($ok ? ((string) ($m['model'] ?? 'ok')) : ('HTTP ' . ($m['http'] ?? 0) . (!empty($m['status']) ? ' ' . $m['status'] : '')));
            } catch (\Throwable $e) {
                $parts[] = $p . ': error';
            }
        }
        $add('model', $anyOk, implode(' | ', $parts));
        $limit = self::setting('ARTICLE_IMAGE_DAILY_LIMIT', 30);
        $used = $this->dailyCount($userId);
        $add('limit', $used < $limit, $used . '/' . $limit);
        return $checks;
    }

    private function recordError(int $id, int $userId, string $code): void
    {
        try {
            Database::getInstance()->query('UPDATE ai_articles SET image_error = ? WHERE id = ? AND user_id = ?', [$code, $id, $userId]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** منع تنفيذ أي سكربت جوه مجلد الرفع */
    private function protectDir(string $dir): void
    {
        $f = $dir . '/.htaccess';
        if (!is_file($f)) {
            @file_put_contents($f, "# Tourfecto - uploads: no script execution\nOptions -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml\n");
        }
    }

    /**
     * يتأكد إنها صورة حقيقية، ويصغّرها ويحوّلها JPEG.
     * @return array{bytes:string, ext:string, mime:string, w:int, h:int}|null
     */
    public function normalize(string $bin): ?array
    {
        if (strlen($bin) < 500 || strlen($bin) > 15000000) {
            return null;
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $info[0] < 64 || $info[1] < 64) {
            return null;
        }
        $mime = $info['mime'];
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg');
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return strlen($bin) <= self::MAX_BYTES ? ['bytes' => $bin, 'ext' => $ext, 'mime' => $mime, 'w' => $info[0], 'h' => $info[1]] : null;
        }
        $im = @imagecreatefromstring($bin);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w > self::MAX_WIDTH) {
            $scaled = imagescale($im, self::MAX_WIDTH, (int) round($h * self::MAX_WIDTH / $w));
            if ($scaled) {
                $im = $scaled;
                $w = imagesx($im);
                $h = imagesy($im);
            }
        }
        // شفافية → خلفية بيضاء (JPEG مفيهوش alpha)
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        foreach ([82, 72, 60] as $q) {
            ob_start();
            imagejpeg($flat, null, $q);
            $out = (string) ob_get_clean();
            if (strlen($out) <= self::MAX_BYTES) {
                return ['bytes' => $out, 'ext' => 'jpg', 'mime' => 'image/jpeg', 'w' => $w, 'h' => $h];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ WordPress

    /**
     * يرفع الصورة لمكتبة وسائط WordPress (أو يستخدم اللي اترفع قبل كده).
     * @return array{media_id:?int, warning:?string}
     */
    public function pushToWordPress(array $article, $publisher, string $siteUrl, string $username, string $appPassword): array
    {
        $rel = (string) ($article['featured_image_path'] ?? '');
        $abs = $rel !== '' ? $this->absPath($rel) : '';
        if ($abs === '' || !is_file($abs)) {
            return ['media_id' => null, 'warning' => 'image_missing'];
        }
        if (!empty($article['wp_media_id'])) {
            return ['media_id' => (int) $article['wp_media_id'], 'warning' => null];
        }
        $bytes = (string) file_get_contents($abs);
        $mime = (substr($abs, -4) === '.png') ? 'image/png' : ((substr($abs, -5) === '.webp') ? 'image/webp' : 'image/jpeg');
        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower((string) ($article['slug'] ?? ''))) ?: ('article-' . (int) $article['id']);
        $filename = trim(substr($slug, 0, 60), '-') . '-' . substr(md5($rel), 0, 6) . '.' . pathinfo($abs, PATHINFO_EXTENSION);

        $up = $publisher->uploadMedia($siteUrl, $username, $appPassword, $bytes, $filename, $mime, (string) ($article['featured_image_alt'] ?? ''), (string) ($article['title'] ?? ''));
        if (empty($up['success']) || empty($up['media_id'])) {
            $this->recordError((int) $article['id'], (int) ($article['user_id'] ?? 0), 'upload_failed');
            return ['media_id' => null, 'warning' => 'upload_failed'];
        }
        Database::getInstance()->query('UPDATE ai_articles SET wp_media_id = ?, image_error = NULL WHERE id = ?', [(int) $up['media_id'], (int) $article['id']]);
        return ['media_id' => (int) $up['media_id'], 'warning' => null];
    }

    /**
     * الخطوة اللي بتتنفّذ قبل النشر: تأكد إن فيه صورة (ولّدها لو مفيش) وارفعها.
     * @return array{media_id:?int, warning:?string}
     */
    public function prepareForWordPress(array $article, int $userId, $publisher, string $siteUrl, string $username, string $appPassword): array
    {
        if (!self::enabled() || !self::columnsReady()) {
            return ['media_id' => null, 'warning' => null];
        }
        $gen = $this->generate($article, $userId, false);
        if (empty($gen['success'])) {
            return ['media_id' => null, 'warning' => $gen['reason'] ?? 'generation_failed'];
        }
        $fresh = Database::getInstance()->query(
            'SELECT id, user_id, title, slug, featured_image_path, featured_image_alt, wp_media_id FROM ai_articles WHERE id = ? LIMIT 1',
            [(int) $article['id']]
        );
        return $this->pushToWordPress($fresh[0] ?? $article, $publisher, $siteUrl, $username, $appPassword);
    }

    /** يحدّث الموديل في الذاكرة بقيم الصورة الجديدة (عشان save() بعدها ما يمسحهاش) */
    public static function refreshModel($article): void
    {
        if (!self::columnsReady()) {
            return;
        }
        try {
            $r = Database::getInstance()->query('SELECT ' . implode(', ', self::COLUMNS) . ' FROM ai_articles WHERE id = ? LIMIT 1', [(int) $article->getAttribute('id')]);
            foreach (($r[0] ?? []) as $k => $v) {
                $article->setAttribute($k, $v);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
