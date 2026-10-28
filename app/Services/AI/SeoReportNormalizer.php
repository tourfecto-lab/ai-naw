<?php

/**
 * Tourfecto - SEO/AEO/GEO Report Normalizer
 *
 * الذكاء الاصطناعي مش بيلتزم بأسماء الحقول: بيرجّع مثلًا target_keywords
 * بدل keywords و direct_answer_blocks بدل direct_answers، فالتقرير كان
 * بيطلع نصه فاضي رغم إن البيانات موجودة في الرد. هنا بنرجّع كل اسم بديل
 * للاسم اللي الكود والواجهة مستنيينه، وبنحوّل العناصر اللي جاية objects
 * لنص مقروء بدل ما تظهر للعميل كـ JSON.
 *
 * منطق خالص بدون DB.
 */
class SeoReportNormalizer
{
    /** القسم => الحقل => أسماء بديلة شافها الذكاء الاصطناعي بيرجّعها */
    private const ALIASES = [
        'seo' => [
            'keywords' => ['target_keywords', 'keyword_opportunities', 'primary_keywords', 'keyword_targets', 'recommended_keywords'],
            'title_suggestions' => ['title_tag_suggestions', 'titles', 'suggested_titles'],
            'meta_suggestions' => ['meta_description_suggestions', 'meta_descriptions', 'suggested_meta'],
            'content_gaps' => ['content_gap_analysis', 'gaps', 'missing_content'],
        ],
        'aeo' => [
            'direct_answers' => ['direct_answer_blocks', 'direct_answer_snippets', 'answer_blocks', 'featured_snippets'],
            'trust_signals' => ['trust_signals_action_plan', 'trust_signal_recommendations', 'eeat_signals'],
            'positioning_strategy' => ['entity_positioning_strategy', 'positioning', 'direct_answer_strategy'],
        ],
        'geo' => [
            'questions_generated' => ['generated_faqs', 'generated_questions', 'questions', 'faqs'],
            'map_integration' => ['maps_integration_recommendations', 'map_integration_recommendations', 'maps_integration'],
            'improvement_suggestions' => ['local_optimization_proposals', 'improvements', 'recommendations', 'local_seo_recommendations'],
        ],
    ];

    /** حقول نصية (مش قوائم) */
    private const TEXT_FIELDS = ['positioning_strategy', 'improvement_suggestions'];

    public static function normalize(array $data): array
    {
        foreach (self::ALIASES as $section => $fields) {
            if (!isset($data[$section]) || !is_array($data[$section])) {
                continue;
            }
            foreach ($fields as $field => $aliases) {
                if (self::isEmpty($data[$section][$field] ?? null)) {
                    foreach ($aliases as $alias) {
                        if (!self::isEmpty($data[$section][$alias] ?? null)) {
                            $data[$section][$field] = $data[$section][$alias];
                            break;
                        }
                    }
                }
                if (!array_key_exists($field, $data[$section])) {
                    continue;
                }
                $data[$section][$field] = in_array($field, self::TEXT_FIELDS, true)
                    ? self::toText($data[$section][$field])
                    : self::toList($data[$section][$field]);
            }
        }
        return $data;
    }

    /**
     * أعمدة جدول ai_reports من التقرير الكامل - عشان التقارير القديمة اللي
     * اتحفظت فاضية تظهر صح من غير ما العميل يدفع تحليل جديد.
     */
    public static function reportColumns(array $full): array
    {
        $full = self::normalize($full);
        return [
            'seo_keywords' => $full['seo']['keywords'] ?? [],
            'seo_title_suggestions' => $full['seo']['title_suggestions'] ?? [],
            'seo_meta_suggestions' => $full['seo']['meta_suggestions'] ?? [],
            'seo_content_gaps' => $full['seo']['content_gaps'] ?? [],
            'aeo_direct_answers' => $full['aeo']['direct_answers'] ?? [],
            'aeo_trust_signals' => $full['aeo']['trust_signals'] ?? [],
            'aeo_positioning_strategy' => $full['aeo']['positioning_strategy'] ?? '',
            'geo_questions_generated' => $full['geo']['questions_generated'] ?? [],
            'geo_map_integration' => $full['geo']['map_integration'] ?? [],
            'geo_improvement_suggestions' => $full['geo']['improvement_suggestions'] ?? '',
        ];
    }

