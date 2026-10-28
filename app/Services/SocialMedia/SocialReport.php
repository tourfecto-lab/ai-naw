<?php

/**
 * Tourfecto - تقرير أداء السوشيال الشهري (زي تقارير الوكالات)
 *
 * صفحة واحدة بهوية الشركة (اللوجو والألوان) جاهزة للطباعة أو "حفظ كـ PDF" من المتصفح وإرسالها لصاحب الشركة:
 * المؤشرات الأساسية مع المقارنة بالشهر اللي فات، الأداء حسب المنصة وحسب نوع المحتوى، أفضل 5 منشورات،
 * أفضل مواعيد النشر، أداء الرد على التعليقات، وتوصيات عملية للشهر الجاي مبنية على الأرقام نفسها (مش كلام عام).
 * build() بيجمع الأرقام، insights() نقي (بيتختبر)، render() بيطلع HTML.
 * @version 1.0.0
 */
require_once __DIR__ . '/SocialBestTimes.php';
require_once __DIR__ . '/SocialGrowth.php';
require_once __DIR__ . '/SocialAttribution.php';

final class SocialReport
{
    public static function build(int $userId, string $month): array
    {
        $db = Database::getInstance();
        $start = $month . '-01 00:00:00';
        $end = gmdate('Y-m-d H:i:s', strtotime($start . ' +1 month'));
        $prevStart = gmdate('Y-m-d H:i:s', strtotime($start . ' -1 month'));
        $data = ['month' => $month, 'brand' => self::brand($userId), 'current' => self::period($userId, $start, $end), 'previous' => self::period($userId, $prevStart, $start)];

        $data['top_posts'] = $db->query(
            "SELECT sp.id AS post_id, sp.content, c.platform, c.external_location_name AS page, pm.likes, pm.comments, pm.shares, pm.reach, pm.permalink, t.published_at,
                    (pm.likes + pm.comments * 2 + pm.shares * 3) AS score
               FROM social_post_metrics pm JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
               LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
              WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= ? AND t.published_at < ?
              ORDER BY score DESC LIMIT 5",
            [$userId, $start, $end]
        ) ?: [];

        // الحجوزات اللي جت من روابط المنشورات (لو تتبّع الحجوزات مفعّل)
        $data['bookings'] = class_exists('SocialAttribution') ? SocialAttribution::totals($userId, $start, $end) : null;
        $data['bookings_prev'] = class_exists('SocialAttribution') ? SocialAttribution::totals($userId, $prevStart, $start) : null;
        $attr = class_exists('SocialAttribution') ? SocialAttribution::forPosts($userId, array_map(static fn ($r) => (int) $r['post_id'], $data['top_posts'])) : [];
        foreach ($data['top_posts'] as &$tp) {
            $tp['bookings'] = $attr[(int) $tp['post_id']]['bookings'] ?? 0;
        }
        unset($tp);

        $data['growth'] = class_exists('SocialGrowth') ? SocialGrowth::series($userId, 31) : [];
        // Active status is a customer choice, not the same as OAuth connection health.
        // Keep historic follower data for paused accounts, but show it separately.
        $data['growth_account_states'] = [];
        $data['growth_account_keys'] = [];
        try {
            $stateRows = $db->query(
                "SELECT c.id AS connection_id, c.platform, c.external_location_id, c.external_location_name, COALESCE(p.is_active, 1) AS is_active
                   FROM platform_connections c
              LEFT JOIN social_account_profiles p ON p.platform_connection_id = c.id
                  WHERE (c.user_id = ? OR c.website_id IN
                         (SELECT id FROM websites WHERE user_id = ?))",
                [$userId, $userId]
            ) ?: [];
            foreach ($stateRows as $stateRow) {
                $connectionId = (int)$stateRow['connection_id'];
                $data['growth_account_states'][$connectionId] = (bool)$stateRow['is_active'];
                $externalId = trim((string)($stateRow['external_location_id'] ?? ''));
                $data['growth_account_keys'][$connectionId] = $externalId !== ''
                    ? strtolower((string)($stateRow['platform'] ?? '')) . ':id:' . $externalId
                    : strtolower((string)($stateRow['platform'] ?? '')) . ':name:' . trim(mb_strtolower((string)($stateRow['external_location_name'] ?? ''), 'UTF-8'));
            }
        } catch (Throwable $e) {
            // When account state cannot be fetched, don't mislabel accounts as paused.
        }

        // Imported Meta posts are reported separately from posts published via Tourfecto.
        // Historical metrics can be unavailable (NULL); do not replace them with invented zeros.
        $data['historical_top_posts'] = [];
        try {
            if ($db->query("SHOW TABLES LIKE 'social_meta_history'")) {
                $data['historical_top_posts'] = $db->query(
                    "SELECT h.connection_id, h.external_post_id, h.content, h.platform, h.permalink,
                            h.published_at, h.likes, h.comments, h.shares,
                            (COALESCE(h.likes,0) + COALESCE(h.comments,0)*2 + COALESCE(h.shares,0)*3) AS score
                       FROM social_meta_history h
                      WHERE h.user_id = ? AND h.published_at >= ? AND h.published_at < ?
                      ORDER BY score DESC, h.published_at DESC LIMIT 5",
                    [$userId, $start, $end]
                ) ?: [];
            }
        } catch (Throwable $e) {
            $data['historical_top_posts'] = [];
        }


        // حسب نوع المحتوى: فيديو / ألبوم / صورة / نص / ستوري
        $hasAlbum = SocialPostService::albumTableExists();
        $hasFormat = SocialPostService::proSchema();
        $rows = $db->query(
            "SELECT m.type AS media_type, sp.media_item_id, " . ($hasAlbum ? '(SELECT COUNT(*) FROM social_post_media spm WHERE spm.social_post_id = sp.id)' : '0') . " AS album,
                    " . ($hasFormat ? 'sp.post_format' : "'feed'") . " AS post_format, COALESCE(pm.likes + pm.comments * 2 + pm.shares * 3, 0) AS score, pm.reach, (pm.target_id IS NOT NULL) AS measured
               FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
               LEFT JOIN media_items m ON m.id = sp.media_item_id
               LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
              WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at >= ? AND t.published_at < ?",
            [$userId, $start, $end]
        ) ?: [];
        $formats = [];
        foreach ($rows as $r) {
            $k = $r['post_format'] === 'story' ? 'story' : ((int) $r['album'] >= 2 ? 'album' : ($r['media_type'] === 'short_video' ? 'video' : ($r['media_item_id'] ? 'photo' : 'text')));
            $formats[$k] = $formats[$k] ?? ['posts' => 0, 'measured' => 0, 'score' => 0, 'reach' => 0];
            $formats[$k]['posts']++;
            if ((int) $r['measured']) {
                $formats[$k]['measured']++;
                $formats[$k]['score'] += (int) $r['score'];
                $formats[$k]['reach'] += (int) $r['reach'];
            }
        }
        foreach ($formats as &$f) {
            $f['avg_score'] = $f['measured'] ? round($f['score'] / $f['measured'], 1) : null;
        }
        unset($f);
        $data['formats'] = $formats;

        $tz = (string) (($db->query('SELECT timezone FROM users WHERE id = ?', [$userId])[0]['timezone'] ?? '') ?: 'UTC');
        $samples = SocialBestTimes::samples($userId);
        $data['timezone'] = $tz;
        $data['best_times'] = SocialBestTimes::preferencesFrom($samples, $tz)['source'] === 'measured' ? SocialBestTimes::rank($samples, $tz, 3) : [];

        $data['comments'] = null;
        try {
            if ($db->query("SHOW TABLES LIKE 'social_comments'")) {
                $c = $db->query(
                    "SELECT COUNT(*) AS total, SUM(status = 'replied') AS replied, SUM(status = 'new') AS open, SUM(status IN ('new','replied') AND intent <> 'spam') AS answerable,
                            SUM(intent IN ('price','booking')) AS leads, AVG(CASE WHEN status = 'replied' AND replied_at IS NOT NULL AND commented_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, commented_at, replied_at) END) AS avg_reply_min
                       FROM social_comments WHERE user_id = ? AND commented_at >= ? AND commented_at < ?",
                    [$userId, $start, $end]
                );
                $data['comments'] = $c ? array_map(static fn ($v) => $v === null ? null : (float) $v, $c[0]) : null;
            }
        } catch (Throwable $e) {
        }
        $data['insights'] = self::insights($data);
        return $data;
    }

    private static function period(int $userId, string $start, string $end): array
    {
        $db = Database::getInstance();
        $out = ['published' => 0, 'failed' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'measured' => 0, 'by_platform' => []];
        foreach ($db->query(
            "SELECT c.platform, t.status, COUNT(*) AS n, COALESCE(SUM(pm.likes),0) AS likes, COALESCE(SUM(pm.comments),0) AS comments, COALESCE(SUM(pm.shares),0) AS shares,
                    COALESCE(SUM(pm.reach),0) AS reach, COUNT(pm.target_id) AS measured
               FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
               LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
               LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
              WHERE sp.user_id = ? AND t.status IN ('published','failed') AND COALESCE(t.published_at, t.scheduled_at, t.created_at) >= ? AND COALESCE(t.published_at, t.scheduled_at, t.created_at) < ?
              GROUP BY c.platform, t.status",
            [$userId, $start, $end]
        ) ?: [] as $r) {
            $p = (string) ($r['platform'] ?: 'other');
            $out['by_platform'][$p] = $out['by_platform'][$p] ?? ['published' => 0, 'failed' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'measured' => 0];
            if ($r['status'] === 'failed') {
                $out['failed'] += (int) $r['n'];
                $out['by_platform'][$p]['failed'] += (int) $r['n'];
                continue;
            }
            $out['published'] += (int) $r['n'];
            $out['by_platform'][$p]['published'] += (int) $r['n'];
            foreach (['likes', 'comments', 'shares', 'reach', 'measured'] as $k) {
                $out[$k] += (int) $r[$k];
                $out['by_platform'][$p][$k] += (int) $r[$k];
            }
        }
        $out['engagement'] = $out['likes'] + $out['comments'] + $out['shares'];
        $out['success_rate'] = ($out['published'] + $out['failed']) > 0 ? round($out['published'] / ($out['published'] + $out['failed']) * 100) : null;
        $out['avg_engagement'] = $out['measured'] ? round($out['engagement'] / $out['measured'], 1) : null;
        return $out;
    }

    private static function brand(int $userId): array
    {
        $brand = ['name' => null, 'logo' => null, 'primary' => '#0f766e', 'accent' => '#f59e0b'];
        try {
            $site = Database::getInstance()->query('SELECT id, company_name, domain FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
            if ($site) {
                $brand['name'] = $site[0]['company_name'] ?: $site[0]['domain'];
                $kit = (new SocialBrandStudio())->kit($userId, (int) $site[0]['id']);
                $brand = ['name' => $kit['brand_name'] ?: $brand['name'], 'logo' => $kit['logo_url'], 'primary' => $kit['primary'], 'accent' => $kit['accent']];
            }
        } catch (Throwable $e) {
        }
        return $brand;
    }

    /**
     * توصيات مبنية على الأرقام (نقية). كل توصية [ar, en].
     * @return array<int, array{0:string,1:string}>
     */
    public static function insights(array $d): array
    {
        $cur = $d['current'];
        $prev = $d['previous'];
        $out = [];
        if ($cur['published'] === 0) {
            return [['مفيش منشورات اتنشرت الشهر ده. ابدأ بخطة شهرية من 8 لـ 12 منشور على الأقل عشان الصفحة تفضل حاضرة عند جمهورك.', 'Nothing was published this month. Start a monthly plan of at least 8–12 posts to keep the page visible to your audience.']];
        }
        if ($prev['engagement'] > 0 && $cur['measured'] > 0) {
            $delta = round(($cur['engagement'] - $prev['engagement']) / $prev['engagement'] * 100);
            if ($delta <= -15) {
                $out[] = ["التفاعل نزل {$delta}% عن الشهر اللي فات. ارجع لنوع المحتوى اللي جاب أعلى تفاعل (تحت) وزوّد الأسئلة والفيديو القصير.", "Engagement fell {$delta}% vs last month. Lean on the best-performing format below and add more questions and short video."];
            } elseif ($delta >= 15) {
                $out[] = ["التفاعل زاد +{$delta}% عن الشهر اللي فات - كمّل على نفس الاتجاه وزوّد عدد المنشورات تدريجيًا.", "Engagement grew +{$delta}% vs last month — keep the direction and increase volume gradually."];
            }
        }
        $formats = array_filter($d['formats'] ?? [], static fn ($f) => $f['avg_score'] !== null && $f['measured'] >= 2);
        if (count($formats) >= 2) {
            uasort($formats, static fn ($a, $b) => $b['avg_score'] <=> $a['avg_score']);
            $names = ['video' => ['الفيديو/الريلز', 'video/reels'], 'album' => ['الألبومات والكاروسيل', 'albums/carousels'], 'photo' => ['الصور', 'photos'], 'text' => ['المنشورات النصية', 'text posts'], 'story' => ['الستوري', 'stories']];
            $keys = array_keys($formats);
            $best = $keys[0];
            $worst = end($keys);
            $ratio = $formats[$worst]['avg_score'] > 0 ? round($formats[$best]['avg_score'] / $formats[$worst]['avg_score'], 1) : null;
            $out[] = ['أعلى تفاعل جه من ' . $names[$best][0] . ($ratio ? " (×{$ratio} مقارنة بـ" . $names[$worst][0] . ')' : '') . '. خلّي نسبته في خطة الشهر الجاي أعلى.',
                      'Your highest engagement came from ' . $names[$best][1] . ($ratio ? " (×{$ratio} vs " . $names[$worst][1] . ')' : '') . '. Give it a bigger share next month.'];
        }
        if (!empty($d['best_times'])) {
            $t = $d['best_times'][0];
            $daysAr = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];
            $daysEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $out[] = ['أفضل ميعاد عندك: ' . $daysAr[$t['dow']] . ' الساعة ' . sprintf('%02d:00', $t['hour']) . '. فعّل "الجدولة الذكية" في الخطة الشهرية عشان المنشورات تنزل في أفضل مواعيدك.',
                      'Your best slot: ' . $daysEn[$t['dow']] . ' at ' . sprintf('%02d:00', $t['hour']) . '. Turn on Smart timing in the monthly plan.'];
        }
        if ($cur['success_rate'] !== null && $cur['success_rate'] < 95) {
            $out[] = ["نسبة نجاح النشر {$cur['success_rate']}% - راجع لوحة صحة النشر واربط الصفحات اللي محتاجة إعادة ربط.", "Publishing success rate is {$cur['success_rate']}% — check Publishing health and reconnect affected pages."];
        }
        $c = $d['comments'] ?? null;
        if ($c && ($c['total'] ?? 0) > 0) {
            $rate = round(((float) $c['replied']) / max(1, (float) ($c['answerable'] ?? $c['total'])) * 100);
            if ($rate < 80) {
                $out[] = ["اترد على {$rate}% بس من التعليقات" . ((int) ($c['leads'] ?? 0) > 0 ? ' و' . (int) $c['leads'] . ' منهم بيسألوا عن سعر أو حجز' : '') . '. كل تعليق سعر من غير رد = حجز ضايع. استخدم صندوق التعليقات والرد المقترح.',
                          "Only {$rate}% of comments were answered" . ((int) ($c['leads'] ?? 0) > 0 ? ', and ' . (int) $c['leads'] . ' asked about price or booking' : '') . '. Every unanswered price question is a lost booking — use the comments inbox.'];
            }
        }
        $bk = $d['bookings'] ?? null;
        if ($bk && $bk['bookings'] > 0) {
            $best = null;
            foreach ($d['top_posts'] ?? [] as $tp) {
                if ((int) ($tp['bookings'] ?? 0) > (int) ($best['bookings'] ?? 0)) {
                    $best = $tp;
                }
            }
            $out[] = ['السوشيال جاب ' . (int) $bk['bookings'] . ' حجز الشهر ده' . ($best ? '، أعلاهم منشور «' . mb_substr((string) $best['content'], 0, 40) . '…» - اعمل منشورات أكتر بنفس الفكرة.' : '.'),
                      'Social brought ' . (int) $bk['bookings'] . ' bookings this month' . ($best ? '; the top one came from "' . mb_substr((string) $best['content'], 0, 40) . '…" — create more posts like it.' : '.')];
        } elseif ($bk !== null && (int) ($bk['clicks'] ?? 0) === 0 && $cur['published'] > 0) {
            $out[] = ['مفيش روابط تتبّع حجوزات في منشورات الشهر ده. فعّل «روابط تتبّع الحجوزات» وحط رابط موقعك في المنشور عشان تعرف كل بوست جاب كام حجز.',
                      'No booking-tracking links in this month\'s posts. Enable "Booking-tracking links" and add your website link to see bookings per post.'];
        }
        if ($cur['published'] < 8) {
            $out[] = ['عدد المنشورات قليل (' . $cur['published'] . '). المعدل الصحي لشركات السياحة من 3 لـ 5 منشورات في الأسبوع على كل منصة.', 'Posting volume is low (' . $cur['published'] . '). A healthy rhythm for tourism brands is 3–5 posts per week per platform.'];
        }
        return array_slice($out, 0, 6);
    }

    private static function growthHtml(array $growth, array $accountStates, array $accountKeys, callable $t, callable $nf, array $plat): string
    {
        // Snapshots are grouped by internal connection ID; a single Meta asset can
        // have several historical connection IDs after reconnecting. Deduplicate
        // by the actual external account identity and prefer an active connection.
        $unique = [];
        foreach ($growth as $g) {
            if (($g['current'] ?? null) === null) {
                continue;
            }
            $connectionId = (int)($g['connection_id'] ?? 0);
            $platform = strtolower(trim((string)($g['platform'] ?? '')));
            $name = trim(mb_strtolower((string)($g['name'] ?? ''), 'UTF-8'));
            $externalKey = $accountKeys[$connectionId] ?? '';
            // Prefer a stable Meta external ID; fall back to display name when
            // missing (common for older migrated connections).
            $key = $externalKey !== '' ? $externalKey : $platform . ':name:' . $name;
            $isActive = $accountStates[$connectionId] ?? false;
            if (!isset($unique[$key]) || ($isActive && !$unique[$key]['active']) ||
                ($isActive === $unique[$key]['active'] && $connectionId > $unique[$key]['id'])) {
                $unique[$key] = ['growth' => $g, 'active' => $isActive, 'id' => $connectionId];
            }
        }
        // For legacy connections with different external IDs but the exact same
        // platform/name, keep a single display row, preferring the active asset.
        $dedup = [];
        foreach ($unique as $entry) {
            $g = $entry['growth'];
            $nameKey = strtolower(trim((string)($g['platform'] ?? ''))) . ':' . trim(mb_strtolower((string)($g['name'] ?? ''), 'UTF-8'));
            if (!isset($dedup[$nameKey]) || ($entry['active'] && !$dedup[$nameKey]['active']) ||
                ($entry['active'] === $dedup[$nameKey]['active'] && $entry['id'] > $dedup[$nameKey]['id'])) {
                $dedup[$nameKey] = $entry;
            }
        }
        $activeRows = '';
        $pausedRows = '';
        $pausedCount = 0;
        foreach ($dedup as $entry) {
            $g = $entry['growth'];
            $ch = $g['change'] ?? null;
            $platform = (string)($g['platform'] ?? '');
            $row = '<tr><td>' . htmlspecialchars(($plat[$platform] ?? $platform) . ' · ' . (string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . $nf($g['current']) . '</td><td>'
                . ($ch === null ? '–' : '<span class="delta ' . ($ch >= 0 ? 'up' : 'down') . '">' . ($ch >= 0 ? '+' : '') . $nf($ch) . (($g['change_pct'] ?? null) !== null ? ' (' . $g['change_pct'] . '%)' : '') . '</span>') . '</td></tr>';
            if ($entry['active']) {
                $activeRows .= $row;
            } else {
                $pausedRows .= $row;
                $pausedCount++;
            }
        }
        $head = '<div class="scroll"><table><tr><th>' . $t('الحساب', 'Account') . '</th><th>' . $t('المتابعين الحاليين', 'Current followers') . '</th><th>' . $t('التغيير خلال الشهر', 'Change this month') . '</th></tr>';
        $out = '<section><h2>' . $t('نمو المتابعين للحسابات النشطة', 'Follower growth – active accounts') . '</h2>';
        $out .= $activeRows ? $head . $activeRows . '</table></div>' : '<p class="muted">' . $t('لا توجد بيانات نمو للحسابات النشطة خلال هذه الفترة.', 'No follower-growth measurements for active accounts in this period.') . '</p>';
        if ($pausedRows) {
            $out .= '<details style="margin-top:18px"><summary style="cursor:pointer;font-weight:600">'
                . $t('الحسابات المتوقفة والسجل التاريخي', 'Paused accounts and historical records') . ' (' . $pausedCount . ')</summary>'
                . '<p class="muted">' . $t('للعرض التاريخي فقط؛ هذه الحسابات ليست نشطة ضمن باقة النشر.', 'Historical reference only; these accounts are not active for publishing.') . '</p>'
                . $head . $pausedRows . '</table></div></details>';
        }
        return $out . '</section>';
    }

    public static function render(array $d, bool $ar): string
    {
        $t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $nf = static fn ($n): string => $n === null ? '–' : number_format((float) $n, is_float($n) && floor($n) != $n ? 1 : 0);
        $cur = $d['current'];
        $prev = $d['previous'];
        $delta = static function ($now, $before) use ($t): string {
            if (!$before) {
                return '';
            }
            $p = round(($now - $before) / $before * 100);
            $cls = $p >= 0 ? 'up' : 'down';
            return '<span class="delta ' . $cls . '">' . ($p >= 0 ? '▲ +' : '▼ ') . $p . '%</span>';
        };
        $plat = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'other' => '—'];
        $fmtNames = ['video' => $t('فيديو / ريلز', 'Video / Reels'), 'album' => $t('ألبوم / كاروسيل', 'Album / Carousel'), 'photo' => $t('صورة', 'Photo'), 'text' => $t('نص', 'Text'), 'story' => $t('ستوري', 'Story')];
        $daysAr = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];
        $daysEn = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $brand = $d['brand'];
        $primary = preg_match('/^#[0-9a-f]{6}$/i', (string) $brand['primary']) ? $brand['primary'] : '#0f766e';
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) $brand['accent']) ? $brand['accent'] : '#f59e0b';
        $monthLabel = $e($d['month']);

        $kpi = static function (string $label, string $value, string $sub = '') {
            return '<div class="kpi"><div class="kv' . (mb_strlen(strip_tags($value)) > 9 ? ' sm' : '') . '">' . $value . '</div><div class="kl">' . $label . '</div>' . ($sub ? '<div class="ks">' . $sub . '</div>' : '') . '</div>';
        };
        $kpis = $kpi($t('منشورات اتنشرت', 'Posts published'), $nf($cur['published']), $delta($cur['published'], $prev['published']))
            . $kpi($t('الوصول (مشاهدون فريدون)', 'Reach (unique viewers)'), $nf($cur['reach']), $delta($cur['reach'], $prev['reach']))
            . $kpi($t('إجمالي التفاعل', 'Total engagement'), $nf($cur['engagement']), $delta($cur['engagement'], $prev['engagement']))
            . $kpi($t('متوسط التفاعل للمنشور', 'Avg engagement / post'), $nf($cur['avg_engagement']), $delta((float) $cur['avg_engagement'], (float) $prev['avg_engagement']))
            . $kpi($t('نسبة نجاح النشر', 'Publishing success'), $cur['success_rate'] === null ? '–' : $cur['success_rate'] . '%')
            . $kpi($t('لايك · تعليق · مشاركة', 'Likes · Comments · Shares'), $nf($cur['likes']) . ' · ' . $nf($cur['comments']) . ' · ' . $nf($cur['shares']));
        $bk = $d['bookings'] ?? null;
        if ($bk && ($bk['clicks'] || $bk['bookings'])) {
            $bkPrev = $d['bookings_prev'] ?? ['bookings' => 0, 'revenue' => 0];
            $fmtMoney = static function ($v, $cur) use ($nf): string {
                if (class_exists('NumberFormatter') && $cur) {
                    $f = new NumberFormatter('en', NumberFormatter::CURRENCY);
                    $out = $f->formatCurrency((float) $v, (string) $cur);
                    if ($out !== false) {
                        return $out;
                    }
                }
                return $nf(round((float) $v)) . ($cur ? ' ' . $cur : '');
            };
            $kpis .= $kpi($t('نقرات روابط المنشورات', 'Post link clicks'), $nf($bk['clicks']))
                . $kpi($t('حجوزات من السوشيال', 'Bookings from social'), $nf($bk['bookings']), $delta($bk['bookings'], $bkPrev['bookings']))
                . $kpi($t('إيراد مؤكد من السوشيال', 'Confirmed social revenue'), htmlspecialchars($fmtMoney($bk['revenue'], $bk['currency']), ENT_QUOTES, 'UTF-8'), $delta($bk['revenue'], $bkPrev['revenue']));
        }

        $platRows = '';
        foreach ($cur['by_platform'] as $k => $v) {
            $eng = $v['likes'] + $v['comments'] + $v['shares'];
            $platRows .= '<tr><td>' . $e($plat[$k] ?? $k) . '</td><td>' . $nf($v['published']) . '</td><td>' . $nf($v['reach']) . '</td><td>' . $nf($eng) . '</td><td>' . ($v['measured'] ? $nf(round($eng / $v['measured'], 1)) : '–') . '</td><td>' . $nf($v['failed']) . '</td></tr>';
        }
        $fmtRows = '';
        foreach ($d['formats'] as $k => $v) {
            $fmtRows .= '<tr><td>' . ($fmtNames[$k] ?? $e($k)) . '</td><td>' . $nf($v['posts']) . '</td><td>' . ($v['avg_score'] === null ? '–' : $nf($v['avg_score'])) . '</td><td>' . ($v['measured'] ? $nf(round($v['reach'] / $v['measured'])) : '–') . '</td></tr>';
        }
        $topRows = '';
        foreach ($d['top_posts'] as $i => $p) {
            $topRows .= '<tr><td>' . ($i + 1) . '</td><td class="txt">' . $e(mb_substr((string) $p['content'], 0, 110)) . (!empty($p['permalink']) && preg_match('#^https://#', (string) $p['permalink']) ? ' <a href="' . $e($p['permalink']) . '" target="_blank" rel="noopener">↗</a>' : '') . '</td><td>' . $e($plat[$p['platform']] ?? $p['platform']) . '</td><td>' . $nf((int) $p['likes']) . '</td><td>' . $nf((int) $p['comments']) . '</td><td>' . $nf((int) $p['shares']) . '</td><td>' . $nf($p['reach'] === null ? null : (int) $p['reach']) . '</td><td>' . ((int) ($p['bookings'] ?? 0) ? '<b>' . (int) $p['bookings'] . '</b>' : '–') . '</td></tr>';
        }
        $historyRows = '';
        foreach (($d['historical_top_posts'] ?? []) as $i => $p) {
            $url = (string) ($p['permalink'] ?? '');
            $link = preg_match('#^https://(?:www\.)?(?:facebook\.com|instagram\.com)/#i', $url)
                ? ' <a href="' . $e($url) . '" target="_blank" rel="noopener noreferrer">↗</a>' : '';
            $historyRows .= '<tr><td>' . ($i + 1) . '</td><td class="txt">'
                . $e(mb_substr((string) ($p['content'] ?? ''), 0, 120)) . $link
                . '</td><td>' . $e($plat[$p['platform']] ?? $p['platform']) . '</td><td>'
                . $nf($p['likes'] === null ? null : (int) $p['likes']) . '</td><td>'
                . $nf($p['comments'] === null ? null : (int) $p['comments']) . '</td><td>'
                . $nf($p['shares'] === null ? null : (int) $p['shares']) . '</td></tr>';
        }
        $historyHtml = $historyRows === '' ? '' : '<section><h2>'
            . $t('أفضل المنشورات المستوردة من Meta', 'Top historical Meta posts')
            . '</h2><p class="muted">' . $t('منشورات أصلية مستوردة؛ غير مضافة لمجاميع نشر المنصة.', 'Imported posts; excluded from Tourfecto publishing totals.')
            . '</p><div class="scroll"><table><tr><th>#</th><th>' . $t('المنشور', 'Post')
            . '</th><th>' . $t('المنصة', 'Platform') . '</th><th>👍</th><th>💬</th><th>↗</th></tr>'
            . $historyRows . '</table></div></section>';
        $times = '';
        foreach ($d['best_times'] as $i => $bt) {
            $times .= '<li><b>' . ['🥇', '🥈', '🥉'][$i] . ' ' . $e($ar ? $daysAr[$bt['dow']] : $daysEn[$bt['dow']]) . ' ' . sprintf('%02d:00', $bt['hour']) . '</b> <span class="muted">' . $t('متوسط تفاعل', 'avg engagement') . ' ' . $nf($bt['avg']) . ' · ' . (int) $bt['n'] . ' ' . $t('منشور', 'posts') . '</span></li>';
        }
        $c = $d['comments'];
        $commentsHtml = '';
        if ($c && ($c['total'] ?? 0) > 0) {
            $rate = round(((float) $c['replied']) / max(1, (float) ($c['answerable'] ?? $c['total'])) * 100);
            $commentsHtml = '<section><h2>' . $t('التعليقات وخدمة العملاء', 'Comments & customer care') . '</h2><div class="kpis">'
                . $kpi($t('تعليقات الجمهور', 'Audience comments'), $nf((int) $c['total']))
                . $kpi($t('نسبة الرد', 'Reply rate'), $rate . '%')
                . $kpi($t('عملاء محتملين (سعر/حجز)', 'Leads (price/booking)'), $nf((int) $c['leads']))
                . $kpi($t('متوسط وقت الرد', 'Avg reply time'), $c['avg_reply_min'] === null ? '–' : ($c['avg_reply_min'] >= 120 ? $nf(round($c['avg_reply_min'] / 60, 1)) . ' ' . $t('ساعة', 'h') : $nf((int) $c['avg_reply_min']) . ' ' . $t('دقيقة', 'min')))
                . '</div></section>';
        }
        $insights = '';
        foreach ($d['insights'] as $in) {
            $insights .= '<li>' . $e($ar ? $in[0] : $in[1]) . '</li>';
        }
        $logo = !empty($brand['logo']) ? '<img src="' . $e($brand['logo']) . '" alt="" class="logo">' : '';
        $dir = $ar ? 'rtl' : 'ltr';
        $lang = $ar ? 'ar' : 'en';
        $empty = '<tr><td colspan="8" class="muted">' . $t('مفيش بيانات مقاسة في الشهر ده - اضغط "تحديث التفاعل" في صفحة السوشيال.', 'No measured data for this month — press "Refresh engagement" on the Social page.') . '</td></tr>';
        $prevMonth = gmdate('Y-m', strtotime($d['month'] . '-01 -1 month'));
        $nextMonth = gmdate('Y-m', strtotime($d['month'] . '-01 +1 month'));

        return '<!doctype html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $t('تقرير السوشيال', 'Social report') . ' ' . $monthLabel . '</title>'
            . '<style>:root{--p:' . $primary . ';--a:' . $accent . ';--ink:#1f2937;--muted:#6b7280;--line:#e5e7eb;--bg:#f8fafc}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.6 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}'
            . '.page{max-width:960px;margin:24px auto;background:#fff;border-radius:16px;box-shadow:0 2px 18px rgba(0,0,0,.06);overflow:hidden}header{background:var(--p);color:#fff;padding:26px 30px;display:flex;align-items:center;gap:18px}header h1{margin:0;font-size:22px}header .sub{opacity:.85;font-size:13px}.logo{width:64px;height:64px;object-fit:contain;background:#fff;border-radius:12px;padding:6px}'
            . 'section{padding:22px 30px;border-top:1px solid var(--line)}h2{font-size:16px;margin:0 0 14px;color:var(--p)}.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}.kpi{border:1px solid var(--line);border-radius:12px;padding:12px 14px}.kv{font-size:22px;font-weight:700;white-space:nowrap}.kv.sm{font-size:16px}.kl{color:var(--muted);font-size:12.5px}.ks{margin-top:2px}.delta{font-size:12px;font-weight:600}.delta.up{color:#15803d}.delta.down{color:#b91c1c}'
            . 'table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:start;vertical-align:top}th{color:var(--muted);font-weight:600;font-size:12px}td.txt{max-width:380px}.muted{color:var(--muted)}ul.ins{margin:0;padding-inline-start:20px}ul.ins li{margin:6px 0}ul.times{list-style:none;margin:0;padding:0}ul.times li{margin:6px 0}'
            . '.bar{display:flex;gap:8px;justify-content:space-between;align-items:center;max-width:960px;margin:16px auto 0;padding:0 16px}.bar a,.bar button{border:1px solid var(--line);background:#fff;border-radius:10px;padding:7px 14px;font:inherit;cursor:pointer;color:var(--ink);text-decoration:none}.bar button.pr{background:var(--p);color:#fff;border-color:var(--p)}footer{padding:14px 30px;color:var(--muted);font-size:12px;border-top:4px solid var(--a)}'
            . '.scroll{overflow-x:auto}@media(max-width:640px){header,section,footer{padding-inline:16px}.page{margin:12px 0;border-radius:0}}@media print{body{background:#fff}.bar{display:none}.page{box-shadow:none;margin:0;max-width:none;border-radius:0}section{break-inside:avoid}header{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body>'
            . '<div class="bar"><span><a href="/social">← ' . $t('السوشيال', 'Social') . '</a> <a href="?month=' . $e($prevMonth) . '">' . $t('الشهر السابق', 'Previous month') . '</a> <a href="?month=' . $e($nextMonth) . '">' . $t('الشهر التالي', 'Next month') . '</a></span><button class="pr" onclick="window.print()">🖨 ' . $t('طباعة / حفظ PDF', 'Print / Save PDF') . '</button></div>'
            . '<div class="page"><header>' . $logo . '<div><h1>' . $t('تقرير أداء السوشيال ميديا', 'Social media performance report') . '</h1><div class="sub">' . $e($brand['name'] ?: '') . ' · ' . $monthLabel . ' · ' . $t('مقارنة بالشهر السابق', 'vs previous month') . '</div></div></header>'
            . '<section><h2>' . $t('الملخص', 'Summary') . '</h2><div class="kpis">' . $kpis . '</div></section>'
            . '<section><h2>' . $t('توصيات للشهر الجاي', 'Recommendations for next month') . '</h2><ul class="ins">' . ($insights ?: '<li class="muted">' . $t('الأداء مستقر - كمّل على نفس الخطة.', 'Performance is stable — keep the current plan.') . '</li>') . '</ul></section>'
            . '<section><h2>' . $t('الأداء حسب المنصة', 'By platform') . '</h2><div class="scroll"><table><tr><th>' . $t('المنصة', 'Platform') . '</th><th>' . $t('منشورات', 'Posts') . '</th><th>' . $t('الوصول', 'Reach') . '</th><th>' . $t('التفاعل', 'Engagement') . '</th><th>' . $t('متوسط/منشور', 'Avg/post') . '</th><th>' . $t('فشل', 'Failed') . '</th></tr>' . ($platRows ?: $empty) . '</table></div></section>'
            . '<section><h2>' . $t('الأداء حسب نوع المحتوى', 'By content format') . '</h2><div class="scroll"><table><tr><th>' . $t('النوع', 'Format') . '</th><th>' . $t('منشورات', 'Posts') . '</th><th>' . $t('متوسط نقاط التفاعل', 'Avg engagement score') . '</th><th>' . $t('متوسط الوصول', 'Avg reach') . '</th></tr>' . ($fmtRows ?: $empty) . '</table></div><p class="muted" style="font-size:12px">' . $t('نقاط التفاعل = لايك + تعليق×2 + مشاركة×3', 'Engagement score = likes + comments×2 + shares×3') . '</p></section>'
            . '<section><h2>' . $t('أفضل 5 منشورات', 'Top 5 posts') . '</h2><div class="scroll"><table><tr><th>#</th><th>' . $t('المنشور', 'Post') . '</th><th>' . $t('المنصة', 'Platform') . '</th><th>👍</th><th>💬</th><th>🔁</th><th>👁</th><th>🧾</th></tr>' . ($topRows ?: $empty) . '</table></div></section>'
            . '<section><h2>' . $t('أفضل مواعيد النشر', 'Best publishing times') . ' <span class="muted" style="font-size:12px">(' . $e($d['timezone']) . ')</span></h2>' . ($times ? '<ul class="times">' . $times . '</ul>' : '<p class="muted">' . $t('محتاجين ' . SocialBestTimes::MIN_MEASURED . ' منشورات متقاسة على الأقل عشان نحدد مواعيدك الفعلية.', 'We need at least ' . SocialBestTimes::MIN_MEASURED . ' measured posts to determine your real best times.') . '</p>') . '</section>'
            . $historyHtml
            . self::growthHtml($d['growth'] ?? [], $d['growth_account_states'] ?? [], $d['growth_account_keys'] ?? [], $t, $nf, $plat)
            . $commentsHtml
            . '<footer>' . $t('الأرقام من Meta Graph API (الوصول = مشاهدون فريدون). تيك توك ويوتيوب بيتحسبوا في عدد المنشورات بس.', 'Figures from Meta Graph API (reach = unique viewers). TikTok and YouTube count toward post volume only.') . '</footer></div></body></html>';
    }
}
