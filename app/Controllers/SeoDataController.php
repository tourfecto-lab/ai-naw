<?php
/** SEO data APIs, with ownership checks before data access and no secrets in responses. */
class SeoDataController extends Controller
{
    private function context(bool $admin=false): array
    {
        if (!$this->isAuthenticated()) throw new InvalidArgumentException('يجب تسجيل الدخول');
        $wid=(int)$this->get('website_id'); $uid=(int)$this->user['id'];
        if ($admin) {
            if (!in_array($this->user['role']??'',['admin','super_admin'],true) || !AdminAccess::can($this->user,'settings.manage')) throw new InvalidArgumentException('غير مسموح');
            $uid=(int)($this->db->query('SELECT user_id FROM websites WHERE id=?',[$wid])[0]['user_id']??0);
        }
        $service=new SeoMarketService($this->db); $service->site($wid,$uid);
        return [$wid,$uid,$service];
    }
    public function markets(array $params=[]): array { return $this->marketAction(false,false); }
    public function addMarket(array $params=[]): array { return $this->marketAction(true,false); }
    public function adminMarkets(array $params=[]): array { return $this->marketAction(false,true); }
    public function adminAddMarket(array $params=[]): array { return $this->marketAction(true,true); }
    private function marketAction(bool $write,bool $admin): array
    {
        try {
            [$wid,$uid,$service]=$this->context($admin);
            if ($write) {
                if ($this->get('action')==='primary') $service->primary($wid,$uid,(int)$this->get('market_id'));
                elseif ($this->get('action')==='replace_unmeasured') {
                    if ($error=$this->verifyCsrf()) return $error;
                    $service->replaceUnmeasured($wid,$uid,(int)$this->get('market_id'),['location_code'=>$this->get('location_code'),'language_code'=>$this->get('language_code'),'label'=>$this->get('label'),'reviewed'=>$this->get('reviewed',false)]);
                }
                else $service->add($wid,$uid,['location_code'=>$this->get('location_code'),'language_code'=>$this->get('language_code'),'label'=>$this->get('label'),'is_primary'=>$this->get('is_primary',false)]);
            }
            return $this->success(['markets'=>$service->markets($wid,$uid),'limit'=>$service->limit($uid)]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function discover(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            $seeds=$this->get('seeds',[]); if (!is_array($seeds)) throw new InvalidArgumentException('الكلمات لازم تكون قائمة');
            $r=(new DataForSeoResearchService($this->db))->research($wid,$uid,(int)$this->get('market_id',0),$seeds);
            if (empty($r['available'])) return $this->success($r,'not measured');
            $words=array_column($r['data'],'keyword');
            return $this->success($r+['discovered_keywords'=>$words,'new_discoveries'=>$words]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function enrich(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            return $this->success((new KeywordResearchService())->enrichTrackedKeywords($this->db,$wid,$uid));
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function keywords(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            $r=(new RankTrackingService())->trackingOverview($this->db,$wid,$uid,(int)$this->get('market_id',0));
            foreach ($r['keywords'] as &$row) {
                $phrase=DataForSeoRankingSource::keywordPhrase($row['keyword']);
                $row['suggested_phrase']=$phrase!==trim($row['keyword'])?$phrase:null;
            } unset($row);
            $r['gaps']=!empty($r['status']['available'])?SeoKeywordGapService::rows($this->db,$wid,$uid):[];
            return $this->success($r);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function measurementQuote(array $params=[]): array
    {
        try {[$wid,$uid]=$this->context();return $this->success((new SeoKeywordMeasurementService($this->db))->quote($uid,$wid,(int)$this->get('market_id',0),(string)$this->get('operation','demand')));}
        catch (InvalidArgumentException $e) {return $this->error($e->getMessage(),422);}
    }
    public function measureKeywords(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        if ($error=$this->verifyCsrf()) return $error;
        if ($error=$this->rateLimitGuard('user','seo_keyword_measure',5,60)) return $error;
        try {[$wid,$uid]=$this->context();return $this->success((new SeoKeywordMeasurementService($this->db))->measure($uid,$wid,(int)$this->get('market_id',0),$this->data));}
        catch (InvalidArgumentException $e) {return $this->error($e->getMessage(),422);}
    }
    public function deleteKeyword(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        $s=$this->db->getConnection()->prepare('DELETE FROM tracked_keywords WHERE id=? AND user_id=?');
        $s->execute([(int)($params['id']??0),(int)$this->user['id']]);
        return $s->rowCount()?$this->success([]):$this->error('الكلمة مش موجودة',404);
    }
    public function addKeyword(array $params=[]): array { return $this->saveKeywords(false); }
    public function bulkAddKeywords(array $params=[]): array { return $this->saveKeywords(true); }
    /** Explicit review repairs only unmeasured legacy input; measured history is never renamed. */
    public function repairKeyword(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        if ($error=$this->verifyCsrf()) return $error;
        try {
            [$wid,$uid,$markets]=$this->context();
            $market=$markets->resolve($wid,$uid,(int)$this->get('market_id',0));
            if ($this->get('reviewed',false)!==true) throw new InvalidArgumentException('راجع عبارة البحث وأكد تنقيتها أولًا');
            $result=$this->db->transaction(function () use ($wid,$uid,$market,$params) {
                $read=function (string $sql,array $values): array {
                    // Database::query intentionally maps failed SELECTs to []. A history
                    // safety check must distinguish that failure from an empty result.
                    try {
                        $statement=$this->db->getConnection()->prepare($sql);
                        if (!$statement->execute($values)) throw new RuntimeException('Read failed');
                        return $statement->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        throw new InvalidArgumentException('تعذر مراجعة السجل؛ لم يتم تعديل الكلمة. حاول لاحقًا');
                    }
                };
                if (!$read('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]))
                    throw new InvalidArgumentException('الموقع غير متاح في حسابك');
                $row=$read('SELECT * FROM tracked_keywords WHERE id=? AND website_id=? AND user_id=? AND market_id=? FOR UPDATE',[(int)($params['id']??0),$wid,$uid,$market['id']])[0]??null;
                if (!$row) throw new InvalidArgumentException('الكلمة غير موجودة في الموقع والسوق المختارين');
                if ($this->get('expected_keyword')!==$row['keyword']) throw new InvalidArgumentException('تغيرت الكلمة؛ حدّث القائمة وراجعها مجددًا');
                $phrase=DataForSeoRankingSource::keywordPhrase($row['keyword']);
                if ($phrase==='' || $phrase===trim($row['keyword'])) throw new InvalidArgumentException('لا توجد تعليقات تحليل معروفة لتنقيتها');
                foreach (['last_checked_at','enriched_at','gsc_checked_at','current_position','search_volume','difficulty','impressions','gsc_position'] as $field)
                    if (isset($row[$field])) throw new InvalidArgumentException('لهذه الكلمة قياسات محفوظة؛ احتفظ بسجلها وأضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM seo_rank_tracking_history WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$uid,$market['id'],$row['keyword']]))
                    throw new InvalidArgumentException('لهذه الكلمة سجل ترتيب محفوظ؛ أضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM seo_keyword_research WHERE website_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$market['id'],$row['keyword']]))
                    throw new InvalidArgumentException('لهذه الكلمة سجل بحث محفوظ؛ أضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$uid,$market['id'],$phrase]))
                    throw new InvalidArgumentException('العبارة الصحيحة موجودة بالفعل؛ احتفظ بالسجل القديم دون دمجه');
                if (!$this->db->exec('UPDATE tracked_keywords SET keyword=? WHERE id=? AND website_id=? AND user_id=? AND market_id=?',[$phrase,$row['id'],$wid,$uid,$market['id']]))
                    throw new InvalidArgumentException('تعذر حفظ العبارة؛ لم يتم تعديل الكلمة. حاول لاحقًا');
                return ['id'=>(int)$row['id'],'previous_keyword'=>$row['keyword'],'keyword'=>$phrase];
            });
            return $this->success($result);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    private function saveKeywords(bool $bulk): array
    {
        try {
            [$wid,$uid,$service]=$this->context();
            $market=$service->resolve($wid,$uid,(int)$this->get('market_id',0));
            $words=$bulk?$this->get('keywords',[]):[$this->get('keyword','')];
            if (!is_array($words) || count($words)>100) throw new InvalidArgumentException('ضيف لحد 100 كلمة في المرة');
            $added=0;
            foreach ($words as $raw) {
                if (!is_string($raw) || trim($raw)==='' || mb_strlen(trim($raw))>255) throw new InvalidArgumentException('كلمة غير صالحة');
                if (DataForSeoRankingSource::keywordPhrase($raw)!==trim($raw)) throw new InvalidArgumentException('أضف عبارة البحث فقط، بدون نية البحث أو الأولوية بين الأقواس');
            }
            foreach (array_unique($words) as $raw) {
                $s=$this->db->getConnection()->prepare('INSERT IGNORE INTO tracked_keywords (website_id,user_id,market_id,keyword) VALUES (?,?,?,?)');
                $s->execute([$wid,$uid,$market['id'],trim($raw)]); $added+=$s->rowCount();
            }
            return $this->success(['added'=>$added]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function gapRefresh(array $params=[]): array
    {
        try { [$wid,$uid]=$this->context(); return $this->success(SeoKeywordGapService::refresh($this->db,$wid,$uid)); }
        catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function adminSites(array $params=[]): array
    {
        if (!$this->isAuthenticated() || !AdminAccess::can($this->user,'settings.manage')) return $this->error('غير مسموح',403);
        return $this->success(['websites'=>$this->db->query('SELECT id,main_url,company_name FROM websites ORDER BY id DESC LIMIT 500')]);
    }
    public function index(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        $admin=str_starts_with((string)($_SERVER['REQUEST_URI']??''),'/admin/');
        $body=<<<'HTML'
<style>.seo-kw-add{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin:14px 0}.seo-kw-add label{flex:1 1 260px;display:grid;gap:4px}.seo-kw-actions{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 22px}.seo-kw-num{font-variant-numeric:tabular-nums;white-space:nowrap}.seo-kw-muted{color:var(--panel-text-muted,#6b7280)}.seo-kw-up{color:var(--panel-success,#16a34a);font-weight:700}.seo-kw-down{color:var(--panel-danger,#dc2626);font-weight:700}.seo-kw-diff{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:var(--panel-card-bg-2,#f3f4f6)}</style>
<div class="p-card" dir="rtl">
<p>أرقام البحث دي من بيانات جوجل الفعلية لكل بلد ولغة. علامة — معناها إن الرقم لسه ما اتقاسش. بيانات البحث بتتحدث كل 30 يوم والترتيب بيتقاس مرة في اليوم، والقياس بيتحسب من باقتك الأول وبعدها من رصيدك.</p>
<label>الموقع <select id="seoSite" class="form-control"></select></label>
<label>السوق <select id="seoMarket" class="form-control"></select></label>
<p id="seoStatus" role="status"></p>
<p id="seoInputReview" role="status" hidden>بعض الكلمات فيها تعليقات تحليل وليست عبارة بحث صافية. راجع العبارة المقترحة ثم نقِّ الكلمات غير المقاسة. التنقية مجانية ولا تبدأ بحثًا أو قياس ترتيب، والسجلات المقاسة لا تُعدَّل.</p>
<details><summary>إضافة سوق / تغيير السوق الرئيسي</summary>
<form id="marketForm" novalidate><label>بلد الجمهور <select id="seoMarketCountry"><option value="">اختَر البلد</option><option value="2840" data-lang="en">United States</option><option value="2826" data-lang="en">United Kingdom</option><option value="2124" data-lang="en">Canada</option><option value="2036" data-lang="en">Australia</option><option value="2372" data-lang="en">Ireland</option><option value="2554" data-lang="en">New Zealand</option><option value="2356" data-lang="en">India</option><option value="2710" data-lang="en">South Africa</option><option value="2702" data-lang="en">Singapore</option><option value="2276" data-lang="de">Germany</option><option value="2040" data-lang="de">Austria</option><option value="2756" data-lang="de">Switzerland</option><option value="2250" data-lang="fr">France</option><option value="2056" data-lang="fr">Belgium</option><option value="2528" data-lang="nl">Netherlands</option><option value="2442" data-lang="fr">Luxembourg</option><option value="2724" data-lang="es">Spain</option><option value="2620" data-lang="pt">Portugal</option><option value="2380" data-lang="it">Italy</option><option value="2300" data-lang="el">Greece</option><option value="2616" data-lang="pl">Poland</option><option value="2203" data-lang="cs">Czechia</option><option value="2348" data-lang="hu">Hungary</option><option value="2642" data-lang="ro">Romania</option><option value="2752" data-lang="sv">Sweden</option><option value="2578" data-lang="no">Norway</option><option value="2208" data-lang="da">Denmark</option><option value="2246" data-lang="fi">Finland</option><option value="2643" data-lang="ru">Russia</option><option value="2804" data-lang="uk">Ukraine</option><option value="2792" data-lang="tr">Turkey</option><option value="2376" data-lang="he">Israel</option><option value="2682" data-lang="ar">Saudi Arabia</option><option value="2784" data-lang="ar">United Arab Emirates</option><option value="2414" data-lang="ar">Kuwait</option><option value="2634" data-lang="ar">Qatar</option><option value="2048" data-lang="ar">Bahrain</option><option value="2512" data-lang="ar">Oman</option><option value="2400" data-lang="ar">Jordan</option><option value="2818" data-lang="ar">Egypt</option><option value="2504" data-lang="fr">Morocco</option><option value="2344" data-lang="zh-TW">Hong Kong</option><option value="2158" data-lang="zh-TW">Taiwan</option><option value="2392" data-lang="ja">Japan</option><option value="2410" data-lang="ko">South Korea</option><option value="2458" data-lang="ms">Malaysia</option><option value="2360" data-lang="id">Indonesia</option><option value="2764" data-lang="th">Thailand</option><option value="2704" data-lang="vi">Vietnam</option><option value="2608" data-lang="en">Philippines</option><option value="2076" data-lang="pt">Brazil</option><option value="2484" data-lang="es">Mexico</option><option value="2032" data-lang="es">Argentina</option><option value="2170" data-lang="es">Colombia</option><option value="2152" data-lang="es">Chile</option></select></label><label>لغة العملاء <select id="seoMarketLanguage"><option value="en">English</option><option value="ar">العربية</option><option value="de">Deutsch</option><option value="fr">Français</option><option value="es">Español</option><option value="it">Italiano</option><option value="pt">Português</option><option value="nl">Nederlands</option><option value="ru">Русский</option><option value="zh-CN">中文 (简体)</option><option value="zh-TW">中文 (繁體)</option><option value="ja">日本語</option><option value="ko">한국어</option><option value="tr">Türkçe</option><option value="pl">Polski</option><option value="sv">Svenska</option><option value="da">Dansk</option><option value="no">Norsk</option><option value="fi">Suomi</option><option value="cs">Čeština</option><option value="hu">Magyar</option><option value="ro">Română</option><option value="el">Ελληνικά</option><option value="uk">Українська</option><option value="he">עברית</option><option value="hi">हिन्दी</option><option value="id">Bahasa Indonesia</option><option value="ms">Bahasa Melayu</option><option value="th">ไทย</option><option value="vi">Tiếng Việt</option></select></label><details><summary>إعداد سوق آخر أو موقع جغرافي مخصص — متقدم</summary><label>كود موقع DataForSEO <input id="seoLocation" type="number" min="1" required placeholder="2826 = UK"></label>
<label>اللغة <input id="seoLanguage" required maxlength="12" placeholder="en"></label>
<label>اسم السوق <input id="seoLabel" required maxlength="100" placeholder="UK – English"></label>
</details><button class="p-btn" type="submit">إضافة السوق المختار</button><button id="seoReplaceMarket" class="p-btn" type="button">تصحيح السوق المختار إذا لم يبدأ قياسه</button><p>بلد الجمهور يختلف عن بلد نشاطك. لو باقتك تسمح بسوق واحد، يمكن تصحيح السوق الافتراضي قبل وجود قياسات أو إعدادات مرتبطة به؛ السجلات المقاسة لا نعيد تسميتها.</p></form>
<button id="seoPrimary" class="p-btn">خلّي السوق المختار رئيسي</button><p id="seoMarketLimit"></p></details>
<div id="seoCustomer">
<div class="seo-kw-add"><label>كلمة تتابعها <input id="seoWord" class="form-control" maxlength="255" placeholder="luxor day tours"></label><button id="seoAdd" class="p-btn">إضافة للمتابعة</button></div>
<section class="p-card"><h3>قياس الكلمات المحفوظة بميزانية واضحة</h3><p>نبدأ بما يصل إلى خمس كلمات تحتاج بيانات حديثة في السوق المختار. إذا لم تضف كلمات بعد، نبحث موقعك لدى المزود لاستخراج أول خمس كلمات حقيقية، دون اختلاق بيانات. عرض الاستخدام مجاني؛ تأكيد القياس يستهلك حصة SEO ثم المحفظة حتى السقف الذي تحدده.</p><button id="seoMeasureQuote" class="p-btn" type="button">عرض الكلمات والاستخدام قبل القياس</button><p id="seoMeasureStatus" role="status"></p><form id="seoMeasureForm" hidden><ul id="seoMeasureWords"></ul><p id="seoMeasureCost"></p><label>أقصى خصم للمحفظة بالدولار — صفر للباقة فقط<input id="seoMeasureCap" type="number" min="0" max="100" step="0.01" required></label><label><input id="seoMeasureConsent" type="checkbox">راجعت الكلمات والسوق والاستخدام وحد الخصم وأوافق على القياس</label><button class="p-btn" type="submit">بدء القياس الموافق عليه</button></form></section>
<div class="seo-kw-actions"><button id="seoResearch" class="p-btn primary">لاقي كلمات لموقعك</button>
<button id="seoCheck" class="p-btn outline">قيس ترتيبك</button>
<button id="seoGap" class="p-btn outline">قارن بالمنافسين (حتى 50 كلمة)</button></div>
<h3>حجم البحث الشهري والترتيب حسب السوق</h3>
<div class="p-table-scroll"><table class="p-table"><thead><tr><th>الكلمة</th><th>حجم البحث</th><th>الصعوبة</th><th>تكلفة النقرة</th><th>الباحث عايز إيه</th><th>الترتيب</th><th>اتجاه 30 يوم</th><th>آخر قياس</th></tr></thead><tbody id="seoWords"></tbody></table></div>
<h3>كلمات لقيناها لموقعك</h3><div id="seoResearchRows"></div>
<h3>فجوة الكلمات مع المنافسين</h3><p>بنقارن السوق الرئيسي بس. الفجوة محتاجة ترتيب منافس من 1 لـ10، وحجم بحث حقيقي، وقياس حديث لموقعك خارج أول 20.</p>
<div class="p-table-scroll"><table class="p-table"><thead><tr><th>الكلمة</th><th>المنافس</th><th>حجم البحث</th><th>ترتيب المنافس</th><th>ترتيبك</th><th>تاريخ القياس</th></tr></thead><tbody id="seoGaps"></tbody></table></div>
</div></div>
HTML;
        $script=($admin?'const seoAdmin=true;':'const seoAdmin=false;').<<<'JS'
(async function(){
const {fetchJSON,esc,toast}=window.Panel;
const el=id=>document.getElementById(id);
const base=seoAdmin?'/api/admin/seo-data':'/api/seo/data';
let wid=0,mid=0,marketRequest=0,wordRequest=0,measurementQuote=null;
function clearMeasure(){measurementQuote=null;el('seoMeasureForm').hidden=true;el('seoMeasureConsent').checked=false;el('seoMeasureStatus').textContent='';}
const measureReason=reason=>({'seo_wallet_limit_reached':'وصلنا لحد المحفظة الموافق عليه','seo_auto_wallet_limit_reached':'حد بيانات SEO الدورية لا يسمح بخصم إضافي','seo_monthly_budget_reached':'ميزانية مزود البحث تحتاج مراجعة الدعم','seo_quota_and_wallet_exhausted':'حصة SEO والرصيد المتاح لا يكفيان','seo_wallet_price_unavailable':'تسعير وحدة SEO يحتاج مراجعة الدعم','not_measured':'مزود البحث غير متاح','rank_check_busy':'فحص ترتيب للموقع يعمل بالفعل','research_busy':'بحث لهذه العينة يعمل بالفعل'})[reason]||'تعذر استكمال رد المزود؛ راجع البيانات المحفوظة قبل تكرار الطلب';
const metric=v=>v===null||v===undefined?'—':esc(String(v));
// تنسيق الأرقام للعميل بدل القيم الخام (5.7600 و2026-10-19 10:07:30)
const none='<span class="seo-kw-muted">—</span>',num=v=>v===null||v===undefined||v===''?none:`<span class="seo-kw-num">${Number(v).toLocaleString('en-US')}</span>`;
const money=v=>v===null||v===undefined||v===''?none:`<span class="seo-kw-num">$${Number(v).toFixed(2)}</span>`;
const level=v=>{if(v===null||v===undefined||v==='')return none;const n=Math.round(Number(v));return `<span class="seo-kw-diff">${n} · ${n<30?'سهلة':n<60?'متوسطة':'صعبة'}</span>`;};
const intent=v=>v?esc(({commercial:'مقارنة قبل الحجز',transactional:'جاهز يحجز',informational:'بيدوّر على معلومة',navigational:'بيدوّر على موقع معيّن'})[v]||v):none;
const when=v=>{if(!v)return none;const d=new Date(String(v).replace(' ','T')+'Z');return isNaN(d)?esc(v):`<span class="seo-kw-num">${d.toLocaleDateString(document.documentElement.lang||'ar',{day:'numeric',month:'short',year:'numeric'})}</span>`;};
const trend=v=>v===null||v===undefined||Number(v)===0?none:Number(v)>0?`<span class="seo-kw-up">▲ ${Math.abs(Number(v))}</span>`:`<span class="seo-kw-down">▼ ${Math.abs(Number(v))}</span>`;
async function post(url,data={}){return fetchJSON(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({website_id:wid,market_id:mid,...data})});}
function fail(r){if(!r.success){toast(r.error||'تعذر تنفيذ الطلب','error');return true;}return false;}
async function markets(){
 const siteId=wid,request=++marketRequest;mid=0;++wordRequest;clearMeasure();el('seoWords').replaceChildren();el('seoGaps').replaceChildren();el('seoInputReview').hidden=true;
 const r=await fetchJSON(base+'/markets?website_id='+siteId);if(siteId!==wid||request!==marketRequest)return;if(fail(r))return;
 el('seoMarket').innerHTML=r.data.markets.map(m=>`<option value="${Number(m.id)}">${esc(m.label)}${Number(m.is_primary)?' ★':''}</option>`).join('');mid=Number(el('seoMarket').value);
 el('seoMarketLimit').textContent='الحد المتاح: '+r.data.limit+' سوق. السوق الافتراضي من لغة الموقع؛ راجعه حسب بلد الجمهور.';
 if(!seoAdmin)await words();
}
async function words(){
 const siteId=wid,marketId=mid,request=++wordRequest;
 const r=await fetchJSON('/api/seo/data/keywords?website_id='+siteId+'&market_id='+marketId);if(siteId!==wid||marketId!==mid||request!==wordRequest)return;if(fail(r))return;
 el('seoStatus').textContent=r.data.status.message||'المصدر مهيأ. الأرقام الناقصة مش مقاسة؛ حفظ الكلمات وحده لا يعني قياسها.';
 el('seoInputReview').hidden=!r.data.keywords.some(k=>k.suggested_phrase);
 el('seoWords').innerHTML=r.data.keywords.map(k=>`<tr><td>${esc(k.keyword)}${k.suggested_phrase?`<p>عبارة البحث المقترحة: <strong>${esc(k.suggested_phrase)}</strong></p><button class="p-btn" data-repair-keyword="${Number(k.id)}">راجعت العبارة — تنقية بدون قياس</button>`:''}</td><td>${num(k.search_volume)}</td><td>${level(k.difficulty)}</td><td>${money(k.cpc)}</td><td>${intent(k.intent)}</td><td>${num(k.current_position)}</td><td>${trend(k.trend)}</td><td>${when(k.last_checked_at)}</td></tr>`).join('')||'<tr><td colspan="8">لسه مفيش كلمات بتتابعها. ضيف كلمة فوق أو استخدم زرار لاقي كلمات لموقعك.</td></tr>';
 for(const button of el('seoWords').querySelectorAll('[data-repair-keyword]'))button.onclick=async()=>{
  const keyword=r.data.keywords.find(k=>Number(k.id)===Number(button.dataset.repairKeyword));
  if(!keyword||!confirm('تنقية عبارة البحث من:\n'+keyword.keyword+'\nإلى:\n'+keyword.suggested_phrase+'\nلن يبدأ بحث أو خصم رصيد.'))return;
  button.disabled=true;try{const result=await post('/api/seo/data/keywords/'+Number(keyword.id)+'/repair',{expected_keyword:keyword.keyword,reviewed:true});if(!fail(result))await words();}finally{if(button.isConnected)button.disabled=false;}
 };
 el('seoGaps').innerHTML=r.data.gaps.filter(g=>Number(g.market_id)===mid).map(g=>`<tr><td>${esc(g.keyword)}</td><td>${esc(g.competitor_domain)}</td><td>${num(g.search_volume)}</td><td>${num(g.position)}</td><td>${g.customer_position===null?'خارج أول 30':num(g.customer_position)}</td><td>${when(g.fetched_at)}</td></tr>`).join('')||'<tr><td colspan="6">لسه مفيش كلمات المنافسين بيظهروا فيها وأنت لأ. استخدم زرار المقارنة بالمنافسين عشان نقيسها.</td></tr>';
}
const marketInput=()=>({location_code:Number(el('seoLocation').value),language_code:el('seoLanguage').value.trim(),label:el('seoLabel').value.trim()});
const marketValid=()=>{const data=marketInput();if(!data.location_code||!data.language_code||!data.label){toast('اختَر بلد الجمهور ولغته أو أكمل إعداد السوق المتقدم','error');return false;}return true;};
el('marketForm').onsubmit=async e=>{e.preventDefault();if(!marketValid())return;const r=await post(base+'/markets',marketInput());if(!fail(r))await markets();};
el('seoReplaceMarket').onclick=async()=>{if(!wid||!mid||!marketValid()||!confirm('تغيير السوق المختار إلى '+el('seoLabel').value+'؟ لن تتغير أي قياسات قديمة، وسنرفض التغيير إذا وُجد سجل مرتبط.'))return;const r=await post(base+'/markets',{...marketInput(),action:'replace_unmeasured',reviewed:true});if(!fail(r))await markets();};
function marketPreset(e){const option=el('seoMarketCountry').selectedOptions[0];if(!option?.value)return;if(e?.target?.id==='seoMarketCountry'&&option.dataset.lang)el('seoMarketLanguage').value=option.dataset.lang;el('seoLocation').value=option.value;el('seoLanguage').value=el('seoMarketLanguage').value;el('seoLabel').value=option.textContent+' – '+el('seoMarketLanguage').selectedOptions[0].textContent;}
el('seoMarketCountry').onchange=marketPreset;el('seoMarketLanguage').onchange=marketPreset;
el('seoPrimary').onclick=async()=>{const r=await post(base+'/markets',{action:'primary'});if(!fail(r))await markets();};
el('seoMarket').onchange=async()=>{mid=Number(el('seoMarket').value);clearMeasure();el('seoResearchRows').replaceChildren();if(!seoAdmin)await words();};
el('seoSite').onchange=async()=>{wid=Number(el('seoSite').value);el('seoResearchRows').replaceChildren();await markets();};
el('seoAdd').onclick=async()=>{const r=await post('/api/ai/keywords/add',{keyword:el('seoWord').value.trim()});if(!fail(r))await words();};
const showMeasurementQuote=async(operation,button)=>{if(!wid||!mid)return;const siteId=wid,marketId=mid;button.disabled=true;clearMeasure();try{const r=await fetchJSON('/api/seo/data/measurement-quote?website_id='+siteId+'&market_id='+marketId+'&operation='+operation);if(siteId!==wid||marketId!==mid)return;if(fail(r))return;const q=r.data;if(q.already_measured){el('seoMeasureStatus').textContent='الكلمات المحفوظة لها بيانات حديثة، أو لا توجد كلمات تحتاج قياسًا. الأرقام غير المتاحة تظل غير مقاسة.';return;}if(!q.available){el('seoMeasureStatus').textContent=q.provider_configured&&q.provider_budget_available===false?'بيانات البحث متوقفة من جهة المنصة؛ الدعم يحتاج مراجعة ميزانية المزود. لم يبدأ قياس ولم نستهلك الحصة أو المحفظة من عرض الاستخدام.':q.wallet_sufficient===false?'الحد الأقصى المتوقع أعلى من الرصيد المتاح بعد الحصة؛ راجع الباقة أو المحفظة قبل القياس.':'القياس غير متاح حاليًا؛ راجع تنقية الكلمات وربط المزود وتسعيره مع الدعم.';return;}measurementQuote=q;el('seoMeasureWords').replaceChildren();if(q.operation==='discover_site'){const li=document.createElement('li');li.textContent='بحث '+q.target_host+' لاستخراج حتى خمس كلمات من بيانات المزود. لا يبدأ هذا العرض المجاني أي طلب مدفوع.';el('seoMeasureWords').append(li);}if(q.operation==='gap'){const li=document.createElement('li');li.textContent='مقارنة حتى خمسة منافسين وأول خمسين كلمة مرشحة في السوق الرئيسي. لا نفترض أن كلمة غير مفحوصة فرصة متحققة.';el('seoMeasureWords').append(li);for(const c of q.competitors||[]){const item=document.createElement('li');item.textContent=c.competitor_domain;el('seoMeasureWords').append(item);}}for(const k of q.keywords){const li=document.createElement('li');li.textContent=k.keyword;el('seoMeasureWords').append(li);}el('seoMeasureCost').textContent='السوق: '+q.market_label+' · حتى '+q.max_units+' وحدات SEO · سقف الخصم المتوقع: '+Number(q.wallet_max).toFixed(2)+' USD. الحصة الباقية: '+(q.plan_remaining===null?'غير محدودة':q.plan_remaining)+'. القياس لا يعني نشر محتوى أو ضمان ترتيب.';el('seoMeasureCap').value=Number(q.wallet_max).toFixed(2);el('seoMeasureForm').hidden=false;}catch(e){el('seoMeasureStatus').textContent='تعذر تحميل عرض الاستخدام؛ لم يبدأ قياس من هذا الزر.';}finally{button.disabled=false;}};
el('seoMeasureQuote').onclick=function(){return showMeasurementQuote('demand',this);};
el('seoMeasureForm').onsubmit=async e=>{e.preventDefault();const q=measurementQuote;if(!q||q.website_id!==wid||q.market_id!==mid||!el('seoMeasureConsent').checked){toast('راجع عرض الاستخدام ووافق عليه أولًا','error');return;}const button=e.currentTarget.querySelector('button');button.disabled=true;try{const r=await post('/api/seo/data/measure',{quote_hash:q.quote_hash,operation:q.operation,max_wallet_charge:Number(el('seoMeasureCap').value),reviewed:true});if(q.website_id!==wid||q.market_id!==mid)return;if(fail(r))return;measurementQuote=null;el('seoMeasureForm').hidden=true;el('seoMeasureStatus').textContent=r.data.available?((r.data.data||[]).length?'حُفظت البيانات التي أرجعها المزود.':'لم يرجع المزود بيانات لهذه العينة. راجع السوق أو أضف عبارة بحث من خدماتك المؤكدة؛ لم نختلق كلمات أو أحجام بحث.')+' خصم المحفظة لهذه العملية: '+Number(r.data.wallet_charged||0).toFixed(2)+' USD. راجع الجدول؛ القيم الناقصة لم تُختلق.':'لم يكتمل القياس: '+measureReason(r.data.reason)+'. خصم المحفظة المسجل: '+Number(r.data.wallet_charged||0).toFixed(2)+' USD. لا نكرر الطلب المدفوع تلقائيًا.';await words();}catch(e){measurementQuote=null;el('seoMeasureForm').hidden=true;el('seoMeasureStatus').textContent='تعذر تأكيد نتيجة الطلب. حدّث الحالة قبل إعادة القياس؛ لن نكرره تلقائيًا.';}finally{button.disabled=false;el('seoMeasureConsent').checked=false;}};
el('seoResearch').onclick=function(){return showMeasurementQuote('discovery',this);};
el('seoCheck').onclick=function(){return showMeasurementQuote('rank',this);};
el('seoGap').onclick=function(){return showMeasurementQuote('gap',this);};
if(seoAdmin)el('seoCustomer').hidden=true;
const r=await fetchJSON(seoAdmin?base+'/sites':'/api/websites');if(fail(r))return;
const sites=r.data.websites||[],requested=new URLSearchParams(location.search).get('website_id');
el('seoSite').innerHTML=sites.map(w=>`<option value="${Number(w.id)}">${esc(w.company_name||w.main_url||String(w.id))}</option>`).join('');
if(requested!==null){if(!/^[1-9]\d*$/.test(requested)||!sites.some(w=>Number(w.id)===Number(requested))){el('seoSite').selectedIndex=-1;el('seoStatus').textContent='الموقع المطلوب غير متاح في حسابك. اختر موقعًا من القائمة للمتابعة.';return;}el('seoSite').value=String(Number(requested));}
wid=Number(el('seoSite').value);if(wid)await markets();
})();
JS;
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_analyze','الكلمات والأسواق','بيانات بحث وترتيب حقيقية لكل سوق',$body,$script);
        exit;
    }
}
