<?php

/**
 * Tourfecto - Outreach Controller
 * Phase 10 (Backlink/Outreach Agent). بيدير الـPipeline الكامل:
 * Prospect → Research (يدوي - العميل بيدخل بيانات حقيقية) → Personalization
 * (AI) → Email (Draft/Approve) → Send (SMTP حقيقي، رسالة واحدة في كل مرة -
 * مش Bulk) → Follow-up → Reply/Negotiation/Link Acquired (تتبع يدوي).
 *
 * قرار مهم عن الإرسال: بنستخدم Mailer.php الموجود بالفعل (SMTP لرسالة
 * واحدة)، مش أي نظام Bulk/Campaign جديد - وده متوافق تمامًا مع تحذير
 * السبيك ("لا ترسل Emails جماعية بدون نظام حماية وموافقة") لأن كل رسالة
 * بتتبعت لوحدها وبعد موافقة صريحة من العميل (status لازم يكون 'approved'
 * الأول)، مش إرسال جماعي تلقائي.
 * @version 1.0.0
 */
class OutreachController extends Controller
{
    private $subscription;
    private $emailGenerator;
    private $discoveryService;

    public function __construct()
    {
        parent::__construct();
        $this->subscription = new SubscriptionValidator();
        $this->emailGenerator = new OutreachEmailGenerator();
        $this->discoveryService = new ProspectDiscoveryService(
            new CompetitorBacklinkDiscoverySource(),
            $this->emailGenerator
        );
    }

    public function index(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $options='';foreach($this->db->query('SELECT id,company_name,domain FROM websites WHERE user_id=? ORDER BY id',[(int)$this->user['id']]) as $site){$options.='<option value="'.(int)$site['id'].'">'.htmlspecialchars((string)($site['company_name']?:$site['domain']),ENT_QUOTES,'UTF-8').'</option>';}
        $body='<link rel="stylesheet" href="/assets/css/service-center.css"><div id="outreachCenter" data-csrf="'.$csrf.'"><label>Website<select id="outreachSite">'.$options.'</select></label><p id="outreachStatus" role="status" aria-live="polite"></p><div id="outreachTools"></div></div><script src="/assets/js/outreach-center.js"></script>';
        echo $this->renderPanelPage('outreach','Outreach and backlinks','Research prospects, review individual emails and verify acquired links',$body,'');exit;
    }

    /**
     * POST /api/outreach/discover  { website_id }
     * اكتشاف تلقائي لمرشّحين للـ Backlink من بيانات المنافسين المتتبعين
     * (بيانات عامة معلنة فقط - بدون أي استخراج بيانات تواصل شخصية)،
     * مع توليد مسودة رسالة لكل مرشح جديد. أي إرسال فعلي بيظل محتاج
     * موافقة صريحة (approveEmail) - الاكتشاف/الصياغة تلقائيين، الإرسال لأ.
     * Rate limit: 10 اكتشافات في الساعة لكل مستخدم (CiRateLimiter).
     */
    public function discover(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $limit = CiRateLimiter::hit('discovery_run', 'user:' . (int) $this->user['id']);
        if (!$limit['allowed']) {
            $minutes = (int) ceil($limit['retry_after'] / 60);
            return $this->error('وصلت للحد الأقصى للاكتشاف (10/ساعة) - جرب تاني بعد ' . $minutes . ' دقيقة', 429);
        }

        $result = $this->discoveryService->discoverForWebsite((int) $this->user['id'], $websiteId);

        if (!$result['available']) {
            return $this->success($result, 'مفيش بيانات كافية للاكتشاف حاليًا - أضف منافسين متتبعين الأول', 200);
        }

        return $this->success($result, 'تم الاكتشاف - المرشحون الجدد محفوظون ومسوداتهم جاهزة للمراجعة والموافقة قبل أي إرسال');
    }

    /** GET /api/outreach/prospects?website_id=X */
    public function listProspects(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $status = $this->get('status');
        $sql = "SELECT * FROM outreach_prospects WHERE website_id = ? AND user_id = ?";
        $bindings = [$websiteId, $this->user['id']];
        if ($status) {
            $sql .= " AND status = ?";
            $bindings[] = $status;
        }
        $sql .= " ORDER BY id DESC";

        $prospects = $this->db->query($sql, $bindings);
        return $this->success(['prospects' => $prospects]);
    }

