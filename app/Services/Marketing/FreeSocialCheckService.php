<?php

/**
 * Tourfecto - «حلّل صفحاتك ومنافسينك» (عام، من غير تسجيل)
 *
 * الزائر بيكتب لينك صفحة فيسبوك ولينك حساب إنستجرام (ولحد 3 منافسين). هنا بنتحقق من الروابط بس (شكل الرابط والمنصة والاسم)
 * ونقول بدقة: إيه اللي هيتحلل، وإيه المطلوب، وأنهي باقة محتاجها. مفيش أي رقم عن الصفحة هنا:
 * أرقام الصفحات والمنافسين بتتقرا من Meta الرسمي بعد ما صاحب الصفحة يربطها من جوه المنصة (SocialAuditService / SocialCompetitors).
 * مفيش دخول على فيسبوك أو إنستجرام ومفيش سحب لبيانات أي صفحة من برّه، ومفيش شبكة في الكلاس ده خالص.
 */
class FreeSocialCheckService
{
    public const MAX_COMPETITORS = 3;
    public const COOKIE = 'tf_social_intake';

    private bool $ar;

    private const FB_RESERVED = ['sharer', 'sharer.php', 'share', 'share.php', 'dialog', 'plugins', 'tr', 'login', 'policies', 'help', 'groups', 'events', 'watch', 'marketplace', 'people',
        'hashtag', 'photo', 'photo.php', 'permalink.php', 'story.php', 'l.php', 'privacy', 'about', 'gaming', 'reel', 'reels', 'stories', 'posts', 'videos', 'photos', 'public', 'search', 'home.php', 'bookmarks'];
    private const IG_RESERVED = ['p', 'reel', 'reels', 'explore', 'accounts', 'direct', 'stories', 'tv', 'about', 'developer', 'legal', 'oauth', 'web', 'challenge', 'directory'];

    public function __construct(string $lang = 'en')
    {
        $this->ar = $lang === 'ar';
    }

    private function t(string $ar, string $en): string
    {
        return $this->ar ? $ar : $en;
    }

