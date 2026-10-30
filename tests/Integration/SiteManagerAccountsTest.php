<?php

use PHPUnit\Framework\TestCase;

/** Per-site manager logins: hashing, lockout, role limits, strict isolation to one website. */
final class SiteManagerAccountsTest extends TestCase
{
    private const OWNER = 999960;
    private const OTHER_OWNER = 999961;
    private const SITE = 999960;
    private const OTHER_SITE = 999961;

    private PDO $pdo;
    private SiteManagerService $svc;

    protected function setUp(): void
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->svc = new SiteManagerService();
        $this->cleanup();
        foreach ([[self::OWNER, self::SITE, 'mgr-a'], [self::OTHER_OWNER, self::OTHER_SITE, 'mgr-b']] as [$uid, $sid, $slug]) {
            $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES ($uid, 'mgr$uid@tourfecto.test', 'x', 'T', NOW()) ON DUPLICATE KEY UPDATE email = email");
            $this->pdo->exec("INSERT INTO generated_websites (id, user_id, slug, status, content_json, created_at) VALUES ($sid, $uid, '$slug', 'published', '{}', NOW()) ON DUPLICATE KEY UPDATE user_id = $uid");
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM site_manager_accounts WHERE website_id IN (' . self::SITE . ',' . self::OTHER_SITE . ')');
        $this->pdo->exec('DELETE FROM site_manager_activity WHERE website_id IN (' . self::SITE . ',' . self::OTHER_SITE . ')');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->pdo->exec('DELETE FROM generated_websites WHERE id IN (' . self::SITE . ',' . self::OTHER_SITE . ')');
            $this->pdo->exec('DELETE FROM users WHERE id IN (' . self::OWNER . ',' . self::OTHER_OWNER . ')');
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function make(string $user = 'client.nile', string $role = 'manager', string $pw = 'Strong-pass-77'): int
    {
        $r = $this->svc->create(self::OWNER, self::SITE, $user, 'Client', $role, $pw);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return $r['id'];
    }

    public function testPasswordIsStoredHashedNeverPlain(): void
    {
        $this->make();
        $hash = (string) $this->pdo->query("SELECT password_hash FROM site_manager_accounts WHERE username = 'client.nile'")->fetchColumn();
        $this->assertNotSame('Strong-pass-77', $hash);
        $this->assertTrue(password_verify('Strong-pass-77', $hash));
    }

    public function testWeakPasswordsAndBadUsernamesAreRejected(): void
    {
        foreach (['short1', 'onlyletterslong', '1234567890123'] as $pw) {
            $this->assertFalse($this->svc->create(self::OWNER, self::SITE, 'weak.user', '', 'viewer', $pw)['ok'], $pw);
        }
        $this->assertFalse($this->svc->create(self::OWNER, self::SITE, 'Bad Name!', '', 'viewer', 'Strong-pass-77')['ok']);
        $this->assertFalse($this->svc->create(self::OWNER, self::SITE, 'role.test', '', 'superadmin', 'Strong-pass-77')['ok']);
    }

    public function testOnlyTheSiteOwnerCanCreateAccountsForTheSite(): void
    {
        $r = $this->svc->create(self::OTHER_OWNER, self::SITE, 'intruder.one', '', 'manager', 'Strong-pass-77');
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->svc->listFor(self::OTHER_OWNER, self::SITE));
    }

    public function testLoginWorksAndWrongPasswordGivesTheSameMessageAsUnknownUser(): void
    {
        $this->make();
        $ok = $this->svc->authenticate('Client.Nile', 'Strong-pass-77', '203.0.113.5');
        $this->assertTrue($ok['ok']);
        $this->assertSame(self::SITE, (int) $ok['account']['website_id']);
        $this->assertArrayNotHasKey('password_hash', $ok['account']);
        $wrong = $this->svc->authenticate('client.nile', 'nope-nope-1', '');
        $unknown = $this->svc->authenticate('nobody.here', 'nope-nope-1', '');
        $this->assertSame($wrong['error'], $unknown['error'], 'usernames must not be probeable');
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $this->make();
        for ($i = 0; $i < SiteManagerService::MAX_FAILED; $i++) {
            $this->svc->authenticate('client.nile', 'wrong-pass-9', '');
        }
        $locked = $this->svc->authenticate('client.nile', 'Strong-pass-77', '');
        $this->assertFalse($locked['ok'], 'even the right password is refused while locked');
    }

