<?php

/**
 * Tourfecto - العقل الاستراتيجي: مسودات متابعة العملاء المحتملين المتأخرين
 *
 * لكل عميل محتمل ميعاد متابعته فات (أو ماحدش كلمه من 3 أيام): بيتجهّز نص متابعة من قالب جاهز (من غير ذكاء اصطناعي
 * فمفيش استهلاك من حصة الاشتراك) ويتحط كملاحظة مثبّتة على العميل في الـ CRM، بعلامة واضحة إنها مسودة.
 * مفيش حاجة بتتبعت لحد: صاحب الشركة بيراجع النص وينسخه ويبعته بنفسه من واتساب أو الإيميل.
 * نفس العميل مابيتكتبلوش مسودة تانية قبل 14 يوم، وكل حاجة مقيّدة بحساب المستخدم.
 */
final class BrainLeadFollowups
{
    public const MAX_DRAFTS = 10;
    public const BATCH = 5;
    public const REPEAT_DAYS = 14;
    public const MARK_AR = '[مسودة متابعة من العقل — راجعها قبل ما تبعتها]';
    public const MARK_EN = '[Follow-up draft from the brain — review before sending]';

    private Database $db;
    /** @var callable|null للاختبارات: fn(int $uid, int $leadId, string $body): void */
    private $writer;

    public function __construct(?Database $db = null, ?callable $writer = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->writer = $writer;
    }

    /** نص المتابعة: عربي لو اسم العميل بحروف عربية، وإلا إنجليزي. مفيش وعود بأسعار أو مواعيد. */
    public static function template(string $name, ?string $interest, ?int $daysSilent = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name)) ?: '';
        $interest = trim((string) $interest);
        $arabic = (bool) preg_match('/\p{Arabic}/u', $name . $interest);
        if ($arabic) {
            $hello = $name !== '' ? "أهلًا {$name}،" : 'أهلًا،';
            $topic = $interest !== '' ? " بخصوص {$interest}" : '';
            return self::MARK_AR . "\n{$hello} بنتواصل معاك{$topic}. لسه مهتم؟ لو عندك أي سؤال عن التفاصيل أو المواعيد المتاحة، أنا موجود أرد عليك فورًا.";
        }
        $hello = $name !== '' ? "Hi {$name}," : 'Hi,';
        $topic = $interest !== '' ? " about {$interest}" : '';
        return self::MARK_EN . "\n{$hello} just following up{$topic}. Are you still interested? If you have any questions about the details or available dates, I am happy to help right away.";
    }

    /** العملاء المتأخرين اللي مالهمش مسودة حديثة، الأعلى قيمة الأول */
    private function candidates(int $uid, int $limit): array
    {
        $limit = max(1, min(self::MAX_DRAFTS, $limit));
        return $this->db->query("SELECT l.id, l.interest, c.name,
                DATEDIFF(NOW(), COALESCE(l.last_engagement_at, l.created_at)) AS days_silent
            FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
            WHERE c.user_id = ? AND l.status IN ('new','nurturing','qualified')
              AND ((l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at < NOW())
                OR (l.next_follow_up_at IS NULL AND COALESCE(l.last_engagement_at, l.created_at) < DATE_SUB(NOW(), INTERVAL 3 DAY)))
              AND NOT EXISTS (SELECT 1 FROM crm_notes n WHERE n.user_id = c.user_id AND n.related_type = 'lead' AND n.related_id = l.id
                  AND (n.body LIKE ? OR n.body LIKE ?) AND n.created_at > DATE_SUB(NOW(), INTERVAL " . self::REPEAT_DAYS . " DAY))
            ORDER BY l.value DESC, COALESCE(l.next_follow_up_at, l.created_at) ASC, l.id ASC LIMIT " . $limit,
            [$uid, mb_substr(self::MARK_AR, 0, 12) . '%', mb_substr(self::MARK_EN, 0, 12) . '%']) ?: [];
    }

    private function remaining(int $uid): int
    {
        return count($this->candidates($uid, self::MAX_DRAFTS));
    }

    /** @return array{action:string,without_draft:int,not_on_google:int,ready_drafts:int,will_draft:int,ai_calls_max:int,publishes:bool,review_url:string} */
    public function preview(int $uid): array
    {
        $ready = (int) ($this->db->query("SELECT COUNT(*) AS c FROM crm_notes WHERE user_id = ? AND related_type = 'lead' AND (body LIKE ? OR body LIKE ?)
            AND created_at > DATE_SUB(NOW(), INTERVAL " . self::REPEAT_DAYS . " DAY)", [$uid, mb_substr(self::MARK_AR, 0, 12) . '%', mb_substr(self::MARK_EN, 0, 12) . '%'])[0]['c'] ?? 0);
        $n = $this->remaining($uid);
        return ['action' => 'lead_followups', 'without_draft' => $n, 'not_on_google' => 0, 'ready_drafts' => $ready, 'will_draft' => $n,
            'ai_calls_max' => 0, 'publishes' => false, 'review_url' => '/crm'];
    }

    /** يكتب دفعة مسودات كملاحظات. مابيبعتش حاجة. */
    public function run(int $uid, int $limit = self::BATCH): array
    {
        $lock = 'tourfecto:brain-followups:' . $uid;
        if ((int) ($this->db->query('SELECT GET_LOCK(?, 0) AS l', [$lock])[0]['l'] ?? 0) !== 1) throw new RuntimeException('busy');
        try {
            $drafted = 0;
            $skipped = 0;
            foreach ($this->candidates($uid, min($limit, self::MAX_DRAFTS)) as $r) {
                $body = self::template((string) $r['name'], $r['interest'] ?? null, isset($r['days_silent']) ? (int) $r['days_silent'] : null);
                try {
                    if ($this->writer) call_user_func($this->writer, $uid, (int) $r['id'], $body);
                    else (new CrmNoteService())->create($uid, ['related_type' => 'lead', 'related_id' => (int) $r['id'], 'body' => $body, 'pinned' => 1]);
                    $drafted++;
                } catch (Throwable $e) {
                    $skipped++;
                }
            }
            return ['drafted' => $drafted, 'skipped' => $skipped] + $this->preview($uid);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
