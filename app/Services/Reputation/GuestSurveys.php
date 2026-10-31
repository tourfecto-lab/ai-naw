<?php

/**
 * Reputation: private guest feedback survey ("how likely are you to recommend us", 0-10, plus a comment).
 * Complements public reviews: the survey page never replaces or hides the public review links (no review gating),
 * it only gives guests a private channel. Low scores notify the owner so the problem is handled before it becomes a
 * public review. Stateless signed link per website (no enumeration), needs migration 2026_10_31_000002.
 */
class GuestSurveys
{
    public const LOW_SCORE = 6;
    public const PER_IP_MINUTES = 10;
    private static ?bool $ready = null;

    private static function key(): string
    {
        foreach (['ENCRYPTION_KEY', 'APP_KEY', 'JWT_SECRET'] as $k) {
            $v = function_exists('env') ? env($k) : getenv($k);
            if (is_string($v) && strlen($v) >= 16) {
                return $v;
            }
        }
        throw new RuntimeException('Signing key is not configured');
    }

    public static function code(int $websiteId): string
    {
        return $websiteId . '-' . substr(hash_hmac('sha256', 'guest-survey:' . $websiteId, self::key()), 0, 20);
    }

    public static function verify(string $code): int
    {
        if (!preg_match('/^([1-9][0-9]{0,9})-([a-f0-9]{20})$/', $code, $m)) {
            return 0;
        }
        return hash_equals(substr(hash_hmac('sha256', 'guest-survey:' . $m[1], self::key()), 0, 20), $m[2]) ? (int) $m[1] : 0;
    }

    public static function ready(?Database $db = null): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $db = $db ?: Database::getInstance();
            return self::$ready = (bool) $db->query("SHOW TABLES LIKE 'guest_surveys'");
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    public static function flush(): void
    {
        self::$ready = null;
    }

