<?php

/**
 * Tourfecto - العقل الاستراتيجي: الملخص الأسبوعي (نص نقي من غير قاعدة بيانات ولا ذكاء اصطناعي)
 *
 * بيتبعت بس للشركات اللي فعّلته (مقفول افتراضيًا). كل رقم فيه جاي من الخطة والأهداف والنتايج الحقيقية؛
 * ولو مفيش حاجة تستاهل (لا خطة ولا هدف ولا نتيجة) مابيتبعتش ملخص فاضي.
 */
final class BrainDigest
{
    private const GOAL = [
        'bookings.last_30' => ['حجوزات آخر 30 يوم', 'Bookings, last 30 days'], 'bookings.revenue_30' => ['إيراد آخر 30 يوم', 'Revenue, last 30 days'],
        'reputation.avg_rating' => ['متوسط التقييمات', 'Average rating'], 'reputation.unanswered_negative' => ['تقييمات سلبية من غير رد', 'Unanswered negative reviews'],
        'scores.seo_score' => ['درجة الموقع', 'Site score'], 'social.published_30' => ['منشورات آخر 30 يوم', 'Posts, last 30 days'], 'chat.pending_approval' => ['ردود شات مستنية', 'Chat replies waiting'],
    ];
    private const STATUS = ['achieved' => ['تحقق ✓', 'Achieved ✓'], 'ahead' => ['متقدم', 'Ahead'], 'on_track' => ['على المسار', 'On track'], 'behind' => ['متأخر', 'Behind'], 'missed' => ['فات ميعاده', 'Missed'], 'unknown' => ['مش مقاس', 'Not measured']];
    private const DAY = ['sun' => ['الأحد', 'Sunday'], 'mon' => ['الاثنين', 'Monday'], 'tue' => ['الثلاثاء', 'Tuesday'], 'wed' => ['الأربعاء', 'Wednesday'], 'thu' => ['الخميس', 'Thursday']];

    /**
     * @param array $decisions قرارات الشركة (BrainDecisionStore::list) — عشان عناوينها
     * @return array{subject:string,html:string}|null null لو مفيش حاجة تتبعت
     */
    public static function build(string $company, array $plan, array $goals, array $results, array $decisions, string $lang = 'ar', string $url = ''): ?array
    {
        $en = $lang === 'en';
        $t = static fn (array $p): string => $en ? $p[1] : $p[0];
        $esc = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $titles = [];
        foreach ($decisions as $d) $titles[$d['key'] ?? ''] = $d['title'][$en ? 'en' : 'ar'] ?? '';
        $activeGoals = array_values(array_filter($goals, static fn ($g) => ($g['status'] ?? '') === 'active'));
        $items = $plan['items'] ?? [];
        if (!$items && !$activeGoals && empty($results['completed'])) return null;

        $h = '<div dir="' . ($en ? 'ltr' : 'rtl') . '" style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;color:#1c2430;line-height:1.6">';
        $h .= '<h2 style="margin:0 0 4px">' . $esc($en ? 'Your weekly plan' : 'خطة أسبوعك') . ($company !== '' ? ' — ' . $esc($company) : '') . '</h2>';
        if ($activeGoals) {
            $h .= '<h3>' . $esc($en ? 'Goals' : 'الأهداف') . '</h3><ul>';
            foreach ($activeGoals as $g) {
                $p = $g['progress'] ?? [];
                $name = $t(self::GOAL[$g['metric_key']] ?? [$g['metric_key'], $g['metric_key']]);
                $h .= '<li><b>' . $esc($name) . '</b>: ' . $esc($p['current'] ?? '—') . ' → ' . $esc($g['target']) . ' (' . $esc($t(self::STATUS[$p['status'] ?? 'unknown'] ?? self::STATUS['unknown'])) . ($p['pct'] !== null && isset($p['pct']) ? ', ' . $esc($p['pct']) . '%' : '') . ')</li>';
            }
            $h .= '</ul>';
        }
        if ($items) {
            $h .= '<h3>' . $esc($en ? 'This week' : 'الأسبوع ده') . '</h3><ol>';
            foreach ($items as $i) {
                $title = $titles[$i['key']] ?? $i['key'];
                $h .= '<li>' . $esc($title) . ' <span style="color:#6b7686">(' . $esc($t(self::DAY[$i['day']] ?? [$i['day'], $i['day']])) . ($i['requires_spend'] ? ', ' . ($en ? 'needs your approval to spend' : 'محتاج موافقتك على الصرف') : '') . ')</span></li>';
            }
            $h .= '</ol>';
        }
        if (!empty($results['completed'])) {
            $h .= '<p>' . $esc($en ? sprintf('Decisions that worked: %d of %d completed.', (int) ($results['improved'] ?? 0), (int) $results['completed'])
                : sprintf('قرارات نفعت: %d من %d اتنفّذت.', (int) ($results['improved'] ?? 0), (int) $results['completed'])) . '</p>';
        }
        if ($url !== '') $h .= '<p><a href="' . $esc($url) . '" style="background:#1d6fe8;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">' . $esc($en ? 'Open the brain' : 'افتح العقل الاستراتيجي') . '</a></p>';
        $h .= '<p style="color:#6b7686;font-size:12px">' . $esc($en ? 'You get this because you turned on the weekly summary. You can turn it off from the brain page.' : 'بيوصلك الملخص ده لأنك فعّلته. تقدر توقفه من صفحة العقل.') . '</p></div>';
        return ['subject' => $en ? 'Your weekly plan from the strategic brain' : 'خطة أسبوعك من العقل الاستراتيجي', 'html' => $h];
    }
}
