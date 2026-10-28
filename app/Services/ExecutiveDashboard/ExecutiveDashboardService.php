<?php

/**
 * Tourfecto - Executive Dashboard Service
 * Phase 15. مش Agent جديد - طبقة تجميع فوق كل الـAgents الموجودة، عشان
 * العميل يشوف في مكان واحد: "أين أنا؟ ما حال موقعي؟ أكبر مشكلة/فرصة؟
 * ماذا أفعل الآن؟" بالظبط زي ما السبيك بتطلب في §16.
 *
 * الـ6 درجات بتتحسب من بيانات حقيقية موجودة بالفعل من الـPhases السابقة -
 * مفيش رقم مُختلق. لو مصدر بيانات معيّن لسه فاضي (مثلاً لسه معملش تدقيق
 * SEO)، بترجع null بدل رقم وهمي، والـFrontend يقدر يعرض "لسه محتاج بيانات"
 * بدل رقم مضلل.
 * @version 1.0.0
 */
class ExecutiveDashboardService
{
    /**
     * الدرجات الست: Overall Growth + SEO + Visibility + Competitor + Reputation + Content
     */
    public function getScores(Database $db, int $userId, int $websiteId): array
    {
        $seo = $this->getSeoScore($db, $userId, $websiteId);
        $competitor = $this->getCompetitorScore($db, $userId, $websiteId);
        $reputation = $this->getReputationScore($db, $userId, $websiteId);
        $content = $this->getContentScore($db, $userId, $websiteId);
        $visibility = $this->getVisibilityScore($db, $userId, $websiteId);

        $available = array_filter([$seo, $competitor, $reputation, $content, $visibility], fn ($s) => $s !== null);
        $overall = empty($available) ? null : round(array_sum($available) / count($available), 1);

        return [
            'overall_growth_score' => $overall,
            'seo_score' => $seo,
            'visibility_score' => $visibility,
            'competitor_score' => $competitor,
            'reputation_score' => $reputation,
            'content_score' => $content,
        ];
    }

