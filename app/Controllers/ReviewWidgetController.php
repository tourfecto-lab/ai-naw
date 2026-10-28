<?php
/**
 * ويدجت التقييمات على موقع العميل: سطر سكربت واحد بيعرض متوسط التقييم وأحدث التقييمات الحلوة (4-5 نجوم).
 * GET /widget/reviews?site=ID&t=TOKEN  (عام - بيتحمّل من متصفح زوار موقع العميل)
 * التوكن HMAC لكل موقع، فمحدش يقدر يعرض تقييمات موقع تاني بتغيير الرقم.
 */
class ReviewWidgetController extends Controller
{
    public const MIN_RATING = 4;
    public const LIMIT = 6;

    public static function token(int $websiteId): string
    {
        $key = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? (string) ENCRYPTION_KEY : '';
        return $key === '' || $websiteId <= 0 ? '' : 'rw_' . substr(hash_hmac('sha256', 'reviews-widget:' . $websiteId, $key), 0, 20);
    }

    public static function snippet(int $websiteId): string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        return '<div id="tourfecto-reviews"></div>' . "\n"
            . '<script async src="' . $base . '/widget/reviews?site=' . $websiteId . '&t=' . self::token($websiteId) . '"></script>';
    }

    /** اسم المراجع الأول + أول حرف من الاسم التاني (خصوصية العميل اللي كتب التقييم) */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $parts = array_values(array_filter($parts, 'strlen'));
        if (!$parts) {
            return '';
        }
        return $parts[0] . (isset($parts[1]) ? ' ' . mb_substr($parts[1], 0, 1) . '.' : '');
    }

    /** GET /widget/reviews */
    public function script(array $params = []): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');

        $siteId = (int) ($_GET['site'] ?? 0);
        $token = (string) ($_GET['t'] ?? '');
        $expected = self::token($siteId);
        if ($expected === '' || !hash_equals($expected, $token)) {
            header('Cache-Control: no-store');
            echo "// Tourfecto reviews: invalid widget code\n";
            exit;
        }
        header('Cache-Control: public, max-age=900');

        $data = ['avg' => 0, 'count' => 0, 'reviews' => [], 'lang' => 'en'];
        try {
            $agg = $this->db->query('SELECT COUNT(*) AS c, AVG(rating) AS a FROM reviews WHERE website_id = ? AND rating > 0', [$siteId])[0] ?? [];
            $data['count'] = (int) ($agg['c'] ?? 0);
            $data['avg'] = round((float) ($agg['a'] ?? 0), 1);
            $rows = $this->db->query(
                'SELECT reviewer_name, review_text, rating, source_platform, COALESCE(review_date, created_at) AS d FROM reviews
                 WHERE website_id = ? AND rating >= ? AND CHAR_LENGTH(TRIM(review_text)) >= 15
                 ORDER BY COALESCE(review_date, created_at) DESC LIMIT ' . self::LIMIT,
                [$siteId, self::MIN_RATING]
            );
            foreach ($rows as $r) {
                $data['reviews'][] = [
                    'n' => self::shortName($r['reviewer_name'] ?? ''),
                    't' => mb_substr(trim((string) $r['review_text']), 0, 280),
                    'r' => (int) round((float) $r['rating']),
                    'p' => ['google_business' => 'Google', 'tripadvisor' => 'TripAdvisor', 'booking' => 'Booking.com'][$r['source_platform']] ?? '',
                    'd' => substr((string) $r['d'], 0, 10),
                ];
            }
            $lang = $this->db->query('SELECT target_language FROM websites WHERE id = ? LIMIT 1', [$siteId])[0]['target_language'] ?? 'en';
            $data['lang'] = str_starts_with((string) $lang, 'ar') ? 'ar' : 'en';
        } catch (Throwable $e) {
            // لو الاستعلام فشل: الويدجت يطلع فاضي بدل ما يكسر صفحة العميل
        }

        echo self::renderJs($data);
        exit;
    }

    public static function renderJs(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return <<<JS
(function () {
  var D = {$json};
  if (!D.count) return;
  var host = document.getElementById('tourfecto-reviews');
  if (!host) {
    var s = document.currentScript;
    host = document.createElement('div');
    if (s && s.parentNode) s.parentNode.insertBefore(host, s); else document.body.appendChild(host);
  }
  if (host.getAttribute('data-tf-done')) return;
  host.setAttribute('data-tf-done', '1');
  var ar = (host.getAttribute('data-lang') || D.lang) === 'ar';
  var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
  var css = ':host{all:initial}.w{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;color:#1f2937;max-width:1100px;margin:0 auto;padding:8px}'
    + '.h{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap}.avg{font-size:28px;font-weight:700}.st{color:#f59e0b;letter-spacing:1px}.cnt{color:#6b7280;font-size:14px}'
    + '.g{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}'
    + '.c{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.04)}'
    + '.t{font-size:14px;line-height:1.6;margin:8px 0;white-space:pre-line}.m{font-size:12px;color:#6b7280;display:flex;justify-content:space-between;gap:8px}'
    + '.f{margin-top:8px;font-size:11px;color:#9ca3af;text-align:end}';
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function stars(n) { n = Math.max(0, Math.min(5, Math.round(n))); return '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n); }
  var style = el('style', null, css);
  var w = el('div', 'w'); w.dir = ar ? 'rtl' : 'ltr';
  var h = el('div', 'h');
  h.appendChild(el('span', 'avg', String(D.avg)));
  h.appendChild(el('span', 'st', stars(D.avg)));
  h.appendChild(el('span', 'cnt', ar ? ('من ' + D.count + ' تقييم') : ('from ' + D.count + ' reviews')));
  w.appendChild(h);
  var g = el('div', 'g');
  D.reviews.forEach(function (r) {
    var c = el('div', 'c');
    c.appendChild(el('div', 'st', stars(r.r)));
    var tx = el('div', 't', r.t); tx.dir = 'auto'; c.appendChild(tx);
    var m = el('div', 'm');
    var nm = el('span', null, r.n); nm.dir = 'auto'; m.appendChild(nm);
    m.appendChild(el('span', null, (r.p ? r.p + ' · ' : '') + r.d));
    c.appendChild(m);
    g.appendChild(c);
  });
  w.appendChild(g);
  w.appendChild(el('div', 'f', 'Tourfecto'));
  root.appendChild(style);
  root.appendChild(w);
})();

JS;
    }

    /** GET /api/reputation/widget-code — كود التضمين لكل مواقع العميل */
    public function code(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (self::token(1) === '') {
            return $this->error('الويدجت مش متاح دلوقتي - كلم الدعم', 503);
        }
        $sites = $this->db->query('SELECT id, company_name, main_url FROM websites WHERE user_id = ? ORDER BY id LIMIT 50', [(int) $this->user['id']]);
        $out = [];
        foreach ($sites as $s) {
            $out[] = ['id' => (int) $s['id'], 'name' => (string) ($s['company_name'] ?: $s['main_url']), 'snippet' => self::snippet((int) $s['id'])];
        }
        return $this->success(['websites' => $out]);
    }
}
