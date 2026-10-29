<?php
require_once __DIR__ . '/BrainSchema.php';

/**
 * Tourfecto - ذكاء السوق من مصادر رسمية مجانية (من غير مفاتيح)
 *
 * المصادر (كل رقم بيتحفظ بمصدره وتاريخه في market_signals):
 * - World Bank:  عدد السياح الداخلين للوجهة (ST.INT.ARVL) ونمو الناتج المحلي للأسواق (NY.GDP.MKTP.KD.ZG). سنوي ومتأخر.
 * - ECB:         سعر الدولار والإسترليني مقابل اليورو يوميًا.
 * - Eurostat:    ليالي الزوار الأجانب في فنادق الوجهة الأوروبية شهريًا (tour_occ_nim، c_resid=FOR) — للشركات اللي وجهتها أوروبية بس،
 *                لأن الرقم بيقيس السفر لجوه البلد ده (مش السفر من أوروبا لوجهات برّاها).
 * - GOV.UK:      تحذيرات السفر البريطانية للوجهة وتاريخ آخر تحديث.
 * - Nager.Date:  الإجازات الرسمية في الأسواق المستهدفة.
 *
 * البيانات دي عامة ومش بيانات عملاء، فبتتخزن مرة للمنصة كلها. التحديث بيحصل لما المصدر يقدم (TTL)،
 * مصدر واحد في كل طلب وبمهلة قصيرة عشان الصفحة ماتتقلش، أو من cron/market_intelligence.php.
 * لو مصدر فشل، الخطأ بيتسجل في market_fetch_log والعقل بيكمّل من غير الإشارة دي (مفيش رقم مخترع).
 */
