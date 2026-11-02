<?php
/** لوحة الظهور الموحدة (SEO/AEO/GEO): قراءة فقط، مقيّدة بمالك الموقع. */
final class VisibilityController extends Controller
{
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $ar = current_lang() === 'ar';
        ob_start();
        require dirname(__DIR__) . '/Views/Visibility/index.php';
        $body = ob_get_clean();
        echo $this->renderPanelPage('seo_expert', $ar ? 'ظهورك في البحث والإجابات' : 'Search and answer visibility',
            $ar ? 'SEO وAEO وGEO في مكان واحد' : 'SEO, AEO and GEO in one place', $body);
        exit;
    }

    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $wid = (int) $this->get('website_id');
        if ($wid <= 0) return $this->error('website_id مطلوب', 422);
        try {
            return $this->success((new VisibilityScoreService($this->db))->overview((int) $this->user['id'], $wid));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        } catch (Throwable $e) {
            Logger::error('Visibility overview unavailable', ['website_id' => $wid, 'user_id' => (int) $this->user['id'], 'exception' => get_class($e), 'error' => $e->getMessage()]);
            return $this->error('تعذر تحميل لوحة الظهور دلوقتي. جرّب مرة تانية.', 503);
        }
    }
}