    /** POST /api/outreach/prospects  { website_id, domain, contact_name?, contact_email?, business_type?, relevant_page?, collaboration_idea?, notes? } */
    public function addProspect(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $domain = trim((string) $this->get('domain', ''));
        if (!$websiteId || $domain === '') {
            return $this->error('website_id و domain مطلوبين', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $prospect = new OutreachProspect([
            'user_id' => (int) $this->user['id'],
            'website_id' => $websiteId,
            'domain' => $domain,
            'contact_name' => $this->get('contact_name') ?: null,
            'contact_email' => $this->get('contact_email') ?: null,
            'business_type' => $this->get('business_type') ?: null,
            'relevant_page' => $this->get('relevant_page') ?: null,
            'collaboration_idea' => $this->get('collaboration_idea') ?: null,
            'notes' => $this->get('notes') ?: null,
            'status' => 'prospect',
        ]);
        $prospect->save();

        $this->log('Outreach Prospect Added', ['website_id' => $websiteId, 'domain' => $domain]);
        return $this->success(['prospect' => $prospect->toArray()], 'تمت إضافة المرشّح');
    }

    /** POST /api/outreach/prospects/{id}/status  { status, link_url? } */
    public function updateProspectStatus(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $prospectId = (int) ($params['id'] ?? 0);
        $status = (string) $this->get('status', '');
        $allowed = ['prospect', 'researched', 'contacted', 'replied', 'negotiating', 'link_acquired', 'declined'];
        if (!$prospectId || !in_array($status, $allowed, true)) {
            return $this->error('بيانات غير صالحة', 422);
        }

        $prospect = $this->ownedProspect($prospectId);
        if (!$prospect) {
            return $this->error('المرشّح غير موجود', 404);
        }

        $prospect->setAttribute('status', $status);
        if ($status === 'link_acquired' && $this->get('link_url')) {
            $prospect->setAttribute('link_url', $this->get('link_url'));
        }
        $prospect->save();

        // Item 2a: عند الحصول على الرابط فعليًا، نسجّله في مراقبة
        // الباك لينكس (idempotent - لن يتكرر لنفس المرشّح). فشل التسجيل
        // لا يكسر تحديث الحالة - يتحمّل بهدوء.
        if ($status === 'link_acquired' && $prospect->getAttribute('link_url')) {
            try {
                (new BacklinkMonitorService())->registerAcquiredLink(
                    (int) $this->user['id'],
                    (int) $prospect->getAttribute('website_id'),
                    (int) $prospect->getAttribute('id'),
                    (string) $prospect->getAttribute('link_url'),
                    (string) $prospect->getAttribute('domain')
                );
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Outreach: failed to register acquired backlink', ['prospect_id' => (int) $prospect->getAttribute('id'), 'error' => CustomerError::message($e->getMessage(), 500)]);
                }
            }
        }

        return $this->success(['prospect' => $prospect->toArray()], 'تم تحديث الStatus');
    }

    /** GET /api/outreach/backlinks?website_id=X - حالة الباك لينكس المسجّلة للمراقبة */
    public function listBacklinks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $backlinks = $this->db->query(
            'SELECT b.*, p.domain AS prospect_domain
             FROM monitored_backlinks b
             LEFT JOIN outreach_prospects p ON p.id = b.prospect_id
             WHERE b.user_id = ? AND b.website_id = ?
             ORDER BY b.created_at DESC
             LIMIT 200',
            [(int) $this->user['id'], $websiteId]
        );

        $summary = (new BacklinkMonitorService())->summaryForWebsite((int) $this->user['id'], $websiteId);
        return $this->success(['backlinks' => $backlinks, 'summary' => $summary]);
    }

    /** POST /api/outreach/backlinks/{id}/check - فحص رابط مسجّل فورًا */
    public function checkBacklink(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $backlinkId = (int) ($params['id'] ?? 0);
        if ($backlinkId <= 0) {
            return $this->error('بيانات غير صالحة', 422);
        }

        $backlink = (new MonitoredBacklink())->find($backlinkId);
        if (!$backlink || (int) $backlink->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الرابط غير موجود', 404);
        }

        $result = (new BacklinkMonitorService())->checkLink($backlinkId);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر الفحص', 502);
        }

