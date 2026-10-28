<?php

/**
 * Tourfecto - تنبيه لما منافس يغيّر سعر رحلة/منتج معيّن + مقارنة بسعرك انت
 *
 * الأسعار جاية من مواقع المنافسين نفسهم (اللقطات الدورية اللي بتحترم robots.txt)، مش من
 * Booking/TripAdvisor (شروطهم بتمنع السحب الآلي). قبل كده كان فيه تنبيه عام "الصفحة اتغيرت" بس؛
 * دلوقتي لو سعر منتج بعينه اتغير ≥ 5% بيوصلك: مين، أنهي رحلة، من كام لكام، وسعرك انت لأقرب رحلة عندك.
 */
class PriceChangeAlertService
{
    private const MIN_CHANGE_PCT = 5.0;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** آخر سعر متسجّل قبل القراءة الجديدة */
    public function previousPrice(int $competitorId, string $productName): ?array
    {
        try {
            return $this->db->query(
                'SELECT price, currency, detected_at FROM ci_product_prices WHERE competitor_id = ? AND product_name = ? ORDER BY detected_at DESC, id DESC LIMIT 1',
                [$competitorId, $productName]
            )[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function check(int $competitorId, string $productName, ?array $prev, float $newPrice, string $currency, ?string $sourceUrl = null): ?array
    {
        if (!$prev || (float) $prev['price'] <= 0 || strtoupper((string) $prev['currency']) !== strtoupper($currency)) {
            return null;
        }
        $old = (float) $prev['price'];
        $pct = ($newPrice - $old) / $old * 100;
        if (abs($pct) < self::MIN_CHANGE_PCT || abs($pct) > 90) {
            return null; // تغيير صغير، أو كبير بشكل غير منطقي (غالبًا قراءة غلط من الصفحة)
        }
        try {
            $c = $this->db->query('SELECT id, user_id, competitor_name, competitor_domain FROM competitors WHERE id = ? LIMIT 1', [$competitorId])[0] ?? null;
            if (!$c) {
                return null;
            }
            $userId = (int) $c['user_id'];
            $paused = $this->db->query('SELECT is_paused FROM ci_watchlist WHERE user_id = ? AND competitor_id = ? LIMIT 1', [$userId, $competitorId]);
            if ($paused && (int) $paused[0]['is_paused'] === 1) {
                return null;
            }
            $dup = $this->db->query(
                "SELECT id FROM ci_alerts WHERE competitor_id = ? AND type = 'product_price_change' AND title LIKE ? AND created_at > (NOW() - INTERVAL 1 DAY) LIMIT 1",
                [$competitorId, '%' . mb_substr($productName, 0, 60) . '%']
            );
            if ($dup) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $name = (string) ($c['competitor_name'] ?: $c['competitor_domain']);
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');
        $dir = $pct < 0 ? 'نزّل' : 'رفع';
        $title = $name . ' ' . $dir . ' سعر "' . mb_substr($productName, 0, 80) . '"';
        $msg = 'من ' . $fmt($old) . ' لـ ' . $fmt($newPrice) . ' ' . $currency . ' (' . ($pct > 0 ? '+' : '') . round($pct, 1) . '%).';

        $own = $this->matchOwnProduct($userId, $productName, $currency);
        if ($own) {
            $diff = ((float) $own['price'] - $newPrice) / $newPrice * 100;
            $msg .= ' سعرك لـ "' . $own['name'] . '": ' . $fmt($own['price']) . ' ' . $currency;
            if (abs($diff) < 3) {
                $msg .= ' (نفس سعرهم تقريبًا).';
            } elseif ($diff > 0) {
                $msg .= ' (أغلى منهم بـ ' . round($diff) . '%' . ($pct < 0 ? ' - فكّر في عرض أو وضّح ليه رحلتك تستاهل أكتر' : '') . ').';
            } else {
                $msg .= ' (أرخص منهم بـ ' . round(abs($diff)) . '%' . ($pct > 0 ? ' - ممكن يبقى عندك مساحة ترفع سعرك شوية' : '') . ').';
            }
        }

        $severity = abs($pct) >= 15 ? 'high' : 'medium';
        try {
            (new CiAlert([
                'user_id' => $userId,
                'competitor_id' => $competitorId,
                'change_id' => null,
                'type' => 'product_price_change',
                'severity' => $severity,
                'title' => mb_substr($title, 0, 255),
                'message' => mb_substr($msg . ($sourceUrl ? ' ' . $sourceUrl : ''), 0, 1000),
                'channel' => 'dashboard',
                'is_read' => 0,
            ]))->save();
            if (class_exists('Notification')) {
                Notification::notify($userId, 'competitor_price_change', '💲 ' . $title, $msg, '/competitor-intelligence');
            }
        } catch (Throwable $e) {
            Logger::warning('price change alert failed', ['error' => $e->getMessage()]);
            return null;
        }
        return ['title' => $title, 'message' => $msg, 'severity' => $severity, 'own' => $own];
    }

    /** أقرب رحلة عند صاحب الحساب لاسم منتج المنافس (تشابه الكلمات) بنفس العملة */
    public function matchOwnProduct(int $userId, string $competitorProduct, string $currency): ?array
    {
        try {
            $rows = $this->db->query('SELECT name, price, currency FROM crm_products WHERE user_id = ? AND is_active = 1 AND price > 0 LIMIT 300', [$userId]);
        } catch (Throwable $e) {
            return null;
        }
        $a = self::tokens($competitorProduct);
        if (count($a) === 0) {
            return null;
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($rows as $r) {
            if (strtoupper((string) $r['currency']) !== strtoupper($currency)) {
                continue;
            }
            $b = self::tokens((string) $r['name']);
            if (!$b) {
                continue;
            }
            $inter = count(array_intersect($a, $b));
            $score = $inter / count(array_unique(array_merge($a, $b)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        return $bestScore >= 0.34 ? $best : null;
    }

    /**
     * أسماء الأماكن والأنشطة السياحية بالعربي ← كلمة إنجليزي موحّدة، عشان "رحلة الأقصر يوم كامل"
     * تتطابق مع "Luxor Full Day Tour". المفاتيح بعد التوحيد (أ/إ/آ→ا، ة→ه، ى→ي، من غير "ال").
     */
    private const SYNONYMS = [
        // أماكن
        'اقصر' => 'luxor', 'لوكسور' => 'luxor', 'اسوان' => 'aswan', 'قاهره' => 'cairo', 'جيزه' => 'giza',
        'اسكندريه' => 'alexandria', 'غردقه' => 'hurghada', 'شرم' => 'sharm', 'شيخ' => 'sheikh', 'دهب' => 'dahab',
        'مرسي' => 'marsa', 'علم' => 'alam', 'جونه' => 'gouna', 'سفاجا' => 'safaga', 'مكادي' => 'makadi', 'سهل' => 'sahl', 'حشيش' => 'hasheesh',
        'فيوم' => 'fayoum', 'سيوه' => 'siwa', 'سيناء' => 'sinai', 'سانت' => 'saint', 'كاترين' => 'catherine', 'نويبع' => 'nuweiba', 'طابا' => 'taba',
        'ابوسمبل' => 'simbel', 'سمبل' => 'simbel', 'ادفو' => 'edfu', 'كوم' => 'kom', 'امبو' => 'ombo', 'فيله' => 'philae', 'نوبه' => 'nubian', 'نوبي' => 'nubian', 'نوبيه' => 'nubian',
        'كرنك' => 'karnak', 'ملوك' => 'kings', 'ملكات' => 'queens', 'حتشبسوت' => 'hatshepsut', 'سقاره' => 'saqqara', 'دهشور' => 'dahshur', 'منف' => 'memphis',
        'اهرامات' => 'pyramid', 'اهرام' => 'pyramid', 'هرم' => 'pyramid', 'ابوالهول' => 'sphinx', 'هول' => 'sphinx', 'ابو' => 'abu',
        'نيل' => 'nile', 'بحر' => 'sea', 'احمر' => 'red', 'صحراء' => 'desert', 'بيضاء' => 'white', 'سوداء' => 'black', 'واحه' => 'oasis', 'واحات' => 'oasis',
        'متحف' => 'museum', 'مصري' => 'egyptian', 'كبير' => 'grand', 'خان' => 'khan', 'خليلي' => 'khalili', 'قلعه' => 'citadel', 'معبد' => 'temple', 'معابد' => 'temple',
        // أنشطة
        'فلوكه' => 'felucca', 'كروز' => 'cruise', 'باخره' => 'cruise', 'منطاد' => 'balloon', 'بالون' => 'balloon', 'سفاري' => 'safari', 'كواد' => 'quad', 'بجي' => 'buggy',
        'جمل' => 'camel', 'جمال' => 'camel', 'خيل' => 'horse', 'سنوركل' => 'snorkeling', 'غطس' => 'diving', 'غوص' => 'diving', 'جزيره' => 'island', 'جزر' => 'island',
        'دولفين' => 'dolphin', 'دلافين' => 'dolphin', 'غواصه' => 'submarine', 'عشاء' => 'dinner', 'غداء' => 'lunch', 'فطار' => 'breakfast', 'شروق' => 'sunrise', 'غروب' => 'sunset',
        'خاص' => 'private', 'خاصه' => 'private', 'جماعي' => 'group', 'كامل' => 'full', 'نص' => 'half', 'نصف' => 'half', 'ليلتين' => 'nights', 'ليالي' => 'nights',
        // إنجليزي بأشكال مختلفة ← نفس الكلمة
        'pyramids' => 'pyramid', 'temples' => 'temple', 'islands' => 'island', 'dolphins' => 'dolphin', 'camels' => 'camel', 'night' => 'nights',
        'snorkel' => 'snorkeling', 'snorkelling' => 'snorkeling', 'dive' => 'diving', 'hot' => 'balloon', 'air' => 'balloon', 'feluca' => 'felucca', 'faluka' => 'felucca',
        'hurgada' => 'hurghada', 'louxor' => 'luxor', 'assuan' => 'aswan', 'giza' => 'giza', 'gizeh' => 'giza',
    ];

    private static function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = str_replace(['أ', 'إ', 'آ', 'ة', 'ى'], ['ا', 'ا', 'ا', 'ه', 'ي'], $s);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'and', 'of', 'to', 'in', 'from', 'with', 'tour', 'trip', 'day', 'ride', 'excursion', 'رحله', 'رحلة', 'جوله', 'يوم', 'وقت', 'من', 'الي', 'في', 'مع', 'و'];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 3 || in_array($p, $stop, true)) {
                continue;
            }
            $p = preg_replace('/^ال/u', '', $p);
            // "وأبو"/"والنيل": واو العطف لازقة في الكلمة
            if (!isset(self::SYNONYMS[$p]) && mb_substr($p, 0, 1) === 'و' && mb_strlen($p) > 3) {
                $q = preg_replace('/^ال/u', '', mb_substr($p, 1));
                if (isset(self::SYNONYMS[$q])) {
                    $p = $q;
                }
            }
            $p = self::SYNONYMS[$p] ?? $p;
            if (in_array($p, ['tour', 'trip', 'day'], true)) {
                continue;
            }
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }
}
