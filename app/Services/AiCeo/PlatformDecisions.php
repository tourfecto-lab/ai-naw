<?php

/**
 * TOURFECTO AI CEO — قرارات إدارة شركة Tourfecto من صورة المنصة (PlatformSnapshot). منطق نقي من غير قاعدة بيانات.
 *
 * كل قرار ليه دليل من أرقام حقيقية، ومكان التنفيذ (صفحة أدمن موجودة)، ودرجات من 1 لـ 5:
 *   impact (أثر على الشركة) · roi (العائد المتوقع) · urgency (الاستعجال) · confidence (ثقة البيانات)
 *   risk (المخاطرة) · cost (التكلفة) · effort (المجهود)
 * الأولوية = 10 × (0.30 impact + 0.20 roi + 0.25 urgency + 0.10 confidence + 0.05 (6−risk) + 0.05 (6−cost) + 0.05 (6−effort))
 * يعني من 10 لـ 50. الاعتماديات (dependencies) بتتكتب في القرار وبتأخّر التنفيذ مش الترتيب.
 *
 * مستوى الخطر:
 *   low    = شغل داخلي (تحليل، مهمة للفريق، تنبيه). ده بس اللي ينفع يتنفّذ تلقائي في AUTO MODE.
 *   medium = تواصل مع عملاء أو شركات بره المنصة → موافقة صاحب المنصة دايمًا.
 *   high   = فلوس، أسعار، باقات، صلاحيات، أو نشر على Production → موافقة صريحة دايمًا، حتى في AUTO MODE.
 */
final class PlatformDecisions
{
    public const RISK_LEVELS = ['low', 'medium', 'high'];

    public static function priority(array $s): float
    {
        $v = static fn (string $k) => max(1, min(5, (int) ($s[$k] ?? 3)));
        return round(10 * (0.30 * $v('impact') + 0.20 * $v('roi') + 0.25 * $v('urgency') + 0.10 * $v('confidence')
            + 0.05 * (6 - $v('risk')) + 0.05 * (6 - $v('cost')) + 0.05 * (6 - $v('effort'))), 2);
    }

