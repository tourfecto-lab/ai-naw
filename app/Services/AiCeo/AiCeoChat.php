<?php

/**
 * TOURFECTO AI CEO — محادثة صاحب المنصة مع العقل (عربي مصري أو إنجليزي حسب السؤال).
 *
 * الأسئلة الأساسية بتتجاوب من الأرقام مباشرة من غير أي طلب ذكاء اصطناعي (ببلاش، وسريع، ومفيش اختلاق):
 *   إيه اللي حصل النهارده؟ · إيه أهم مشكلة عندنا؟ · ليه الاشتراكات قلّت؟ · إيه الخدمات اللي محتاجة تطوير؟
 *   إيه أفضل دولة نستهدفها؟ · إيه المطلوب مني دلوقتي؟
 * أي سؤال تاني بيروح للذكاء الاصطناعي (AIOrchestrator، نفس مزودي المنصة) مع ملخص صغير من نفس البيانات،
 * وتعليمات إنه مايجاوبش من بره البيانات. لو زرار الطوارئ مضغوط، مفيش أي طلب ذكاء اصطناعي.
 */
final class AiCeoChat
{
    /** @var callable|null للاختبارات: fn(string $prompt): ?string */
    private $ai;

    public function __construct(?callable $ai = null)
    {
        $this->ai = $ai;
    }

    public static function intent(string $q): ?string
    {
        $q = mb_strtolower($q);
        $rules = [
            'needs_me' => '/(مني|منّي|المطلوب|محتاج.{0,6}موافق|موافقتي|what do you need|need from me|approvals?)/u',
            'top_problem' => '/(أهم مشكل|اهم مشكل|أكبر مشكل|اكبر مشكل|top problem|biggest problem|main problem)/u',
            'subs_down' => '/((الاشتراكات|اشتراكات|المشتركين).{0,15}(قل|نزل|بتقل|وقع)|churn|subscriptions? (drop|down|fell)|why .*subscri)/u',
            'best_country' => '/(دولة|دوله|بلد|انهي سوق|أنهي سوق|country|which market)/u',
            'services_dev' => '/(تطوير|محتاج(ة)? تتحسن|services? .*(improve|develop)|what to (build|fix))/u',
            'today' => '/(النهارده|النهارده|النهاردة|انهارده|اليوم|حصل|today|happened)/u',
        ];
        foreach ($rules as $intent => $re) if (preg_match($re, $q)) return $intent;
        return null;
    }

    /**
     * @return array{answer:string, intent:?string, source:string}
     *   source: data (من الأرقام) · ai (من الذكاء الاصطناعي) · none (مقدرش يجاوب)
     */
    public function answer(string $question, array $snap, array $decisions, array $settings): array
    {
        $ar = (bool) preg_match('/\p{Arabic}/u', $question);
        $t = static fn (string $a, string $e) => $ar ? $a : $e;
        $intent = self::intent($question);
        if ($intent !== null) return ['answer' => $this->fromData($intent, $snap, $decisions, $settings, $ar), 'intent' => $intent, 'source' => 'data'];

        if (!empty($settings['paused'])) {
            return ['answer' => $t('AI CEO متوقف بزرار الطوارئ، فمش هبعت أي طلب للذكاء الاصطناعي. أقدر أجاوب من الأرقام على: إيه اللي حصل النهارده؟ إيه أهم مشكلة؟ ليه الاشتراكات قلّت؟ إيه الخدمات اللي محتاجة تطوير؟ إيه أفضل دولة؟ إيه المطلوب مني؟',
                'The AI CEO is on emergency stop, so no AI request is sent. I can still answer from the numbers: what happened today, the top problem, why subscriptions dropped, what needs development, the best country, and what I need from you.'), 'intent' => null, 'source' => 'none'];
        }
        $prompt = $this->prompt($question, $snap, $decisions, $ar);
        $text = null;
        try {
            if ($this->ai) {
                $text = call_user_func($this->ai, $prompt);
            } else {
                $r = (new AIOrchestrator())->generateContent($prompt, ['task' => 'ai_ceo_chat', 'maxOutputTokens' => 700, 'temperature' => 0.3]);
                $text = !empty($r['success']) ? trim((string) ($r['data'] ?? '')) : null;
            }
        } catch (Throwable $e) {
            $text = null;
        }
        if ($text) return ['answer' => $text, 'intent' => null, 'source' => 'ai'];
        return ['answer' => $t('مش قادر أوصل للذكاء الاصطناعي دلوقتي. جرّب سؤال من الأسئلة الأساسية وأنا أجاوبك من الأرقام مباشرة.', 'The AI model is not reachable right now. Try one of the core questions and I will answer from the numbers.'), 'intent' => null, 'source' => 'none'];
    }