    public static function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', 'survey-ip:' . $ip, self::key()), 0, 16);
    }

    /**
     * @return array{id:int,low:bool}
     * @throws InvalidArgumentException invalid input; RuntimeException('rate') too many from the same address
     */
    public static function submit(int $websiteId, array $in, string $ipHash, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            throw new RuntimeException('unavailable');
        }
        $site = $db->query('SELECT id, user_id, company_name FROM websites WHERE id = ? LIMIT 1', [$websiteId])[0] ?? null;
        if (!$site) {
            throw new InvalidArgumentException('Survey not found');
        }
        if (!isset($in['score']) || !is_numeric($in['score']) || (int) $in['score'] != $in['score'] || (int) $in['score'] < 0 || (int) $in['score'] > 10) {
            throw new InvalidArgumentException('Choose a score from 0 to 10');
        }
        $score = (int) $in['score'];
        $comment = mb_substr(trim((string) ($in['comment'] ?? '')), 0, 2000);
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 150);
        $contact = mb_substr(trim((string) ($in['contact'] ?? '')), 0, 190);
        $recent = (int) ($db->query('SELECT COUNT(*) c FROM guest_surveys WHERE website_id = ? AND ip_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ' . self::PER_IP_MINUTES . ' MINUTE)', [$websiteId, $ipHash])[0]['c'] ?? 0);
        if ($recent >= 2) {
            throw new RuntimeException('rate');
        }
        $id = (int) $db->query(
            'INSERT INTO guest_surveys (user_id, website_id, score, comment, guest_name, guest_contact, ip_hash) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $site['user_id'], $websiteId, $score, $comment !== '' ? $comment : null, $name !== '' ? $name : null, $contact !== '' ? $contact : null, $ipHash]
        );
        $low = $score <= self::LOW_SCORE;
        if ($low && class_exists('Notification')) {
            try {
                Notification::notify((int) $site['user_id'], 'survey_low', 'Low guest feedback score (' . $score . '/10)', mb_substr($comment !== '' ? $comment : 'No comment', 0, 140), '/reputation/surveys');
            } catch (Throwable $e) {
                // the response is saved; a notification failure must not lose it
            }
        }
        return ['id' => $id, 'low' => $low];
    }

    /** Net Promoter Score and counts for one account (owner), optional website and period start. */
    public static function summary(int $userId, ?int $websiteId = null, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $params = [$userId];
        $where = 'user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
        if ($websiteId) {
            $where .= ' AND website_id = ?';
            $params[] = $websiteId;
        }
        $r = $db->query("SELECT COUNT(*) total, SUM(score >= 9) promoters, SUM(score BETWEEN 7 AND 8) passives, SUM(score <= 6) detractors, ROUND(AVG(score),1) avg_score, SUM(status = 'new') pending FROM guest_surveys WHERE $where", $params)[0] ?? [];
        $total = (int) ($r['total'] ?? 0);
        $p = (int) ($r['promoters'] ?? 0);
        $d = (int) ($r['detractors'] ?? 0);
        return [
            'total' => $total, 'promoters' => $p, 'passives' => (int) ($r['passives'] ?? 0), 'detractors' => $d,
            'avg_score' => $r['avg_score'] !== null ? (float) $r['avg_score'] : null,
            'nps' => $total ? (int) round(($p - $d) / $total * 100) : null,
            'pending' => (int) ($r['pending'] ?? 0),
        ];
    }

    public static function listFor(int $userId, string $status = '', int $page = 1, int $perPage = 20, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $where = 's.user_id = ?';
        $params = [$userId];
        if (in_array($status, ['new', 'handled'], true)) {
            $where .= ' AND s.status = ?';
            $params[] = $status;
        }
        $total = (int) ($db->query("SELECT COUNT(*) c FROM guest_surveys s WHERE $where", $params)[0]['c'] ?? 0);
        $rows = $db->query("SELECT s.id, s.website_id, s.score, s.comment, s.guest_name, s.guest_contact, s.status, s.created_at, s.handled_at, w.company_name AS site FROM guest_surveys s LEFT JOIN websites w ON w.id = s.website_id WHERE $where ORDER BY s.created_at DESC, s.id DESC LIMIT $perPage OFFSET $offset", $params) ?: [];
        return ['rows' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    public static function markHandled(int $userId, int $id, bool $handled = true, ?Database $db = null): bool
    {
        $db = $db ?: Database::getInstance();
        $row = $db->query('SELECT id FROM guest_surveys WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if (!$row) {
            return false;
        }
        $db->exec('UPDATE guest_surveys SET status = ?, handled_at = ' . ($handled ? 'NOW()' : 'NULL') . ' WHERE id = ? AND user_id = ?', [$handled ? 'handled' : 'new', $id, $userId]);
        return true;
    }

    private const TEXT = [
        'title' => ['Private feedback', 'رأيك الخاص'],
        'intro' => ['Tell us privately how we did. It takes 20 seconds.', 'قولنا رأيك بشكل خاص. بياخد 20 ثانية.'],
        'q' => ['How likely are you to recommend us to a friend?', 'ما مدى احتمال إنك تنصح صديق بينا؟'],
        'comment' => ['What is the main reason for your score? (optional)', 'إيه السبب الرئيسي لدرجتك؟ (اختياري)'],
        'name' => ['Your name (optional)', 'اسمك (اختياري)'],
        'contact' => ['Phone or email if you want us to reply (optional)', 'تليفون أو إيميل لو عايزنا نرد عليك (اختياري)'],
        'send' => ['Send feedback', 'ابعت'],
        'thanks' => ['Thank you, we received your feedback.', 'شكرًا، وصلنا رأيك.'],
        'public' => ['You can also share a public review:', 'ولو حابب تشاركنا رأيك علنًا:'],
        'unavailable' => ['This page is not available right now.', 'الصفحة دي مش متاحة دلوقتي.'],
        'low' => ['0 = not likely', '0 = مستبعد'], 'high' => ['10 = very likely', '10 = مرجّح جدًا'],
        'err' => ['Something went wrong, please try again.', 'حصلت مشكلة، حاول تاني.'],
    ];

    public static function page(string $company, string $code, bool $ar, array $publicLinks = [], bool $available = true): string
    {
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $k): string => htmlspecialchars(self::TEXT[$k][$ar ? 1 : 0], ENT_QUOTES, 'UTF-8');
        $links = '';
        foreach ($publicLinks as $l) {
            $links .= '<a class="pub" rel="noopener noreferrer" href="' . $e($l['url']) . '">' . $e($l['label']) . '</a>';
        }
        $scores = '';
        for ($i = 0; $i <= 10; $i++) {
            $scores .= '<label class="sc"><input type="radio" name="score" value="' . $i . '" required><span>' . $i . '</span></label>';
        }
        $form = $available
            ? '<form id="f" novalidate><fieldset><legend>' . $t('q') . '</legend><div class="scores">' . $scores . '</div><div class="ends"><span>' . $t('low') . '</span><span>' . $t('high') . '</span></div></fieldset>'
              . '<label>' . $t('comment') . '<textarea name="comment" rows="3" maxlength="2000"></textarea></label>'
              . '<label>' . $t('name') . '<input name="name" maxlength="150" autocomplete="name"></label>'
              . '<label>' . $t('contact') . '<input name="contact" maxlength="190" autocomplete="email"></label>'
              . '<input name="website_url" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true"><button type="submit">' . $t('send') . '</button><p id="msg" role="alert" class="err"></p></form>'
              . '<div id="done" hidden><p class="ok">' . $t('thanks') . '</p>' . ($links !== '' ? '<p class="muted">' . $t('public') . '</p>' . $links : '') . '</div>'
            : '<p class="muted">' . $t('unavailable') . '</p>';
        $js = $available ? '<script>(function(){var f=document.getElementById("f"),m=document.getElementById("msg");f.addEventListener("submit",function(ev){ev.preventDefault();m.textContent="";var d=new FormData(f),o={};d.forEach(function(v,k){o[k]=v});'
            . 'if(o.score===undefined){m.textContent=' . json_encode(self::TEXT['q'][$ar ? 1 : 0], JSON_UNESCAPED_UNICODE) . ';return}'
            . 'fetch("/survey/' . $e($code) . '",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(o)}).then(function(r){return r.json()}).then(function(r){if(r&&r.success){f.hidden=true;document.getElementById("done").hidden=false}else{m.textContent=(r&&r.error)||' . json_encode(self::TEXT['err'][$ar ? 1 : 0], JSON_UNESCAPED_UNICODE) . '}}).catch(function(){m.textContent=' . json_encode(self::TEXT['err'][$ar ? 1 : 0], JSON_UNESCAPED_UNICODE) . '})})})();</script>' : '';
        return '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>' . $e($company) . ' - ' . $t('title') . '</title>'
            . '<style>:root{color-scheme:light dark;--bg:#f6f7fb;--card:#fff;--ink:#111827;--muted:#6b7280;--brand:#4f46e5}@media(prefers-color-scheme:dark){:root{--bg:#0f172a;--card:#1e293b;--ink:#f1f5f9;--muted:#94a3b8;--brand:#818cf8}}'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Tahoma,sans-serif;padding:16px}.card{background:var(--card);max-width:460px;width:100%;border-radius:18px;padding:26px 22px;box-shadow:0 10px 30px rgba(0,0,0,.12)}'
            . 'h1{font-size:21px;margin:0 0 4px}.muted{color:var(--muted)}label{display:block;margin:14px 0 0;font-size:14px}textarea,input:not([type=radio]){width:100%;box-sizing:border-box;margin-top:6px;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;background:transparent;color:inherit}'
            . 'fieldset{border:0;padding:0;margin:16px 0 0}legend{font-size:15px;font-weight:600;margin-bottom:8px}.scores{display:grid;grid-template-columns:repeat(11,1fr);gap:4px}.sc input{position:absolute;opacity:0}.sc span{display:block;text-align:center;padding:9px 0;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer;font-size:14px}'
            . '.sc input:checked+span{background:var(--brand);color:#fff;border-color:var(--brand)}.sc input:focus-visible+span{outline:3px solid #f59e0b}.ends{display:flex;justify-content:space-between;font-size:12px;color:var(--muted);margin-top:6px}'
            . 'button,.pub{display:block;width:100%;box-sizing:border-box;margin-top:16px;padding:13px;border:0;border-radius:12px;background:var(--brand);color:#fff;font:inherit;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}.hp{position:absolute;left:-9999px;width:1px;height:1px}.err{color:#b91c1c;min-height:1em}.ok{font-weight:700}</style></head>'
            . '<body><main class="card"><h1>' . $e($company) . '</h1><p class="muted">' . $t('intro') . '</p>' . $form . '</main>' . $js . '</body></html>';
    }
}
