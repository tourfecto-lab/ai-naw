<?php

/**
 * Tourfecto - Booking Engine (Phase 2)
 * منطق الحجز الأساسي: فحص التوفر، إنشاء حجز آمن من تعارض التزامن
 * (SELECT ... FOR UPDATE داخل transaction)، تأكيد، إلغاء.
 *
 * ملاحظة دمج: النسخة دي بديل مباشر لملف BookingEngine.php المُرسل في
 * Phase 2 zip - نفس الفكرة والـ API تقريبًا، لكن معاد كتابتها بالكامل
 * لتستخدم كلاسات المشروع الحقيقية (Database::getInstance()، Model بدون
 * namespace) بدل namespaces وكلاسات (App\Core\Cache, App\Core\Encryption)
 * كانت هتفشل فورًا وقت أول تحميل (Class not found) لأن المشروع كله
 * classmap بدون namespaces - راجع رد الشات لتفاصيل الفحص.
 * @version 1.0.0  @date 2026-08-21
 */
class BookingEngine
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * إنشاء حجز جديد - آمن من التعارض (لو حجزين جم في نفس اللحظة على نفس
     * اليوم، واحد بس ينجح لو السعة خلصت).
     *
     * @throws Exception لو الخدمة مش موجودة/مش مملوكة للحساب، أو مفيش سعة
     */
    public function createBooking(int $userId, array $data): array
    {
        $productId = (int) ($data['product_id'] ?? 0);
        $startDate = (string) ($data['start_date'] ?? '');
        $endDate=isset($data['end_date'])?(string)$data['end_date']:null;
        if($endDate!==null){HotelStayService::dates($startDate,$endDate);if(filter_var($data['rooms_count']??1,FILTER_VALIDATE_INT)===false)throw new InvalidArgumentException('عدد الغرف غير صالح');}

        if ($productId <= 0 || $startDate === '') {
            throw new Exception('product_id و start_date مطلوبين');
        }

        if ($userId <= 0 || !InventoryService::isValidDate($startDate)) {
            throw new InvalidArgumentException('تاريخ الحجز يجب أن يكون صالحًا بالصيغة YYYY-MM-DD');
        }
        foreach (['adults_count' => 1, 'children_count' => 0] as $field => $minimum) {
            $value = $data[$field] ?? $minimum;
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum || (int) $value > 200) {
                throw new InvalidArgumentException('عدد المسافرين غير صالح');
            }
        }
        if (isset($data['total_amount']) && (!is_numeric($data['total_amount']) || !is_finite((float) $data['total_amount']) || (float) $data['total_amount'] < 0)) {
            throw new InvalidArgumentException('إجمالي الحجز غير صالح');
        }
        if (!empty($data['start_time']) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', (string) $data['start_time'])) {
            throw new InvalidArgumentException('وقت الحجز غير صالح');
        }

        $requestToken=$data['request_token']??null;
        if($requestToken!==null && (!is_string($requestToken)||!preg_match('/^[a-f0-9]{32}$/D',$requestToken)))throw new InvalidArgumentException('رمز الطلب غير صالح');
        $extraIds=(array)($data['extra_ids']??[]);
        foreach($extraIds as $id)if(filter_var($id,FILTER_VALIDATE_INT)===false||(int)$id<1)throw new InvalidArgumentException('إضافة غير صالحة');
        $extraIds=array_values(array_unique(array_map('intval',$extraIds)));sort($extraIds);
        $fingerprint=$requestToken ? hash('sha256',json_encode([$productId,$startDate,$endDate,(int)($data['rooms_count']??1),(int)($data['adults_count']??1),(int)($data['children_count']??0),$extraIds,trim((string)($data['customer_name']??'')),trim((string)($data['customer_email']??'')),trim((string)($data['customer_phone']??''))],JSON_THROW_ON_ERROR)) : null;
        $result = $this->db->transaction(function (Database $db) use ($userId, $productId, $startDate, $data, $requestToken, $fingerprint,$endDate) {
            // 1) تأكد إن الخدمة مملوكة للحساب فعلاً
            $product = $db->query(
                'SELECT id, name, price, currency, website_id FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$productId, $userId]
            );
            if (empty($product)) {
                throw new Exception('الخدمة غير موجودة أو غير مملوكة لهذا الحساب');
            }
            $product = $product[0];

            if($requestToken){
                $existing=$db->query('SELECT id,booking_reference,status,total_amount,public_request_fingerprint FROM bookings WHERE user_id=? AND public_request_token=? LIMIT 1 FOR UPDATE',[$userId,$requestToken])[0]??null;
                if($existing){
                    if(!hash_equals((string)$existing['public_request_fingerprint'],$fingerprint))throw new InvalidArgumentException('رمز الطلب مرتبط ببيانات حجز مختلفة');
                    if($existing['status']==='cancelled')throw new RuntimeException('الطلب السابق أُلغي؛ ابدأ طلبًا جديدًا بعد مراجعة الحجز');
                    return ['id'=>(int)$existing['id'],'booking_reference'=>$existing['booking_reference'],'status'=>$existing['status'],'total_amount'=>(float)$existing['total_amount'],'reused'=>true];
                }
            }

            // 2) قفل صف التوفر لليوم ده (FOR UPDATE) - أي محاولة حجز تانية
            // لنفس اليوم/المنتج هتستنى لحد ما الـ transaction دي تخلص.
            $inventory = $db->query(
                'SELECT id, capacity, booked_count, price_override, is_blocked
                 FROM inventory WHERE product_id = ? AND date = ? LIMIT 1 FOR UPDATE',
                [$productId, $startDate]
            );

            if (empty($inventory)) {
                // مفيش صف توفر متسجل لليوم ده = يُعتبر غير متاح صراحةً
                // (سياسة آمنة: عدم وجود بيانات لا يعني سعة غير محدودة).
                throw new Exception('لا يوجد توفر مسجّل لهذا التاريخ');
            }
            $inv = $inventory[0];

            if ((int) $inv['is_blocked'] === 1) {
                throw new Exception('هذا التاريخ مُغلق للحجز');
            }
            $operations = new BookingOperationsService($db);
            $settings = $operations->productSettings($userId, $productId);
            $unitPrice = $inv['price_override'] !== null ? (float) $inv['price_override'] : (float) $product['price'];
            $adults = (int) ($data['adults_count'] ?? 1);
            $children = (int) ($data['children_count'] ?? 0);
            $units = $endDate!==null?(int)($data['rooms_count']??1):BookingOperationsService::units($settings, $adults, $children);
            $stay=$endDate!==null?HotelStayService::quote($productId,(float)$product['price'],$startDate,$endDate,$units,true):null;
            if ((int) $inv['booked_count'] + $units > (int) $inv['capacity']) { throw new Exception('الأماكن المتاحة أقل من العدد المطلوب'); }
            $extras = $operations->extraTotal($userId, $productId, (array) ($data['extra_ids'] ?? []), $adults + $children);
            $calculated = ($stay!==null?$stay['total']:BookingOperationsService::baseTotal($unitPrice, $settings, $adults, $children)) + $extras['total'];
            if(isset($data['expected_total']) && (!is_numeric($data['expected_total']) || !is_finite((float)$data['expected_total']) || abs(round((float)$data['expected_total'],2)-$calculated)>0.001))throw new InvalidArgumentException('السعر اتغير؛ راجع السعر والتوفر ثم أكد الحجز مرة أخرى');
            $totalAmount = round((float) ($data['total_amount'] ?? $calculated), 2);
            $pricing = json_encode(['end_date'=>$endDate,'rooms_count'=>$endDate!==null?$units:null,'nights'=>$stay['nights']??1,'nightly_breakdown'=>$stay['nightly_breakdown']??null,'adult_price'=>$unitPrice,'child_price'=>(float)$settings['child_price'],'extras'=>$extras['items'],'capacity_mode'=>$settings['capacity_mode'],'inventory_units'=>$units,'cancellation_hours'=>(int)$settings['cancellation_hours'],'refund_percent'=>(float)$settings['refund_percent'],'calculated_total'=>$calculated,'manual_override'=>isset($data['total_amount'])], JSON_THROW_ON_ERROR);
            if (!is_finite($totalAmount) || $totalAmount < 0) {
                throw new InvalidArgumentException('سعر الخدمة غير صالح');
            }
            if (!empty($data['customer_id'])) {
                $contact = $db->query('SELECT id FROM crm_contacts WHERE id = ? AND user_id = ? LIMIT 1', [(int) $data['customer_id'], $userId]);
                if (!$contact) {
                    throw new InvalidArgumentException('العميل غير موجود في هذا الحساب');
                }
            }

            // 4) إسناد إعلاني اختياري (من كوكي /r/{code} لمدة 30 يوم). لازم يكون
            //    رابط UTM لحملة مملوكة للحساب نفسه - أي إسناد خارجي يُتجاهل
            //    بصمت (منع تلاعب الإسناد عبر طلب حجز معدّل).
            $attributedUtmLinkId = (isset($data['attributed_utm_link_id']) && (int) $data['attributed_utm_link_id'] > 0)
                ? (int) $data['attributed_utm_link_id']
                : null;
            if ($attributedUtmLinkId !== null) {
                $link = $db->query(
                    'SELECT u.id FROM ad_utm_links u
                     JOIN ad_campaigns c ON c.id = u.campaign_id
                     WHERE u.id = ? AND c.user_id = ? LIMIT 1',
                    [$attributedUtmLinkId, $userId]
                );
                if (empty($link)) {
                    $attributedUtmLinkId = null;
                }
            }

            $reference = $this->generateReference();

            $bookingId = $db->query(
                'INSERT INTO bookings
                    (booking_reference, user_id, product_id, customer_id, customer_name,
                     customer_phone, customer_email, start_date, start_time,
                     adults_count, children_count, total_amount, currency, status, source,
                     attributed_utm_link_id, notes, inventory_units, pricing_json, public_request_token, public_request_fingerprint,end_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $reference, $userId, $productId, $data['customer_id'] ?? null,
                    $data['customer_name'] ?? '', $data['customer_phone'] ?? null,
                    $data['customer_email'] ?? null, $startDate, $data['start_time'] ?? null,
                    $adults, $children, $totalAmount, $data['currency'] ?? $product['currency'],
                    'pending', $data['source'] ?? 'direct',
                    $attributedUtmLinkId, $data['notes'] ?? null, $units, $pricing, $requestToken, $fingerprint,$endDate,
                ]
            );

            WhatsAppStudioService::attribute($db->getConnection(),$userId,(int)($product['website_id']??0),(int)$bookingId,(is_string($data['wa_token']??null)?$data['wa_token']:''));

            // 5) حدّث عداد الحجوزات في نفس الـ transaction (الصف لسه مقفول لينا)
            foreach($stay['inventory']??[$inv] as $night){$q=$db->getConnection()->prepare('UPDATE inventory SET booked_count=booked_count+? WHERE id=?');$q->execute([$units,$night['id']]);}

            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, NULL, ?, ?, ?)',
                [$bookingId, 'pending', $userId, 'إنشاء حجز جديد']
            );

            // 6) اربط الحجز بأول صفقة open لنفس الحساب لنفس العميل (إن وجدت).
            //    لا ينشئ صفقة جديدة - الربط بيخلّي تأكيد الحجز يرفع الصفقة لـ won.
            $this->linkOpenDealToBooking($db, $userId, $bookingId, $data);

            return [
                'id' => $bookingId,
                'booking_reference' => $reference,
                'status' => 'pending',
                'total_amount' => $totalAmount,
            ];
        });
        // التسويق بالبريد: إسناد الحجز لكليك إيميل (tf_em) + مشغّل "حجز جديد".
        // بعد الـ commit عشان أي إرسال SMTP ما يطوّلش قفل الحجز.
        if (empty($result['reused'])) {
            if (class_exists('EmailPlatformBridge') && is_string($data['em_token'] ?? null) && $data['em_token'] !== '') {
                (new EmailPlatformBridge($this->db))->attributeBooking($userId, (int) $result['id'], $data['em_token']);
            }
            $this->notifyEmailMarketing((int) $result['id'], 'booking_created');
        }
        return $result;
    }

    public function confirmBooking(int $userId, int $bookingId): bool
    {
        $confirmed = $this->db->transaction(function (Database $db) use ($userId, $bookingId): bool {
            $rows = $db->query('SELECT id, status FROM bookings WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE', [$bookingId, $userId]);
            if (!$rows) {
                throw new Exception('الحجز غير موجود');
            }
            if (in_array($rows[0]['status'], ['confirmed', 'completed'], true)) {
                return true;
            }
            if ($rows[0]['status'] !== 'pending') {
                throw new Exception('لا يمكن تأكيد حجز ملغي');
            }
            $this->changeStatus($userId, $bookingId, 'confirmed', 'تأكيد الحجز');
            $this->markLinkedDealWon($db, $bookingId);
            $this->recordAgencyCommission($db, $bookingId);
            $this->recordBookingRevenue($db, $bookingId);
            $this->dispatchConversionEventIfAttributed($db, $bookingId);
            $this->dispatchBookingConfirmationEmail($db, $bookingId);
            return true;
        });
        $this->notifyEmailMarketing($bookingId, 'booking_confirmed');
        return $confirmed;
    }

    /**
     * تأكيد الحجز بعد اكتمال الدفع - بيتنادى من Webhook الدفع (مفيش
     * جلسة مستخدم/صلاحيات مالك، فبنستخدمها داخليًا بس بعد التحقق من
     * توقيع البوابة في StripeCheckoutService). Idempotent: لو الحجز
     * confirmed فعلًا بيرجع true من غير ما يضيف تاريخ مكرر.
     */
    public function confirmBookingFromPayment(int $bookingId): bool
    {
        $confirmed = $this->db->transaction(function (Database $db) use ($bookingId) {
            $rows = $db->query(
                'SELECT id, status FROM bookings WHERE id = ? LIMIT 1 FOR UPDATE',
                [$bookingId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $fromStatus = $rows[0]['status'];
            if ($fromStatus === 'confirmed' || $fromStatus === 'completed') {
                return true;
            }
            if ($fromStatus === 'cancelled') {
                throw new Exception('لا يمكن تأكيد حجز ملغي بعد الدفع');
            }

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', ['confirmed', $bookingId]);
            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $fromStatus, 'confirmed', null, 'تأكيد تلقائي بعد نجاح الدفع']
            );

            // ارفع الصفقة المربوطة بالحجز لـ won (إن كانت open)
            $this->markLinkedDealWon($db, $bookingId);
            // سجّل عمولة الوكالة للحجز لو صاحبه عميل وكالة (نفس الـ transaction)
            $this->recordAgencyCommission($db, $bookingId);
            // سجّل إيراد الحجز في rev_revenue_records (idempotent)
            $this->recordBookingRevenue($db, $bookingId);
            // CAPI: حدث تحويل غير متزامن لو الحجز اتعمل عليه إسناد إعلاني
            // (INSERT جوه نفس الـ transaction - لو التأكيد اتراجع، الحدث مش بيتبعت)
            $this->dispatchConversionEventIfAttributed($db, $bookingId);

            // إيميل تأكيد الحجز: Job غير متزامن جوه نفس الـ transaction —
            // لو التأكيد اتراجع (rollback) مفيش إيميل يتجدول.
            $this->dispatchBookingConfirmationEmail($db, $bookingId);

            return true;
        });
        $this->notifyEmailMarketing($bookingId, 'booking_confirmed');
        return $confirmed;
    }

    /** إلغاء حجز - بيفك حجز مكان التوفر تلقائيًا لو الحجز كان pending/confirmed */
    public function cancelBooking(int $userId, int $bookingId, string $reason = ''): bool
    {
        $cancelled = $this->db->transaction(function (Database $db) use ($userId, $bookingId, $reason) {
            $rows = $db->query(
                'SELECT id, product_id, start_date,end_date, status, booking_reference, total_amount, currency, inventory_units
                 FROM bookings WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$bookingId, $userId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $booking = $rows[0];

            if (in_array($booking['status'], ['cancelled', 'completed'], true)) {
                throw new Exception('لا يمكن إلغاء حجز ملغي بالفعل أو مكتمل');
            }

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', ['cancelled', $bookingId]);

            // فكّ مكان التوفر لو كان محجوز فعليًا
            $db->query(
                'UPDATE inventory SET booked_count = GREATEST(0, booked_count - ?)
                 WHERE product_id = ? AND date >= ? AND date < ?',
                [max(1, (int) ($booking['inventory_units'] ?? 1)), $booking['product_id'], $booking['start_date'],$booking['end_date']?:date('Y-m-d',strtotime($booking['start_date'].' +1 day'))]
            );

            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $booking['status'], 'cancelled', $userId, $reason ?: 'إلغاء بواسطة الحساب']
            );

            // عمولة الوكالة المرتبطة بالحجز (لو موجودة): pending تُلغى
            // تلقائيًا (voided)، وpaid تُترك كما هي مع تنبيه الأدمن.
            $this->handleCommissionOnCancel($db, $bookingId);

            // تصحيح إيراد ذكاء الإيرادات: لو الحجز ده كان مؤكَّد فعلًا
            // (يعني سجّلناله إيراد booking في rev_revenue_records)، نسجّل
            // سجل تصحيحي بمبلغ سالب (booking_refund) عشان صافي الإيراد
            // يعكس الواقع — حجز ملغي ما يفضلش محسوب كإيراد.
            $this->recordBookingRefund($db, $bookingId, (string) $booking['status']);

            return true;
        });
        $this->notifyEmailMarketing($bookingId, 'booking_cancelled');
        return $cancelled;
    }

    /**
     * ربط التسويق بالبريد (EmailPlatformBridge): مشغّلات الأتمتة + إسناد
     * الإيراد. ثانوي تمامًا — أي فشل بيتسجّل جوه الـ bridge وما يأثرش على الحجز.
     * المشغّل بيتسجّل مرة واحدة لكل حجز حتى لو التأكيد اتنادى أكتر من مرة.
     */
    private function notifyEmailMarketing(int $bookingId, string $trigger): void
    {
        if ($bookingId <= 0 || !class_exists('EmailPlatformBridge')) {
            return;
        }
        try {
            (new EmailPlatformBridge($this->db))->onBookingEvent($bookingId, $trigger);
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Email marketing booking hook failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * معالجة عمولة الوكالة عند إلغاء حجز مؤكد (داخل نفس الـ transaction):
     *   - pending → voided: الحجز اتلغى قبل دفع العمولة، فالمستحقات تُسقَط.
     *   - paid    → تبقى كما هي + تنبيه (Notification + Logger) للأدمن
     *               (صاحب الوكالة): العمولة المدفوعة لا تُعكس تلقائيًا أبدًا —
     *               أي استرداد قرار بشري/يدوي.
     *   - مفيش عمولة → بلا أثر جانبي.
     * crm_deals لا تُلمس هنا عمدًا: قرار بشري موثق في PROGRESS.md.
     */
    private function handleCommissionOnCancel(Database $db, int $bookingId): void
    {
        $rows = $db->query(
            "SELECT c.id, c.status, a.owner_user_id AS agency_owner_user_id
             FROM agency_commissions c
             LEFT JOIN agencies a ON a.id = c.agency_id
             WHERE c.booking_id = ? LIMIT 1 FOR UPDATE",
            [$bookingId]
        );
        if (empty($rows)) {
            return;
        }
        $commission = $rows[0];

        if ($commission['status'] === 'pending') {
            $db->query(
                "UPDATE agency_commissions SET status = 'voided', updated_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                [(int) $commission['id']]
            );
            return;
        }

        if ($commission['status'] === 'paid') {
            $ownerUserId = (int) ($commission['agency_owner_user_id'] ?? 0);
            $message = 'حجز ملغي وعمولته مدفوعة بالفعل — يلزم استرداد يدوي إن لزم.';
            if ($ownerUserId > 0 && class_exists('Notification')) {
                Notification::notify(
                    $ownerUserId,
                    'commission_paid_on_cancelled_booking',
                    'عمولة مدفوعة على حجز ملغي',
                    $message,
                    ''
                );
            }
            if (class_exists('Logger')) {
                Logger::warning(
                    'Booking cancelled but agency commission already paid',
                    ['booking_id' => $bookingId, 'commission_id' => (int) $commission['id']]
                );
            }
        }
    }

    private function changeStatus(int $userId, int $bookingId, string $toStatus, string $reason): bool
    {
        return $this->db->transaction(function (Database $db) use ($userId, $bookingId, $toStatus, $reason) {
            $rows = $db->query(
                'SELECT id, status FROM bookings WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$bookingId, $userId]
            );
            if (empty($rows)) {
                throw new Exception('الحجز غير موجود');
            }
            $fromStatus = $rows[0]['status'];

            $db->query('UPDATE bookings SET status = ? WHERE id = ?', [$toStatus, $bookingId]);
            $db->query(
                'INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)',
                [$bookingId, $fromStatus, $toStatus, $userId, $reason]
            );

            return true;
        });
    }

    /** مرجع حجز فريد يظهر للعميل - مش تسلسلي عشان محدش يخمن عدد الحجوزات */
    private function generateReference(): string
    {
        return 'BK-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * ربط الحجز بأول صفقة open لنفس الحساب ينتمي لها نفس العميل
     * (عبر customer_id / الإيميل / الهاتف). الربط بيمكّن تأكيد الحجز
     * من ترقية الصفقة لـ won. لا ينشئ صفقات جديدة ولا يعدّل غير
     * الصفقات اللي لسه booking_id بتاعها NULL.
     */
    private function linkOpenDealToBooking(Database $db, int $userId, int $bookingId, array $data): void
    {
        $contactId = $data['customer_id'] ?? null;
        $email = (string) ($data['customer_email'] ?? '');
        $phone = (string) ($data['customer_phone'] ?? '');

        if ($contactId === null && $email === '' && $phone === '') {
            return;
        }

        $sql = "SELECT d.id
                FROM crm_deals d
                LEFT JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ? AND d.status = 'open' AND d.booking_id IS NULL";
        $params = [$userId];

        $conds = [];
        if ($contactId !== null) {
            $conds[] = 'd.contact_id = ?';
            $params[] = (int) $contactId;
        }
        if ($email !== '') {
            $conds[] = 'c.email = ?';
            $params[] = $email;
        }
        if ($phone !== '') {
            $conds[] = 'c.phone = ?';
            $params[] = $phone;
        }
        if (empty($conds)) {
            return;
        }

        $sql .= ' AND (' . implode(' OR ', $conds) . ') ORDER BY d.created_at ASC LIMIT 1';
        $rows = $db->query($sql, $params);
        if (empty($rows)) {
            return;
        }

        $db->query('UPDATE crm_deals SET booking_id = ? WHERE id = ? AND status = ?', [
            $bookingId, $rows[0]['id'], 'open',
        ]);
    }

    /**
     * ترقية الصفقة المربوطة بالحجز لـ won عند تأكيد الحجز (يدوي أو
     * بعد الدفع). Idempotent: صفقات won/lost والإلغاءات مش بتتأثر.
     */
    private function markLinkedDealWon(Database $db, int $bookingId): void
    {
        $rows = $db->query(
            "SELECT id FROM crm_deals WHERE booking_id = ? AND status = 'open' LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            return;
        }

        $db->query(
            "UPDATE crm_deals SET status = 'won', closed_at = COALESCE(closed_at, NOW()) WHERE id = ? AND status = 'open'",
            [$rows[0]['id']]
        );
    }

    /**
     * حساب وتسجيل عمولة الوكالة تلقائيًا عند تأكيد حجز لعميل يتبع
     * وكالة (عبر agency_clients). العمولة = total_amount × commission_rate
     * (نسبة العميل في agency_clients) / 100، بحالة pending.
     *
     * القاعدة المستخدمة هي total_amount لأنها نفس القيمة اللي بتتسجّل
     * كـ amount في payment_transactions عند الدفع (Stripe/Paymob) - أي
     * رسوم بوابة بقت خارج المعاملة أصلاً، فمفيش مبلغ مُحصَّل مختلف.
     *
     * Idempotent: booking_id فريد في agency_commissions، والتكرار
     * بيعمل update للمبلغ مش إدراج مكرر. العملاء غير التابعين لوكالة
     * (أو وكالة علاقتها مش active) بيتجاهلوا بصمت.
     */
    private function recordAgencyCommission(Database $db, int $bookingId): void
    {
        $rows = $db->query(
            "SELECT b.user_id, b.total_amount,
                    ac.id AS agency_client_id, ac.agency_id, ac.commission_rate
             FROM bookings b
             JOIN agency_clients ac ON ac.client_user_id = b.user_id AND ac.status = 'active'
             WHERE b.id = ? AND b.status = 'confirmed'
             LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            return;
        }
        $row = $rows[0];

        $rate = (float) ($row['commission_rate'] ?? 10.00);
        $amount = round(((float) $row['total_amount']) * $rate / 100, 2);
        if ($amount <= 0) {
            return;
        }

        $db->query(
            "INSERT INTO agency_commissions (agency_id, agency_client_id, booking_id, commission_amount, status)
             VALUES (?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE commission_amount = VALUES(commission_amount)",
            [(int) $row['agency_id'], (int) $row['agency_client_id'], $bookingId, $amount]
        );
    }

    /**
     * تسجيل إيراد الحجز المؤكَّد في rev_revenue_records (ذكاء الإيرادات).
     * يُستدعى من نقطتي التأكيد (يدوي + بعد الدفع) داخل نفس الـ transaction.
     *
     * Idempotent إلزامي: لو نفس الحجز اتأكد أكتر من مرة (الكود الحالي بيسمح
     * بذلك نادرًا)، ما نضيفش سجل مكرر — بنفحص وجود reference_id لنفس
     * user_id + source='booking' قبل الإدخال. بيستخدم نفس جدول
     * rev_revenue_records الموجود (المصمم من البداية لاستقبال
     * booking/order/subscription/manual) — لا ننشئ جدولًا جديدًا ولا
     * نلمس منطق Stripe/CRM/العمولة القائم.
     */
    private function recordBookingRevenue(Database $db, int $bookingId): void
    {
        try {
            $rows = $db->query(
                "SELECT b.user_id,b.booking_reference,b.total_amount,b.currency,b.customer_id,b.id booking_id,b.product_id,p.name product_name,CASE WHEN b.end_date IS NOT NULL THEN 'rooms' WHEN p.tour_slug IS NOT NULL THEN 'tours' ELSE 'other' END category
                 FROM bookings b LEFT JOIN crm_products p ON p.id=b.product_id AND p.user_id=b.user_id WHERE b.id = ? AND b.status = 'confirmed' LIMIT 1",
                [$bookingId]
            );
            if (empty($rows)) {
                return;
            }
            $booking = $rows[0];
            $userId = (int) $booking['user_id'];
            $reference = (string) $booking['booking_reference'];
            $amount = round((float) $booking['total_amount'], 2);
            if ($reference === '' || $amount <= 0) {
                return;
            }

            // Idempotency: سجل إيراد booking لنفس الحجز موجود أصلًا؟ → لا نضيف مكرر
            $existing = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'booking' AND reference_id = ? LIMIT 1",
                [$userId, $reference]
            );
            if (!empty($existing)) {
                return;
            }

            $db->query(
                "INSERT INTO rev_revenue_records
                    (user_id, source, reference_id, amount, currency, recorded_at, notes, product_name,category,product_id,customer_id,booking_id)
                 VALUES (?, 'booking', ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $reference,
                    $amount,
                    (string) ($booking['currency'] ?: 'USD'),
                    'حجز مؤكد - ' . $reference, $booking['product_name']??null,$booking['category']??null,$booking['product_id']??null,$booking['customer_id']??null,$booking['booking_id']??null,
                ]
            );

            // مزامنة كاش/إعادة الحساب المرتبطة بالموديول (نفس نمط RevenueController)
            if (function_exists('event')) {
                event('revenue.updated', ['user_id' => $userId, 'amount' => $amount, 'source' => 'booking']);
            }
        } catch (Exception $e) {
            // الإيراد ثانوي ولا يجب أن يكسر تدفق التأكيد - نسجّل ونتجاهل
            if (class_exists('Logger')) {
                Logger::warning('Booking revenue record failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * تسجيل تصحيح الإيراد عند إلغاء حجز كان مؤكَّدًا بالفعل.
     * يُستدعى من cancelBooking داخل نفس الـ transaction.
     *
     * القاعدة (نفس أسلوب إصلاح العمولة "لو كان موجود، وثّق الإلغاء"):
     *   - لو الحجز المسجل بالـ fromStatus لم يكن confirmed → مفيش إيراد
     *     booking اتسجّل أصلًا (الحجز اللي اتحول cancelled من pending) فلا
     *     نضيف سجل تصحيحي.
     *   - لو من confirmed → نضيف سجل booking_refund بمبلغ سالب
     *     (-total_amount) للـ reference_id نفسه، بشرط وجود السجل الإيجابي
     *     الأصلي، وبشرط عدم وجود سجل تصحيحي مسبق (idempotent).
     * amount من نوع DECIMAL(12,2) موقّع (ليس UNSIGNED) → المبلغ السالب مقبول.
     */
    private function recordBookingRefund(Database $db, int $bookingId, string $fromStatus): void
    {
        if ($fromStatus !== 'confirmed') {
            return;
        }
        try {
            $rows = $db->query(
                "SELECT b.user_id,b.booking_reference,b.total_amount,b.currency,b.customer_id,b.id booking_id,b.product_id,p.name product_name,CASE WHEN b.end_date IS NOT NULL THEN 'rooms' WHEN p.tour_slug IS NOT NULL THEN 'tours' ELSE 'other' END category
                 FROM bookings b LEFT JOIN crm_products p ON p.id=b.product_id AND p.user_id=b.user_id WHERE b.id = ? LIMIT 1",
                [$bookingId]
            );
            if (empty($rows)) {
                return;
            }
            $booking = $rows[0];
            $userId = (int) $booking['user_id'];
            $reference = (string) $booking['booking_reference'];
            $amount = round((float) $booking['total_amount'], 2);
            if ($reference === '' || $amount <= 0) {
                return;
            }

            // لا نضيف تصحيح إلا لو كان فيه سجل إيراد booking أصلي فعليًا
            $positive = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'booking' AND reference_id = ? LIMIT 1",
                [$userId, $reference]
            );
            if (empty($positive)) {
                return;
            }

            // Idempotency: سجل تصحيحي لنفس الحجز موجود أصلًا؟ → لا نضيف مكرر
            $existing = $db->query(
                "SELECT id FROM rev_revenue_records
                 WHERE user_id = ? AND source = 'booking_refund' AND reference_id = ? LIMIT 1",
                [$userId, $reference]
            );
            if (!empty($existing)) {
                return;
            }

            $db->query(
                "INSERT INTO rev_revenue_records
                    (user_id, source, reference_id, amount, currency, recorded_at, notes, product_name,category,product_id,customer_id,booking_id)
                 VALUES (?, 'booking_refund', ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $reference,
                    -$amount,
                    (string) ($booking['currency'] ?: 'USD'),
                    'تصحيح قيمة حجز ملغي؛ ليس استردادًا نقديًا - ' . $reference,$booking['product_name']??null,$booking['category']??null,$booking['product_id']??null,$booking['customer_id']??null,$booking['booking_id']??null,
                ]
            );

            if (function_exists('event')) {
                event('revenue.updated', ['user_id' => $userId, 'amount' => -$amount, 'source' => 'booking_refund']);
            }
        } catch (Exception $e) {
            // التصحيح ثانوي ولا يكسر تدفق الإلغاء - نسجّل ونتجاهل
            if (class_exists('Logger')) {
                Logger::warning('Booking refund record failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * إرسال حدث تحويل CAPI غير متزامن (عبر طابور DB) لما حجز تم إسناده
     * لرابط UTM إعلاني (attributed_utm_link_id). لا يوقف تدفق التأكيد أبدًا:
     * أي فشل (طابور غير متاح/خطأ) بيتسجّل ويتجاهل بصمت. الحجوزات من غير
     * إسناد مابتدخلش هنا أصلًا.
     */
    private function dispatchConversionEventIfAttributed(Database $db, int $bookingId): void
    {
        try {
            $rows = $db->query(
                'SELECT id FROM bookings WHERE id = ? AND attributed_utm_link_id IS NOT NULL LIMIT 1',
                [$bookingId]
            );
            if (empty($rows)) {
                return;
            }
            if (!class_exists('QueueManager') || !class_exists('Container')) {
                return;
            }

            $queue = Container::getInstance()->make(QueueManager::class);
            if ($queue->isReady()) {
                $queue->push('SendAdConversionJob', ['booking_id' => $bookingId], 'ads', 0);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('CAPI conversion dispatch failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * جدولة إيميل تأكيد الحجز كـ Job غير متزامن (SendBookingConfirmationJob)
     * على طابور 'email'. الإيميل ثانوي والتأكيد أساسي: بيجدول فقط للحجز
     * confirmed وله customer_email صالح، وأي فشل (طابور غير متاح/خطأ)
     * بيتسجّل ويتجاهل بصمت — لا يوقف تدفق التأكيد أبدًا.
     */
    private function dispatchBookingConfirmationEmail(Database $db, int $bookingId): void
    {
        try {
            $rows = $db->query(
                "SELECT id FROM bookings
                 WHERE id = ? AND status = 'confirmed'
                   AND customer_email IS NOT NULL AND customer_email != ''
                 LIMIT 1",
                [$bookingId]
            );
            if (empty($rows)) {
                return;
            }
            if (!class_exists('QueueManager') || !class_exists('Container')) {
                return;
            }

            $queue = Container::getInstance()->make(QueueManager::class);
            if ($queue->isReady()) {
                $queue->push('SendBookingConfirmationJob', ['booking_id' => $bookingId], 'email', 0);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Booking confirmation email dispatch failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
            }
        }
    }
}
