<?php

/**
 * Discount coupons for the online bookings of a generated website.
 *
 * The discount is computed on the server from the booking's own calculated total (never from a number the browser sends),
 * is capped at MAX_DISCOUNT_SHARE of that total (a booking is never free: payment gateways reject zero), and is applied in
 * BookingEngine before the total is stored, so payments, refunds, revenue and agency commission all see the discounted total.
 * Usage is counted inside the booking transaction on a locked coupon row, so max_uses cannot be exceeded by parallel bookings.
 */
class SiteCouponService
{
    public const MAX_DISCOUNT_SHARE = 0.90;
    public const MAX_PER_SITE = 50;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public static function normalizeCode($code): ?string
    {
        $c = strtoupper(trim((string) $code));
        return preg_match('/^[A-Z0-9_-]{3,30}$/D', $c) ? $c : null;
    }

    private function ownsSite(int $ownerId, int $websiteId): bool
    {
        return (bool) $this->db->query('SELECT id FROM generated_websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $ownerId]);
    }

    /** @return array[] */
    public function listFor(int $ownerId, int $websiteId): array
    {
        if (!$this->ownsSite($ownerId, $websiteId)) {
            return [];
        }
        return $this->db->query('SELECT id, code, type, value, min_total, max_uses, used_count, valid_from, valid_until, is_active FROM site_coupons WHERE website_id = ? ORDER BY id DESC', [$websiteId]) ?: [];
    }

    public function hasActive(int $websiteId): bool
    {
        try {
            return (bool) $this->db->query(
                'SELECT id FROM site_coupons WHERE website_id = ? AND is_active = 1 AND (valid_until IS NULL OR valid_until >= UTC_DATE()) AND (max_uses IS NULL OR used_count < max_uses) LIMIT 1',
                [$websiteId]
            );
        } catch (Throwable $e) {
            return false; // table not applied yet: the booking form simply shows no coupon field
        }
    }

    /** @return array{ok:bool,error?:string,id?:int} */
    public function create(int $ownerId, int $websiteId, array $in): array
    {
        if (!$this->ownsSite($ownerId, $websiteId)) {
            return ['ok' => false, 'error' => 'الموقع مش موجود'];
        }
        $v = self::validate($in);
        if (!$v['ok']) {
            return $v;
        }
        $f = $v['fields'];
        if ((int) ($this->db->query('SELECT COUNT(*) AS c FROM site_coupons WHERE website_id = ?', [$websiteId])[0]['c'] ?? 0) >= self::MAX_PER_SITE) {
            return ['ok' => false, 'error' => 'وصلت الحد الأقصى من الكوبونات'];
        }
        if ($this->db->query('SELECT id FROM site_coupons WHERE website_id = ? AND code = ? LIMIT 1', [$websiteId, $f['code']])) {
            return ['ok' => false, 'error' => 'الكود ده موجود قبل كده'];
        }
        $this->db->query(
            'INSERT INTO site_coupons (website_id, owner_user_id, code, type, value, min_total, max_uses, valid_from, valid_until) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$websiteId, $ownerId, $f['code'], $f['type'], $f['value'], $f['min_total'], $f['max_uses'], $f['valid_from'], $f['valid_until']]
        );
        $id = (int) ($this->db->query('SELECT id FROM site_coupons WHERE website_id = ? AND code = ? LIMIT 1', [$websiteId, $f['code']])[0]['id'] ?? 0);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array{ok:bool,error?:string,fields?:array} */
    public static function validate(array $in): array
    {
        $code = self::normalizeCode($in['code'] ?? '');
        if ($code === null) {
            return ['ok' => false, 'error' => 'الكود 3-30 حرف إنجليزي أو أرقام أو - _'];
        }
        $type = (string) ($in['type'] ?? 'percent');
        if (!in_array($type, ['percent', 'fixed'], true)) {
            return ['ok' => false, 'error' => 'نوع الخصم غير صحيح'];
        }
        $value = $in['value'] ?? null;
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
            return ['ok' => false, 'error' => 'قيمة الخصم لازم تكون أكبر من صفر'];
        }
        $value = round((float) $value, 2);
        if (($type === 'percent' && $value > 90) || $value > 1000000) {
            return ['ok' => false, 'error' => $type === 'percent' ? 'نسبة الخصم لا تزيد عن 90%' : 'قيمة الخصم كبيرة جدًا'];
        }
        $min = $in['min_total'] ?? 0;
        if ($min === '' || $min === null) {
            $min = 0;
        }
        if (!is_numeric($min) || (float) $min < 0 || (float) $min > 100000000) {
            return ['ok' => false, 'error' => 'الحد الأدنى للحجز غير صحيح'];
        }
        $max = $in['max_uses'] ?? null;
        if ($max === '' || $max === null) {
            $max = null;
        } elseif (filter_var($max, FILTER_VALIDATE_INT) === false || (int) $max < 1 || (int) $max > 1000000) {
            return ['ok' => false, 'error' => 'عدد مرات الاستخدام غير صحيح'];
        } else {
            $max = (int) $max;
        }
        $dates = [];
        foreach (['valid_from', 'valid_until'] as $k) {
            $d = $in[$k] ?? null;
            if ($d === '' || $d === null) {
                $dates[$k] = null;
                continue;
            }
            $ts = strtotime((string) $d);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $d) || $ts === false) {
                return ['ok' => false, 'error' => 'التاريخ غير صحيح'];
            }
            $dates[$k] = (string) $d;
        }
        if ($dates['valid_from'] !== null && $dates['valid_until'] !== null && $dates['valid_until'] < $dates['valid_from']) {
            return ['ok' => false, 'error' => 'تاريخ النهاية قبل البداية'];
        }
        return ['ok' => true, 'fields' => ['code' => $code, 'type' => $type, 'value' => $value, 'min_total' => round((float) $min, 2), 'max_uses' => $max, 'valid_from' => $dates['valid_from'], 'valid_until' => $dates['valid_until']]];
    }

    public function setActive(int $ownerId, int $websiteId, int $couponId, bool $active): bool
    {
        if (!$this->ownsSite($ownerId, $websiteId) || !$this->db->query('SELECT id FROM site_coupons WHERE id = ? AND website_id = ?', [$couponId, $websiteId])) {
            return false;
        }
        $this->db->query('UPDATE site_coupons SET is_active = ? WHERE id = ? AND website_id = ?', [$active ? 1 : 0, $couponId, $websiteId]);
        return true;
    }

    public function delete(int $ownerId, int $websiteId, int $couponId): bool
    {
        if (!$this->ownsSite($ownerId, $websiteId) || !$this->db->query('SELECT id FROM site_coupons WHERE id = ? AND website_id = ?', [$couponId, $websiteId])) {
            return false;
        }
        $this->db->query('DELETE FROM site_coupons WHERE id = ? AND website_id = ?', [$couponId, $websiteId]);
        return true;
    }

    /** @return array{ok:bool,error?:string,discount?:float} the rules of one coupon row against a total */
    public static function check(array $row, float $total, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        if ((int) $row['is_active'] !== 1) {
            return ['ok' => false, 'error' => 'كود الخصم غير مفعّل'];
        }
        if (!empty($row['valid_from']) && $today < (string) $row['valid_from']) {
            return ['ok' => false, 'error' => 'كود الخصم لسه ما بدأش'];
        }
        if (!empty($row['valid_until']) && $today > (string) $row['valid_until']) {
            return ['ok' => false, 'error' => 'كود الخصم انتهى'];
        }
        if ($row['max_uses'] !== null && (int) $row['used_count'] >= (int) $row['max_uses']) {
            return ['ok' => false, 'error' => 'كود الخصم وصل للحد الأقصى من الاستخدام'];
        }
        if ($total < (float) $row['min_total']) {
            return ['ok' => false, 'error' => 'الحجز أقل من الحد الأدنى لكود الخصم (' . rtrim(rtrim(number_format((float) $row['min_total'], 2, '.', ''), '0'), '.') . ')'];
        }
        $raw = $row['type'] === 'percent' ? $total * (float) $row['value'] / 100 : (float) $row['value'];
        $discount = round(min($raw, $total * self::MAX_DISCOUNT_SHARE), 2);
        return $discount > 0 ? ['ok' => true, 'discount' => $discount] : ['ok' => false, 'error' => 'كود الخصم لا ينطبق على هذا الحجز'];
    }

    /** Read-only evaluation for the public quote. @return array{ok:bool,error?:string,discount?:float,code?:string} */
    public function evaluate(int $websiteId, $code, float $total): array
    {
        $c = self::normalizeCode($code);
        if ($c === null) {
            return ['ok' => false, 'error' => 'كود الخصم غير صحيح'];
        }
        $row = $this->db->query('SELECT * FROM site_coupons WHERE website_id = ? AND code = ? LIMIT 1', [$websiteId, $c])[0] ?? null;
        if (!$row) {
            return ['ok' => false, 'error' => 'كود الخصم غير صحيح'];
        }
        $r = self::check($row, $total);
        return $r['ok'] ? $r + ['code' => $c] : $r;
    }

    /**
     * Inside the booking transaction: lock the coupon row, re-check the rules, count the use.
     * @return array{coupon_id:int,code:string,discount:float}
     * @throws InvalidArgumentException with a customer-facing message when the coupon cannot be used
     */
    public static function redeem(Database $db, int $websiteId, $code, float $total): array
    {
        $c = self::normalizeCode($code);
        $row = ($c !== null && $websiteId > 0) ? ($db->query('SELECT * FROM site_coupons WHERE website_id = ? AND code = ? LIMIT 1 FOR UPDATE', [$websiteId, $c])[0] ?? null) : null;
        if (!$row) {
            throw new InvalidArgumentException('كود الخصم غير صحيح');
        }
        $r = self::check($row, $total);
        if (!$r['ok']) {
            throw new InvalidArgumentException($r['error']);
        }
        $db->query('UPDATE site_coupons SET used_count = used_count + 1 WHERE id = ?', [(int) $row['id']]);
        return ['coupon_id' => (int) $row['id'], 'code' => $c, 'discount' => $r['discount']];
    }
}