final class MarketIntelligenceService
{
    /** مدة صلاحية كل مصدر بالثواني */
    public const TTL = ['govuk' => 21600, 'ecb' => 86400, 'eurostat' => 604800, 'nager' => 2592000, 'worldbank' => 604800];
    /** وجهات Eurostat (بيانات الإقامة السياحية متاحة ليها) */
    public const EUROSTAT_GEO = ['IT', 'ES', 'FR', 'GR', 'PT', 'HR', 'DE', 'NL', 'AT', 'BE', 'CZ', 'HU', 'MT', 'CY', 'IE', 'PL', 'SI', 'SK', 'RO', 'BG', 'DK', 'SE', 'FI', 'EE', 'LV', 'LT', 'LU', 'CH', 'NO', 'IS'];
    /** وجهات بتسعّر باليورو غالبًا */
    public const EUROZONE = ['IT', 'ES', 'FR', 'GR', 'PT', 'HR', 'DE', 'NL', 'AT', 'BE', 'MT', 'CY', 'IE', 'SI', 'SK', 'EE', 'LV', 'LT', 'LU', 'FI'];
    private const TIMEOUT = 8;
    private const GOVUK_SLUGS = ['EG' => 'egypt', 'JO' => 'jordan', 'MA' => 'morocco', 'TR' => 'turkey', 'AE' => 'united-arab-emirates', 'TN' => 'tunisia', 'GR' => 'greece',
        'IT' => 'italy', 'ES' => 'spain', 'FR' => 'france', 'PT' => 'portugal', 'HR' => 'croatia', 'DE' => 'germany', 'NL' => 'netherlands', 'AT' => 'austria', 'CZ' => 'czech-republic',
        'HU' => 'hungary', 'MT' => 'malta', 'CY' => 'cyprus', 'CH' => 'switzerland', 'IS' => 'iceland', 'NO' => 'norway', 'SA' => 'saudi-arabia', 'GE' => 'georgia', 'TZ' => 'tanzania', 'KE' => 'kenya'];
    /** أهم أسواق السياحة الوافدة لو الشركة ماحددتش أسواقها */
    public const DEFAULT_MARKETS = ['GB', 'DE', 'US', 'IT', 'FR'];
    private const COUNTRY_NAMES = [
        'GB' => ['uk', 'united kingdom', 'britain', 'england', 'بريطانيا', 'المملكة المتحدة', 'إنجلترا'], 'US' => ['usa', 'united states', 'america', 'أمريكا', 'امريكا', 'الولايات المتحدة'],
        'DE' => ['germany', 'ألمانيا', 'المانيا'], 'FR' => ['france', 'فرنسا'], 'IT' => ['italy', 'إيطاليا', 'ايطاليا'], 'ES' => ['spain', 'إسبانيا', 'اسبانيا'], 'CA' => ['canada', 'كندا'],
        'AU' => ['australia', 'أستراليا', 'استراليا'], 'NL' => ['netherlands', 'holland', 'هولندا'], 'PL' => ['poland', 'بولندا'], 'BE' => ['belgium', 'بلجيكا'], 'CH' => ['switzerland', 'سويسرا'],
        'AT' => ['austria', 'النمسا'], 'IE' => ['ireland', 'أيرلندا', 'ايرلندا'], 'SA' => ['saudi', 'السعودية'], 'AE' => ['uae', 'emirates', 'الإمارات', 'الامارات'], 'RU' => ['russia', 'روسيا'],
        'CN' => ['china', 'الصين'], 'JP' => ['japan', 'اليابان'], 'IN' => ['india', 'الهند'],
    ];
    private const DESTINATIONS = ['EG' => ['egypt', 'مصر', 'luxor', 'aswan', 'cairo', 'hurghada', 'sharm', 'الأقصر', 'أسوان', 'القاهرة', 'الغردقة', 'شرم'], 'JO' => ['jordan', 'الأردن', 'petra', 'البتراء'],
        'MA' => ['morocco', 'المغرب', 'marrakech', 'مراكش'], 'TR' => ['turkey', 'türkiye', 'تركيا', 'istanbul', 'إسطنبول', 'cappadocia'], 'AE' => ['dubai', 'abu dhabi', 'دبي', 'أبوظبي'],
        'SA' => ['saudi', 'السعودية', 'mecca', 'makkah', 'medina', 'مكة', 'المدينة المنورة', 'umrah', 'عمرة'], 'GE' => ['georgia', 'جورجيا', 'tbilisi', 'تبليسي'],
        'TZ' => ['tanzania', 'zanzibar', 'تنزانيا', 'زنجبار'], 'KE' => ['kenya', 'كينيا', 'nairobi'],
        'IT' => ['italy', 'إيطاليا', 'ايطاليا', 'rome', 'روما', 'florence', 'venice', 'فينيسيا', 'milan', 'ميلانو'], 'ES' => ['spain', 'إسبانيا', 'اسبانيا', 'madrid', 'مدريد', 'barcelona', 'برشلونة'],
        'FR' => ['france', 'فرنسا', 'paris', 'باريس', 'nice'], 'GR' => ['greece', 'اليونان', 'athens', 'أثينا', 'santorini', 'سانتوريني', 'mykonos'], 'PT' => ['portugal', 'البرتغال', 'lisbon', 'لشبونة', 'porto'],
        'HR' => ['croatia', 'كرواتيا', 'dubrovnik', 'split'], 'AT' => ['austria', 'النمسا', 'vienna', 'فيينا', 'salzburg'], 'CZ' => ['czech', 'التشيك', 'prague', 'براغ'],
        'HU' => ['hungary', 'المجر', 'budapest', 'بودابست'], 'NL' => ['amsterdam', 'أمستردام'], 'CH' => ['switzerland', 'سويسرا', 'zurich', 'interlaken'], 'MT' => ['malta', 'مالطا'],
        'CY' => ['cyprus', 'قبرص'], 'IS' => ['iceland', 'أيسلندا'], 'NO' => ['norway', 'النرويج', 'fjord']];

    private Database $db;
    /** @var callable(string):?string */
    private $http;

    public function __construct(?Database $db = null, ?callable $http = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
        $this->http = $http ?? [self::class, 'get'];
    }