    private function getSeoScore(Database $db, int $userId, int $websiteId): ?float
    {
        try {
            $rows = $db->query(
                "SELECT overall_score FROM wo_audits WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
                [$websiteId, $userId]
            );
            return isset($rows[0]) ? (float) $rows[0]['overall_score'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getCompetitorScore(Database $db, int $userId, int $websiteId): ?float
    {
        try {
            $rows = $db->query(
                "SELECT AVG(my_score) AS avg_score FROM competitors WHERE website_id = ? AND user_id = ? AND last_analyzed_at IS NOT NULL",
                [$websiteId, $userId]
            );
            return isset($rows[0]['avg_score']) && $rows[0]['avg_score'] !== null ? round((float) $rows[0]['avg_score'], 1) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * درجة السمعة (0-100) محسوبة من متوسط التقييم الحقيقي (0-5) + نسبة
     * المراجعات الإيجابية - مش رقم من عند الذكاء الاصطناعي.
     */
    private function getReputationScore(Database $db, int $userId, int $websiteId): ?float
    {
        try {
            $rows = $db->query(
                "SELECT COUNT(*) AS total, AVG(rating) AS avg_rating,
                        SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) AS positive
                 FROM reviews WHERE website_id = ? AND user_id = ?",
                [$websiteId, $userId]
            );
            $total = (int) ($rows[0]['total'] ?? 0);
            if ($total === 0) {
                return null;
            }

            $avgRating = (float) $rows[0]['avg_rating'];
            $positivePct = ((int) $rows[0]['positive']) / $total;

            $score = ($avgRating / 5) * 70 + $positivePct * 30;
            return round(min(100, max(0, $score)), 1);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * درجة المحتوى (0-100) محسوبة من عدد المقالات المنشورة فعليًا + وجود
     * FAQs (إشارة جودة إضافية من Phase 8) - مقياس بسيط لكن حقيقي.
     */
    private function getContentScore(Database $db, int $userId, int $websiteId): ?float
    {
        try {
            $rows = $db->query(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN faqs_json IS NOT NULL AND faqs_json != '[]' THEN 1 ELSE 0 END) AS with_faqs
                 FROM ai_articles WHERE website_id = ? AND user_id = ? AND status = 'completed'",
                [$websiteId, $userId]
            );
            $total = (int) ($rows[0]['total'] ?? 0);
            if ($total === 0) {
                return null;
            }

            $withFaqs = (int) ($rows[0]['with_faqs'] ?? 0);
            // 10 مقالات منشورة = 70 نقطة أساسية (سقف)، + مكافأة لو نسبة كبيرة منهم فيها FAQs
            $baseScore = min(70, $total * 7);
            $faqBonus = $total > 0 ? ($withFaqs / $total) * 30 : 0;
            return round(min(100, $baseScore + $faqBonus), 1);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * درجة الظهور (0-100) محسوبة من نسبة الكلمات المفتاحية المتابَعة اللي
     * ليها صفحة مستهدفة محددة + متوسط درجة الفرصة (Phase 6) - مش ترتيب
     * فعلي في جوجل (محتاج تكامل GSC كامل مش موجود بعد - موضّح في القيود).
     */
    private function getVisibilityScore(Database $db, int $userId, int $websiteId): ?float
    {
        try {
            $rows = $db->query(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN target_page IS NOT NULL AND target_page != '' THEN 1 ELSE 0 END) AS with_target,
                        AVG(opportunity_score) AS avg_opportunity
                 FROM tracked_keywords WHERE website_id = ? AND user_id = ?",
                [$websiteId, $userId]
            );
            $total = (int) ($rows[0]['total'] ?? 0);
            if ($total === 0) {
                return null;
            }

            $withTargetPct = ((int) $rows[0]['with_target']) / $total;
            $avgOpportunity = (float) ($rows[0]['avg_opportunity'] ?? 0);

            $score = $withTargetPct * 50 + ($avgOpportunity / 100) * 50;
            return round(min(100, max(0, $score)), 1);
        } catch (Exception $e) {
            return null;
        }
    }

    /** Top 5 فرص حقيقية - من فرص النمو اليدوية (Phase 11) + أعلى الكلمات المفتاحية فرصة (Phase 6) */
    public function getTopOpportunities(Database $db, int $userId, int $websiteId, int $limit = 5): array
    {
        $items = [];
        try {
            $rows = $db->query(
                "SELECT title, estimated_impact AS impact FROM ceo_growth_opportunities WHERE user_id = ? AND status NOT IN ('done','dismissed') LIMIT ?",
                [$userId, $limit]
            );
            foreach ($rows as $r) {
                $items[] = ['title' => $r['title'], 'impact' => $r['impact'], 'source' => 'growth_opportunity'];
            }
        } catch (Exception $e) {
        }

        try {
            $rows = $db->query(
                "SELECT keyword, opportunity_score FROM tracked_keywords WHERE website_id = ? AND user_id = ? AND priority = 'high' ORDER BY opportunity_score DESC LIMIT ?",
                [$websiteId, $userId, $limit]
            );
            foreach ($rows as $r) {
                $items[] = ['title' => "استهدف الكلمة \"{$r['keyword']}\"", 'impact' => $r['opportunity_score'] >= 70 ? 'high' : 'medium', 'source' => 'keyword'];
            }
        } catch (Exception $e) {
        }

        usort($items, fn ($a, $b) => ($b['impact'] === 'high' ? 1 : 0) <=> ($a['impact'] === 'high' ? 1 : 0));
        return array_slice($items, 0, $limit);
    }

    /** Top 5 مشاكل حقيقية - من أخطر نتائج تدقيق SEO + المخاطر المفتوحة اليدوية */
    public function getTopProblems(Database $db, int $userId, int $websiteId, int $limit = 5): array
    {
        $items = [];
        try {
            $rows = $db->query(
                "SELECT f.title, f.severity FROM wo_audit_findings f
                 INNER JOIN wo_audits a ON a.id = f.audit_id
                 WHERE a.website_id = ? AND a.user_id = ? AND f.status IN ('fail','warn')
                 ORDER BY a.completed_at DESC, FIELD(f.severity,'critical','high','medium','low') LIMIT ?",
                [$websiteId, $userId, $limit]
            );
            foreach ($rows as $r) {
                $items[] = ['title' => $r['title'], 'severity' => $r['severity'], 'source' => 'seo_finding'];
            }
        } catch (Exception $e) {
        }

        try {
            $rows = $db->query(
                "SELECT title, severity FROM ceo_risk_alerts WHERE user_id = ? AND is_resolved = 0 LIMIT ?",
                [$userId, $limit]
            );
            foreach ($rows as $r) {
                $items[] = ['title' => $r['title'], 'severity' => $r['severity'], 'source' => 'risk_alert'];
            }
        } catch (Exception $e) {
        }

        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($items, fn ($a, $b) => ($order[$a['severity']] ?? 2) <=> ($order[$b['severity']] ?? 2));
        return array_slice($items, 0, $limit);
    }

    /** آخر 5 تغييرات فعلية طُبّقت (Auto Pilot - Phase 13) */
    public function getRecentChanges(Database $db, int $userId, int $websiteId, int $limit = 5): array
    {
        try {
            $rows = $db->query(
                "SELECT l.field_name, l.old_value, l.new_value, l.trigger, l.applied_at
                 FROM auto_pilot_change_log l
                 INNER JOIN generated_websites g ON g.id = l.generated_website_id
                 WHERE g.user_id = ? AND l.rolled_back_at IS NULL
                 ORDER BY l.applied_at DESC LIMIT ?",
                [$userId, $limit]
            );
            return $rows;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * مقارنة المنافسين الحالية - ملحوظة صادقة: مفيش تتبع تاريخي (Snapshot
     * واحد بس محفوظ لكل منافس، مش سلسلة زمنية)، فـ"حركة المنافس" الفعلية
     * (تحسّن/تراجع بمرور الوقت) مش متاحة لسه - محتاجة جدول تاريخي منفصل.
     */
    public function getCompetitorSnapshot(Database $db, int $userId, int $websiteId): array
    {
        try {
            return $db->query(
                "SELECT competitor_name, competitor_domain, my_score, competitor_score, last_analyzed_at
                 FROM competitors WHERE website_id = ? AND user_id = ? AND last_analyzed_at IS NOT NULL
                 ORDER BY last_analyzed_at DESC LIMIT 5",
                [$websiteId, $userId]
            );
        } catch (Exception $e) {
            return [];
        }
    }
}
