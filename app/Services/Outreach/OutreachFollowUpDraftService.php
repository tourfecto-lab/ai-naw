<?php

/**
 * Tourfecto - Outreach Follow-Up Draft Service (Item 2b)
 * @version 1.0.0
 *
 * بيولّد مسودات متابعة (draft ONLY - ممنوع الإرسال التلقائي) للمرشّحين
 * اللي مرّ على آخر رسالة مُرسلة ليهم 7 أيام أو أكثر وما زالوا في مراحل
 * نشطة (contacted/replied/negotiating - مش declined أو link_acquired).
 *
 * القواعد:
 * - مش بيبعث حاجة أبدًا - بس بيولّد مسودة بالـ sequence التالي ويحفظها
 *   بـ status='draft'، وبيبلّغ المستخدم إن فيه مسودة جاهزة للمراجعة.
 * - بيراعي التسلسل: بيحسب أقصى sequence موجود ويزيد عليه واحد، وبيكف
 *   عند حد أقصى 3 متابعات لكل مرشّح (sequence 1/2/3) زي ما
 *   OutreachEmailGenerator مصمّم.
 * - Idempotent: لو فيه مسودة/متابعة موجودة بالفعل للـ sequence ده،
 *   بيتخطّى المرشّح (لا تكرار).
 */
class OutreachFollowUpDraftService
{
    private const FOLLOW_UP_AFTER_DAYS = 7;
    private const MAX_FOLLOW_UPS = 3;

    /** @var OutreachEmailGenerator|null */
    private $generator;

    public function __construct(?OutreachEmailGenerator $generator = null)
    {
        $this->generator = $generator;
    }

    /**
     * توليد مسودات المتابعة المستحقة حاليًا.
     * @param int $limit
     * @return array{generated:int, skipped:int, failed:int, drafts:array}
     */
    public function generateDueFollowUps(int $limit = 50): array
    {
        $db = Database::getInstance();

        // المرشّحون المستحقون: عندهم رسالة مُرسلة من 7 أيام أو أكثر،
        // الحالة نشطة، وأقصى sequence عندهم أقل من الحد الأقصى.
        $prospects = $db->query(
            "SELECT p.id, p.user_id, p.website_id, p.domain, p.contact_name,
                    p.business_type, p.relevant_page, p.collaboration_idea,
                    COALESCE(MAX(e.sequence_number), 0) AS max_seq
             FROM outreach_prospects p
             JOIN outreach_emails e ON e.prospect_id = p.id
             WHERE p.status IN ('contacted', 'replied', 'negotiating')
               AND e.status = 'sent'
               AND e.sent_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY p.id, p.user_id, p.website_id, p.domain, p.contact_name,
                      p.business_type, p.relevant_page, p.collaboration_idea
             HAVING max_seq < ?
             ORDER BY p.id ASC
             LIMIT ?",
            [self::FOLLOW_UP_AFTER_DAYS, self::MAX_FOLLOW_UPS, $limit]
        );

        $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'drafts' => []];
        $notifiedUserIds = [];

        foreach ($prospects as $prospect) {
            $prospectId = (int) $prospect['id'];
            $nextSequence = (int) $prospect['max_seq'] + 1;

            // Idempotency: لو في رسالة (أي حالة) بالـ sequence ده، نتخطّى
            $existing = $db->query(
                'SELECT id FROM outreach_emails WHERE prospect_id = ? AND sequence_number = ? LIMIT 1',
                [$prospectId, $nextSequence]
            );
            if (!empty($existing)) {
                $stats['skipped']++;
                continue;
            }

            $website = (new Website())->find((int) $prospect['website_id']);
            $myWebsite = [
                'company_name' => $website ? $website->getAttribute('company_name') : null,
                'main_url' => $website ? $website->getAttribute('main_url') : null,
                'industry' => $website ? $website->getAttribute('industry') : null,
            ];

            try {
                $generator = $this->generator ?? new OutreachEmailGenerator();
                $result = $generator->generate($prospect, $myWebsite, $nextSequence);
                if (!($result['success'] ?? false)) {
                    $stats['failed']++;
                    continue;
                }

                $email = new OutreachEmail([
                    'prospect_id' => $prospectId,
                    'sequence_number' => $nextSequence,
                    'subject' => (string) $result['data']['subject'],
                    'body' => (string) $result['data']['body'],
                    'status' => 'draft',
                ]);
                $email->save();
                $stats['generated']++;
                $stats['drafts'][] = $email->toArray();
                $notifiedUserIds[(int) $prospect['user_id']] = true;
            } catch (Throwable $e) {
                $stats['failed']++;
                if (class_exists('Logger')) {
                    Logger::warning('Outreach follow-up draft generation failed', ['prospect_id' => $prospectId, 'error' => $e->getMessage()]);
                }
            }
        }

        // إشعار لكل مستخدم اتعمل ليه متابعة: مسودة جاهزة للمراجعة
        foreach (array_keys($notifiedUserIds) as $userId) {
            Notification::notify(
                (int) $userId,
                'outreach_followup_draft',
                'مسودات متابعة Outreach جاهزة للمراجعة',
                'اتولدت مسودات متابعة جديدة - راجعها ووافق عليها قبل الإرسال',
                '/outreach'
            );
        }

        return $stats;
    }
}