    /**
     * يتحقق من لينك واحد ويرجّع الشكل القياسي.
     * @param string $expect facebook|instagram|any (أي حقل الزائر كتب فيه)
     * @return array{ok:bool, platform?:string, handle?:string, url?:string, numeric_id?:bool, error?:string}
     */
    public function parseLink(string $raw, string $expect = 'any'): array
    {
        $u = trim($raw);
        if ($u === '' || mb_strlen($u) > 300) {
            return ['ok' => false, 'error' => $this->t('اللينك فاضي أو طويل جدًا.', 'The link is empty or too long.')];
        }
        // اسم مجرّد (@name أو name) بيتفسّر حسب الحقل
        if (!preg_match('~(facebook\.com|fb\.com|instagram\.com)~i', $u) && !preg_match('~/~', $u)) {
            if ($expect === 'any') {
                return ['ok' => false, 'error' => $this->t('اكتب لينك كامل لصفحة فيسبوك أو حساب إنستجرام.', 'Enter a full Facebook page or Instagram profile link.')];
            }
            $u = 'https://' . ($expect === 'instagram' ? 'www.instagram.com/' : 'www.facebook.com/') . ltrim($u, '@');
        } elseif (!preg_match('~^https?://~i', $u)) {
            $u = 'https://' . ltrim($u, '/');
        }
        $p = parse_url($u);
        $host = strtolower((string) ($p['host'] ?? ''));
        $host = preg_replace('~^(www\.|m\.|web\.|mbasic\.|l\.)~', '', $host);
        $path = trim((string) ($p['path'] ?? ''), '/');
        parse_str((string) ($p['query'] ?? ''), $q);
        $seg = $path === '' ? [] : explode('/', $path);

        if (in_array($host, ['facebook.com', 'fb.com'], true)) {
            $platform = 'facebook';
        } elseif ($host === 'instagram.com') {
            $platform = 'instagram';
        } else {
            return ['ok' => false, 'error' => $this->t('اللينك ده مش من فيسبوك ولا إنستجرام.', 'This link is not from Facebook or Instagram.')];
        }
        if ($expect !== 'any' && $platform !== $expect) {
            return ['ok' => false, 'error' => $platform === 'instagram'
                ? $this->t('ده لينك إنستجرام - حطه في خانة إنستجرام.', 'This is an Instagram link - put it in the Instagram field.')
                : $this->t('ده لينك فيسبوك - حطه في خانة فيسبوك.', 'This is a Facebook link - put it in the Facebook field.')];
        }

        if ($platform === 'instagram') {
            $h = $seg[0] ?? '';
            if ($h === '' || in_array(strtolower($h), self::IG_RESERVED, true)) {
                return ['ok' => false, 'error' => $this->t('ده مش لينك حساب. افتح الحساب نفسه وانسخ اللينك (instagram.com/اسم_الحساب).', 'This is not a profile link. Open the profile itself and copy its link (instagram.com/username).')];
            }
            if (!preg_match('~^[A-Za-z0-9._]{1,30}$~', $h)) {
                return ['ok' => false, 'error' => $this->t('اسم حساب إنستجرام غير صحيح.', 'The Instagram username is not valid.')];
            }
            return ['ok' => true, 'platform' => 'instagram', 'handle' => strtolower($h), 'url' => 'https://www.instagram.com/' . strtolower($h) . '/'];
        }

        // فيسبوك
        $first = strtolower($seg[0] ?? '');
        if ($first === 'profile.php' && isset($q['id']) && preg_match('~^\d{5,20}$~', (string) $q['id'])) {
            return ['ok' => true, 'platform' => 'facebook', 'handle' => (string) $q['id'], 'url' => 'https://www.facebook.com/profile.php?id=' . $q['id'], 'numeric_id' => true];
        }
        if ($first === 'pages' && isset($seg[2]) && preg_match('~^\d{5,20}$~', $seg[2])) {
            return ['ok' => true, 'platform' => 'facebook', 'handle' => $seg[2], 'url' => 'https://www.facebook.com/' . $seg[2], 'numeric_id' => true];
        }
        if ($first === 'pg' && isset($seg[1])) {
            $first = strtolower($seg[1]);
        }
        if ($first === '' || in_array($first, self::FB_RESERVED, true) || str_ends_with($first, '.php')) {
            return ['ok' => false, 'error' => $this->t('ده مش لينك صفحة. افتح الصفحة نفسها وانسخ اللينك (facebook.com/اسم_الصفحة).', 'This is not a page link. Open the page itself and copy its link (facebook.com/pagename).')];
        }
        if (!preg_match('~^[A-Za-z0-9.\-]{5,80}$~', $first)) {
            return ['ok' => false, 'error' => $this->t('اسم صفحة فيسبوك غير صحيح.', 'The Facebook page name is not valid.')];
        }
        return ['ok' => true, 'platform' => 'facebook', 'handle' => $first, 'url' => 'https://www.facebook.com/' . $first];
    }

    /**
     * @param array{facebook?:string, instagram?:string, competitors?:array} $in
     * @return array{ok:bool, error?:string, field_errors?:array, own?:array, competitors?:array, measures?:array, requirements?:array, steps?:array, recommended?:?array, intake?:array}
     */
    public function run(array $in): array
    {
        $errors = [];
        $own = ['facebook' => null, 'instagram' => null];
        foreach (['facebook', 'instagram'] as $k) {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                continue;
            }
            $r = $this->parseLink($raw, $k);
            if ($r['ok']) {
                $own[$k] = $r;
            } else {
                $errors[$k] = $r['error'];
            }
        }
        $comps = [];
        $seen = [];
        $rawComps = array_values(array_filter(array_map(static fn ($x) => trim((string) $x), (array) ($in['competitors'] ?? [])), static fn ($x) => $x !== ''));
        if (count($rawComps) > self::MAX_COMPETITORS) {
            $errors['competitors'] = $this->t('الحد الأقصى ' . self::MAX_COMPETITORS . ' منافسين في الفحص المجاني.', 'The free check allows up to ' . self::MAX_COMPETITORS . ' competitors.');
            $rawComps = array_slice($rawComps, 0, self::MAX_COMPETITORS);
        }
        foreach ($rawComps as $i => $raw) {
            $r = $this->parseLink($raw, 'any');
            if (!$r['ok']) {
                $errors['competitor_' . $i] = $r['error'];
                continue;
            }
            $key = $r['platform'] . ':' . $r['handle'];
            if (isset($seen[$key]) || ($own[$r['platform']]['handle'] ?? null) === $r['handle']) {
                $errors['competitor_' . $i] = $this->t('ده نفس صفحتك أو متكرر - ضيف منافس مختلف.', 'This is your own page or a duplicate - add a different competitor.');
                continue;
            }
            $seen[$key] = true;
            $r['trackable'] = $r['platform'] === 'instagram';
            $r['note'] = $r['platform'] === 'instagram'
                ? $this->t('هنقرا نشاطه وتفاعل آخر منشوراته لو الحساب Business أو Creator وعام. غير كده هنقولك السبب بدل ما نخمّن.', 'We read its activity and recent-post engagement if the account is Business or Creator and public. Otherwise we tell you why instead of guessing.')
                : $this->t('Meta مابتسمحش بقراية أرقام صفحات فيسبوك التانية. هنفتحلك إعلاناته الشغالة من مكتبة إعلانات Meta الرسمية، ونقارنك بيه من الإعلانات والمحتوى المعلن.', 'Meta does not allow reading other Facebook pages’ numbers. We open its running ads from Meta’s official Ad Library for you.');
            $comps[] = $r;
        }
        if (!$own['facebook'] && !$own['instagram'] && !$errors) {
            $errors['own'] = $this->t('اكتب لينك صفحة فيسبوك أو حساب إنستجرام على الأقل.', 'Enter at least a Facebook page or an Instagram profile link.');
        }
        if ($errors) {
            return ['ok' => false, 'error' => reset($errors), 'field_errors' => $errors];
        }

