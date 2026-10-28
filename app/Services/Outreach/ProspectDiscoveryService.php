<?php

/**
 * Tourfecto - Outreach Prospect Discovery Service
 * بيكتشف مرشّحين للـ Backlink تلقائيًا (الاكتشاف والصياغة تلقائيين)،
 * بس **أي إرسال فعلي لازم موافقة صريحة** من العميل من غير استثناء
 * (الرسالة بتتسجّل draft ومحتاجة approveEmail زي التدفق الحالي بالظبط).
 *
 * الأمان:
 * - بيجمع فقط بيانات عامة معلنة من مصدر الـ CompetitorIntelligence
 *   (دومين / نوع نشاط / صفحة ذات صلة) - **ممنوع استخراج بيانات تواصل
 *   شخصية** (WHOIS / إيميلات خاصة)، والكود لا يكتب contact_email أو
 *   contact_name إطلاقًا.
 * - منع التكرار: دومين موجود فعلًا (لنفس الموقع) أو دومين اتعمل منه
 *   رابط بالفعل (link_acquired) بيتتجاهل.
 * - لا يخترع مرشّحين: لو مفيش بيانات مصدر متاحة بيرجّع insufficient_data.
 * @version 1.0.0
 */
class ProspectDiscoveryService
{
    /** @var ProspectDiscoverySourceInterface[] */
    private array $sources;
    private ?OutreachEmailGenerator $generator;

    public function __construct(?ProspectDiscoverySourceInterface $source = null, ?OutreachEmailGenerator $generator = null)
    {
        $this->sources = $source !== null ? [$source] : [new CompetitorBacklinkDiscoverySource()];
        $this->generator = $generator;
    }

    /**
     * اكتشاف مرشّحين لموقع معين، حفظ الجدد منهم بـ status='prospect'
     * (فقط)، وتوليد مسودة رسالة لكل مرشح جديد (حالة draft).
     *
     * @return array{
     *   available:bool, reason:?string,
     *   discovered:int, duplicates:int, skipped_own:int, already_linked:int,
     *   drafts_generated:int, prospects:array
     * }
     */
    public function discoverForWebsite(int $userId, int $websiteId, array $options = []): array
    {
        $db = Database::getInstance();

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== $userId) {
            return ['available' => false, 'reason' => 'website_not_found',
                    'discovered' => 0, 'duplicates' => 0, 'skipped_own' => 0,
                    'already_linked' => 0, 'drafts_generated' => 0, 'prospects' => []];
        }

        $myWebsite = [
            'company_name' => $website->getAttribute('company_name') ?: null,
            'main_url' => $website->getAttribute('main_url') ?: null,
            'industry' => $website->getAttribute('industry') ?: null,
        ];
        $ownDomain = $this->hostOf((string) ($myWebsite['main_url'] ?? ''));
        $industry = (string) ($myWebsite['industry'] ?? '');

        $context = ['user_id' => $userId, 'website_id' => $websiteId];
        $result = ['available' => false, 'reason' => null,
                   'discovered' => 0, 'duplicates' => 0, 'skipped_own' => 0,
                   'already_linked' => 0, 'drafts_generated' => 0, 'prospects' => []];

        $reasons = [];
        $anyAvailable = false;

