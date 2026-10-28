<?php

/**
 * Tourfecto - SEO Content Generate Job (Phase 24)
 * @version 1.0.0
 *
 * توليد محتوى حملة SEO في الخلفية (مش في HTTP request): بياخد معرف حملة
 * (أو عنصر واحد) وبيولّد المقالات بالذكاء الاصطناعي عبر SeoContentService،
 * عشان التوليد الثقيل (LLM) ميطوّلش رد الـ API.
 *
 * Idempotent: بيولّد بس العناصر اللي حالتها لسه 'queued' - إعادة تشغيل
 * لنفس الحملة مش هتعمل مقالات مكررة.
 */
class SeoContentGenerateJob implements QueueJobInterface
{
    private function loadDependencies(): void
    {
        $deps = [
            '/Services/AI/ArticleGenerator.php',
            '/Services/Seo/SeoContentService.php',
        ];
        foreach ($deps as $rel) {
            $file = APP_PATH . $rel;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    public function handle(array $payload): void
    {
        $this->loadDependencies();

        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $itemId = (int) ($payload['item_id'] ?? 0);

        if ($campaignId <= 0 && $itemId <= 0) {
            throw new InvalidArgumentException('SeoContentGenerateJob: missing campaign_id/item_id');
        }

        $db = Database::getInstance();
        $service = new SeoContentService($db);

        if ($itemId > 0) {
            $res = $service->generateItem($itemId);
            if (empty($res['success'])) {
                throw new RuntimeException('SeoContentGenerateJob item failed: ' . ($res['error'] ?? 'unknown'));
            }
            return;
        }

        $res = $service->generateCampaign($campaignId);
        if (!empty($res['success']) && $res['failed'] > 0 && $res['generated'] === 0) {
            // كل العناصر فشلت - نعتبرها فشل للطابور عشان يعيد المحاولة
            throw new RuntimeException('SeoContentGenerateJob: all items failed');
        }
    }
}
