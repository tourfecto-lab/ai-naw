<?php

use PHPUnit\Framework\TestCase;

/**
 * The per-site WhatsApp webhook answers 200 for "nothing to deliver to" (no connection, or disconnected on purpose),
 * so Meta never retries or disables the subscription; a broken-but-present connection answers 503 so Meta redelivers.
 */
final class WhatsAppRetryDecisionTest extends TestCase
{
    private function decide(?string $status): bool
    {
        $m = new ReflectionMethod(WhatsAppCloudController::class, 'shouldAskMetaToRetry');
        $m->setAccessible(true);
        return $m->invoke(null, $status);
    }

    public function testFinalStatesAreAcknowledged(): void
    {
        $this->assertFalse($this->decide(null), 'no connection row');
        $this->assertFalse($this->decide(''), 'blank status');
        $this->assertFalse($this->decide('disconnected'), 'owner disconnected on purpose');
        $this->assertFalse($this->decide('connected'), 'connected is handled by the normal path');
    }

    public function testBrokenButPresentConnectionsAskForRetry(): void
    {
        $this->assertTrue($this->decide('error'));
        $this->assertTrue($this->decide('expired'));
        $this->assertTrue($this->decide('pending'));
    }
}
