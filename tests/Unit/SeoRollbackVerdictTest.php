<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoRollbackVerdict.php';

/** Live-test findings (site 45): our own schema still visible must not be called a client edit; a client's title is never overwritten. */
final class SeoRollbackVerdictTest extends TestCase
{
    public function testRestoredPageIsVerified(): void
    {
        $this->assertSame('rollback_verified', SeoRollbackVerdict::evaluate(['yamamatpalestine.online'], ['yamamatpalestine.online'], false, true));
        $this->assertSame('rollback_verified', SeoRollbackVerdict::evaluate([], [], false, true), 'no schema before, none now');
    }

    public function testOurOwnValueStillLiveIsNotAClientEdit(): void
    {
        $ours = [['@type' => 'Organization', 'name' => 'Test']];
        $this->assertSame('rollback_not_seen', SeoRollbackVerdict::evaluate([], $ours, true, true));
    }

    public function testAThirdValueIsAClientEditAndIsNotOverwritten(): void
    {
        $this->assertSame('client_edit_detected', SeoRollbackVerdict::evaluate(['old title'], ['Client Edited Title'], false, true));
    }

    public function testUnreadablePageIsNeverAVerdict(): void
    {
        $this->assertSame('rollback_not_seen', SeoRollbackVerdict::evaluate(['a'], ['b'], false, false));
        $this->assertSame('rollback_not_seen', SeoRollbackVerdict::evaluate(['a'], [], false, true), 'an empty read is not a client edit');
    }
}
