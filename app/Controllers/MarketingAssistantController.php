<?php

/**
 * Tourfecto - Marketing Assistant Controller
 * @version 1.0.0
 */
class MarketingAssistantController extends Controller
{
    /** @var MarketingAssistantService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new MarketingAssistantService();
    }

    /** GET /marketing-assistant */
    public function index(array $params = []): array
    {
        $tools = [
            'ad_copy' => ['نص إعلان', '📢', 'نص جاهز لإعلانات Google/Meta - عنوان جذاب ودعوة واضحة للعميل'],
            'slogan' => ['شعار تسويقي', '✨', 'جملة قصيرة ولافتة تلخّص هوية شركتك أو عرضك المميز'],
            'email_subject' => ['عنوان بريد إلكتروني', '✉️', 'عنوان يخلّي العميل يفتح إيميلاتك بدل ما يتجاهلها'],
            'social_bio' => ['نبذة سوشيال ميديا', '👤', 'وصف مختصر لحساباتك على انستجرام/فيسبوك يبان فيه نشاطك بوضوح'],
            'product_description' => ['وصف منتج/خدمة', '📦', 'وصف مقنع لباقة سياحية أو خدمة تقدّمها لموقعك أو منشوراتك'],
            'campaign_ideas' => ['أفكار حملة', '💡', 'أفكار تسويقية جاهزة لمناسبة أو موسم معيّن (زي رمضان أو الصيف)'],
        ];

        $toolCards = '';
        foreach ($tools as $key => [$label, $icon, $desc]) {
            $labelSafe = htmlspecialchars($label, ENT_QUOTES);
            $descSafe = htmlspecialchars($desc, ENT_QUOTES);
            $toolCards .= "<button class=\"p-card tool-card\" onclick=\"selectTool('{$key}','{$labelSafe}')\"><div class=\"stat-icon blue\">{$icon}</div><h3>{$labelSafe}</h3><p class=\"tool-card-desc\">{$descSafe}</p></button>";
        }

        $body = <<<HTML
        <p class="p-cell-muted" style="margin-bottom:16px;">دوس على أي أداة تحتها عشان تشوف طبيعتها، واكتب وصف بسيط لمنتجك أو مناسبتك عشان الذكاء الاصطناعي يولّد نص مناسب.</p>
        <div class="p-grid cols-4" style="margin-bottom:20px;">{$toolCards}</div>
        <div class="p-card" id="toolPanel" style="display:none;">
            <div class="p-card-head"><h3 id="toolTitle"></h3></div>
            <textarea id="toolInput" rows="3" style="width:100%;" class="p-select" placeholder="اكتب وصف موجز..."></textarea>
            <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                <button class="p-btn" id="toolRunBtn" onclick="runTool()">توليد</button>
                <button class="p-btn outline" onclick="copyOutput()">نسخ الناتج</button>
            </div>
            <div id="toolOutput" style="margin-top:16px;white-space:pre-wrap;"></div>
        </div>
        <div class="p-card no-pad" style="margin-top:20px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>السجل</h3></div>
            <div class="p-table-scroll"><table class="p-table" id="historyTable">
                <thead><tr><th>الأداة</th><th>الموضوع</th><th>التاريخ</th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="3">جارِ التحميل...</td></tr></tbody>
            </table></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const jsonHeaders = { 'Content-Type': 'application/json' };
    let currentTool = null;

    window.selectTool = function (key, label) {
        currentTool = key;
        document.getElementById('toolTitle').textContent = label;
        document.getElementById('toolPanel').style.display = 'block';
        document.getElementById('toolOutput').textContent = '';
        document.getElementById('toolInput').focus();
    };

    window.runTool = async function () {
        const input = document.getElementById('toolInput').value.trim();
        const out = document.getElementById('toolOutput');
        const btn = document.getElementById('toolRunBtn');
        if (!currentTool) { P.toast('اختار أداة أولًا من البطاقات بالأعلى', 'error'); return; }
        if (!input) { P.toast('اكتب وصف موجز قبل التوليد', 'error'); document.getElementById('toolInput').focus(); return; }
        btn.disabled = true;
        btn.textContent = 'جارِ التوليد...';
        out.textContent = 'جارِ التوليد...';
        try {
            const res = await fetchJSON('/api/marketing-assistant/run', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ type: currentTool, input, website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null }) });
            if (res.success) {
                out.textContent = res.data.output || '';
                P.toast('تم توليد المحتوى', 'success');
            } else {
                out.textContent = res.error || 'فشل التوليد';
                P.toast(res.error || 'فشل التوليد', 'error');
            }
            loadHistory();
        } finally {
            btn.disabled = false;
            btn.textContent = 'توليد';
        }
    };

    window.copyOutput = async function () {
        const txt = document.getElementById('toolOutput').textContent;
        if (!txt || txt.startsWith('جارِ')) { P.toast('لا يوجد محتوى للنسخ بعد', 'error'); return; }
        try {
            await navigator.clipboard.writeText(txt);
            P.toast('تم نسخ المحتوى', 'success');
        } catch (e) {
            P.toast('تعذر النسخ', 'error');
        }
    };

    async function loadHistory() {
        const res = await fetchJSON('/api/marketing-assistant/history');
        const tbody = document.querySelector('#historyTable tbody');
        if (res.success && res.data.history && res.data.history.length) {
            tbody.innerHTML = res.data.history.map(h => `<tr><td>${esc(h.type)}</td><td>${esc(h.title)}</td><td class="p-cell-muted">${esc(h.created_at)}</td></tr>`).join('');
        } else {
            tbody.innerHTML = '<tr><td colspan="3" class="p-cell-muted text-center">لا يوجد سجل بعد</td></tr>';
        }
    }
    loadHistory();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('marketing_assistant', 'مساعد التسويق الذكي', 'أدوات تسويقية سريعة مبنية على الذكاء الاصطناعي', $body, $script);
        exit;
    }

    /** POST /api/marketing-assistant/run */
    public function run(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['type' => 'required', 'input' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $uid=(int)$this->user['id'];$reservation=null;
        try {
            $reservation=ServiceUsageReservation::reserve($uid,'marketing_tool','marketing_tool');
            $interaction=$this->service->run($uid,(string)$this->get('type'),(string)$this->get('input'), (int)$this->get('website_id',0) ?: null);
            if(!ServiceUsageReservation::settle($reservation,'completed'))throw new RuntimeException('Usage settlement failed');
            return $this->success(['output'=>$interaction->getAttribute('output'),'operation_id'=>$reservation]);
        } catch(ProviderRequestFailure $e) {
            if($reservation)ServiceUsageReservation::settle($reservation,$e->uncertain?'uncertain':'released');
            return $this->error($e->getMessage(),502,['operation_id'=>$reservation]);
        } catch(InvalidArgumentException $e) {
            if($reservation)ServiceUsageReservation::settle($reservation,'released');
            return $this->error(CustomerError::message($e->getMessage(),400),422);
        } catch(Throwable $e) {
            if($reservation)ServiceUsageReservation::settle($reservation,'uncertain');
            Logger::error('Marketing service failed',['operation_id'=>$reservation]);
            return $this->error($reservation?'Unable to confirm this operation. Contact support with its ID.':'Unable to reserve service credit.',$reservation?503:402,['operation_id'=>$reservation]);
        }
    }

    /** GET /api/marketing-assistant/history */
    public function history(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new AIAssistantInteraction())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 30);
        return $this->success(['history' => array_map(fn ($i) => $i->toArray(), $items)]);
    }
}
