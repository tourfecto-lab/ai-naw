<?php

/**
 * Tourfecto - Action Center Service
 * Phase 12. مش هيبني منطق كتابة جديد خالص - كل التوصيات دي أصلًا بتتحفظ
 * وبتتحدّث عن طريق الـEndpoints الموجودة بالفعل من الـPhases اللي قبل كده
 * (Website Optimizer Phase 5, Outreach Phase 10, CEO Advisor/Executive
 * Extras Phase 11). هنا بس بنجمعهم في قايمة موحّدة واحدة ومرتبة بالأولوية،
 * عشان العميل يشوف "ماذا أفعل الآن؟" في مكان واحد بدل ما يقلّب بين كذا
 * صفحة، بالظبط زي ما السبيك بتطلب في §17.
 *
 * كل عنصر راجع بشكل موحّد فيه:
 * source, id, title, description, category, priority, status, created_at,
 * action_type, action_hint (تلميح للـFrontend عن الـEndpoint المستخدم فعلًا
 * لتنفيذ/رفض العنصر ده - مفيش تكرار لمنطق موجود ومُختبر بالفعل).
 * @version 1.0.0
 */
class ActionCenterService
{
    /**
     * @param Database $db
     * @param int $userId
     * @param int|null $websiteId لو محدد، بيفلتر العناصر المرتبطة بموقع معيّن بس (مش كل العناصر ليها website_id مباشر زي المهام اليدوية)
     * @return array قايمة موحّدة، مرتبة: critical/high أولًا، الأحدث أولًا داخل نفس الأولوية
     */
    public function getActionItems(Database $db, int $userId, ?int $websiteId = null): array
    {
        $items = [];

        $items = array_merge($items, $this->getPendingSeoFixes($db, $userId, $websiteId));
        $items = array_merge($items, $this->getDraftOutreachEmails($db, $userId, $websiteId));
        $items = array_merge($items, $this->getStaleOutreachFollowups($db, $userId, $websiteId));
        $items = array_merge($items, $this->getManualTasks($db, $userId));
        $items = array_merge($items, $this->getRiskAlerts($db, $userId));
        $items = array_merge($items, $this->getGrowthOpportunities($db, $userId));
        $items = array_merge($items, $this->getCompetitorInsights($db, $userId));
        $items = array_merge($items, $this->getMarketingAssistantItems($db, $userId));

        usort($items, function ($a, $b) {
            $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            $pa = $order[$a['priority']] ?? 2;
            $pb = $order[$b['priority']] ?? 2;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return $items;
    }

    private function getPendingSeoFixes(Database $db, int $userId, ?int $websiteId): array
    {
        try {
            $sql = "SELECT f.id, f.title, f.description, f.category, f.created_at, a.website_id
                    FROM wo_fixes f
                    INNER JOIN wo_audits a ON a.id = f.audit_id
                    WHERE a.user_id = ? AND f.status = 'pending'";
            $bindings = [$userId];
            if ($websiteId) {
                $sql .= " AND a.website_id = ?";
                $bindings[] = $websiteId;
            }
            $sql .= " ORDER BY f.id DESC LIMIT 20";

            $rows = $db->query($sql, $bindings);
            return array_map(fn ($r) => [
                'source' => 'website_optimizer',
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'description' => $r['description'],
                'category' => $r['category'] ?: 'seo',
                'priority' => 'medium', // wo_fixes مفيهاش severity مباشر - أولوية موحّدة مبدئيًا (تحسين مستقبلي: ربطها بـwo_audit_findings.severity عن طريق check_key)
                'status' => 'pending',
                'created_at' => $r['created_at'],
                'action_type' => 'website_optimizer_fix',
                'action_hint' => 'POST /api/website-optimizer/fixes/' . $r['id'] . '/status (أو /apply-auto لو موقع Website Builder بتاعك)',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getDraftOutreachEmails(Database $db, int $userId, ?int $websiteId): array
    {
        try {
            $sql = "SELECT e.id, e.subject, p.domain, e.created_at, p.website_id
                    FROM outreach_emails e
                    INNER JOIN outreach_prospects p ON p.id = e.prospect_id
                    WHERE p.user_id = ? AND e.status = 'draft'";
            $bindings = [$userId];
            if ($websiteId) {
                $sql .= " AND p.website_id = ?";
                $bindings[] = $websiteId;
            }
            $sql .= " ORDER BY e.id DESC LIMIT 20";

            $rows = $db->query($sql, $bindings);
            return array_map(fn ($r) => [
                'source' => 'outreach',
                'id' => (int) $r['id'],
                'title' => 'راجع رسالة التواصل مع ' . $r['domain'],
                'description' => 'الموضوع: ' . $r['subject'],
                'category' => 'outreach',
                'priority' => 'medium',
                'status' => 'draft',
                'created_at' => $r['created_at'],
                'action_type' => 'outreach_email_draft',
                'action_hint' => 'POST /api/outreach/emails/' . $r['id'] . '/edit ثم /approve ثم /send',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * مرشّحين تم التواصل معاهم من أكتر من 5 أيام من غير أي تحديث حالة (رد/رفض) -
     * فرصة "متابعة" حقيقية، مش تخمين.
     */
    private function getStaleOutreachFollowups(Database $db, int $userId, ?int $websiteId): array
    {
        try {
            $sql = "SELECT id, domain, updated_at, website_id
                    FROM outreach_prospects
                    WHERE user_id = ? AND status = 'contacted' AND updated_at <= DATE_SUB(NOW(), INTERVAL 5 DAY)";
            $bindings = [$userId];
            if ($websiteId) {
                $sql .= " AND website_id = ?";
                $bindings[] = $websiteId;
            }
            $sql .= " ORDER BY updated_at ASC LIMIT 20";

            $rows = $db->query($sql, $bindings);
            return array_map(fn ($r) => [
                'source' => 'outreach',
                'id' => (int) $r['id'],
                'title' => 'ابعت متابعة لـ' . $r['domain'],
                'description' => 'اتواصلنا معاهم من أكتر من 5 أيام من غير رد - وقت مناسب لمتابعة لطيفة',
                'category' => 'outreach',
                'priority' => 'low',
                'status' => 'contacted',
                'created_at' => $r['updated_at'],
                'action_type' => 'outreach_followup',
                'action_hint' => 'POST /api/outreach/emails/generate { prospect_id, sequence_number: 1 }',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getManualTasks(Database $db, int $userId): array
    {
        try {
            $rows = $db->query("SELECT id, title, created_at FROM cc_ai_tasks WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 20", [$userId]);
            return array_map(fn ($r) => [
                'source' => 'manual', 'id' => (int) $r['id'], 'title' => $r['title'], 'description' => null,
                'category' => 'general', 'priority' => 'medium', 'status' => 'open', 'created_at' => $r['created_at'],
                'action_type' => 'manual_task', 'action_hint' => 'POST /api/executive/tasks/' . $r['id'] . '/complete',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getRiskAlerts(Database $db, int $userId): array
    {
        try {
            $rows = $db->query("SELECT id, title, description, severity, created_at FROM ceo_risk_alerts WHERE user_id = ? AND is_resolved = 0 ORDER BY FIELD(severity,'critical','high','medium','low') LIMIT 20", [$userId]);
            return array_map(fn ($r) => [
                'source' => 'ceo_advisor', 'id' => (int) $r['id'], 'title' => $r['title'], 'description' => $r['description'],
                'category' => 'risk', 'priority' => $r['severity'], 'status' => 'open', 'created_at' => $r['created_at'],
                'action_type' => 'risk_alert', 'action_hint' => 'POST /api/executive/alerts/' . $r['id'] . '/read (بعد ما تحلها)',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getGrowthOpportunities(Database $db, int $userId): array
    {
        try {
            $rows = $db->query("SELECT id, title, description, estimated_impact, created_at FROM ceo_growth_opportunities WHERE user_id = ? AND status NOT IN ('done','dismissed') ORDER BY FIELD(estimated_impact,'high','medium','low') LIMIT 20", [$userId]);
            return array_map(fn ($r) => [
                'source' => 'ceo_advisor', 'id' => (int) $r['id'], 'title' => $r['title'], 'description' => $r['description'],
                'category' => 'growth', 'priority' => $r['estimated_impact'], 'status' => 'new', 'created_at' => $r['created_at'],
                'action_type' => 'growth_opportunity', 'action_hint' => null,
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * رؤى/تهديدات/فرص/توصيات Competitor Intelligence الجديدة (status = 'new')
     * - مفيش جدول محلي => صفر (مقاومة للبيئات الناقصة).
     */
    private function getCompetitorInsights(Database $db, int $userId): array
    {
        try {
            $rows = $db->query(
                "SELECT id, competitor_id, type, title, description, confidence, threat_level,
                        recommended_action, created_at
                   FROM ci_insights
                  WHERE user_id = ? AND status = 'new'
                    AND type IN ('threat','opportunity','recommendation')
                  ORDER BY FIELD(threat_level,'high','medium','low'), id DESC LIMIT 20",
                [$userId]
            );
            return array_map(fn ($r) => [
                'source' => 'competitor',
                'id' => (int) $r['id'],
                'title' => $r['title'],
                'description' => trim((string) ($r['recommended_action'] ?? '') . "\n" . (string) $r['description']),
                'category' => $r['type'],
                'priority' => $r['threat_level'] ?: ($r['confidence'] ?: 'medium'),
                'status' => 'new',
                'created_at' => $r['created_at'],
                'action_type' => 'competitor_insight',
                'action_hint' => null,
                'competitor_id' => $r['competitor_id'] ? (int) $r['competitor_id'] : null,
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * آخر نواتج Marketing Assistant (آخر 7 أيام) كإجراءات قابلة للتنفيذ -
     * الناتج الأهم هو توليد محتوى تسويقي، بيتهيّأ للمتابعة على شكل مهمة.
     * (مفيش عمود status في الجدول - dedup بيعتمد على id كل تفاعل)
     */
    private function getMarketingAssistantItems(Database $db, int $userId): array
    {
        try {
            $rows = $db->query(
                "SELECT id, type, title, output, created_at
                   FROM ai_assistant_interactions
                  WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  ORDER BY id DESC LIMIT 20",
                [$userId]
            );
            return array_map(fn ($r) => [
                'source' => 'marketing',
                'id' => (int) $r['id'],
                'title' => 'نفّذ المحتوى التسويقي: ' . ($r['title'] ?: $r['type']),
                'description' => mb_substr((string) $r['output'], 0, 400),
                'category' => 'marketing',
                'priority' => 'medium',
                'status' => 'new',
                'created_at' => $r['created_at'],
                'action_type' => 'marketing_output',
                'action_hint' => 'انشر المحتوى أو استخدمه في حملة - ثم أكمله كمهمة هنا',
            ], $rows);
        } catch (Exception $e) {
            return [];
        }
    }
}
