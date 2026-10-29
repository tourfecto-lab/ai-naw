<?php

/** TOURFECTO AI CEO (المرحلة 4): البوابة القانونية قبل أول رسالة تسويق، والعميل المثالي */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/SalesCompliance.php';

final class SalesComplianceTest extends TestCase
{
    private const ALL = ['us' => true, 'eu' => true, 'gcc' => true, 'other' => true];

    private function ev(string $country, string $email, array $basis = self::ALL, array $consent = [], bool $address = true, string $status = 'new'): array
    {
        return SalesCompliance::evaluate(['country' => $country, 'email' => $email, 'status' => $status], $basis, $consent, $address);
    }

    public function testRegionsAndRoleEmails(): void
    {
        $this->assertSame('us', SalesCompliance::region('us'));
        $this->assertSame('eu', SalesCompliance::region('GB'));
        $this->assertSame('gcc', SalesCompliance::region('AE'));
        $this->assertSame('ca', SalesCompliance::region('CA'));
        $this->assertSame('other', SalesCompliance::region('EG'));
        $this->assertSame(true, SalesCompliance::isRoleEmail('Info@nile.com'));
        $this->assertSame(true, SalesCompliance::isRoleEmail('reservations@x.com'));
        $this->assertSame(false, SalesCompliance::isRoleEmail('ahmed.ali@x.com'));
    }

    public function testConservativeGate(): void
    {
        $this->assertSame(true, $this->ev('US', 'john@tours.com')['ok']);                 // CAN-SPAM: opt-out
        $this->assertSame('can_spam_opt_out', $this->ev('US', 'john@tours.com')['basis']);
        $this->assertSame(true, $this->ev('FR', 'info@tours.fr')['ok']);                  // مصلحة مشروعة + إيميل عام
        $this->assertSame(['personal_email_needs_opt_in'], $this->ev('FR', 'marie@tours.fr')['reasons']);
        $this->assertSame(['country_needs_opt_in'], $this->ev('DE', 'info@reisen.de')['reasons']);
        $this->assertSame(['casl_needs_opt_in'], $this->ev('CA', 'info@tours.ca')['reasons']);
        $this->assertSame(['region_basis_not_confirmed'], $this->ev('AE', 'info@tours.ae', ['us' => true])['reasons']);
        $this->assertSame(['no_postal_address'], $this->ev('US', 'info@tours.com', self::ALL, [], false)['reasons']);
        $this->assertContains('objected', $this->ev('US', 'info@tours.com', self::ALL, [['event' => 'objection', 'channel' => 'all']])['reasons']);
        $this->assertContains('status_unsubscribed', $this->ev('US', 'info@tours.com', self::ALL, [], true, 'unsubscribed')['reasons']);
    }

    public function testRecordedOptInPassesEvenStrictCountries(): void
    {
        $optIn = [['event' => 'opt_in', 'channel' => 'email']];
        $this->assertSame(true, $this->ev('DE', 'hans@reisen.de', [], $optIn)['ok']);
        $this->assertSame('consent', $this->ev('CA', 'bob@tours.ca', [], $optIn)['basis']);
        // موافقة على واتساب بس مش موافقة على الإيميل
        $this->assertSame(false, $this->ev('DE', 'hans@reisen.de', [], [['event' => 'opt_in', 'channel' => 'whatsapp']])['ok']);
    }

    public function testIdealCustomerFit(): void
    {
        $fit = SalesCompliance::icpFit(['country' => 'US', 'category' => 'Tour agency', 'score' => 100, 'website' => 'https://x.com'], ['us', 'eu', 'gcc']);
        $this->assertSame(100, $fit);
        $this->assertSame(0, SalesCompliance::icpFit(['country' => 'EG', 'category' => 'Museum', 'score' => null, 'website' => null], ['us']));
    }
}
