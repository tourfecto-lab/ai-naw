<?php

/** TOURFECTO AI CEO (المرحلة 2): الاستهلاك غير الطبيعي ومراجعة توزيع النماذج */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/AiCostIntelligence.php';

final class AiCostIntelligenceTest extends TestCase
{
    public function testAnomalyNeedsThreeTimesTheUsualDayAndAMeaningfulAmount(): void
    {
        $features = [
            ['feature' => 'seo_article', 'today' => 6.0, 'avg_day' => 1.0],   // 6× وفوق نص دولار
            ['feature' => 'chat_reply', 'today' => 0.4, 'avg_day' => 0.01],   // مبلغ صغير
            ['feature' => 'review_reply', 'today' => 2.0, 'avg_day' => 1.0], // ضعفين بس
        ];
        $a = AiCostIntelligence::anomalies($features, []);
        $this->assertCount(1, $a);
        $this->assertSame('seo_article', $a[0]['name']);
    }

    public function testRoutingReviewFlagsAFarCostlierModelOnTheSameTask(): void
    {
        $rows = [
            ['feature' => 'seo_article', 'model' => 'big-model', 'requests' => 20, 'cost_per_request' => 0.05],
            ['feature' => 'seo_article', 'model' => 'small-model', 'requests' => 40, 'cost_per_request' => 0.005],
            ['feature' => 'chat_reply', 'model' => 'a', 'requests' => 10, 'cost_per_request' => 0.002],
            ['feature' => 'chat_reply', 'model' => 'b', 'requests' => 10, 'cost_per_request' => 0.003],
        ];
        $r = AiCostIntelligence::routingReview($rows);
        $this->assertCount(1, $r);
        $this->assertSame('big-model', $r[0]['expensive_model']);
        $this->assertSame('small-model', $r[0]['cheap_model']);
    }
}