    /** @return array<int,array> مرتبة بالأولوية */
    public static function decide(array $snap): array
    {
        $out = [];
        $add = static function (string $key, string $area, string $risk, array $scores, array $title, array $why, array $evidence, string $url, array $action, array $deps = []) use (&$out): void {
            $out[] = ['key' => $key, 'area' => $area, 'risk' => $risk, 'scores' => $scores, 'priority' => self::priority($scores),
                'title' => ['ar' => $title[0], 'en' => $title[1]], 'why' => ['ar' => $why[0], 'en' => $why[1]],
                'evidence' => array_map(static fn ($e) => ['ar' => $e[0], 'en' => $e[1]], $evidence),
                'action_url' => $url, 'action' => ['ar' => $action[0], 'en' => $action[1]], 'dependencies' => $deps,
                'auto_ok' => $risk === 'low', 'requires_approval' => $risk !== 'low'];
        };
        $n = static fn ($v) => number_format((float) $v, (float) $v == (int) $v ? 0 : 2);

        // البيانات نفسها: قسم مش متاح = القرارات اللي بتعتمد عليه مش هتطلع، وده لازم يتقال
        $missing = array_keys(array_filter(array_diff_key($snap, ['generated_at' => 1]), static fn ($v) => $v === null));
        if ($missing) {
            $add('data_sources_missing', 'data', 'low', ['impact' => 3, 'roi' => 3, 'urgency' => 3, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                ['مصادر بيانات مش متاحة للعقل', 'Some data sources are unavailable to the AI CEO'],
                ['أي قرار بيعتمد على المصادر دي مش هيطلع لحد ما ترجع.', 'Decisions that depend on these sources are skipped until they are back.'],
                [['الأقسام: ' . implode('، ', $missing), 'Sections: ' . implode(', ', $missing)]], '/admin/health', ['افتح صحة المنصة', 'Open platform health']);
        }

        $p = $snap['platform'] ?? null;
        if ($p) {
            $fails = array_values(array_filter($p['problems'] ?? [], static fn ($x) => $x['status'] === 'fail'));
            $warns = array_values(array_filter($p['problems'] ?? [], static fn ($x) => $x['status'] === 'warn'));
            if ($fails) {
                $add('platform_failures', 'platform', 'low', ['impact' => 5, 'roi' => 4, 'urgency' => 5, 'confidence' => 5, 'risk' => 1, 'cost' => 2, 'effort' => 3],
                    ['فيه أعطال في المنصة محتاجة تتصلّح', 'Platform checks are failing'],
                    ['العطل بيوقف خدمة عند العملاء وممكن مايبانش غير لما حد يشتكي.', 'A failing check stops a service for customers, often before anyone complains.'],
                    array_map(static fn ($f) => [$f['label'] . ': ' . $f['message'], $f['label'] . ': ' . $f['message']], array_slice($fails, 0, 4)),
                    '/admin/health', ['افتح صحة المنصة', 'Open platform health']);
            }
            if ($warns) {
                $add('platform_warnings', 'platform', 'low', ['impact' => 3, 'roi' => 3, 'urgency' => 2, 'confidence' => 4, 'risk' => 1, 'cost' => 2, 'effort' => 2],
                    ['تحذيرات في صحة المنصة', 'Platform health warnings'],
                    ['التحذير النهارده ممكن يبقى عطل بكرة.', 'Today\'s warning can be tomorrow\'s outage.'],
                    array_map(static fn ($f) => [$f['label'] . ': ' . $f['message'], $f['label'] . ': ' . $f['message']], array_slice($warns, 0, 4)),
                    '/admin/health', ['راجع التحذيرات', 'Review the warnings']);
            }
        }

        $e = $snap['errors'] ?? null;
        if ($e && ($e['errors_last_hour'] >= 20 || $e['errors_24h'] >= 200)) {
            $add('error_spike', 'development', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => $e['errors_last_hour'] >= 20 ? 5 : 3, 'confidence' => 5, 'risk' => 1, 'cost' => 2, 'effort' => 3],
                ['أخطاء النظام عالية', 'System errors are high'],
                ['خطأ متكرر غالبًا معناه خدمة واقفة عند عملاء.', 'A repeating error usually means a service is broken for customers.'],
                array_merge([['آخر ساعة: ' . $e['errors_last_hour'] . ' · آخر 24 ساعة: ' . $e['errors_24h'], 'Last hour: ' . $e['errors_last_hour'] . ' · last 24h: ' . $e['errors_24h']]],
                    array_map(static fn ($g) => [$g['count'] . '× ' . $g['message'], $g['count'] . '× ' . $g['message']], array_slice($e['top'] ?? [], 0, 3))),
                '/admin/errors', ['افتح أخطاء النظام', 'Open system errors']);
        }

        $r = $snap['revenue'] ?? null;
        if ($r) {
            $mrr = $r['mrr']['now'] ?? null;
            $mrrPrev = $r['mrr']['prev'] ?? null;
            if ($mrr !== null && $mrrPrev !== null && $mrr < $mrrPrev) {
                $add('mrr_down', 'revenue', 'low', ['impact' => 5, 'roi' => 4, 'urgency' => 4, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                    ['الإيراد الشهري المتكرر (MRR) نزل', 'Monthly recurring revenue (MRR) went down'],
                    ['لازم نعرف نزل من إلغاء ولا من تخفيض باقات قبل أي خطوة.', 'Find out whether it came from cancellations or downgrades before acting.'],
                    [['MRR دلوقتي $' . $n($mrr) . ' مقابل $' . $n($mrrPrev) . ' من 30 يوم', 'MRR now $' . $n($mrr) . ' vs $' . $n($mrrPrev) . ' 30 days ago']],
                    '/admin/metrics', ['افتح أرقام SaaS', 'Open SaaS metrics']);
            }
            $ch = $r['logo_churn']['now'] ?? null;
            $chPrev = $r['logo_churn']['prev'] ?? null;
            if ($ch !== null && $ch > 0 && ($chPrev === null || $ch > $chPrev)) {
                $add('churn_up', 'customer_success', 'medium', ['impact' => 5, 'roi' => 5, 'urgency' => 4, 'confidence' => 4, 'risk' => 2, 'cost' => 2, 'effort' => 3],
                    ['نسبة إلغاء الاشتراكات زادت', 'Subscription churn went up'],
                    ['كل عميل بيلغي بيخسّرنا إيراده كل شهر. التواصل مع العملاء محتاج موافقتك.', 'Every cancellation loses its revenue every month. Contacting customers needs your approval.'],
                    [['الإلغاء آخر 30 يوم ' . $n($ch) . '% مقابل ' . ($chPrev === null ? '—' : $n($chPrev) . '%') . ' قبلها', 'Churn last 30 days ' . $n($ch) . '% vs ' . ($chPrev === null ? '—' : $n($chPrev) . '%') . ' before']],
                    '/admin/customers-health', ['افتح صحة العملاء', 'Open customer health']);
            }
        }

        $c = $snap['customers'] ?? null;
        if ($c && $c['paying_at_risk'] > 0) {
            $add('save_at_risk_customers', 'customer_success', 'medium', ['impact' => 5, 'roi' => 5, 'urgency' => 4, 'confidence' => 4, 'risk' => 2, 'cost' => 2, 'effort' => 3],
                ['عملاء بيدفعوا معرّضين يلغوا', 'Paying customers at risk of cancelling'],
                ['الاحتفاظ بعميل أرخص بكتير من جلب عميل جديد. التواصل معاهم بموافقتك.', 'Keeping a customer costs far less than winning a new one. Reaching out needs your approval.'],
                [[$c['paying_at_risk'] . ' عميل · إيراد شهري معرّض $' . $n($c['mrr_at_risk']), $c['paying_at_risk'] . ' customers · $' . $n($c['mrr_at_risk']) . ' MRR at risk']],
                '/admin/customers-health', ['شوف العملاء المعرّضين', 'See customers at risk']);
        }

        $t = $snap['trials'] ?? null;
        if ($t && $t['ending_24h'] > 0) {
            $add('trials_ending', 'sales', 'medium', ['impact' => 4, 'roi' => 5, 'urgency' => 5, 'confidence' => 5, 'risk' => 2, 'cost' => 1, 'effort' => 2],
                ['تجارب مجانية بتخلص خلال 24 ساعة', 'Free trials end within 24 hours'],
                ['آخر يوم في التجربة هو أعلى فرصة للاشتراك. التواصل بموافقتك.', 'The last trial day is the best moment to convert. Outreach needs your approval.'],
                [[$t['ending_24h'] . ' تجربة · ' . $t['active'] . ' تجربة شغالة', $t['ending_24h'] . ' trials · ' . $t['active'] . ' active trials']],
                '/admin/trials', ['افتح متابعة التجارب', 'Open trial follow-up']);
        }
        if ($t && $t['ended_unconverted'] > 0) {
            $add('trials_unconverted', 'sales', 'medium', ['impact' => 3, 'roi' => 4, 'urgency' => 3, 'confidence' => 5, 'risk' => 2, 'cost' => 1, 'effort' => 2],
                ['تجارب خلصت من غير اشتراك', 'Trials ended without subscribing'],
                ['دول أسخن عملاء محتملين عندنا: جرّبوا المنصة فعلًا.', 'These are the warmest leads we have: they already tried the platform.'],
                [[$t['ended_unconverted'] . ' تجربة آخر 30 يوم', $t['ended_unconverted'] . ' trials in the last 30 days']],
                '/admin/trials', ['افتح متابعة التجارب', 'Open trial follow-up']);
        }

        $su = $snap['signups'] ?? null;
        if ($su && $su['last_7d'] === 0) {
            $add('no_signups', 'growth', 'low', ['impact' => 5, 'roi' => 4, 'urgency' => 4, 'confidence' => 5, 'risk' => 1, 'cost' => 2, 'effort' => 3],
                ['مفيش تسجيلات جديدة الأسبوع ده', 'No new sign-ups this week'],
                ['من غير تسجيلات مفيش نمو. نبدأ بفحص صفحة التسجيل ومصادر الزيارات.', 'No sign-ups means no growth. Start by checking the sign-up page and traffic sources.'],
                [['آخر 7 أيام: 0 · الأسبوع اللي قبله: ' . $su['prev_7d'], 'Last 7 days: 0 · week before: ' . $su['prev_7d']]],
                '/admin/onboarding-funnel', ['افتح مسار التسجيل', 'Open the onboarding funnel']);
        } elseif ($su && $su['prev_7d'] >= 3 && $su['last_7d'] < 0.7 * $su['prev_7d']) {
            $add('signups_down', 'growth', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => 3, 'confidence' => 4, 'risk' => 1, 'cost' => 2, 'effort' => 3],
                ['التسجيلات قلّت', 'Sign-ups dropped'],
                ['نشوف هل المشكلة في الزيارات ولا في صفحة التسجيل.', 'Check whether traffic or the sign-up page is the cause.'],
                [['آخر 7 أيام: ' . $su['last_7d'] . ' · الأسبوع اللي قبله: ' . $su['prev_7d'], 'Last 7 days: ' . $su['last_7d'] . ' · week before: ' . $su['prev_7d']]],
                '/admin/onboarding-funnel', ['افتح مسار التسجيل', 'Open the onboarding funnel']);
        }

        $ai = $snap['ai_cost'] ?? null;
        if ($ai && $ai['today_usd'] > max(1.0, 2 * $ai['avg_day_7d_usd'])) {
            $add('ai_cost_spike', 'cost', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => 4, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                ['تكلفة الذكاء الاصطناعي النهارده أعلى من العادي', 'AI cost today is above normal'],
                ['ممكن تكون خدمة بتكرر طلبات أو عميل بيستهلك بشكل غير طبيعي.', 'A service may be repeating calls, or one account is consuming abnormally.'],
                [['النهارده $' . $n($ai['today_usd']) . ' · متوسط اليوم آخر أسبوع $' . $n($ai['avg_day_7d_usd']) . ' · الشهر $' . $n($ai['month_usd']), 'Today $' . $n($ai['today_usd']) . ' · daily average last week $' . $n($ai['avg_day_7d_usd']) . ' · month $' . $n($ai['month_usd'])]],
                '/admin/ai-usage', ['افتح تكلفة الذكاء الاصطناعي', 'Open AI usage']);
        }
        if ($ai && $ai['requests_today'] >= 20 && $ai['failed_today'] / max(1, $ai['requests_today']) > 0.2) {
            $add('ai_failures', 'platform', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => 4, 'confidence' => 4, 'risk' => 1, 'cost' => 2, 'effort' => 2],
                ['طلبات ذكاء اصطناعي كتير بتفشل', 'Many AI requests are failing'],
                ['ممكن مزود واقع أو مفتاح منتهي، والعملاء بيشوفوا أخطاء.', 'A provider may be down or a key expired; customers see errors.'],
                [[$ai['failed_today'] . ' من ' . $ai['requests_today'] . ' طلب النهارده', $ai['failed_today'] . ' of ' . $ai['requests_today'] . ' requests today']],
                '/admin/ai-routing', ['افتح توزيع المزودين', 'Open AI routing']);
        }

