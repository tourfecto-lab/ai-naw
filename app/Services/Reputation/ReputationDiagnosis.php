<?php

/**
 * Tourfecto - تشخيص السمعة بعين خبير
 *
 * التحليل القديم كان بيعدّ كلمات فندقية (غرفة، واي فاي) على مستوى التقييم كله، فتقييم 5 نجوم
 * فيه "the pickup was late" كان بيتحسب مدح للمواعيد. هنا:
 * - مواضيع شركات الجولات (المرشد، الاستلام والمواعيد، السواق والعربية، البرنامج والوقت، السعر،
 *   محلات الهدايا والعمولات، الحجز والتواصل، الأكل، الأمان، حجم الجروب) بكذا لغة.
 * - كل جملة بتتقيّم لوحدها (مدح ولا شكوى)، ومعاها جملة من كلام العميل كدليل.
 * - مؤشرات الخبير: معدل الرد، سرعة الرد، تقييمات سلبية من غير رد، اتجاه آخر 30 يوم، عدد التقييمات.
 * - درجة صحة السمعة من 100، وخطوات مرتّبة بالأولوية بأرقام العميل نفسه.
 *
 * منطق خالص من غير قاعدة بيانات ولا ذكاء اصطناعي (مجاني وبيشتغل دايمًا).
 */
class ReputationDiagnosis
{
    /** موضوع => [عربي, إنجليزي, كلمات (regex)] */
    public const TOPICS = [
        'guide' => ['المرشد السياحي', 'Tour guide', 'guide|guides|guided|egyptologist|reiseleiter\w*|führer\w*|fremdenführer|guía|guia|guida|rehber\w*|gids|مرشد\w*|المرشد|الجايد|الدليل'],
        'pickup' => ['الاستلام والمواعيد', 'Pickup & punctuality', 'pick ?up|picked (us|me) up|on time|punctual\w*|late|delay\w*|waited|waiting|pünktlich\w*|abholung|verspät\w*|retard\w*|ponctu\w*|puntual\w*|recogida|retraso|puntual\w*|ritardo|in orario|استلام|اتأخر\w*|تأخير|التأخير|ميعاد|في الميعاد|مستني\w*|انتظرنا'],
        'driver' => ['السواق والعربية', 'Driver & vehicle', 'driver|drivers|car|van|minivan|bus|vehicle|air ?con\w*|fahrer|auto|chauffeur|voiture|conductor|coche|autista|şoför|araç|سواق|السائق|سائق|العربية|السيارة|الباص|التكييف'],
        'itinerary' => ['البرنامج والوقت', 'Itinerary & timing', 'itinerary|schedule|program\w*|enough time|rushed|hurried|time at|too short|too long|programm|zeitplan|programme|itinéraire|itinerario|programa|programma|البرنامج|برنامج|الوقت|وقت كافي|مستعجل\w*|اتسربعنا'],
        'value' => ['السعر والقيمة', 'Price & value', 'price\w*|value|worth|expensive|overpriced|cheap|hidden (cost|fee)s?|extra (cost|fee|charge)s?|tips?|tipping|money|preis\w*|teuer|prix|cher|precio|caro|prezzo|costoso|fiyat|pahalı|سعر|السعر|غالي|غالية|فلوس|بقشيش|تكلفة'],
        'shopping' => ['محلات الهدايا والعمولات', 'Shopping stops', 'shop|shops|shopping|souvenir\w*|papyrus|perfume|alabaster|essence|commission|bazaar|laden|geschäft|magasin|boutique|tienda|negozio|dükkan|بازار|محل|محلات|عطور|البردي|عمولة'],
        'booking' => ['الحجز والتواصل', 'Booking & communication', 'booking|booked|book|communication|communicat\w*|respon\w*|whatsapp|e-?mail|confirm\w*|cancel\w*|refund\w*|buchung|réservation|reserva|prenotazione|rezervasyon|حجز|الحجز|تواصل|التواصل|ردوا|الرد|إلغاء|استرداد|واتساب'],
        'food' => ['الأكل', 'Food', 'lunch|dinner|breakfast|food|meal\w*|restaurant|essen|mittagessen|repas|déjeuner|comida|almuerzo|pranzo|cibo|yemek|أكل|الأكل|غدا|الغدا|عشا|فطار|مطعم'],
        'safety' => ['الأمان', 'Safety', 'safe|safety|unsafe|secure|danger\w*|sicher\w*|sécurité|seguro|seguridad|sicuro|güvenli|أمان|آمن|امان|خطر'],
        'group' => ['حجم الجروب', 'Group size', 'group|groups|crowd\w*|private tour|small group|gruppe|groupe|grupo|gruppo|grup|جروب|مجموعة|زحمة|زحام|خاصة'],
        'clean' => ['النظافة', 'Cleanliness', 'clean\w*|dirty|smell\w*|sauber|schmutzig|propre|sale|limpi\w*|sucio|pulito|sporco|temiz|kirli|نظيف\w*|نظافة|وسخ|ريحة'],
    ];

