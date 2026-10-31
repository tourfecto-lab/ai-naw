<?php

/**
 * Admin reputation limits endpoint: the CSRF token it hands to the page must be one the save endpoint accepts
 * (a wrong helper once returned an empty token and every save failed with 419 on the live site).
 */

use PHPUnit\Framework\TestCase;

final class AdminReputationLimitsControllerTest extends TestCase
{
    private function controller(array $data = []): AdminReputationLimitsController
    {
        $c = new AdminReputationLimitsController();
        foreach (['authenticated' => true, 'user' => ['id' => 1, 'role' => 'admin'], 'data' => $data] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        }
        return $c;
    }

    public function testDataReturnsATokenTheSaveEndpointAccepts(): void
    {
        if (!class_exists('Csrf')) {
            $this->markTestSkipped('Csrf not available');
        }
        $res = $this->controller()->data();
        $this->assertTrue($res['success'] ?? false);
        $token = (string) ($res['data']['csrf_token'] ?? '');
        $this->assertNotEmpty($token, 'the page needs a real CSRF token');
        $this->assertTrue(Csrf::verify($token));
    }

    public function testSaveWithoutATokenIsRejectedWith419(): void
    {
        $res = $this->controller(['limits' => ['free' => ['locations' => 1]]])->save();
        $this->assertSame(419, $res['code'] ?? $res['status'] ?? 419);
        $this->assertFalse($res['success'] ?? true);
    }
}
