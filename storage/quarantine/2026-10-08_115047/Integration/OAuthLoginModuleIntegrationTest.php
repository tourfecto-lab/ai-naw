<?php

/**
 * Tourfecto - OAuth Login (Item 2) Integration Test
 * بيفحص تدفقات تسجيل الدخول الاجتماعي (Google/Facebook/Microsoft + Apple)
 * بمصادر بيانات حقيقية في `tourfecto_test` وصفر شبكة (transport وهمي بنفس
 * بنية رد curl - نفس نمط حقن WordPressPublisher من الموديول 3):
 *   1) نجاح: تبادل الكود → توكن، وجلب البروفايل / فك id_token → هوية.
 *   2) توكن غير صالح/منتهي: رفض التبادل (400) أو فشل جلب البروفايل (401) → فشل.
 *   3) فشل اتصال بالمزوّد الخارجي: transport يرجّع خطأ شبكة → فشل نظيف.
 *   4) Replay attack: نفس الكود مستخدم مرتين → أول مرة تنجح والثانية يرفضها
 *      المزوّد (invalid_grant) فنعرض فشل؛ وحالة OAuth (state) أحادية الاستخدام
 *      على مستوى المتحكم - استعمال نفس الـ state مرتين يُرفض.
 *
 * معرّفات معزولة: المستخدم 999961، عناوين 203.0.114.x، إعدادات OAuth بقيّم
 * اختبارية في system_settings (تُمسح بعد كل اختبار).
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Encryption.php';
require_once __DIR__ . '/../../app/Services/System/SystemSettingsService.php';
require_once __DIR__ . '/../../app/Services/OAuth/SocialLoginClient.php';
require_once __DIR__ . '/../../app/Services/OAuth/AppleSignInClient.php';
require_once __DIR__ . '/../../app/Controllers/AuthController.php';

/** transport وهمي بيسجّل الطلبات ويرجّع استجابات مسرّحة بنفس بنية رد curl */
final class OAuthTransportFake
{
    public array $calls = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(array $req): array
    {
        $this->calls[] = $req;
        $idx = min(count($this->calls) - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }
}

final class OAuthLoginModuleIntegrationTest extends TestCase
{
    private const USER = 999961;

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private static array $seededKeys = [];

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([
                    $app . '/Config/app.php',
                    $app . '/Config/database.php',
                    $app . '/Config/encryption.php',
                    $app . '/Config/constants.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }
            foreach (['oauth_accounts', 'system_settings'] as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
                    return null;
                }
            }

            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو جداول OAuth غير موجودة');
        }
        $this->cleanup();
        $this->seedSettings();
        $_SERVER['REMOTE_ADDR'] = '203.0.114.10';
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['user']);
    }

    protected function tearDown(): void
    {
        if (self::$pdo === null) {
            return;
        }
        $this->cleanup();
        unset($_SESSION['oauth_state'], $_SESSION['oauth_provider'], $_SESSION['user'], $_SERVER['REMOTE_ADDR']);
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("DELETE FROM oauth_accounts WHERE user_id = 999961");
        $pdo->exec("DELETE FROM users WHERE id = 999961");
        if (!empty(self::$seededKeys)) {
            $keys = implode(',', array_map(fn ($k) => "'" . $k . "'", self::$seededKeys));
            $pdo->exec("DELETE FROM system_settings WHERE setting_key IN ({$keys})");
            self::$seededKeys = [];
        }
    }

    private function seedSettings(): void
    {
        $svc = new SystemSettingsService();
        $map = [
            'google_client_id' => 'test-google-client-id',
            'google_client_secret' => 'test-google-secret',
            'meta_app_id' => 'test-meta-app-id',
            'meta_app_secret' => 'test-meta-secret',
            'oauth_microsoft_client_id' => 'test-ms-client-id',
            'oauth_microsoft_client_secret' => 'test-ms-secret',
            'oauth_microsoft_tenant' => 'common',
            'oauth_apple_client_id' => 'com.test.services',
            'oauth_apple_team_id' => 'TEAM1234',
            'oauth_apple_key_id' => 'KEYID1234',
            'oauth_apple_private_key' => "-----BEGIN EC PRIVATE KEY-----\nMHcCAQEEIImD0bo2c73iL81wgUieRaVD3SCtw35XaNX0gglgC06hoAoGCCqGSM49\nAwEHoUQDQgAE+y0ShB8PAWWEiwO3iUya5TrZ1VAw3/abkyZXYbuolvESw0K3kSrF\neO8yMa+XiOeUaOckwxzsXTdfncVf+vD76A==\n-----END EC PRIVATE KEY-----\n",
        ];
        foreach ($map as $key => $value) {
            $svc->set($key, $value);
            self::$seededKeys[] = $key;
        }
    }

    // ================================================================
    // Google - نجاح
    // ================================================================

    public function testGoogleSuccessTokenExchangeAndProfile(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'tok-123', 'token_type' => 'Bearer']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['sub' => 'g-user-1', 'email' => 'oauth-user@example.com', 'name' => 'OAuth User']), 'error' => null],
        ]);
        $client = new SocialLoginClient('google', $transport);

        $token = $client->exchangeCodeForToken('auth-code-1');
        $this->assertTrue($token['success']);
        $this->assertSame('tok-123', $token['access_token']);

        $profile = $client->fetchProfile($token['access_token']);
        $this->assertNotNull($profile);
        $this->assertSame('g-user-1', $profile['id']);
        $this->assertSame('oauth-user@example.com', $profile['email']);
        $this->assertSame('OAuth User', $profile['name']);

        $this->assertCount(2, $transport->calls);
        $this->assertStringContainsString('oauth2.googleapis.com/token', $transport->calls[0]['url']);
        $this->assertStringContainsString('code=auth-code-1', $transport->calls[0]['body']);
        $this->assertStringContainsString('Authorization: Bearer tok-123', $transport->calls[1]['headers'][1] ?? '');
    }

    public function testGoogleConfiguredAndAuthUrlContainsState(): void
    {
        $client = new SocialLoginClient('google', new OAuthTransportFake([]));
        $this->assertTrue($client->isConfigured());

        $url = $client->buildAuthUrl('state-xyz');
        $this->assertStringContainsString('state=state-xyz', $url);
        $this->assertStringContainsString('client_id=test-google-client-id', $url);
        $this->assertStringContainsString('redirect_uri', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    // ================================================================
    // Google - توكن غير صالح / منتهي
    // ================================================================

    public function testGoogleInvalidTokenRejected(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Invalid authorization code']), 'error' => null],
        ]);
        $client = new SocialLoginClient('google', $transport);

        $token = $client->exchangeCodeForToken('bad-code');
        $this->assertFalse($token['success']);
        $this->assertArrayHasKey('error', $token);
        $this->assertStringContainsString('Invalid authorization code', (string) $token['error']);
    }

    public function testGoogleExpiredAccessTokenProfileNull(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'expired-tok']), 'error' => null],
            ['http_code' => 401, 'body' => json_encode(['error' => 'invalid_token']), 'error' => null],
        ]);
        $client = new SocialLoginClient('google', $transport);

        $token = $client->exchangeCodeForToken('ok-code');
        $this->assertTrue($token['success']);

        $this->assertNull($client->fetchProfile($token['access_token']));
    }

    // ================================================================
    // فشل اتصال بالمزوّد الخارجي
    // ================================================================

    public function testProviderConnectionFailureSurfacesCleanly(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Could not resolve host: accounts.google.com'],
        ]);
        $client = new SocialLoginClient('google', $transport);

        $token = $client->exchangeCodeForToken('code');
        $this->assertFalse($token['success']);
        $this->assertStringContainsString('cURL Error', (string) $token['error']);
        $this->assertStringContainsString('Could not resolve host', (string) $token['error']);
    }

    public function testProviderConnectionFailureProfileNull(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 't']), 'error' => null],
            ['http_code' => 0, 'body' => '', 'error' => 'Connection timed out'],
        ]);
        $client = new SocialLoginClient('microsoft', $transport);

        $token = $client->exchangeCodeForToken('c');
        $this->assertTrue($token['success']);
        $this->assertNull($client->fetchProfile($token['access_token']));
    }

    // ================================================================
    // Replay attack
    // ================================================================

    public function testReplaySameCodeTwiceRejectedSecondTime(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'tok-1']), 'error' => null],
            // المزوّد الحقيقي بيرفض الكود المستخدم قبل كده بـ invalid_grant
            ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_grant']), 'error' => null],
        ]);
        $client = new SocialLoginClient('google', $transport);

        $first = $client->exchangeCodeForToken('single-use-code');
        $this->assertTrue($first['success']);

        $replay = $client->exchangeCodeForToken('single-use-code');
        $this->assertFalse($replay['success']);
        $this->assertStringContainsString('invalid_grant', (string) $replay['error']);
    }

    /** state أحادية الاستخدام على مستوى المتحكم: نفس الـ state مرتين → الثانية مرفوضة */
    public function testOAuthStateSingleUseBlocksReplay(): void
    {
        $auth = new AuthController();
        $_SESSION['oauth_state'] = 'state-single-use';
        $_SESSION['oauth_provider'] = 'google';

        $method = new ReflectionMethod(AuthController::class, 'verifyOAuthState');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($auth, 'google', 'state-single-use'));
        // الـ state اتشال من الجلسة بعد أول استخدام - أي محاولة تانية ترفض
        $this->assertFalse($method->invoke($auth, 'google', 'state-single-use'));
    }

    public function testOAuthStateProviderMismatchRejected(): void
    {
        $auth = new AuthController();
        $_SESSION['oauth_state'] = 'state-abc';
        $_SESSION['oauth_provider'] = 'google';

        $method = new ReflectionMethod(AuthController::class, 'verifyOAuthState');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($auth, 'facebook', 'state-abc'));
        $this->assertFalse($method->invoke($auth, 'google', 'wrong-state'));
    }

    // ================================================================
    // Facebook - بروفايل بـ access_token في الـ query
    // ================================================================

    public function testFacebookProfileUsesQueryAccessToken(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'fb-tok']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['id' => 'fb-user-9', 'email' => 'fb@example.com', 'name' => 'FB User']), 'error' => null],
        ]);
        $client = new SocialLoginClient('facebook', $transport);

        $token = $client->exchangeCodeForToken('fb-code');
        $this->assertTrue($token['success']);

        $profile = $client->fetchProfile($token['access_token']);
        $this->assertNotNull($profile);
        $this->assertSame('fb-user-9', $profile['id']);

        $url = $transport->calls[1]['url'];
        $this->assertStringContainsString('access_token=fb-tok', $url);
        $this->assertStringContainsString('fields=id%2Cname%2Cemail', $url);
    }

    // ================================================================
    // Apple - نجاح + فك id_token
    // ================================================================

    public function testAppleSuccessExchangeAndDecode(): void
    {
        $payload = json_encode(['iss' => 'https://appleid.apple.com', 'sub' => 'apple-user-1', 'email' => 'apple@example.com', 'exp' => time() + 300]);
        $idToken = self::b64url(json_encode(['alg' => 'ES256'])) . '.' . self::b64url($payload) . '.signature';

        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'a', 'id_token' => $idToken, 'expires_in' => 3600]), 'error' => null],
        ]);
        $client = new AppleSignInClient($transport);

        $token = $client->exchangeCodeForToken('apple-code');
        $this->assertTrue($token['success']);
        $this->assertSame($idToken, $token['id_token']);

        $decoded = $client->decodeIdToken($token['id_token']);
        $this->assertNotNull($decoded);
        $this->assertSame('apple-user-1', $decoded['id']);
        $this->assertSame('apple@example.com', $decoded['email']);

        $this->assertStringContainsString('code=apple-code', $transport->calls[0]['body']);
        $this->assertStringContainsString('appleid.apple.com/auth/token', $transport->calls[0]['url']);
    }

    public function testAppleInvalidCodeRejected(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_grant']), 'error' => null],
        ]);
        $client = new AppleSignInClient($transport);

        $token = $client->exchangeCodeForToken('bad-apple-code');
        $this->assertFalse($token['success']);
        $this->assertStringContainsString('invalid_grant', (string) $token['error']);
    }

    public function testAppleMalformedIdTokenRejected(): void
    {
        $client = new AppleSignInClient(new OAuthTransportFake([]));

        $this->assertNull($client->decodeIdToken('not-a-jwt'));
        $this->assertNull($client->decodeIdToken('a.b'));

        // id_token بتوكن من غير sub → مرفوض
        $badPayload = self::b64url(json_encode(['email' => 'x@example.com']));
        $this->assertNull($client->decodeIdToken('h.' . $badPayload . '.s'));
    }

    public function testAppleReplaySameCodeTwiceRejectedSecondTime(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id_token' => 'x.y.z']), 'error' => null],
            ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_grant']), 'error' => null],
        ]);
        $client = new AppleSignInClient($transport);

        $this->assertTrue($client->exchangeCodeForToken('apple-single-use')['success']);
        $replay = $client->exchangeCodeForToken('apple-single-use');
        $this->assertFalse($replay['success']);
    }

    // ================================================================
    // Apple - فشل اتصال بالمزوّد
    // ================================================================

    public function testAppleProviderConnectionFailureSurfaces(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Connection refused'],
        ]);
        $client = new AppleSignInClient($transport);

        $token = $client->exchangeCodeForToken('apple-code');
        $this->assertFalse($token['success']);
        $this->assertStringContainsString('cURL Error', (string) $token['error']);
    }

    // ================================================================
    // Microsoft - نجاح + بناء عنوان بتبديل الـ tenant
    // ================================================================

    public function testMicrosoftSuccessUsesTenantInUrl(): void
    {
        $transport = new OAuthTransportFake([
            ['http_code' => 200, 'body' => json_encode(['access_token' => 'ms-tok']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['sub' => 'ms-user-1', 'email' => 'ms@example.com']), 'error' => null],
        ]);
        $client = new SocialLoginClient('microsoft', $transport);

        $token = $client->exchangeCodeForToken('ms-code');
        $this->assertTrue($token['success']);
        $this->assertStringContainsString('login.microsoftonline.com/common/oauth2/v2.0/token', $transport->calls[0]['url']);

        $profile = $client->fetchProfile($token['access_token']);
        $this->assertSame('ms-user-1', $profile['id']);
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