    /** يكمّل الأعمدة الفاضية في صف تقرير من full_report_json */
    public static function fillReportRow(array $row): array
    {
        $full = $row['full_report_json'] ?? null;
        if (is_string($full)) {
            $full = json_decode($full, true);
        }
        if (!is_array($full)) {
            return $row;
        }
        foreach (self::reportColumns($full) as $col => $value) {
            if (self::isEmpty($row[$col] ?? null) || (is_array($row[$col]) && self::hasObjects($row[$col]))) {
                $row[$col] = $value;
            }
        }
        if (empty($row['keywords_found']) && is_array($row['seo_keywords'] ?? null)) {
            $row['keywords_found'] = count($row['seo_keywords']);
        }
        return $row;
    }

    /** Present historical records honestly without changing stored status, score or billing. */
    public static function present(array $row): array
    {
        foreach (array_keys(self::reportColumns([])) as $field) {
            if (!is_string($row[$field] ?? null)) continue;
            $decoded = json_decode($row[$field], true);
            if (json_last_error() === JSON_ERROR_NONE) $row[$field] = $decoded;
        }
        $row = self::fillReportRow($row);
        $meaningful = false;
        foreach (['seo_keywords','seo_title_suggestions','seo_meta_suggestions','seo_content_gaps','aeo_direct_answers','aeo_trust_signals','aeo_positioning_strategy','geo_faq_schema','geo_questions_generated','geo_map_integration','geo_improvement_suggestions'] as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value)) { $decoded = json_decode($value, true); if (json_last_error() === JSON_ERROR_NONE) $value = $decoded; }
            if (is_array($value) ? count(array_filter($value, static fn($x) => $x !== null && $x !== '' && $x !== [])) > 0 : (is_string($value) && trim($value) !== '')) { $meaningful = true; break; }
        }
        $state = ($row['status'] ?? '') === 'completed' && !$meaningful ? 'incomplete' : ($row['status'] ?? 'pending');
        $score = $row['analysis_score'] ?? null;
        $score = $meaningful && is_numeric($score) && (float)$score >= 0 && (float)$score <= 100 ? (float)$score : null;
        $row['presentation'] = ['state'=>$state, 'score'=>$score, 'message'=>$state === 'incomplete' ? 'This saved report has no usable analysis. No new analysis or credit charge has been started. Contact support with this report number.' : ($score === null && $meaningful ? 'The analysis is available, but its score was not measured.' : null)];
        return $row;
    }

    private static function isEmpty($v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    private static function hasObjects(array $list): bool
    {
        foreach ($list as $item) {
            if (is_array($item)) {
                return true;
            }
        }
        return false;
    }

    private static function toList($value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $text = self::itemText($item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    private static function toText($value): string
    {
        if (is_array($value)) {
            return implode("\n", self::toList($value));
        }
        return trim((string) $value);
    }

    /** عنصر واحد لنص مقروء: سؤال + إجابة، كلمة + أولوية، أو القيم كلها */
    private static function itemText($item): string
    {
        if (!is_array($item)) {
            return is_scalar($item) ? trim((string) $item) : '';
        }
        $pick = static function (array $keys) use ($item): string {
            foreach ($keys as $k) {
                if (isset($item[$k]) && is_scalar($item[$k]) && trim((string) $item[$k]) !== '') {
                    return trim((string) $item[$k]);
                }
            }
            return '';
        };

        $question = $pick(['question', 'query', 'name', 'q']);
        $answer = $pick(['direct_answer', 'answer', 'text', 'a', 'acceptedAnswer']);
        if ($question !== '' && $answer !== '') {
            return $question . ' — ' . $answer;
        }

        $keyword = $pick(['keyword', 'term', 'phrase']);
        if ($keyword !== '') {
            $extra = array_filter([$pick(['intent']), $pick(['priority', 'volume'])]);
            return $keyword . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
        }

        $parts = [];
        foreach ($item as $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $parts[] = trim((string) $v);
            }
        }
        return implode(' — ', $parts);
    }
}