        foreach ($this->sources as $source) {
            $found = $source->discover($context);

            if (!($found['available'] ?? false)) {
                $reasons[] = $source->sourceName() . ':' . ($found['reason'] ?? 'unavailable');
                continue;
            }

            $anyAvailable = true;
            $existing = $this->existingDomains($db, $userId, $websiteId);
            $linked = $this->linkedDomains($db, $userId);

            foreach ($found['candidates'] as $candidate) {
                $domain = $this->normalizeDomain((string) ($candidate['domain'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                // استبعاد دوميننا الخاص بنا
                if ($ownDomain !== '' && $this->normalizeDomain($ownDomain) === $domain) {
                    $result['skipped_own']++;
                    continue;
                }
                // منع تكرار دومين موجود لنفس الموقع (أي حالة)
                if (isset($existing[$domain])) {
                    $result['duplicates']++;
                    continue;
                }
                // إنت مالكش رابط منها: نستبعد الدومينات اللي فيهم link_acquired أصلًا
                if (isset($linked[$domain])) {
                    $result['already_linked']++;
                    continue;
                }

                $prospect = $this->saveProspect($db, $userId, $websiteId, $candidate, $domain, $industry);
                $result['discovered']++;
                $result['prospects'][] = $prospect->toArray();

                $draftSaved = $this->generateDraft($prospect->toArray(), $myWebsite);
                if ($draftSaved) {
                    $result['drafts_generated']++;
                }
            }
        }

        $result['available'] = $anyAvailable;
        $result['reason'] = $anyAvailable ? null : ('insufficient_data: ' . implode(', ', $reasons));

        if ($anyAvailable) {
            ActivityLog::record('outreach', 'discovery.run', [
                'user_id' => $userId,
                'subject_type' => 'outreach_prospects',
                'subject_id' => $websiteId,
                'meta' => ['discovered' => $result['discovered'], 'drafts' => $result['drafts_generated']],
            ]);
        }

        return $result;
    }

    /**
     * حساب relevance_score (0-100) لمرشّح من إشارات عامة فقط.
     * public static عشان يتختبر بوحدة مستقلة.
     */
    public static function relevanceScore(array $signals, string $industry = '', string $candidateName = ''): int
    {
        $score = 40.0; // الأساس: دومين صحيح في نفس المجال

        // قوة الموقع (competitor_score DECIMAL 0-100 غالبًا) - لحد 30 نقطة
        $competitorScore = (float) ($signals['competitor_score'] ?? 0);
        $score += min(30.0, max(0.0, $competitorScore / 100) * 30.0);

        // وجود لقطة حقيقية (موقع نشط بمحتوى مُلتقط) - 12 نقطة
        if (!empty($signals['has_snapshot'])) {
            $score += 12.0;
        }

        // تشابه الكلمات المفتاحية بين مجالي/اسم المرشح ومجالي - لحد 18 نقطة
        $overlap = 0.0;
        $tokens = preg_split('/[\s،,·\-_\/]+/u', mb_strtolower($industry)) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            $haystack = mb_strtolower($candidateName . ' ' . ($signals['business_type'] ?? ''));
            if (mb_strpos($haystack, $token) !== false) {
                $overlap += 6.0;
            }
        }
        $score += min(18.0, $overlap);

        return (int) max(0, min(100, round($score)));
    }

    /** حفظ المرشح الجديد ببيانات عامة فقط (status='prospect'، بلا أي بيانات تواصل) */
    private function saveProspect(Database $db, int $userId, int $websiteId, array $candidate, string $domain, string $industry): OutreachProspect
    {
        $name = (string) ($candidate['signals']['name'] ?? '');

        $prospect = new OutreachProspect([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'domain' => $domain,
            // ممنوع: contact_name / contact_email - بيانات عامة معلنة فقط
            'contact_name' => null,
            'contact_email' => null,
            'business_type' => $candidate['business_type'] ?? null,
            'relevant_page' => $candidate['relevant_page'] ?? null,
            'collaboration_idea' => $candidate['collaboration_idea'] ?? null,
            'notes' => 'اكتشاف تلقائي من ' . ($candidate['source'] ?? 'competitor_backlinks')
                . ' | relevance_score=' . self::relevanceScore($candidate['signals'] ?? [], $industry, $name),
            'status' => 'prospect',
        ]);
        $prospect->save();

        return $prospect;
    }

    /** توليد مسودة رسالة (status='draft') - مش بتتبعت غير بعد موافقة صريحة. */
    private function generateDraft(array $prospect, array $myWebsite): bool
    {
        if ($this->generator === null) {
            return false;
        }
        try {
            $result = $this->generator->generate($prospect, $myWebsite, 0);
            if (!($result['success'] ?? false)) {
                return false;
            }
            $email = new OutreachEmail([
                'prospect_id' => (int) $prospect['id'],
                'sequence_number' => 0,
                'subject' => (string) $result['data']['subject'],
                'body' => (string) $result['data']['body'],
                'status' => 'draft',
            ]);
            $email->save();
            return true;
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Outreach draft generation failed', ['prospect_id' => (int) ($prospect['id'] ?? 0), 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /** الدومينات الموجودة فعلًا لنفس الموقع (أي حالة) - لمنع التكرار */
    private function existingDomains(Database $db, int $userId, int $websiteId): array
    {
        $rows = $db->query(
            'SELECT domain FROM outreach_prospects WHERE user_id = ? AND website_id = ?',
            [$userId, $websiteId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$this->normalizeDomain((string) $r['domain'])] = true;
        }
        return $map;
    }

    /** الدومينات اللي احنا أصلًا جايبن منها رابط (link_acquired) للمستخدم */
    private function linkedDomains(Database $db, int $userId): array
    {
        $rows = $db->query(
            "SELECT domain FROM outreach_prospects WHERE user_id = ? AND status = 'link_acquired'",
            [$userId]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$this->normalizeDomain((string) $r['domain'])] = true;
        }
        return $map;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim((string) $domain);
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = preg_replace('~^www\.~i', '', $domain);
        $domain = preg_replace('~[/?#].*$~', '', $domain);
        return mb_strtolower(trim($domain));
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host !== null && $host !== false ? (string) $host : '';
    }
}
