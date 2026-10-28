<?php
require_once dirname(__DIR__).'/Services/Subscription/UsageReservationReview.php';
class UsageReservationAdminController extends Controller
{
    private function allowed(bool $write=false): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role']??'', ['admin','super_admin'],true)
            && AdminAccess::can($this->user,$write?'billing.manage':'billing.view');
    }
    public function index(array $params=[]): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح',403);
        $body='<section class="p-card"><h2>مراجعة الاستخدام المعلق / Pending usage</h2><p>راجع نتيجة الطلب الأصلي قبل التسوية. اختر «تم التنفيذ» إذا استلم العميل الخدمة، أو «لم ينفذ» إذا تأكدت أنها لم تنفذ. الوقت وحده لا يثبت الفشل. لا تحرر رصيد طلب لا يزال يعمل.</p><a href="/admin/billing-ops">الدفع والكوبونات</a> · <a href="/admin/jobs">مهام واتساب وإعادة المحاولة</a><div id="urNotice" data-csrf="'.htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8').'" role="status"></div><div id="urList"></div><h3>استقبال واتساب</h3><p>الحالة uncertain تعني أن الرسالة محفوظة وقد يكون الرد أرسل؛ راجع المحادثة قبل إرسال رد يدوي. لا تتم إعادة إرسال رد غير محسوم تلقائيًا.</p><div id="urWhatsApp"></div></section><script src="'.htmlspecialchars(asset_v('/assets/js/usage-reservations.js'),ENT_QUOTES,'UTF-8').'"></script>';
        echo $this->renderPanelPage('subscription','مراجعة الاستخدام المعلق','تسوية بعد التحقق من النتيجة',$body); exit;
    }
    public function listing(array $params=[]): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح',403);
        header('Cache-Control: private, no-store');
        return $this->success([
            'can_resolve'=>$this->allowed(true),
            'service_reservations'=>$this->db->query("SELECT token,user_id,feature_key feature,1 quantity,source,amount,state,created_at,updated_at FROM service_usage_reservations WHERE state IN ('reserved','uncertain') ORDER BY created_at LIMIT 200"),
            'reservations'=>$this->db->query("SELECT token,user_id,subscription_id,feature,quantity,source,amount,state,created_at,updated_at FROM billing_usage_reservations WHERE state IN ('reserved','uncertain') ORDER BY created_at ASC LIMIT 200"),
            'whatsapp'=>$this->db->query("SELECT id,website_id,kind,state,attempts,job_id,last_error,created_at,updated_at FROM whatsapp_webhook_inbox WHERE state IN ('queued','processing','failed','uncertain') ORDER BY id DESC LIMIT 100"),
        ]);
    }
    public function resolve(array $params=[]): array
    {
        if (!$this->allowed(true)) return $this->error('غير مصرح',403);
        if (!Csrf::verify((string)$this->get('csrf_token'))) return $this->error('انتهت صلاحية الجلسة، حدّث الصفحة',419);
        try {
            $method=$this->get('kind')==='service'?'resolveService':'resolve';
            return $this->success(UsageReservationReview::$method((string)($params['token']??''),(string)$this->get('outcome'),(string)$this->get('evidence'),(int)$this->user['id']));
        } catch (InvalidArgumentException $e) {return $this->error($e->getMessage(),422);}
        catch (RuntimeException $e) {return $this->error($e->getMessage(),409);}
        catch (Throwable $e) {Logger::error('Usage review failed');return $this->error('تعذر تأكيد التسوية. حدّث القائمة قبل إعادة المحاولة',503);}
    }
}
