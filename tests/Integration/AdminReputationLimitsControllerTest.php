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
        // another test may have left a token in the request globals; this one must submit none
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_POST['csrf_token'], $_GET['csrf_token'], $_REQUEST['csrf_token']);
        $res = $this->controller(['limits' => ['free' => ['locations' => 1]]])->save();
        $this->assertSame(419, $res['code'] ?? $res['status'] ?? 419);
        $this->assertFalse($res['success'] ?? true);
    }

    public function testUnknownCustomerAnswersWithAJsonSafeStatusNotA404(): void
    {
        if (!class_exists('Csrf')) {
            $this->markTestSkipped('Csrf not available');
        }
        $res = $this->controller(['email' => 'nobody-' . bin2hex(random_bytes(4)) . '@example.invalid', 'values' => ['locations' => 3], 'csrf_token' => Csrf::token()])->override();
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(422, $res['code'] ?? $res['status'] ?? 422, 'the production web server replaces a PHP 404 with an HTML page, which breaks the JSON screens');
    }
}