        return $this->success(['backlink' => $result['backlink']], 'تم الفحص - الStatus: ' . $result['status']);
    }

    /** GET /api/outreach/performance?website_id=X - تقرير أداء الـ Pipeline (Item 2c) */
    public function performanceReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $report = (new OutreachPerformanceService())->report((int) $this->user['id'], $websiteId);
        return $this->success(['report' => $report]);
    }

    /** POST /api/outreach/emails/generate  { prospect_id, sequence_number? } */
    public function generateEmail(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $prospectId = (int) $this->get('prospect_id');
        $sequenceNumber = (int) $this->get('sequence_number', 0);
        if (!$prospectId) {
            return $this->error('prospect_id مطلوب', 422);
        }

        $prospect = $this->ownedProspect($prospectId);
        if (!$prospect) {
            return $this->error('المرشّح غير موجود', 404);
        }

        $creditsCheck = $this->subscription->reserveAICredits((int) $this->user['id'], 1);
        if (!$creditsCheck['available']) {
            return $this->error($creditsCheck['message'] ?? 'رصيد الذكاء الاصطناعي غير كافٍ', 403);
        }

        $website = (new Website())->find((int) $prospect->getAttribute('website_id'));
        $myWebsite = [
            'company_name' => $website ? $website->getAttribute('company_name') : null,
            'main_url' => $website ? $website->getAttribute('main_url') : null,
            'industry' => $website ? $website->getAttribute('industry') : null,
        ];

        $result = $this->emailGenerator->generate($prospect->toArray(), $myWebsite, $sequenceNumber);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر توليد الرسالة', 502);
        }

        $email = new OutreachEmail([
            'prospect_id' => $prospectId,
            'sequence_number' => $sequenceNumber,
            'subject' => $result['data']['subject'],
            'body' => $result['data']['body'],
            'status' => 'draft',
        ]);
        $email->save();

        if (!$this->subscription->consumeAICredits((int) $this->user['id'], 1, $creditsCheck['source'] === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');
        $this->log('Outreach Email Generated', ['prospect_id' => $prospectId, 'sequence_number' => $sequenceNumber]);

        return $this->success(['email' => $email->toArray()], 'تم توليد مسودة الرسالة');
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /** GET /api/outreach/emails?prospect_id=X */
    public function listEmails(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $prospectId = (int) $this->get('prospect_id');
        if (!$prospectId) {
            return $this->error('prospect_id مطلوب', 422);
        }
        if (!$this->ownedProspect($prospectId)) {
            return $this->error('المرشّح غير موجود', 404);
        }

        $emails = $this->db->query(
            "SELECT * FROM outreach_emails WHERE prospect_id = ? ORDER BY sequence_number ASC, id ASC",
            [$prospectId]
        );
        return $this->success(['emails' => $emails]);
    }

    /**
     * POST /api/outreach/emails/{id}/edit  { subject?, body? }
     * تعديل يدوي قبل الموافقة/الإرسال - العميل ممكن يعدّل أي حاجة في المسودة.
     */
    public function editEmail(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $emailId = (int) ($params['id'] ?? 0);
        $email = $this->ownedEmail($emailId);
        if (!$email) {
            return $this->error('الرسالة غير موجودة', 404);
        }

        if ($email->getAttribute('status') === 'sent') {
            return $this->error('الرسالة اتبعتت بالفعل - مينفعش تتعدل', 422);
        }

        if ($this->get('subject') !== null) {
            $email->setAttribute('subject', (string) $this->get('subject'));
        }
        if ($this->get('body') !== null) {
            $email->setAttribute('body', (string) $this->get('body'));
        }
        $email->save();

        return $this->success(['email' => $email->toArray()], 'تم التحديث');
    }

    /** POST /api/outreach/emails/{id}/approve */
    public function approveEmail(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $emailId = (int) ($params['id'] ?? 0);
        $email = $this->ownedEmail($emailId);
        if (!$email) {
            return $this->error('الرسالة غير موجودة', 404);
        }

        if ($email->getAttribute('status') === 'sent') {
            return $this->error('الرسالة اتبعتت بالفعل', 422);
        }

        $email->setAttribute('status', 'approved');
        $email->save();

        return $this->success(['email' => $email->toArray()], 'تمت الموافقة - جاهزة للإرسال');
    }

    /**
     * POST /api/outreach/emails/{id}/send
     * إرسال فعلي حقيقي - رسالة واحدة بس في كل نداء (مش Bulk)، وبيرفض
     * الإرسال لو الرسالة مش status='approved' الأول (خطوة موافقة إجبارية).
     */
    public function sendEmail(array $params = []): array
    {
        if($csrfError=$this->verifyCsrf())return $csrfError;
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $emailId = (int) ($params['id'] ?? 0);
        $email = $this->ownedEmail($emailId);
        if (!$email) {
            return $this->error('الرسالة غير موجودة', 404);
        }

        if ($email->getAttribute('status') !== 'approved') {
            return $this->error('لازم توافق على الرسالة الأول قبل الإرسال', 422);
        }

        $prospect = (new OutreachProspect())->find((int) $email->getAttribute('prospect_id'));
        if (!$prospect || (int) $prospect->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('المرشّح غير موجود', 404);
        }
        $toEmail = $prospect->getAttribute('contact_email');
        if (!$toEmail) {
            return $this->error('مفيش إيميل تواصل مسجّل للمرشّح ده - أضفه الأول', 422);
        }

        if (!class_exists('Mailer')) {
            return $this->error('خدمة البريد غير متاحة', 500);
        }
        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            return $this->error('إعدادات البريد (MAIL_USERNAME/MAIL_PASSWORD) مش متظبطة على السيرفر - كلم الأدمن', 503);
        }

        $toName = $prospect->getAttribute('contact_name') ?: $prospect->getAttribute('domain');
        $htmlBody = nl2br(htmlspecialchars((string) $email->getAttribute('body'), ENT_QUOTES, 'UTF-8'));
        try{
            $result=ExternalExecution::run('outreach-email:'.(int)$this->user['id'].':'.$emailId,(int)$this->user['id'],function()use($mailer,$toEmail,$toName,$email,$htmlBody){
                $r=$mailer->send($toEmail,$toName,(string)$email->getAttribute('subject'),$htmlBody);
                if(empty($r['success']))throw new RuntimeException('SMTP delivery is uncertain. Review the mail provider before another send.');
                return $r;
            });
        }catch(Throwable $e){return $this->error('Delivery needs provider review. Do not send this email again until its outcome is verified.',409);}

        if (!$result['success']) {
            $email->setAttribute('status', 'failed');
            $email->setAttribute('error_message', $result['error'] ?? 'فشل الإرسال');
            $email->save();
            return $this->error('فشل إرسال الرسالة: ' . ($result['error'] ?? ''), 502);
        }

        $email->setAttribute('status', 'sent');
        $email->setAttribute('sent_at', date('Y-m-d H:i:s'));
        $email->save();

        if ($prospect->getAttribute('status') === 'prospect' || $prospect->getAttribute('status') === 'researched') {
            $prospect->setAttribute('status', 'contacted');
            $prospect->save();
        }

        $this->log('Outreach Email Sent', ['prospect_id' => $prospect->getAttribute('id'), 'email_id' => $emailId]);
        return $this->success(['email' => $email->toArray()], 'تم إرسال الرسالة');
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $website = (new Website())->find($websiteId);
        return $website && (int) $website->getAttribute('user_id') === (int) $this->user['id'];
    }

    private function ownedProspect(int $prospectId): ?OutreachProspect
    {
        $prospect = (new OutreachProspect())->find($prospectId);
        if (!$prospect || (int) $prospect->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $prospect;
    }

    private function ownedEmail(int $emailId): ?OutreachEmail
    {
        $email = (new OutreachEmail())->find($emailId);
        if (!$email) {
            return null;
        }
        $prospect = $this->ownedProspect((int) $email->getAttribute('prospect_id'));
        return $prospect ? $email : null;
    }
}