    private function fromData(string $intent, array $s, array $decisions, array $settings, bool $ar): string
    {
        $t = static fn (string $a, string $e) => $ar ? $a : $e;
        $L = static fn ($o) => is_array($o) ? (string) ($ar ? ($o['ar'] ?? '') : ($o['en'] ?? '')) : (string) $o;
        $n = static fn ($v) => $v === null ? '—' : number_format((float) $v, (float) $v == (int) $v ? 0 : 2);
        $open = array_values(array_filter($decisions, static fn ($d) => in_array($d['status'] ?? '', ['proposed', 'approved'], true)));
        $unknown = $t('مش معروف (المصدر مش متاح)', 'unknown (source unavailable)');
        $lines = [];

        switch ($intent) {
            case 'today':
                $su = $s['signups'] ?? null; $r = $s['revenue'] ?? null; $e = $s['errors'] ?? null; $p = $s['platform'] ?? null; $ai = $s['ai_cost'] ?? null; $tr = $s['trials'] ?? null;
                $lines[] = $t('ملخص النهارده:', 'Today at a glance:');
                $lines[] = '• ' . $t('تسجيلات جديدة: ', 'New sign-ups: ') . ($su ? $su['today'] . $t(' النهارده · ', ' today · ') . $su['last_7d'] . $t(' آخر 7 أيام', ' in the last 7 days') : $unknown);
                $lines[] = '• ' . $t('الإيراد الشهري (MRR): ', 'MRR: ') . ($r ? '$' . $n($r['mrr']['now']) . $t(' (من 30 يوم: $', ' (30 days ago: $') . $n($r['mrr']['prev']) . ')' : $unknown);
                $lines[] = '• ' . $t('عملاء بيدفعوا: ', 'Paying customers: ') . ($r ? $n($r['paying']['now']) : $unknown);
                $lines[] = '• ' . $t('تجارب شغالة: ', 'Active trials: ') . ($tr ? $tr['active'] . $t(' (بتخلص خلال 24 ساعة: ', ' (ending within 24h: ') . $tr['ending_24h'] . ')' : $unknown);
                $lines[] = '• ' . $t('صحة المنصة: ', 'Platform health: ') . ($p ? strtoupper((string) $p['overall']) . ' · ' . (int) ($p['counts']['fail'] ?? 0) . $t(' عطل، ', ' failing, ') . (int) ($p['counts']['warn'] ?? 0) . $t(' تحذير', ' warnings') : $unknown);
                $lines[] = '• ' . $t('أخطاء النظام آخر 24 ساعة: ', 'System errors in 24h: ') . ($e ? $e['errors_24h'] : $unknown);
                $lines[] = '• ' . $t('تكلفة الذكاء الاصطناعي النهارده: ', 'AI cost today: ') . ($ai ? '$' . $n($ai['today_usd']) . $t(' (الشهر: $', ' (month: $') . $n($ai['month_usd']) . ')' : $unknown);
                $lines[] = '• ' . $t('قرارات مفتوحة: ', 'Open decisions: ') . count($open);
                break;

            case 'top_problem':
                if (!$open) { $lines[] = $t('مفيش مشكلة مفتوحة في البيانات اللي عندي دلوقتي.', 'There is no open problem in the data I have right now.'); break; }
                $d = $open[0];
                $lines[] = $t('أهم حاجة دلوقتي: ', 'Top priority now: ') . $L($d['title'] ?? '') . ' (' . $t('أولوية ', 'priority ') . $n($d['priority'] ?? 0) . ')';
                $lines[] = $L($d['why'] ?? '');
                foreach ($d['evidence'] ?? [] as $ev) $lines[] = '• ' . $L($ev);
                $lines[] = $t('التنفيذ من: ', 'Act from: ') . ($d['action_url'] ?? '') . (!empty($d['requires_approval']) ? $t(' · محتاج موافقتك', ' · needs your approval') : '');
                break;

            case 'subs_down':
                $r = $s['revenue'] ?? null; $tr = $s['trials'] ?? null; $c = $s['customers'] ?? null;
                if (!$r) { $lines[] = $t('مصدر أرقام الاشتراكات مش متاح دلوقتي، فمقدرش أحكم.', 'The subscription numbers are unavailable right now, so I cannot tell.'); break; }
                $down = ($r['paying']['now'] ?? 0) < ($r['paying']['prev'] ?? 0);
                $lines[] = $down ? $t('أيوه، العملاء اللي بيدفعوا قلّوا: ', 'Yes, paying customers went down: ') : $t('العملاء اللي بيدفعوا ماقلّوش آخر 30 يوم: ', 'Paying customers did not go down in the last 30 days: ');
                $lines[] = '• ' . $t('دلوقتي ', 'Now ') . $n($r['paying']['now']) . $t(' مقابل ', ' vs ') . $n($r['paying']['prev']) . $t(' من 30 يوم', ' 30 days ago');
                $lines[] = '• ' . $t('نسبة الإلغاء: ', 'Churn: ') . $n($r['logo_churn']['now']) . '%' . $t(' (قبلها ', ' (before ') . $n($r['logo_churn']['prev']) . '%)';
                $lines[] = '• ' . $t('تحويل التجارب لاشتراك: ', 'Trial conversion: ') . ($r['trial_conversion'] === null ? '—' : $n($r['trial_conversion']) . '%');
                if ($tr) $lines[] = '• ' . $t('تجارب خلصت من غير اشتراك آخر 30 يوم: ', 'Trials ended without subscribing (30 days): ') . $tr['ended_unconverted'];
                if ($c) $lines[] = '• ' . $t('عملاء بيدفعوا معرّضين يلغوا: ', 'Paying customers at risk: ') . $c['paying_at_risk'] . ' ($' . $n($c['mrr_at_risk']) . $t(' شهريًا)', ' MRR)');
                $lines[] = $t('السبب الأقرب من الأرقام: ', 'Most likely cause from the numbers: ') . (($r['logo_churn']['now'] ?? 0) > ($r['logo_churn']['prev'] ?? 0)
                    ? $t('الإلغاء زاد. راجع العملاء اللي ألغوا في أرقام SaaS.', 'more cancellations. Review recent churn in SaaS metrics.')
                    : (($tr['ended_unconverted'] ?? 0) > 0 ? $t('التجارب مش بتتحول لاشتراكات.', 'trials are not converting.') : $t('مش واضح من البيانات الحالية؛ أسباب الإلغاء نفسها لسه مش متسجلة (المرحلة 6).', 'not clear from current data; cancellation reasons are not recorded yet (phase 6).')));
                break;

            case 'services_dev':
                $p = $s['platform'] ?? null; $e = $s['errors'] ?? null;
                $lines[] = $t('اللي محتاج تطوير أو تصليح حسب البيانات:', 'What needs development or fixing, from the data:');
                foreach (array_slice($p['problems'] ?? [], 0, 5) as $x) $lines[] = '• [' . $x['status'] . '] ' . $x['label'] . ': ' . $x['message'];
                foreach (array_slice($e['top'] ?? [], 0, 3) as $g) $lines[] = '• ' . $t('خطأ متكرر ', 'Repeating error ') . $g['count'] . '×: ' . $g['message'];
                if (count($lines) === 1) $lines[] = $t('مفيش أعطال أو أخطاء متكررة في آخر 24 ساعة.', 'No failing checks or repeating errors in the last 24 hours.');
                $lines[] = $t('ملحوظة: تقييم كل خدمة بالاستخدام والشكاوى جاي في المرحلة 7.', 'Note: per-service scoring by usage and complaints comes in phase 7.');
                break;

            case 'best_country':
                $cs = array_values(array_filter($s['outreach']['countries'] ?? [], static fn ($c) => $c['country'] !== '?' && $c['contacted'] > 0));
                if (!$cs) { $lines[] = $t('لسه مفيش تواصل كفاية مع شركات في أي دولة عشان أحكم. ابدأ من تسويق المنصة (/admin/growth).', 'There is not enough outreach in any country yet to judge. Start from platform outreach (/admin/growth).'); break; }
                usort($cs, static fn ($a, $b) => [$b['signed_up'], $b['interested'], $b['clicks']] <=> [$a['signed_up'], $a['interested'], $a['clicks']]);
                $lines[] = $t('حسب نتايج التواصل الفعلية:', 'Based on actual outreach results:');
                foreach (array_slice($cs, 0, 3) as $c) $lines[] = '• ' . $c['country'] . ': ' . $c['contacted'] . $t(' اتكلمت، ', ' contacted, ') . $c['interested'] . $t(' مهتم، ', ' interested, ') . $c['signed_up'] . $t(' سجّل', ' signed up');
                if ($cs[0]['contacted'] < 20) $lines[] = $t('العينة صغيرة (أقل من 20 شركة)، فده مؤشر مش حكم نهائي.', 'The sample is small (under 20 companies), so treat this as a signal, not a verdict.');
                break;

            case 'needs_me':
                if (!empty($settings['paused'])) $lines[] = $t('⚠️ AI CEO متوقف بزرار الطوارئ. شغّله تاني لما تكون جاهز.', '⚠️ The AI CEO is on emergency stop. Resume it when ready.');
                $need = array_values(array_filter($open, static fn ($d) => ($d['status'] ?? '') === 'proposed'));
                if (!$need) { $lines[] = $t('مفيش حاجة مستنياك دلوقتي.', 'Nothing is waiting for you right now.'); break; }
                $lines[] = $t('مستني منك:', 'Waiting for you:');
                foreach (array_slice($need, 0, 6) as $d) $lines[] = '• ' . $L($d['title'] ?? '') . (!empty($d['requires_approval']) ? $t(' (موافقة)', ' (approval)') : '') . ' → ' . ($d['action_url'] ?? '');
                break;
        }
        return implode("\n", $lines);
    }

