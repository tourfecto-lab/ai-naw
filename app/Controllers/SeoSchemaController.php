<?php
/** تحقق ومعاينة JSON-LD (SEO Schema): بلا كتابة في قاعدة البيانات وبلا نشر. */
final class SeoSchemaController extends Controller
{
    private const PREVIEWS_PER_HOUR = 20;

    /** POST /api/seo/schema/validate: يتحقق من JSON-LD مكتوب (بلا أي طلب شبكة). */
    public function check(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        $text = (string) $this->get('jsonld', '');
        if (trim($text) === '') return $this->error('jsonld مطلوب', 422);
        return $this->success(SeoSchemaValidator::validate($text));
    }

    /** POST /api/seo/schema/preview: يقرأ صفحة من موقع المستخدم نفسه ويبني الـSchema المقترح ويتحقق منه. لا نشر. */
    public function preview(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        $uid = (int) $this->user['id'];
        $wid = (int) $this->get('website_id');
        $url = trim((string) $this->get('url', ''));
        $rows = $wid > 0 ? $this->db->query('SELECT main_url, company_name, brand_name FROM websites WHERE id = ? AND user_id = ?', [$wid, $uid]) : [];
        if (!$rows) return $this->error('الموقع مش موجود', 422);
        $site = parse_url((string) $rows[0]['main_url']);
        $page = parse_url($url);
        if (!SeoSameSiteUrl::allowed((string) $rows[0]['main_url'], $url)) {
            return $this->error('الرابط لازم يكون https ومن نفس موقعك', 422);
        }
        if (!SeoSameSiteUrl::throttle('schema_preview', self::PREVIEWS_PER_HOUR)) return $this->error('وصلت للحد الأقصى للمعاينات في الساعة. جرّب بعد شوية.', 429);
        try {
            $fetched = (new SeoExpertPageContext())->fresh($url);
        } catch (Throwable $e) {
            Logger::error('Schema preview fetch failed', ['website_id' => $wid, 'exception' => get_class($e)]);
            return $this->error('تعذر قراءة الصفحة دلوقتي.', 503);
        }
        if (empty($fetched['ok'])) return $this->error('الصفحة مش متاحة للقراءة (تأكد إنها عامة وبترد 200).', 422);
        $fetched['page_path'] = (string) ($page['path'] ?? '/');
        $business = (string) ($rows[0]['brand_name'] ?: $rows[0]['company_name']);
        $built = SeoSchemaBuilder::forPage($fetched, ['site_url' => ($site['scheme'] ?? 'https') . '://' . ($site['host'] ?? ''), 'business_name' => $business]);
        return $this->success([
            'url' => $url,
            'existing_types' => $this->typesOf((array) ($fetched['schema'] ?? [])),
            'existing_validation' => array_map(static fn ($d) => SeoSchemaValidator::validate($d), array_slice(array_values((array) ($fetched['schema'] ?? [])), 0, 10)),
            'existing_invalid_blocks' => (int) ($fetched['invalid_schema'] ?? 0),
            'proposed' => $built ? ['types' => $built['types'], 'jsonld' => $built['graph'], 'validation' => SeoSchemaValidator::validate($built['graph'])] : null,
            'note' => 'معاينة فقط: مفيش نشر. أي إضافة بتتطبق من خلال دورة SEO Expert بموافقتك.',
        ]);
    }

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $ar = current_lang() === 'ar';
        ob_start();
        require dirname(__DIR__) . '/Views/SeoSchema/index.php';
        $body = ob_get_clean();
        echo $this->renderPanelPage('seo_expert', $ar ? 'فحص ومعاينة Schema' : 'Schema check and preview',
            $ar ? 'تحقق من JSON-LD وشوف المقترح لصفحاتك قبل أي نشر' : 'Validate JSON-LD and preview the proposal for your pages before any publishing', $body);
        exit;
    }

    /** @param array<int,mixed> $docs */
    private function typesOf(array $docs): array
    {
        $types = [];
        $walk = static function ($n) use (&$walk, &$types): void {
            if (!is_array($n)) return;
            foreach ((array) ($n['@type'] ?? []) as $t) if (is_string($t)) $types[$t] = true;
            foreach ((array) ($n['@graph'] ?? []) as $c) $walk($c);
            if (array_is_list($n)) foreach ($n as $c) $walk($c);
        };
        foreach ($docs as $d) $walk($d);
        return array_keys($types);
    }
}
