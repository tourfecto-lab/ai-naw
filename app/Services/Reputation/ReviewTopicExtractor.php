<?php

/**
 * Tourfecto - Review Topic Extractor
 * @version 1.0.0
 *
 * استخراج موضوعات/عوامل المراجعات ديناميكيًا (G4 في التحليل التنافسي):
 * كان التحليل السابق يعتمد على كلمات مفتاحية ثابتة في واجهة النظرة العامة
 * (JS فقط، وتقتصر على المراجعات السلبية). هذا الكلاس ينقل المنطق إلى
 * Server-Side بتصنيف مصطلحات ثنائي اللغة (عربي/إنجليزي) خاص بالقطاع
 * السياحي والفندقي، ويزن بعض الكلمات القوية بمعامل مضاعف، ثم يجمع
 * الموضوعات عبر نصوص المراجعات مع تقسيمها حسب المشاعر ومتوسط التقييم.
 *
 * لا يعتمد على أي LLM خارجي (بلا Credits)، لذا يعمل دائمًا وبدون تكلفة.
 * لو النصوص فارغة/لا تطابق أي موضوع، يرجع مصفوفة فارغة بأمان.
 */
class ReviewTopicExtractor
{
    /**
     * تصنيف الموضوعات: كل موضوع له مفتاح، تسميات ثنائية اللغة، وقوائم
     * كلمات (عربي/إنجليزي) + كلمات قوية بوزن مضاعف (شكوى مباشرة واضحة).
     *
     * @return array<string, array{label_ar:string, label_en:string, keywords:array<int,string>, strong:array<int,string>}>
     */
    public function taxonomy(): array
    {
        return [
            'cleanliness' => [
                'label_ar' => 'النظافة',
                'label_en' => 'Cleanliness',
                'keywords' => ['نظاف', 'نظيفة', 'نظيف', 'وسخ', 'متسخ', 'غبار', 'ريحة', 'رائحة', 'أنيق', 'مرتب',
                    'clean', 'dirty', 'dust', 'smell', 'tidy', 'spotless', 'stain', 'grime', 'hygiene'],
                'strong' => ['وسخ', 'متسخ', 'dirty', 'grime'],
            ],
            'staff_service' => [
                'label_ar' => 'الخدمة والموظفون',
                'label_en' => 'Staff & service',
                'keywords' => ['موظف', 'موظفين', 'خدمة', 'استقبال', 'تعامل', 'لطيف', 'متعاون', 'محترم', 'ترحيب',
                    'staff', 'service', 'reception', 'employee', 'friendly', 'helpful', 'courteous', 'welcome', 'front desk'],
                'strong' => ['rude', 'وقح', 'سيئ جدا', 'مش متعاون'],
            ],
            'room_quality' => [
                'label_ar' => 'جودة الغرفة',
                'label_en' => 'Room quality',
                'keywords' => ['غرفة', 'سرير', 'مكيف', 'تكييف', 'صوت', 'هدوء', 'إطلالة', 'اطلالة', 'ديكور', 'أثاث',
                    'room', 'bed', 'view', 'noise', 'quiet', 'pillow', 'furniture', 'balcony', 'air conditioning'],
                'strong' => ['عطل المكيف', 'مكيف واقف', 'سرير مكسور', 'noisy', 'صاخب'],
            ],
            'price_value' => [
                'label_ar' => 'السعر والقيمة',
                'label_en' => 'Pricing & value',
                'keywords' => ['سعر', 'اسعار', 'أسعار', 'فلوس', 'مكلف', 'رخيص', 'قيمة', 'فاتورة', 'ثمن', 'تكلفة',
                    'price', 'expensive', 'cheap', 'value', 'cost', 'bill', 'worth', 'overpriced', 'affordable'],
                'strong' => ['مكلف جدا', 'غالي جدا', 'overpriced', 'زاد السعر'],
            ],
            'food_dining' => [
                'label_ar' => 'الطعام',
                'label_en' => 'Food & dining',
                'keywords' => ['أكل', 'اكل', 'طعام', 'فطور', 'إفطار', 'عشاء', 'غداء', 'مطعم', 'مذاق', 'وجبة', 'بوفيه', 'مشروب',
                    'food', 'breakfast', 'dinner', 'lunch', 'restaurant', 'taste', 'meal', 'buffet', 'drink'],
                'strong' => ['أكل مش حلو', 'اكل وحش', 'تسمم', 'food poisoning'],
            ],
            'location_accessibility' => [
                'label_ar' => 'الموقع والوصول',
                'label_en' => 'Location & access',
                'keywords' => ['موقع', 'قريب', 'وسط', 'مركزي', 'وصول', 'مواصلات', 'الشاطئ', 'مطار', 'تمشية', 'شارع',
                    'location', 'near', 'central', 'access', 'transport', 'airport', 'beach', 'walk', 'street'],
                'strong' => ['بعيد جدا', 'مواصلات صعبة', 'far away', 'bad location'],
            ],
            'wifi_connectivity' => [
                'label_ar' => 'الإنترنت والاتصال',
                'label_en' => 'WiFi & connectivity',
                'keywords' => ['واي فاي', 'وايفاي', 'إنترنت', 'انترنت', 'نت', 'شبكة', 'اتصال', 'إشارة', 'تغطية',
                    'wifi', 'internet', 'network', 'connection', 'signal', 'coverage', '5g', '4g', 'broadband'],
                'strong' => ['واي فاي ضعيف', 'لا انترنت', 'wifi weak', 'no internet'],
            ],
            'booking_process' => [
                'label_ar' => 'الحجز والإجراءات',
                'label_en' => 'Booking & process',
                'keywords' => ['حجز', 'إلغاء', 'تأكيد', 'دفع', 'دفعة', 'إيداع', 'سياسة', 'استرداد', 'تسجيل', 'مغادرة',
                    'booking', 'cancel', 'confirmation', 'payment', 'deposit', 'refund', 'policy', 'check-in', 'check-out'],
                'strong' => ['رفض استرداد', 'no refund', 'cancel fee'],
            ],
            'safety_security' => [
                'label_ar' => 'الأمان والخصوصية',
                'label_en' => 'Safety & security',
                'keywords' => ['أمان', 'امان', 'آمن', 'سرقة', 'خزنة', 'قفل', 'أمن', 'مخيف', 'خصوصية', 'مراقبة',
                    'safe', 'secure', 'security', 'theft', 'lock', 'unsafe', 'privacy', 'surveillance'],
                'strong' => ['اتسرقت', 'سرقة', 'theft', 'unsafe'],
            ],
            'transfers_tours' => [
                'label_ar' => 'النقل والجولات',
                'label_en' => 'Transfers & tours',
                'keywords' => ['جولة', 'سفاري', 'غوص', 'قارب', 'مرشد', 'انتقال', 'باص', 'مرسى', 'يخت', 'جولات',
                    'tour', 'safari', 'diving', 'boat', 'guide', 'transfer', 'bus', 'shuttle', 'yacht'],
                'strong' => ['جولة ملغاة', 'canceled tour', 'غوص ملغي'],
            ],
        ];
    }