    private function prompt(string $question, array $snap, array $decisions, bool $ar): string
    {
        $brief = array_map(static fn ($d) => ['title' => $d['title']['en'] ?? '', 'status' => $d['status'] ?? '', 'priority' => $d['priority'] ?? 0, 'risk' => $d['risk'] ?? '',
            'evidence' => array_map(static fn ($e) => $e['en'] ?? '', $d['evidence'] ?? [])], array_slice($decisions, 0, 8));
        $data = json_encode(['platform_snapshot' => $snap, 'decisions' => $brief], JSON_UNESCAPED_UNICODE);
        return "You are TOURFECTO AI CEO, the executive assistant of the owner of Tourfecto (a SaaS platform for tourism businesses).\n"
            . "Answer ONLY from the DATA below. If the data does not contain the answer, say so plainly and say which data is missing. Never invent numbers, customers or results.\n"
            . 'Answer in ' . ($ar ? 'Egyptian Arabic' : 'English') . ", in at most 8 short lines, and end with the single most useful next step.\n"
            . "Never suggest spending money, changing prices or contacting people without the owner's approval.\n\n"
            . "DATA:\n" . mb_substr((string) $data, 0, 12000) . "\n\nQUESTION:\n" . mb_substr($question, 0, 1000);
    }
}
