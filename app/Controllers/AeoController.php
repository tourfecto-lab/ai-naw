<?php
/** مولّد إجابات AEO: مسودة من حقائق الصفحة، ثم FAQPage بعد مراجعة المستخدم. لا نشر ولا كتابة في القاعدة ولا تكلفة AI. */
final class AeoController extends Controller
{
    private const DRAFTS_PER_HOUR = 20;

    /** POST /api/aeo/draft {website_id,url} */
    public function draft(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        if ($err = $this->rateLimitGuard('user', 'aeo_draft', self::DRAFTS_PER_HOUR, 3600)) return $err;
        $wid = (int) $this->get('website_id');
        $url = trim((string) $this->get('url', ''));
        $rows = $wid > 0 ? $this->db->query('SELECT main_url FROM websites WHERE id = ? AND user_id = ?', [$wid, (int) $this->user['id']]) : [];
        if (!$rows) return $this->error('الموقع مش موجود', 422);
        if (!SeoSameSiteUrl::allowed((string) $rows[0]['main_url'], $url)) return $this->error('الرابط لازم يكون https ومن نفس موقعك', 422);
        if (!SeoSameSiteUrl::throttle('aeo_draft', self::DRAFTS_PER_HOUR)) return $this->error('وصلت للحد الأقصى في الساعة. جرّب بعد شوية.', 429);
        try {
            $page = (new SeoExpertPageContext())->fresh($url);
        } catch (Throwable $e) {
            Logger::error('AEO draft fetch failed', ['website_id' => $wid, 'exception' => get_class($e)]);
            return $this->error('تعذر قراءة الصفحة دلوقتي.', 503);
        }
        if (empty($page['ok'])) return $this->error('الصفحة مش متاحة للقراءة (تأكد إنها عامة وبترد 200).', 422);
        $draft = AeoAnswerBuilder::draft($page);
        AeoCoverageStore::record($this->db, (int) $this->user['id'], $wid, $url, $draft['readiness'], count($draft['pairs']));
        return $this->success($draft);
    }

    /** POST /api/aeo/scan {website_id}: يفحص دفعة صغيرة من صفحات الرحلات (من آخر دورة SEO Expert) ويخزّن تغطية FAQ. قراءة عامة فقط. */
    public function scan(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        if ($err = $this->rateLimitGuard('user', 'aeo_scan', 6, 3600)) return $err;
        $uid = (int) $this->user['id'];
        $wid = (int) $this->get('website_id');
        $rows = $wid > 0 ? $this->db->query('SELECT main_url FROM websites WHERE id = ? AND user_id = ?', [$wid, $uid]) : [];
        if (!$rows) return $this->error('الموقع مش موجود', 422);
        if (AeoCoverageStore::summary($this->db, $uid, $wid) === null) return $this->error('ميزة تتبع التغطية محتاجة تفعيل جدول جديد من الأدمن (migration). المسودة والفحص الفردي شغالين.', 409);
        if (!SeoSameSiteUrl::throttle('aeo_scan', 6)) return $this->error('وصلت للحد الأقصى في الساعة. جرّب بعد شوية.', 429);
        try {
            $run = $this->db->query("SELECT snapshot_json FROM seo_expert_runs WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1", [$wid, $uid]);
        } catch (Throwable $e) {
            $run = [];
        }
        $snap = $run ? json_decode((string) $run[0]['snapshot_json'], true) : null;
        $urls = [];
        foreach ((array) ($snap['pages'] ?? []) as $p) {
            $u = (string) ($p['url'] ?? '');
            if ($u !== '' && SeoSameSiteUrl::allowed((string) $rows[0]['main_url'], $u) && SeoSchemaBuilder::isTourPath((string) parse_url($u, PHP_URL_PATH))) $urls[$u] = true;
        }
        if (!$urls) return $this->error('مفيش صفحات رحلات في آخر دورة SEO Expert. شغّل دورة الأول.', 422);
        $batch = AeoCoverageStore::nextUrls($this->db, $uid, $wid, array_keys($urls), 8);
        $reader = new SeoExpertPageContext();
        $deadline = microtime(true) + 40;
        $done = 0;
        $skipped = 0;
        foreach ($batch as $u) {
            if (microtime(true) > $deadline) break;
            try {
                $page = $reader->fresh($u);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($page['ok'])) continue;
            if (!AeoAnswerBuilder::isTripPage($page)) {
                AeoCoverageStore::recordNotATrip($this->db, $uid, $wid, $u);
                $skipped++;
                continue;
            }
            AeoCoverageStore::record($this->db, $uid, $wid, $u, AeoAnswerBuilder::readiness($page), count(AeoAnswerBuilder::draft($page)['pairs']));
            $done++;
        }
        $summary = AeoCoverageStore::summary($this->db, $uid, $wid);
        return $this->success(['scanned_now' => $done, 'skipped_not_trip' => $skipped, 'candidate_pages' => count($urls), 'summary' => $summary, 'measurement' => AeoCoverageStore::measurement($summary, gmdate('Y-m-d H:i:s'))]);
    }

    /** POST /api/aeo/build {pairs:[{question,answer}]} */
    public function build(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($err = $this->verifyCsrf()) return $err;
        $pairs = $this->get('pairs', []);
        if (!is_array($pairs) || !$pairs) return $this->error('اختار سؤال واحد على الأقل', 422);
        $out = AeoAnswerBuilder::build($pairs);
        if ($out['count'] === 0) return $this->error('كل الأسئلة اترفضت (فاضية أو بدون دليل أو فيها روابط أو أرقام مش موجودة في الدليل).', 422, ['rejected' => $out['rejected']]);
        return $this->success($out);
    }

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $ar = current_lang() === 'ar';
        ob_start();
        require dirname(__DIR__) . '/Views/Aeo/index.php';
        $body = ob_get_clean();
        echo $this->renderPanelPage('seo_expert', $ar ? 'مولّد الإجابات (AEO)' : 'Answer generator (AEO)',
            $ar ? 'أسئلة وإجابات من حقائق صفحتك الفعلية، بعد مراجعتك' : 'Questions and answers from your page\'s real facts, after your review', $body);
        exit;
    }
}