    /** تسمية ثنائية اللغة مناسبة للعرض (تستخدم العربية افتراضيًا) */
    public function topicLabel(string $key): string
    {
        $taxonomy = $this->taxonomy();
        return $taxonomy[$key]['label_ar'] ?? $taxonomy[$key]['label_en'] ?? $key;
    }

    /**
     * استخراج الموضوعات المطابقة لنص مراجعة واحد.
     *
     * @return array<int, array{key:string, label:string, score:int}>
     */
    public function extractFromText(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (trim($text) === '') {
            return [];
        }

        $matches = [];
        foreach ($this->taxonomy() as $key => $def) {
            $score = 0;
            foreach ($def['keywords'] as $kw) {
                if ($kw !== '' && mb_strpos($text, mb_strtolower($kw, 'UTF-8')) !== false) {
                    $score += 1;
                }
            }
            foreach ($def['strong'] as $kw) {
                if ($kw !== '' && mb_strpos($text, mb_strtolower($kw, 'UTF-8')) !== false) {
                    $score += 2;
                }
            }
            if ($score > 0) {
                $matches[] = ['key' => $key, 'label' => $this->topicLabel($key), 'score' => $score];
            }
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $matches;
    }

    /**
     * تجميع الموضوعات عبر مجموعة مراجعات:
     * كل مراجعة (مصفوفة فيها review_text + اختياري sentiment/rating) تساهم
     * بموضوعاتها المطابقة، مع توزيع المشاعر ومتوسط التقييم وحصة الظهور.
     *
     * @param array<int, array{review_text:string, sentiment?:string, rating?:float|int|string}> $reviews
     * @return array<int, array{key:string, label:string, count:int, positive:int, neutral:int, negative:int, avg_rating:float, share_percent:float}>
     */
    public function extractTopics(array $reviews, int $limit = 8): array
    {
        $totals = [];
        $matchedReviews = 0;

        foreach ($reviews as $review) {
            $text = (string) ($review['review_text'] ?? '');
            $matched = $this->extractFromText($text);
            if (empty($matched)) {
                continue;
            }
            $matchedReviews++;

            $sentiment = $this->normalizeSentiment($review);
            $rating = (float) ($review['rating'] ?? 0);

            foreach ($matched as $m) {
                if (!isset($totals[$m['key']])) {
                    $totals[$m['key']] = ['count' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'rating_sum' => 0.0, 'label' => $m['label']];
                }
                $totals[$m['key']]['count']++;
                $totals[$m['key']][$sentiment]++;
                $totals[$m['key']]['rating_sum'] += $rating;
            }
        }

        $out = [];
        foreach ($totals as $key => $t) {
            $out[] = [
                'key' => $key,
                'label' => $t['label'],
                'count' => $t['count'],
                'positive' => $t['positive'],
                'neutral' => $t['neutral'],
                'negative' => $t['negative'],
                'avg_rating' => round($t['rating_sum'] / max(1, $t['count']), 2),
                'share_percent' => $matchedReviews > 0 ? round(($t['count'] / $matchedReviews) * 100, 1) : 0.0,
            ];
        }

        usort($out, function ($a, $b) {
            if ($b['count'] !== $a['count']) {
                return $b['count'] <=> $a['count'];
            }
            return $b['negative'] <=> $a['negative'];
        });

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * أهم الموضوعات في المراجعات السلبية - لاقتراحات التحسين (بديل
     * المنطق الثابت القديم في واجهة النظرة العامة).
     *
     * @return array<int, array{key:string, label:string, count:int, priority:string}>
     */
    public function topTopicsForNegative(array $reviews, int $limit = 5): array
    {
        $counts = [];
        foreach ($reviews as $review) {
            $sentiment = $this->normalizeSentiment($review);
            if ($sentiment !== 'negative') {
                continue;
            }
            $matched = $this->extractFromText((string) ($review['review_text'] ?? ''));
            foreach ($matched as $m) {
                $counts[$m['key']] = ['key' => $m['key'], 'label' => $m['label'], 'count' => ($counts[$m['key']]['count'] ?? 0) + 1];
            }
        }

        $list = array_values($counts);
        usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);

        foreach ($list as $i => $item) {
            $list[$i]['priority'] = $item['count'] >= 3 ? 'high' : ($item['count'] === 2 ? 'medium' : 'low');
        }

        return array_slice($list, 0, max(1, $limit));
    }

    /** تطبيع مشاعر المراجعة: positive/neutral/negative مع استنتاج من التقييم عند غيابها */
    private function normalizeSentiment(array $review): string
    {
        $sentiment = strtolower((string) ($review['sentiment'] ?? ''));
        if (in_array($sentiment, ['positive', 'neutral', 'negative', 'mixed'], true)) {
            return $sentiment === 'mixed' ? 'neutral' : $sentiment;
        }
        $rating = (float) ($review['rating'] ?? 0);
        if ($rating > 0) {
            return $rating >= 4 ? 'positive' : ($rating <= 2 ? 'negative' : 'neutral');
        }
        return 'neutral';
    }
}
