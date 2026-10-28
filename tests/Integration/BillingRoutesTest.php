<?php

/**
 * Tourfecto - Billing Routes Registration Test
 * اختبار PHPUnit لضمان تسجيل كل مسارات الفوترة/الاشتراكات في الراوتر
 * وإن الـ regex بتاعها بيطابق عناوين فعلية - بدون قاعدة بيانات.
 *
 * نفس فلسفة route_registration_test.php (الموجود سابقًا للـ AI Chat)
 * لكن بصيغة PHPUnit TestCase قابلة للدمج في الـ suite.
 *
 * التشغيل: phpunit --bootstrap Unit/offline_bootstrap.php Integration/BillingRoutesTest.php
 * @version 1.0.0
 * @date 2026-08-17
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Router.php';

final class BillingRoutesTest extends TestCase
{
    private const EXPECTED_ROUTES = [
        // الاشتراكات (SubscriptionController)
        ['POST', '/api/subscription/validate', 'SubscriptionController', 'validateSubscriptionStatus'],
        ['GET',  '/api/subscription/current', 'SubscriptionController', 'current'],
        ['POST', '/api/subscription/create', 'SubscriptionController', 'create'],
        ['POST', '/api/subscription/renew', 'SubscriptionController', 'renew'],
        ['POST', '/api/subscription/cancel', 'SubscriptionController', 'cancel'],
        ['GET',  '/api/subscription/plans', 'SubscriptionController', 'getPlans'],
        ['POST', '/api/subscription/upgrade', 'SubscriptionController', 'upgrade'],
        ['GET',  '/api/subscription/invoices', 'SubscriptionController', 'getInvoices'],
        ['GET',  '/api/subscription/invoice/42', 'SubscriptionController', 'getInvoice'],
        ['POST', '/api/subscription/payment', 'SubscriptionController', 'processPayment'],
        ['GET',  '/api/subscription/billing-profile', 'SubscriptionController', 'getBillingProfile'],
        ['PUT',  '/api/subscription/billing-profile', 'SubscriptionController', 'updateBillingProfile'],
        // المحفظة (WalletController)
        ['GET',  '/api/wallet/balance', 'WalletController', 'getBalance'],
        ['POST', '/api/wallet/redeem-card', 'WalletController', 'redeemCard'],
        ['GET',  '/api/wallet/history', 'WalletController', 'getHistory'],
        ['POST', '/api/wallet/deposit', 'WalletController', 'requestDeposit'],
        ['POST', '/api/wallet/subscribe', 'WalletController', 'subscribeWithBalance'],
    ];

    private array $routes = [];

    protected function setUp(): void
    {
        $router = new Router();
        include __DIR__ . '/../../app/routes/api.php';
        $ref = new ReflectionProperty(Router::class, 'routes');
        $ref->setAccessible(true);
        $this->routes = $ref->getValue($router);
    }

    public function testAllExpectedBillingRoutesRegistered(): void
    {
        foreach (self::EXPECTED_ROUTES as [$method, $url, $controller, $action]) {
            $this->assertRoute($method, $url, $controller, $action);
        }
    }

    public function testInvoiceDynamicRouteHasUniqueTarget(): void
    {
        // /api/subscription/invoice/{id} لازم يطابق getInvoice - ومش
        // أي مسار تاني جوه نفس الميثود يلتقط الـ id.
        $this->assertRoute('GET', '/api/subscription/invoice/99', 'SubscriptionController', 'getInvoice');
    }

    public function testBillingRoutesDoNotLeakToUnrelatedPaths(): void
    {
        // مسار لا ينتمي للفوترة لازم مايطابقش مسارات الفوترة.
        foreach (self::EXPECTED_ROUTES as [$method, $url, $controller, $action]) {
            $unrelated = '/api/unrelated/' . uniqid();
            $this->assertDoesNotMatchAny($method, $unrelated);
        }
        $this->addToAssertionCount(1);
    }

    private function assertRoute(string $method, string $url, string $controller, string $action): void
    {
        if (!isset($this->routes[$method])) {
            $this->fail("no routes registered for method {$method}");
        }
        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $url)) {
                $this->assertSame($controller, $route['controller'], "{$method} {$url} controller");
                $this->assertSame($action, $route['action'], "{$method} {$url} action");
                return;
            }
        }
        $this->fail("{$method} {$url} -> expected {$controller}::{$action}, no route matched");
    }

    private function assertDoesNotMatchAny(string $method, string $url): void
    {
        if (!isset($this->routes[$method])) {
            return;
        }
        foreach ($this->routes[$method] as $route) {
            // preg_match يرجع 0 (int) عند عدم التطابق - assertFalse لا
            // يصلح هنا لأنه مقارنة strict (0 !== false). نتحقق من القيمة 0.
            $this->assertSame(
                0,
                preg_match($route['pattern'], $url),
                "{$method} {$url} unexpectedly matched {$route['controller']}::{$route['action']}"
            );
        }
    }
}
