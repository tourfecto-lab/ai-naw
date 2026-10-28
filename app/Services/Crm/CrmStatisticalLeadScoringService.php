<?php

/**
 * Tourfecto - CRM Statistical AI Lead Scoring Service (بند 6)
 * @version 1.0.0
 *
 * طبقة إحصائية شفافة فوق التقييم Rule-based القائم (CrmLeadScoringService)
 * — "إحصائي وليس ML": احتمال تحويل كل Lead يُشتق من معدل التحويل التجريبي
 * الحقيقي لمصدره داخل بيانات الحساب نفسه (leads وصلت لقرار نهائي أو لها
 * صفقة مغلقة)، مع فاصل ثقة Wilson (95%) وحجم عينة صريح.
 *
 * مبادئ صارمة (مرآة لـ CrmForecastService):
 *   - لا اختراع بيانات: لو العينة أقل من حد أدنى، conv_probability = null
 *     صراحةً مع رسالة "بيانات غير كافية" بدل رقم مُختلق (بند 39).
 *   - Additive فقط: الأعمدة الجديدة (conv_probability / score_confidence /
 *     score_signals_json) لا تُعدّل score/priority/score_reason الخاصة
 *     بالتقييم Rule-based القائم إطلاقًا.
 *   - كل رقم يُعرض مع توصيف إحصائي (نسبة، عينة، فاصل ثقة) — لا تُعرض
 *     الاحتمالية كحقيقة مؤكدة.
 */

class CrmStatisticalLeadScoringService
{
    private $db;

    /** الحد الأدنى لعينة المصدر لاعتبار التقدير "موثوقًا" */
    private const MIN_SAMPLE = 10;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // إحصائيات التحويل حسب المصدر (Dashboard)
    // ================================================================

    /**
     * معدلات التحويل التجريبية لكل مصدر داخل بيانات الحساب، مع فاصل
     * Wilson وموثوقية العينة. @return array
     */
    public function sourceConversionStats(int $tenantUserId): array
    {
        $rows = $this->db->query(
            "SELECT c.source AS source,
                    COUNT(*) AS total,
                    SUM(CASE WHEN l.status = 'converted' OR EXISTS (
                            SELECT 1 FROM crm_deals d WHERE d.lead_id = l.id AND d.status = 'won'
                        ) THEN 1 ELSE 0 END) AS converted
             FROM crm_leads l
             JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ?
               AND c.source IS NOT NULL
               AND (l.status IN ('converted','disqualified')
                    OR EXISTS (SELECT 1 FROM crm_deals d WHERE d.lead_id = l.id AND d.status IN ('won','lost')))
             GROUP BY c.source
             ORDER BY total DESC",
            [$tenantUserId]
        );

        $overall = ['source' => null, 'total' => 0, 'converted' => 0];
        $perSource = [];
        foreach ($rows as $row) {
            $total = (int) $row['total'];
            $converted = (int) $row['converted'];
            $overall['total'] += $total;
            $overall['converted'] += $converted;
            $perSource[] = $this->buildStatRow($row['source'], $converted, $total);
        }

        $overallRow = $this->buildStatRow('overall', $overall['converted'], $overall['total']);
        $overallRow['source'] = 'overall';

        return [
            'basis' => 'statistical',
            'min_sample' => self::MIN_SAMPLE,
            'overall' => $overallRow,
            'per_source' => $perSource,
            'note' => 'معدلات تحويل تجريبية من سجل الحساب (قرارات نهائية فقط) - ليست ضمانًا للتحويل',
        ];
    }

    /** يحسب سطرًا إحصائيًا واحدًا (نسبة + فاصل Wilson + موثوقية) */
    private function buildStatRow(string $source, int $converted, int $total): array
    {
        $rate = $total > 0 ? $converted / $total : null;
        $interval = $total > 0 ? $this->wilsonInterval($converted, $total) : null;

        return [
            'source' => $source,
            'total' => $total,
            'converted' => $converted,
            'rate' => $rate !== null ? round($rate, 4) : null,
            'wilson_lower' => $interval !== null ? round($interval['lower'], 4) : null,
            'wilson_upper' => $interval !== null ? round($interval['upper'], 4) : null,
            'reliable' => $total >= self::MIN_SAMPLE,
            'confidence' => $this->confidenceFor($total),
        ];
    }

