<?php

use PHPUnit\Framework\TestCase;

/**
 * TrialFollowUpService (قائمة تجارب العملاء للأدمن) بتنادي TrialPlanService::adminProgress() وبتعرض
 * done/total/next/value في admin-insights.js. الدالة كانت ناقصة والاستثناء بيتبلع، فالعمود كان فاضي دايمًا.
 */
final class TrialPlanAdminProgressTest extends TestCase
{
    public function testAdminProgressReturnsTheShapeTheAdminUiExpects(): void
    {
        $service = new TrialPlanService();
        $p = $service->adminProgress(999777, date('Y-m-d H:i:s', strtotime('+2 days')));

        $this->assertSame(['done', 'total', 'next', 'value', 'day', 'days'], array_keys($p));
        $this->assertGreaterThan(0, $p['total'], 'خطة التجربة فيها مهام');
        $this->assertSame(0, $p['done'], 'مستخدم من غير أي نشاط = ولا مهمة اتعملت');
        $this->assertNotSame('', $p['next'], 'المهمة الجاية لازم تتحدد لما مفيش حاجة اتعملت');
        $this->assertIsArray($p['value']);
        $this->assertGreaterThanOrEqual(1, $p['day']);
    }
}
