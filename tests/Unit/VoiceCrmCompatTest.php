<?php

/** AI Voice & Sales Agent: CRM task status compatibility across the two crm_tasks definitions. Pure. */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceCrmCompat.php';

final class VoiceCrmCompatTest extends TestCase
{
    public function testOpenStatusFollowsWhicheverCrmTasksDefinitionIsLive(): void
    {
        $this->assertSame('open', VoiceCrmCompat::openStatusFrom("enum('open','in_progress','done','cancelled')"));
        $this->assertSame('pending', VoiceCrmCompat::openStatusFrom("enum('pending','in_progress','completed','cancelled')"));
        $this->assertSame('pending', VoiceCrmCompat::openStatusFrom(''));
    }
}