    /** الوجهة والأسواق من نص الشركة نفسه (خدماتها وجمهورها) — من غير أي تخمين برة النص */
    public static function detect(string $servicesText, string $audienceText, array $marketList = []): array
    {
        $find = static function (string $text, array $dict): array {
            $t = mb_strtolower($text);
            $hits = [];
            foreach ($dict as $cc => $words) foreach ($words as $w) {
                $pos = mb_strpos($t, $w);
                if ($pos !== false) { $hits[$cc] = min($hits[$cc] ?? PHP_INT_MAX, $pos); }
            }
            asort($hits);
            return array_keys($hits);
        };
        $dest = $find($servicesText, self::DESTINATIONS)[0] ?? 'EG';
        $markets = array_values(array_unique(array_merge($find(implode(' ', $marketList), self::COUNTRY_NAMES), $find($audienceText, self::COUNTRY_NAMES))));
        $markets = array_values(array_diff($markets, [$dest]));
        return ['destination' => $dest, 'markets' => array_slice($markets ?: self::DEFAULT_MARKETS, 0, 6), 'markets_default' => !$markets];
    }

    /** صورة السوق لشركة: بتقرا المخزّن، وبتحدّث مصدر واحد قديم بس لو $refresh */
    public function forCompany(string $dest, array $markets, ?int $leadDays, bool $refresh = true): array
    {
        if ($refresh) $this->refreshOne($dest, $markets);
        $read = fn (string $src, string $key) => $this->db->query('SELECT period, value, meta_json, fetched_at FROM market_signals WHERE source = ? AND series_key = ? ORDER BY period DESC LIMIT 400', [$src, $key]) ?: [];

        $advice = $read('govuk', 'advice:' . $dest)[0] ?? null;
        $adviceMeta = $advice ? (json_decode((string) $advice['meta_json'], true) ?: []) : [];
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $holidays = [];
        foreach ($markets as $cc) foreach (array_reverse($read('nager', 'holidays:' . $cc)) as $h) {
            $days = (int) $today->diff(new DateTimeImmutable($h['period'], new DateTimeZone('UTC')))->format('%r%a');
            if ($days < 0 || $days > 120) continue;
            $meta = json_decode((string) $h['meta_json'], true) ?: [];
            $holidays[] = ['country' => $cc, 'date' => $h['period'], 'name' => (string) ($meta['name'] ?? ''), 'days_away' => $days];
        }
        usort($holidays, static fn ($a, $b) => $a['days_away'] <=> $b['days_away']);

        // تغيّر العملة في آخر 90 يوم: عدد الدولارات/الإسترليني لكل يورو
        $fxChange = static function (array $rows): ?array {
            if (count($rows) < 2) return null;
            $latest = $rows[0];
            $from = (new DateTimeImmutable($latest['period']))->modify('-90 days')->format('Y-m-d');
            $base = null;
            foreach ($rows as $r) { if ($r['period'] <= $from) { $base = $r; break; } }
            $base = $base ?? end($rows);
            if ((float) $base['value'] <= 0) return null;
            return ['latest' => round((float) $latest['value'], 4), 'base' => round((float) $base['value'], 4), 'from' => $base['period'], 'to' => $latest['period'],
                'change_pct' => round(((float) $latest['value'] / (float) $base['value'] - 1) * 100, 1)];
        };
        $fx = $fxChange($read('ecb', 'USD:EUR'));
        $fxGbp = $fxChange($read('ecb', 'GBP:EUR'));

        // الطلب على الوجهة الأوروبية: ليالي الأجانب في آخر شهر منشور مقابل نفس الشهر السنة اللي فاتت
        $demand = null;
        if (in_array($dest, self::EUROSTAT_GEO, true)) {
            $rows = [];
            foreach ($read('eurostat', 'nights_foreign:' . $dest) as $r) $rows[$r['period']] = (float) $r['value'];
            if ($rows) {
                krsort($rows);
                $latestPeriod = array_key_first($rows);
                $prevPeriod = (new DateTimeImmutable($latestPeriod . '-01'))->modify('-12 months')->format('Y-m');
                $demand = ['country' => $dest, 'period' => $latestPeriod, 'nights' => $rows[$latestPeriod], 'previous_period' => $prevPeriod, 'previous' => $rows[$prevPeriod] ?? null,
                    'change_pct' => isset($rows[$prevPeriod]) && $rows[$prevPeriod] > 0 ? round(($rows[$latestPeriod] / $rows[$prevPeriod] - 1) * 100, 1) : null];
            }
        }

        $arr = $read('worldbank', 'ST.INT.ARVL:' . $dest);
        $arrivals = $arr ? ['country' => $dest, 'latest' => ['year' => $arr[0]['period'], 'value' => (float) $arr[0]['value']], 'previous' => isset($arr[1]) ? ['year' => $arr[1]['period'], 'value' => (float) $arr[1]['value']] : null] : null;
        $economy = [];
        foreach ($markets as $cc) { $g = $read('worldbank', 'NY.GDP.MKTP.KD.ZG:' . $cc)[0] ?? null; if ($g) $economy[$cc] = ['gdp_growth' => round((float) $g['value'], 1), 'year' => $g['period']]; }

        $sources = [];
        foreach (array_keys(self::TTL) as $src) {
            $r = $this->db->query('SELECT fetched_at, status, error, rows_saved FROM market_fetch_log WHERE source = ? LIMIT 1', [self::scope($src, $dest, $markets)])[0] ?? null;
            if ($r) $sources[$src] = ['source' => $src] + $r;
        }

        return [
            'destination' => $dest, 'markets' => $markets, 'lead_time_days' => $leadDays,
            'travel_advice' => $advice ? ['country' => $dest, 'updated_at' => $advice['period'], 'change' => (string) ($adviceMeta['change'] ?? ''), 'url' => 'https://www.gov.uk/foreign-travel-advice/' . (self::GOVUK_SLUGS[$dest] ?? ''),
                'recent' => strtotime($advice['period'] . ' UTC') >= time() - 14 * 86400] : null,
            'holidays' => array_slice($holidays, 0, 12), 'fx' => $fx, 'fx_gbp' => $fxGbp, 'eurozone' => in_array($dest, self::EUROZONE, true), 'demand' => $demand, 'arrivals' => $arrivals, 'economy' => $economy, 'sources' => $sources,
        ];
    }

