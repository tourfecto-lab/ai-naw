<?php

/**
 * Tourfecto - أكواد الخصم
 *
 * evaluate(): بيتأكد إن الكود ينفع (شغّال، في ميعاده، مااتخلصش، مش متستخدم قبل
 *             كده من نفس العميل، للباقة دي، وأول دفعة لو مطلوب) وبيحسب الخصم.
 * redeem():   بيسجّل الاستخدام (جوه نفس الـ transaction بتاع الخصم من المحفظة).
 *
 * الكود بيتطبّق على الاشتراك الجديد بس (مش على فرق تغيير الباقة)، والخصم
 * عمره ما يعدّي سعر الباقة.
 *
 * @version 1.0.0
 */
class CouponService
{
    /** @var Database */
    private $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', trim($code)));
    }

    /**
     * @return array{ok:bool, error?:string, coupon_id?:int, code?:string, discount?:float, final?:float, label?:string}
     */
    public function evaluate(string $code, int $userId, string $planKey, float $amount, bool $isPlanChange = false): array
    {
        $code = self::normalize($code);
        if ($code === '') {
            return ['ok' => false, 'error' => 'اكتب كود الخصم'];
        }
        if ($isPlanChange) {
            return ['ok' => false, 'error' => 'كود الخصم بيتطبّق على الاشتراك الجديد بس، مش على تغيير الباقة'];
        }
        try {
            $rows = $this->db->query('SELECT * FROM coupons WHERE code = ? LIMIT 1', [$code]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'أكواد الخصم مش متاحة دلوقتي'];
        }
        $c = $rows[0] ?? null;
        if (!$c || empty($c['is_active'])) {
            return ['ok' => false, 'error' => 'الكود ده مش صحيح'];
        }
        $now = time();
        if (!empty($c['valid_from']) && strtotime($c['valid_from']) > $now) {
            return ['ok' => false, 'error' => 'الكود ده لسه مابدأش'];
        }
        if (!empty($c['valid_until']) && strtotime($c['valid_until']) < $now) {
            return ['ok' => false, 'error' => 'الكود ده انتهى'];
        }
        $plans = json_decode((string) ($c['plan_codes'] ?? ''), true);
        if (is_array($plans) && $plans && !in_array($planKey, $plans, true)) {
            return ['ok' => false, 'error' => 'الكود ده مش للباقة دي'];
        }
        $used = (int) ($this->db->query('SELECT COUNT(*) v FROM coupon_redemptions WHERE coupon_id = ?', [(int) $c['id']])[0]['v'] ?? 0);
        if ($c['max_redemptions'] !== null && $used >= (int) $c['max_redemptions']) {
            return ['ok' => false, 'error' => 'الكود ده خلص'];
        }
        $mine = (int) ($this->db->query('SELECT COUNT(*) v FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?', [(int) $c['id'], $userId])[0]['v'] ?? 0);
        if ($mine >= max(1, (int) $c['per_user_limit'])) {
            return ['ok' => false, 'error' => 'انت استخدمت الكود ده قبل كده'];
        }
        if (!empty($c['first_payment_only'])) {
            $paid = (int) ($this->db->query("SELECT COUNT(*) v FROM invoices WHERE user_id = ? AND status = 'paid'", [$userId])[0]['v'] ?? 0);
            if ($paid > 0) {
                return ['ok' => false, 'error' => 'الكود ده لأول اشتراك بس'];
            }
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'مفيش مبلغ يتخصم منه'];
        }
        $discount = $c['discount_type'] === 'percent'
            ? round($amount * min(100, (float) $c['amount']) / 100, 2)
            : min($amount, round((float) $c['amount'], 2));
        return [
            'ok' => true,
            'coupon_id' => (int) $c['id'],
            'code' => $code,
            'discount' => $discount,
            'final' => round($amount - $discount, 2),
            'label' => $c['discount_type'] === 'percent' ? rtrim(rtrim(number_format((float)$c['amount'],2,'.',''), '0'), '.') . '%' : '$' . $c['amount'],
        ];
    }

    /**
     * تسجيل استخدام الكود - لازم يتنده جوه transaction.
     * 🔒 بيقفل صف الكوبون (FOR UPDATE) ويعيد عدّ الاستخدامات، عشان لو اتنين استخدموا آخر
     * كود في نفس اللحظة واحد بس ياخده (قبل كده الحد العام كان ممكن يتعدّى).
     * @throws Exception برسالة تبدأ بـ COUPON: لو الكود خلص أو اتقفل
     */
    public function redeem(int $couponId, int $userId, string $planKey, float $original, float $discount): void
    {
        $c = $this->db->query('SELECT id, is_active, max_redemptions, per_user_limit FROM coupons WHERE id = ? FOR UPDATE', [$couponId]);
        if (!$c || empty($c[0]['is_active'])) {
            throw new Exception('COUPON:الكود ده اتقفل');
        }
        if ($c[0]['max_redemptions'] !== null) {
            $used = (int) ($this->db->query('SELECT COUNT(*) v FROM coupon_redemptions WHERE coupon_id = ?', [$couponId])[0]['v'] ?? 0);
            if ($used >= (int) $c[0]['max_redemptions']) {
                throw new Exception('COUPON:الكود ده خلص');
            }
        }
        $mine = (int) ($this->db->query('SELECT COUNT(*) v FROM coupon_redemptions WHERE coupon_id = ? AND user_id = ?', [$couponId, $userId])[0]['v'] ?? 0);
        if ($mine >= max(1, (int) $c[0]['per_user_limit'])) {
            throw new Exception('COUPON:انت استخدمت الكود ده قبل كده');
        }
        $this->db->query(
            'INSERT INTO coupon_redemptions (coupon_id, user_id, plan_code, original_amount, discount_amount) VALUES (?, ?, ?, ?, ?)',
            [$couponId, $userId, $planKey, $original, $discount]
        );
    }

    private static function validDate($value, bool $end): ?string
    {
        if ($value===null || $value==='') return null;
        $value=str_replace('T',' ',trim((string)$value));
        $format=strlen($value)===10?'Y-m-d':(strlen($value)===16?'Y-m-d H:i':'Y-m-d H:i:s');
        $d=DateTimeImmutable::createFromFormat('!'.$format,$value);
        if (!$d || $d->format($format)!==$value) throw new InvalidArgumentException('Invalid date');
        if ($end && strlen($value)===10) $d=$d->setTime(23,59,59);
        return $d->format('Y-m-d H:i:s');
    }

    public function lockAndVerify(array $quoted, int $userId, string $planKey, bool $change): void
    {
        $row=$this->db->query('SELECT * FROM coupons WHERE id=? FOR UPDATE',[(int)$quoted['coupon_id']]);
        if (!$row) throw new RuntimeException('COUPON:الكود اتغير. طبّقه مرة أخرى.');
        $fresh=$this->evaluate($quoted['code'],$userId,$planKey,(float)$quoted['original'],$change);
        if (empty($fresh['ok']) || (int)$fresh['coupon_id']!==(int)$quoted['coupon_id'] || abs((float)$fresh['discount']-(float)$quoted['discount'])>0.00001) throw new RuntimeException('COUPON:الكود اتغير أو انتهى. طبّقه مرة أخرى.');
    }

    // ------------------------------------------------------------------ للأدمن

    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id) used,
                    (SELECT COALESCE(SUM(discount_amount), 0) FROM coupon_redemptions r WHERE r.coupon_id = c.id) total_discount
               FROM coupons c ORDER BY c.is_active DESC, c.id DESC'
        );
        foreach ($rows as &$r) {
            $r['plan_codes'] = json_decode((string) ($r['plan_codes'] ?? ''), true) ?: [];
            $r['is_active'] = (bool) $r['is_active'];
            $r['first_payment_only'] = (bool) $r['first_payment_only'];
            $r['expired'] = !empty($r['valid_until']) && strtotime($r['valid_until']) < time();
        }
        return $rows;
    }

    /** @return array{ok:bool, error?:string, id?:int} */
    public function save(array $in, ?int $id, int $adminId): array
    {
        $code = self::normalize((string) ($in['code'] ?? ''));
        if (strlen($code) < 3 || strlen($code) > 40) {
            return ['ok' => false, 'error' => 'الكود لازم يكون من 3 لـ 40 حرف/رقم (إنجليزي)'];
        }
        $type = ($in['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount <= 0 || ($type === 'percent' && $amount > 100)) {
            return ['ok' => false, 'error' => $type === 'percent' ? 'النسبة لازم تكون من 1 لـ 100' : 'المبلغ لازم يكون أكبر من صفر'];
        }
        $rawMax=$in['max_redemptions']??null;
        $max = $rawMax === '' || $rawMax === null ? null : max(1,(int)$rawMax);
        $per = max(1, (int) ($in['per_user_limit'] ?? 1));
        try { $from=self::validDate($in['valid_from']??null,false); $until=self::validDate($in['valid_until']??null,true); } catch (Throwable $e) { return ['ok'=>false,'error'=>'اكتب تاريخ صلاحية صحيح']; }
        if ($from && $until && strtotime($until) < strtotime($from)) {
            return ['ok' => false, 'error' => 'تاريخ النهاية قبل البداية'];
        }
        $plans = array_values(array_filter(array_map('strval', (array) ($in['plan_codes'] ?? []))));
        $vals = [
            $code, mb_substr((string) ($in['description'] ?? ''), 0, 255) ?: null, $type, $amount, $max, $per, $from, $until,
            $plans ? json_encode($plans) : null, !empty($in['first_payment_only']) ? 1 : 0, array_key_exists('is_active', $in) ? (!empty($in['is_active']) ? 1 : 0) : 1,
        ];
        $dup = $this->db->query('SELECT id FROM coupons WHERE code = ? AND id <> ? LIMIT 1', [$code, (int) $id]);
        if ($dup) {
            return ['ok' => false, 'error' => 'فيه كود بنفس الاسم'];
        }
        if ($id) {
            $this->db->query(
                'UPDATE coupons SET code=?, description=?, discount_type=?, amount=?, max_redemptions=?, per_user_limit=?, valid_from=?, valid_until=?, plan_codes=?, first_payment_only=?, is_active=? WHERE id=?',
                array_merge($vals, [$id])
            );
            return ['ok' => true, 'id' => $id];
        }
        $newId = $this->db->query(
            'INSERT INTO coupons (code, description, discount_type, amount, max_redemptions, per_user_limit, valid_from, valid_until, plan_codes, first_payment_only, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge($vals, [$adminId])
        );
        return ['ok' => true, 'id' => (int) $newId];
    }
}
