<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoPlanQualityGate.php';

/** Live finding (site 45): the plan asked to "create a sitemap" and "update robots.txt" on a site that already has both. */
final class SeoPlanEvidenceTest extends TestCase
{
    private function tasks(): array
    {
        return [
            ['title' => 'Create a Sitemap', 'instructions' => 'Generate a sitemap for your website and submit it to Google Search Console.'],
            ['title' => 'Update Robots.txt File', 'instructions' => 'Check the robots.txt file to ensure that it is configured properly.'],
            ['title' => 'Fix the broken link to /old-tour', 'instructions' => 'The page /tours returns 404 for /old-tour.'],
        ];
    }

    public function testSitemapAndRobotsTasksAreDroppedWhenTheSiteAlreadyHasThem(): void
    {
        $kept = SeoPlanQualityGate::filterClientTasks($this->tasks(), [], ['sitemap_valid' => 3, 'robots_text' => "User-agent: *\nDisallow: /wp-admin/\n"]);
        $this->assertSame(['Fix the broken link to /old-tour'], array_column($kept, 'title'));
    }

    public function testTasksStayWhenThereIsNoEvidenceOrTheSiteReallyBlocks(): void
    {
        $this->assertCount(3, SeoPlanQualityGate::filterClientTasks($this->tasks(), [], []), "no evidence: nothing is dropped on a guess");
        $kept = SeoPlanQualityGate::filterClientTasks($this->tasks(), [], ['sitemap_valid' => 0, 'robots_text' => "User-agent: *\nDisallow: /\n"]);
        $this->assertContains('Update Robots.txt File', array_column($kept, 'title'), 'a robots.txt that blocks everything is a real problem');
    }

    public function testRobotsBlockDetection(): void
    {
        $this->assertTrue(SeoPlanQualityGate::robotsBlocksSite("User-agent: *\nDisallow: /\n"));
        $this->assertFalse(SeoPlanQualityGate::robotsBlocksSite("User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n"));
        $this->assertFalse(SeoPlanQualityGate::robotsBlocksSite("User-agent: BadBot\nDisallow: /\n\nUser-agent: *\nDisallow:\n"), 'a rule for another bot is not a site block');
    }
}