    /** مفتاح السجل: المصدر + النطاق اللي اتجاب له (الوجهة و/أو الأسواق) */
    public static function scope(string $src, string $dest, array $markets): string
    {
        $m = array_values(array_unique($markets));
        sort($m);
        return match ($src) {
            'ecb' => 'ecb',
            'govuk', 'eurostat' => $src . ':' . $dest,
            default => $src . ':' . $dest . ':' . implode(',', array_slice($m, 0, 8)),
        };
    }

    /** يحدّث أقدم مصدر انتهت صلاحيته للنطاق ده (مصدر واحد بس في الطلب) */
    public function refreshOne(string $dest, array $markets): ?string
    {
        foreach (self::TTL as $src => $ttl) {
            $at = $this->db->query('SELECT fetched_at FROM market_fetch_log WHERE source = ? LIMIT 1', [self::scope($src, $dest, $markets)])[0]['fetched_at'] ?? null;
            if ($at && strtotime($at . ' UTC') > time() - $ttl) continue;
            // مصادر مالهاش بيانات للوجهة دي (Eurostat برّة أوروبا، أو وجهة مالهاش صفحة GOV.UK) مابنطلبهاش
            if ($src === 'eurostat' && !in_array($dest, self::EUROSTAT_GEO, true)) continue;
            if ($src === 'govuk' && !isset(self::GOVUK_SLUGS[$dest])) continue;
            $this->refresh($src, $dest, $markets);
            return $src;
        }
        return null;
    }

    /** يجيب مصدر ويحفظه. بيرجع عدد الصفوف، أو بيرمي استثناء ويسجّله */
    public function refresh(string $src, string $dest, array $markets): int
    {
        $scope = self::scope($src, $dest, $markets);
        $lock = $this->db->query("SELECT GET_LOCK(?, 0) AS l", ['tourfecto:market:' . md5($scope)])[0]['l'] ?? 0;
        if ((int) $lock !== 1) return 0;
        try {
            $rows = match ($src) {
                'govuk' => $this->fetchGovUk($dest),
                'ecb' => $this->fetchEcb(),
                'eurostat' => $this->fetchEurostat($dest),
                'nager' => $this->fetchNager(array_values(array_unique(array_merge($markets, self::DEFAULT_MARKETS)))),
                'worldbank' => $this->fetchWorldBank($dest, array_values(array_unique(array_merge($markets, self::DEFAULT_MARKETS)))),
                default => throw new InvalidArgumentException('Unknown source'),
            };
            foreach ($rows as $r) {
                $this->db->query('INSERT INTO market_signals (source, series_key, period, value, meta_json, fetched_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE value = VALUES(value), meta_json = VALUES(meta_json), fetched_at = VALUES(fetched_at)',
                    [$src, $r['key'], $r['period'], $r['value'], isset($r['meta']) ? json_encode($r['meta'], JSON_UNESCAPED_UNICODE) : null]);
            }
            $this->log($scope, $rows ? 'ok' : 'empty', null, count($rows));
            return count($rows);
        } catch (Throwable $e) {
            $this->log($scope, 'error', mb_substr($e->getMessage(), 0, 290), 0);
            return 0;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK(?)", ['tourfecto:market:' . md5($scope)]);
        }
    }

