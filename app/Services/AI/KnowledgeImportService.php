<?php

/**
 * Tourfecto - استيراد معلومات الشات بوت من رابط صفحة أو ملف (PDF / نص)
 *
 * بدل ما العميل يكتب كل معلومة بإيده: بيدّينا رابط صفحة (عن الشركة، الرحلات، الأسعار، الأسئلة الشائعة)
 * أو ملف PDF (بروشور، سياسة إلغاء)، والذكاء الاصطناعي بيقسّمه لمعلومات جاهزة في الأقسام الصح.
 * النتيجة "معاينة" بس - العميل بيختار اللي يتحفظ، عشان مايدخلش حاجة غلط للبوت.
 */
class KnowledgeImportService
{
    public const SECTIONS = [
        'company_info', 'service', 'tour', 'destination', 'pricing', 'faq', 'policy',
        'cancellation_policy', 'contact_info', 'business_hours',
    ];
    private const MAX_TEXT = 24000;
    private const MAX_ENTRIES = 20;

    /** @return array{ok:bool, text?:string, title?:string, error?:string} */
    public function textFromUrl(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'error' => 'الرابط مش صحيح'];
        }
        if (!class_exists('SafeHttp')) {
            return ['ok' => false, 'error' => 'جلب الصفحات مش متاح'];
        }
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ar,en;q=0.8'],
        ], 5);
        $html = is_string($r['response'] ?? null) ? $r['response'] : '';
        if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 400 || $html === '') {
            return ['ok' => false, 'error' => 'مقدرناش نفتح الصفحة (' . (($r['error'] ?? '') ?: ('HTTP ' . ($r['code'] ?? 0))) . ')'];
        }
        return $this->textFromHtml(substr($html, 0, 3_000_000));
    }

    /** @return array{ok:bool, text?:string, title?:string, error?:string} */
    public function textFromHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $title = trim((string) ($xp->query('//title')->item(0)->textContent ?? ''));
        foreach ($xp->query('//script|//style|//noscript|//svg|//nav|//footer|//header|//form|//iframe') as $n) {
            $n->parentNode->removeChild($n);
        }
        // المحتوى الأساسي لو الصفحة معلّماه، وإلا الـ body كله
        $root = $xp->query('//main')->item(0) ?: $xp->query('//article')->item(0) ?: $xp->query('//body')->item(0);
        if (!$root) {
            return ['ok' => false, 'error' => 'الصفحة فاضية'];
        }
        $inner = $doc->saveHTML($root);
        $inner = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "$0\n", $inner);
        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $this->cleanText($text);
        if (mb_strlen($text) < 80) {
            return ['ok' => false, 'error' => 'الصفحة مافيهاش نص كفاية (ممكن تكون معمولة بالجافاسكريبت) - جرّب صفحة تانية أو ارفع PDF'];
        }
        return ['ok' => true, 'text' => $text, 'title' => mb_substr($title, 0, 200)];
    }

    /** @return array{ok:bool, text?:string, title?:string, error?:string} */
    public function textFromFile(string $path, string $originalName, bool $ocr=false, string $language="en"): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!is_file($path) || filesize($path) > 10 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'الملف لازم يكون أقل من 10 ميجا'];
        }
        if ($ext === 'txt' || $ext === 'md') {
            $text = (string) file_get_contents($path);
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
            }
            return $this->finishText($text, $originalName);
        }
        if ($ext !== 'pdf') {
            return ['ok' => false, 'error' => 'الملفات المدعومة: PDF أو TXT'];
        }
        $head = (string) file_get_contents($path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            return ['ok' => false, 'error' => 'الملف ده مش PDF سليم'];
        }
        $read=KnowledgeDocumentReader::read($path,$ocr,$language);
        if(empty($read['ok']))return $read;
        return $this->finishText((string)$read['text'],$originalName)+['method'=>$read['method'],'source_note'=>$read['note']??null];
    }

    private function finishText(string $text, string $name): array
    {
        $text = $this->cleanText($text);
        if (mb_strlen($text) < 40) {
            return ['ok' => false, 'error' => 'الملف مافيهوش نص كفاية'];
        }
        return ['ok' => true, 'text' => $text, 'title' => mb_substr($name, 0, 200)];
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t", "\xC2\xA0"], ["\n", "\n", ' ', ' '], $text);
        $text = preg_replace('/[ ]{2,}/u', ' ', $text);
        $text = preg_replace("/\n\\s*\n+/u", "\n\n", $text);
        return trim(mb_substr((string) $text, 0, self::MAX_TEXT));
    }

    private function pdftotext(): ?string
    {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        if (function_exists('shell_exec')) {
            $w = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
            return $w !== '' ? $w : null;
        }
        return null;
    }

    /**
     * الذكاء الاصطناعي بيقسّم النص لمعلومات (قسم + عنوان + محتوى) - من النص بس، من غير تأليف.
     * @return array{ok:bool, entries?:array<int,array{section:string,title:string,content:string}>, error?:string}
     */
    public function extractEntries(string $text, string $language, ?object $ai = null): array
    {
        $language = InternationalSettingsService::validLanguage($language) ? $language : 'en';
        $sections = implode(', ', self::SECTIONS);
        $langName = InternationalSettingsService::languages()[$language] ?? 'English';
        $prompt = "You are building a knowledge base for a tourism company's customer-service chatbot.\n"
            . "From the SOURCE TEXT below, extract factual knowledge entries the chatbot can use to answer customers.\n"
            . "Rules:\n"
            . "- Use ONLY facts stated in the source. Never invent prices, dates, phone numbers or policies.\n"
            . "- One entry per distinct topic (one tour, one policy, one FAQ question...). Max " . self::MAX_ENTRIES . " entries.\n"
            . "- section must be one of: {$sections}\n"
            . "- title: short (max 80 chars). For faq, the title is the question.\n"
            . "- content: complete, self-contained, max 500 chars, written in {$langName}.\n"
            . "- Skip navigation text, cookie notices, legal boilerplate and marketing fluff.\n"
            . "Return JSON only: {\"entries\":[{\"section\":\"...\",\"title\":\"...\",\"content\":\"...\"}]}\n\n"
            . "SOURCE TEXT:\n\"\"\"\n" . $text . "\n\"\"\"";

        $ai = $ai ?: (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
        $res = $ai->generateContent($prompt, ['responseMimeType' => 'application/json', 'maxOutputTokens' => 8192, 'temperature' => 0.2, 'task' => 'knowledge_import']);
        if (empty($res['success'])) {
            return ['ok'=>false,'uncertain'=>preg_match('/timeout|timed out|connection reset|uncertain/i',(string)($res['error']??''))===1,'error'=>'Knowledge extraction provider is unavailable.'];
        }
        $raw = trim((string) ($res['data'] ?? ''));
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $raw);
        $json = json_decode($raw, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
        }
        $list = is_array($json) ? ($json['entries'] ?? (array_is_list($json) ? $json : [])) : [];
        if (!$list) {
            $list = self::salvageEntries($raw);
        }
        return ['ok' => true, 'entries' => self::sanitizeEntries($list)];
    }

    /** تنضيف وتوحيد المعلومات (من الـ AI أو من المتصفح قبل الحفظ) */
    /**
     * الرد اتقطع قبل ما يخلص (حد طول الرد) فالـ JSON كله بيبقى غير صالح وكنا
     * بنرمي كل حاجة ونقول للعميل "مالقيناش معلومات". هنا بناخد كل عنصر كامل.
     */
    public static function salvageEntries(string $raw): array
    {
        $out = [];
        if (preg_match_all('/\{[^{}]*"content"\s*:\s*"(?:[^"\\\\]|\\\\.)*"[^{}]*\}/u', $raw, $m)) {
            foreach ($m[0] as $obj) {
                $e = json_decode($obj, true);
                if (is_array($e)) {
                    $out[] = $e;
                }
            }
        }
        return $out;
    }

    public static function sanitizeEntries(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            $section = (string) ($e['section'] ?? '');
            if (!in_array($section, self::SECTIONS, true)) {
                $section = 'faq';
            }
            $title = trim(strip_tags((string) ($e['title'] ?? '')));
            $content = trim(strip_tags((string) ($e['content'] ?? '')));
            if ($content === '' || mb_strlen($content) < 10) {
                continue;
            }
            $key = md5(mb_strtolower($title . '|' . mb_substr($content, 0, 120)));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['section' => $section, 'title' => mb_substr($title, 0, 200), 'content' => mb_substr($content, 0, 4000)];
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }
        return $out;
    }
}