    // ================================================================
    // تقييم Lead واحد
    // ================================================================

    /**
     * يحسب الطبقة الإحصائية لـ Lead واحد من بيانات الحساب ويخزّنها
     * additively على السجل (لا يمسّ score/priority/score_reason القديمة).
     *
     * @throws Exception لو الـLead غير موجود أو لا يخصّ هذا المستخدم.
     */
    public function scoreLead(int $leadId, int $tenantUserId): CrmLead
    {
        $lead = (new CrmLead())->find($leadId);
        if (!$lead) {
            throw new Exception('Lead غير موجود', 404);
        }

        $contact = $this->db->query(
            "SELECT user_id, source FROM crm_contacts WHERE id = ?",
            [(int) $lead->getAttribute('contact_id')]
        );
        $contact = $contact[0] ?? null;
        if (!$contact || (int) $contact['user_id'] !== $tenantUserId) {
            throw new Exception('لا تملك صلاحية الوصول إلى هذا الـLead', 403);
        }

        $source = $lead->getAttribute('source') ?: ($contact['source'] ?? null);
        $stats = $this->sourceConversionStats($tenantUserId);

        $selected = null;
        foreach ($stats['per_source'] as $row) {
            if ($source !== null && $row['source'] === $source) {
                $selected = $row;
                break;
            }
        }
        if ($selected === null) {
            $selected = $stats['overall'];
        }

        $signals = [
            'source' => $source,
            'sample' => $selected['total'],
            'converted' => $selected['converted'],
            'rate' => $selected['rate'],
            'wilson_lower' => $selected['wilson_lower'],
            'wilson_upper' => $selected['wilson_upper'],
            'reliable' => $selected['reliable'],
            'basis' => 'statistical',
            'label' => 'تقدير إحصائي من معدل تحويل ' . ($source ?: 'إجمالي') . ' الحساب - ليس ضمانًا',
        ];

        // لا نختلق رقمًا: عينة غير كافية → null صراحةً
        if (!$selected['reliable'] || $selected['rate'] === null) {
            $signals['insufficient'] = true;
            $signals['label'] = 'بيانات غير كافية (' . $selected['total'] . ' قرار نهائي فقط - الحد الأدنى ' . self::MIN_SAMPLE . ') - لا يوجد تقدير موثوق بعد';
        }

        $lead->setAttribute('conv_probability', $selected['reliable'] && $selected['rate'] !== null ? $selected['rate'] : null);
        $lead->setAttribute('score_confidence', $selected['reliable'] ? $selected['confidence'] : 'low');
        $lead->setAttribute('score_signals_json', json_encode($signals, JSON_UNESCAPED_UNICODE));
        $lead->save();

        ActivityLog::record('crm', 'lead.scored.statistical', [
            'subject_type' => 'crm_leads', 'subject_id' => $leadId,
            'meta' => ['conv_probability' => $lead->getAttribute('conv_probability'), 'signals' => $signals],
        ]);

        return $lead;
    }

    // ================================================================
    // أدوات إحصائية
    // ================================================================

    /**
     * فاصل ثقة Wilson (95%) لنسبة نجاح — تقريب طبيعي معتاد على العينات
     * الصغيرة، ويُستخدم لأنه لا ينهار عند نسبة 0 أو 1 (خلاف Wald).
     * @return array{lower:float, upper:float}
     */
    public function wilsonInterval(int $successes, int $n): array
    {
        if ($n <= 0) {
            return ['lower' => 0.0, 'upper' => 0.0];
        }
        $z = 1.96; // 95%
        $p = $successes / $n;
        $denom = 1 + ($z * $z) / $n;
        $center = ($p + ($z * $z) / (2 * $n)) / $denom;
        $margin = $z * sqrt(($p * (1 - $p)) / $n + ($z * $z) / (4 * $n * $n)) / $denom;

        return [
            'lower' => max(0.0, $center - $margin),
            'upper' => min(1.0, $center + $margin),
        ];
    }

    private function confidenceFor(int $total): string
    {
        if ($total >= 30) {
            return 'high';
        }
        if ($total >= self::MIN_SAMPLE) {
            return 'moderate';
        }
        return 'low';
    }
}
