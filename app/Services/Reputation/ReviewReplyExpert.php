<?php

/**
 * Tourfecto - ردود التقييمات بمستوى خبير سمعة
 *
 * الرد القديم كان بياخد نص التقييم بس: من غير اسم صاحب التقييم ولا عدد النجوم ولا اسم الشركة،
 * وكان بيتكتب إنجليزي حتى لو التقييم ألماني أو إيطالي، وبيبدأ كل مرة بنفس الجملة.
 * هنا: نوع التقييم من النجوم والنص مع بعض (إيجابي / إيجابي فيه ملاحظة / سلبي / نجوم من غير كلام)،
 * لغة صاحب التقييم، قواعد المنصة (TripAdvisor مايقبلش لينكات ولا أرقام تليفون)، تفاصيل من كلامه هو،
 * توقيع باسم الشركة، ومنع تكرار بدايات الردود اللي فاتت. وبعد الكتابة: مراجعة آلية، ولو فيه مشكلة بيتعاد مرة واحدة.
 *
 * منطق خالص من غير قاعدة بيانات (الكونتكست بيتبعت جاهز) عشان يتختبر لوحده.
 */
class ReviewReplyExpert
{
    public const TYPES = ['positive', 'mixed', 'negative', 'rating_only'];

    /** طول الرد بالكلمات لكل نوع (الرد الطويل على 5 نجوم من سطر واحد بيبان آلي) */
    public const WORDS = ['rating_only' => [12, 40], 'positive' => [30, 85], 'mixed' => [50, 120], 'negative' => [60, 140]];

    /** جمل بتفضح إن الرد آلي أو منسوخ - الخبير مابيكتبهاش */
    public const CLICHES = [
        'thank you so much for your wonderful review', 'means the world to us', 'we are thrilled to hear', 'we are delighted to hear',
        'seamless', 'valued customer', 'any inconvenience', 'we apologize for any inconvenience', 'your feedback is important to us',
        'absolute pleasure', 'unforgettable adventure', 'top priority', 'we strive to', 'rest assured',
        'نعتذر عن أي إزعاج', 'عميلنا العزيز', 'رأيك يهمنا', 'نسعد بخدمتك دائما',
    ];

    private const LANGS = [
        'ar' => 'Arabic', 'en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'tr' => 'Turkish', 'ru' => 'Russian', 'pl' => 'Polish', 'zh' => 'Chinese',
        'ja' => 'Japanese', 'ko' => 'Korean', 'he' => 'Hebrew',
    ];

    /** كلمات شائعة لكل لغة (تمييز لغات الحروف اللاتينية) */
    private const STOP = [
        'en' => ['the', 'and', 'was', 'with', 'very', 'we', 'our', 'great', 'tour', 'guide', 'is', 'it', 'to', 'of', 'for'],
        'de' => ['und', 'der', 'die', 'das', 'war', 'sehr', 'wir', 'mit', 'ist', 'nicht', 'ein', 'eine', 'auch', 'für', 'uns'],
        'fr' => ['et', 'le', 'la', 'les', 'très', 'nous', 'avec', 'est', 'une', 'un', 'pour', 'pas', 'était', 'notre', 'des'],
        'es' => ['y', 'el', 'la', 'muy', 'con', 'fue', 'nos', 'es', 'una', 'un', 'para', 'los', 'las', 'que', 'todo'],
        'it' => ['e', 'il', 'la', 'molto', 'con', 'era', 'siamo', 'è', 'una', 'un', 'per', 'che', 'della', 'stato', 'guida'],
        'pt' => ['e', 'o', 'a', 'muito', 'com', 'foi', 'nós', 'é', 'uma', 'um', 'para', 'que', 'não', 'passeio', 'guia'],
        'nl' => ['en', 'de', 'het', 'was', 'zeer', 'heel', 'we', 'met', 'is', 'een', 'voor', 'niet', 'ook', 'onze', 'gids'],
        'tr' => ['ve', 'bir', 'çok', 'ile', 'için', 'bu', 'da', 'de', 'harika', 'rehber', 'tur', 'teşekkürler', 'güzel', 'olarak', 'gibi'],
        'pl' => ['i', 'w', 'na', 'bardzo', 'był', 'była', 'się', 'z', 'jest', 'nie', 'przewodnik', 'polecam', 'do', 'to', 'że'],
    ];

