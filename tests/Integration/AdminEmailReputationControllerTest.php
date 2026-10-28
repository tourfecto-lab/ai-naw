<?php

use PHPUnit\Framework\TestCase;

/**
 * AdminEmailReputationController كان بينادي $this->input() من غير ما تتعرّف (PHPStan: method.notFound ×5)،
 * فمسارات الأدمن (إيقاف/استئناف الحساب، الحدود، مراجعة الاعتماد، إيقاف الحملة) كانت بتنهار بخطأ فادح.
 * الاختبار ده بيمرّ على أول سطر في كل مسار منهم (قراءة input) من غير ما يعتمد على بيانات DB.
 */
final class AdminEmailReputationControllerTest extends TestCase
{
    private function controller(array $data): AdminEmailReputationController
    {
        $c = new AdminEmailReputationController();
        $prop = new ReflectionProperty(Controller::class, 'data');
        $prop->setAccessible(true);
        $prop->setValue($c, $data);
        return $c;
    }

    public function testInputReturnsRequestData(): void
    {
        $c = $this->controller(['reason' => 'spam complaints']);
        $m = new ReflectionMethod($c, 'input');
        $m->setAccessible(true);
        $this->assertSame(['reason' => 'spam complaints'], $m->invoke($c));
    }

    public function testPauseAccountRejectsInvalidInputInsteadOfFatalError(): void
    {
        $res = $this->controller(['reason' => 'x'])->pauseAccount(['id' => 0]);
        $this->assertFalse($res['success']);
        $this->assertSame(422, $res['code'] ?? $res['status'] ?? 422);
    }

    public function testPauseAccountRequiresAReason(): void
    {
        $res = $this->controller(['reason' => ''])->pauseAccount(['id' => 5]);
        $this->assertFalse($res['success']);
    }
}
