<?php
/**
 * تحليل GEO من الإجابات المخزّنة فقط (seo_ai_answers): مفيش استدعاء AI ولا تكلفة هنا.
 * بيطلّع: تطور أسبوعي، مقارنة المنافسين، المصادر المستشهد بيها، ونتيجة كل سؤال (prompt) مع توصية مبنية على دليل.
 * الأرقام عينات API مجمّعة (مش ترتيب تطبيقات المستهلك)، وكل شريحة معاها حجم العينة ومستوى الثقة.
 * compose() منطق صرف بلا قاعدة بيانات علشان يتختبر مباشرة.
 */
final class GeoInsightsService
{
    private const MAX_ROWS = 3000;
    private const MAX_PROMPTS = 60;
    private const MAX_RECS = 10;
    private const PROVIDERS = ['openai', 'gemini', 'perplexity', 'claude'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /** @throws InvalidArgumentException لو الموقع مش بتاع المستخدم */
    public function overview(int $uid, int $wid, int $marketId = 0, ?string $now = null): array
    {
        $site = $this->db->query('SELECT main_url FROM websites WHERE id = ? AND user_id = ?', [$wid, $uid]);
        if (!$site) {
            throw new InvalidArgumentException('الموقع مش موجود');
        }
        $args = [$wid, $uid];
        $where = '';
        if ($marketId > 0) {
            $where = ' AND market_id = ?';
            $args[] = $marketId;
        }
        $rows = $this->safe(fn () => $this->db->query(
            'SELECT market_id, provider, question, week_start, mentioned_brand, brand_position, cited_domain, cited_urls, competitors_mentioned, checked_at
             FROM seo_ai_answers WHERE website_id = ? AND user_id = ?' . $where . ' AND week_start >= DATE_SUB(UTC_DATE(), INTERVAL 8 WEEK) ORDER BY checked_at DESC, id DESC LIMIT ' . self::MAX_ROWS,
            $args
        )) ?? [];
        $known = $this->safe(fn () => $this->db->query('SELECT competitor_name AS name, competitor_domain AS domain FROM competitors WHERE website_id = ? AND user_id = ? AND is_active = 1 LIMIT 30', [$wid, $uid])) ?? [];
        $host = self::host((string) $site[0]['main_url']);
        $out = self::compose((array) $rows, (array) $known, $host, $now ?? gmdate('Y-m-d H:i:s')) + ['website_id' => $wid, 'market_id' => $marketId];
        $out['diagnosis'] = $this->diagnose($uid, $wid);
        $out['progress'] = $this->progress($uid, $wid);
        if ($out['state'] !== 'not_measured') {
            // عينة جزئية: نعرض بس الأسباب اللي بتوقف القياس، مش رسالة "مستني أول تشغيل"
            $out['diagnosis'] = array_values(array_filter($out['diagnosis'], static fn ($d) => $d['code'] !== 'waiting_for_first_run'));
        }
        return $out;
    }

    /**
     * تقدّم القياس: فحوص الأسبوع الحالي حسب الحالة، ووظايف الطابور الخاصة بالموقع ده (قراءة فقط، من غير حجز ولا صرف).
     * @return array{calls:array,jobs:array}
     */
    public function progress(int $uid, int $wid): array
    {
        $week = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
        $calls = $this->safe(fn () => $this->db->query('SELECT provider, status, MAX(IF(cost_basis LIKE \'fail:%\', cost_basis, NULL)) AS basis, COUNT(*) AS n FROM seo_ai_calls WHERE website_id = ? AND user_id = ? AND week_start = ? GROUP BY provider, status ORDER BY provider, status', [$wid, $uid, $week])) ?? [];
        $jobs = $this->safe(fn () => $this->db->query(
            "SELECT status, COUNT(*) AS n, MIN(available_at) AS next_at FROM jobs WHERE job_class = 'AiVisibilityCheckJob' AND payload LIKE ? AND payload LIKE ? GROUP BY status",
            ['%"website_id":' . $wid . ',%', '%"user_id":' . $uid . ',%']
        )) ?? [];
        return ['week' => $week, 'calls' => array_map(static fn ($r) => ['provider' => (string) $r['provider'], 'status' => (string) $r['status'], 'why' => str_starts_with((string) ($r['basis'] ?? ''), 'fail:') ? substr((string) $r['basis'], 5) : '', 'n' => (int) $r['n']], (array) $calls),
            'jobs' => array_map(static fn ($r) => ['status' => (string) $r['status'], 'n' => (int) $r['n'], 'next_at' => $r['next_at']], (array) $jobs)];
    }

    /**
     * ليه مفيش قياس؟ نفس شروط AiVisibilityService::run بس بدون أي استدعاء مدفوع: مزوّد مفعّل؟ أسئلة معتمدة؟ موافقة حالية؟ أسئلة صالحة؟
     * @return array<int,array{code:string,market_id:int,message_ar:string,message_en:string}>
     */
    public function diagnose(int $uid, int $wid): array
    {
        $out = [];
        $add = static function (string $code, int $mid, string $ar, string $en) use (&$out): void {
            $out[] = ['code' => $code, 'market_id' => $mid, 'message_ar' => $ar, 'message_en' => $en];
        };
        try {
            $svc = new AiVisibilityService($this->db);
            if (!array_filter($svc->providers(), static fn ($p) => $p->configured())) {
                $add('no_provider_configured', 0, 'مفيش مزوّد AI مكتمل الإعداد (مفتاح + تفعيل + geo_enabled) من إعدادات الأدمن.', 'No AI provider is fully configured (key + enabled + geo_enabled) in admin settings.');
            }
            $cap = (float) (new SystemSettingsService())->get('geo_monthly_budget_usd', '0');
            if (!is_finite($cap) || $cap <= 0) {
                $add('budget_not_configured', 0, 'السقف الشهري لقياس GEO مش مضبوط (geo_monthly_budget_usd = 0) من إعدادات الأدمن، فالقياس بيرفض يبدأ.', 'The monthly GEO budget is not set (geo_monthly_budget_usd = 0) in admin settings, so measurement refuses to start.');
            } else {
                $spent = (float) ($this->safe(fn () => $this->db->query("SELECT COALESCE(SUM(IF(cost IS NULL, reserved_cost, cost)), 0) AS s FROM seo_ai_calls WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"))[0]['s'] ?? 0);
                if ($spent + 0.10 > $cap) {
                    $add('budget_reached', 0, 'السقف الشهري لقياس GEO اتوصّل له (' . round($spent, 2) . ' من ' . round($cap, 2) . ' دولار).', 'The monthly GEO budget is reached (' . round($spent, 2) . ' of ' . round($cap, 2) . ' USD).');
                }
            }
            foreach ((new SeoMarketService($this->db))->markets($wid, $uid) as $m) {
                $mid = (int) $m['id'];
                $set = $svc->set($uid, $wid, $mid);
                if (!$set) continue;
                if (empty($set['approved_at'])) {
                    $add('questions_not_approved', $mid, 'أسئلة السوق ده مش معتمدة.', 'The questions for this market are not approved.');
                    continue;
                }
                try {
                    $c = $svc->context($uid, $wid, $mid);
                } catch (Throwable $e) {
                    $add('business_profile_not_confirmed', $mid, 'بيانات الشركة في Business Hub مش مؤكدة أو مش مطابقة للموقع.', 'The business profile in Business Hub is not confirmed or does not match the site.');
                    continue;
                }
                if ((int) $set['profile_version'] !== $c['profile_version'] || $set['facts_hash'] !== $c['facts_hash']) {
                    $add('questions_need_current_approval', $mid, 'بيانات الشركة اتغيّرت بعد اعتماد الأسئلة، فلازم تعتمد الأسئلة تاني علشان القياس يشتغل.', 'Business data changed after the questions were approved; approve the questions again so measurement can run.');
                    continue;
                }
                try {
                    AiVisibilityQuestions::validate($set['questions'], $c['aliases']);
                } catch (InvalidArgumentException $e) {
                    $add('questions_invalid', $mid, 'الأسئلة المعتمدة مش صالحة للقياس: ' . $e->getMessage(), 'The approved questions are not valid for measurement: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            Logger::error('GEO diagnosis unavailable', ['website_id' => $wid, 'exception' => get_class($e)]);
        }
        if (!$out) {
            $add('waiting_for_first_run', 0, 'كل الشروط سليمة. القياس بيتنفّذ سؤال سؤال من الطابور (حوالي سؤال كل دقيقة)، فالنتائج بتظهر تدريجيًا.', 'All conditions look fine. Measurement runs one question at a time from the queue (about one per minute), so results appear gradually.');
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows إجابات (الأحدث الأول)
     * @param array<int,array{name?:string,domain?:string}> $known منافسين مسجّلين
     */
    public static function compose(array $rows, array $known, string $ownHost, string $now): array
    {
        if (!$rows) {
            return ['state' => 'not_measured', 'answers' => 0, 'trend' => [], 'competitors' => [], 'citations' => ['domains' => [], 'own_pages' => []], 'prompts' => [], 'recommendations' => [],
                'note' => 'No stored AI answers yet. Measurements need the AI visibility providers to be enabled and a question set approved.'];
        }
        foreach ($rows as &$r) {
            $r['_competitors'] = self::list($r['competitors_mentioned'] ?? null, true);
            $r['_urls'] = self::list($r['cited_urls'] ?? null, false);
        }
        unset($r);

        $knownKeys = [];
        foreach ($known as $k) {
            foreach ([strtolower(trim((string) ($k['name'] ?? ''))), self::host((string) ($k['domain'] ?? ''))] as $key) {
                if ($key !== '') $knownKeys[$key] = strtolower(trim((string) ($k['name'] ?? $k['domain'] ?? '')));
            }
        }

        return [
            'state' => 'measured_sample',
            'answers' => count($rows),
            'confidence' => count($rows) >= 100 ? 'high' : (count($rows) >= 30 ? 'medium' : 'low'),
            'as_of' => (string) $rows[0]['checked_at'],
            'stale' => (strtotime($now . ' UTC') - strtotime((string) $rows[0]['checked_at'] . ' UTC')) > 14 * 86400,
            'trend' => self::trend($rows),
            'competitors' => self::competitors($rows, $knownKeys),
            'citations' => self::citations($rows, $ownHost, $knownKeys),
            'prompts' => ($prompts = self::prompts($rows)),
            'recommendations' => self::recommendations($prompts),
            'note' => 'API samples with web search; not consumer app rankings. Compare trends, not single answers.',
        ];
    }

    /** أسبوعي إجمالي + لكل مزوّد: نسبة الذكر ونسبة الاستشهاد (على الإجابات اللي فيها بيانات استشهاد). */
    private static function trend(array $rows): array
    {
        $acc = [];
        foreach ($rows as $r) {
            $w = (string) $r['week_start'];
            $p = (string) $r['provider'];
            foreach (['all', $p] as $k) {
                $a = $acc[$w][$k] ?? ['n' => 0, 'm' => 0, 'cn' => 0, 'c' => 0];
                $a['n']++;
                $a['m'] += (int) (bool) $r['mentioned_brand'];
                if ($r['cited_domain'] !== null) {
                    $a['cn']++;
                    $a['c'] += (int) (bool) $r['cited_domain'];
                }
                $acc[$w][$k] = $a;
            }
        }
        ksort($acc);
        $out = [];
        $prev = null;
        foreach ($acc as $week => $by) {
            $fmt = static fn (array $a): array => ['answers' => $a['n'], 'share_of_voice' => $a['n'] ? round($a['m'] / $a['n'], 4) : null, 'citation_rate' => $a['cn'] ? round($a['c'] / $a['cn'], 4) : null];
            $row = ['week' => $week, 'all' => $fmt($by['all']), 'providers' => []];
            foreach (self::PROVIDERS as $p) {
                if (isset($by[$p])) $row['providers'][$p] = $fmt($by[$p]);
            }
            $row['delta_share_of_voice'] = $prev !== null && $row['all']['share_of_voice'] !== null ? round($row['all']['share_of_voice'] - $prev, 4) : null;
            $prev = $row['all']['share_of_voice'];
            $out[] = $row;
        }
        return $out;
    }

    /** لكل منافس: في كام إجابة اتذكر، ونسبة ده من الإجابات، وكام مرة اتذكر وإحنا لأ. */
    private static function competitors(array $rows, array $knownKeys): array
    {
        $n = count($rows);
        $brand = array_sum(array_map(static fn ($r) => (int) (bool) $r['mentioned_brand'], $rows));
        $by = [];
        foreach ($rows as $r) {
            $seen = [];
            foreach ($r['_competitors'] as $c) {
                $name = trim((string) ($c['name'] ?? $c['domain'] ?? ''));
                if ($name === '' || isset($seen[strtolower($name)])) continue;
                $seen[strtolower($name)] = true;
                $e = $by[strtolower($name)] ?? ['name' => $name, 'domain' => self::host((string) ($c['domain'] ?? '')), 'answers' => 0, 'without_us' => 0, 'providers' => []];
                $e['answers']++;
                if (!$r['mentioned_brand']) $e['without_us']++;
                $e['providers'][(string) $r['provider']] = true;
                $by[strtolower($name)] = $e;
            }
        }
        $out = [];
        foreach ($by as $key => $e) {
            $out[] = ['name' => $e['name'], 'domain' => $e['domain'], 'registered' => isset($knownKeys[$key]) || ($e['domain'] !== '' && isset($knownKeys[$e['domain']])),
                'answers' => $e['answers'], 'share' => round($e['answers'] / $n, 4), 'mentioned_without_us' => $e['without_us'], 'providers' => array_keys($e['providers'])];
        }
        usort($out, static fn ($a, $b) => [$b['answers'], $a['name']] <=> [$a['answers'], $b['name']]);
        return ['brand' => ['answers' => $brand, 'share' => round($brand / $n, 4)], 'list' => array_slice($out, 0, 15)];
    }

    /** أكتر النطاقات استشهادًا (مع تصنيف: موقعنا/منافس/آخر) وأكتر صفحات موقعنا استشهادًا. */
    private static function citations(array $rows, string $ownHost, array $knownKeys): array
    {
        $domains = [];
        $own = [];
        foreach ($rows as $r) {
            $seen = [];
            foreach ($r['_urls'] as $u) {
                $h = self::host((string) $u);
                if ($h === '') continue;
                if ($ownHost !== '' && $h === $ownHost) {
                    $own[(string) $u] = ($own[(string) $u] ?? 0) + 1;
                }
                if (isset($seen[$h])) continue;
                $seen[$h] = true;
                $d = $domains[$h] ?? ['domain' => $h, 'answers' => 0, 'providers' => []];
                $d['answers']++;
                $d['providers'][(string) $r['provider']] = true;
                $domains[$h] = $d;
            }
        }
        $n = count($rows);
        $list = [];
        foreach ($domains as $h => $d) {
            $list[] = ['domain' => $h, 'answers' => $d['answers'], 'share' => round($d['answers'] / $n, 4), 'providers' => array_keys($d['providers']),
                'kind' => ($ownHost !== '' && $h === $ownHost) ? 'own' : (isset($knownKeys[$h]) ? 'competitor' : 'other')];
        }
        usort($list, static fn ($a, $b) => [$b['answers'], $a['domain']] <=> [$a['answers'], $b['domain']]);
        arsort($own);
        $ownPages = [];
        foreach (array_slice($own, 0, 10, true) as $u => $c) $ownPages[] = ['url' => $u, 'answers' => $c];
        return ['domains' => array_slice($list, 0, 15), 'own_pages' => $ownPages];
    }

    /** نتيجة كل سؤال: آخر إجابة لكل مزوّد (الأحدث أولًا في المدخلات). */
    private static function prompts(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $q = trim((string) $r['question']);
            $p = (string) $r['provider'];
            if ($q === '' || isset($by[$q]['providers'][$p])) continue;
            $by[$q]['providers'][$p] = [
                'mentioned' => (bool) $r['mentioned_brand'],
                'position' => $r['brand_position'] !== null ? (int) $r['brand_position'] : null,
                'cited' => $r['cited_domain'] === null ? null : (bool) $r['cited_domain'],
                'competitors' => array_values(array_unique(array_filter(array_map(static fn ($c) => trim((string) ($c['name'] ?? $c['domain'] ?? '')), $r['_competitors'])))),
                'week' => (string) $r['week_start'],
            ];
        }
        $out = [];
        foreach ($by as $q => $d) {
            $ps = $d['providers'];
            $mentioned = count(array_filter($ps, static fn ($x) => $x['mentioned']));
            $citedMeasured = array_filter($ps, static fn ($x) => $x['cited'] !== null);
            $cited = count(array_filter($citedMeasured, static fn ($x) => $x['cited']));
            $comp = [];
            foreach ($ps as $x) foreach ($x['competitors'] as $c) $comp[$c] = ($comp[$c] ?? 0) + 1;
            arsort($comp);
            $out[] = ['question' => $q, 'providers' => $ps, 'providers_measured' => count($ps), 'mentioned_in' => $mentioned, 'cited_in' => $cited, 'cited_measured_in' => count($citedMeasured),
                'competitors' => array_keys($comp), 'status' => $mentioned === count($ps) ? 'won' : ($mentioned === 0 ? 'lost' : 'partial')];
        }
        usort($out, static fn ($a, $b) => [['lost' => 0, 'partial' => 1, 'won' => 2][$a['status']], -count($a['competitors']), $a['question']] <=> [['lost' => 0, 'partial' => 1, 'won' => 2][$b['status']], -count($b['competitors']), $b['question']]);
        return array_slice($out, 0, self::MAX_PROMPTS);
    }

    /** توصيات مبنية على الدليل الموجود في نفس السؤال؛ مفيش توصية من غير دليل. */
    private static function recommendations(array $prompts): array
    {
        $recs = [];
        foreach ($prompts as $p) {
            $ev = ['providers_mentioned' => $p['mentioned_in'], 'providers_measured' => $p['providers_measured'], 'competitors' => array_slice($p['competitors'], 0, 5)];
            if ($p['status'] === 'lost' && $p['competitors']) {
                $recs[] = ['question' => $p['question'], 'kind' => 'competitor_ahead', 'priority' => 'high', 'evidence' => $ev, 'url' => '/seo-expert',
                    'title_ar' => 'منافس بيظهر في الإجابة وإنت لأ', 'title_en' => 'A competitor appears in the answer and you do not',
                    'action_ar' => 'اكتب أو قوّي صفحة بتجاوب السؤال ده مباشرة بحقائق مؤكدة (سعر، مدة، سياسة)، وقارنها بصفحة: ' . implode('، ', array_slice($p['competitors'], 0, 3)),
                    'action_en' => 'Write or strengthen a page that answers this question directly with confirmed facts (price, duration, policy), and compare it with: ' . implode(', ', array_slice($p['competitors'], 0, 3))];
            } elseif ($p['status'] === 'lost') {
                $recs[] = ['question' => $p['question'], 'kind' => 'open_question', 'priority' => 'medium', 'evidence' => $ev, 'url' => '/aeo',
                    'title_ar' => 'السؤال ده مفيش فيه ذكر لحد', 'title_en' => 'Nobody is mentioned for this question',
                    'action_ar' => 'فرصة: انشر إجابة واضحة للسؤال ده في صفحة رحلة أو قسم أسئلة شائعة.',
                    'action_en' => 'Opportunity: publish a clear answer to this question on a trip page or an FAQ section.'];
            } elseif ($p['cited_measured_in'] > 0 && $p['cited_in'] === 0) {
                $recs[] = ['question' => $p['question'], 'kind' => 'mentioned_not_cited', 'priority' => 'medium', 'evidence' => $ev, 'url' => '/seo-schema',
                    'title_ar' => 'بيذكروك لكن من غير رابط لموقعك', 'title_en' => 'You are mentioned, but your site is not cited',
                    'action_ar' => 'ضيف معلومات قابلة للاقتباس وSchema سليم في الصفحة المناسبة علشان المحركات تستشهد بيها.',
                    'action_en' => 'Add citable facts and valid schema on the matching page so engines cite it.'];
            }
        }
        $w = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recs, static fn ($a, $b) => [$w[$a['priority']], -count($a['evidence']['competitors'])] <=> [$w[$b['priority']], -count($b['evidence']['competitors'])]);
        return array_slice($recs, 0, self::MAX_RECS);
    }

    /** @return array<int,array<string,mixed>> */
    private static function list($v, bool $objects): array
    {
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        if (!is_array($v)) {
            return [];
        }
        return $objects ? array_values(array_filter($v, 'is_array')) : array_values(array_filter($v, 'is_string'));
    }

    private static function host(string $u): string
    {
        $h = $u === '' ? '' : (parse_url(str_contains($u, '://') ? $u : 'https://' . $u, PHP_URL_HOST) ?: '');
        return strtolower(preg_replace('/^www\./', '', (string) $h));
    }

    private function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Logger::error('GeoInsightsService source unavailable', ['exception' => get_class($e), 'error' => $e->getMessage()]);
            return null;
        }
    }
}
