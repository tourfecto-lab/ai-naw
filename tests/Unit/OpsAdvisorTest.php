<?php

/** TOURFECTO AI CEO (المرحلة 7): حكم فحص الصفحات، تحويل الإشارات لحوادث، أولوية بنود التطوير، ونص الـ Issue */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/OpsAdvisor.php';

final class OpsAdvisorTest extends TestCase
{
    public function testVerdict(): void
    {
        $this->assertSame('ok', OpsAdvisor::verdict('/', 'public', 200, 400)['status']);
        $this->assertSame('fail', OpsAdvisor::verdict('/', 'public', 500, 400)['status']);
        $this->assertSame('fail', OpsAdvisor::verdict('/', 'public', 0, 15000)['status']);
        $this->assertSame('fail', OpsAdvisor::verdict('/pricing', 'public', 404, 100)['status']);
        $this->assertSame('slow', OpsAdvisor::verdict('/', 'public', 200, 4500)['status']);
        $this->assertSame('ok', OpsAdvisor::verdict('/dashboard', 'protected', 302, 200)['status']);
        $this->assertSame('ok', OpsAdvisor::verdict('/api/brain', 'protected', 401, 200)['status']);
        $this->assertSame('warn', OpsAdvisor::verdict('/dashboard', 'protected', 200, 200)['status']);
        $this->assertSame('fail', OpsAdvisor::verdict('/dashboard', 'protected', 502, 200)['status']);
    }

    public function testSignals(): void
    {
        $syn = [OpsAdvisor::verdict('/', 'public', 500, 100), OpsAdvisor::verdict('/crm', 'protected', 200, 100), OpsAdvisor::verdict('/blog', 'public', 200, 5000), OpsAdvisor::verdict('/help', 'public', 200, 100)];
        $s = OpsAdvisor::signals($syn, [['section' => 'cron', 'key' => 'scheduler', 'status' => 'fail', 'label' => 'Scheduler', 'message' => 'not run 3h'], ['section' => 'mail', 'key' => 'x', 'status' => 'warn', 'label' => 'x', 'message' => '']],
            [['message' => 'Query Error x', 'count' => 150], ['message' => 'rare', 'count' => 3]], [['provider' => 'gemini', 'status' => 'fail', 'error_rate' => 40.0, 'calls' => 50]], ['status' => 'fail', 'message' => 'old']);
        $this->assertSame('critical', $s['page:/']['severity']);
        $this->assertSame('security', $s['auth:/crm']['service']);
        $this->assertSame('low', $s['slow:/blog']['severity']);
        $this->assertSame('high', $s['health:cron:scheduler']['severity']);
        $this->assertSame(false, isset($s['health:mail:x']), 'warnings are not incidents');
        $this->assertSame('high', $s['error:' . substr(md5('Query Error x'), 0, 12)]['severity']);
        $this->assertSame(true, isset($s['provider:gemini']) && isset($s['backup']));
        $this->assertSame(7, count($s));
    }

    public function testBacklogPriority(): void
    {
        $this->assertSame(true, OpsAdvisor::backlogPriority('critical', 'S') > OpsAdvisor::backlogPriority('critical', 'L'));
        $this->assertSame(true, OpsAdvisor::backlogPriority('high', 'M') > OpsAdvisor::backlogPriority('low', 'M'));
        $this->assertSame(true, OpsAdvisor::backlogPriority('medium', 'M') <= 50.0);
    }

    public function testIssueText(): void
    {
        $t = OpsAdvisor::issueText(['id' => 7, 'service' => 'whatsapp', 'problem' => 'Customers cannot connect', 'evidence' => '5 messages', 'severity' => 'high', 'improvement' => 'Fix', 'priority' => 38.5,
            'cost_estimate' => 'M', 'benefit' => 'Less churn', 'test_method' => 'Connect a test number', 'needs_approval' => true, 'metric_before' => '5 / 90 days']);
        $this->assertSame('[whatsapp] Customers cannot connect', $t['title']);
        foreach (['**Evidence:** 5 messages', '**How to test:** Connect a test number', '**Needs approval:** yes', '**Before:** 5 / 90 days', 'backlog #7'] as $needle) $this->assertStringContainsString($needle, $t['body']);
    }
}