    private function log(string $src, string $status, ?string $error, int $n): void
    {
        $this->db->query('INSERT INTO market_fetch_log (source, fetched_at, status, error, rows_saved) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), status = VALUES(status), error = VALUES(error), rows_saved = VALUES(rows_saved)', [$src, $status, $error, $n]);
    }

    private function json(string $url): array
    {
        $body = ($this->http)($url);
        if ($body === null || $body === '') throw new RuntimeException('No response from ' . parse_url($url, PHP_URL_HOST));
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Unexpected response from ' . parse_url($url, PHP_URL_HOST));
        return $data;
    }

    // ---------- المحللات: نقية بتاخد الرد وترجع صفوف، عشان تتختبر من غير شبكة ----------

    public function fetchGovUk(string $dest): array
    {
        $slug = self::GOVUK_SLUGS[$dest] ?? null;
        if (!$slug) return [];
        return self::parseGovUk($this->json('https://www.gov.uk/api/content/foreign-travel-advice/' . $slug), $dest);
    }

    public static function parseGovUk(array $d, string $dest): array
    {
        $updated = (string) ($d['public_updated_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $updated)) throw new RuntimeException('GOV.UK: missing public_updated_at');
        $change = trim(strip_tags((string) ($d['details']['change_description'] ?? $d['change_description'] ?? '')));
        return [['key' => 'advice:' . $dest, 'period' => substr($updated, 0, 10), 'value' => null, 'meta' => ['change' => mb_substr($change, 0, 400), 'title' => (string) ($d['title'] ?? ''), 'updated_at' => $updated]]];
    }

    public function fetchEcb(): array
    {
        $from = gmdate('Y-m-d', strtotime('-200 days'));
        $body = ($this->http)('https://data-api.ecb.europa.eu/service/data/EXR/D.USD+GBP.EUR.SP00.A?startPeriod=' . $from . '&format=csvdata');
        if (!$body) throw new RuntimeException('No response from data-api.ecb.europa.eu');
        return self::parseEcbCsv($body);
    }

    public static function parseEcbCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $head = str_getcsv((string) array_shift($lines));
        $iPeriod = array_search('TIME_PERIOD', $head, true);
        $iValue = array_search('OBS_VALUE', $head, true);
        $iCur = array_search('CURRENCY', $head, true);
        if ($iPeriod === false || $iValue === false || $iCur === false) throw new RuntimeException('ECB: unexpected CSV header');
        $out = [];
        foreach ($lines as $l) {
            $c = str_getcsv($l);
            if (!isset($c[$iPeriod], $c[$iValue]) || !is_numeric($c[$iValue]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $c[$iPeriod])) continue;
            if (!in_array($c[$iCur] ?? '', ['USD', 'GBP'], true)) continue;
            $out[] = ['key' => $c[$iCur] . ':EUR', 'period' => $c[$iPeriod], 'value' => (float) $c[$iValue]];
        }
        return $out;
    }

    /** ليالي الزوار المقيمين برّة البلد في الفنادق وأماكن الإقامة السياحية (شهري) */
    public function fetchEurostat(string $dest): array
    {
        if (!in_array($dest, self::EUROSTAT_GEO, true)) return [];
        $since = gmdate('Y-m', strtotime('-30 months'));
        return self::parseEurostat($this->json('https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/tour_occ_nim?geo=' . $dest
            . '&unit=NR&c_resid=FOR&nace_r2=I551-I553&freq=M&sinceTimePeriod=' . $since . '&lang=EN'), $dest);
    }

    /** JSON-stat 2.0: كل الأبعاد التانية ثابتة على قيمة واحدة، فترتيب القيمة = ترتيب الشهر */
    public static function parseEurostat(array $d, string $dest): array
    {
        $time = $d['dimension']['time']['category']['index'] ?? null;
        if (!is_array($time) || !isset($d['value'])) throw new RuntimeException('Eurostat: unexpected response' . (isset($d['error']) ? ' (' . json_encode($d['error']) . ')' : ''));
        foreach (($d['size'] ?? []) as $i => $n) if ((int) $n !== 1 && ($d['id'][$i] ?? '') !== 'time') throw new RuntimeException('Eurostat: more than one series returned');
        $out = [];
        foreach ($time as $period => $idx) {
            $v = $d['value'][(string) $idx] ?? $d['value'][$idx] ?? null;
            if ($v === null || !is_numeric($v) || !preg_match('/^\d{4}-\d{2}$/', (string) $period)) continue;
            $out[] = ['key' => 'nights_foreign:' . $dest, 'period' => (string) $period, 'value' => (float) $v];
        }
        return $out;
    }

    public function fetchNager(array $countries): array
    {
        $out = [];
        $years = array_unique([(int) gmdate('Y'), (int) gmdate('Y', strtotime('+120 days'))]);
        foreach (array_slice($countries, 0, 8) as $cc) foreach ($years as $y) {
            if (!preg_match('/^[A-Z]{2}$/', $cc)) continue;
            $out = array_merge($out, self::parseNager($this->json('https://date.nager.at/api/v3/PublicHolidays/' . $y . '/' . $cc), $cc));
        }
        return $out;
    }

    public static function parseNager(array $list, string $cc): array
    {
        $out = [];
        foreach ($list as $h) {
            // الإجازات القومية بس (مش إجازة ولاية أو مقاطعة)
            if (!is_array($h) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($h['date'] ?? '')) || (isset($h['global']) && $h['global'] === false)) continue;
            $out[] = ['key' => 'holidays:' . $cc, 'period' => $h['date'], 'value' => null, 'meta' => ['name' => (string) ($h['name'] ?? $h['localName'] ?? '')]];
        }
        return $out;
    }

