<?php

use PHPUnit\Framework\TestCase;

/** Stored-crawl title/description length review tasks for pages outside the top-priority plan. */
final class SeoMetaLengthTasksTest extends TestCase
{
    /** @var array<int,array> */
    private array $inserted = [];

    private function service(): SeoExpertService
    {
        $this->inserted = [];
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($args) {
            $this->inserted[] = $args;
            return true;
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $ref = new ReflectionClass(SeoExpertService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('pdo');
        $prop->setAccessible(true);
        $prop->setValue($svc, $pdo);
        return $svc;
    }

    private function page(string $path, string $title, string $description): array
    {
        $url = 'https://example.com' . $path;
        return ['url' => $url, 'page_path' => $path, 'title' => $title, 'description' => $description,
            'values' => ['title' => [$title], 'description' => [$description]]];
    }

    private function run1(array $pages, array $changes = [], string $lang = 'en'): array
    {
        $svc = $this->service();
        $m = new ReflectionMethod($svc, 'metaLengthTasks');
        $m->setAccessible(true);
        $m->invoke($svc, ['id' => 1, 'website_id' => 2, 'user_id' => 3], $pages, ['brief' => ['language' => $lang]], $changes);
        return $this->inserted;
    }

    private const GOOD_TITLE = 'Private Cairo Day Tour with Pyramids | Brand';
    private const GOOD_DESC = 'Visit the Pyramids of Giza and the Egyptian Museum on a private full-day tour with a licensed guide, hotel pickup and lunch included.';

    public function testPagesWithinRangeCreateNoTasks(): void
    {
        $this->assertSame([], $this->run1([$this->page('/tours/cairo', self::GOOD_TITLE, self::GOOD_DESC)]));
    }

    public function testTooLongTitleAndTooShortDescriptionCreateReviewTasks(): void
    {
        $rows = $this->run1([$this->page('/tours/cairo', str_repeat('A', 80), 'Too short')]);
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('too long (80 characters)', $rows[0][7]);
        $this->assertStringContainsString('too short (9 characters)', $rows[1][7]);
        $this->assertSame('needs_client', end($rows[0]));
        $this->assertSame('manual', $rows[0][3]);
    }

    public function testArabicWordingWhenBriefLanguageIsArabic(): void
    {
        $rows = $this->run1([$this->page('/tours/cairo', str_repeat('A', 80), self::GOOD_DESC)], [], 'ar');
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('طويل', $rows[0][7]);
    }

    public function testFieldWithAnExistingProposedChangeIsSkipped(): void
    {
        $changes = [['page_index' => 0, 'field' => 'title', 'value' => 'x']];
        $rows = $this->run1([$this->page('/tours/cairo', str_repeat('A', 80), self::GOOD_DESC)], $changes);
        $this->assertSame([], $rows);
    }

    public function testEmptyAndMultiValuedFieldsAreSkipped(): void
    {
        $empty = $this->page('/tours/a', '', '');
        $multi = $this->page('/tours/b', str_repeat('A', 80), self::GOOD_DESC);
        $multi['values']['title'] = [str_repeat('A', 80), 'second title'];
        $this->assertSame([], $this->run1([$empty, $multi]));
    }

    public function testIneligiblePagesAreSkipped(): void
    {
        $this->assertSame([], $this->run1([$this->page('/cart', str_repeat('A', 80), 'short')]));
    }

    public function testAtMostTenTasksPerCycle(): void
    {
        $pages = [];
        for ($i = 0; $i < 20; $i++) {
            $pages[] = $this->page('/tours/t' . $i, str_repeat('A', 80), 'short');
        }
        $this->assertCount(10, $this->run1($pages));
    }
}