        // المرحلة 2: ميزانية المنصة، والاستهلاك غير الطبيعي، وتوزيع النماذج، وميزانية AI CEO
        $pb = $ai['platform_budget'] ?? null;
        if ($pb && ($pb['projected_pct'] ?? 0) >= 90) {
            $add('ai_budget_projection', 'cost', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => ($pb['used_pct'] ?? 0) >= 90 ? 5 : 3, 'confidence' => 3, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                ['تكلفة الذكاء الاصطناعي هتعدّي الميزانية الشهرية', 'AI spend is on track to exceed the monthly budget'],
                ['التوقع بسرعة الصرف الحالية. راجع أغلى المهام قبل ما الميزانية تخلص.', 'Projection at the current daily pace. Review the costliest tasks before the budget runs out.'],
                [['اتصرف ' . $n($pb['used_pct']) . '% · المتوقع آخر الشهر ' . $n($pb['projected_pct']) . '% من $' . $n($pb['limit']), 'Spent ' . $n($pb['used_pct']) . '% · projected ' . $n($pb['projected_pct']) . '% of $' . $n($pb['limit'])]],
                '/admin/ai-ceo#costs', ['افتح تكلفة الذكاء الاصطناعي', 'Open AI cost optimization']);
        }
        if (!empty($ai['anomalies'])) {
            $add('ai_usage_anomaly', 'cost', 'low', ['impact' => 3, 'roi' => 4, 'urgency' => 4, 'confidence' => 4, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                ['استهلاك ذكاء اصطناعي غير طبيعي النهارده', 'Abnormal AI usage today'],
                ['مهمة أو عميل بيصرف النهارده أكتر من 3 أضعاف معدله: ممكن تكرار طلبات أو إساءة استخدام.', 'A task or account spends over 3× its usual daily rate today: possibly repeated calls or abuse.'],
                array_map(static fn ($x) => [$x['name'] . ': $' . $n($x['today']) . ' النهارده مقابل $' . $n($x['avg_day']) . ' في اليوم عادةً', $x['name'] . ': $' . $n($x['today']) . ' today vs $' . $n($x['avg_day']) . ' a day usually'], array_slice($ai['anomalies'], 0, 4)),
                '/admin/ai-ceo#costs', ['افتح تكلفة الذكاء الاصطناعي', 'Open AI cost optimization']);
        }
        if (!empty($ai['routing_review'])) {
            // تغيير النموذج بيأثر على جودة خدمة العملاء: بموافقة، وبعد اختبار الجودة
            $add('ai_routing_review', 'cost', 'medium', ['impact' => 3, 'roi' => 4, 'urgency' => 2, 'confidence' => 3, 'risk' => 3, 'cost' => 1, 'effort' => 2],
                ['مهام بتستخدم نموذج أغلى بكتير من بديل شغال على نفس المهمة', 'Tasks use a model far costlier than an alternative already serving the same task'],
                ['جرّب النموذج الأرخص على عينة وقارن الجودة قبل ما تغيّر التوزيع.', 'Test the cheaper model on a sample and compare quality before changing the routing.'],
                array_map(static fn ($x) => [$x['feature'] . ': ' . $x['expensive_model'] . ' $' . $x['expensive_cpr'] . '/طلب مقابل ' . $x['cheap_model'] . ' $' . $x['cheap_cpr'], $x['feature'] . ': ' . $x['expensive_model'] . ' $' . $x['expensive_cpr'] . '/request vs ' . $x['cheap_model'] . ' $' . $x['cheap_cpr']], array_slice($ai['routing_review'], 0, 4)),
                '/admin/ai-routing', ['افتح توزيع خدمات AI', 'Open AI routing']);
        }
        if (!empty($ai['aiceo']['blocked'])) {
            $add('aiceo_budget_reached', 'cost', 'low', ['impact' => 2, 'roi' => 2, 'urgency' => 2, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['AI CEO وصل ميزانيته للذكاء الاصطناعي', 'The AI CEO reached its AI budget'],
                ['المحادثة شغالة من الأرقام بس لحد ما الميزانية تتجدد أو تزوّدها.', 'Chat answers from the numbers only until the budget resets or you raise it.'],
                [[($ai['aiceo']['blocked'] === 'daily' ? 'اليومية' : 'الشهرية') . ': $' . $n($ai['aiceo']['day']) . ' اليوم · $' . $n($ai['aiceo']['month']) . ' الشهر', ucfirst((string) $ai['aiceo']['blocked']) . ': $' . $n($ai['aiceo']['day']) . ' today · $' . $n($ai['aiceo']['month']) . ' this month']],
                '/admin/ai-ceo#costs', ['افتح ميزانية AI CEO', 'Open the AI CEO budget']);
        }

        // المرحلة 4: المبيعات (البوابة القانونية قبل أي رسالة، والـ CRM)
        $sl = $snap['sales'] ?? null;
        if ($sl && !$sl['has_address']) {
            $add('sales_postal_address', 'sales', 'low', ['impact' => 4, 'roi' => 3, 'urgency' => 3, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['عنوان الشركة البريدي ناقص في إيميلات التسويق', 'The company postal address is missing from outreach emails'],
                ['القوانين (زي CAN-SPAM) بتشترط عنوان فعلي في كل إيميل تسويقي، فالبوابة مش هتوافق على رسايل جديدة.', 'Laws such as CAN-SPAM require a real postal address in every marketing email, so the gate approves no new messages.'],
                [['حط العنوان الكامل في إعدادات تسويق المنصة', 'Add the full address in platform outreach settings']], '/admin/growth', ['افتح تسويق المنصة', 'Open platform outreach']);
        }
        if ($sl && !$sl['basis_confirmed'] && ($sl['queue_blocked'] ?? 0) > 0) {
            $add('sales_basis_setup', 'sales', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => 3, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 2],
                ['حدد الأساس القانوني للتسويق في كل منطقة', 'Confirm the lawful basis for outreach per region'],
                ['من غير تأكيدك، البوابة مش هتوافق على أول رسالة لأي شركة (أمريكا، أوروبا، الخليج، باقي الدول).', 'Without your confirmation the gate approves no first message to any company (US, Europe, Gulf, rest of world).'],
                [[$sl['queue_blocked'] . ' شركة مستنية', $sl['queue_blocked'] . ' companies waiting']], '/admin/ai-ceo#sales', ['افتح المبيعات', 'Open Leads & Sales']);
        }
        if ($sl && ($sl['queue_ok'] ?? 0) > 0) {
            $add('sales_compliant_ready', 'sales', 'medium', ['impact' => 4, 'roi' => 4, 'urgency' => 3, 'confidence' => 4, 'risk' => 2, 'cost' => 1, 'effort' => 1],
                ['شركات عدّت البوابة القانونية ومستنية موافقتك على أول رسالة', 'Companies passed the compliance gate and await your approval for the first message'],
                ['الموافقة بتبدأ سلسلة الإيميلات بحدها اليومي، ومفيش إرسال من غير موافقتك.', 'Approving starts the email sequence within its daily limit; nothing is sent without you.'],
                [[$sl['queue_ok'] . ' شركة مسموح التواصل معاها', $sl['queue_ok'] . ' companies may be contacted']], '/admin/ai-ceo#sales', ['راجع ووافق', 'Review and approve']);
        }
        if ($sl && ($sl['active_noncompliant'] ?? 0) > 0) {
            $add('sales_noncompliant_sequences', 'sales', 'medium', ['impact' => 4, 'roi' => 3, 'urgency' => 4, 'confidence' => 4, 'risk' => 3, 'cost' => 1, 'effort' => 1],
                ['سلاسل إيميل شغالة على شركات البوابة دلوقتي بترفضها', 'Running email sequences to companies the gate now rejects'],
                ['اتوافق عليها قبل البوابة. راجعها ووقّف اللي ملوش أساس قانوني.', 'They were approved before the gate. Review and pause those without a lawful basis.'],
                [[$sl['active_noncompliant'] . ' شركة', $sl['active_noncompliant'] . ' companies']], '/admin/ai-ceo#sales', ['راجعها', 'Review them']);
        }
        if ($sl && ($sl['crm_pending'] ?? 0) > 0) {
            $add('sales_crm_handoff', 'sales', 'low', ['impact' => 4, 'roi' => 5, 'urgency' => 4, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['شركات مهتمة لسه مااتنقلتش للـ CRM', 'Interested companies are not in the CRM yet'],
                ['المهتم اللي مابيتابعش بيبرد. النقل بيعمل جهة اتصال وفرصة بمتابعة بكرة.', 'An interested lead goes cold without follow-up. Moving it creates a contact and a deal with a follow-up tomorrow.'],
                [[$sl['crm_pending'] . ' شركة ردت أو مهتمة', $sl['crm_pending'] . ' replied or interested companies']], '/admin/ai-ceo#sales', ['انقلهم للـ CRM', 'Move them to the CRM']);
        }

        // المرحلة 5: موقع Tourfecto نفسه
        $br = $snap['brand'] ?? null;
        if ($br && $br['audit_at'] === null) {
            $add('brand_audit_first', 'brand', 'low', ['impact' => 3, 'roi' => 4, 'urgency' => 2, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['افحص سيو موقع Tourfecto', 'Audit the SEO of the Tourfecto website'],
                ['فحص ببلاش للعناوين والوصف والفهرسة والبيانات المنظمة والسرعة، عشان نعرف نقط الضعف قبل خطة المحتوى.', 'A free check of titles, descriptions, indexing, structured data and speed before the content plan.'],
                [['مفيش فحص لسه', 'No audit yet']], '/admin/ai-ceo#brand', ['افتح نمو العلامة', 'Open Brand Growth']);
        } elseif ($br && $br['audit_critical'] > 0) {
            $add('brand_site_critical', 'brand', 'low', ['impact' => 4, 'roi' => 4, 'urgency' => 4, 'confidence' => 5, 'risk' => 1, 'cost' => 2, 'effort' => 2],
                ['مشاكل خطيرة في سيو موقع Tourfecto', 'Critical SEO problems on the Tourfecto website'],
                ['صفحات واقعة أو ممنوعة من الفهرسة أو مالهاش عنوان بتضيع زيارات وتسجيلات.', 'Broken, non-indexable or untitled pages lose traffic and sign-ups.'],
                [[$br['audit_critical'] . ' مشكلة خطيرة · الدرجة ' . ($br['audit_score'] ?? '—') . '/100', $br['audit_critical'] . ' critical issues · score ' . ($br['audit_score'] ?? '—') . '/100']],
                '/admin/ai-ceo#brand', ['شوف المشاكل', 'See the issues']);
        }
        if ($br && $br['visitors_30d'] >= 50 && $br['signups_30d'] === 0) {
            $add('brand_no_conversions', 'growth', 'low', ['impact' => 5, 'roi' => 4, 'urgency' => 4, 'confidence' => 4, 'risk' => 1, 'cost' => 2, 'effort' => 3],
                ['زوار من غير تسجيلات', 'Visitors but no sign-ups'],
                ['الموقع بيجيب زيارات ومفيش حد بيسجل: راجع صفحات الدخول ودعوة التسجيل.', 'The site gets visits but nobody signs up: review the landing pages and the sign-up call to action.'],
                [[$br['visitors_30d'] . ' زائر في 30 يوم · 0 تسجيل', $br['visitors_30d'] . ' visitors in 30 days · 0 sign-ups']], '/admin/ai-ceo#brand', ['افتح نمو العلامة', 'Open Brand Growth']);
        }
        if ($br && !$br['brand_site_in_platform']) {
            $add('brand_site_not_in_platform', 'brand', 'low', ['impact' => 3, 'roi' => 4, 'urgency' => 2, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['استخدم المنصة نفسها لتسويق Tourfecto', 'Use the platform itself to market Tourfecto'],
                ['ضيف tourfecto.pro كموقع في حساب صاحب المنصة عشان السيو والسوشيال والتقييمات واستوديو التصميم يشتغلوا لـ Tourfecto.', 'Add tourfecto.pro as a website in the owner account so SEO, social, reviews and the studio work for Tourfecto.'],
                [['مفيش موقع لـ Tourfecto في حساب أدمن', 'No Tourfecto website in an admin account']], '/websites', ['ضيف الموقع', 'Add the website']);
        }

        $o = $snap['outreach'] ?? null;
        if ($o && $o['awaiting_approval'] > 0 && !$sl) {
            $add('outreach_awaiting_approval', 'sales', 'medium', ['impact' => 4, 'roi' => 4, 'urgency' => 3, 'confidence' => 4, 'risk' => 3, 'cost' => 2, 'effort' => 2],
                ['شركات سياحة مستنية موافقتك قبل أول رسالة', 'Tourism companies await your approval before the first message'],
                ['الإرسال لشركات من بره المنصة محتاج تتأكد من الأساس القانوني والموافقة.', 'Contacting outside companies needs a lawful basis and your approval.'],
                [[$o['awaiting_approval'] . ' شركة · ' . $o['total'] . ' شركة في القايمة', $o['awaiting_approval'] . ' companies · ' . $o['total'] . ' in the list']],
                '/admin/growth', ['افتح تسويق المنصة', 'Open platform outreach'], ['legal_basis']);
        }
        if ($o && $o['paused_reason'] !== '') {
            $add('outreach_paused', 'sales', 'low', ['impact' => 3, 'roi' => 3, 'urgency' => 3, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1],
                ['تسويق المنصة متوقف', 'Platform outreach is paused'],
                ['الحملة واقفة لسبب محتاج مراجعة.', 'The campaign stopped for a reason that needs review.'],
                [[$o['paused_reason'], $o['paused_reason']]], '/admin/growth', ['افتح تسويق المنصة', 'Open platform outreach']);
        }

        usort($out, static fn ($a, $b) => $b['priority'] <=> $a['priority']);
        return $out;
    }
}