    public function fetchWorldBank(string $dest, array $markets): array
    {
        $out = self::parseWorldBank($this->json('https://api.worldbank.org/v2/country/' . $dest . '/indicator/ST.INT.ARVL?format=json&mrv=5&per_page=50'), 'ST.INT.ARVL');
        $codes = implode(';', array_filter(array_slice($markets, 0, 8), static fn ($c) => preg_match('/^[A-Z]{2}$/', $c)));
        if ($codes !== '') $out = array_merge($out, self::parseWorldBank($this->json('https://api.worldbank.org/v2/country/' . $codes . '/indicator/NY.GDP.MKTP.KD.ZG?format=json&mrv=3&per_page=100'), 'NY.GDP.MKTP.KD.ZG'));
        return $out;
    }

    public static function parseWorldBank(array $d, string $indicator): array
    {
        // الشكل: [ {page,...}, [ {country:{id}, date, value}, ... ] ] — لو فيه خطأ بيرجع [ {message:[...]} ]
        if (isset($d[0]['message'])) throw new RuntimeException('World Bank: ' . (string) ($d[0]['message'][0]['value'] ?? 'error'));
        $out = [];
        foreach (($d[1] ?? []) as $r) {
            $cc = (string) ($r['country']['id'] ?? '');
            if (!preg_match('/^[A-Z]{2}$/', $cc) || !isset($r['value']) || !is_numeric($r['value']) || !preg_match('/^\d{4}$/', (string) ($r['date'] ?? ''))) continue;
            $out[] = ['key' => $indicator . ':' . $cc, 'period' => (string) $r['date'], 'value' => (float) $r['value']];
        }
        return $out;
    }

    /** GET بسيط لروابط ثابتة معروفة (مش روابط من العميل) */
    public static function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/csv'], CURLOPT_USERAGENT => 'TourfectoMarketIntelligence/1.0 (+https://tourfecto.pro)']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body) || $code < 200 || $code >= 300) throw new RuntimeException('HTTP ' . $code . ' from ' . parse_url($url, PHP_URL_HOST));
        return $body;
    }
}