        $igComps = count(array_filter($comps, static fn ($c) => $c['platform'] === 'instagram'));
        $hasOwnIg = (bool) $own['instagram'];
        $warnings = [];
        if ($igComps > 0 && !$hasOwnIg) {
            $warnings[] = $this->t('مراقبة منافسي إنستجرام بتشتغل من خلال حساب إنستجرام بيزنس بتاعك. ضيف لينك حسابك أو اربطه بعد التسجيل.', 'Instagram competitor tracking works through your own Instagram business account. Add your profile link or connect it after sign-up.');
        }

        return [
            'ok' => true,
            'own' => $own,
            'competitors' => $comps,
            'warnings' => $warnings,
            'measures' => $this->measures($comps),
            'requirements' => $this->requirements($igComps),
            'steps' => [
                $this->t('سجّل واشترك في باقة سوشيال.', 'Sign up and choose a social plan.'),
                $this->t('اربط صفحتك وحسابك بتسجيل دخول Meta الرسمي (صلاحيات قراءة النشاط والتعليقات).', 'Connect your page and profile through the official Meta login (read access to activity and comments).'),
                $this->t('المنصة بتقرا بياناتك الحقيقية وبتحلل كل صفحة وبتقارنها بمنافسينك.', 'The platform reads your real data, analyses each page and compares it with your competitors.'),
                $this->t('بتطلعلك الفجوات وخطة منشورات مجدولة بتتنفّذ بعد موافقتك.', 'It lists the gaps and a scheduled post plan that runs after your approval.'),
            ],
            'recommended' => self::recommendedTier($igComps),
            'intake' => [
                'fb' => $own['facebook']['url'] ?? null,
                'ig' => $own['instagram']['url'] ?? null,
                'comp' => array_map(static fn ($c) => $c['url'], $comps),
            ],
        ];
    }

    /** إيه اللي بيتقاس فعلًا (نفس مقاييس SocialAuditService وSocialCompetitors) - وصف مش أرقام */
    private function measures(array $comps): array
    {
        $out = [
            'own' => [
                [$this->t('معدل النشر (منشورات في الأسبوع)', 'Posting rate (posts per week)')],
                [$this->t('عمر آخر منشور', 'Age of the last post')],
                [$this->t('التفاعل نسبةً لعدد المتابعين', 'Engagement relative to followers')],
                [$this->t('تنوع المحتوى (فيديو، كاروسيل، صور)', 'Content mix (video, carousel, photos)')],
                [$this->t('طريق الحجز: موقع، واتساب، تليفون', 'Booking path: website, WhatsApp, phone')],
                [$this->t('سرعة الرد على أسئلة السعر والحجز في التعليقات', 'Reply speed to price and booking questions in comments')],
                [$this->t('لغة المحتوى مقابل أسواقك', 'Content language versus your markets')],
            ],
        ];
        if ($comps) {
            $out['competitors'] = [
                [$this->t('معدل نشرهم وتنوع محتواهم', 'Their posting rate and content mix')],
                [$this->t('متوسط تفاعل منشوراتهم وأنجح 3 منشورات', 'Average engagement per post and their top 3 posts')],
                [$this->t('أكتر هاشتاجات بيستخدموها', 'Hashtags they use most')],
                [$this->t('الفجوة بينك وبينهم وإيه المطلوب لتتخطاهم', 'The gap between you and them and what it takes to pass them')],
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function requirements(int $igComps): array
    {
        $r = [
            $this->t('أنت أدمن صفحة فيسبوك (عشان تقدر تربطها).', 'You are an admin of the Facebook page (to connect it).'),
            $this->t('حساب إنستجرام لازم يكون Business أو Creator ومربوط بصفحة فيسبوك.', 'The Instagram account must be Business or Creator and linked to the Facebook page.'),
        ];
        if ($igComps > 0) {
            $r[] = $this->t('حسابات المنافسين على إنستجرام لازم تكون عامة ومن نوع Business أو Creator، وإلا هنوضحلك إنها مش متاحة بدل ما نعرض أرقام تقريبية.', 'Competitor Instagram accounts must be public Business or Creator accounts, otherwise we show that they are unavailable instead of approximate numbers.');
        }
        return $r;
    }

    /**
     * أقل باقة للبيع بتغطي عدد منافسي إنستجرام المطلوبين - من إعدادات الأدمن الحية (مش أرقام ثابتة).
     * @return array{key:string,name:string,name_en:string,price_monthly:float,competitors:int}|null
     */
    public static function recommendedTier(int $igCompetitors): ?array
    {
        if (!class_exists('SocialPackages')) {
            return null;
        }
        try {
            $cfg = SocialPackages::config();
        } catch (Throwable $e) {
            return null;
        }
        foreach (['basic', 'pro', 'agency', 'enterprise'] as $k) {
            $t = $cfg['tiers'][$k] ?? null;
            if (!$t || empty($t['for_sale']) || (float) $t['price_monthly'] <= 0) {
                continue;
            }
            if ($igCompetitors === 0 || (int) $t['competitors'] >= $igCompetitors || (int) $t['competitors'] < 0) {
                return ['key' => $k, 'name' => (string) $t['name'], 'name_en' => (string) $t['name_en'], 'price_monthly' => (float) $t['price_monthly'], 'competitors' => (int) $t['competitors']];
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ تسليم الفحص للمنصة بعد التسجيل (كوكي موقّعة)

    private static function key(): ?string
    {
        $secret = defined('JWT_SECRET') ? (string) JWT_SECRET : (defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '');
        return strlen($secret) >= 32 ? hash_hmac('sha256', 'tourfecto_social_intake_v1', $secret) : null;
    }

    /** @param array{fb:?string,ig:?string,comp:list<string>} $intake */
    public static function seal(array $intake, ?int $now = null): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $body = rtrim(strtr(base64_encode(json_encode(['fb' => $intake['fb'] ?? null, 'ig' => $intake['ig'] ?? null, 'comp' => array_values($intake['comp'] ?? []), 'exp' => ($now ?? time()) + 14 * 86400], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $key);
    }

    /** يفتح الكوكي ويعيد التحقق من كل رابط من جديد (مش بنثق في محتواها) */
    public static function open(string $token, ?int $now = null): ?array
    {
        $key = self::key();
        if ($key === null || !preg_match('~^([A-Za-z0-9_\-]+)\.([a-f0-9]{64})$~', $token, $m) || !hash_equals(hash_hmac('sha256', $m[1], $key), $m[2])) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
        if (!is_array($d) || (int) ($d['exp'] ?? 0) < ($now ?? time())) {
            return null;
        }
        $svc = new self('en');
        $out = ['fb' => null, 'ig' => null, 'comp' => []];
        foreach (['fb' => 'facebook', 'ig' => 'instagram'] as $k => $platform) {
            if (!empty($d[$k])) {
                $r = $svc->parseLink((string) $d[$k], $platform);
                $out[$k] = $r['ok'] ? $r : null;
            }
        }
        foreach (array_slice((array) ($d['comp'] ?? []), 0, self::MAX_COMPETITORS) as $c) {
            $r = $svc->parseLink((string) $c, 'any');
            if ($r['ok']) {
                $out['comp'][] = $r;
            }
        }
        return ($out['fb'] || $out['ig']) ? $out : null;
    }
}