    public function testSwitchedOffAccountCannotLoginOrKeepASession(): void
    {
        $id = $this->make();
        $account = $this->svc->authenticate('client.nile', 'Strong-pass-77', '')['account'];
        $this->assertNotNull($this->svc->activeAccount($id, self::SITE));
        $this->svc->update(self::OWNER, self::SITE, $id, ['is_active' => 0]);
        $this->assertFalse($this->svc->authenticate('client.nile', 'Strong-pass-77', '')['ok']);
        $this->assertNull($this->svc->activeAccount($id, self::SITE), 'an open session loses access on the next request');
        $this->assertSame(self::SITE, (int) $account['website_id']);
    }

    public function testSessionIsPinnedToItsOwnSite(): void
    {
        $id = $this->make();
        $this->assertNull($this->svc->activeAccount($id, self::OTHER_SITE), 'a session cannot be pointed at another site');
    }

    public function testResetPasswordUnlocksAndInvalidatesTheOldOne(): void
    {
        $id = $this->make();
        for ($i = 0; $i < SiteManagerService::MAX_FAILED; $i++) {
            $this->svc->authenticate('client.nile', 'wrong-pass-9', '');
        }
        $this->assertTrue($this->svc->update(self::OWNER, self::SITE, $id, ['password' => 'Another-pass-88'])['ok']);
        $this->assertFalse($this->svc->authenticate('client.nile', 'Strong-pass-77', '')['ok']);
        $this->assertTrue($this->svc->authenticate('client.nile', 'Another-pass-88', '')['ok']);
    }

    public function testOtherOwnersCannotEditOrDeleteTheAccount(): void
    {
        $id = $this->make();
        $this->assertFalse($this->svc->update(self::OTHER_OWNER, self::SITE, $id, ['role' => 'viewer'])['ok']);
        $this->assertFalse($this->svc->delete(self::OTHER_OWNER, self::SITE, $id));
        $this->assertTrue($this->svc->delete(self::OWNER, self::SITE, $id));
    }

    public function testRoleMatrix(): void
    {
        $this->assertTrue(SiteManagerService::can('manager', 'bookings'));
        $this->assertFalse(SiteManagerService::can('editor', 'bookings'));
        $this->assertTrue(SiteManagerService::can('editor', 'leads'));
        $this->assertTrue(SiteManagerService::can('viewer', 'view'));
        $this->assertFalse(SiteManagerService::can('viewer', 'leads'));
        $this->assertFalse(SiteManagerService::can('unknown', 'view'));
    }

