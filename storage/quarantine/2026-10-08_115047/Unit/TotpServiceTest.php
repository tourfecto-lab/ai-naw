<?php

/**
 * Tourfecto - TOTP Service Test
 * اختبار حقيقي لخوارزمية TOTP (app/Services/TotpService.php) باستخدام
 * قيم الاختبار الرسمية المنشورة في RFC 6238 Appendix B - مش بيانات
 * مُختلقة. الهدف إثبات إن التنفيذ الذاتي لـ HOTP/Base32 (من غير أي
 * مكتبة خارجية) متوافق فعليًا مع المعيار ومع أي تطبيق Authenticator
 * حقيقي (Google Authenticator / Authy / 1Password).
 *
 * ملاحظة: RFC 6238 Appendix B ينشر القيم لـ 8 أرقام (SHA1) - الخدمة
 * بتولّد 6 أرقام (القياس الافتراضي لكل تطبيقات الـAuthenticator)،
 * وده مش تغيير في الخوارزمية: قيمة الـ 6 أرقام هي آخر 6 أرقام من
 * قيمة الـ 8 أرقام بالظبط (لأنها `truncated % 10^6`).
 *
 * @version 1.0.1
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/TotpService.php';

final class TotpServiceTest extends TestCase
{
    /**
     * سر الاختبار الرسمي من RFC 6238 Appendix B: النص "12345678901234567890"
     * (20 بايت ASCII)، بترميز Base32:
     * GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
     */
    private const RFC_TEST_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * قيم اختبار SHA1 الرسمية من RFC 6238 Appendix B، محوّلة لقيمة الـ
     * 6 أرقام (آخر 6 أرقام من قيمة الـ 8 أرقام المنشورة في المعيار).
     * كل قيمة موثّقة من المعيار نفسه:
     *   T=59          → 8-digit: 94287082  → 6-digit: 287082
     *   T=1111111109  → 8-digit: 07081804  → 6-digit: 081804
     *   T=1111111111  → 8-digit: 14050471  → 6-digit: 050471
     *   T=1234567890  → 8-digit: 89005924  → 6-digit: 005924
     *   T=2000000000  → 8-digit: 69279037  → 6-digit: 279037
     */
    public static function rfc6238TestVectors(): array
    {
        return [
            'T=59 (1970-01-01 00:00:59 UTC)' => [59, '287082'],
            'T=1111111109 (2005-03-18 01:58:29 UTC)' => [1111111109, '081804'],
            'T=1111111111 (2005-03-18 01:58:31 UTC)' => [1111111111, '050471'],
            'T=1234567890 (2009-02-13 23:31:30 UTC)' => [1234567890, '005924'],
            'T=2000000000 (2033-05-18 03:33:20 UTC)' => [2000000000, '279037'],
        ];
    }

    /**
     * @dataProvider rfc6238TestVectors
     */
    public function testGetCodeMatchesOfficialRfc6238Vectors(int $timestamp, string $expectedCode): void
    {
        $actual = TotpService::getCode(self::RFC_TEST_SECRET_BASE32, $timestamp);
        $this->assertSame(
            $expectedCode,
            $actual,
            "TOTP code at T={$timestamp} should match the RFC 6238 Appendix B test vector (6-digit)."
        );
    }

    public function testVerifyAcceptsAFreshlyGeneratedCodeForNow(): void
    {
        $secret = TotpService::generateSecret();
        $currentCode = TotpService::getCode($secret);

        $this->assertTrue(TotpService::verify($secret, $currentCode));
    }

    public function testVerifyRejectsAnObviouslyWrongCode(): void
    {
        $secret = TotpService::generateSecret();

        $this->assertFalse(TotpService::verify($secret, '000000'));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $secret = TotpService::generateSecret();

        $this->assertFalse(TotpService::verify($secret, 'abcdef'));
        $this->assertFalse(TotpService::verify($secret, '12345')); // قصير جدًا
        $this->assertFalse(TotpService::verify($secret, '1234567')); // طويل جدًا
        $this->assertFalse(TotpService::verify($secret, ''));
    }

    public function testVerifyToleratesOneStepOfClockDrift(): void
    {
        $secret = TotpService::generateSecret();
        // كود من 30 ثانية في الماضي - المفروض لسه مقبول (نافذة ±1 خطوة)
        $codeFromPreviousStep = TotpService::getCode($secret, time() - 30);

        $this->assertTrue(TotpService::verify($secret, $codeFromPreviousStep));
    }

    public function testVerifyRejectsCodeOutsideTheToleranceWindow(): void
    {
        $secret = TotpService::generateSecret();
        // كود من 5 دقايق في الماضي - المفروض يترفض
        $oldCode = TotpService::getCode($secret, time() - 300);

        $this->assertFalse(TotpService::verify($secret, $oldCode));
    }

    public function testGenerateSecretProducesValidBase32OfExpectedLength(): void
    {
        $secret = TotpService::generateSecret();

        // 20 بايت (160-bit) بترميز Base32 بمعدل 8 حروف لكل 5 بايت = 32 حرف
        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testProvisioningUriContainsExpectedComponents(): void
    {
        $secret = self::RFC_TEST_SECRET_BASE32;
        $uri = TotpService::provisioningUri($secret, 'user@example.com', 'Tourfecto');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Tourfecto', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function testRecoveryCodesAreGeneratedInExpectedFormatAndAreUnique(): void
    {
        $codes = TotpService::generateRecoveryCodes(10);

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes), 'Recovery codes must all be unique.');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        }
    }

    public function testRecoveryCodeHashingAndVerificationRoundTrip(): void
    {
        $rawCodes = TotpService::generateRecoveryCodes(5);
        $hashed = TotpService::hashRecoveryCodes($rawCodes);

        // كل كود المفروض يتطابق مع الـ hash بتاعه
        foreach ($rawCodes as $i => $raw) {
            $foundIndex = TotpService::verifyRecoveryCode($hashed, $raw);
            $this->assertSame($i, $foundIndex);
        }

        // كود عشوائي غير موجود المفروض يترفض
        $this->assertNull(TotpService::verifyRecoveryCode($hashed, 'ZZZZ-ZZZZ'));
    }

    public function testRawRecoveryCodesAreNeverStoredInTheHashedOutput(): void
    {
        $rawCodes = TotpService::generateRecoveryCodes(3);
        $hashed = TotpService::hashRecoveryCodes($rawCodes);

        foreach ($rawCodes as $raw) {
            $this->assertNotContains($raw, $hashed, 'Raw recovery code must never appear in the hashed storage array.');
        }
    }
}