    // كلمات نفي عامة زي no / pas / non / مش بتظهر في المدح كمان ("no problem") فمش منها
    private const NEG = '/\b(not|never|late|delay\w*|rude|bad|worst|poor|terrible|awful|horrible|disappoint\w*|dirty|overpriced|expensive|rushed|waited|problems?|issues?|complain\w*|unfortunately|scam|forced|pushy|pressure\w*|unsafe|cancel\w*|nicht|leider|schlecht|enttäusch\w*|mauvais|déçu\w*|malheureusement|malo|lamentablemente|purtroppo|kötü|değil|maalesef)\b|ماكانش|مكانش|وحش|سيء|سيئ|اتأخر|تأخير|للأسف|غالي|مزعج|ضغط|إجبار|زحمة|وسخ|زفت/iu';
    private const POS = '/\b(great|excellent|amazing|awesome|fantastic|wonderful|perfect|best|lovely|friendly|knowledgeable|professional|helpful|kind|punctual|on time|comfortable|clean|safe|recommend\w*|enjoy\w*|beautiful|super|toll|ausgezeichnet|wunderbar|freundlich|pünktlich|empfehle\w*|génial|excellent|parfait|sympa\w*|recommande|excelente|genial|perfecto|amable|recomiendo|ottimo|fantastico|perfetto|gentile|consiglio|harika|mükemmel|güzel)\b|ممتاز\w*|رائع\w*|جميل\w*|حلو\w*|محترم\w*|محترف\w*|نظيف\w*|في الميعاد|أنصح|انصح|تحفة|جامد|عظيم/iu';

