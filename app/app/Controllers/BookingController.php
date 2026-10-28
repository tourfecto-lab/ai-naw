<?php

/**
 * Tourfecto - Booking Controller (Booking Engine - Phase 2)
 * @version 1.0.0  @date 2026-08-21
 */
class BookingController extends Controller
{
    private BookingEngine $engine;
    private InventoryService $inventory;

    public function __construct()
    {
        parent::__construct();
        $this->engine = new BookingEngine();
        $this->inventory = new InventoryService();
    }

    /**
     * الحجوزات بتاعة صاحب الحساب - عضو الفريق بيشوف حجوزات صاحب الشغل مش حساب فاضي.
     * (قبل كده كل حاجة كانت على id العضو نفسه فالصفحة بتطلع فاضية لأي حد في الفريق)
     */
    private function scopeId(): int
    {
        $id = (int) ($this->getUser()['id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        try {
            $r = Database::getInstance()->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$id]);
            $owner = (int) ($r[0]['owner_user_id'] ?? 0);
            return $owner > 0 ? $owner : $id;
        } catch (Throwable $e) {
            return $id;
        }
    }

    /** عضو الفريق بدور "مشاهد" يشوف بس ومايعدّلش */
    private function readOnlyError(): ?array
    {
        $id = (int) ($this->getUser()['id'] ?? 0);
        try {
            $r = Database::getInstance()->query('SELECT owner_user_id, workspace_role FROM users WHERE id = ? LIMIT 1', [$id]);
            if (!$r || ((int) $r[0]['owner_user_id'] > 0 && !in_array(($r[0]['workspace_role'] ?? ''), ['admin', 'manager', 'sales', 'support'], true))) {
                return $this->error('صلاحيتك مشاهدة بس - اطلب من صاحب الحساب يغيّر دورك', 403);
            }
        } catch (Throwable $e) {
            return $this->error('تعذر التحقق من صلاحية التعديل؛ حاول لاحقًا', 503);
        }
        return null;
    }

    /** GET /api/bookings */
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = $this->scopeId();
        $page = max(1, (int) $this->get('page', 1));
        $perPage = min(100, max(1, (int) $this->get('per_page', 20)));

        $filters = array_filter([
            'status' => $this->get('status'),
            'product_id' => $this->get('product_id'),
            'from_date' => $this->get('from_date'),
            'to_date' => $this->get('to_date'),
            'search' => mb_substr(trim((string) $this->get('search', '')), 0, 80),
        ]);

        $bookings = (new Booking())->forUser($userId, $filters, $page, $perPage);

        return $this->success(['bookings' => $bookings, 'page' => $page, 'per_page' => $perPage]);
    }

    /** GET /api/bookings/{id} */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = $this->scopeId();
        $booking = (new Booking())->findOwned($userId, (int) ($params['id'] ?? 0));

        if (!$booking) {
            return $this->error('الحجز غير موجود', 404);
        }

        return $this->success(['booking' => $booking->toArray()]);
    }

    /** POST /api/bookings */
    public function store(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }

        if (!$this->validate([
            'product_id' => 'required|numeric',
            'start_date' => 'required',
            'customer_name' => 'required',
        ])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $userId = $this->scopeId();

        // حجز بيعمله صاحب الشركة بنفسه (من الشات/التليفون): الحقول المسموحة بس
        $in = $this->all();
        $data = [
            'product_id' => (int) ($in['product_id'] ?? 0),
            'start_date' => (string) ($in['start_date'] ?? ''),
            'start_time' => !empty($in['start_time']) && preg_match('/^\d{2}:\d{2}/', (string) $in['start_time']) ? substr((string) $in['start_time'], 0, 5) . ':00' : null,
            'customer_name' => mb_substr(trim((string) ($in['customer_name'] ?? '')), 0, 190),
            'customer_phone' => mb_substr(preg_replace('/[^0-9+]/', '', (string) ($in['customer_phone'] ?? '')), 0, 30) ?: null,
            'customer_email' => filter_var((string) ($in['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
            'adults_count' => max(1, min(200, (int) ($in['adults_count'] ?? 1))),
            'children_count' => max(0, min(200, (int) ($in['children_count'] ?? 0))),
            'source' => in_array($in['source'] ?? 'direct', ['direct', 'chat', 'phone', 'walk_in'], true) ? (string) ($in['source'] ?? 'direct') : 'direct',
            'notes' => mb_substr(trim(strip_tags((string) ($in['notes'] ?? ''))), 0, 1000) ?: null,
        ];
        if (!empty($in['start_time']) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', (string) $in['start_time'])) {
            return $this->error('حدد وقتًا صحيحًا', 422);
        }
        if (isset($in['total_amount']) && $in['total_amount'] !== '' && $in['total_amount'] !== null) {
            $t = round((float) $in['total_amount'], 2);
            if (!is_numeric($in['total_amount']) || !is_finite($t) || $t <= 0) {
                return $this->error('الإجمالي لازم يكون أكبر من صفر', 422);
            }
            $data['total_amount'] = $t;
        }
        if (!InventoryService::isValidDate($data['start_date']) || $data['start_date'] < date('Y-m-d')) {
            return $this->error('التاريخ لازم يكون النهارده أو بعد كده', 422);
        }
        // اليوم ده مالوش توفر متحدد؟ صاحب الشركة هو اللي بيحجز فنفتحه بالسعة الافتراضية
        try {
            $avail = $this->inventory->checkAvailability($data['product_id'], $data['start_date']);
            if (!$avail['available'] && ($avail['reason'] ?? '') === 'no_inventory_set') {
                $cap = function_exists('env') && is_numeric(env('WEBSITE_BOOKING_DEFAULT_CAPACITY')) ? max(1, (int) env('WEBSITE_BOOKING_DEFAULT_CAPACITY')) : 15;
                $this->inventory->setDay($userId, $data['product_id'], $data['start_date'], $cap);
            }
        } catch (Throwable $e) {
        }

        try {
            $data['wa_token'] = is_string($in['wa_token']??null)?$in['wa_token']:'';
            $data['em_token'] = is_string($in['em_token'] ?? null) && preg_match('/^[a-f0-9]{32,64}$/D', $in['em_token']) ? $in['em_token'] : '';
            $result = $this->engine->createBooking($userId, array_filter($data, fn ($v) => $v !== null));

            return $this->success($result, 'تم إنشاء الحجز بنجاح', 201);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/bookings/{id}/confirm */
    public function confirm(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }

        $userId = $this->scopeId();

        try {
            $this->engine->confirmBooking($userId, (int) ($params['id'] ?? 0));

            return $this->success([], 'تم تأكيد الحجز');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/bookings/{id}/cancel */
    public function cancel(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }

        $userId = $this->scopeId();
        $reason = (string) $this->get('reason', '');

        try {
            $this->engine->cancelBooking($userId, (int) ($params['id'] ?? 0), $reason);

            return $this->success([], 'تم إلغاء الحجز');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/bookings/{id}/checkout */
    public function checkout(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }

        $userId = $this->scopeId();
        $bookingId = (int) ($params['id'] ?? 0);
        $successUrl = (string) $this->get('success_url', '');
        $cancelUrl = (string) $this->get('cancel_url', '');
        $gateway = (string) $this->get('gateway', '');

        try {
            // 🔒 فلوس الحجز تروح لصاحب النشاط مش لحساب المنصة
            if (class_exists('OperatorPayments')) {
                $mode = OperatorPayments::effectiveMode($userId);
                if ($mode === 'request') {
                    return $this->error('فعّل استلام الدفع الأونلاين الأول: منشئ المواقع ← 💳 استلام الفلوس (اربط حساب Stripe بتاعك)', 422);
                }
                if ($mode === 'own_stripe') {
                    $result = OperatorPayments::stripeFor($userId)->createCheckoutSession($userId, $bookingId, $successUrl, $cancelUrl);
                    $result['gateway'] = 'stripe';
                    return $this->success($result, 'تم إنشاء رابط الدفع');
                }
            }
            $gatewayService = $this->resolvePaymentGateway($gateway);
            $result = $gatewayService->createCheckoutSession($userId, $bookingId, $successUrl, $cancelUrl);
            $result['gateway'] = $gatewayService->key();

            return $this->success($result, 'تم إنشاء رابط الدفع');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** POST /api/webhook/booking/stripe - بدون Auth (التوثيق بالتوقيع) */
    public function stripeWebhook(array $params = []): array
    {
        $payload = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $result = (new StripeCheckoutService())->handleWebhook($payload, $signature);

            return $this->success(['handled' => $result['handled'], 'event' => $result['event']]);
        } catch (Exception $e) {
            $code = $e->getCode() === 401 ? 401 : 500;
            http_response_code($code);
            return $this->error(CustomerError::message($e->getMessage(), 500), $code);
        }
    }

    /** POST /api/webhook/booking/paymob - بدون Auth (التوثيق بـ hmac query param) */
    public function paymobWebhook(array $params = []): array
    {
        $payload = file_get_contents('php://input') ?: '';
        $hmac = isset($_GET['hmac']) && is_string($_GET['hmac']) ? $_GET['hmac'] : '';

        try {
            $result = (new PaymobGateway())->handleWebhook($payload, $hmac);

            return $this->success(['handled' => $result['handled'], 'event' => $result['event']]);
        } catch (Exception $e) {
            $code = $e->getCode() === 401 ? 401 : 500;
            http_response_code($code);
            return $this->error(CustomerError::message($e->getMessage(), 500), $code);
        }
    }

    /**
     * اختيار بوابة الدفع للـ checkout - Stripe و Paymob قابلين للتبديل
     * من نفس النقطة. `$requested` لو واضح (stripe|paymob) بيستخدمها
     * جبرًا؛ غير كده بيختار أول بوابة مفعّلة بترتيب Stripe ثم Paymob.
     *
     * @return StripeCheckoutService|PaymobGateway
     */
    private function resolvePaymentGateway(string $requested = ''): object
    {
        $stripe = new StripeCheckoutService();
        $paymob = new PaymobGateway();
        $requested = strtolower(trim($requested));

        if ($requested === 'paymob') {
            if ($paymob->isConfigured()) {
                return $paymob;
            }
            throw new Exception('بوابة الدفع Paymob غير مُفعّلة');
        }
        if ($requested === 'stripe') {
            if ($stripe->isConfigured()) {
                return $stripe;
            }
            throw new Exception('بوابة الدفع Stripe غير مُفعّلة');
        }

        return $stripe->isConfigured() ? $stripe : $paymob;
    }

    /** GET /api/bookings/dashboard */
    public function dashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $userId = $this->scopeId();
        $stats = (new Booking())->dashboardStats($userId);

        return $this->success(['stats' => $stats]);
    }

    /** GET /api/inventory/{productId}/calendar?from=&to= */
    public function calendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $productId = (int) ($params['productId'] ?? 0);
        // 🔒 قبل كده أي حساب كان يقدر يشوف توفر منتج حساب تاني
        $userId = $this->scopeId();
        if (!Database::getInstance()->query('SELECT id FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1', [$productId, $userId])) {
            return $this->error('الخدمة غير موجودة', 404);
        }
        $from = (string) $this->get('from', date('Y-m-d'));
        $to = (string) $this->get('to', date('Y-m-d', strtotime('+30 days')));

        $calendar = (new InventoryDay())->calendar($productId, $from, $to);

        return $this->success(['calendar' => $calendar,'capacity_mode'=>(new BookingOperationsService())->productSettings($userId,$productId)['capacity_mode']]);
    }

    /** POST /api/inventory/{productId} { date, capacity, price_override?, is_blocked? } */
    public function setInventory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }

        if (!$this->validate(['date' => 'required', 'capacity' => 'required|numeric'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $userId = $this->scopeId();
        $productId = (int) ($params['productId'] ?? 0);

        if(filter_var($this->get('capacity'),FILTER_VALIDATE_INT)===false)return $this->error('السعة لازم تكون عددًا صحيحًا',422);
        try {
            $this->inventory->setDay(
                $userId,
                $productId,
                (string) $this->get('date'),
                (int) $this->get('capacity'),
                $this->get('price_override') !== null ? (float) $this->get('price_override') : null,
                filter_var($this->get('is_blocked', false), FILTER_VALIDATE_BOOLEAN)
            );

            return $this->success([], 'تم تحديث التوفر');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /bookings - صفحة الحجوزات والتوفر */
    public function page(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=%2Fbookings');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('bookings', 'الحجوزات والتوفر', 'كل الحجوزات اللي جاتلك من موقعك، وتحديد الأماكن المتاحة لكل يوم', self::PAGE_HTML, self::PAGE_JS);
        exit;
    }

    /** GET /api/bookings/reminders/settings */
    public function reminderSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $svc = new TripReminderService();
        $s = $svc->settings($this->scopeId());
        $sample = [
            'customer_name' => 'أحمد', 'product_name' => 'رحلة الأقصر يوم كامل', 'start_date' => TripReminderService::localDate('+1 day'),
            'start_time' => '07:30:00', 'adults_count' => 2, 'children_count' => 1, 'booking_reference' => 'BK-1A2B3C4D',
        ];
        $sampleEn = ['customer_name' => 'John', 'product_name' => 'Luxor Full Day Tour'] + $sample;
        return $this->success(['settings' => $s, 'preview' => $svc->render($sample, $s, 'ar'), 'preview_en' => $svc->render($sampleEn, $s, 'en')]);
    }

    /** POST /api/bookings/reminders/settings */
    public function saveReminderSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        $r = (new TripReminderService())->save($this->scopeId(), [
            'enabled' => $this->get('enabled', 0),
            'send_hour' => $this->get('send_hour', 18),
            'channel' => (string) $this->get('channel', 'auto'),
            'meeting_point' => (string) $this->get('meeting_point', ''),
            'contact_phone' => (string) $this->get('contact_phone', ''),
            'message_template' => (string) $this->get('message_template', ''),
            'message_template_en' => (string) $this->get('message_template_en', ''),
            'language' => (string) $this->get('language', 'auto'),
        ]);
        return $r['ok'] ? $this->success([], 'اتحفظت إعدادات التذكير') : $this->error($r['error'], 422);
    }

    /** POST /api/bookings/reminders/test {to: phone|email} - تجربة على رقمك/إيميلك */
    public function testReminder(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($rl = $this->rateLimitGuard('user', 'trip_reminder_test', 5, 600)) {
            return $rl;
        }
        $to = trim((string) $this->get('to', ''));
        $isEmail = (bool) filter_var($to, FILTER_VALIDATE_EMAIL);
        if (!$isEmail && strlen(preg_replace('/\D/', '', $to)) < 8) {
            return $this->error('اكتب رقم واتساب (بكود الدولة) أو إيميل', 422);
        }
        $svc = new TripReminderService();
        $s = $svc->settings($this->scopeId());
        $text = $svc->render([
            'customer_name' => 'تجربة', 'product_name' => 'رحلة تجريبية', 'start_date' => TripReminderService::localDate('+1 day'),
            'adults_count' => 2, 'booking_reference' => 'BK-TEST',
        ], $s);
        $r = $svc->deliver($this->scopeId(), $isEmail ? 'email' : 'whatsapp', $isEmail ? null : $to, $isEmail ? $to : null, $text, 'تجربة: تذكير الرحلة');
        return $r['ok'] ? $this->success(['channel' => $r['channel']], 'اتبعتت رسالة التجربة ✓') : $this->error($r['error'] ?: 'تعذر الإرسال', 422);
    }

    /** POST /api/bookings/{id}/remind - ابعت التذكير دلوقتي لحجز معيّن */
    public function remind(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        if ($rl = $this->rateLimitGuard('user', 'trip_reminder_manual', 30, 600)) {
            return $rl;
        }
        $r = (new TripReminderService())->sendForBookingId($this->scopeId(), (int) ($params['id'] ?? 0));
        if (!$r['ok']) {
            return $this->error($r['error'] ?: 'تعذر الإرسال', 422);
        }
        return $this->success(['channel' => $r['channel']], $r['channel'] === 'whatsapp' ? 'اتبعت التذكير على واتساب ✓' : 'اتبعت التذكير على الإيميل ✓');
    }

    /** GET /api/bookings/{id}/payments - كل الدفعات المتسجّلة على الحجز (أونلاين + يدوي) */
    public function payments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $db = Database::getInstance();
        $b = $db->query('SELECT id, total_amount, amount_paid, currency FROM bookings WHERE id = ? AND user_id = ? LIMIT 1', [(int) ($params['id'] ?? 0), $this->scopeId()]);
        if (!$b) {
            return $this->error('الحجز غير موجود', 404);
        }
        $rows = $db->query(
            "SELECT id, amount, currency, gateway, payment_method, status, metadata, created_at FROM payment_transactions
              WHERE booking_id = ? AND status IN ('succeeded','partially_refunded','refunded') ORDER BY id DESC LIMIT 50",
            [(int) $b[0]['id']]
        );
        $out = [];
        foreach ($rows as $r) {
            $m = json_decode((string) ($r['metadata'] ?? ''), true) ?: [];
            $out[] = [
                'id' => (int) $r['id'], 'amount' => (float) $r['amount'], 'currency' => $r['currency'],
                'manual' => $r['gateway'] === 'manual', 'method' => $r['payment_method'],
                'note' => (string) ($m['note'] ?? ''), 'kind' => (string) ($m['payment_kind'] ?? ''), 'created_at' => $r['created_at'],
            ];
        }
        return $this->success(['payments' => $out, 'booking' => $b[0]]);
    }

    /**
     * POST /api/bookings/{id}/payments {amount, method: cash|transfer|pos|other, note?}
     * تسجيل دفعة اتدفعت برا المنصة (كاش/تحويل/ماكينة). لو الحجز لسه "مستني" بيتأكد.
     */
    public function addPayment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        $db = Database::getInstance();
        $owner = $this->scopeId();
        $b = $db->query('SELECT * FROM bookings WHERE id = ? AND user_id = ? LIMIT 1', [(int) ($params['id'] ?? 0), $owner])[0] ?? null;
        if (!$b) {
            return $this->error('الحجز غير موجود', 404);
        }
        if (!in_array($b['status'], ['pending', 'confirmed', 'completed'], true)) {
            return $this->error('الحجز ده ملغي', 422);
        }
        $inputAmount=$this->get('amount',0);
        if(!is_numeric($inputAmount)||!is_finite((float)$inputAmount))return $this->error('مبلغ غير صالح',422);
        $amount = round((float) $inputAmount, 2);
        $remaining = round((float) $b['total_amount'] - (float) $b['amount_paid'], 2);
        if ($amount <= 0) {
            return $this->error('اكتب المبلغ', 422);
        }
        if ($amount > $remaining + 0.001) {
            return $this->error('المبلغ أكبر من الباقي على الحجز (' . $remaining . ' ' . $b['currency'] . ')', 422);
        }
        $method = in_array($this->get('method', 'cash'), ['cash', 'transfer', 'pos', 'other'], true) ? (string) $this->get('method', 'cash') : 'cash';
        $note = mb_substr(trim(strip_tags((string) $this->get('note', ''))), 0, 200);
        $actor = (int) ($this->getUser()['id'] ?? 0);
        try{
        $db->transaction(function (Database $db) use ($b, $amount, $method, $note, $actor, $owner) {
            $current=$db->query('SELECT * FROM bookings WHERE id=? AND user_id=? FOR UPDATE',[(int)$b['id'],$owner])[0]??null;
            if(!$current||!in_array($current['status'],['pending','confirmed','completed'],true))throw new RuntimeException('الحجز لا يقبل دفعات جديدة');
            if($amount>round((float)$current['total_amount']-(float)$current['amount_paid'],2)+0.001)throw new RuntimeException('الدفعة تتجاوز المتبقي على الحجز؛ حدّث الصفحة وراجع المبلغ');
            $db->query(
                'INSERT INTO payment_transactions (internal_transaction_id, user_id, amount, currency, payment_method, gateway, status, reference, booking_id, metadata, idempotency_key)
                 VALUES (?, ?, ?, ?, ?, \'manual\', \'succeeded\', ?, ?, ?, ?)',
                ['MAN-' . strtoupper(bin2hex(random_bytes(8))), $owner, $amount, (string) $b['currency'], $method, (string) $b['booking_reference'], (int) $b['id'],
                 json_encode(['manual' => true, 'note' => $note, 'recorded_by' => $actor, 'amount_recorded' => true], JSON_UNESCAPED_UNICODE),
                 'manual-' . $b['id'] . '-' . bin2hex(random_bytes(6))]
            );
            $db->query('UPDATE bookings SET amount_paid = LEAST(total_amount, amount_paid + ?) WHERE id = ?', [$amount, (int) $b['id']]);
        });
        }catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
        $confirmed = false;
        if ($b['status'] === 'pending') {
            try {
                $confirmed = $this->engine->confirmBooking($owner, (int) $b['id']);
            } catch (Throwable $e) {
                $confirmed = false;
            }
        }
        return $this->success(['confirmed' => $confirmed], 'اتسجّلت الدفعة' . ($confirmed ? ' والحجز اتأكد ✓' : ''), 201);
    }

    /** DELETE /api/bookings/{id}/payments/{paymentId} - إلغاء دفعة يدوي اتسجّلت غلط */
    public function removePayment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        $db = Database::getInstance();
        $tx = $db->query(
            "SELECT t.id, t.amount, t.booking_id FROM payment_transactions t JOIN bookings b ON b.id = t.booking_id
              WHERE t.id = ? AND t.booking_id = ? AND b.user_id = ? AND t.gateway = 'manual' AND t.status = 'succeeded' LIMIT 1",
            [(int) ($params['paymentId'] ?? 0), (int) ($params['id'] ?? 0), $this->scopeId()]
        )[0] ?? null;
        if (!$tx) {
            return $this->error('الدفعة مش موجودة (الدفعات الأونلاين بتترجع من Stripe مش من هنا)', 404);
        }
        try{
            $db->transaction(function(Database $db)use($tx){
                $db->query('SELECT id FROM bookings WHERE id=? FOR UPDATE',[(int)$tx['booking_id']]);
                $changed=$db->query("UPDATE payment_transactions SET status='cancelled' WHERE id=? AND gateway='manual' AND status='succeeded'",[(int)$tx['id']]);
                if($changed){$db->query('UPDATE bookings SET amount_paid=GREATEST(0,amount_paid-?) WHERE id=?',[(float)$tx['amount'],(int)$tx['booking_id']]);}
            });
        }catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
        return $this->success([], 'اتشالت الدفعة');
    }

    /** POST /api/bookings/{id}/status {status: completed|no_show} - بعد يوم الرحلة */
    public function setStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        $userId = $this->scopeId();
        $status = (string) $this->get('status', '');
        if (!in_array($status, ['completed', 'no_show'], true)) {
            return $this->error('حالة مش مدعومة', 422);
        }
        $db = Database::getInstance();
        $row = $db->query('SELECT id, status, start_date FROM bookings WHERE id = ? AND user_id = ? LIMIT 1', [(int) ($params['id'] ?? 0), $userId]);
        if (!$row) {
            return $this->error('الحجز غير موجود', 404);
        }
        if ($row[0]['status'] !== 'confirmed') {
            return $this->error('الحجز لازم يكون مؤكد الأول', 422);
        }
        if (strtotime((string) $row[0]['start_date']) > strtotime(date('Y-m-d'))) {
            return $this->error('ماينفعش قبل يوم الرحلة', 422);
        }
        $db->exec('UPDATE bookings SET status = ? WHERE id = ?', [$status, (int) $row[0]['id']]);
        try {
            $db->exec('INSERT INTO booking_status_history (booking_id, from_status, to_status, changed_by_user_id, reason) VALUES (?, ?, ?, ?, ?)',
                [(int) $row[0]['id'], 'confirmed', $status, (int) ($this->getUser()['id'] ?? 0), 'من صفحة الحجوزات']);
        } catch (Throwable $e) {
        }
        return $this->success([], $status === 'completed' ? 'اتعلّم إن الرحلة خلصت' : 'اتعلّم إن العميل ماحضرش');
    }

    /**
     * POST /api/inventory/{productId}/bulk {from, to, capacity, weekdays?: [0-6], is_blocked?}
     * تحديد التوفر لفترة مرة واحدة (مثلاً: كل يوم جمعة وسبت لحد آخر السنة 20 مكان)
     */
    public function bulkInventory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        if ($ro = $this->readOnlyError()) {
            return $ro;
        }
        $userId = $this->scopeId();
        $productId = (int) ($params['productId'] ?? 0);
        if (!InventoryService::isValidDate((string) $this->get('from', '')) || !InventoryService::isValidDate((string) $this->get('to', ''))) {
            return $this->error('حدد تاريخًا صالحًا بالصيغة YYYY-MM-DD', 422);
        }
        $from = strtotime((string) $this->get('from', ''));
        $to = strtotime((string) $this->get('to', ''));
        $capacity = filter_var($this->get('capacity', -1),FILTER_VALIDATE_INT);
        $blocked = filter_var($this->get('is_blocked', false), FILTER_VALIDATE_BOOLEAN);
        if (!$from || !$to || $to < $from) {
            return $this->error('حدد فترة صحيحة', 422);
        }
        if ($from < strtotime(date('Y-m-d'))) {
            $from = strtotime(date('Y-m-d'));
        }
        if (($to - $from) / 86400 > 366) {
            return $this->error('أقصى فترة سنة', 422);
        }
        if ($capacity === false || $capacity < 0 || $capacity > 10000) {
            return $this->error('السعة لازم تكون رقم من 0', 422);
        }
        $days = $this->get('weekdays', []);
        $days = is_array($days) ? array_map('intval', $days) : [];
        $done = 0;
        $skipped = [];
        for ($t = $from; $t <= $to; $t = strtotime('+1 day', $t)) {
            if ($days && !in_array((int) date('w', $t), $days, true)) {
                continue;
            }
            try {
                $this->inventory->setDay($userId, $productId, date('Y-m-d', $t), $capacity, null, $blocked);
                $done++;
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'غير مملوكة')) {
                    return $this->error(CustomerError::message($e->getMessage(), 400), 404);
                }
                $skipped[] = date('Y-m-d', $t); // فيه حجوزات أكتر من السعة الجديدة
            }
        }
        return $this->success(['updated' => $done, 'skipped' => $skipped],
            'اتحدّث ' . $done . ' يوم' . ($skipped ? ' - واتساب ' . count($skipped) . ' يوم فيهم حجوزات أكتر من السعة الجديدة' : ''));
    }

    private const PAGE_HTML = <<<'HTML'
