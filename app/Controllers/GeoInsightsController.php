<?php
/** تتبع GEO: تطور، منافسين، مصادر، ونتيجة كل سؤال مع توصية. قراءة فقط من الإجابات المخزّنة، بلا أي استدعاء AI. */
final class GeoInsightsController extends Controller
{
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $wid = (int) $this->get('website_id');
        if ($wid <= 0) return $this->error('website_id مطلوب', 422);
        try {
            return $this->success((new GeoInsightsService($this->db))->overview((int) $this->user['id'], $wid, (int) $this->get('market_id')));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('GEO insights unavailable', ['website_id' => $wid, 'user_id' => (int) $this->user['id'], 'exception' => get_class($e), 'error' => $e->getMessage()]);
            return $this->error('تعذر تحميل تتبع GEO دلوقتي. جرّب مرة تانية.', 503);
        }
    }

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $ar = current_lang() === 'ar';
        ob_start();
        require dirname(__DIR__) . '/Views/GeoInsights/index.php';
        $body = ob_get_clean();
        echo $this->renderPanelPage('ai_visibility', $ar ? 'تتبع الأسئلة والمنافسين (GEO)' : 'Prompt and competitor tracking (GEO)',
            $ar ? 'تطور ظهورك في إجابات الذكاء الاصطناعي، ومين بيظهر معاك، وتوصية لكل سؤال' : 'How your AI answer visibility is trending, who appears alongside you, and a recommendation per question', $body);
        exit;
    }
}