    public function testGeneratedPasswordsPassThePolicy(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame([], SiteManagerService::passwordProblems(SiteManagerService::generatePassword(), 'x'));
        }
    }

    private function controllerFor(int $accountId): SiteManagerController
    {
        $_SESSION['site_manager'] = ['id' => $accountId, 'website_id' => self::SITE, 'at' => time()];
        unset($_SESSION['user']);
        return new SiteManagerController();
    }

    public function testManagerSessionNeverBecomesATourfectoUserSession(): void
    {
        $id = $this->make();
        $this->controllerFor($id);
        $this->assertArrayNotHasKey('user', $_SESSION, 'no other part of the platform may accept a site-manager login');
        unset($_SESSION['site_manager']);
    }

    public function testViewerCannotConfirmBookingsAndAnonymousIsRefused(): void
    {
        $id = $this->make('viewer.only', 'viewer');
        $c = $this->controllerFor($id);
        $r = $c->bookingAction(['bookingId' => 1]);
        $this->assertSame(403, $r['status'] ?? $r['code'] ?? 403);
        $this->assertFalse((bool) ($r['success'] ?? false));
        $this->assertTrue((bool) ($c->bookingsList([])['success'] ?? false), 'read access works');

        unset($_SESSION['site_manager']);
        $anon = (new SiteManagerController())->bookingsList([]);
        $this->assertFalse((bool) ($anon['success'] ?? false));
    }

    public function testSwitchedOffAccountSessionIsDroppedByTheController(): void
    {
        $id = $this->make();
        $this->svc->update(self::OWNER, self::SITE, $id, ['is_active' => 0]);
        $r = $this->controllerFor($id)->bookingsList([]);
        $this->assertFalse((bool) ($r['success'] ?? false));
        $this->assertArrayNotHasKey('site_manager', $_SESSION);
    }

    private function savedContent(): array
    {
        return json_decode((string) $this->pdo->query('SELECT content_json FROM generated_websites WHERE id = ' . self::SITE)->fetchColumn(), true);
    }

    private function saveAs(int $accountId, array $payload): array
    {
        $_SESSION['site_manager'] = ['id' => $accountId, 'website_id' => self::SITE, 'at' => time()];
        $_SESSION['csrf_token'] = 'tok-123';
        $_POST = $payload + ['csrf_token' => 'tok-123'];
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        try {
            return (new SiteManagerController())->contentSave([]);
        } finally {
            $_POST = [];
            unset($_SESSION['site_manager']);
        }
    }

    public function testManagerEditsTextsButNeverPricesAndMarkupIsStripped(): void
    {
        $this->pdo->exec("UPDATE generated_websites SET content_json = " . $this->pdo->quote(json_encode([
            'business_name' => 'Old', 'tours' => [['slug' => 'nile', 'name' => 'Nile', 'price' => '350 EGP', 'short_description' => 'old']],
        ])) . ' WHERE id = ' . self::SITE);
        $id = $this->make('content.mgr', 'manager');
        $r = $this->saveAs($id, [
            'business_name' => '<b>New Name</b>', 'about' => "line one
<script>x</script>line two",
            'contact' => ['whatsapp' => '+20 100 000 0000', 'email' => 'info@example.test'],
            'items' => [['slug' => 'nile', 'short_description' => 'Fresh <i>text</i>', 'highlights' => "Sunset
Tea", 'price' => '1 EGP'], ['slug' => 'unknown', 'short_description' => 'x']],
        ]);
        $this->assertTrue((bool) ($r['success'] ?? false), json_encode($r));
        $c = $this->savedContent();
        $this->assertSame('New Name', $c['business_name']);
        $this->assertStringNotContainsString('<script', $c['about']);
        $this->assertSame('+201000000000', $c['contact']['whatsapp']);
        $this->assertSame('Fresh text', $c['tours'][0]['short_description']);
        $this->assertSame(['Sunset', 'Tea'], $c['tours'][0]['highlights']);
        $this->assertSame('350 EGP', $c['tours'][0]['price'], 'prices stay with the account owner');
        $this->assertCount(1, $c['tours'], 'unknown slugs are ignored, nothing is added');
    }

    public function testInvalidEmailAndEmptyNameAreRejected(): void
    {
        $id = $this->make('content.bad', 'manager');
        $this->assertFalse((bool) ($this->saveAs($id, ['contact' => ['email' => 'not-an-email']])['success'] ?? false));
        $this->assertFalse((bool) ($this->saveAs($id, ['business_name' => '   '])['success'] ?? false));
    }

    public function testEditorAndViewerCannotEditContent(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $id = $this->make('c.' . $role, $role);
            $r = $this->saveAs($id, ['business_name' => 'Hacked']);
            $this->assertFalse((bool) ($r['success'] ?? false), $role);
        }
        $this->assertNotSame('Hacked', $this->savedContent()['business_name'] ?? '');
    }

    public function testOwnPasswordChangeNeedsTheCurrentPasswordAndThePolicy(): void
    {
        $id = $this->make('self.change');
        $this->assertFalse($this->svc->changeOwnPassword($id, self::SITE, 'wrong-current-1', 'Brand-new-pass-5')['ok']);
        $this->assertFalse($this->svc->changeOwnPassword($id, self::SITE, 'Strong-pass-77', 'short1')['ok']);
        $this->assertFalse($this->svc->changeOwnPassword($id, self::SITE, 'Strong-pass-77', 'Strong-pass-77')['ok'], 'must differ');
        $this->assertFalse($this->svc->changeOwnPassword($id, self::OTHER_SITE, 'Strong-pass-77', 'Brand-new-pass-5')['ok'], 'pinned to its own site');
        $this->assertTrue($this->svc->changeOwnPassword($id, self::SITE, 'Strong-pass-77', 'Brand-new-pass-5')['ok']);
        $this->assertFalse($this->svc->authenticate('self.change', 'Strong-pass-77', '')['ok']);
        $this->assertTrue($this->svc->authenticate('self.change', 'Brand-new-pass-5', '')['ok']);
    }

    public function testActivityIsLoggedAndOnlyTheOwnerCanReadIt(): void
    {
        $id = $this->make('logger.one');
        $this->svc->log($id, self::SITE, 'booking_confirm', '#5');
        $this->svc->log($id, self::SITE, 'login');
        $rows = $this->svc->activityFor(self::OWNER, self::SITE);
        $this->assertSame(['login', 'booking_confirm'], array_column($rows, 'action'), 'newest first');
        $this->assertSame('logger.one', $rows[0]['username']);
        $this->assertSame([], $this->svc->activityFor(self::OTHER_OWNER, self::SITE));
    }

    public function testLoginThroughTheControllerIsRecorded(): void
    {
        $this->make('ctl.login');
        $_SESSION['csrf_token'] = 'tok-9';
        $_POST = ['csrf_token' => 'tok-9', 'username' => 'ctl.login', 'password' => 'Strong-pass-77'];
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $r = (new SiteManagerController())->login([]);
        $_POST = [];
        $this->assertTrue((bool) ($r['success'] ?? false));
        $this->assertSame(['login'], array_column($this->svc->activityFor(self::OWNER, self::SITE), 'action'));
        unset($_SESSION['site_manager']);
    }

    public function testInviteCreatesAPendingAccountWithAHashedOneTimeToken(): void
    {
        $r = $this->svc->invite(self::OWNER, self::SITE, 'invited.one', 'Invited', 'editor', 'invited@example.test');
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['token']);
        $stored = (string) $this->pdo->query("SELECT invite_token_hash FROM site_manager_accounts WHERE id = " . (int) $r['id'])->fetchColumn();
        $this->assertSame(hash('sha256', $r['token']), $stored);
        $this->assertNotSame($r['token'], $stored, 'the raw token is never stored');
        $rows = $this->svc->listFor(self::OWNER, self::SITE);
        $this->assertSame('invited@example.test', $rows[0]['email']);
        $this->assertSame(1, (int) $rows[0]['invite_pending']);
        $this->assertFalse($this->svc->invite(self::OWNER, self::SITE, 'bad.mail', '', 'viewer', 'not-an-email')['ok']);
        $this->assertFalse($this->svc->invite(self::OTHER_OWNER, self::SITE, 'intruder.inv', '', 'viewer', 'x@example.test')['ok'], 'only the owner invites');
    }

    public function testAcceptingAnInviteSetsThePasswordAndTheLinkWorksOnce(): void
    {
        $r = $this->svc->invite(self::OWNER, self::SITE, 'invited.two', 'Two', 'manager', 'two@example.test');
        $info = $this->svc->inviteInfo($r['token']);
        $this->assertSame('invited.two', $info['username']);
        $weak = $this->svc->acceptInvite($r['token'], 'short1');
        $this->assertFalse($weak['ok']);
        $this->assertNotNull($this->svc->inviteInfo($r['token']), 'a weak password does not burn the link');
        $ok = $this->svc->acceptInvite($r['token'], 'My-own-pass-42');
        $this->assertGreaterThan(0, $ok['account_id'] ?? 0, 'the controller signs the person in with these ids');
        $this->assertGreaterThan(0, $ok['website_id'] ?? 0);
        $this->assertTrue($ok['ok']);
        $this->assertTrue($this->svc->authenticate('invited.two', 'My-own-pass-42', '')['ok']);
        $this->assertNull($this->svc->inviteInfo($r['token']), 'the link is single use');
        $this->assertFalse($this->svc->acceptInvite($r['token'], 'Another-pass-43')['ok']);
        $this->assertFalse($this->svc->authenticate('invited.two', 'Another-pass-43', '')['ok']);
        $this->assertSame(0, (int) $this->svc->listFor(self::OWNER, self::SITE)[0]['invite_pending']);
    }

    public function testExpiredMalformedAndReplacedInviteLinksAreRefused(): void
    {
        $r = $this->svc->invite(self::OWNER, self::SITE, 'invited.three', '', 'viewer', 'three@example.test');
        $this->assertNull($this->svc->inviteInfo('not-a-token'));
        $this->assertNull($this->svc->inviteInfo(str_repeat('a', 64)));
        $this->pdo->exec("UPDATE site_manager_accounts SET invite_expires = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = " . (int) $r['id']);
        $this->assertNull($this->svc->inviteInfo($r['token']), 'expired');
        $this->assertFalse($this->svc->acceptInvite($r['token'], 'My-own-pass-42')['ok']);

        $fresh = $this->svc->reinvite(self::OWNER, self::SITE, (int) $r['id']);
        $this->assertTrue($fresh['ok']);
        $this->assertNotNull($this->svc->inviteInfo($fresh['token']));
        $again = $this->svc->reinvite(self::OWNER, self::SITE, (int) $r['id']);
        $this->assertNull($this->svc->inviteInfo($fresh['token']), 'sending a new invite cancels the previous link');
        $this->assertNotNull($this->svc->inviteInfo($again['token']));
        $this->assertFalse($this->svc->reinvite(self::OTHER_OWNER, self::SITE, (int) $r['id'])['ok']);
    }

    public function testSwitchedOffAccountsCannotAcceptAnInvite(): void
    {
        $r = $this->svc->invite(self::OWNER, self::SITE, 'invited.four', '', 'viewer', 'four@example.test');
        $this->svc->update(self::OWNER, self::SITE, (int) $r['id'], ['is_active' => 0]);
        $this->assertNull($this->svc->inviteInfo($r['token']));
    }

    public function testAcceptEndpointNeedsCsrfAndAValidToken(): void
    {
        $r = $this->svc->invite(self::OWNER, self::SITE, 'invited.five', '', 'viewer', 'five@example.test');
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SESSION['csrf_token'] = 'tok-5';

        $_POST = ['token' => $r['token'], 'password' => 'My-own-pass-42'];
        $noCsrf = (new SiteManagerController())->inviteAccept([]);
        $this->assertFalse((bool) ($noCsrf['success'] ?? false), 'refused without a CSRF token');

        $_POST = ['csrf_token' => 'tok-5', 'token' => str_repeat('0', 64), 'password' => 'My-own-pass-42'];
        $this->assertFalse((bool) ((new SiteManagerController())->inviteAccept([])['success'] ?? false));

        $_POST = ['csrf_token' => 'tok-5', 'token' => $r['token'], 'password' => 'My-own-pass-42'];
        $this->assertTrue((bool) ((new SiteManagerController())->inviteAccept([])['success'] ?? false));
        $_POST = [];
        $this->assertTrue($this->svc->authenticate('invited.five', 'My-own-pass-42', '')['ok']);
    }
}
