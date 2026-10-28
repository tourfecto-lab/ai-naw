<?php
/** Operational view with explicit server-side admin authorization on every action. */
class CreativeAdminController extends Controller
{
    private function allowed(): bool{return $this->isAuthenticated()&&in_array($this->user['role']??'',['admin','super_admin'],true);}
    public function index(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('Forbidden',403);
        $body='<div class="p-card"><h2>متابعة استوديو الوسائط</h2><p>الرصيد المحجوز ليس خصمًا. تكلفة المزود تُسجّل من الفاتورة الفعلية، وتظهر منفصلة عن سعر بيع الخدمة.</p><a href="/admin" class="p-btn outline">العودة للإدارة</a><div id="csAdminSummary" aria-live="polite"></div><div class="p-toolbar"><label>حالة الطلب <select id="csAdminStatus" class="p-select"><option value="all">الكل</option><option value="generating">قيد التنفيذ</option><option value="failed">لم يكتمل</option><option value="completed">مكتمل</option></select></label><label>رقم العميل <input id="csAdminUser" class="p-select" type="number" min="1"></label><button id="csAdminRefresh" class="p-btn">تحديث</button></div><div id="csAdminRows"></div><button id="csAdminMore" class="p-btn outline" hidden>تحميل المزيد</button><p id="csAdminMessage" role="status"></p></div><script src="'.htmlspecialchars(asset_v('/assets/js/creative-admin.js'),ENT_QUOTES,'UTF-8').'"></script>';
        echo $this->renderPanelPage('creative-studio','متابعة استوديو الوسائط','الطلبات والاستخدام والتكلفة المسجلة',$body,'window.CreativeAdmin.init(window.Panel);');exit;
    }
    public function listing(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('Forbidden',403);
        try{$status=$this->get('status','all');$user=$this->get('user_id','');$cursor=$this->get('cursor','');if(!in_array($status,['all','generating','failed','completed'],true)||!is_scalar($user)||!is_scalar($cursor)||($user!==''&&!ctype_digit((string)$user))||($cursor!==''&&!ctype_digit((string)$cursor)))throw new InvalidArgumentException('Invalid filter');$pdo=$this->db->getConnection();$sql='SELECT * FROM media_items WHERE 1=1';$args=[];if($status!=='all'){$sql.=' AND status=?';$args[]=$status;}if((int)$user){$sql.=' AND user_id=?';$args[]=(int)$user;}if((int)$cursor){$sql.=' AND id<?';$args[]=(int)$cursor;}$s=$pdo->prepare($sql.' ORDER BY id DESC LIMIT 101');$s->execute($args);$rows=$s->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>100;$rows=array_slice($rows,0,100);$public=[];
            foreach($rows as $row){$item=GenerationOptions::publicItem($row);$item['user_id']=(int)$row['user_id'];$item['provider_cost']=$row['provider_cost']===null?null:(float)$row['provider_cost'];$item['recoverable']=!empty($row['file_path'])&&!$row['usage_charged']&&empty($row['deleted_at'])&&($row['status']==='failed'||($row['status']==='generating'&&$row['generation_stage']==='billing'));$item['preview']=$row['file_path']&&empty($row['deleted_at'])&&in_array($row['status'],['completed','failed'],true)?'/api/admin/creative-studio/media/'.(int)$row['id'].'/content':null;$public[]=$item;}
            $s=$pdo->prepare("SELECT status,COUNT(*) total FROM media_items WHERE created_at>=? GROUP BY status");$s->execute([date('Y-m-d H:i:s',time()-30*86400)]);$counts=$s->fetchAll(PDO::FETCH_KEY_PAIR);$cost=$pdo->query('SELECT COALESCE(SUM(provider_cost),0) total,COUNT(provider_cost) recorded,COUNT(*) assets FROM media_items')->fetch(PDO::FETCH_ASSOC);$held=$pdo->query("SELECT COALESCE(SUM(amount),0) wallet_amount,COUNT(*) requests FROM creative_reservations WHERE status='held'")->fetch(PDO::FETCH_ASSOC);
            return $this->success(['items'=>$public,'next_cursor'=>$more?(int)end($rows)['id']:null,'summary'=>['last_30_days'=>$counts,'provider_cost'=>$cost,'holds'=>$held]]);
        }catch(Throwable $e){Logger::error('Creative admin listing failed',['message'=>$e->getMessage()]);return $this->error('تعذر قراءة متابعة الاستوديو.',503);}
    }
    public function content(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('Forbidden',403);
        try{$s=$this->db->getConnection()->prepare("SELECT * FROM media_items WHERE id=? AND deleted_at IS NULL AND status IN ('completed','failed')");$s->execute([(int)($params['id']??0)]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row||!$row['file_path'])throw new InvalidArgumentException('Unavailable');PrivateMedia::stream((string)$row['file_path'],(int)$row['id']);exit;}catch(Throwable $e){return $this->error('الملف غير متاح',404);}
    }
    public function action(array $params=[]): array
    {
        if(!$this->allowed())return $this->error('Forbidden',403);if($e=$this->rateLimitGuard('user','creative_admin',30,60))return $e;$id=(int)($params['id']??0);if($id<1||!GenerationLifecycle::lock($id))return $this->error('الطلب مشغول. حاول لاحقًا.',409);
        try{$result=$this->db->transaction(function()use($id){$item=(new MediaItem())->find($id);if(!$item||$item->getAttribute('deleted_at'))throw new InvalidArgumentException('Unavailable');$action=$this->get('action','');
            if($action==='cost'){$cost=$this->get('provider_cost');if(!is_numeric($cost)||!is_finite((float)$cost)||(float)$cost<0||(float)$cost>10000)throw new InvalidArgumentException('Invalid cost');$item->setAttribute('provider_cost',round((float)$cost,4));GenerationLifecycle::save($item);}
            elseif($action==='cancel'){
                if($item->getAttribute('status')!=='generating'||$item->getAttribute('generation_stage')!=='queued')throw new InvalidArgumentException('Only queued requests can be cancelled');GenerationLifecycle::fail($item,'Queued request cancelled by administrator');
            }elseif($action==='reconcile'){
                $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];
                if($item->getAttribute('status')!=='generating'||$item->getAttribute('file_path')||!in_array($item->getAttribute('generation_stage'),['starting','generating','processing'],true))throw new InvalidArgumentException('Not an interrupted request');
                $job=($meta['source']??'')==='video_editor'?RenderCreativeVideoJob::class:($item->getAttribute('type')==='short_video'?GenerateVideoJob::class:GenerateMediaJob::class);
                $queue=Container::getInstance()->make(QueueManager::class);$jobId=$queue->isReady()?$queue->push($job,['media_item_id'=>$id],'media'):false;if(!$jobId)throw new RuntimeException('Reconciliation queue unavailable');$item->setAttribute('job_id',(int)$jobId);GenerationLifecycle::save($item);
            }elseif($action==='recover'){
                if(!$item->getAttribute('file_path')||$item->getAttribute('usage_charged')||!in_array($item->getAttribute('status'),['failed','generating'],true))throw new InvalidArgumentException('Recovery requires an existing unsettled file');PrivateMedia::resolve((string)$item->getAttribute('file_path'));$meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];$billing=$meta['billing']??null;if(!is_array($billing)||!in_array($billing['source']??'',['plan','wallet'],true))throw new InvalidArgumentException('No approved price');CreativeReservations::reserve($item,$billing);$s=$this->db->getConnection()->prepare("SELECT COUNT(*) FROM media_items WHERE user_id=? AND status='generating'");$s->execute([(int)$item->getAttribute('user_id')]);if((int)$s->fetchColumn()>=2&&$item->getAttribute('status')!=='generating')throw new InvalidArgumentException('Active request limit');$item->setAttribute('status','generating');$item->setAttribute('generation_stage','billing');$item->setAttribute('error_message',null);GenerationLifecycle::save($item);$job=$item->getAttribute('type')==='short_video'?GenerateVideoJob::class:GenerateMediaJob::class;$queue=Container::getInstance()->make(QueueManager::class);$jobId=$queue->isReady()?$queue->push($job,['media_item_id'=>$id],'media'):false;if(!$jobId)throw new RuntimeException('Recovery queue unavailable');$item->setAttribute('job_id',(int)$jobId);GenerationLifecycle::save($item);
            }else throw new InvalidArgumentException('Invalid action');return ['id'=>$id,'action'=>$action];});
            try{if(class_exists('ActivityLog'))ActivityLog::record('admin','creative.'.$result['action'],['user_id'=>(int)$this->user['id'],'subject_type'=>'media_items','subject_id'=>$id]);}catch(Throwable $e){Logger::warning('Creative admin audit unavailable',['media_item_id'=>$id]);}
            return $this->success($result);
        }catch(InvalidArgumentException $e){return $this->error('لا يمكن تنفيذ الإجراء على هذه الحالة؛ راجع النتيجة والرصيد والقيمة.',422);}catch(Throwable $e){Logger::error('Creative admin action failed',['media_item_id'=>$id,'message'=>$e->getMessage()]);return $this->error('تعذر تنفيذ الإجراء الآن.',503);}finally{GenerationLifecycle::unlock($id);}
    }
}
