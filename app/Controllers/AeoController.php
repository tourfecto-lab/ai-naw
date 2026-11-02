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
        return $this->success(AeoAnswerBuilder::draft($page));
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