<style>
.bk-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
.bk-tile{padding:14px 16px}
.bk-tile b{display:block;font-size:22px}
.bk-tile span{font-size:12.5px;color:var(--panel-text-muted)}
.bk-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}
.bk-day{border:1px solid var(--panel-border);border-radius:10px;padding:6px;min-height:74px;font-size:12px;background:var(--panel-card-bg)}
.bk-day.blocked{opacity:.55;background:repeating-linear-gradient(45deg,transparent,transparent 6px,rgba(127,127,127,.12) 6px,rgba(127,127,127,.12) 12px)}
.bk-day.full{border-color:var(--panel-danger,#d33)}
.bk-day .d{font-weight:700}
.bk-day input{width:100%;box-sizing:border-box;margin-top:4px;padding:3px 2px;font-size:12px;text-align:center;-moz-appearance:textfield;appearance:textfield}
.bk-day input::-webkit-inner-spin-button,.bk-day input::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}
.bk-day small{display:block;color:var(--panel-text-muted)}
.bk-wd{text-align:center;font-size:11.5px;color:var(--panel-text-muted)}
.bk-actions{display:flex;gap:6px;flex-wrap:wrap}
@media (max-width:640px){.bk-cal{gap:3px}.bk-day{padding:4px;min-height:64px;font-size:11px}}
</style>
<div class="bk-tiles" id="bkTiles"></div>
<div class="p-tabs" style="margin-bottom:14px;">
    <button type="button" class="p-tab active" data-tab="list" onclick="bkTab('list')">📋 الحجوزات</button>
    <button type="button" class="p-tab" data-tab="avail" onclick="bkTab('avail')">📅 التوفر</button>
    <button type="button" class="p-tab" data-tab="remind" onclick="bkTab('remind')">⏰ تذكير قبل الرحلة</button>
</div>

<div id="bkList" class="p-card no-pad">
    <div class="p-toolbar" style="padding:14px 16px 0;flex-wrap:wrap;gap:8px;">
        <select id="bkStatus" class="p-select" onchange="bkLoad()">
            <option value="">كل الحالات</option>
            <option value="pending">مستني تأكيد/دفع</option>
            <option value="confirmed">مؤكد</option>
            <option value="completed">خلصت</option>
            <option value="cancelled">ملغي</option>
            <option value="no_show">ماحضرش</option>
        </select>
        <input id="bkSearch" class="p-select" placeholder="بحث بالاسم أو الكود أو الموبايل" style="min-width:200px;">
    </div>
    <div class="p-table-scroll"><table class="p-table">
        <thead><tr><th>الكود</th><th>العميل</th><th>الرحلة</th><th>التاريخ</th><th>الأفراد</th><th>المبلغ</th><th>الحالة</th><th></th></tr></thead>
        <tbody id="bkRows"><tr class="p-loading-row"><td colspan="8">جاري التحميل...</td></tr></tbody>
    </table></div>
    <div style="padding:10px 16px;display:flex;gap:8px;justify-content:center;">
        <button class="p-btn outline xs" id="bkPrev" onclick="bkPage(-1)">السابق</button>
        <button class="p-btn outline xs" id="bkNext" onclick="bkPage(1)">التالي</button>
    </div>
</div>

<div class="p-modal-overlay" id="plModal">
    <div class="p-modal" style="max-width:520px;">
        <div class="p-modal-head"><h3>💳 الدفع</h3><button class="p-modal-close" onclick="P_close('plModal')">×</button></div>
        <div class="p-modal-body">
            <div id="plInfo" class="p-cell-muted" style="font-size:13px;margin-bottom:10px;"></div>
            <div class="p-tabs" style="margin-bottom:12px;">
                <button type="button" class="p-tab active" data-pltab="link" onclick="plTab('link')">🔗 لينك دفع للعميل</button>
                <button type="button" class="p-tab" data-pltab="manual" onclick="plTab('manual')">💵 سجّل دفعة</button>
            </div>
            <div id="plManual" style="display:none;">
                <p class="p-cell-muted" style="font-size:12.5px;margin-top:0;">العميل دفع كاش أو تحويل أو على الماكينة؟ سجّلها هنا عشان الحساب يبقى مظبوط. لو الحجز لسه "مستني" هيتأكد.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <div style="width:130px;"><label class="form-label">المبلغ</label><input type="number" id="mpAmount" class="form-control" min="0.01" step="0.01"></div>
                    <div style="flex:1;min-width:140px;"><label class="form-label">طريقة الدفع</label>
                        <select id="mpMethod" class="form-control"><option value="cash">كاش</option><option value="transfer">تحويل (بنك / إنستاباي / محفظة)</option><option value="pos">ماكينة / فيزا عندك</option><option value="other">غير كده</option></select></div>
                </div>
                <label class="form-label" style="margin-top:8px;">ملاحظة (اختياري)</label>
                <input id="mpNote" class="form-control" maxlength="200" placeholder="مثلاً: رقم التحويل، أو استلمها أحمد">
                <button class="p-btn primary" style="margin-top:10px;" onclick="mpAdd()">💾 سجّل الدفعة</button>
            </div>
            <div id="plForm">
                <label class="form-label">العميل يدفع</label>
                <select id="plKind" class="form-control" onchange="plKindChanged()">
                    <option value="deposit_pct">عربون بنسبة من الإجمالي</option>
                    <option value="deposit_fixed">عربون بمبلغ ثابت</option>
                    <option value="full">الباقي كله</option>
                </select>
                <div id="plValueWrap" style="display:flex;gap:8px;align-items:center;margin-top:8px;">
                    <input type="number" id="plValue" class="form-control" min="1" step="0.01" value="30" style="max-width:140px;">
                    <span id="plValueUnit">%</span>
                    <span id="plCalc" class="p-cell-muted" style="font-size:13px;"></span>
                </div>
                <label class="form-label" style="margin-top:10px;">اللينك صالح لمدة</label>
                <select id="plHours" class="form-control">
                    <option value="24">يوم</option><option value="48" selected>يومين</option><option value="72">3 أيام</option><option value="168">أسبوع</option>
                </select>
                <button class="p-btn primary" style="margin-top:12px;" onclick="plCreate()">اعمل اللينك</button>
            </div>
            <div id="plResult" style="display:none;margin-top:12px;"></div>
            <div id="plHistory" style="margin-top:14px;"></div>
            <div id="plPayments" style="margin-top:14px;"></div>
        </div>
    </div>
</div>

<div id="bkRemind" style="display:none;">
    <div class="p-grid cols-2" style="align-items:start;">
        <div class="p-card">
            <div class="p-card-head"><h3>⏰ تذكير العميل اليوم اللي قبل رحلته</h3></div>
            <p class="p-cell-muted" style="font-size:13px;margin-top:0;">بيقلل اللي مابيجوش، وبيوفّر عليك مكالمات "الميعاد إمتى؟". كل حجز مؤكد بيوصله تذكير واحد بس.</p>
            <label style="display:flex;gap:8px;align-items:center;margin:10px 0;font-weight:700;"><input type="checkbox" id="rmEnabled"> شغّل التذكير التلقائي</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:140px;"><label class="form-label">يتبعت اليوم اللي قبلها الساعة</label><select id="rmHour" class="form-control"></select></div>
                <div style="flex:1;min-width:140px;"><label class="form-label">عن طريق</label>
                    <select id="rmChannel" class="form-control">
                        <option value="auto">واتساب، أو إيميل لو مفيش رقم</option>
                        <option value="whatsapp">واتساب بس</option>
                        <option value="email">إيميل بس</option>
                    </select></div>
            </div>
            <label class="form-label" style="margin-top:10px;">📍 مكان التجمع</label>
            <textarea id="rmMeeting" class="form-control" rows="2" placeholder="مثلاً: لوبي فندق ستيجنبرجر - الأقصر، أو رابط لوكيشن Google Maps"></textarea>
            <label class="form-label" style="margin-top:10px;">📞 رقم التواصل اللي يظهر للعميل</label>
            <input id="rmPhone" class="form-control" dir="ltr" placeholder="+20 100 000 0000">
            <label class="form-label" style="margin-top:10px;">لغة الرسالة</label>
            <select id="rmLang" class="form-control">
                <option value="auto">تلقائي: عربي للأسماء العربي، إنجليزي للأجانب</option>
                <option value="ar">عربي دايمًا</option>
                <option value="en">إنجليزي دايمًا</option>
            </select>
            <label class="form-label" style="margin-top:10px;">نص الرسالة (عربي)</label>
            <textarea id="rmTemplate" class="form-control" rows="8" dir="auto"></textarea>
            <details style="margin-top:8px;"><summary style="cursor:pointer;font-size:13px;">نص الرسالة (إنجليزي)</summary>
                <textarea id="rmTemplateEn" class="form-control" rows="8" dir="ltr" style="margin-top:6px;"></textarea>
            </details>
            <p class="p-cell-muted" style="font-size:12px;">المتغيرات: {name} {trip} {when} {date} {time} {people} {meeting_point} {contact} {reference} - <a href="#" onclick="rmReset();return false;">رجّع النص الافتراضي</a></p>
            <button class="p-btn primary" onclick="rmSave()">💾 احفظ</button>
        </div>
        <div>
            <div class="p-card" style="margin-bottom:14px;">
                <div class="p-card-head"><h3>👀 شكل الرسالة</h3></div>
                <div id="rmPreview" dir="auto" style="white-space:pre-wrap;background:var(--panel-card-bg-2,#f5f7fb);border-radius:12px;padding:12px 14px;font-size:14px;line-height:1.8;"></div>
                <div id="rmPreviewEn" dir="ltr" style="text-align:left;white-space:pre-wrap;background:var(--panel-card-bg-2,#f5f7fb);border-radius:12px;padding:12px 14px;font-size:14px;line-height:1.8;margin-top:10px;"></div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>🧪 جرّبها على نفسك</h3></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input id="rmTestTo" class="form-control" dir="ltr" placeholder="201001234567 أو you@mail.com" style="flex:1;min-width:180px;">
                    <button class="p-btn outline" onclick="rmTest()">ابعت تجربة</button>
                </div>
                <p class="p-cell-muted" style="font-size:12px;">الواتساب بيتبعت من رقمك المربوط في التكاملات (UltraMsg). احفظ الأول عشان التجربة تاخد آخر نص.</p>
            </div>
            <div class="p-card" style="margin-top:14px;">
                <b>☀️ وعايز ملخص ليك انت كل يوم الصبح؟</b>
                <p class="p-cell-muted" style="font-size:12.5px;margin:6px 0 10px;">حجوزات وفلوس امبارح، رحلات النهارده، واللي مستنيك ترد عليه - في رسالة واتساب واحدة.</p>
                <a class="p-btn outline xs" href="/profile/settings?tab=notifications#mbCard">فعّل ملخص الصبح</a>
            </div>
        </div>
    </div>
</div>

<div id="bkAvail" style="display:none;">
    <div class="p-card" style="margin-bottom:14px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;"><label class="form-label">الرحلة / الخدمة</label><select id="bkProduct" class="form-control" onchange="bkCal()"></select></div>
            <div><button class="p-btn outline xs" onclick="bkShift(-30)">‹ الشهر اللي فات</button> <button class="p-btn outline xs" onclick="bkShift(30)">الشهر الجاي ›</button></div>
        </div>
        <p class="p-cell-muted" style="font-size:12px;margin:10px 0 0;">الرقم في كل يوم = عدد الحجوزات المسموحة. غيّره واضغط برّه الخانة يتحفظ. 0 = اليوم مقفول.</p>
    </div>
    <div class="p-card" style="margin-bottom:14px;">
        <div class="bk-cal" id="bkCalHead"></div>
        <div class="bk-cal" id="bkCalGrid" style="margin-top:6px;"></div>
    </div>
    <div class="p-card">
        <div class="p-card-head"><h3>⚡ تحديد فترة مرة واحدة</h3></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <div><label class="form-label">من</label><input type="date" id="bkFrom" class="form-control"></div>
            <div><label class="form-label">لحد</label><input type="date" id="bkTo" class="form-control"></div>
            <div style="width:110px;"><label class="form-label">السعة</label><input type="number" min="0" id="bkCap" class="form-control" value="10"></div>
            <div><label class="form-label">الأيام</label><div id="bkWeekdays" style="display:flex;gap:4px;flex-wrap:wrap;"></div></div>
            <button class="p-btn primary" onclick="bkBulk()">تطبيق</button>
        </div>
    </div>
</div>
HTML;

    private const PAGE_JS = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const STATUS = { pending: ['مستني', 'orange'], confirmed: ['مؤكد', 'green'], completed: ['خلصت', 'blue'], cancelled: ['ملغي', 'gray'], no_show: ['ماحضرش', 'red'] };
    const WD = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];
    let page = 1, products = [], calStart = new Date(), lastRows = 0;
    calStart.setHours(0, 0, 0, 0);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    window.bkTab = function (t) {
        document.querySelectorAll('.p-tab[data-tab]').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
        document.getElementById('bkList').style.display = t === 'list' ? '' : 'none';
        document.getElementById('bkAvail').style.display = t === 'avail' ? '' : 'none';
        document.getElementById('bkRemind').style.display = t === 'remind' ? '' : 'none';
        if (t === 'avail') bkCal();
        if (t === 'remind') rmLoad();
    };

    async function tiles() {
        const r = await fetchJSON('/api/bookings/dashboard');
        if (!r.success) return;
        const s = r.data.stats;
        document.getElementById('bkTiles').innerHTML = [
            ['⏳', s.pending, 'مستنيين تأكيد أو دفع'], ['✅', s.confirmed, 'مؤكدين'],
            ['🏁', s.completed, 'رحلات خلصت'], ['💰', P.money ? P.money(s.total_revenue) : Number(s.total_revenue).toFixed(2), 'إيراد الحجوزات المؤكدة'],
        ].map(t => '<div class="p-card bk-tile"><span>' + t[0] + ' ' + esc(t[2]) + '</span><b dir="ltr">' + esc(String(t[1])) + '</b></div>').join('');
    }

    window.bkLoad = async function () {
        const qs = new URLSearchParams({ page: page, per_page: 25 });
        const st = document.getElementById('bkStatus').value; if (st) qs.set('status', st);
        const q = document.getElementById('bkSearch').value.trim(); if (q) qs.set('search', q);
        const r = await fetchJSON('/api/bookings?' + qs.toString());
        const body = document.getElementById('bkRows');
        if (!r.success) { body.innerHTML = '<tr><td colspan="8">' + esc(r.error || 'تعذر التحميل') + '</td></tr>'; return; }
        const rows = r.data.bookings || [];
        lastRows = rows.length;
        document.getElementById('bkPrev').disabled = page <= 1;
        document.getElementById('bkNext').disabled = rows.length < 25;
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="8"><div class="p-empty" style="padding:20px 0;"><div class="p-empty-icon">📭</div>مفيش حجوزات لسه. شارك رابط موقع الحجز بتاعك مع عملائك.</div></td></tr>';
            return;
        }
        body.innerHTML = rows.map(b => {
            const s = STATUS[b.status] || [b.status, 'gray'];
            const past = new Date(b.start_date + 'T00:00:00') <= today;
            const acts = [];
            if (b.status === 'pending') acts.push('<button class="p-btn primary xs" onclick="bkAct(' + b.id + ',&quot;confirm&quot;)">تأكيد</button>');
            if (b.status === 'confirmed' && past) {
                acts.push('<button class="p-btn outline xs" onclick="bkAct(' + b.id + ',&quot;completed&quot;)">خلصت</button>');
                acts.push('<button class="p-btn outline xs" onclick="bkAct(' + b.id + ',&quot;no_show&quot;)">ماحضرش</button>');
            }
            const due = Number(b.total_amount) - Number(b.amount_paid || 0);
            if (['pending', 'confirmed', 'completed'].indexOf(b.status) !== -1 && (due > 0.009 || Number(b.amount_paid || 0) > 0)) acts.push('<button class="p-btn outline xs" title="لينك دفع للعميل، أو تسجيل دفعة كاش/تحويل" onclick="plOpen(' + b.id + ')">💳 الدفع</button>');
            if (b.status === 'confirmed' && !past) acts.push('<button class="p-btn outline xs" title="ابعت تذكير بالرحلة دلوقتي" onclick="bkRemind(' + b.id + ')">🔔 ذكّره</button>');
            if (b.status === 'pending' || b.status === 'confirmed') acts.push('<button class="p-btn danger xs" onclick="bkAct(' + b.id + ',&quot;cancel&quot;)">إلغاء</button>');
            const rmTag = b.reminder_status ? (String(b.reminder_status).indexOf('sent') === 0
                ? '<br><small title="' + esc(b.reminder_sent_at || '') + '">🔔 اتبعت تذكير</small>'
                : '<br><small style="color:var(--panel-danger,#d33)" title="' + esc(b.reminder_error || '') + '">⚠️ التذكير ماوصلش</small>') : '';
            const contact = [b.customer_phone ? '<a href="https://wa.me/' + esc(String(b.customer_phone).replace(/\D/g, '')) + '" target="_blank" rel="noopener" dir="ltr">' + esc(b.customer_phone) + '</a>' : '', b.customer_email ? '<span dir="ltr">' + esc(b.customer_email) + '</span>' : ''].filter(Boolean).join('<br>');
            return '<tr><td dir="ltr"><code>' + esc(b.booking_reference) + '</code></td>'
                + '<td><b>' + esc(b.customer_name || '-') + '</b><br><small>' + contact + '</small></td>'
                + '<td>' + esc(b.product_name || '-') + '</td>'
                + '<td dir="ltr">' + esc(b.start_date) + '</td>'
                + '<td>' + (Number(b.adults_count) + Number(b.children_count || 0)) + '</td>'
                + '<td dir="ltr">' + esc(Number(b.total_amount).toFixed(2) + ' ' + (b.currency || '')) + (Number(b.amount_paid || 0) > 0 ? '<br><small style="color:var(--panel-success,#0e9f6e)">' + (due > 0.009 ? 'مدفوع ' + Number(b.amount_paid).toFixed(2) : 'مدفوع بالكامل ✓') + '</small>' : '') + '</td>'
                + '<td><span class="pill ' + s[1] + '">' + esc(s[0]) + '</span>' + rmTag + '</td>'
                + '<td><div class="bk-actions">' + acts.join('') + '</div></td></tr>';
        }).join('');
    };
    window.bkPage = function (d) { page = Math.max(1, page + d); bkLoad(); };
    let searchTimer = null;
    document.getElementById('bkSearch').addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(function () { page = 1; bkLoad(); }, 350); });

    window.bkAct = async function (id, act) {
        if (act === 'cancel' && !confirm('تلغي الحجز ده؟ المكان هيرجع متاح.')) return;
        const url = act === 'confirm' ? '/api/bookings/' + id + '/confirm'
            : act === 'cancel' ? '/api/bookings/' + id + '/cancel' : '/api/bookings/' + id + '/status';
        const r = await fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(act === 'completed' || act === 'no_show' ? { status: act } : {}) });
        if (r.success) { toast(r.message || 'تم', 'success'); bkLoad(); tiles(); } else toast(r.error || 'تعذر', 'error');
    };

    // ===== لينك الدفع =====
    let plBooking = null, plRows = [];
    window.P_close = function (id) { P.closeModal ? P.closeModal(id) : document.getElementById(id).classList.remove('open'); };
    function plDue() { return plBooking ? Math.max(0, Number(plBooking.total_amount) - Number(plBooking.amount_paid || 0)) : 0; }
    window.plKindChanged = function () {
        const k = document.getElementById('plKind').value;
        document.getElementById('plValueWrap').style.display = k === 'full' ? 'none' : 'flex';
        document.getElementById('plValueUnit').textContent = k === 'deposit_pct' ? '%' : (plBooking ? plBooking.currency : '');
        if (k === 'deposit_pct' && Number(document.getElementById('plValue').value) > 100) document.getElementById('plValue').value = 30;
        plCalc();
    };
    function plCalc() {
        if (!plBooking) return;
        const k = document.getElementById('plKind').value, v = Number(document.getElementById('plValue').value || 0);
        const amt = k === 'deposit_pct' ? Number(plBooking.total_amount) * v / 100 : (k === 'full' ? plDue() : v);
        document.getElementById('plCalc').textContent = '= ' + amt.toFixed(2) + ' ' + plBooking.currency;
    }
    document.addEventListener('input', function (e) { if (e.target && e.target.id === 'plValue') plCalc(); });
    function plLinkHtml(l) {
        const st = { active: ['شغّال', 'green'], paid: ['اتدفع ✓', 'blue'], expired: ['انتهى', 'gray'], cancelled: ['اتلغى', 'gray'], replaced: ['اتبدّل', 'gray'] }[l.status] || [l.status, 'gray'];
        return '<div class="p-card" style="padding:10px 12px;margin-bottom:8px;">'
            + '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><b dir="ltr">' + esc(Number(l.amount).toFixed(2) + ' ' + l.currency) + '</b>'
            + '<span class="pill ' + st[1] + '">' + esc(st[0]) + '</span><small class="p-cell-muted">' + (l.kind === 'deposit' ? 'عربون' : 'المبلغ كله') + ' · اتفتح ' + l.views + ' مرة</small></div>'
            + (l.status === 'active' ? '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">'
                + '<input class="form-control" dir="ltr" readonly value="' + esc(l.url) + '" style="flex:1;min-width:160px;font-size:12px;" onclick="this.select()">'
                + '<button class="p-btn outline xs" onclick="plCopy(' + l.id + ')">📋 انسخ الرسالة</button>'
                + (l.whatsapp_url ? '<a class="p-btn primary xs" target="_blank" rel="noopener" href="' + esc(l.whatsapp_url) + '">واتساب</a>' : '')
                + '<button class="p-btn danger xs" onclick="plCancel(' + l.id + ')">إلغاء</button></div>' : '')
            + '</div>';
    }
    async function plLoad() {
        const r = await fetchJSON('/api/bookings/' + plBooking.id + '/payment-links');
        plRows = r.success ? r.data.links : [];
        if (r.success && !r.data.online_payments) {
            document.getElementById('plForm').innerHTML = '<div class="alert alert-warning" style="margin:0;">لازم تفعّل الدفع الأونلاين الأول (اربط حساب Stripe بتاعك) - من <a href="/website-builder">صفحة موقع الحجز ← استلام الفلوس</a>.</div>';
        }
        document.getElementById('plHistory').innerHTML = plRows.length ? '<b style="font-size:13px;">اللينكات</b>' + plRows.map(plLinkHtml).join('') : '';
    }
    window.plTab = function (t) {
        document.querySelectorAll('[data-pltab]').forEach(b => b.classList.toggle('active', b.dataset.pltab === t));
        document.getElementById('plManual').style.display = t === 'manual' ? '' : 'none';
        ['plForm', 'plHistory', 'plResult'].forEach(id => { const el = document.getElementById(id); if (el && id !== 'plResult') el.style.display = t === 'link' ? '' : 'none'; });
        if (t === 'manual') { const a = document.getElementById('mpAmount'); a.value = plDue() > 0 ? plDue().toFixed(2) : ''; a.focus(); }
    };
    const MP_METHOD = { cash: 'كاش', transfer: 'تحويل', pos: 'ماكينة', other: 'غير كده', card: 'أونلاين (Stripe)' };
    async function mpLoad() {
        const r = await fetchJSON('/api/bookings/' + plBooking.id + '/payments');
        if (!r.success) return;
        plBooking.amount_paid = r.data.booking.amount_paid;
        const list = r.data.payments || [];
        document.getElementById('plPayments').innerHTML = list.length ? '<b style="font-size:13px;">الدفعات المتسجّلة</b>' + list.map(function (p) {
            return '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 0;border-bottom:1px solid var(--panel-border);font-size:13px;">'
                + '<b dir="ltr">' + esc(Number(p.amount).toFixed(2) + ' ' + p.currency) + '</b>'
                + '<span class="pill ' + (p.manual ? 'gray' : 'blue') + '">' + esc(p.manual ? (MP_METHOD[p.method] || p.method) : 'أونلاين') + '</span>'
                + '<small class="p-cell-muted" style="flex:1;">' + esc((p.note || '') + ' ' + String(p.created_at).slice(0, 16)) + '</small>'
                + (p.manual ? '<button class="p-btn danger xs" onclick="mpRemove(' + p.id + ')">شيلها</button>' : '') + '</div>';
        }).join('') : '';
    }
    window.mpAdd = async function () {
        const amount = Number(document.getElementById('mpAmount').value || 0);
        if (amount <= 0) { toast('اكتب المبلغ', 'error'); return; }
        const r = await fetchJSON('/api/bookings/' + plBooking.id + '/payments', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            amount: amount, method: document.getElementById('mpMethod').value, note: document.getElementById('mpNote').value,
        }) });
        if (!r.success) { toast(r.error || 'تعذر', 'error'); return; }
        toast(r.message, 'success');
        document.getElementById('mpNote').value = '';
        await mpLoad(); plInfoText(); bkLoad(); tiles();
    };
    window.mpRemove = async function (pid) {
        if (!confirm('تشيل الدفعة دي؟ (لو اتسجّلت غلط)')) return;
        const r = await fetchJSON('/api/bookings/' + plBooking.id + '/payments/' + pid, { method: 'DELETE' });
        if (r.success) { toast(r.message, 'success'); await mpLoad(); plInfoText(); bkLoad(); } else toast(r.error || 'تعذر', 'error');
    };
    function plInfoText() {
        document.getElementById('plInfo').textContent = (plBooking.customer_name || '') + ' · ' + plBooking.start_date + ' · الإجمالي ' + Number(plBooking.total_amount).toFixed(2) + ' ' + plBooking.currency
            + (Number(plBooking.amount_paid || 0) > 0 ? ' · مدفوع ' + Number(plBooking.amount_paid).toFixed(2) + ' · الباقي ' + plDue().toFixed(2) : '');
    }

    window.plOpen = async function (id) {
        const r = await fetchJSON('/api/bookings/' + id);
        if (!r.success) { toast(r.error || 'تعذر', 'error'); return; }
        plBooking = r.data.booking;
        plInfoText();
        document.getElementById('plResult').style.display = 'none';
        plKindChanged();
        const past = new Date(plBooking.start_date + 'T00:00:00') < today;
        plTab(past || plDue() <= 0 ? 'manual' : 'link');
        P.openModal ? P.openModal('plModal') : document.getElementById('plModal').classList.add('open');
        plLoad();
        mpLoad();
    };
    window.plCreate = async function () {
        const k = document.getElementById('plKind').value;
        const r = await fetchJSON('/api/bookings/' + plBooking.id + '/payment-links', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            kind: k, value: Number(document.getElementById('plValue').value || 0), hours: parseInt(document.getElementById('plHours').value, 10),
        }) });
        if (!r.success) { toast(r.error || 'تعذر', 'error'); return; }
        toast('اتعمل اللينك - ابعته للعميل', 'success');
        plLoad();
    };
    window.plCopy = function (id) {
        const l = plRows.find(x => x.id === id); if (!l) return;
        const done = function () { toast('اتنسخت الرسالة مع اللينك ✓', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(l.message).then(done, done); else done();
    };
    window.plCancel = async function (id) {
        if (!confirm('تلغي اللينك ده؟ العميل مش هيقدر يدفع بيه.')) return;
        const r = await fetchJSON('/api/payment-links/' + id + '/cancel', { method: 'POST' });
        if (r.success) plLoad(); else toast(r.error || 'تعذر', 'error');
    };

    window.bkRemind = async function (id) {
        const r = await fetchJSON('/api/bookings/' + id + '/remind', { method: 'POST' });
        if (r.success) { toast(r.message, 'success'); bkLoad(); } else toast(r.error || 'تعذر', 'error');
    };

    // ===== تذكير قبل الرحلة =====
    let rmDefault = '', rmDefaultEn = '', rmLoaded = false;
    const rmSample = { name: 'أحمد', trip: 'رحلة الأقصر يوم كامل', people: '3', reference: 'BK-1A2B3C4D', time: ' الساعة 07:30' };
    function rmRender() {
        const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
        const d = iso(tomorrow);
        const meet = document.getElementById('rmMeeting').value.trim() || 'هنبعتلك التفاصيل';
        const phone = document.getElementById('rmPhone').value.trim() || '-';
        let t = document.getElementById('rmTemplate').value || rmDefault;
        const map = { '{name}': rmSample.name, '{trip}': rmSample.trip, '{when}': 'بكرة ' + d, '{date}': d, '{time}': rmSample.time,
            '{people}': rmSample.people, '{meeting_point}': meet, '{contact}': phone, '{reference}': rmSample.reference };
        Object.keys(map).forEach(k => { t = t.split(k).join(map[k]); });
        t = t.split('\n').filter(l => !/: -\s*$/.test(l)).join('\n');
        document.getElementById('rmPreview').textContent = t;
        let te = document.getElementById('rmTemplateEn').value || rmDefaultEn;
        const mapEn = { '{name}': 'John', '{trip}': 'Luxor Full Day Tour', '{when}': 'tomorrow, ' + d, '{date}': d, '{time}': ' at 07:30',
            '{people}': rmSample.people, '{meeting_point}': document.getElementById('rmMeeting').value.trim() || 'we will send you the details', '{contact}': phone, '{reference}': rmSample.reference };
        Object.keys(mapEn).forEach(k => { te = te.split(k).join(mapEn[k]); });
        te = te.split('\n').filter(l => !/: -\s*$/.test(l)).join('\n');
        const lang = document.getElementById('rmLang').value;
        document.getElementById('rmPreview').style.display = lang === 'en' ? 'none' : '';
        document.getElementById('rmPreviewEn').style.display = lang === 'ar' ? 'none' : '';
        document.getElementById('rmPreviewEn').textContent = te;
    }
    window.rmLoad = async function () {
        if (rmLoaded) return;
        const hs = document.getElementById('rmHour');
        hs.innerHTML = Array.from({ length: 17 }, (_, i) => i + 6).map(h => '<option value="' + h + '">' + (h > 12 ? (h - 12) + ' مساءً' : h === 12 ? '12 الضهر' : h + ' صباحًا') + '</option>').join('');
        const r = await fetchJSON('/api/bookings/reminders/settings');
        if (!r.success) { toast(r.error || 'تعذر التحميل', 'error'); return; }
        const s = r.data.settings;
        rmDefault = s.default_template;
        document.getElementById('rmEnabled').checked = Number(s.enabled) === 1;
        hs.value = String(s.send_hour);
        document.getElementById('rmChannel').value = s.channel;
        document.getElementById('rmMeeting').value = s.meeting_point || '';
        document.getElementById('rmPhone').value = s.contact_phone || '';
        document.getElementById('rmTemplate').value = s.message_template || rmDefault;
        rmDefaultEn = s.default_template_en || '';
        document.getElementById('rmTemplateEn').value = s.message_template_en || rmDefaultEn;
        document.getElementById('rmLang').value = s.language || 'auto';
        ['rmMeeting', 'rmPhone', 'rmTemplate', 'rmTemplateEn'].forEach(id => document.getElementById(id).addEventListener('input', rmRender));
        document.getElementById('rmLang').addEventListener('change', rmRender);
        rmRender();
        rmLoaded = true;
    };
    window.rmReset = function () { document.getElementById('rmTemplate').value = rmDefault; document.getElementById('rmTemplateEn').value = rmDefaultEn; rmRender(); };
    window.rmSave = async function () {
        const r = await fetchJSON('/api/bookings/reminders/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            enabled: document.getElementById('rmEnabled').checked ? 1 : 0,
            send_hour: parseInt(document.getElementById('rmHour').value, 10),
            channel: document.getElementById('rmChannel').value,
            meeting_point: document.getElementById('rmMeeting').value,
            contact_phone: document.getElementById('rmPhone').value,
            message_template: document.getElementById('rmTemplate').value,
            message_template_en: document.getElementById('rmTemplateEn').value,
            language: document.getElementById('rmLang').value,
        }) });
        if (r.success) toast(r.message, 'success'); else toast(r.error || 'تعذر الحفظ', 'error');
    };
    window.rmTest = async function () {
        const to = document.getElementById('rmTestTo').value.trim();
        if (!to) { toast('اكتب رقمك أو إيميلك', 'error'); return; }
        const r = await fetchJSON('/api/bookings/reminders/test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ to: to }) });
        if (r.success) toast(r.message, 'success'); else toast(r.error || 'تعذر الإرسال', 'error');
    };

    async function loadProducts(preselect) {
        const r = await fetchJSON('/api/crm/products?only_active=1');
        products = (r.success && r.data.products) ? r.data.products : [];
        const sel = document.getElementById('bkProduct');
        sel.innerHTML = products.length ? products.map(p => '<option value="' + p.id + '">' + esc(p.name) + '</option>').join('') : '<option value="">مفيش رحلات - ضيف رحلات في موقع الحجز الأول</option>';
        if (preselect && products.some(p => String(p.id) === String(preselect))) sel.value = String(preselect);
    }

    window.bkShift = function (d) { calStart.setDate(calStart.getDate() + d); if (calStart < today) calStart = new Date(today); bkCal(); };

    window.bkCal = async function () {
        const pid = document.getElementById('bkProduct').value;
        const grid = document.getElementById('bkCalGrid');
        document.getElementById('bkCalHead').innerHTML = WD.map(w => '<div class="bk-wd">' + w + '</div>').join('');
        if (!pid) { grid.innerHTML = ''; return; }
        const start = new Date(calStart); start.setDate(start.getDate() - start.getDay());
        const end = new Date(start); end.setDate(end.getDate() + 34);
        const r = await fetchJSON('/api/inventory/' + pid + '/calendar?from=' + iso(start) + '&to=' + iso(end));
        const capacityUnit=r.success && r.data.capacity_mode==='seat'?'مقعد':'حجز';
        const map = {};
        (r.success ? r.data.calendar : []).forEach(d => { map[d.date] = d; });
        let html = '';
        for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            const k = iso(d), inv = map[k], past = d < today;
            const cap = inv ? Number(inv.capacity) : '', booked = inv ? Number(inv.booked_count) : 0;
            const blocked = inv && (Number(inv.is_blocked) === 1 || Number(inv.capacity) === 0);
            const full = inv && !blocked && booked >= Number(inv.capacity);
            html += '<div class="bk-day' + (blocked ? ' blocked' : '') + (full ? ' full' : '') + '" style="' + (past ? 'opacity:.35;' : '') + '">'
                + '<div class="d">' + d.getDate() + '/' + (d.getMonth() + 1) + '</div>'
                + (past ? '' : '<input type="number" min="0" value="' + cap + '" placeholder="—" data-date="' + k + '" onchange="bkSetDay(this)">')
                + '<small>' + (inv ? booked + ' '+capacityUnit+' محجوز' : 'مش محدد') + '</small></div>';
        }
        grid.innerHTML = html;
    };

    window.bkSetDay = async function (el) {
        const pid = document.getElementById('bkProduct').value;
        const cap = el.value === '' ? null : parseInt(el.value, 10);
        if (cap === null || isNaN(cap) || cap < 0) return;
        const r = await fetchJSON('/api/inventory/' + pid, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ date: el.dataset.date, capacity: cap, is_blocked: cap === 0 }) });
        if (r.success) { toast('اتحفظ ' + el.dataset.date, 'success'); bkCal(); } else { toast(r.error || 'تعذر', 'error'); bkCal(); }
    };

    window.bkBulk = async function () {
        const pid = document.getElementById('bkProduct').value;
        if (!pid) return;
        const weekdays = Array.from(document.querySelectorAll('.bkWd:checked')).map(c => parseInt(c.value, 10));
        const cap = parseInt(document.getElementById('bkCap').value, 10);
        const r = await fetchJSON('/api/inventory/' + pid + '/bulk', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
            from: document.getElementById('bkFrom').value, to: document.getElementById('bkTo').value,
            capacity: cap, is_blocked: cap === 0, weekdays: weekdays,
        }) });
        if (r.success) { toast(r.message, 'success'); bkCal(); } else toast(r.error || 'تعذر', 'error');
    };

    document.getElementById('bkWeekdays').innerHTML = WD.map((w, n) => '<label style="font-size:12px;display:flex;gap:3px;align-items:center;border:1px solid var(--panel-border);border-radius:8px;padding:3px 6px;"><input type="checkbox" class="bkWd" value="' + n + '" checked>' + w + '</label>').join('');
    const t0 = new Date(); document.getElementById('bkFrom').value = iso(t0);
    const t1 = new Date(); t1.setMonth(t1.getMonth() + 3); document.getElementById('bkTo').value = iso(t1);

    const qp = new URLSearchParams(location.search);
    tiles(); bkLoad();
    loadProducts(qp.get('product')).then(function () { if (qp.get('product')) bkTab('avail'); });
    if (qp.get('tab') === 'remind') bkTab('remind');
})();
JS;
}