    /**
     * لغة التقييم (كود من حرفين) - من الحروف الأول، وبعدين من الكلمات الشائعة.
     * تقييم نجوم من غير كلام: اسم صاحبه بالعربي يبقى الرد عربي (عمود review_language افتراضيه 'en' فمايتصدّقش لوحده).
     */
    public static function language(string $text, string $hint = '', string $name = ''): string
    {
        $t = trim($text);
        if ($t === '') {
            if (preg_match('/\p{Arabic}/u', $name)) return 'ar';
            return isset(self::LANGS[$hint]) ? $hint : 'en';
        }
        if (preg_match('/\p{Arabic}/u', $t)) return 'ar';
        if (preg_match('/\p{Hebrew}/u', $t)) return 'he';
        if (preg_match('/\p{Cyrillic}/u', $t)) return 'ru';
        if (preg_match('/\p{Hangul}/u', $t)) return 'ko';
        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $t)) return 'ja';
        if (preg_match('/\p{Han}/u', $t)) return 'zh';
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($t), -1, PREG_SPLIT_NO_EMPTY);
        $best = 'en';
        $bestScore = 0;
        foreach (self::STOP as $lang => $stop) {
            $score = count(array_intersect($words, $stop));
            if ($lang === 'tr' && preg_match('/[ığş]/u', $t)) $score += 3;
            if ($lang === 'de' && preg_match('/[äöüß]/u', $t)) $score += 2;
            if ($lang === 'pl' && preg_match('/[ąęłńśźż]/u', $t)) $score += 3;
            if ($score > $bestScore) {
                $best = $lang;
                $bestScore = $score;
            }
        }
        // كلمة أو اتنين بس ومفيش دليل: نصدّق اللغة المتسجلة مع التقييم
        if ($bestScore < 2 && isset(self::LANGS[$hint]) && $hint !== 'ar') {
            return $hint;
        }
        return $best;
    }

    public static function languageName(string $code): string
    {
        return self::LANGS[$code] ?? 'English';
    }

    /**
     * نوع التقييم: النجوم الأول (5 نجوم من غير كلام مش "محايد")، والنص بيكشف الشكوى جوه تقييم عالي.
     */
    public static function type(float $rating, string $text, string $sentiment = ''): string
    {
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        if ($words < 3) {
            return $rating > 0 && $rating <= 2 ? 'negative' : ($rating > 0 && $rating < 4 ? 'mixed' : 'rating_only');
        }
        if ($rating > 0 && $rating <= 2) return 'negative';
        if ($rating >= 4) {
            return self::hasComplaint($text) || $sentiment === 'negative' ? 'mixed' : 'positive';
        }
        if ($rating > 0) return $sentiment === 'positive' && !self::hasComplaint($text) ? 'positive' : 'mixed';
        return $sentiment === 'negative' ? 'negative' : ($sentiment === 'positive' ? 'positive' : 'mixed');
    }

    /** شكوى جوه تقييم كويس ("but", "however", "unfortunately", "late") */
    public static function hasComplaint(string $text): bool
    {
        return (bool) preg_match('/\b(but|however|unfortunately|disappoint\w*|late|delay\w*|rude|dirty|overpriced|expensive|cancel\w*|waited|wait(ing)? for|only downside|could be better|aber|leider|mais|malheureusement|pero|lamentablemente|però|purtroppo|ama|maalesef)\b|لكن|بس للأسف|للأسف|اتأخر|تأخير|وحش|سيء|مخيب/iu', $text);
    }

    /**
     * الـ Prompt: كل اللي خبير السمعة بيعرفه قبل ما يكتب.
     * @param array $r  review_text, rating, reviewer_name, platform, review_language, sentiment
     * @param array $b  name, signer, city, email (ممكن فاضيين)
     * @param array $recent ردود قديمة للنشاط ده (عشان مانكررش بداياتها)
     * @param string $style أمثلة من ردود صاحب النشاط نفسه (مركز الأتمتة)
     */
    public static function prompt(array $r, array $b, array $recent = [], string $style = '', array $issues = []): string
    {
        $text = trim((string) ($r['review_text'] ?? ''));
        $rating = (float) ($r['rating'] ?? 0);
        $type = self::type($rating, $text, (string) ($r['sentiment'] ?? ''));
        $lang = self::language($text, (string) ($r['review_language'] ?? ''), (string) ($r['reviewer_name'] ?? ''));
        $langName = self::languageName($lang);
        $platform = self::platformName((string) ($r['platform'] ?? ''));
        [$min, $max] = self::WORDS[$type];
        $first = self::firstName((string) ($r['reviewer_name'] ?? ''));
        $business = trim((string) ($b['name'] ?? ''));
        $signer = trim((string) ($b['signer'] ?? ''));
        $sign = $signer !== '' ? $signer . ($business !== '' ? ', ' . $business : '') : ($business !== '' ? 'The ' . $business . ' team' : '');

        $goal = [
            'rating_only' => "The guest left {$rating} stars with no written comment. Write a short, warm thank-you (1–2 sentences). Do not ask for more feedback and do not pretend they described anything.",
            'positive' => 'A happy guest. Thank them by first name, pick 1–2 specific details THEY mentioned (a guide or driver name, a place, a moment) and react to those genuinely. Add one natural line about the experience/destination that helps future readers. End with a light, sincere invitation to come back or to explore another trip — no hard selling.',
            'mixed' => 'A mostly good review with a real complaint inside. Thank them, appreciate the specific positives, then acknowledge the specific issue plainly (no excuses, no blaming the guest), say it was shared with the team and what will be checked. Keep it calm and confident.',
            'negative' => 'An unhappy guest. Future guests read this reply more than the review. Thank them for telling you, apologise sincerely for the SPECIFIC problem they describe (name it), never argue or contradict them in public, never blame them, never reveal booking details or private information, do not promise refunds or compensation in public. Say briefly what you are doing about it only in general terms, and invite them to continue privately' . (self::isTripAdvisor($r) ? ' (ask them to message you through TripAdvisor or reach the team directly — do not write an email, phone number or URL).' : (($b['email'] ?? '') !== '' ? ' at ' . $b['email'] . '.' : ' by contacting the team directly.')),
        ][$type];

        $lines = [];
        $lines[] = 'You are the guest-relations manager of a tour and travel company and a senior online-reputation expert. You write public owner responses that read as personal, specific and human, and that make future travellers trust the company.';
        $lines[] = '';
        $lines[] = 'REVIEW (' . $platform . ', ' . ($rating > 0 ? $rating . '/5 stars' : 'no star rating') . ($first !== '' ? ', by ' . $first : '') . '):';
        $lines[] = $text !== '' ? '"""' . mb_substr($text, 0, 3000) . '"""' : '(no text — rating only)';
        $lines[] = '';
        if ($business !== '') $lines[] = 'Company: ' . $business . (!empty($b['city']) ? ' (' . $b['city'] . ')' : '');
        $lines[] = 'Review type: ' . $type . '. ' . $goal;
        $lines[] = '';
        $lines[] = 'RULES:';
        $lines[] = "- Write ONLY in {$langName} — the same language the guest wrote in." . ($lang !== 'en' ? " Not English. Natural native {$langName}, not a translation." : '');
        $lines[] = "- {$min}–{$max} words. Shorter is better than padded.";
        $lines[] = $first !== '' ? "- Greet them by first name ({$first}) once, naturally." : '- No name is available: use a friendly greeting without a name or placeholder.';
        $lines[] = '- Use only facts from the review. Never invent tour names, staff names, offers, prices, policies or events.';
        $lines[] = '- Mention the trip/place they mentioned in plain words (helps search), never stuff keywords.';
        $lines[] = '- Do not start with "Thank you so much for your wonderful review" or any stock opener. Avoid these phrases: ' . implode('; ', array_slice(self::CLICHES, 0, 15)) . '.';
        $lines[] = '- At most one exclamation mark. ' . ($type === 'negative' || $type === 'mixed' ? 'No emojis.' : 'No more than one emoji, only if it fits.');
        $lines[] = '- Never mention AI, templates, or that the reply was generated.';
        if ($type === 'negative' || $type === 'mixed') {
            $lines[] = '- Name the problem in calm, neutral words. Do not repeat the guest\'s harsh words (rude, scam, dirty, terrible…) — the reply is public and searchable.';
        }
        if (self::isTripAdvisor($r)) $lines[] = '- TripAdvisor rules: no URLs, emails, phone numbers, discounts or requests to change the review.';
        // التوقيع بلغة الرد نفسها (كان بيطلع "The X team" إنجليزي تحت رد ألماني أو عربي)
        $lines[] = $signer !== ''
            ? "- End with a sign-off on its own line written in {$langName}: the name {$signer}" . ($business !== '' ? " and the company name {$business}" : '') . '.'
            : ($business !== '' ? "- End with a sign-off on its own line written in {$langName}, meaning \"the {$business} team\" (translate the word team; keep the company name as is)." : "- End with a short, warm sign-off from the team, written in {$langName}.");
        if ($recent) {
            $openers = array_values(array_unique(array_filter(array_map(static fn ($x) => self::opener((string) $x), $recent))));
            if ($openers) {
                $lines[] = '- The company already used these openings recently — start differently: "' . implode('" | "', array_slice($openers, 0, 6)) . '"';
            }
        }
        if ($issues) {
            $lines[] = '';
            $lines[] = 'Your previous draft had these problems — fix all of them: ' . implode('; ', $issues) . '.';
        }
        $lines[] = '';
        $lines[] = 'Output only the reply text (with the sign-off line), no quotes, no headings.';
        // مارك سياسة اللغة: بيمنع إضافة تعليمة "اكتب إنجليزي" العامة اللي كانت بتغلب على لغة التقييم
        $lines[] = "\n[Tourfecto audience output policy]\nThe output language for this task is the reviewer's language: {$langName}. This overrides any website or dashboard language.";
        $out = implode("\n", $lines);
        return $style !== '' ? $out . "\n" . $style : $out;
    }

    /**
     * مراجعة الرد قبل ما يتحفظ: نفس قواعد الخبير. فاضية = الرد سليم.
     * @return string[] المشاكل (بالإنجليزي عشان تتبعت للموديل في المحاولة التانية)
     */
    public static function check(string $reply, array $r, array $recent = []): array
    {
        $issues = [];
        $text = trim((string) ($r['review_text'] ?? ''));
        $type = self::type((float) ($r['rating'] ?? 0), $text, (string) ($r['sentiment'] ?? ''));
        $lang = self::language($text, (string) ($r['review_language'] ?? ''), (string) ($r['reviewer_name'] ?? ''));
        $body = trim($reply);
        if ($body === '') {
            return ['empty reply'];
        }
        $words = count(preg_split('/\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY));
        [$min, $max] = self::WORDS[$type];
        if ($words > (int) round($max * 1.25)) $issues[] = "too long ({$words} words, max {$max})";
        if ($words < (int) floor($min * 0.6)) $issues[] = "too short ({$words} words, min {$min})";
        $hasArabic = (bool) preg_match('/\p{Arabic}/u', $body);
        if ($lang === 'ar' && !$hasArabic) $issues[] = 'must be written in Arabic';
        if ($lang !== 'ar' && $hasArabic) $issues[] = 'must not contain Arabic; write in ' . self::languageName($lang);
        if ($lang !== 'en' && $lang !== 'ar' && $text !== '' && self::language($body) === 'en' && str_word_count(strtolower($body)) > 12) {
            $issues[] = 'written in English but the guest wrote in ' . self::languageName($lang);
        }
        // توقيع إنجليزي ("The X team", "Kind regards") تحت رد بلغة تانية
        $lines = preg_split('/\n+/u', $body);
        $lastLine = trim((string) end($lines));
        if ($lang !== 'en' && preg_match('/^(the\s|kind regards|best regards|warm regards|sincerely)/i', $lastLine)) {
            $issues[] = 'the sign-off must be written in ' . self::languageName($lang) . ', not English';
        }
        $low = mb_strtolower($body);
        foreach (self::CLICHES as $c) {
            if (str_contains($low, $c)) $issues[] = 'remove the stock phrase "' . $c . '"';
        }
        if (preg_match('/\[[^\]]{2,30}\]|\{[^}]{2,30}\}|<name>|\bXXX\b/i', $body)) $issues[] = 'contains a placeholder';
        if (self::isTripAdvisor($r) && preg_match('#https?://|www\.|[\w.+-]+@[\w-]+\.\w+|\+?\d[\d\s().-]{7,}\d#u', $body)) {
            $issues[] = 'TripAdvisor does not allow links, emails or phone numbers';
        }
        if (substr_count($body, '!') > 1) $issues[] = 'use at most one exclamation mark';
        if (($type === 'negative' || $type === 'mixed') && preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body)) $issues[] = 'no emojis on a complaint';
        if (preg_match('/\b(as an ai|language model|generated)\b|ذكاء اصطناعي/i', $body)) $issues[] = 'never mention AI';
        $op = self::opener($body);
        foreach ($recent as $old) {
            if ($op !== '' && $op === self::opener((string) $old)) {
                $issues[] = 'starts exactly like a recent reply ("' . $op . '")';
                break;
            }
        }
        return array_values(array_unique($issues));
    }

    /** تنضيف رد الموديل: علامات تنصيص، "Reply:"، ماركداون */
    public static function clean(string $reply): string
    {
        $s = trim($reply);
        $s = preg_replace('/^(reply|response|الرد)\s*[:：]\s*/iu', '', $s);
        $s = preg_replace('/^```\w*\s*|\s*```$/u', '', $s);
        $s = trim($s, " \t\n\r\0\x0B\"'“”«»");
        $s = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $s);
        $s = preg_replace("/[ \t]+/u", ' ', $s);
        $s = preg_replace("/\n{3,}/u", "\n\n", $s);
        return trim($s);
    }

    /** أول 5 كلمات (لمنع تكرار البدايات) */
    public static function opener(string $reply): string
    {
        $w = preg_split('/\s+/u', mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $reply))), -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_slice($w, 0, 5));
    }

    public static function firstName(string $name): string
    {
        $n = trim(preg_replace('/\s+/u', ' ', $name));
        if ($n === '' || preg_match('/^(a |an )?(google|tripadvisor) (user|member)|anonymous|guest|traveller|traveler|مستخدم|زائر/i', $n)) {
            return '';
        }
        $first = explode(' ', $n)[0];
        // أسماء مستعارة زي "travelfan2019": مش اسم حقيقي
        if (preg_match('/\d{2,}|_/', $first) || mb_strlen($first) < 2) {
            return '';
        }
        return mb_convert_case($first, MB_CASE_TITLE, 'UTF-8');
    }

    public static function platformName(string $p): string
    {
        return ['google_business' => 'Google', 'tripadvisor' => 'TripAdvisor', 'booking' => 'Booking.com', 'expedia' => 'Expedia', 'trustpilot' => 'Trustpilot'][$p] ?? ($p !== '' ? ucfirst($p) : 'Google');
    }

    private static function isTripAdvisor(array $r): bool
    {
        return ($r['platform'] ?? '') === 'tripadvisor';
    }
}