    /**
     * @param array<int,array> $reviews  rating, review_text, sentiment|sentiment_label, created_at|review_date, reply_sent|reply_sent_at, reviewer_name, id
     */
    public static function analyze(array $reviews, ?int $now = null, bool $ar = true): array
    {
        $now = $now ?? time();
        $d30 = $now - 30 * 86400;
        $d60 = $now - 60 * 86400;
        $total = count($reviews);
        $replied = 0;
        $hours = [];
        $unanswered = [];
        $r30 = [];
        $rPrev = [];
        $topics = [];
        foreach ($reviews as $r) {
            $rating = (float) ($r['rating'] ?? 0);
            $text = trim((string) ($r['review_text'] ?? ''));
            $at = strtotime((string) ($r['review_date'] ?? $r['created_at'] ?? '')) ?: $now;
            $isReplied = !empty($r['reply_sent_at']) || !empty($r['reply_sent']) && (string) $r['reply_sent'] !== '0';
            if ($isReplied) {
                $replied++;
                $seen = max($at, strtotime((string) ($r['created_at'] ?? '')) ?: $at);
                if (!empty($r['reply_sent_at']) && ($sent = strtotime((string) $r['reply_sent_at'])) && $sent >= $seen) {
                    $hours[] = ($sent - $seen) / 3600;
                }
            }
            $neg = ($rating > 0 && $rating <= 3) || in_array((string) ($r['sentiment'] ?? $r['sentiment_label'] ?? ''), ['negative'], true);
            if ($neg && !$isReplied) {
                $unanswered[] = ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['reviewer_name'] ?? ''), 'days' => max(0, (int) floor(($now - $at) / 86400)), 'rating' => $rating];
            }
            if ($rating > 0) {
                if ($at >= $d30) $r30[] = $rating;
                elseif ($at >= $d60) $rPrev[] = $rating;
            }
            foreach (self::aspects($text, $rating) as [$key, $pol, $quote]) {
                $topics[$key][$pol][] = $quote;
            }
        }
        usort($unanswered, static fn ($a, $b) => $b['days'] <=> $a['days']);
        $avg = static fn (array $a) => $a ? round(array_sum($a) / count($a), 2) : null;
        $label = static fn (string $k) => self::TOPICS[$k][$ar ? 0 : 1];

        $list = [];
        foreach ($topics as $k => $p) {
            $list[] = ['key' => $k, 'label' => $label($k), 'praise' => count($p['pos'] ?? []), 'complaints' => count($p['neg'] ?? []),
                'praise_quote' => self::bestQuote($p['pos'] ?? []), 'complaint_quote' => self::bestQuote($p['neg'] ?? [])];
        }
        $strengths = array_values(array_filter($list, static fn ($t) => $t['praise'] > 0));
        usort($strengths, static fn ($a, $b) => [$b['praise'], $a['complaints']] <=> [$a['praise'], $b['complaints']]);
        $weak = array_values(array_filter($list, static fn ($t) => $t['complaints'] > 0));
        usort($weak, static fn ($a, $b) => [$b['complaints'], $a['praise']] <=> [$a['complaints'], $b['praise']]);

        foreach (['strengths' => 'praise_quote', 'weak' => 'complaint_quote'] as $var => $q) {
            $used = [];
            foreach ($$var as $i => $t) {
                if ($t[$q] !== '' && isset($used[$t[$q]])) {
                    $$var[$i][$q] = '';
                }
                $used[$t[$q]] = true;
            }
        }
        $rate = $total ? (int) round($replied * 100 / $total) : 0;
        $avg30 = $avg($r30);
        $avgPrev = $avg($rPrev);
        $allRatings = array_values(array_filter(array_map(static fn ($r) => (float) ($r['rating'] ?? 0), $reviews)));
        $avgAll = $avg($allRatings);
        $respH = $hours ? (int) round(array_sum($hours) / count($hours)) : null;

        // صحة السمعة: التقييم 40 + الرد 25 + السلبي اللي اتردّ عليه 15 + عدد التقييمات الأخيرة 10 + الاتجاه 10
        $score = 0;
        if ($avgAll !== null) $score += max(0, ($avgAll - 1) / 4) * 40;
        $score += $rate / 100 * 25;
        $negTotal = count(array_filter($reviews, static fn ($r) => (float) ($r['rating'] ?? 0) > 0 && (float) ($r['rating'] ?? 0) <= 3));
        $score += $negTotal ? (1 - count($unanswered) / $negTotal) * 15 : 15;
        $score += min(1, count($r30) / 6) * 10;
        $score += $avg30 === null || $avgPrev === null || $avg30 >= $avgPrev - 0.05 ? 10 : max(0, 10 - ($avgPrev - $avg30) * 20);
        $score = (int) round(max(0, min(100, $score)));

        return [
            'score' => $total ? $score : null,
            'total' => $total,
            'avg_rating' => $avgAll,
            'count_30' => count($r30),
            'avg_30' => $avg30,
            'avg_prev_30' => $avgPrev,
            'response_rate' => $rate,
            'response_hours' => $respH,
            'unanswered_negative' => array_slice($unanswered, 0, 10),
            'unanswered_count' => count($unanswered),
            'strengths' => array_slice($strengths, 0, 3),
            'weaknesses' => array_slice($weak, 0, 3),
            'actions' => self::actions($total, $rate, $respH, $unanswered, $avg30, $avgPrev, count($r30), $strengths, $weak, $ar),
        ];
    }

    /**
     * المواضيع في نص واحد، كل جملة لوحدها: [موضوع, pos|neg, الجملة]
     * @return array<int,array{0:string,1:string,2:string}>
     */
    public static function aspects(string $text, float $rating = 0): array
    {
        if ($text === '') {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (preg_split('/(?<=[.!?؟؛;])\s+|\n+|\s+(?=but\b|however\b|aber\b|mais\b|pero\b|ma\b|لكن|بس\s)/iu', $text) as $sentence) {
            $s = trim($sentence);
            if (mb_strlen($s) < 8) {
                continue;
            }
            // جملة فيها مدح وشكوى ("great guide but late pickup") بتتقسم عند but؛ لو لسه فيها الاتنين:
            // كلمة شكوى صريحة (اتأخر، وسخ، غالي) تغلب، وإلا النجوم هي اللي بتحكم
            $plain = preg_replace('/\bno problems?\b|without (any )?(problems?|issues?)|not a problem|kein problem|sans problème|sin problemas?|nessun problema|مفيش مشاكل|من غير مشاكل/iu', '', $s);
            $neg = preg_match(self::NEG, $plain) === 1;
            $pos = preg_match(self::POS, $s) === 1;
            if ($neg && !$pos) {
                $pol = 'neg';
            } elseif ($pos && !$neg) {
                $pol = 'pos';
            } elseif ($pos && $neg) {
                $pol = preg_match('/\b(late|delay\w*|waited|rude|dirty|overpriced|rushed|pushy|forced|scam)\b|اتأخر|تأخير|وحش|وسخ|غالي|ضغط/iu', $s) || $rating <= 3 ? 'neg' : 'pos';
            } else {
                $pol = $rating >= 4 ? 'pos' : ($rating > 0 && $rating <= 2 ? 'neg' : '');
            }
            if ($pol === '') {
                continue;
            }
            foreach (self::TOPICS as $key => [, , $re]) {
                if (isset($seen[$key . $pol])) {
                    continue;
                }
                if (preg_match('/(?<![\p{L}])(' . $re . ')(?![\p{L}])/iu', $s)) {
                    $out[] = [$key, $pol, mb_substr(preg_replace('/^(but|however|aber|mais|pero|ma|لكن|بس)\s+/iu', '', $s), 0, 160)];
                    $seen[$key . $pol] = true;
                }
            }
        }
        return $out;
    }

    private static function days(int $d): string
    {
        return $d <= 1 ? 'يوم' : ($d === 2 ? 'يومين' : ($d <= 10 ? $d . ' أيام' : $d . ' يوم'));
    }

    /** أوضح جملة كدليل: مش قصيرة أوي ولا طويلة أوي */
    private static function bestQuote(array $quotes): string
    {
        if (!$quotes) {
            return '';
        }
        usort($quotes, static fn ($a, $b) => abs(mb_strlen($a) - 70) <=> abs(mb_strlen($b) - 70));
        return rtrim($quotes[0], ' .!?؟');
    }

    /** خطوات مرتّبة بالأولوية، بأرقام العميل نفسه - زي تقرير استشاري */
    private static function actions(int $total, int $rate, ?int $respH, array $unanswered, ?float $avg30, ?float $avgPrev, int $count30, array $strengths, array $weak, bool $ar): array
    {
        $L = static fn (string $a, string $e) => $ar ? $a : $e;
        $cnt = static fn (int $k) => $k === 1 ? 'تقييم واحد' : ($k === 2 ? 'تقييمين' : ($k <= 10 ? $k . ' تقييمات' : $k . ' تقييم'));
        $a = [];
        if (!$total) {
            return [['priority' => 'high', 'text' => $L('مفيش تقييمات متزامنة لسه. اربط Google وTripAdvisor من «ربط منصة جديدة» واضغط «زامن دلوقتي».', 'No synced reviews yet. Connect Google and TripAdvisor and press "Sync now".')]];
        }
        if ($unanswered) {
            $o = $unanswered[0];
            $a[] = ['priority' => 'high', 'text' => $L(
                'رد على التقييمات السلبية اللي لسه من غير رد (' . count($unanswered) . '). ' . (count($unanswered) > 1 ? 'أقدمهم' : 'التقييم') . ' من ' . self::days($o['days']) . ($o['name'] !== '' ? ' (' . $o['name'] . ')' : '') . '. اللي بيقرا بيحكم عليكم من ردكم على السلبي أكتر من التقييم نفسه.',
                'Reply to ' . count($unanswered) . ' unanswered negative review(s). The oldest is ' . $o['days'] . ' days old. Readers judge you by how you answer criticism.'
            ), 'link' => '/reputation/reviews'];
        }
        if ($weak) {
            $w = $weak[0];
            $a[] = ['priority' => $w['complaints'] >= 3 ? 'high' : 'medium', 'text' => $L(
                'أكتر حاجة العملاء بيشتكوا منها: ' . $w['label'] . ' (' . $cnt($w['complaints']) . ').' . ($w['complaint_quote'] !== '' ? ' مثال: «' . $w['complaint_quote'] . '».' : '') . ' دي محتاجة تتصلّح في التشغيل نفسه، مش في الرد بس.',
                'Top complaint: ' . $w['label'] . ' (' . $w['complaints'] . ' reviews).' . ($w['complaint_quote'] !== '' ? ' Example: "' . $w['complaint_quote'] . '".' : '') . ' Fix it in operations, not only in replies.'
            )];
            if ($w['key'] === 'shopping') {
                $a[] = ['priority' => 'medium', 'text' => $L('وقفات المحلات والعمولات بتنزّل التقييم بسرعة على TripAdvisor. خلّيها اختيارية، واكتب ده بوضوح في وصف الرحلة.', 'Shopping stops hurt ratings quickly. Make them optional and say so in the tour description.')];
            }
        }
        if ($avg30 !== null && $avgPrev !== null && $avg30 < $avgPrev - 0.1) {
            $a[] = ['priority' => 'high', 'text' => $L('تقييمكم آخر 30 يوم (' . $avg30 . ') نزل عن الشهر اللي قبله (' . $avgPrev . '). راجعوا التقييمات الأخيرة: غالبًا فيه مشكلة جديدة في رحلة أو موظف.', 'Your last-30-day rating (' . $avg30 . ') dropped from the previous month (' . $avgPrev . '). Check recent reviews for a new problem.')];
        }
        if ($rate < 80) {
            $a[] = ['priority' => $rate < 50 ? 'high' : 'medium', 'text' => $L('معدل ردكم ' . $rate . '%. الشركات اللي سمعتها قوية بترد على كل التقييمات تقريبًا. خلّي الرد على 4 و5 نجوم أوتوماتيك من «مركز الأتمتة»، وراجع السلبي بنفسك.', 'Your reply rate is ' . $rate . '%. Strong brands answer almost every review. Automate 4–5 star replies and review negatives yourself.'), 'link' => '/automation'];
        }
        if ($respH !== null && $respH > 48) {
            $a[] = ['priority' => 'medium', 'text' => $L('متوسط وقت الرد ' . self::days((int) round($respH / 24)) . '. الهدف أقل من 24 ساعة، خصوصًا للسلبي. تنبيهات التقييمات السلبية بتوصلك على الإيميل فورًا.', 'Average reply time is ' . round($respH / 24, 1) . ' days. Aim for under 24 hours, especially for negatives.')];
        }
        if ($count30 < 6) {
            $a[] = ['priority' => 'medium', 'text' => $L('جالكم ' . $cnt($count30) . ' بس آخر 30 يوم. التقييمات الجديدة بترفعكم في Google وTripAdvisor. ابعت طلب تقييم لكل عميل بعد الرحلة بيوم من «اطلب تقييمات».', 'Only ' . $count30 . ' reviews in the last 30 days. Fresh reviews lift your ranking. Send a review request the day after every trip.'), 'link' => '/review-requests'];
        }
        if ($strengths) {
            $s = $strengths[0];
            $a[] = ['priority' => 'low', 'text' => $L('أكتر حاجة العملاء بيحبوها: ' . $s['label'] . ' (' . $cnt($s['praise']) . ').' . ($s['praise_quote'] !== '' ? ' زي: «' . $s['praise_quote'] . '».' : '') . ' حطوها في إعلاناتكم وصفحات الرحلات، دي ميزتكم قدام المنافسين.', 'What guests love most: ' . $s['label'] . ' (' . $s['praise'] . ' reviews). Use it in ads and tour pages.')];
        }
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($a, static fn ($x, $y) => $order[$x['priority']] <=> $order[$y['priority']]);
        return array_slice($a, 0, 6);
    }
}
