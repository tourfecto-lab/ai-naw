<?php

/**
 * Tourfecto - Email Marketing Controller
 * @version 1.0.0
 *
 * موديول تسويق البريد الاحترافي — منافس لـ Brevo/Mailchimp مبني بالكامل على
 * بنية Tourfecto (إرسال SMTP + تتبع فتح/كليك/إلغاء على جداول خاصة) من غير
 * أي اعتماد على خدمة خارجية. يضم:
 *   - لوحة تحكم (KPIs + أحدث الحملات + حالة بوابة الإرسال)
 *   - إدارة الجمهور (قوائم + مشتركين + استيراد + إلغاء اشتراك)
 *   - القوالب (متغيرات تخصيص + معاينة)
 *   - الحملات (إنشاء/جدولة/إرسال فوري/إلغاء/تقرير تفاعلي)
 *   - التتبع العام (بكسل فتح + كليك + إلغاء اشتراك) - من غير Auth
 *
 * العزل: كل استعلام مربوط بـ $this->uid() (user_id) - نفس نمط باقي
 * الموديولات (Tenant Isolation).
 */
class EmailMarketingController extends Controller
{
    /** @var EmailListService */
    private $listService;

    /** @var EmailCampaignService */
    private $campaignService;

    /** @var EmailTrackingService */
    private $trackingService;

    public function __construct()
    {
        parent::__construct();
        $this->listService = new EmailListService();
        $this->campaignService = new EmailCampaignService();
        $this->trackingService = new EmailTrackingService();
    }

    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    // ============================================================
    //  Pages (web routes)
    // ============================================================

    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $tabs = $this->emailTabsHtml('dashboard');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📬 Professional email marketing</h3>
                <span class="p-card-sub">An email marketing platform built into Tourfecto - campaigns with open/click tracking and unsubscribe, from your data and on your infrastructure</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;" id="emKpis">
                <div class="p-loading-row">Loading...</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;" id="emQuickWrap">
            <div class="p-card">
                <div class="p-card-head"><h3>🚀 Quick action</h3></div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="p-btn primary" onclick="window.location.href='/email-marketing/campaigns?new=1'">+ New campaign</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/lists'">Manage lists</button>
                    <button class="p-btn" onclick="window.location.href='/email-marketing/templates'">Templates</button>
                </div>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>📡 Sending gateway</h3><span class="p-card-sub">via the mail server (SMTP)</span></div>
                <div id="emDeliveryStatus"><div class="p-loading-row">Checking...</div></div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📨 Latest campaigns</h3></div>
            <div id="emRecentCampaigns"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>👥 Top lists</h3></div>
            <div id="emTopLists"><div class="p-loading-row">Loading...</div></div>
        </div>
        HTML;

        $script = $this->indexJs();
        echo $this->renderPanelPage('email_marketing', 'Email marketing', 'Professional email campaigns with full tracking', $body, $script);
        return [];
    }

    public function showListsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('lists');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>👥 Audience lists</h3>
                <button class="p-btn primary xs" onclick="openListModal()">+ New list</button>
            </div>
            <div id="emListsTable"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head">
                <h3>📇 Subscribers <span id="emSubCountLabel"></span></h3>
                <div style="display:flex;gap:8px;">
                    <select id="emSubListFilter" class="p-select" onchange="loadSubscribers()">
                        <option value="0">All lists</option>
                    </select>
                    <button class="p-btn primary xs" onclick="openSubscriberModal()">+ Add subscriber</button>
                    <button class="p-btn xs" onclick="openImportModal()">📥 Import</button>
                </div>
            </div>
            <div id="emSubscribersTable"><div class="p-loading-row">Loading...</div></div>
            <div id="emSubscribersPager" style="margin-top:10px;"></div>
        </div>

        <!-- List modal -->
        <div class="p-modal-overlay" id="listModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="listModalTitle">New list</h3>
                    <button class="p-modal-close" onclick="document.getElementById('listModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="listId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">List name *</label>
                    <input type="text" id="listName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Example: summer offers customers">
                    <label class="p-cell-muted" style="font-size:12px;">Description (optional)</label>
                    <input type="text" id="listDesc" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="Short description">
                    <button class="p-btn primary" onclick="saveList()">Save</button>
                </div>
            </div>
        </div>

        <!-- Subscriber modal -->
        <div class="p-modal-overlay" id="subscriberModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>Add subscriber</h3>
                    <button class="p-modal-close" onclick="document.getElementById('subscriberModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">Email *</label>
                    <input type="email" id="subEmail" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="client@example.com">
                    <label class="p-cell-muted" style="font-size:12px;">Name</label>
                    <input type="text" id="subName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Full name">
                    <label class="p-cell-muted" style="font-size:12px;">List</label>
                    <select id="subList" class="p-select" style="width:100%;margin-bottom:10px;"></select>
                    <button class="p-btn primary" onclick="saveSubscriber()">Save</button>
                </div>
            </div>
        </div>

        <!-- Import modal -->
        <div class="p-modal-overlay" id="importModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>📥 Import subscribers</h3>
                    <button class="p-modal-close" onclick="document.getElementById('importModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">Target list</label>
                    <select id="importList" class="p-select" style="width:100%;margin-bottom:8px;"></select>
                    <label class="p-cell-muted" style="font-size:12px;">Data (JSON or CSV format: email,name on each line)</label>
                    <textarea id="importData" class="p-select" style="width:100%;min-height:120px;font-family:monospace;font-size:12px;"
                        placeholder='[{&quot;email&quot;:&quot;a@example.com&quot;,&quot;name&quot;:&quot;Ahmed&quot;},{&quot;email&quot;:&quot;b@example.com&quot;,&quot;name&quot;:&quot;Sara&quot;}]'></textarea>
                    <div style="display:flex;gap:8px;margin-top:8px;">
                        <button class="p-btn xs" onclick="previewImport()">👁 Preview</button>
                        <button class="p-btn primary xs" onclick="importSubscribers()">Import</button>
                    </div>
                    <div id="importResult" style="margin-top:10px;font-size:13px;"></div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->listsJs();
        echo $this->renderPanelPage('email_marketing', 'Audience lists', 'Manage subscribers and lists', $body, $script);
        return [];
    }

    public function showContactsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('contacts');

        $body = <<<HTML
        {$tabs}

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;" id="emContactSubTabs">
            <button class="p-btn primary xs" data-ctab="overview" onclick="switchContactTab('overview')">📇 Overview</button>
            <button class="p-btn xs" data-ctab="fields" onclick="switchContactTab('fields')">🏷️ Custom fields</button>
            <button class="p-btn xs" data-ctab="tags" onclick="switchContactTab('tags')">📛 Tags</button>
            <button class="p-btn xs" data-ctab="segments" onclick="switchContactTab('segments')">🧩 Segments</button>
            <button class="p-btn xs" data-ctab="suppressions" onclick="switchContactTab('suppressions')">🚫 Suppressed</button>
        </div>

        <!-- Overview -->
        <div id="emContactOverview">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head"><h3>📇 Contacts overview</h3></div>
                <div id="emContactStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div class="p-loading-row">Loading...</div>
                </div>
            </div>
            <div class="p-card">
                <div class="p-card-head">
                    <h3>👥 Contacts <span id="emCSubCountLabel"></span></h3>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <select id="emCListFilter" class="p-select" onchange="loadContactSubscribers()"></select>
                        <select id="emCStatusFilter" class="p-select" onchange="loadContactSubscribers()">
                            <option value="">All statuses</option>
                            <option value="subscribed">Subscriber</option>
                            <option value="unsubscribed">Cancelled</option>
                            <option value="bounced">Bounced</option>
                        </select>
                        <input type="text" id="emCSearch" class="p-select" placeholder="Search..." style="max-width:180px;" onkeyup="if(event.key==='Enter')loadContactSubscribers()">
                        <button class="p-btn xs" onclick="loadContactSubscribers()">🔍</button>
                        <button class="p-btn primary xs" onclick="exportContacts()">📥 Export</button>
                    </div>
                </div>
                <div id="emContactsTable"><div class="p-loading-row">Loading...</div></div>
                <div id="emContactsPager" style="margin-top:10px;"></div>
            </div>
        </div>

        <!-- Custom fields -->
        <div id="emContactFields" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>🏷️ Custom fields</h3>
                    <button class="p-btn primary xs" onclick="openFieldModal()">+ New field</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">Use custom fields to store any data about contacts (company, city, birthday...) and personalize campaigns with it via {{custom.field_name}}.</p>
                <div id="emFieldsTable"><div class="p-loading-row">Loading...</div></div>
            </div>
        </div>

        <!-- Tags -->
        <div id="emContactTags" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>📛 Tags</h3>
                    <button class="p-btn primary xs" onclick="openTagModal()">+ New tag</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">Organize contacts with tags (VIP, leads, interested in offers...).</p>
                <div id="emTagsTable"><div class="p-loading-row">Loading...</div></div>
            </div>
        </div>

        <!-- Segments -->
        <div id="emContactSegments" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head">
                    <h3>🧩 Segments</h3>
                    <button class="p-btn primary xs" onclick="openSegmentModal()">+ New segment</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">Dynamic segments calculated in real time based on conditions (status, tag, list, custom field, engagement).</p>
                <div id="emSegmentsTable"><div class="p-loading-row">Loading...</div></div>
            </div>
        </div>

        <!-- Suppressions -->
        <div id="emContactSuppressions" style="display:none;">
            <div class="p-card">
                <div class="p-card-head">
                    <h3>🚫 Suppression list</h3>
                    <button class="p-btn primary xs" onclick="openSuppressionModal()">+ Add address</button>
                </div>
                <p class="p-cell-muted" style="font-size:13px;margin-bottom:10px;">Addresses here receive no campaigns (bounces, complaints, unsubscribes). They are automatically excluded from all sending.</p>
                <div id="emSuppressionsTable"><div class="p-loading-row">Loading...</div></div>
            </div>
        </div>

        <!-- Field modal -->
        <div class="p-modal-overlay" id="fieldModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="fieldModalTitle">New field</h3>
                    <button class="p-modal-close" onclick="document.getElementById('fieldModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="fieldId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">Programmatic name * (snake_case)</label>
                    <input type="text" id="fieldName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="company_name">
                    <label class="p-cell-muted" style="font-size:12px;">Display label *</label>
                    <input type="text" id="fieldLabel" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Company name">
                    <label class="p-cell-muted" style="font-size:12px;">Type</label>
                    <select id="fieldType" class="p-select" style="width:100%;margin-bottom:8px;" onchange="toggleFieldOptions()">
                        <option value="text">Text</option>
                        <option value="number">Number</option>
                        <option value="date">Date</option>
                        <option value="boolean">Yes/No</option>
                        <option value="select">Dropdown</option>
                        <option value="multi_select">Multi-select</option>
                    </select>
                    <div id="fieldOptionsWrap" style="display:none;margin-bottom:8px;">
                        <label class="p-cell-muted" style="font-size:12px;">Options (comma separated)</label>
                        <input type="text" id="fieldOptions" class="p-select" style="width:100%;" placeholder="A, B, C">
                    </div>
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:12px;">
                        <input type="checkbox" id="fieldRequired"> Required field
                    </label>
                    <button class="p-btn primary" onclick="saveField()">Save</button>
                </div>
            </div>
        </div>

        <!-- Tag modal -->
        <div class="p-modal-overlay" id="tagModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>New tag</h3>
                    <button class="p-modal-close" onclick="document.getElementById('tagModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="tagId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">Tag name *</label>
                    <input type="text" id="tagName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="VIP">
                    <label class="p-cell-muted" style="font-size:12px;">Color (optional)</label>
                    <input type="color" id="tagColor" class="p-select" style="width:100%;height:38px;margin-bottom:12px;" value="#0077be">
                    <button class="p-btn primary" onclick="saveTag()">Save</button>
                </div>
            </div>
        </div>

        <!-- Segment modal -->
        <div class="p-modal-overlay" id="segmentModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>New segment</h3>
                    <button class="p-modal-close" onclick="document.getElementById('segmentModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="segmentId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">Segment name *</label>
                    <input type="text" id="segmentName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Riyadh customers">
                    <label class="p-cell-muted" style="font-size:12px;">Description</label>
                    <input type="text" id="segmentDesc" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Short description">
                    <label class="p-cell-muted" style="font-size:12px;">Condition logic</label>
                    <select id="segmentMatchAll" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="1">All conditions (AND)</option>
                        <option value="0">Any condition (OR)</option>
                    </select>
                    <div id="segmentConditions"></div>
                    <button class="p-btn xs" onclick="addSegmentCondition()" style="margin-bottom:10px;">+ Add condition</button>
                    <div style="margin-bottom:10px;" id="segmentLiveCount" class="p-cell-muted" style="font-size:13px;"></div>
                    <button class="p-btn primary" onclick="saveSegment()">Save</button>
                </div>
            </div>
        </div>

        <!-- Suppression modal -->
        <div class="p-modal-overlay" id="suppressionModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>Add to suppression list</h3>
                    <button class="p-modal-close" onclick="document.getElementById('suppressionModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <label class="p-cell-muted" style="font-size:12px;">Email *</label>
                    <input type="email" id="supEmail" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="bounce@example.com">
                    <label class="p-cell-muted" style="font-size:12px;">Reason</label>
                    <select id="supType" class="p-select" style="width:100%;margin-bottom:8px;">
                        <option value="manual">Manual cancellation</option>
                        <option value="bounce">Bounce</option>
                        <option value="complaint">Complaint</option>
                        <option value="spam">Spam report</option>
                    </select>
                    <label class="p-cell-muted" style="font-size:12px;">Notes</label>
                    <input type="text" id="supReason" class="p-select" style="width:100%;margin-bottom:12px;" placeholder="Optional">
                    <button class="p-btn primary" onclick="saveSuppression()">Save</button>
                </div>
            </div>
        </div>

        <style>
        .em-condition-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:6px;margin-bottom:8px;align-items:center;}
        .em-tag-pill{display:inline-block;background:#0b2436;color:#7dd3fc;border-radius:999px;padding:2px 10px;font-size:12px;margin:2px;}
        </style>
        HTML;

        $script = $this->contactsJs();
        echo $this->renderPanelPage('email_marketing', 'Manage contacts', 'Fields, tags, segments and suppressions', $body, $script);
        return [];
    }

    public function showSubscriberDetailPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('contacts');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3 id="subDetailName">👤 Contact details</h3>
                <button class="p-btn xs" onclick="window.location.href='/email-marketing/contacts'">← Back</button>
            </div>
            <div id="subDetailBody"><div class="p-loading-row">Loading...</div></div>
        </div>
        HTML;

        $script = $this->subscriberDetailJs((int) ($params['id'] ?? 0));
        echo $this->renderPanelPage('email_marketing', 'Contact details', 'Full contact profile', $body, $script);
        return [];
    }

    public function showTemplatesPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('templates');

        $body = <<<HTML
        {$tabs}

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
            <button class="p-btn primary xs" onclick="switchTemplatesTab('mine')">🗂️ Your templates</button>
            <button class="p-btn xs" onclick="switchTemplatesTab('gallery')">🖼️ Template gallery</button>
            <span style="flex:1;"></span>
            <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?new=1'">🎨 Visual editor</button>
            <button class="p-btn primary xs" onclick="openTemplateModal()">+ New template</button>
        </div>

        <div id="emTemplatesGrid"><div class="p-loading-row">Loading...</div></div>
        <div id="emGalleryGrid" style="display:none;"><div class="p-loading-row">Loading the gallery...</div></div>

        <!-- Template modal -->
        <div class="p-modal-overlay" id="templateModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="templateModalTitle">New template</h3>
                    <button class="p-modal-close" onclick="document.getElementById('templateModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="templateId" value="">
                    <label class="p-cell-muted" style="font-size:12px;">Template name *</label>
                    <input type="text" id="templateName" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Example: weekly offers newsletter">
                    <label class="p-cell-muted" style="font-size:12px;">Default subject (subject)</label>
                    <input type="text" id="templateSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Example: this week offers {{first_name}}">
                    <label class="p-cell-muted" style="font-size:12px;">Content HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">Insert variable:</span>
                        <button class="p-btn xs" onclick="insertVar('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{company_name}}')">{{company_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{campaign_name}}')">{{campaign_name}}</button>
                        <button class="p-btn xs" onclick="insertVar('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                    </div>
                    <textarea id="templateHtml" class="p-select" style="width:100%;min-height:260px;font-family:monospace;font-size:12px;" placeholder="<p>Hello {{first_name}},</p>"></textarea>
                    <div style="display:flex;gap:8px;margin-top:10px;">
                        <button class="p-btn primary" onclick="saveTemplate()">Save</button>
                        <button class="p-btn" onclick="previewTemplateInline()">👁 Preview</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview modal -->
        <div class="p-modal-overlay" id="previewModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3>Template preview</h3>
                    <button class="p-modal-close" onclick="document.getElementById('previewModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body" id="previewBody" style="background:#f3f4f6;"></div>
            </div>
        </div>

        <!-- Share modal -->
        <div class="p-modal-overlay" id="shareModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3>🔗 Share template</h3>
                    <button class="p-modal-close" onclick="document.getElementById('shareModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <p class="p-cell-muted" style="margin-top:0;">Share the template with a public link, anyone can preview it and import it to their account.</p>
                    <input type="text" id="shareUrl" class="p-select" style="width:100%;" readonly>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button class="p-btn primary" onclick="copyShareUrl()">📋 Copy link</button>
                        <button class="p-btn danger" onclick="stopSharing()">Stop sharing</button>
                    </div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->templatesJs();
        echo $this->renderPanelPage('email_marketing', 'Email templates', 'Ready templates with personalization variables and a visual editor', $body, $script);
        return [];
    }

    public function showTemplateBuilderPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $templateId = (int) $this->get('template_id', 0);
        $galleryKey = (string) $this->get('gallery', '');
        $isNew = (int) $this->get('new', 0) === 1;

        $templateName = '';
        $templateSubject = '';
        $initialBlocks = '[]';
        $saveTarget = 0;

        if ($templateId > 0) {
            $template = (new EmailTemplate())->find($templateId);
            if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
                return $this->error('Template not found', 404);
            }
            $templateName = (string) $template->getAttribute('name');
            $templateSubject = (string) $template->getAttribute('subject');
            $initialBlocks = (string) $template->getAttribute('blocks');
            if ($initialBlocks === '' || $initialBlocks === null) {
                $initialBlocks = '[]';
            }
            $saveTarget = $templateId;
        } elseif ($galleryKey !== '') {
            $catalog = (new EmailTemplateEditorService())->catalog();
            if (!isset($catalog[$galleryKey])) {
                return $this->error('Template not found in the gallery', 404);
            }
            $item = $catalog[$galleryKey];
            $templateName = $item['name'];
            $templateSubject = $item['subject'];
            $initialBlocks = json_encode($item['blocks'], JSON_UNESCAPED_UNICODE);
        }

        $body = <<<HTML
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
            <a href="/email-marketing/templates" class="p-btn xs">→ Templates</a>
            <input type="text" id="bdName" class="p-select" style="max-width:280px;" placeholder="Template name *" value="{$this->safeAttr($templateName)}">
            <input type="text" id="bdSubject" class="p-select" style="max-width:360px;flex:1;min-width:200px;" placeholder="Default subject (subject)" value="{$this->safeAttr($templateSubject)}">
            <span style="flex:1;"></span>
            <button class="p-btn xs" onclick="previewBuilder()">👁 Preview</button>
            <button class="p-btn primary" onclick="saveBuilder()">💾 Save template</button>
        </div>

        <div style="display:grid;grid-template-columns:210px 1fr 260px;gap:14px;" id="bdLayout">
            <!-- Palette -->
            <div class="p-card" style="align-self:start;">
                <div class="p-card-head"><h3 style="font-size:14px;">🧩 Blocks</h3></div>
                <div id="bdPalette" style="display:flex;flex-direction:column;gap:6px;"></div>
            </div>

            <!-- Canvas -->
            <div class="p-card" style="background:#1f2937;">
                <div class="p-card-head">
                    <h3 style="font-size:14px;">🖥️ Live preview</h3>
                    <div style="display:flex;gap:6px;">
                        <button class="p-btn xs" onclick="renderBlocks()">↻ Update</button>
                    </div>
                </div>
                <div id="bdCanvasWrap" style="background:repeating-linear-gradient(45deg,#111827,#111827 8px,#0b1220 8px,#0b1220 16px);border-radius:10px;padding:14px;">
                    <div id="bdCanvas" style="max-width:660px;margin:0 auto;"></div>
                </div>
                <div id="bdBlocksBar" style="margin-top:12px;display:flex;flex-direction:column;gap:6px;"></div>
            </div>

            <!-- Inspector -->
            <div class="p-card" style="align-self:start;">
                <div class="p-card-head"><h3 style="font-size:14px;">⚙️ Block settings</h3></div>
                <div id="bdInspector"><p class="p-cell-muted">Choose a block from the list to edit.</p></div>
            </div>
        </div>
        HTML;

        $script = $this->builderJs($initialBlocks, $saveTarget);
        echo $this->renderPanelPage('email_marketing', 'Visual editor', 'Build an email template with blocks', $body, $script);
        return [];
    }

    /** صفحة عامة (بدون تسجيل) لعرض قالب مشترك واستيراده. */
    public function showSharedTemplatePage(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $editor = new EmailTemplateEditorService();
        $shared = $editor->byShareToken($token);
        if (!$shared) {
            http_response_code(404);
            echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>Template not found</title>'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;color:#374151;}</style>'
                . '</head><body><div style="text-align:center;padding:24px;"><h2>Shared template not found or sharing was stopped</h2>'
                . '<a href="/" style="color:#2563eb;">Back to home</a></div></body></html>';
            exit;
        }

        $name = htmlspecialchars((string) $shared['name'], ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars((string) $shared['subject'], ENT_QUOTES, 'UTF-8');
        $html = (string) $shared['html_body'];
        $tokenEsc = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="ar" dir="rtl">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$name} — Shared template</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: Arial, Helvetica, sans-serif; background:#f3f4f6; margin:0; padding:0; color:#111827; }
            .top { background:#111827; color:#fff; padding:14px 20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .top h1 { font-size:17px; margin:0; }
            .top .sub { font-size:12px; color:#9ca3af; }
            .wrap { max-width:700px; margin:24px auto; padding:0 16px; }
            .card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
            .card .hd { padding:14px 20px; border-bottom:1px solid #e5e7eb; font-size:13px; color:#374151; }
            .frame { padding:18px; background:#f9fafb; }
            iframe { width:100%; min-height:480px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; }
            .btn { display:inline-block; background:#2563eb; color:#fff; text-decoration:none; padding:10px 22px; border-radius:8px; font-weight:700; border:0; cursor:pointer; font-size:14px; }
            .btn.ghost { background:#fff; color:#111827; border:1px solid #e5e7eb; margin-right:8px; }
            .meta { font-size:12px; color:#6b7280; padding:10px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        </style>
        </head>
        <body>
        <div class="top">
            <div>
                <h1>📨 {$name}</h1>
                <div class="sub">Email template shared via the Tourfecto marketing platform</div>
            </div>
        </div>
        <div class="wrap">
            <div class="card">
                <div class="hd">Topic: <b>{$subject}</b></div>
                <div class="frame"><iframe sandbox="" srcdoc="{$this->attrEncode($html)}"></iframe></div>
                <div class="meta">
                    <button class="btn" id="importBtn">Use this template</button>
                    <span>Use the template to start building your own campaign quickly.</span>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('importBtn').addEventListener('click', async () => {
            const res = await fetch('/api/email-marketing/templates/shared/{$tokenEsc}/import', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'});
            const data = await res.json();
            if (data.success) { window.location.href = '/email-marketing/templates?imported=1'; }
            else if (res.status === 401) { window.location.href = '/login?next=/email-marketing/templates/shared/{$tokenEsc}'; }
            else { notify(data.error || 'Could not import'); }
        });
        </script>
        </body>
        </html>
        HTML;
        exit;
    }

    private function safeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function attrEncode(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function showCampaignsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🚀 Campaigns</h3>
                <button class="p-btn primary xs" onclick="openCampaignModal()">+ New campaign</button>
            </div>
            <div id="emCampaignsTable"><div class="p-loading-row">Loading...</div></div>
        </div>

        <!-- Campaign modal -->
        <div class="p-modal-overlay" id="campaignModal">
            <div class="p-modal wide">
                <div class="p-modal-head">
                    <h3 id="campaignModalTitle">New campaign</h3>
                    <button class="p-modal-close" onclick="document.getElementById('campaignModal').classList.remove('open')">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="campaignId" value="">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">Campaign name *</label>
                            <input type="text" id="campaignName" class="p-select" style="width:100%;" placeholder="Example: summer offer launch">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">Audience (list)</label>
                            <select id="campaignList" class="p-select" style="width:100%;"></select>
                        </div>
                    </div>
                    <div style="margin-top:8px;">
                        <label class="p-cell-muted" style="font-size:12px;">Or target a dynamic segment (overrides the list)</label>
                        <select id="campaignSegment" class="p-select" style="width:100%;">
                            <option value="0">— No segment —</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px;">
                        <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;"><input type="checkbox" id="campaignSto"/> <span>Send at each subscriber's best time<br><small class="p-cell-muted">Learned from their real opens/clicks (within 24h). Others get it immediately.</small></span></label>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">Repeat (newsletter)</label>
                            <div style="display:flex;gap:6px;">
                                <select id="campaignRecurrence" class="p-select" style="flex:1;" onchange="document.getElementById('campaignFirstRun').style.display=this.value?'block':'none'">
                                    <option value="">Send once</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option>
                                </select>
                                <input type="datetime-local" id="campaignFirstRun" class="p-select" style="flex:1;display:none;" title="First send">
                            </div>
                        </div>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">Topic (subject) * <button type="button" class="p-btn xs" onclick="aiSubjects(this)">✨ Suggest subjects</button></label>
                    <input type="text" id="campaignSubject" class="p-select" style="width:100%;margin-bottom:8px;" placeholder="Example: special offer for the first 50 customers {{first_name}}">
                    <div id="campaignAiSubjects" style="display:flex;flex-direction:column;gap:4px;margin-bottom:8px;"></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">From (name)</label>
                            <input type="text" id="campaignFromName" class="p-select" style="width:100%;" placeholder="Your company">
                        </div>
                        <div>
                            <label class="p-cell-muted" style="font-size:12px;">From (email)</label>
                            <input type="text" id="campaignFromEmail" class="p-select" style="width:100%;" placeholder="noreply@yourdomain.com">
                        </div>
                    </div>
                    <label class="p-cell-muted" style="font-size:12px;margin-top:8px;display:block;">Content HTML</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <span style="font-size:12px;color:#6b7280;">Insert variable:</span>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{first_name}}')">{{first_name}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{email}}')">{{email}}</button>
                        <button class="p-btn xs" onclick="insertVarCampaign('{{unsubscribe_url}}')">{{unsubscribe_url}}</button>
                        <button class="p-btn xs" onclick="aiWriteBody(this)">✨ Write with AI</button>
                        <button class="p-btn xs" onclick="aiRewriteBody(this)">✨ Improve text</button>
                    </div>
                    <textarea id="campaignHtml" class="p-select" style="width:100%;min-height:220px;font-family:monospace;font-size:12px;" placeholder="<p>Hello {{first_name}},</p><p>This week offer...</p>"></textarea>
                    <div id="campaignScheduleWrap" style="margin-top:10px;display:none;">
                        <label class="p-cell-muted" style="font-size:12px;">Schedule (date and time)</label>
                        <input type="datetime-local" id="campaignScheduledAt" class="p-select" style="width:100%;">
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px;">
                        <button class="p-btn primary" onclick="saveCampaign(false)">Save as draft</button>
                        <button class="p-btn" onclick="saveCampaign(true)">📅 Schedule</button>
                        <button class="p-btn danger" onclick="document.getElementById('campaignModal').classList.remove('open')">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->campaignsJs();
        echo $this->renderPanelPage('email_marketing', 'Campaigns', 'Create and schedule email marketing campaigns', $body, $script);
        return [];
    }

    public function showCampaignDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('Campaign not found', 404);
        }
        $tabs = $this->emailTabsHtml('campaigns');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📊 Campaign report: <span id="crName"></span></h3>
                <div style="display:flex;gap:8px;">
                    <button class="p-btn xs" onclick="refreshCampaignReport()">↻ Update</button>
                    <button class="p-btn xs danger" id="crCancelBtn" onclick="cancelCampaign()" style="display:none;">Unschedule</button>
                    <button class="p-btn xs primary" id="crRetryBtn" onclick="retryFailedCampaign()" style="display:none;">↻ Retry</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;" id="crKpis">
                <div class="p-loading-row">Loading...</div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 Engagement rates</h3></div>
            <div id="crRates"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📨 Recipients</h3></div>
            <div id="crRecipients"><div class="p-loading-row">Loading...</div></div>
            <div id="crRecipientsPager" style="margin-top:10px;"></div>
        </div>
        HTML;

        $script = $this->campaignReportJs($campaignId);
        echo $this->renderPanelPage('email_marketing', 'Campaign report', 'Campaign statistics and engagement', $body, $script);
        return [];
    }

    public function showReportsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('reports');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📈 Email marketing statistics</h3><span class="p-card-sub">Open and click rates across all your campaigns</span></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;" id="emStatsKpis">
                <div class="p-loading-row">Loading...</div>
            </div>
        </div>

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>📉 Daily trend</h3>
                <select id="emTsDays" class="p-select" style="width:auto;" onchange="loadTimeseries()"><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="14">Last 14 days</option></select>
            </div>
            <div id="emTsChart" style="min-height:240px;"><div class="p-loading-row">Loading...</div></div>
            <div id="emTsRevenue" style="margin-top:6px;"></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🗂 Performance of each campaign</h3></div>
            <div id="emStatsCampaigns"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>⚡ Transactional messages</h3><span class="p-card-sub">Send automated messages (welcome/password/notifications)</span></div>
            <div id="emStatsTransactional"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>🧪 A/B tests</h3><span class="p-card-sub">Compare campaign variants performance (opens/clicks)</span></div>
            <div id="emStatsAbTests"><div class="p-loading-row">Loading...</div></div>
        </div>
        HTML;

        $script = $this->reportsJs();
        echo $this->renderPanelPage('email_marketing', 'Reports', 'Your campaigns engagement statistics', $body, $script);
        return [];
    }

    public function showAutomationsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('automations');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>⚙️ Automatic workflow</h3>
                <span class="p-card-sub">Automation like Brevo: subscribe / tag / open / click / after a delay - with wait steps, emails, tags and lists</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="openAutomationModal()">+ New workflow</button>
                <button class="p-btn" onclick="runAutomationsDue(this)">Run due items now</button>
            </div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🔗 Platform connections</h3><span class="p-card-sub">Bookings and CRM trigger workflows; bookings made from an email are credited to the campaign</span></div>
            <div id="emPlatformIntegration" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;padding:4px 2px;">
                <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" id="emIntSyncBookings"/> <span>Add new booking customers as subscribers<br><small class="p-cell-muted">Off by default. Existing contacts who unsubscribed are never re-subscribed.</small></span></label>
                <div><label class="p-label">List for booking customers</label><select class="p-input" id="emIntBookingList" style="width:100%;"></select></div>
                <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" id="emIntSyncCrm"/> <span>Add new CRM leads / won deals as subscribers</span></label>
                <div><label class="p-label">List for CRM contacts</label><select class="p-input" id="emIntCrmList" style="width:100%;"></select></div>
                <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" id="emIntAttribution"/> <span>Credit bookings to the email that brought them</span></label>
                <div><label class="p-label">Attribution window (days)</label><input class="p-input" id="emIntWindow" type="number" min="1" max="90" style="width:100%;"/></div>
                <div style="grid-column:1/-1;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <small class="p-cell-muted" id="emIntVars"></small>
                    <button class="p-btn primary xs" onclick="savePlatformIntegration(this)">Save connections</button>
                </div>
            </div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📋 Workflow</h3></div>
            <div id="emAutomationsList"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div id="emAutomationModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:860px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 id="emAutoModalTitle" style="margin:0;">New workflow</h3>
                    <button class="p-btn xs" onclick="closeAutomationModal()">✕ Close</button>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div>
                        <label class="p-label">Name</label>
                        <input class="p-input" id="emAutoName" placeholder="Example: welcome new subscribers" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Description</label>
                        <input class="p-input" id="emAutoDesc" placeholder="Optional description" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Operator</label>
                        <select class="p-input" id="emAutoTrigger" style="width:100%;"></select>
                    </div>
                    <div id="emAutoTriggerValueWrap">
                        <label class="p-label" id="emAutoTriggerValueLabel">List (optional)</label>
                        <select class="p-input" id="emAutoTriggerValue" style="width:100%;"></select>
                    </div>
                    <div>
                        <label class="p-label">Qualifying entry lists (optional)</label>
                        <select class="p-input" id="emAutoEntryAudience" multiple style="width:100%;height:70px;"></select>
                    </div>
                    <div>
                        <label class="p-label">Stay only while in these lists (optional)</label>
                        <select class="p-input" id="emAutoExitAudience" multiple style="width:100%;height:70px;"></select>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <label class="p-label" style="margin:0;">Steps</label>
                        <button class="p-btn xs" onclick="addAutomationStep()">+ Add step</button>
                    </div>
                    <div id="emAutoSteps" style="display:flex;flex-direction:column;gap:8px;"></div>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                    <button class="p-btn" onclick="closeAutomationModal()">Cancel</button>
                    <button class="p-btn primary" id="emAutoSaveBtn" onclick="saveAutomation()">💾 Save workflow</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->automationsJs();
        echo $this->renderPanelPage('email_marketing', 'Automatic workflow', 'Email marketing automation', $body, $script);
        return [];
    }

    public function showEmailSettingsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('settings');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🔌 Sending settings (SMTP)</h3>
                <span class="p-card-sub">Connect your own mail server - like Brevo, sending happens through your own infrastructure, not a third party</span>
            </div>
            <div id="emSmtpStatus" style="margin-top:8px;"></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>⚙️ Server details</h3></div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:10px;">
                <div>
                    <label class="p-label">Host (host)</label>
                    <input class="p-input" id="emSmtpHost" placeholder="smtp.example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">Port (port)</label>
                    <input class="p-input" id="emSmtpPort" type="number" value="587" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">Encryption</label>
                    <select class="p-input" id="emSmtpEncryption" style="width:100%;">
                        <option value="tls">STARTTLS</option>
                        <option value="ssl">SSL/TLS</option>
                        <option value="">No encryption</option>
                    </select>
                </div>
                <div>
                    <label class="p-label">Username</label>
                    <input class="p-input" id="emSmtpUsername" placeholder="you@example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">Password / App Password</label>
                    <input class="p-input" id="emSmtpPassword" type="password" placeholder="••••••••" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">Sender (from email)</label>
                    <input class="p-input" id="emSmtpFromEmail" placeholder="noreply@example.com" style="width:100%;"/>
                </div>
                <div>
                    <label class="p-label">Sender name</label>
                    <input class="p-input" id="emSmtpFromName" placeholder="My company" style="width:100%;"/>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button class="p-btn primary" onclick="saveSmtpSettings()">💾 Save settings</button>
                <button class="p-btn" onclick="testSmtpSettings()">🧪 Test connection</button>
                <button class="p-btn" id="emSmtpToggleBtn" onclick="toggleSmtpSettings()">⏻ Enable</button>
                <button class="p-btn danger" onclick="deleteSmtpSettings()">🗑 Delete settings</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px;margin-top:14px;" id="emSmtpStatusRow">
                <input type="checkbox" id="emSmtpActive" onchange="toggleSmtpSettings()">
                <label for="emSmtpActive">Enable sending via these settings</label>
            </div>
            <p class="p-cell-muted" style="margin-top:12px;">Your settings override the global .env settings. The password is not shown again after saving.</p>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head">
                <h3>🔔 Webhook Delivery tracking</h3>
                <span class="p-card-sub">Bounces and complaints from the email provider are recorded automatically - example SendGrid/Mailgun/Postmark</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap;">
                <input type="checkbox" id="emDeliveryWebhookEnabled" onchange="toggleDeliveryWebhook()" style="transform:scale(1.1);">
                <label for="emDeliveryWebhookEnabled">Enable receiving the delivery webhook</label>
                <button class="p-btn" onclick="regenerateDeliveryWebhookSecret()">🔑 Generate a new key</button>
            </div>
            <div style="margin-top:12px;font-size:13px;">
                <label class="p-label">Webhook URL (put it in the email provider settings)</label>
                <code id="emDeliveryWebhookUrl" style="display:block;background:#0f172a;color:#7dd3fc;padding:8px 12px;border-radius:8px;margin-top:6px;direction:ltr;text-align:left;word-break:break-all;"></code>
            </div>
            <div style="margin-top:10px;font-size:13px;">
                <label class="p-label">Secret key (sent in the signature header - support us Mailgun/SendGrid/Postmark)</label>
                <code id="emDeliveryWebhookSecret" style="display:block;background:#0f172a;color:#a5f3fc;padding:8px 12px;border-radius:8px;margin-top:6px;direction:ltr;text-align:left;word-break:break-all;"></code>
            </div>
            <p class="p-cell-muted" style="margin-top:10px;">Signature verification: the user private key first, then the global .env key
                (EMAIL_DELIVERY_WEBHOOK_SECRET) at minimum. Unknown types are safely ignored
                (bounce/complaint/spam only). Note: with raw SMTP (the current setup) there is no
                webhooks official from the server itself - record issues manually via this endpoint or
                from the provider dashboard until an automatic API integration exists.</p>
        </div>
        HTML;

        $script = $this->smtpSettingsJs();
        echo $this->renderPanelPage('email_marketing', 'Sending settings', 'SMTP / Deliverability', $body, $script);
        return [];
    }

    public function showTransactionalPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('transactional');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>📨 Transactional messages</h3>
                <span class="p-card-sub">Registration confirmation, password recovery, invoices - with open/click tracking and no unsubscribe</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="openTransactionalTemplateModal()">+ New transactional template</button>
                <button class="p-btn" onclick="loadTransactionalLogs()">Sending log</button>
            </div>
            <div id="emTxStats" style="display:flex;gap:12px;margin-top:14px;"></div>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>🗂️ Transactional templates</h3></div>
            <div id="emTxTemplates"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>📋 Sending log</h3></div>
            <div id="emTxLogs"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div id="emTxTemplateModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:760px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 id="emTxModalTitle" style="margin:0;">New transactional template</h3>
                    <button class="p-btn xs" onclick="closeTransactionalTemplateModal()">✕ Close</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
                    <div>
                        <label class="p-label">Name</label>
                        <input class="p-input" id="emTxName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Short value (slug)</label>
                        <input class="p-input" id="emTxSlug" placeholder="welcome (Optional)" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Topic (subject)</label>
                        <input class="p-input" id="emTxSubject" style="width:100%;"/>
                    </div>
                </div>
                <div style="margin-top:12px;">
                    <label class="p-label">Content (HTML - supports {{variables}})</label>
                    <textarea class="p-input" id="emTxHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;"></textarea>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeTransactionalTemplateModal()">Cancel</button>
                    <button class="p-btn primary" onclick="saveTransactionalTemplate()">💾 Save template</button>
                </div>
            </div>
        </div>

        <div id="emTxSendModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:520px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;">Send a transactional message</h3>
                    <button class="p-btn xs" onclick="closeTransactionalSendModal()">✕ Close</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr;gap:12px;">
                    <div>
                        <label class="p-label">Recipient email</label>
                        <input class="p-input" id="emTxSendEmail" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Recipient name (optional)</label>
                        <input class="p-input" id="emTxSendName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Data (JSON for variables)</label>
                        <textarea class="p-input" id="emTxSendData" rows="3" placeholder='{"first_name":"Ahmed","company_name":"My company"}' style="width:100%;font-family:monospace;font-size:13px;"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeTransactionalSendModal()">Cancel</button>
                    <button class="p-btn primary" onclick="sendTransactionalNow()">📤 Send now</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->transactionalJs();
        echo $this->renderPanelPage('email_marketing', 'Transactional messages', 'Transactional Email', $body, $script);
        return [];
    }

    public function showAbTestsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $tabs = $this->emailTabsHtml('ab_tests');

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🧪 A/B test</h3>
                <span class="p-card-sub">Split your audience between two versions (subject or content) and pick the winning variant - like Brevo/Mailchimp</span>
            </div>
            <button class="p-btn primary" onclick="openAbTestModal()">+ New A/B test</button>
        </div>

        <div class="p-card">
            <div class="p-card-head"><h3>📋 Tests</h3></div>
            <div id="emAbTestsList"><div class="p-loading-row">Loading...</div></div>
        </div>

        <div id="emAbTestModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:999;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;">
            <div style="background:#fff;border-radius:14px;max-width:640px;width:100%;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;">New A/B test</h3>
                    <button class="p-btn xs" onclick="closeAbTestModal()">✕ Close</button>
                </div>
                <div style="display:grid;grid-template-columns:1fr;gap:12px;">
                    <div>
                        <label class="p-label">Name</label>
                        <input class="p-input" id="emAbName" style="width:100%;"/>
                    </div>
                    <div>
                        <label class="p-label">Main campaign (audience)</label>
                        <select class="p-input" id="emAbCampaign" style="width:100%;"></select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label class="p-label">Variant B share %</label>
                            <input class="p-input" id="emAbSplit" type="number" value="50" min="5" max="95" style="width:100%;"/>
                        </div>
                        <div>
                            <label class="p-label">Winning metric</label>
                            <select class="p-input" id="emAbMetric" style="width:100%;">
                                <option value="open">Open rate</option>
                                <option value="click">Click rate</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button class="p-btn" onclick="closeAbTestModal()">Cancel</button>
                    <button class="p-btn primary" onclick="createAbTest()">Create test</button>
                </div>
            </div>
        </div>
        HTML;

        $script = $this->abTestsJs();
        echo $this->renderPanelPage('email_marketing', 'A/B test', 'A/B Testing', $body, $script);
        return [];
    }

    public function showAbTestDetailsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $ab = $this->abTestService()->get($this->uid(), $id);
        if (!$ab) {
            echo $this->renderPanelPage(
                'email_marketing',
                'A/B test',
                'A/B Testing',
                '<p class="p-cell-muted" style="padding:20px;">Test not found.</p>'
            );
            return [];
        }
        $tabs = $this->emailTabsHtml('ab_tests');
        $va = $ab['variant_a'] ?? [];
        $vb = $ab['variant_b'] ?? [];
        $vaTotal = (int) ($va['total_recipients'] ?? 0);
        $vbTotal = (int) ($vb['total_recipients'] ?? 0);
        $vaSubject = htmlspecialchars((string) ($va['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vaHtml = htmlspecialchars((string) ($va['html_body'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vbSubject = htmlspecialchars((string) ($vb['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
        $vbHtml = htmlspecialchars((string) ($vb['html_body'] ?? ''), ENT_QUOTES, 'UTF-8');
        $abStatus = htmlspecialchars((string) $ab['status'], ENT_QUOTES, 'UTF-8');
        $abMetric = htmlspecialchars((string) $ab['metric'], ENT_QUOTES, 'UTF-8');
        $abName = htmlspecialchars((string) $ab['name'], ENT_QUOTES, 'UTF-8');
        $abSplit = (int) $ab['split_percent'];

        $body = <<<HTML
        {$tabs}

        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head">
                <h3>🧪 {$abName}</h3>
                <span class="p-card-sub">Status: {$abStatus} — Metric: {$abMetric} — B share: {$abSplit}%</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="p-btn primary" onclick="runAbTest(this)">🚀 Run and split the audience</button>
                <button class="p-btn" onclick="sendAbBatch(this)">📤 Send batch</button>
                <button class="p-btn" onclick="loadAbReport()">📈 Report</button>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;background:var(--panel-card-bg-2,#f9fafb);padding:10px;border-radius:10px;">
                <span style="font-size:13px;font-weight:600;">✉️ Send test:</span>
                <select id="emAbTestVariant" class="p-select" style="max-width:150px;">
                    <option value="all">All variables</option>
                    <option value="a">Variant A</option>
                    <option value="b">Variant B</option>
                </select>
                <input type="email" id="emAbTestEmail" class="p-select" style="flex:1;min-width:200px;" placeholder="your-email@example.com">
                <button class="p-btn xs primary" onclick="sendAbTestEmail(this)">📬 Send</button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="p-card">
                <div class="p-card-head"><h3>🅰️ Variant A — {$vaTotal} recipient</h3></div>
                <label class="p-label">Topic</label>
                <input class="p-input" id="emAbASubject" value="{$vaSubject}" style="width:100%;"/>
                <label class="p-label" style="margin-top:10px;">Content</label>
                <textarea class="p-input" id="emAbAHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;">{$vaHtml}</textarea>
                <button class="p-btn xs" style="margin-top:10px;" onclick="saveAbVariant('a')">💾 Save variant A</button>
            </div>
            <div class="p-card">
                <div class="p-card-head"><h3>🅱️ Variant B — {$vbTotal} recipient</h3></div>
                <label class="p-label">Topic</label>
                <input class="p-input" id="emAbBSubject" value="{$vbSubject}" style="width:100%;"/>
                <label class="p-label" style="margin-top:10px;">Content</label>
                <textarea class="p-input" id="emAbBHtml" rows="10" style="width:100%;font-family:monospace;font-size:13px;">{$vbHtml}</textarea>
                <button class="p-btn xs" style="margin-top:10px;" onclick="saveAbVariant('b')">💾 Save variant B</button>
            </div>
        </div>

        <div class="p-card" style="margin-top:16px;">
            <div class="p-card-head"><h3>📊 Report</h3></div>
            <div id="emAbReport"><div class="p-loading-row">Request the report to view results</div></div>
        </div>
        HTML;

        $script = $this->abTestDetailsJs($id);
        echo $this->renderPanelPage('email_marketing', 'A/B test', 'A/B Testing', $body, $script);
        return [];
    }

    // ============================================================
    //  API: Dashboard
    // ============================================================

    public function dashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        $lists = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_lists WHERE user_id = ?",
            [$userId]
        )[0]['total'];

        $campaigns = $this->db->query(
            "SELECT COUNT(*) AS total, COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(opened_count),0) AS opened, COALESCE(SUM(clicked_count),0) AS clicked
             FROM email_campaigns WHERE user_id = ?",
            [$userId]
        )[0];

        $sentTotal = (int) ($campaigns['sent'] ?? 0);
        $openRate = $sentTotal > 0 ? round((int) $campaigns['opened'] / $sentTotal * 100, 1) : 0;
        $clickRate = $sentTotal > 0 ? round((int) $campaigns['clicked'] / $sentTotal * 100, 1) : 0;

        $txStats = $this->transactionalService()->stats($userId);
        $abCount = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_ab_tests WHERE user_id = ?",
            [$userId]
        )[0]['total'];
        $revenue = (new EmailPlatformBridge())->revenueSummary($userId);
        $revenueLabel = implode(' + ', array_map(
            fn ($r) => number_format((float) $r['revenue'], 0) . ' ' . $r['currency'],
            $revenue['by_currency']
        )) ?: '0';
        $revenueBookings = array_sum(array_map(fn ($r) => (int) $r['bookings'], $revenue['by_currency']));

        return $this->success([
            'kpis' => [
                ['label' => 'Active subscribers', 'value' => $subscribers, 'icon' => '👥'],
                ['label' => 'Lists', 'value' => $lists, 'icon' => '🗂'],
                ['label' => 'Campaigns', 'value' => (int) $campaigns['total'], 'icon' => '🚀'],
                ['label' => 'Open rate', 'value' => $openRate . '%', 'icon' => '👁'],
                ['label' => 'Click rate', 'value' => $clickRate . '%', 'icon' => '🖱'],
                ['label' => 'Transactional messages', 'value' => (int) ($txStats['total'] ?? 0), 'icon' => '⚡'],
                ['label' => 'A/B tests', 'value' => $abCount, 'icon' => '🧪'],
                ['label' => 'Bookings from email', 'value' => $revenueBookings, 'icon' => '🧳'],
                ['label' => 'Booking revenue from email', 'value' => $revenueLabel, 'icon' => '💰'],
            ],
            'email_revenue' => $revenue,
            'delivery' => $this->deliveryStatus(),
            'quota' => $this->campaignService->guard()->snapshot($userId),
            'recent_campaigns' => $this->campaignService->list($userId),
            'top_lists' => $this->listService->lists($userId),
        ], 'Email marketing dashboard');
    }

    // ============================================================
    //  API: Lists & Subscribers
    // ============================================================

    public function lists(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['lists' => $this->listService->lists($this->uid())]);
    }

    public function createList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->createList($this->uid(), (string) $this->get('name'), $this->get('description'));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'List created')
            : $this->error($result['error'], 422);
    }

    public function updateList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->updateList($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'List updated')
            : $this->error($result['error'], 404);
    }

    public function deleteList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteList($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'List deleted')
            : $this->error($result['error'], 404);
    }

    public function listSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) ($params['id'] ?? 0);
        $result = $this->listService->subscribers(
            $this->uid(),
            $listId,
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function allSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->subscribers(
            $this->uid(),
            (int) $this->get('list_id', 0),
            ['status' => $this->get('status'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function createSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $result = $this->listService->subscribe(
            $this->uid(),
            (string) $this->get('email'),
            ['name' => $this->get('name'), 'source' => 'manual'],
            $listId
        );
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Subscriber added')
            : $this->error($result['error'], 422);
    }

    // ============================================================
    //  Public: Double Opt-In (بند 2) — بدون AuthMiddleware عمدًا
    //  (النموذج العام + رابط التأكيد في البريد). حماية الإساءة عبر
    //  RateLimitMiddleware العام + التحقق من صحة الإيميل فقط.
    // ============================================================

    /**
     * POST /webhooks/email/subscribe — اشتراك عام (يبدأ pending_optin).
     * البريد الإلكتروني مطلوب؛ قائمة اختيارية (تُربط بها عند التأكيد).
     * @param array $params body أو form: email, name, list_id
     */
    public function publicSubscribe(array $params = []): array
    {
        $email = strtolower(trim((string) $this->get('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address', 422);
        }

        // الاشتراك العام يتطلب تأكيد (Double Opt-In) — معرفة المالك (user_id)
        // من القائمة المختارة إن وُجدت، وإلا نرفض (لا يمكن إسناد الاشتراك
        // لمستخدم بدون سياق قائمة). الـ endpoint العام يفترض إرسال list_id
        // من نموذج الموقع/الوصلة العامة.
        $listId = (int) $this->get('list_id', 0);
        $list = $listId > 0 ? (new EmailList())->find($listId) : null;
        if (!$list) {
            return $this->error('Invalid subscription list', 422);
        }
        $userId = (int) $list->getAttribute('user_id');

        $result = $this->listService->subscribe(
            $userId,
            $email,
            ['name' => (string) $this->get('name'), 'source' => 'form', 'require_optin' => true],
            $listId
        );
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }
        return $this->success([
            'id' => $result['id'],
            'pending_optin' => (bool) ($result['pending_optin'] ?? false),
            'status' => $result['status'] ?? 'subscribed',
        ], 'Your subscription was received - check your email and confirm your subscription within minutes');
    }

    /**
     * GET /webhooks/email/confirm/{token} — تفعيل الاشتراك من رابط البريد.
     * يعرض صفحة HTML بسيطة بنتيجة التأكيد (نجاح/فشل) ولا يحتاج Auth.
     */
    public function publicConfirmOptin(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $result = $this->listService->confirmOptin($token, $ip);

        $success = (bool) ($result['success'] ?? false);
        $emoji = $success ? '✅' : '⚠️';
        $title = $success ? 'Your subscription is confirmed' : 'Invalid link';
        $body = $success
            ? 'Thank you! Your subscription is now confirmed and you will receive our messages.'
            : 'This link is invalid or expired. You can subscribe again from the public form.';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
body{font-family:Segoe UI,Tahoma,Arial,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;}
.box{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,23,42,.08);padding:40px;max-width:420px;width:100%;text-align:center;}
.ico{font-size:48px;margin-bottom:12px;}
h1{font-size:22px;color:#0f172a;margin:0 0 10px;}
p{color:#475569;line-height:1.9;margin:0;}
</style>
</head>
<body><div class="box"><div class="ico">{$emoji}</div><h1>{$title}</h1><p>{$body}</p></div></body>
</html>
HTML;
        exit;
    }

    public function importSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $listId = (int) $this->get('list_id', 0);
        $raw = (string) $this->get('data');
        $rows = $this->parseImportData($raw);
        if (empty($rows)) {
            return $this->error('No valid data to import', 422);
        }
        // نفس مسار الاستيراد المتقدم: تنضيف القائمة + عدم إعادة اشتراك اللي لغى/ارتد
        $result = $this->contacts()->importContacts($this->uid(), $rows, ['list_id' => $listId]);
        return $this->success($result, 'Imported');
    }

    public function unsubscribeSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        // API (إدارة): إلغاء اشتراك مشترك محدد
        $id = (int) ($params['id'] ?? 0);
        $rows = $this->db->query(
            "SELECT unsubscribe_token FROM email_subscribers WHERE id = ? AND user_id = ?",
            [$id, $this->uid()]
        );
        if (empty($rows)) {
            return $this->error('Subscriber not found', 404);
        }
        $ok = $this->listService->unsubscribeByToken($rows[0]['unsubscribe_token']);
        return $ok ? $this->success([], 'User unsubscribed') : $this->error('Could not cancel', 422);
    }

    public function deleteSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->listService->deleteSubscriber($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Subscriber deleted')
            : $this->error($result['error'], 404);
    }

    // ============================================================
    //  Contact Management APIs (Phase 1) — fields / tags / segments / suppressions
    // ============================================================

    private function contacts(): ContactManagementService
    {
        return new ContactManagementService();
    }

    // ----- Subscriber detail & advanced import/export -----

    public function getSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $data = $this->contacts()->subscriberDetail($this->uid(), (int) ($params['id'] ?? 0));
        return $data ? $this->success(['subscriber' => $data]) : $this->error('Subscriber not found', 404);
    }

    public function updateSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $svc = $this->contacts();
        $body = $this->all();

        if (isset($body['name'])) {
            $sub = (new EmailSubscriber())->find($id);
            if (!$sub || (int) $sub->getAttribute('user_id') !== $this->uid()) {
                return $this->error('Subscriber not found', 404);
            }
            $sub->setAttribute('name', trim((string) $body['name']));
            $sub->save();
        }
        if (!empty($body['status'])) {
            $result = $svc->updateSubscriberStatus($this->uid(), $id, (string) $body['status']);
            if (!$result['success']) {
                return $this->error($result['error'], 422);
            }
        }
        if (!empty($body['custom_values']) && is_array($body['custom_values'])) {
            $svc->saveCustomValues($this->uid(), $id, $body['custom_values']);
        }
        return $this->success([], 'Subscriber updated');
    }

    public function importContactsAdvanced(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $raw = (string) $this->get('data');
        $rows = $this->parseImportData($raw);
        if (empty($rows)) {
            return $this->error('No valid data to import', 422);
        }
        $options = [
            'list_id' => (int) $this->get('list_id', 0),
            'tags' => is_array($this->get('tags')) ? array_map('strval', $this->get('tags')) : [],
            'field_map' => is_array($this->get('field_map')) ? $this->get('field_map') : [],
        ];
        $result = $this->contacts()->importContacts($this->uid(), $rows, $options);
        return $this->success($result, 'Imported');
    }

    /** POST /subscribers/import-preview — نفس قواعد الاستيراد من غير كتابة */
    public function previewImport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = $this->parseImportData((string) $this->get('data'));
        if (empty($rows)) {
            return $this->error('No valid data to import', 422);
        }
        return $this->success($this->contacts()->previewImport($this->uid(), $rows));
    }

    /** POST /ai-assist — اقتراحات عنوان/نص بالذكاء الاصطناعي (اقتراح فقط) */
    public function aiAssist(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $company = $this->db->query("SELECT company_name FROM users WHERE id = ? LIMIT 1", [$this->uid()])[0]['company_name'] ?? '';
        $result = (new EmailAiWriterService())->assist(
            (string) $this->get('action', ''),
            (string) $this->get('input', ''),
            ['company_name' => (string) $company, 'language' => (string) $this->get('language', 'ar')]
        );
        return $result['success'] ? $this->success($result) : $this->error($result['error'], 422);
    }

    /** POST /campaigns/{id}/recurrence — {recurrence: daily|weekly|monthly|'', first_run_at} */
    public function setCampaignRecurrence(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailRecurringCampaignService())->configure(
            $this->uid(),
            (int) ($params['id'] ?? 0),
            (string) $this->get('recurrence', ''),
            (string) $this->get('first_run_at', '')
        );
        return $result['success'] ? $this->success($result, 'Recurrence saved') : $this->error($result['error'], 422);
    }

    public function exportSubscribers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $filters = [
            'list_id' => (int) $this->get('list_id', 0),
            'status' => $this->get('status') ? (string) $this->get('status') : null,
            'segment_id' => (int) $this->get('segment_id', 0),
        ];
        $rows = $this->contacts()->exportSubscribers($this->uid(), $filters);
        $format = $this->get('format') === 'csv' ? 'csv' : 'json';
        if ($format === 'csv') {
            return $this->success(['data' => $this->toCsv($rows)]);
        }
        return $this->success(['data' => $rows]);
    }

    private function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $row));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    // ----- Custom fields -----

    public function customFields(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['fields' => $this->contacts()->customFields($this->uid())]);
    }

    public function createCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createCustomField($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Field created')
            : $this->error($result['error'], 422);
    }

    public function updateCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateCustomField($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Field updated')
            : $this->error($result['error'], 422);
    }

    public function deleteCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteCustomField($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Field deleted')
            : $this->error($result['error'], 422);
    }

    // ----- Tags -----

    public function tags(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['tags' => $this->contacts()->tags($this->uid())]);
    }

    public function createTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createTag($this->uid(), (string) $this->get('name'), $this->get('color'));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Tag created')
            : $this->error($result['error'], 422);
    }

    public function updateTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateTag($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Tag updated')
            : $this->error($result['error'], 422);
    }

    public function deleteTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteTag($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Tag deleted')
            : $this->error($result['error'], 422);
    }

    public function assignSubscriberTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->assignTag($this->uid(), (int) ($params['id'] ?? 0), (int) $this->get('tag_id'));
        return $result['success']
            ? $this->success([], 'Tag added')
            : $this->error($result['error'], 422);
    }

    public function removeSubscriberTag(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->contacts()->removeTag($this->uid(), (int) ($params['id'] ?? 0), (int) ($params['tagId'] ?? 0));
        return $this->success([], 'Tag removed');
    }

    // ----- Segments -----

    public function segments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $list = $this->contacts()->segments($this->uid());
        foreach ($list as &$seg) {
            $seg['conditions'] = json_decode((string) ($seg['conditions'] ?? '[]'), true) ?: [];
        }
        return $this->success(['segments' => $list]);
    }

    public function createSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->createSegment($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Segment created')
            : $this->error($result['error'], 422);
    }

    public function updateSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->updateSegment($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Segment updated')
            : $this->error($result['error'], 422);
    }

    public function deleteSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->deleteSegment($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Segment deleted')
            : $this->error($result['error'], 422);
    }

    public function segmentPreview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->evaluateSegment($this->uid(), (int) ($params['id'] ?? 0), [], 10);
        return $this->success([
            'count' => $result['count'] ?? 0,
            'preview' => array_slice($result['data'] ?? [], 0, 10),
        ]);
    }

    // ----- Suppressions -----

    public function suppressions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->suppressions(
            $this->uid(),
            ['type' => $this->get('type'), 'q' => $this->get('q')],
            (int) $this->get('page', 1),
            (int) $this->get('per_page', 50)
        );
        return $this->success($result);
    }

    public function addSuppression(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->contacts()->addSuppression(
            $this->uid(),
            (string) $this->get('email'),
            (string) $this->get('type', 'manual'),
            $this->get('reason')
        );
        return $result['success']
            ? $this->success([], 'Address added to the suppression list')
            : $this->error($result['error'], 422);
    }

    public function removeSuppression(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $this->contacts()->removeSuppression($this->uid(), (int) ($params['id'] ?? 0));
        return $this->success([], 'Address removed from the suppression list');
    }

    // ============================================================
    //  API: Templates
    // ============================================================

    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
            'variables' => EmailRenderer::variables(),
            'categories' => (new EmailTemplateEditorService())->categories(),
        ]);
    }

    /** معرض القوالب المدمجة + أنواع البلوكات للمحرر المرئي. */
    public function templateCatalog(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $editor = new EmailTemplateEditorService();
        return $this->success([
            'catalog' => $editor->catalog(),
            'categories' => $editor->categories(),
            'block_types' => $editor->blockTypes(),
        ]);
    }

    /** إنشاء قالب من معرض القوالب المدمجة. */
    public function createTemplateFromGallery(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->createFromCatalog($this->uid(), (string) $this->get('catalog_key', ''));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Template added to your templates')
            : $this->error($result['error'], 422);
    }

    /** تحويل بلوكات JSON إلى HTML (يستخدمه المحرر المرئي للمعاينة). */
    public function renderBlocks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $raw = (string) $this->get('blocks', '[]');
        $blocks = json_decode($raw, true);
        if (!is_array($blocks)) {
            return $this->error('Invalid block structure', 422);
        }
        $html = (new EmailTemplateEditorService())->blocksToHtml($blocks);
        return $this->success(['html' => $html]);
    }

    /** نسخ قالب مملوك. */
    public function duplicateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->duplicateTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Template duplicated')
            : $this->error($result['error'], 404);
    }

    /** تفعيل/إلغاء المشاركة العامة لقالب (body: {enabled: true/false}). */
    public function shareTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $enabled = filter_var($this->get('enabled', true), FILTER_VALIDATE_BOOLEAN);
        $result = (new EmailTemplateEditorService())->setShared($this->uid(), (int) ($params['id'] ?? 0), $enabled);
        if (!$result['success']) {
            return $this->error($result['error'], 404);
        }
        $url = '';
        if ($result['share_token']) {
            $base = rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.com', '/');
            $url = $base . '/email-marketing/templates/shared/' . rawurlencode((string) $result['share_token']);
        }
        return $this->success(['share_token' => $result['share_token'], 'share_url' => $url], $enabled ? 'Sharing enabled' : 'Sharing stopped');
    }

    /** جلب قالب مشترك عامًا (بدون تحقق ملكية). */
    public function getSharedTemplate(array $params = []): array
    {
        $shared = (new EmailTemplateEditorService())->byShareToken((string) ($params['token'] ?? ''));
        if (!$shared) {
            return $this->error('Shared template not found', 404);
        }
        return $this->success([
            'name' => $shared['name'],
            'subject' => $shared['subject'],
            'category' => $shared['category'],
            'blocks' => $shared['blocks'],
            'html' => $shared['html_body'],
        ]);
    }

    /** استيراد قالب مشترك إلى حساب المستخدم. */
    public function importSharedTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->importShared($this->uid(), (string) ($params['token'] ?? ''));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Template imported to your account')
            : $this->error($result['error'], 404);
    }

    /** نسخ حملة إلى مسودة جديدة. */
    public function duplicateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailTemplateEditorService())->duplicateCampaign($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Campaign duplicated')
            : $this->error($result['error'], 404);
    }

    public function createTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (trim((string) $this->get('name')) === '') {
            return $this->error('Template name is required', 422);
        }
        $template = new EmailTemplate([
            'user_id' => $this->uid(),
            'name' => trim((string) $this->get('name')),
            'subject' => (string) $this->get('subject', ''),
            'category' => $this->get('category') ? (string) $this->get('category') : null,
            'html_body' => (string) $this->get('html_body', ''),
            'blocks' => $this->get('blocks') !== null ? (string) $this->get('blocks') : null,
        ]);
        $id = $template->save();
        return $id ? $this->success(['id' => (int) $id], 'Template created') : $this->error('Could not save', 422);
    }

    public function getTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('Template not found', 404);
        }
        $row = $template->toArray();
        $row['blocks'] = json_decode((string) ($row['blocks'] ?? 'null'), true) ?: [];
        return $this->success($row);
    }

    public function updateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('Template not found', 404);
        }
        foreach (['name', 'subject', 'html_body', 'blocks', 'category'] as $field) {
            if ($this->get($field) !== null) {
                $template->setAttribute($field, (string) $this->get($field));
            }
        }
        $template->save();
        return $this->success([], 'Template updated');
    }

    public function deleteTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('Template not found', 404);
        }
        $template->delete();
        return $this->success([], 'Template deleted');
    }

    public function previewTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = (new EmailTemplate())->find((int) ($params['id'] ?? 0));
        if (!$template || (int) $template->getAttribute('user_id') !== $this->uid()) {
            return $this->error('Template not found', 404);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $template->getAttribute('html_body'),
            ['name' => 'Example user', 'first_name' => 'Example', 'email' => 'client@example.com', 'company_name' => 'Your company', 'campaign_name' => 'Test campaign']
        );
        return $this->success(['html' => $html, 'subject' => (string) $template->getAttribute('subject')]);
    }

    /** معاينة HTML عارض (قبل الحفظ) - يخصص المتغيرات فقط من غير تتبع. */
    public function previewHtml(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $renderer = new EmailRenderer();
        $html = $renderer->personalize(
            (string) $this->get('html', ''),
            ['name' => 'Example user', 'first_name' => 'Example', 'email' => 'client@example.com', 'company_name' => 'Your company', 'campaign_name' => 'Test campaign']
        );
        return $this->success(['html' => $html]);
    }

    // ============================================================
    //  API: Campaigns
    // ============================================================

    public function campaigns(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'campaigns' => $this->campaignService->list($this->uid()),
            'lists' => $this->listService->lists($this->uid()),
            'segments' => $this->contacts()->segments($this->uid()),
            'templates' => (new EmailTemplate())->where(['user_id' => $this->uid()], ['created_at' => 'DESC']),
        ]);
    }

    public function createCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Campaign created')
            : $this->error($result['error'], 422);
    }

    public function updateCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->update($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Campaign updated')
            : $this->error($result['error'], 404);
    }

    public function deleteCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Campaign deleted')
            : $this->error($result['error'], 404);
    }

    public function getCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaign = $this->campaignService->get($this->uid(), (int) ($params['id'] ?? 0));
        if (!$campaign) {
            return $this->error('Campaign not found', 404);
        }
        return $this->success($campaign);
    }

    /**
     * إرسال فوري: يجهّز المستلمين ثم يدفع مهمة في طابور الإرسال.
     */
    public function sendCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $campaignId = (int) ($params['id'] ?? 0);
        $campaign = $this->campaignService->get($this->uid(), $campaignId);
        if (!$campaign) {
            return $this->error('Campaign not found', 404);
        }
        if (!in_array($campaign['status'], ['draft', 'scheduled', 'sending', 'failed', 'partial', 'uncertain'], true)) {
            return $this->error('A campaign in this status cannot be sent', 422);
        }

        // حماية السمعة: الحساب موقوف أو محتاج مراجعة => رسالة واضحة بدل ما الحملة تفضل معلّقة
        $pre = $this->campaignService->guard()->preflight($this->uid(), $campaignId);
        if (!$pre['allowed']) {
            return $this->error($pre['message'], 403);
        }

        // حملة فشلت بالكامل: إعادة تعيين المستلمين الفاشلين إلى pending
        if (in_array($campaign['status'],['failed','partial','uncertain'],true)) {
            $retried = $this->campaignService->retryFailed($this->uid(), $campaignId);
            if (!$retried['success']) {
                return $this->error($retried['error'], 422);
            }
        }

        $prepared = $this->campaignService->prepareRecipients($this->uid(), $campaignId);
        if (!$prepared['success']) {
            return $this->error($prepared['error'], 422);
        }
        if ($prepared['total'] <= 0) {
            return $this->error('No active subscribers in the target audience', 422);
        }

        if (!class_exists('QueueManager')) {
            return $this->error('The queue system is unavailable', 500);
        }
        $queue = new QueueManager();
        $queue->push(SendEmailCampaignBatchJob::class, [
            'user_id' => $this->uid(),
            'campaign_id' => $campaignId,
        ], 'email');

        $this->db->query(
            "UPDATE email_campaigns SET status = 'sending' WHERE id = ?",
            [$campaignId]
        );

        return $this->success(['total' => $prepared['total']], 'Campaign sending started (' . $prepared['total'] . ' recipients) - it will be sent via cron');
    }

    /** POST /api/email-marketing/campaigns/{id}/pause - إيقاف يدوي لحملة قيد الإرسال */
    public function pauseCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $r = $this->campaignService->guard()->userPauseCampaign($this->uid(), (int) ($params['id'] ?? 0));
        return $r['success'] ? $this->success([], 'Campaign paused') : $this->error($r['error'], 422);
    }

    /** POST /api/email-marketing/campaigns/{id}/resume - استئناف حملة أوقفها العميل بنفسه */
    public function resumeCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $r = $this->campaignService->guard()->userResumeCampaign($this->uid(), (int) ($params['id'] ?? 0));
        return $r['success'] ? $this->success([], 'Campaign resumed') : $this->error($r['error'], 422);
    }

    /** GET /api/email-marketing/domain-check?domain=&selector= - فحص SPF/DKIM/DMARC لدومين الإرسال */
    public function domainCheck(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $domain = strtolower(trim((string) $this->get('domain')));
        if (!DomainAuthChecker::isValidDomain($domain)) {
            return $this->error('Invalid domain', 422);
        }
        $selector = trim((string) $this->get('selector'));
        $result = (new DomainAuthChecker())->check($domain, $selector !== '' ? [$selector] : []);
        return $this->success($result, 'Domain authentication check');
    }

    /** GET /api/email-marketing/contacts/subscribers/{id}/export-data - تصدير بيانات مشترك (GDPR) */
    public function exportSubscriberData(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $data = (new EmailComplianceService())->exportSubscriberData($this->uid(), (int) ($params['id'] ?? 0), $this->uid());
        return $data ? $this->success($data) : $this->error('Subscriber not found', 404);
    }

    /** DELETE /api/email-marketing/contacts/subscribers/{id}/erase?keep_suppression=1 - محو مشترك (GDPR) */
    public function eraseSubscriber(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $keep = (string) $this->get('keep_suppression', '1') !== '0';
        $r = (new EmailComplianceService())->eraseSubscriber($this->uid(), (int) ($params['id'] ?? 0), $keep, $this->uid());
        return $r['success'] ? $this->success($r, 'Subscriber erased') : $this->error($r['error'], 404);
    }

    /** GET /api/email-marketing/contacts/subscribers/{id}/consent - سجل الموافقات */
    public function subscriberConsent(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success((new EmailComplianceService())->consentHistory($this->uid(), (int) ($params['id'] ?? 0)));
    }

    /** GET /api/email-marketing/compliance - جاهزية الحساب قانونيًا */
    public function complianceStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success((new EmailComplianceService())->sendReadiness($this->uid()));
    }

    /** GET /api/email-marketing/quota - عدّاد استهلاك الإرسال + حالة الحساب للعميل */
    public function quota(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success($this->campaignService->guard()->snapshot($this->uid()), 'Sending quota');
    }

    public function sendTestEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $toEmail = (string) $this->get('email');
        $result = $this->campaignService->sendTest($this->uid(), (int) ($params['id'] ?? 0), $toEmail);
        return $result['success']
            ? $this->success([], 'Test email sent')
            : $this->error($result['error'] ?? 'Could not send', 422);
    }

    public function scheduleCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->schedule($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('scheduled_at'));
        return $result['success']
            ? $this->success([], 'Campaign scheduled')
            : $this->error($result['error'], 422);
    }

    public function cancelCampaign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->campaignService->cancel($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Scheduled campaign cancelled')
            : $this->error($result['error'], 404);
    }

    public function campaignReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $report = $this->campaignService->report($this->uid(), (int) ($params['id'] ?? 0));
        if (!$report) {
            return $this->error('Campaign not found', 404);
        }
        $report['recipients'] = $this->db->query(
            "SELECT r.id, r.email, r.name, r.status, r.opened_at, r.clicked_at, r.open_count, r.click_count, r.error_message,
                    s.unsubscribe_token
             FROM email_campaign_recipients r
             LEFT JOIN email_subscribers s ON s.id = r.subscriber_id
             WHERE r.campaign_id = ?
             ORDER BY r.id ASC
             LIMIT 200",
            [$report['id']]
        );
        return $this->success($report);
    }

    // ============================================================
    //  API: Automations (المرحلة 3)
    // ============================================================

    private function automationService(): EmailAutomationService
    {
        return new EmailAutomationService();
    }

    private function smtpSettingsService(): SmtpSettingsService
    {
        return new SmtpSettingsService();
    }

    private function transactionalService(): TransactionalEmailService
    {
        return new TransactionalEmailService();
    }

    private function abTestService(): AbTestService
    {
        return new AbTestService();
    }

    public function automations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'automations' => $this->automationService()->list($this->uid()),
            'triggers' => EmailAutomation::triggers(),
            'step_types' => EmailAutomationStep::types(),
        ]);
    }

    // ============================================================
    //  API: Platform integration (Bookings / CRM / Revenue attribution)
    // ============================================================

    public function platformIntegration(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $bridge = new EmailPlatformBridge();
        return $this->success([
            'settings' => $bridge->settings($this->uid()),
            'revenue' => $bridge->revenueSummary($this->uid()),
            'variables' => EmailPlatformBridge::variables(),
        ]);
    }

    public function savePlatformIntegration(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = (new EmailPlatformBridge())->saveSettings($this->uid(), $this->all());
        return $result['success']
            ? $this->success($result['settings'], 'Integration settings saved')
            : $this->error($result['error'], 422);
    }

    public function createAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Workflow created')
            : $this->error($result['error'], 422);
    }

    public function getAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $automation = $this->automationService()->get($this->uid(), (int) ($params['id'] ?? 0));
        if (!$automation) {
            return $this->error('Workflow not found', 404);
        }
        return $this->success($automation);
    }

    public function updateAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->update($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Workflow updated')
            : $this->error($result['error'], 422);
    }

    public function deleteAutomation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Workflow deleted')
            : $this->error($result['error'], 422);
    }

    public function setAutomationSteps(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->setSteps($this->uid(), (int) ($params['id'] ?? 0), (array) $this->get('steps', []));
        return $result['success']
            ? $this->success([], 'Steps saved')
            : $this->error($result['error'], 422);
    }

    public function setAutomationStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->setStatus($this->uid(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
        return $result['success']
            ? $this->success([], 'Status updated')
            : $this->error($result['error'], 422);
    }

    public function runAutomationsDue(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->automationService()->processDue();
        return $this->success($result, 'Upcoming flows processed');
    }

    // ============================================================
    //  API: SMTP Settings
    // ============================================================

    public function smtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $svc = $this->smtpSettingsService();
        $settings = $svc->get($this->uid());
        if ($settings) {
            unset($settings['password']);
        }
        return $this->success([
            'settings' => $settings,
            'effective' => $this->safeEffectiveSettings($svc),
        ]);
    }

    public function saveSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->save($this->uid(), $this->all());
        return $result['success']
            ? $this->success([], 'Settings saved for SMTP')
            : $this->error($result['error'], 422);
    }

    // ============================================================
    //  API: SMTP Delivery Webhook (بند 1 - تتبع الارتدادات/الشكاوى)
    // ============================================================

    /**
     * GET /api/email-marketing/smtp/webhook
     * حالة + رابط webhook تتبع التسليم (المفتاح يعرض مقنّعًا - النص الكامل
     * بيتكشف لحظة التوليد/التجديد بس).
     */
    public function deliveryWebhookSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $settings = $this->smtpSettingsService()->get($uid);
        $enabled = (bool) ($settings['delivery_webhook_enabled'] ?? false);
        $secret = trim((string) ($settings['delivery_webhook_secret'] ?? ''));
        $url = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/webhooks/email/delivery-status/' . $uid;

        return $this->success([
            'enabled' => $enabled,
            'url' => $url,
            'has_secret' => $secret !== '',
            'secret_masked' => $this->maskWebhookSecret($secret),
        ]);
    }

    /**
     * POST /api/email-marketing/smtp/webhook
     * تفعيل/تعطيل الـ webhook + توليد/تجديد المفتاح السري (regenerate).
     */
    public function saveDeliveryWebhook(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->saveDeliveryWebhook($this->uid(), $this->all());
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Could not save the settings of webhook', 422);
        }
        return $this->success([
            'enabled' => (bool) $result['enabled'],
            'secret' => $result['secret'] ?? '',
            'secret_masked' => $this->maskWebhookSecret((string) ($result['secret'] ?? '')),
        ], 'Delivery tracking webhook settings saved');
    }

    /** إخفاء المفتاح السري (أول 4 + آخر 4) — يظهر كاملًا لحظة التوليد فقط. */
    private function maskWebhookSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return strlen($secret) > 8
            ? substr($secret, 0, 4) . '…' . substr($secret, -4)
            : '••••••';
    }

    public function testSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $data = $this->all();
        $result = $this->smtpSettingsService()->test($this->uid(), $data ?: null);
        return $result['success']
            ? $this->success([], 'Connected and authenticated successfully')
            : $this->error('Test failed: ' . ($result['error'] ?? 'Unknown error'), 422);
    }

    public function deleteSmtpSettings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->smtpSettingsService()->delete($this->uid());
        return $result['success']
            ? $this->success([], $result['deleted'] ? 'Deleted settings of SMTP' : 'No saved settings')
            : $this->error('Could not delete the settings', 422);
    }

    // ============================================================
    //  API: Transactional Emails
    // ============================================================

    public function transactionalTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'templates' => $this->transactionalService()->listTemplates($this->uid()),
            'variables' => EmailRenderer::variables(),
        ]);
    }

    public function createTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->createTemplate($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Transactional template created')
            : $this->error($result['error'], 422);
    }

    public function getTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $template = $this->transactionalService()->getTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $template
            ? $this->success(['template' => $template])
            : $this->error('Template not found', 404);
    }

    public function updateTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->updateTemplate($this->uid(), (int) ($params['id'] ?? 0), $this->all());
        return $result['success']
            ? $this->success([], 'Template updated')
            : $this->error($result['error'], 422);
    }

    public function deleteTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->deleteTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Template deleted')
            : $this->error($result['error'], 422);
    }

    public function duplicateTransactionalTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->transactionalService()->duplicateTemplate($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Template duplicated')
            : $this->error($result['error'], 422);
    }

    public function sendTransactionalEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $body = $this->all();
        $result = $this->transactionalService()->send(
            $this->uid(),
            (int) ($body['template_id'] ?? 0),
            (string) ($body['to_email'] ?? ''),
            (array) ($body['data'] ?? []),
            (array) ($body['options'] ?? [])
        );
        return $result['success']
            ? $this->success(['id' => $result['id']], 'Transactional message sent')
            : $this->error('Submission failed: ' . ($result['error'] ?? 'Unknown error'), 422);
    }

    public function transactionalLogs(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $filters = array_filter([
            'status' => $this->get('status'),
            'template_id' => $this->get('template_id'),
            'email' => $this->get('email'),
            'limit' => $this->get('limit'),
        ], fn ($v) => $v !== null && $v !== '');
        return $this->success(['logs' => $this->transactionalService()->logs($this->uid(), $filters)]);
    }

    public function transactionalStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['stats' => $this->transactionalService()->stats($this->uid())]);
    }

    // ============================================================
    //  API: A/B Tests
    // ============================================================

    public function abTests(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success([
            'ab_tests' => $this->abTestService()->list($this->uid()),
            'campaigns' => $this->campaignService->list($this->uid()),
        ]);
    }

    public function createAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->create($this->uid(), $this->all());
        return $result['success']
            ? $this->success(['id' => $result['id']], 'A/B test created')
            : $this->error($result['error'], 422);
    }

    public function getAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $ab = $this->abTestService()->get($this->uid(), (int) ($params['id'] ?? 0));
        return $ab
            ? $this->success(['ab_test' => $ab])
            : $this->error('Test not found', 404);
    }

    public function deleteAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->delete($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Test deleted')
            : $this->error($result['error'], 422);
    }

    public function setAbTestVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $body = $this->all();
        $result = $this->abTestService()->setVariantContent(
            $this->uid(),
            (int) ($params['id'] ?? 0),
            (string) ($body['variant'] ?? ''),
            (array) $body
        );
        return $result['success']
            ? $this->success([], 'Variant updated')
            : $this->error($result['error'], 422);
    }

    public function startAbTest(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->start($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([
                'a' => $result['a'],
                'b' => $result['b'],
                'total' => $result['total'],
            ], 'Test started and audience split')
            : $this->error($result['error'], 422);
    }

    public function sendAbTestBatch(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $abTestId = (int) ($params['id'] ?? 0);
        $test = $this->abTestService()->get($this->uid(), $abTestId);
        if (!$test) {
            return $this->error('Test not found', 404);
        }
        if ($test['status'] !== 'running') {
            return $this->error('The test is not running', 422);
        }

        if (!class_exists('QueueManager')) {
            return $this->error('The queue system is unavailable', 500);
        }
        $queue = new QueueManager();
        $queue->push(SendAbTestBatchJob::class, [
            'user_id' => $this->uid(),
            'ab_test_id' => $abTestId,
        ], 'email');

        return $this->success(['queued' => true], 'A/B test batch sending started - it will be sent via cron');
    }

    /**
     * إرسال بريد اختبار لأحد متغيرات اختبار أ/ب (أو كلاهما) لمعاينة الشكل
     * قبل الإرسال الفعلي — دون التأثير على الجمهور.
     */
    public function sendAbTestTestEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $abTestId = (int) ($params['id'] ?? 0);
        $variant = (string) ($this->data['variant'] ?? 'all');
        $toEmail = trim((string) ($this->data['email'] ?? ''));
        $result = $this->abTestService()->sendTest($this->uid(), $abTestId, $variant, $toEmail);
        return $result['success']
            ? $this->success(['sent' => $result['sent']], 'Test messages sent successfully')
            : $this->error($result['error'] ?? 'Failed to send test messages', 422);
    }

    public function abTestReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $report = $this->abTestService()->report($this->uid(), (int) ($params['id'] ?? 0));
        return $report
            ? $this->success(['report' => $report])
            : $this->error('Test not found', 404);
    }

    public function declareAbTestWinner(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->declareWinner(
            $this->uid(),
            (int) ($params['id'] ?? 0),
            (string) $this->get('winner', '')
        );
        return $result['success']
            ? $this->success([], 'Winner declared')
            : $this->error($result['error'], 422);
    }

    public function applyAbTestWinner(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $result = $this->abTestService()->applyWinnerToBase($this->uid(), (int) ($params['id'] ?? 0));
        return $result['success']
            ? $this->success([], 'Winner copied to the main campaign')
            : $this->error($result['error'], 422);
    }

    /** GET /stats/timeseries?days=30 — بيانات الرسم البياني اليومي */
    public function statsTimeseries(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success((new EmailEngagementService())->timeseries($this->uid(), (int) $this->get('days', 30)));
    }

    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = $this->uid();
        $campaigns = $this->db->query(
            "SELECT c.id, c.name, c.status, c.total_recipients, c.sent_count, c.opened_count, c.clicked_count,
                    c.unsubscribed_count, c.bounced_count, c.created_at, c.sent_at
             FROM email_campaigns c WHERE c.user_id = ?
             ORDER BY c.created_at DESC",
            [$userId]
        );

        $sentTotal = 0;
        $openedTotal = 0;
        $clickedTotal = 0;
        $unsubTotal = 0;
        foreach ($campaigns as $c) {
            $sentTotal += (int) $c['sent_count'];
            $openedTotal += (int) $c['opened_count'];
            $clickedTotal += (int) $c['clicked_count'];
            $unsubTotal += (int) $c['unsubscribed_count'];
        }

        $subscribers = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_subscribers WHERE user_id = ? AND status = 'subscribed'",
            [$userId]
        )[0]['total'];

        return $this->success([
            'kpis' => [
                ['label' => 'Total sent', 'value' => $sentTotal, 'icon' => '📨'],
                ['label' => 'Times opened', 'value' => $openedTotal, 'icon' => '👁'],
                ['label' => 'Clicks', 'value' => $clickedTotal, 'icon' => '🖱'],
                ['label' => 'Unsubscribe', 'value' => $unsubTotal, 'icon' => '🚫'],
                ['label' => 'Active subscribers', 'value' => $subscribers, 'icon' => '👥'],
            ],
            'open_rate' => $sentTotal > 0 ? round($openedTotal / $sentTotal * 100, 1) : 0,
            'click_rate' => $sentTotal > 0 ? round($clickedTotal / $sentTotal * 100, 1) : 0,
            'campaigns' => $campaigns,
        ]);
    }

    // ============================================================
    //  Public tracking endpoints (من غير Auth - بيطلبها عملاء البريد)
    // ============================================================

    public function trackOpen(array $params = []): array
    {
        $raw = (string) ($params['token'] ?? '');
        $token = preg_replace('/\.gif$/', '', $raw);

        if ($token === '') {
            $this->gif();
            exit;
        }
        $this->trackingService->recordOpen(
            $token,
            isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null
        );
        $this->trackingService->recordTransactionalOpen($token);
        $this->trackingService->recordAutomationOpen($token);
        $this->gif();
        exit;
    }

    public function trackClick(array $params = []): array
    {
        $clickToken = (string) ($params['click_token'] ?? '');
        $encoded = (string) $this->get('u', '');
        $url = $this->trackingService->recordClick($clickToken, $encoded);
        if ($url === null) {
            $url = $this->trackingService->recordTransactionalClick($clickToken, $encoded);
        }
        if ($url === null) {
            $url = $this->trackingService->recordAutomationClick($clickToken, $encoded);
        }

        if ($url === null) {
            http_response_code(404);
            echo 'Invalid tracking link.';
            exit;
        }
        // روابط موقع صاحب الحملة بتاخد tf_em عشان الحجز اللي يجي منها يتنسب للحملة
        if (class_exists('EmailPlatformBridge')) {
            $url = (new EmailPlatformBridge())->decorateClickUrl($clickToken, $url);
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    public function unsubscribeLink(array $params = []): array
    {
        $token = (string) ($params['unsubscribe_token'] ?? '');
        $this->trackingService->unsubscribe($token);

        // RFC 8058 one-click: عملاء البريد (Gmail/Yahoo) يرسلون POST
        // ويتوقعون استجابة 2xx بسيطة بجسم قصير - لا صفحة HTML كاملة.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo '1';
            exit;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="ar"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Unsubscribe</title>'
            . '<body style="margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;background:#f9fafb;color:#111827;">'
            . '<div style="text-align:center;padding:32px;max-width:420px;background:#fff;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);">'
            . '<div style="font-size:44px;">🙏</div>'
            . '<h2 style="margin:16px 0 8px;">You have been unsubscribed successfully</h2>'
            . '<p style="color:#6b7280;font-size:14px;">You will no longer receive marketing messages from this list. If you unsubscribed by mistake you can subscribe again any time.</p>'
            . '</div></body></html>';
        exit;
    }

    // ============================================================
    //  Helpers
    // ============================================================

    private function deliveryStatus(): array
    {
        $svc = new SmtpSettingsService();
        $ready = $svc->isReady($this->uid());
        $effective = $svc->settingsForUser($this->uid());
        $from = $effective['from_email'] ?: '—';
        return [
            'provider' => 'smtp',
            'smtp' => $ready,
            'from_email' => $from,
            'label' => $ready
                ? "Send via SMTP ({$effective['host']} — of {$from})"
                : 'Not configured - set the SMTP settings from the Settings tab',
        ];
    }

    private function safeEffectiveSettings(SmtpSettingsService $svc): array
    {
        $settings = $svc->settingsForUser($this->uid());
        $settings['password'] = $settings['password'] !== ''
            ? str_repeat('•', min(10, strlen($settings['password'])))
            : '';
        $settings['ready'] = $svc->isReady($this->uid());
        return $settings;
    }

    private function parseImportData(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // JSON array أولًا
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // غير كده فسر كـ CSV: email,name في كل سطر
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = str_getcsv($line);
            if (isset($parts[0])) {
                $rows[] = ['email' => $parts[0], 'name' => $parts[1] ?? ''];
            }
        }
        return $rows;
    }

    private function gif(): void
    {
        header('Content-Type: image/gif');
        header('Content-Length: 43');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    private function emailTabsHtml(string $active): string
    {
        $tabs = [
            'dashboard' => ['/email-marketing', '📊 Dashboard'],
            'lists' => ['/email-marketing/lists', '👥 Audience'],
            'contacts' => ['/email-marketing/contacts', '📇 Contacts'],
            'templates' => ['/email-marketing/templates', '🎨 Templates'],
            'campaigns' => ['/email-marketing/campaigns', '🚀 Campaigns'],
            'automations' => ['/email-marketing/automations', '⚙️ Automation'],
            'ab_tests' => ['/email-marketing/ab-tests', '🧪 A/B test'],
            'transactional' => ['/email-marketing/transactional', '📨 Transactional'],
            'settings' => ['/email-marketing/settings', '🔌 Settings'],
            'reports' => ['/email-marketing/reports', '📈 Reports'],
        ];
        $html = '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">';
        foreach ($tabs as $key => [$href, $label]) {
            $activeClass = $key === $active ? 'p-btn primary xs' : 'p-btn xs';
            $html .= "<a href=\"{$href}\" class=\"{$activeClass}\">{$label}</a>";
        }
        $html .= '</div>';
        return $html;
    }

    // ============================================================
    //  Page scripts
    // ============================================================

    private function indexJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadDashboard() {
            const r = await emApi('/dashboard');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emKpis').innerHTML = kpis;

            const d = r.data.delivery;
            document.getElementById('emDeliveryStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">${d.smtp ? '📧' : '⚠️'}</span>
                    <span style="font-size:14px;">${d.label}</span>
                </div>`;

            const cs = r.data.recent_campaigns || [];
            document.getElementById('emRecentCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Campaign</th><th>Status</th><th>Sender</th><th>Opens</th><th>Click</th><th></th></tr></thead>
                    <tbody>${cs.map(c => `
                        <tr>
                            <td>${emText(c.name)}</td>
                            <td>${statusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">Report</a></td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No campaigns yet - create your first campaign from the Campaigns tab.</p>';

            const ls = r.data.top_lists || [];
            document.getElementById('emTopLists').innerHTML = ls.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>List</th><th>Subscribers</th><th></th></tr></thead>
                    <tbody>${ls.map(l => `
                        <tr><td>${emText(l.name)}</td><td>${l.actual_count}</td>
                        <td><a class="p-btn xs" href="/email-marketing/lists">Manage</a></td></tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">Create your first audience list from the Audience tab.</p>';
        }
        function statusBadge(s) {
            const map = {draft:'Draft', scheduled:'Scheduled', sending:'Sending', sent:'Sent', cancelled:'Cancelled', failed:'Failed',partial:'Partially completed',uncertain:'Outcome needs review'};
            return `<span style="background:#f3f4f6;padding:2px 10px;border-radius:20px;font-size:12px;">${emText(map[s] || s)}</span>`;
        }
        loadDashboard();
        JS;
    }

    private function listsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        let emLists = [];
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadLists() {
            const r = await emApi('/lists');
            if (!r.success) return;
            emLists = r.data.lists;
            const sel = document.getElementById('emSubListFilter');
            sel.innerHTML = '<option value="0">All lists</option>' + emLists.map(l =>
                `<option value="${l.id}">${emText(l.name)} (${l.actual_count})</option>`).join('');
            const sl = document.getElementById('subList');
            sl.innerHTML = '<option value="0">No list</option>' + emLists.map(l =>
                `<option value="${l.id}">${emText(l.name)}</option>`).join('');
            const il = document.getElementById('importList');
            il.innerHTML = '<option value="0">No list</option>' + emLists.map(l =>
                `<option value="${l.id}">${emText(l.name)}</option>`).join('');
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">Choose a list</option>' + emLists.map(l =>
                `<option value="${l.id}">${emText(l.name)} (${l.actual_count})</option>`).join('');

            document.getElementById('emListsTable').innerHTML = emLists.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Name</th><th>Description</th><th>Subscribers</th><th>Created</th><th></th></tr></thead>
                    <tbody>${emLists.map(l => `
                        <tr>
                            <td><b>${emText(l.name)}</b></td>
                            <td style="color:#6b7280;">${emText(l.description || '—')}</td>
                            <td>${l.actual_count}</td>
                            <td style="color:#6b7280;">${(l.created_at || '').slice(0,10)}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                <button class="p-btn xs" onclick="editList(${Number(l.id)})">Edit</button>
                                <button class="p-btn xs danger" onclick="removeList(${l.id})">Delete</button>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No lists yet.</p>';
            loadSubscribers();
        }
        function openListModal(id, name, desc) {
            document.getElementById('listModalTitle').textContent = id ? 'Edit list' : 'New list';
            document.getElementById('listId').value = id || '';
            document.getElementById('listName').value = name || '';
            document.getElementById('listDesc').value = desc || '';
            document.getElementById('listModal').classList.add('open');
        }
        function editList(id) {
            const list = emLists.find(l => Number(l.id) === Number(id));
            if (list) openListModal(id, list.name, list.description);
        }
        async function saveList() {
            const id = document.getElementById('listId').value;
            const name = document.getElementById('listName').value.trim();
            if (!name) { notify('List name is required'); return; }
            const path = id ? '/lists/' + id : '/lists';
            const opts = {
                method: id ? 'PATCH' : 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({name, description: document.getElementById('listDesc').value})
            };
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('listModal').classList.remove('open'); loadLists(); }
            else notify(r.error || 'Error');
        }
        async function removeList(id) {
            if (!confirm('Delete this list? (The subscribers themselves are not deleted)')) return;
            const r = await emApi('/lists/' + id, {method: 'DELETE'});
            if (r.success) loadLists(); else notify(r.error);
        }
        let emSubPage = 1;
        async function loadSubscribers() {
            const listId = document.getElementById('emSubListFilter').value;
            const r = await emApi('/subscribers?list_id=' + listId + '&page=' + emSubPage + '&per_page=50');
            if (!r.success) return;
            const d = r.data;
            document.getElementById('emSubCountLabel').textContent = '(' + d.total + ')';
            const rows = (d.data || []).map(s => `
                <tr>
                    <td>${s.email}</td>
                    <td>${emText(s.name || '—')}</td>
                    <td>${subStatusBadge(s.status)}</td>
                    <td>${s.list_count} Lists</td>
                    <td style="text-align:left;white-space:nowrap;">
                        ${s.status === 'subscribed'
                            ? `<button class="p-btn xs" onclick="unsubSub(${s.id})">Unsubscribe</button>` : ''}
                        <button class="p-btn xs danger" onclick="delSub(${s.id})">Delete</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emSubscribersTable').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Lists</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No subscribers.</p>';
            const pages = Math.max(1, Math.ceil(d.total / d.per_page));
            document.getElementById('emSubscribersPager').innerHTML = `
                <button class="p-btn xs" ${emSubPage <= 1 ? 'disabled' : ''} onclick="emSubPage--;loadSubscribers()">Previous</button>
                <span style="margin:0 10px;font-size:13px;">Page ${emSubPage} of ${pages}</span>
                <button class="p-btn xs" ${emSubPage >= pages ? 'disabled' : ''} onclick="emSubPage++;loadSubscribers()">Next</button>`;
        }
        function subStatusBadge(s) {
            const map = {subscribed:'Active', unsubscribed:'Unsubscribed', bounced:'Bounce'};
            return `<span style="background:${s==='subscribed'?'#dcfce7':s==='unsubscribed'?'#fee2e2':'#fef3c7'};padding:2px 10px;border-radius:20px;font-size:12px;">${emText(map[s]||s)}</span>`;
        }
        function openSubscriberModal() {
            document.getElementById('subEmail').value = '';
            document.getElementById('subName').value = '';
            document.getElementById('subscriberModal').classList.add('open');
        }
        async function saveSubscriber() {
            const body = {
                email: document.getElementById('subEmail').value.trim(),
                name: document.getElementById('subName').value.trim(),
                list_id: document.getElementById('subList').value
            };
            const r = await emApi('/subscribers', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            if (r.success) { document.getElementById('subscriberModal').classList.remove('open'); loadSubscribers(); }
            else notify(r.error);
        }
        function openImportModal() { document.getElementById('importData').value = ''; document.getElementById('importResult').innerHTML=''; document.getElementById('importModal').classList.add('open'); }
        async function importSubscribers() {
            const body = {list_id: document.getElementById('importList').value, data: document.getElementById('importData').value};
            const r = await emApi('/subscribers/import', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            document.getElementById('importResult').innerHTML = r.success
                ? `✅ Imported: ${r.data.added} new, ${r.data.updated} updated, ${r.data.invalid} invalid, ${r.data.rejected || 0} rejected, ${r.data.skipped_opted_out || 0} skipped (unsubscribed before)`
                : '❌ ' + (r.error || 'Error');
            if (r.success) setTimeout(loadSubscribers, 600);
        }
        async function previewImport() {
            const body = {data: document.getElementById('importData').value};
            const r = await emApi('/subscribers/import-preview', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
            const box = document.getElementById('importResult');
            if (!r.success) { box.innerHTML = '❌ ' + emText(r.error || 'Error'); return; }
            const c = r.data.counts;
            const labels = {new:'New', update:'Already in list (updated)', invalid:'Invalid', duplicate_in_file:'Duplicate in file', opted_out:'Unsubscribed before (skipped)', rejected:'Rejected (disposable/blocked)'};
            const colors = {new:'#16a34a', update:'#2563eb', invalid:'#dc2626', duplicate_in_file:'#9ca3af', opted_out:'#d97706', rejected:'#dc2626'};
            const chips = Object.keys(labels).map(k => `<span style="display:inline-block;margin:2px 6px 2px 0;padding:2px 10px;border-radius:20px;background:#f3f4f6;"><b style="color:${colors[k]}">${c[k] || 0}</b> ${labels[k]}</span>`).join('');
            const rows = (r.data.sample || []).map(x => `<tr><td>${emText(x.email)}</td><td>${emText(x.name)}</td><td style="color:${colors[x.verdict] || '#111'}">${emText(labels[x.verdict] || x.verdict)}${x.reason ? ' · ' + emText(x.reason) : ''}</td></tr>`).join('');
            box.innerHTML = `<div style="margin-bottom:6px;">${c.total} rows${c.risky ? ` · ${c.risky} risky (role/typo)` : ''}</div>${chips}
                <div style="max-height:220px;overflow:auto;margin-top:8px;"><table class="p-table" style="font-size:12px;"><thead><tr><th>Email</th><th>Name</th><th>Result</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        }
        async function unsubSub(id) {
            if (!confirm('Unsubscribe this subscriber permanently?')) return;
            const r = await emApi('/subscribers/' + id + '/unsubscribe', {method:'POST'});
            if (r.success) loadSubscribers(); else notify(r.error);
        }
        async function delSub(id) {
            if (!confirm('Delete this subscriber permanently?')) return;
            const r = await emApi('/subscribers/' + id, {method:'DELETE'});
            if (r.success) loadSubscribers(); else notify(r.error);
        }
        loadLists();
        JS;
    }

    private function templatesJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        let activeTemplatesTab = 'mine';
        const CATEGORY_LABELS = {'welcome':'Welcome','newsletter':'Newsletter','promo':'Promotional','event':'Events','transactional':'Transactional','holiday':'Occasions'};
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadTemplates() {
            const r = await emApi('/templates');
            if (!r.success) return;
            const list = r.data.templates || [];
            document.getElementById('emTemplatesGrid').innerHTML = list.length ? `
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">
                    ${list.map(t => `
                        <div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:6px;">
                            <div style="font-weight:600;display:flex;align-items:center;gap:6px;">
                                ${emText(t.name)}
                                ${t.category ? `<span class="em-tag-pill" style="font-size:10px;">${CATEGORY_LABELS[t.category] || t.category}</span>` : ''}
                                ${t.share_token ? '<span class="em-tag-pill" style="font-size:10px;background:#064e3b;">Shared</span>' : ''}
                            </div>
                            <div style="font-size:12px;color:#6b7280;">${emText(t.subject || 'No subject')}</div>
                            <div style="font-size:11px;color:#9ca3af;">${(t.html_body || '').length} characters${t.blocks ? ' · Blocks' : ''}</div>
                            <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">
                                <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?template_id=${t.id}'">🎨 Edit</button>
                                <button class="p-btn xs" onclick="previewT(${t.id})">👁 Preview</button>
                                <button class="p-btn xs" onclick="duplicateTemplate(${t.id})">⧉ Copy</button>
                                <button class="p-btn xs" onclick="shareTemplate(${t.id})">🔗</button>
                                <button class="p-btn xs danger" onclick="removeTemplate(${t.id})">Delete</button>
                            </div>
                        </div>`).join('')}
                </div>` : '<p class="p-cell-muted" style="padding:16px;">No templates - use the gallery or the visual editor to build the first template.</p>';
        }
        async function loadGallery() {
            const r = await emApi('/templates/catalog');
            if (!r.success) return;
            const catalog = Object.entries(r.data.catalog || {});
            const cats = [['all','All'], ...Object.entries(r.data.categories || {})];
            let filter = 'all';
            document.getElementById('emGalleryGrid').innerHTML = `
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                    ${cats.map(([k,v]) => `<button class="p-btn xs ${filter===k?'primary':''}" data-cat="${k}" onclick="filterGallery('${k}')">${v}</button>`).join('')}
                </div>
                <div id="emGalleryCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;">
                    ${catalog.map(([key,item]) => `
                        <div class="gallery-card" data-cat="${item.category}" style="border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;">
                            <div style="height:120px;background:linear-gradient(135deg,#1e3a8a,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;padding:10px;text-align:center;">${emText(item.name)}</div>
                            <div style="padding:12px;display:flex;flex-direction:column;gap:6px;flex:1;">
                                <div style="font-size:12px;color:#6b7280;">${emText(item.description)}</div>
                                <div style="font-size:11px;color:#9ca3af;">${CATEGORY_LABELS[item.category] || item.category}</div>
                                <div style="margin-top:auto;display:flex;gap:6px;">
                                    <button class="p-btn primary xs" onclick="useGallery('${key}')">Use template</button>
                                    <button class="p-btn xs" onclick="window.location.href='/email-marketing/templates/builder?gallery=${key}'">🎨 Personalization</button>
                                </div>
                            </div>
                        </div>`).join('')}
                </div>`;
            window.galleryCat = 'all';
        }
        function filterGallery(cat) {
            window.galleryCat = cat;
            document.querySelectorAll('.gallery-card').forEach(c => {
                c.style.display = (cat === 'all' || c.dataset.cat === cat) ? '' : 'none';
            });
            document.querySelectorAll('#emGalleryGrid [data-cat]').forEach(b => b.classList.toggle('primary', b.dataset.cat === cat));
        }
        function switchTemplatesTab(tab) {
            activeTemplatesTab = tab;
            document.getElementById('emTemplatesGrid').style.display = tab === 'mine' ? '' : 'none';
            document.getElementById('emGalleryGrid').style.display = tab === 'gallery' ? '' : 'none';
            if (tab === 'gallery' && !window.galleryLoaded) { window.galleryLoaded = true; loadGallery(); }
            const btns = document.querySelectorAll('button[onclick^="switchTemplatesTab"]');
            btns.forEach(b => b.classList.toggle('primary', b.textContent.includes(tab === 'mine' ? 'Your templates' : 'Gallery')));
        }
        async function useGallery(key) {
            const r = await emApi('/templates/from-gallery', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({catalog_key: key})});
            if (r.success) { notify('Template added to your templates'); loadTemplates(); switchTemplatesTab('mine'); }
            else notify(r.error);
        }
        async function duplicateTemplate(id) {
            const r = await emApi('/templates/' + id + '/duplicate', {method:'POST'});
            if (r.success) loadTemplates(); else notify(r.error);
        }
        let currentShareId = null;
        async function shareTemplate(id) {
            const r = await emApi('/templates/' + id + '/share', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({enabled:true})});
            if (r.success) {
                currentShareId = id;
                document.getElementById('shareUrl').value = window.location.origin + '/email-marketing/templates/shared/' + (r.data.share_token || '');
                document.getElementById('shareModal').classList.add('open');
                loadTemplates();
            } else notify(r.error);
        }
        async function stopSharing() {
            if (!currentShareId) return;
            const r = await emApi('/templates/' + currentShareId + '/share', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({enabled:false})});
            if (r.success) { document.getElementById('shareModal').classList.remove('open'); loadTemplates(); }
            else notify(r.error);
        }
        function copyShareUrl() {
            const el = document.getElementById('shareUrl');
            el.select(); document.execCommand('copy');
            notify('Link copied');
        }
        function openTemplateModal(id, name, subject, html) {
            document.getElementById('templateModalTitle').textContent = id ? 'Edit template' : 'New template';
            document.getElementById('templateId').value = id || '';
            document.getElementById('templateName').value = name || '';
            document.getElementById('templateSubject').value = subject || '';
            document.getElementById('templateHtml').value = html || '';
            document.getElementById('templateModal').classList.add('open');
        }
        function editTemplate(id) {
            fetch('/api/email-marketing/templates/' + id).then(r=>r.json()).then(r => {
                if (r.success) openTemplateModal(r.data.id, r.data.name, r.data.subject, r.data.html_body);
            });
        }
        function insertVar(v) {
            const ta = document.getElementById('templateHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveTemplate() {
            const id = document.getElementById('templateId').value;
            const body = {
                name: document.getElementById('templateName').value.trim(),
                subject: document.getElementById('templateSubject').value,
                html_body: document.getElementById('templateHtml').value
            };
            const path = id ? '/templates/' + id : '/templates';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (r.success) { document.getElementById('templateModal').classList.remove('open'); loadTemplates(); }
            else notify(r.error);
        }
        async function previewT(id) {
            const r = await emApi('/templates/' + id + '/preview', {method:'POST'});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">Topic: <b>${emText(r.data.subject)}</b></div>
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function previewTemplateInline() {
            const r = await emApi('/templates/preview-html', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({html: document.getElementById('templateHtml').value})});
            if (!r.success) return;
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(r.data.html)}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function removeTemplate(id) {
            if (!confirm('Delete this template?')) return;
            const r = await emApi('/templates/' + id, {method:'DELETE'});
            if (r.success) loadTemplates(); else notify(r.error);
        }
        loadTemplates();
        JS;
    }

    private function builderJs(string $initialBlocks, int $saveTarget): string
    {
        $blocks = str_replace('</', '<\\/', (string) $initialBlocks);
        $target = $saveTarget;
        $js = <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        const INITIAL_BLOCKS = __INITIAL_BLOCKS__;
        const SAVE_TARGET = __SAVE_TARGET__;
        let bdBlocks = [];
        let bdSelected = -1;
        const BLOCK_TYPES = [
            {type:'text', label:'Text', icon:'📝'},
            {type:'heading', label:'Heading', icon:'🅷'},
            {type:'image', label:'Image', icon:'🖼'},
            {type:'button', label:'Button', icon:'🔘'},
            {type:'divider', label:'Divider', icon:'➖'},
            {type:'spacer', label:'Spacer', icon:'⬜'},
            {type:'social', label:'Social', icon:'🌐'},
            {type:'video', label:'Video', icon:'🎬'},
            {type:'columns', label:'Two columns (trip cards)', icon:'🧳'},
            {type:'html', label:'Code HTML', icon:'💻'}
        ];
        const FIELD_DEFS = {
            text: [ {k:'content', label:'Content', type:'textarea'} ],
            heading: [ {k:'text', label:'Text', type:'text'}, {k:'level', label:'Size', type:'select', opts:[['h1','Heading 1'],['h2','Heading 2'],['h3','Heading 3'],['h4','Heading 4']]}, {k:'align', label:'Alignment', type:'select', opts:[['right','Right'],['center','Center'],['left','Left']]} ],
            image: [ {k:'src', label:'Image URL', type:'text'}, {k:'alt', label:'Alt text', type:'text'}, {k:'width', label:'Width (px)', type:'number'}, {k:'url', label:'Link on click', type:'text'} ],
            button: [ {k:'text', label:'Button text', type:'text'}, {k:'url', label:'Link', type:'text'}, {k:'bg', label:'Background color', type:'color'}, {k:'color', label:'Text color', type:'color'} ],
            divider: [ {k:'color', label:'Color', type:'color'}, {k:'thickness', label:'Thickness (px)', type:'number'} ],
            spacer: [ {k:'height', label:'Height (px)', type:'number'} ],
            social: [ {k:'networks', label:'Networks', type:'checkboxes', opts:['facebook','twitter','instagram','linkedin','youtube','whatsapp']},
                {k:'link_facebook', label:'Facebook page URL', type:'text'}, {k:'link_instagram', label:'Instagram URL', type:'text'},
                {k:'link_twitter', label:'X URL', type:'text'}, {k:'link_linkedin', label:'LinkedIn URL', type:'text'},
                {k:'link_youtube', label:'YouTube URL', type:'text'}, {k:'link_whatsapp', label:'WhatsApp link (wa.me/...)', type:'text'} ],
            video: [ {k:'url', label:'Video URL (YouTube/Vimeo/any)', type:'text'}, {k:'thumbnail', label:'Thumbnail URL (auto for YouTube)', type:'text'}, {k:'caption', label:'Caption', type:'text'} ],
            columns: [ {k:'left_image', label:'Left: image URL', type:'text'}, {k:'left_title', label:'Left: title', type:'text'}, {k:'left_text', label:'Left: text', type:'text'}, {k:'left_button', label:'Left: button text', type:'text'}, {k:'left_url', label:'Left: link', type:'text'},
                {k:'right_image', label:'Right: image URL', type:'text'}, {k:'right_title', label:'Right: title', type:'text'}, {k:'right_text', label:'Right: text', type:'text'}, {k:'right_button', label:'Right: button text', type:'text'}, {k:'right_url', label:'Right: link', type:'text'} ],
            html: [ {k:'html', label:'Code HTML', type:'textarea'} ]
        };
        const NET_LABELS = {facebook:'Facebook', twitter:'X', instagram:'Instagram', linkedin:'LinkedIn', youtube:'YouTube', whatsapp:'WhatsApp'};

        async function emApiBuilder(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        function defaultBlock(type) {
            const base = {
                text: {type:'text', content:'<p>Write your text here. You can insert variables like {{first_name}}.</p>'},
                heading: {type:'heading', text:'Main heading', level:'h2', align:'right'},
                image: {type:'image', src:'', alt:'', width:'600', url:''},
                button: {type:'button', text:'Click here', url:'https://example.com', bg:'#2563eb', color:'#ffffff'},
                divider: {type:'divider', color:'#e5e7eb', thickness:'1'},
                spacer: {type:'spacer', height:'24'},
                social: {type:'social', networks:['facebook','twitter','instagram','linkedin']},
                video: {type:'video', url:'', thumbnail:'', caption:''},
                columns: {type:'columns', left_image:'', left_title:'Trip one', left_text:'Short description', left_button:'Book now', left_url:'https://example.com',
                    right_image:'', right_title:'Trip two', right_text:'Short description', right_button:'Book now', right_url:'https://example.com'},
                html: {type:'html', html:'<div style="background:#f3f4f6;padding:16px;border-radius:8px;">Custom code</div>'}
            };
            return JSON.parse(JSON.stringify(base[type] || base.text));
        }
        function renderPalette() {
            document.getElementById('bdPalette').innerHTML = BLOCK_TYPES.map(bt =>
                `<button class="p-btn xs" draggable="true" ondragstart="bdDragStart(event,'add','${bt.type}')" style="justify-content:flex-start;text-align:right;cursor:grab;" onclick="addBlock('${bt.type}')">${bt.icon} ${bt.label}</button>`
            ).join('');
            // الإفلات في آخر القائمة
            const bar = document.getElementById('bdBlocksBar');
            bar.ondragover = e => e.preventDefault();
            bar.ondrop = e => { if (e.target === bar) bdDrop(e, bdBlocks.length); };
        }
        function addBlock(type, at) {
            const pos = typeof at === 'number' ? Math.max(0, Math.min(at, bdBlocks.length)) : bdBlocks.length;
            bdBlocks.splice(pos, 0, defaultBlock(type));
            bdSelected = pos;
            renderAll();
        }
        // سحب وإفلات (HTML5): من اللوحة = إضافة في المكان، من القائمة = إعادة ترتيب
        let bdDrag = null;
        function bdDragStart(e, kind, value) {
            bdDrag = {kind, value};
            try { e.dataTransfer.setData('text/plain', String(value)); e.dataTransfer.effectAllowed = kind === 'add' ? 'copy' : 'move'; } catch (err) {}
        }
        function bdDrop(e, index) {
            e.preventDefault();
            e.stopPropagation();
            document.querySelectorAll('.bd-row').forEach(r => r.style.borderTop = '');
            if (!bdDrag) return;
            if (bdDrag.kind === 'add') {
                addBlock(bdDrag.value, index);
            } else {
                const from = parseInt(bdDrag.value, 10);
                if (from === index || from + 1 === index) { bdDrag = null; return; }
                const [moved] = bdBlocks.splice(from, 1);
                const to = from < index ? index - 1 : index;
                bdBlocks.splice(to, 0, moved);
                bdSelected = to;
                renderAll();
            }
            bdDrag = null;
        }
        function moveBlock(i, dir) {
            const j = i + dir;
            if (j < 0 || j >= bdBlocks.length) return;
            [bdBlocks[i], bdBlocks[j]] = [bdBlocks[j], bdBlocks[i]];
            bdSelected = j;
            renderAll();
        }
        function removeBlock(i) {
            bdBlocks.splice(i, 1);
            if (bdSelected >= bdBlocks.length) bdSelected = bdBlocks.length - 1;
            renderAll();
        }
        function selectBlock(i) {
            bdSelected = i;
            renderAll();
        }
        function renderAll() {
            renderBlocksList();
            renderBlocks();
            renderInspector();
        }
        function renderBlocksList() {
            const wrap = document.getElementById('bdBlocksBar');
            if (!bdBlocks.length) { wrap.innerHTML = '<p class="p-cell-muted" style="padding:8px;">Add blocks from the sidebar.</p>'; return; }
            wrap.innerHTML = bdBlocks.map((b, i) => {
                const def = BLOCK_TYPES.find(t => t.type === b.type) || {label:b.type, icon:'🧩'};
                const sel = i === bdSelected ? 'background:#0b2436;border-color:#3b82f6;' : '';
                return `<div class="bd-row" draggable="true" ondragstart="bdDragStart(event,'move',${i})" ondragover="event.preventDefault();this.style.borderTop='3px solid #3b82f6'" ondragleave="this.style.borderTop=''" ondrop="bdDrop(event,${i})" style="display:flex;align-items:center;gap:8px;background:#0b2436;border:1px solid ${i===bdSelected?'#3b82f6':'#1f2937'};border-radius:8px;padding:6px 10px;cursor:grab;${sel}" onclick="selectBlock(${i})">
                    <span title="Drag to reorder">⠿</span>
                    <span>${def.icon}</span>
                    <span style="flex:1;font-size:13px;">${def.label} #${i+1}</span>
                    <button class="p-btn xs" onclick="event.stopPropagation();moveBlock(${i},-1)">↑</button>
                    <button class="p-btn xs" onclick="event.stopPropagation();moveBlock(${i},1)">↓</button>
                    <button class="p-btn xs danger" onclick="event.stopPropagation();removeBlock(${i})">×</button>
                </div>`;
            }).join('');
        }
        async function renderBlocks() {
            const r = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            const html = r.success ? r.data.html : '<div style="color:#f87171;padding:12px;">Could not show the preview</div>';
            // srcdoc بياخد HTML مش URL: encodeURI كان بيعرض النص كـ %3C...
            const srcdoc = String(html).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            document.getElementById('bdCanvas').innerHTML = `<iframe sandbox="" style="width:100%;min-height:520px;border:none;border-radius:8px;background:#fff;" srcdoc="${srcdoc}"></iframe>`;
        }
        function renderInspector() {
            const el = document.getElementById('bdInspector');
            if (bdSelected < 0 || bdSelected >= bdBlocks.length) { el.innerHTML = '<p class="p-cell-muted">Choose a block from the list to edit.</p>'; return; }
            const b = bdBlocks[bdSelected];
            const defs = FIELD_DEFS[b.type] || [];
            el.innerHTML = defs.map(f => {
                let val = b[f.k];
                if (f.type === 'checkboxes') {
                    const list = Array.isArray(val) ? val : [];
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label>` + f.opts.map(o =>
                        `<label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-bottom:4px;"><input type="checkbox" value="${o}" ${list.includes(o)?'checked':''} onchange="setField(${bdSelected},'${f.k}',Array.from(document.querySelectorAll('#bdInspector input[type=checkbox]:checked')).map(x=>x.value))"> ${NET_LABELS[o]||o}</label>`).join('');
                }
                if (f.type === 'select') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><select class="p-select" style="width:100%;margin-bottom:10px;" onchange="setField(${bdSelected},'${f.k}',this.value)">` + f.opts.map(o => `<option value="${o[0]}" ${String(val)===o[0]?'selected':''}>${o[1]}</option>`).join('') + '</select>';
                }
                if (f.type === 'color') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><input type="color" value="${val||'#000000'}" class="p-select" style="width:100%;height:36px;margin-bottom:10px;padding:2px;" onchange="setField(${bdSelected},'${f.k}',this.value)">`;
                }
                if (f.type === 'textarea') {
                    return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><textarea class="p-select" style="width:100%;min-height:110px;margin-bottom:10px;font-family:monospace;font-size:12px;" oninput="setField(${bdSelected},'${f.k}',this.value)">${String(val||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</textarea>`;
                }
                return `<label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">${f.label}</label><input type="${f.type}" value="${String(val||'').replace(/"/g,'&quot;')}" class="p-select" style="width:100%;margin-bottom:10px;" oninput="setField(${bdSelected},'${f.k}',this.value)">`;
            }).join('') + `<button class="p-btn xs danger" style="margin-top:6px;" onclick="removeBlock(${bdSelected})">Delete block</button>`;
        }
        function setField(i, k, v) {
            if (i < 0 || i >= bdBlocks.length) return;
            bdBlocks[i][k] = v;
            renderBlocks();
        }
        async function previewBuilder() {
            const r = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            if (!r.success) return;
            const name = document.getElementById('bdName').value;
            const subject = document.getElementById('bdSubject').value;
            window.open('', '_blank').document.write('<html><head><title>' + name + '</title></head><body>' + r.data.html + '</body></html>');
        }
        async function saveBuilder() {
            const name = document.getElementById('bdName').value.trim();
            if (!name) { notify('Template name is required'); return; }
            const subject = document.getElementById('bdSubject').value;
            const r1 = await emApiBuilder('/templates/blocks/render', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({blocks: JSON.stringify(bdBlocks)})});
            if (!r1.success) { notify('Could not generate HTML'); return; }
            const body = { name: name, subject: subject, blocks: JSON.stringify(bdBlocks), html_body: r1.data.html };
            const path = SAVE_TARGET ? '/templates/' + SAVE_TARGET : '/templates';
            const opts = {method: SAVE_TARGET ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)};
            const r = await emApiBuilder(path, opts);
            if (r.success) { notify('Template saved'); window.location.href = '/email-marketing/templates'; }
            else notify(r.error);
        }
        try { bdBlocks = JSON.parse(INITIAL_BLOCKS || '[]'); if (!Array.isArray(bdBlocks)) bdBlocks = []; } catch(e) { bdBlocks = []; }
        renderPalette();
        renderAll();
        JS;
        return str_replace(
            ['__INITIAL_BLOCKS__', '__SAVE_TARGET__'],
            [$blocks, $target],
            $js
        );
    }

    private function campaignsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadCampaigns() {
            const r = await emApi('/campaigns');
            if (!r.success) return;
            const cl = document.getElementById('campaignList');
            if (cl) cl.innerHTML = '<option value="0">Choose a list</option>' + (r.data.lists || []).map(l =>
                `<option value="${l.id}">${emText(l.name)} (${l.actual_count})</option>`).join('');
            const cs = document.getElementById('campaignSegment');
            if (cs) cs.innerHTML = '<option value="0">— No segment —</option>' + (r.data.segments || []).map(s =>
                `<option value="${s.id}">${emText(s.name)} (${s.subscriber_count || 0})</option>`).join('');

            const list = r.data.campaigns || [];
            document.getElementById('emCampaignsTable').innerHTML = list.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Name</th><th>Audience</th><th>Status</th><th>Sender</th><th>Opens</th><th>Click</th><th>Send time</th><th></th></tr></thead>
                    <tbody>${list.map(c => `
                        <tr>
                            <td><b>${emText(c.name)}</b><div style="font-size:11px;color:#6b7280;">${emText(c.subject)}</div></td>
                            <td style="font-size:12px;">${emText(c.segment_id ? ('🧩 ' + (c.segment_name || 'Segment')) : (c.list_name || '—'))}</td>
                            <td>${cStatusBadge(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${c.clicked_count}</td>
                            <td style="font-size:12px;color:#6b7280;">${emText(c.scheduled_at || '—')}</td>
                            <td style="text-align:left;white-space:nowrap;">
                                ${canAct(c.status) ? `<button class="p-btn xs primary" onclick="sendNow(${c.id}, this)">${['failed','partial','uncertain'].includes(c.status) ? '↻ Retry' : 'Send'}</button>` : ''}
                                ${c.status === 'scheduled' ? `<button class="p-btn xs danger" onclick="cancelC(${c.id})">Cancel</button>` : ''}
                                ${['draft','scheduled','cancelled'].includes(c.status) ? `<button class="p-btn xs" onclick="editCampaign(${c.id})">Edit</button>` : ''}
                                ${['draft','scheduled','cancelled'].includes(c.status) ? `<button class="p-btn xs" onclick="duplicateC(${c.id})">⧉ Copy</button>` : ''}
                                ${['draft','cancelled'].includes(c.status) ? `<button class="p-btn xs danger" onclick="deleteC(${c.id})">Delete</button>` : ''}
                                <a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">Report</a>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No campaigns - create your first campaign now.</p>';
        }
        function cStatusBadge(s) {
            const map = {draft:'Draft', scheduled:'Scheduled', sending:'Sending', sent:'Sent', cancelled:'Cancelled', failed:'Failed',partial:'Partially completed',uncertain:'Outcome needs review'};
            const colors = {draft:'#f3f4f6', scheduled:'#fef3c7', sending:'#dbeafe', sent:'#dcfce7', cancelled:'#fee2e2', failed:'#fee2e2',partial:'#fef3c7',uncertain:'#fef3c7'};
            return `<span style="background:${colors[s]||'#f3f4f6'};padding:2px 10px;border-radius:20px;font-size:12px;">${emText(map[s]||s)}</span>`;
        }
        function canAct(s) { return ['draft','scheduled','failed','partial','uncertain'].includes(s); }
        function openCampaignModal() {
            document.getElementById('campaignModalTitle').textContent = 'New campaign';
            ['campaignId','campaignName','campaignSubject','campaignFromName','campaignFromEmail','campaignHtml','campaignScheduledAt'].forEach(id => document.getElementById(id).value='');
            document.getElementById('campaignList').value = '0';
            document.getElementById('campaignSegment').value = '0';
            document.getElementById('campaignScheduleWrap').style.display = 'none';
            document.getElementById('campaignSto').checked = false;
            document.getElementById('campaignRecurrence').value = '';
            document.getElementById('campaignFirstRun').value = '';
            document.getElementById('campaignFirstRun').style.display = 'none';
            document.getElementById('campaignAiSubjects').innerHTML = '';
            document.getElementById('campaignModal').classList.add('open');
        }
        async function editCampaign(id) {
            const r = await emApi('/campaigns/' + id);
            if (!r.success) return;
            const c = r.data;
            document.getElementById('campaignModalTitle').textContent = 'Edit campaign';
            document.getElementById('campaignId').value = c.id;
            document.getElementById('campaignName').value = c.name;
            document.getElementById('campaignSubject').value = c.subject;
            document.getElementById('campaignFromName').value = c.from_name || '';
            document.getElementById('campaignFromEmail').value = c.from_email || '';
            document.getElementById('campaignHtml').value = c.html_body || '';
            document.getElementById('campaignList').value = c.list_id || '0';
            const cs = document.getElementById('campaignSegment');
            if (cs) cs.value = c.segment_id ? String(c.segment_id) : '0';
            document.getElementById('campaignScheduleWrap').style.display = c.status === 'scheduled' ? 'block' : 'none';
            document.getElementById('campaignSto').checked = !!parseInt(c.send_time_optimization || 0, 10);
            document.getElementById('campaignRecurrence').value = c.recurrence || '';
            document.getElementById('campaignFirstRun').style.display = c.recurrence ? 'block' : 'none';
            document.getElementById('campaignFirstRun').value = c.next_run_at ? (window.Panel && window.Panel.localDateTime ? window.Panel.localDateTime(c.next_run_at) : c.next_run_at.slice(0, 16)) : '';
            document.getElementById('campaignAiSubjects').innerHTML = '';
            document.getElementById('campaignScheduledAt').value = c.scheduled_at ? (window.Panel.localDateTime ? window.Panel.localDateTime(c.scheduled_at) : c.scheduled_at.slice(0,16)) : '';
            document.getElementById('campaignModal').classList.add('open');
        }
        async function aiCall(action, input) {
            return emApi('/ai-assist', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action, input})});
        }
        async function aiSubjects(btn) {
            const idea = document.getElementById('campaignName').value.trim() || document.getElementById('campaignSubject').value.trim();
            btnBusy(btn, true);
            try {
                const r = await aiCall('subjects', idea);
                if (!r.success) { notify(r.error || 'AI is unavailable'); return; }
                const box = document.getElementById('campaignAiSubjects');
                box.innerHTML = (r.data.suggestions || []).map(sg => `<button type="button" class="p-btn xs" style="text-align:start;" data-sg="${emText(sg)}">${emText(sg)}</button>`).join('');
                box.querySelectorAll('[data-sg]').forEach(b => b.onclick = () => { document.getElementById('campaignSubject').value = b.dataset.sg; box.innerHTML = ''; });
            } finally { btnBusy(btn, false); }
        }
        async function aiWriteBody(btn) {
            const brief = prompt('Describe the email (offer, destination, dates, call to action):', document.getElementById('campaignName').value || '');
            if (!brief) return;
            btnBusy(btn, true);
            try {
                const r = await aiCall('body', brief);
                if (!r.success) { notify(r.error || 'AI is unavailable'); return; }
                const ta = document.getElementById('campaignHtml');
                if (ta.value.trim() && !confirm('Replace the current content with the AI draft?')) return;
                ta.value = r.data.html || '';
            } finally { btnBusy(btn, false); }
        }
        async function aiRewriteBody(btn) {
            const ta = document.getElementById('campaignHtml');
            if (!ta.value.trim()) { notify('Write some content first'); return; }
            btnBusy(btn, true);
            try {
                const r = await aiCall('body', 'Improve this email, keep the same offer, links and variables:\n' + ta.value);
                if (!r.success) { notify(r.error || 'AI is unavailable'); return; }
                if (confirm('Replace the content with the improved version?')) ta.value = r.data.html || ta.value;
            } finally { btnBusy(btn, false); }
        }
        function insertVarCampaign(v) {
            const ta = document.getElementById('campaignHtml');
            ta.value += v;
            ta.focus();
        }
        async function saveCampaign(schedule) {
            const id = document.getElementById('campaignId').value;
            const body = {
                name: document.getElementById('campaignName').value.trim(),
                subject: document.getElementById('campaignSubject').value.trim(),
                from_name: document.getElementById('campaignFromName').value.trim(),
                from_email: document.getElementById('campaignFromEmail').value.trim(),
                list_id: document.getElementById('campaignList').value,
                segment_id: document.getElementById('campaignSegment').value,
                html_body: document.getElementById('campaignHtml').value,
                scheduled_at: schedule ? document.getElementById('campaignScheduledAt').value : null,
                send_time_optimization: document.getElementById('campaignSto').checked ? 1 : 0
            };
            if (!body.name || !body.subject || !body.html_body) { notify('Fill in the campaign name, subject and content'); return; }
            const path = id ? '/campaigns/' + id : '/campaigns';
            const opts = {method: id ? 'PATCH' : 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)};
            const r = await emApi(path, opts);
            if (!r.success) { notify(r.error); return; }
            const cid = r.data.id || id;
            const recurrence = document.getElementById('campaignRecurrence').value;
            const rr = (!recurrence && !document.getElementById('campaignFirstRun').value) ? {success: true} : await emApi('/campaigns/' + cid + '/recurrence', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({recurrence, first_run_at: document.getElementById('campaignFirstRun').value})});
            if (!rr.success && recurrence) { notify(rr.error); return; }
            if (schedule) {
                const s = await emApi('/campaigns/' + cid + '/schedule', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({scheduled_at: document.getElementById('campaignScheduledAt').value})});
                if (!s.success) { notify(s.error); return; }
            }
            document.getElementById('campaignModal').classList.remove('open');
            loadCampaigns();
        }
        async function sendNow(id, btn) {
            if (!confirm('Start sending the campaign to all active subscribers in the audience?')) return;
            btnBusy(btn, true);
            try {
                const r = await emApi('/campaigns/' + id + '/send', {method:'POST'});
                notify(r.success ? r.message : (r.error || 'Error'));
                if (r.success) loadCampaigns();
            } finally { btnBusy(btn, false); }
        }
        async function cancelC(id) {
            if (!confirm('Cancel this scheduled campaign?')) return;
            const r = await emApi('/campaigns/' + id + '/cancel', {method:'POST'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        async function duplicateC(id) {
            const r = await emApi('/campaigns/' + id + '/duplicate', {method:'POST'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        async function deleteC(id) {
            if (!confirm('Delete this campaign permanently?')) return;
            const r = await emApi('/campaigns/' + id, {method:'DELETE'});
            if (r.success) loadCampaigns(); else notify(r.error);
        }
        const params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1') { openCampaignModal(); history.replaceState({}, '', '/email-marketing/campaigns'); }
        loadCampaigns();
        JS;
    }

    private function campaignReportJs(int $campaignId): string
    {
        $cid = (int) $campaignId;
        $js = <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadReport() {
            const r = await emApi('/campaigns/__CID__/report');
            if (!r.success) return;
            const c = r.data;
            document.getElementById('crName').textContent = c.name + ' — ' + c.subject;
            document.getElementById('crCancelBtn').style.display = c.status === 'scheduled' ? 'inline-block' : 'none';
            document.getElementById('crRetryBtn').style.display = ['failed','partial','uncertain'].includes(c.status) ? 'inline-block' : 'none';
            const kpis = [
                ['📨', c.sent_count, 'Sent'],
                ['👁', c.opened_count, 'Opened'],
                ['🖱', c.clicked_count, 'Click'],
                ['🚫', c.unsubscribed_count, 'Cancel'],
                ['↩️', c.bounced_count, 'Bounce'],
                ['⏳', (c.total_recipients - c.sent_count), 'Pending']
            ].map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:24px;">${k[0]}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${k[2]}</div>
                </div>`).join('');
            document.getElementById('crKpis').innerHTML = kpis;

            const rates = [
                ['Open rate (all)', c.open_rate + '%'],
                ['Real open rate (excl. Apple MPP & scanners)', (c.human_open_rate ?? c.open_rate) + '%'],
                ['Machine opens (Apple MPP / scanners)', c.open_breakdown ? ((c.open_breakdown.apple_mpp || 0) + ' / ' + (c.open_breakdown.scanner || 0)) : '—'],
                ['Click rate', c.click_rate + '%'],
                ['Click/open', c.click_to_open_rate + '%'],
                ['Unsubscribe rate', c.unsubscribe_rate + '%'],
                ['Bookings from this campaign', parseInt(c.attributed_bookings_count || 0, 10)],
                ['Attributed booking revenue', Number(c.attributed_revenue || 0).toLocaleString()]
            ].map(rr => `
                <div style="padding:12px;border:1px solid #e5e7eb;border-radius:10px;text-align:center;">
                    <div style="font-size:20px;font-weight:700;">${rr[1]}</div>
                    <div style="font-size:12px;color:#6b7280;">${rr[0]}</div>
                </div>`).join('');
            document.getElementById('crRates').innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">' + rates + '</div>';

            const rows = (c.recipients || []).map(rc => `
                <tr>
                    <td>${rc.email}</td>
                    <td>${emText(rc.name || '—')}</td>
                    <td>${rBadge(rc.status)}</td>
                    <td>${rc.open_count || 0} / ${rc.click_count || 0}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.opened_at || '—'}</td>
                    <td style="font-size:12px;color:#6b7280;">${rc.clicked_at || '—'}</td>
                </tr>`).join('');
            document.getElementById('crRecipients').innerHTML = rows
                ? `<table class="p-table" style="width:100%;"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Open/click</th><th>First open</th><th>First click</th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No recipients - start sending the campaign first.</p>';
        }
        function rBadge(s) {
            const map = {pending:'⏳', sent:'📤', opened:'👁', clicked:'🖱', unsubscribed:'🚫', bounced:'↩️', failed:'❌',sending:'⏳',uncertain:'⚠️'};
            return map[s] || s;
        }
        async function refreshCampaignReport() { loadReport(); }
        async function cancelCampaign() {
            if (!confirm('Unschedule the campaign?')) return;
            const r = await emApi('/campaigns/__CID__/cancel', {method:'POST'});
            if (r.success) loadReport(); else notify(r.error);
        }
        async function retryFailedCampaign() {
            const r = await emApi('/campaigns/__CID__/send', {method:'POST'});
            if (r.success) notify('Reset ' + r.data.total + ' recipients - sending started via cron.'); else notify(r.error);
            if (r.success) loadReport();
        }
        loadReport();
        JS;
        return str_replace('__CID__', (string) $cid, $js);
    }

    private function reportsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }

        // رسم يومي SVG من غير مكتبات خارجية: مرسل / فتح حقيقي / كليك
        async function loadTimeseries() {
            const days = document.getElementById('emTsDays').value;
            const r = await emApi('/stats/timeseries?days=' + days);
            const box = document.getElementById('emTsChart');
            if (!r.success) { box.innerHTML = '<p class="p-cell-muted">Could not load the chart.</p>'; return; }
            const d = r.data;
            const series = [
                {key: 'sent', label: 'Sent', color: '#94a3b8'},
                {key: 'opens', label: 'Real opens', color: '#2563eb'},
                {key: 'clicks', label: 'Clicks', color: '#16a34a'},
            ];
            const W = 760, H = 220, P = {l: 40, r: 10, t: 10, b: 26};
            const max = Math.max(1, ...series.flatMap(s => d[s.key] || []));
            const n = d.days.length;
            const x = i => P.l + (n <= 1 ? 0 : i * (W - P.l - P.r) / (n - 1));
            const y = v => H - P.b - v * (H - P.t - P.b) / max;
            const grid = [0, 0.5, 1].map(f => {
                const v = Math.round(max * f), yy = y(v);
                return `<line x1="${P.l}" x2="${W - P.r}" y1="${yy}" y2="${yy}" stroke="currentColor" stroke-opacity=".12"/><text x="${P.l - 6}" y="${yy + 4}" font-size="10" text-anchor="end" fill="currentColor" fill-opacity=".6">${v}</text>`;
            }).join('');
            const step = Math.max(1, Math.ceil(n / 8));
            const xLabels = d.days.map((day, i) => i % step === 0 ? `<text x="${x(i)}" y="${H - 8}" font-size="10" text-anchor="middle" fill="currentColor" fill-opacity=".6">${day.slice(5)}</text>` : '').join('');
            const lines = series.map(s => {
                const pts = (d[s.key] || []).map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
                const dots = (d[s.key] || []).map((v, i) => `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="6" fill="transparent"><title>${d.days[i]} · ${s.label}: ${v}</title></circle>`).join('');
                return `<polyline points="${pts}" fill="none" stroke="${s.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>${dots}`;
            }).join('');
            const legend = series.map(s => `<span style="display:inline-flex;align-items:center;gap:6px;margin-inline-end:14px;font-size:12px;"><span style="width:14px;height:3px;border-radius:2px;background:${s.color};display:inline-block;"></span>${s.label}: <b>${(d[s.key] || []).reduce((a, b) => a + b, 0)}</b></span>`).join('');
            box.innerHTML = `<div style="margin-bottom:6px;">${legend}</div><svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;display:block;" role="img" aria-label="Daily email activity">${grid}${xLabels}${lines}</svg>`;
            const bookings = (d.bookings || []).reduce((a, b) => a + b, 0);
            const revenue = (d.revenue || []).reduce((a, b) => a + b, 0);
            document.getElementById('emTsRevenue').innerHTML = `<span class="p-cell-muted" style="font-size:13px;">🧳 Bookings from email in this period: <b>${bookings}</b> · 💰 Revenue: <b>${revenue.toLocaleString()}</b></span>`;
        }
        document.addEventListener('DOMContentLoaded', loadTimeseries);
        if (document.readyState !== 'loading') loadTimeseries();
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function loadStats() {
            const r = await emApi('/stats');
            if (!r.success) return;
            const kpis = r.data.kpis.map(k => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:26px;">${k.icon}</div>
                    <div style="font-size:22px;font-weight:700;margin-top:6px;">${k.value}</div>
                    <div style="font-size:12px;color:#6b7280;">${k.label}</div>
                </div>`).join('');
            document.getElementById('emStatsKpis').innerHTML = kpis;

            const cs = r.data.campaigns || [];
            document.getElementById('emStatsCampaigns').innerHTML = cs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Campaign</th><th>Status</th><th>Sender</th><th>Opens</th><th>Open rate</th><th>Click</th><th>Click rate</th><th>Cancel</th><th></th></tr></thead>
                    <tbody>${cs.map(c => {
                        const base = Math.max(1, c.sent_count);
                        const openRate = Math.round(c.opened_count / base * 1000) / 10;
                        const clickRate = Math.round(c.clicked_count / base * 1000) / 10;
                        return `
                        <tr>
                            <td><b>${emText(c.name)}</b></td>
                            <td>${emText(c.status)}</td>
                            <td>${c.sent_count}</td>
                            <td>${c.opened_count}</td>
                            <td>${openRate}%</td>
                            <td>${c.clicked_count}</td>
                            <td>${clickRate}%</td>
                            <td>${c.unsubscribed_count}</td>
                            <td><a class="p-btn xs" href="/email-marketing/campaigns/${c.id}">Report</a></td>
                        </tr>`;}).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No sent campaigns yet.</p>';
        }
        async function loadTransactionalStats() {
            const r = await emApi('/transactional/stats');
            if (!r.success) return;
            const s = r.data.stats || {};
            const st = [
                ['Sender', s.sent || 0],
                ['Opened', s.opened || 0],
                ['Click', s.clicked || 0],
                ['Failed', s.failed || 0],
            ].map(([l, v]) => `
                <div class="p-cell" style="text-align:center;padding:14px 10px;">
                    <div style="font-size:20px;font-weight:700;">${v}</div>
                    <div style="font-size:12px;color:#6b7280;">${l}</div>
                </div>`).join('');
            const recent = (s.recent || []).slice(0, 5);
            const rows = recent.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Template</th><th>Email</th><th>Status</th><th>Opens</th><th>Click</th><th>Date</th></tr></thead>
                    <tbody>${recent.map(l => `
                        <tr>
                            <td>${emText(l.template_name || '-')}</td>
                            <td>${l.to_email}</td>
                            <td>${l.status}</td>
                            <td>${l.opened_at ? 'Yes' : '-'}</td>
                            <td>${l.clicked_at ? 'Yes' : '-'}</td>
                            <td>${l.created_at}</td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:12px;">No transactional messages yet.</p>';
            document.getElementById('emStatsTransactional').innerHTML =
                `<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px;">${st}</div>${rows}`;
        }
        async function loadAbTestsStats() {
            const r = await emApi('/ab-tests');
            if (!r.success) return;
            const ts = r.data.ab_tests || [];
            const reports = await Promise.all(ts.map(t => emApi('/ab-tests/' + t.id + '/report')));
            const rows = reports.filter(x => x.success && x.data.report).map(x => x.data.report);
            document.getElementById('emStatsAbTests').innerHTML = rows.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Test</th><th>Status</th><th>Metric</th><th>A (open/click)</th><th>B (open/click)</th><th>Winner</th><th></th></tr></thead>
                    <tbody>${rows.map(t => {
                        const a = t.variant_a || {}, b = t.variant_b || {};
                        const aR = Math.max(1, a.sent_count);
                        const bR = Math.max(1, b.sent_count);
                        return `
                        <tr>
                            <td><b>${emText(t.name)}</b></td>
                            <td>${t.status}</td>
                            <td>${t.metric_label || '-'}</td>
                            <td>${Math.round(a.open_rate * 100) / 100}% / ${Math.round(a.click_rate * 100) / 100}%</td>
                            <td>${Math.round(b.open_rate * 100) / 100}% / ${Math.round(b.click_rate * 100) / 100}%</td>
                            <td>${t.declared_winner || t.winner || '-'}</td>
                            <td><a class="p-btn xs" href="/email-marketing/ab-tests/${t.id}">Report</a></td>
                        </tr>`;}).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:12px;">No A/B tests yet.</p>';
        }
        loadStats();
        loadTransactionalStats();
        loadAbTestsStats();
        JS;
    }

    private function contactsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            // تصحيح: الـ API بيرجّع {success, data:{...}} والصفحة دي بتقرا الحقول مباشرة (r.total / r.tags / r.data[])
            // فكانت بتقع بـ "map is not a function" والأرقام كلها صفر. بنفك الغلاف هنا بس.
            const j = await res.json();
            if (j && j.data && typeof j.data === 'object' && !Array.isArray(j.data)) {
                return Object.assign({ success: j.success, message: j.message, error: j.error, code: j.code }, j.data);
            }
            return j;
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            const headers = { 'Content-Type': 'application/json' };
            return emApi(path, { method: 'POST', headers, body: JSON.stringify(body) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let emContactPage = 1;
        let emCFields = [];
        let emCTags = [];
        let emCStatus = 'subscribed';
        let emCListId = 0;
        let emCQ = '';

        function switchContactTab(tab) {
            document.querySelectorAll('#emContactSubTabs .p-btn').forEach(b => {
                b.classList.toggle('primary', b.dataset.ctab === tab);
            });
            document.getElementById('emContactOverview').style.display = tab === 'overview' ? '' : 'none';
            document.getElementById('emContactFields').style.display = tab === 'fields' ? '' : 'none';
            document.getElementById('emContactTags').style.display = tab === 'tags' ? '' : 'none';
            document.getElementById('emContactSegments').style.display = tab === 'segments' ? '' : 'none';
            document.getElementById('emContactSuppressions').style.display = tab === 'suppressions' ? '' : 'none';
            if (tab === 'overview') { loadContactOverview(); loadContactSubscribers(); }
            if (tab === 'fields') loadFields();
            if (tab === 'tags') loadTags();
            if (tab === 'segments') loadSegments();
            if (tab === 'suppressions') loadSuppressions();
        }

        async function loadContactOverview() {
            const [subs, lists, tags, segs, supps, custom] = await Promise.all([
                emApi('/subscribers?per_page=1'),
                emApi('/lists'),
                emApi('/contacts/tags'),
                emApi('/contacts/segments'),
                emApi('/contacts/suppressions?per_page=1'),
                emApi('/contacts/custom-fields'),
            ]);
            emCFields = custom.fields || [];
            emCTags = tags.tags || [];
            let html = '';
            const cards = [
                ['👥', subs.total ?? 0, 'Total contacts'],
                ['✅', subs.data.filter(s => s.status === 'subscribed').length, 'Active now'],
                ['🚫', supps.total ?? 0, 'In the suppression list'],
                ['🧩', (segs.segments || []).length, 'Segment'],
                ['🏷️', emCFields.length, 'Custom field'],
                ['📛', emCTags.length, 'Tag'],
            ];
            html = cards.map(([ic, val, lbl]) => `
                <div class="p-cell" style="text-align:center;padding:18px 10px;">
                    <div style="font-size:24px;">${ic}</div>
                    <div style="font-size:20px;font-weight:700;margin-top:4px;">${val}</div>
                    <div style="font-size:12px;color:#6b7280;">${esc(lbl)}</div>
                </div>`).join('');
            document.getElementById('emContactStats').innerHTML = html;

            const listSel = document.getElementById('emCListFilter');
            listSel.innerHTML = '<option value="0">All lists</option>' + (lists.lists || []).map(l =>
                `<option value="${l.id}">${esc(l.name)} (${l.actual_count})</option>`).join('');
            listSel.value = emCListId;
        }

        async function loadContactSubscribers() {
            emCListId = parseInt(document.getElementById('emCListFilter').value || '0');
            emCStatus = document.getElementById('emCStatusFilter').value || '';
            emCQ = document.getElementById('emCSearch').value || '';
            const q = `per_page=20&page=${emContactPage}&list_id=${emCListId}&status=${encodeURIComponent(emCStatus)}&q=${encodeURIComponent(emCQ)}`;
            const r = await emApi('/subscribers?' + q);
            const rows = (r.data || []).map(s => `
                <tr>
                    <td><b>${esc(s.email)}</b></td>
                    <td>${esc(s.name || '-')}</td>
                    <td>${s.status === 'subscribed' ? '✅' : s.status === 'unsubscribed' ? '🚫' : '⚠️'} ${esc(s.status)}</td>
                    <td>${s.list_count || 0}</td>
                    <td>${s.engagement_score || 0}</td>
                    <td>${esc((s.created_at || '').slice(0, 10))}</td>
                    <td><a class="p-btn xs" href="/email-marketing/contacts/${s.id}">View</a></td>
                </tr>`).join('');
            document.getElementById('emContactsTable').innerHTML = r.data.length
                ? `<table class="p-table"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Lists</th><th>Engagement</th><th>Date added</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No contacts.</p>';
            document.getElementById('emCSubCountLabel').textContent = `(${r.total})`;
            const pages = Math.max(1, Math.ceil((r.total || 0) / 20));
            document.getElementById('emContactsPager').innerHTML =
                `<button class="p-btn xs" onclick="emContactPage=Math.max(1,emContactPage-1);loadContactSubscribers()">‹</button>
                 <span style="margin:0 10px;">${emContactPage} / ${pages}</span>
                 <button class="p-btn xs" onclick="emContactPage=Math.min(${pages},emContactPage+1);loadContactSubscribers()">›</button>`;
        }

        async function exportContacts() {
            const list = parseInt(document.getElementById('emCListFilter').value || '0');
            const q = `format=csv&list_id=${list}&status=${encodeURIComponent(emCStatus)}`;
            const r = await emApi('/subscribers/export?' + q);
            if (!r.data) return;
            const blob = new Blob([r.data], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'contacts.csv';
            a.click();
        }

        // ----- Custom fields -----
        async function loadFields() {
            const r = await emApi('/contacts/custom-fields');
            const rows = (r.fields || []).map(f => `
                <tr>
                    <td><code>{{custom.${esc(f.name)}}}</code></td>
                    <td>${esc(f.label)}</td>
                    <td>${esc(f.field_type)}</td>
                    <td>${f.is_system ? 'System' : f.is_required ? 'Required' : '-'}</td>
                    <td style="text-align:left;">
                        ${f.is_system ? '' : `<button class="p-btn xs" onclick="openFieldModal(${f.id})">Edit</button>
                        <button class="p-btn xs" onclick="deleteField(${f.id})">Delete</button>`}
                    </td>
                </tr>`).join('');
            document.getElementById('emFieldsTable').innerHTML = r.fields.length
                ? `<table class="p-table"><thead><tr><th>Variant</th><th>Label</th><th>Type</th><th>Property</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No custom fields.</p>';
        }
        function openFieldModal(id) {
            document.getElementById('fieldId').value = id || '';
            if (id) {
                const f = emCFields.find(x => x.id === id);
                if (f) {
                    document.getElementById('fieldName').value = f.name;
                    document.getElementById('fieldLabel').value = f.label;
                    document.getElementById('fieldType').value = f.field_type;
                    document.getElementById('fieldOptions').value = (f.options || []).join(', ');
                    toggleFieldOptions();
                }
            } else {
                ['fieldName', 'fieldLabel', 'fieldOptions'].forEach(i => document.getElementById(i).value = '');
                document.getElementById('fieldType').value = 'text';
                toggleFieldOptions();
            }
            document.getElementById('fieldModal').classList.add('open');
        }
        function toggleFieldOptions() {
            const t = document.getElementById('fieldType').value;
            document.getElementById('fieldOptionsWrap').style.display = ['select', 'multi_select'].includes(t) ? '' : 'none';
        }
        async function saveField() {
            const id = document.getElementById('fieldId').value;
            const body = {
                name: document.getElementById('fieldName').value,
                label: document.getElementById('fieldLabel').value,
                field_type: document.getElementById('fieldType').value,
                options: document.getElementById('fieldOptions').value,
                is_required: document.getElementById('fieldRequired').checked,
            };
            const r = id ? await emApi('/contacts/custom-fields/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/custom-fields', body);
            if (r.success) { document.getElementById('fieldModal').classList.remove('open'); loadFields(); }
            else notify(r.error || 'An error occurred');
        }
        async function deleteField(id) {
            if (!confirm('Delete the field?')) return;
            const r = await emApi('/contacts/custom-fields/' + id, { method: 'DELETE' });
            if (r.success) loadFields();
        }

        // ----- Tags -----
        async function loadTags() {
            const r = await emApi('/contacts/tags');
            const rows = (r.tags || []).map(t => `
                <tr>
                    <td><span class="em-tag-pill" style="background:${t.color || '#0b2436'};">${esc(t.name)}</span></td>
                    <td>${t.subscriber_count || 0} Subscriber</td>
                    <td style="text-align:left;">
                        <button class="p-btn xs" onclick="openTagModal(${t.id})">Edit</button>
                        <button class="p-btn xs" onclick="deleteTag(${t.id})">Delete</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emTagsTable').innerHTML = r.tags.length
                ? `<table class="p-table"><thead><tr><th>Tag</th><th>Subscribers</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No tags.</p>';
        }
        function openTagModal(id) {
            document.getElementById('tagId').value = id || '';
            if (id) {
                const t = emCTags.find(x => x.id === id);
                if (t) {
                    document.getElementById('tagName').value = t.name;
                    document.getElementById('tagColor').value = t.color || '#0077be';
                }
            } else {
                document.getElementById('tagName').value = '';
                document.getElementById('tagColor').value = '#0077be';
            }
            document.getElementById('tagModal').classList.add('open');
        }
        async function saveTag() {
            const id = document.getElementById('tagId').value;
            const body = { name: document.getElementById('tagName').value, color: document.getElementById('tagColor').value };
            const r = id ? await emApi('/contacts/tags/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/tags', body);
            if (r.success) { document.getElementById('tagModal').classList.remove('open'); loadTags(); }
            else notify(r.error || 'An error occurred');
        }
        async function deleteTag(id) {
            if (!confirm('Delete the tag?')) return;
            const r = await emApi('/contacts/tags/' + id, { method: 'DELETE' });
            if (r.success) loadTags();
        }

        // ----- Segments -----
        async function loadSegments() {
            const r = await emApi('/contacts/segments');
            const rows = (r.segments || []).map(s => `
                <tr>
                    <td><b>${esc(s.name)}</b></td>
                    <td>${esc(s.description || '-')}</td>
                    <td><span class="em-seg-badge">${s.subscriber_count} contact</span></td>
                    <td>${(s.conditions || []).length} Condition</td>
                    <td style="text-align:left;">
                        <button class="p-btn xs" onclick="openSegmentModal(${s.id})">Edit</button>
                        <button class="p-btn xs" onclick="deleteSegment(${s.id})">Delete</button>
                    </td>
                </tr>`).join('');
            document.getElementById('emSegmentsTable').innerHTML = r.segments.length
                ? `<table class="p-table"><thead><tr><th>Name</th><th>Description</th><th>Result</th><th>Conditions</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No segments. Create a segment to target specific parts of your audience.</p>';
        }
        function openSegmentModal(id) {
            document.getElementById('segmentId').value = id || '';
            const seg = id ? (emSegs || []).find(x => x.id === id) : null;
            document.getElementById('segmentName').value = seg ? seg.name : '';
            document.getElementById('segmentDesc').value = seg ? (seg.description || '') : '';
            document.getElementById('segmentMatchAll').value = seg ? (seg.match_all ? '1' : '0') : '1';
            document.getElementById('segmentConditions').innerHTML = '';
            const conds = seg && seg.conditions ? seg.conditions : [{ field: 'status', operator: 'is', value: 'subscribed' }];
            conds.forEach(c => addSegmentCondition(c));
            document.getElementById('segmentModal').classList.add('open');
            updateSegmentLive();
        }
        function segmentFieldOptions() {
            let opts = '<option value="status">Status</option><option value="email">Email</option><option value="name">Name</option>';
            opts += '<option value="created_at">Date added</option><option value="engagement_score">Engagement score</option>';
            opts += '<option value="language">Language</option><option value="has_tag">Has tag</option><option value="not_has_tag">Does not have tag</option>';
            opts += '<option value="in_list">In list</option><option value="not_in_list">Not in list</option>';
            opts += '<option value="opened">Open any email</option><option value="not_opened">Not opened</option><option value="clicked">Click any email</option><option value="not_clicked">Not clicked</option>';
            (emCFields || []).forEach(f => opts += `<option value="custom:${esc(f.name)}">Field: ${esc(f.label)}</option>`);
            return opts;
        }
        function addSegmentCondition(cond) {
            cond = cond || { field: 'status', operator: 'is', value: '' };
            const div = document.createElement('div');
            div.className = 'em-condition-row';
            div.innerHTML = `
                <select class="p-select" onchange="updateSegmentLive()">${segmentFieldOptions().replace(`value="${esc(cond.field)}"`, `value="${esc(cond.field)}" selected`)}</select>
                <select class="p-select" onchange="updateSegmentLive()">
                    <option value="is" ${cond.operator==='is'?'selected':''}>Equals</option>
                    <option value="is_not" ${cond.operator==='is_not'?'selected':''}>Not equal</option>
                    <option value="contains" ${cond.operator==='contains'?'selected':''}>Contains</option>
                    <option value="starts_with" ${cond.operator==='starts_with'?'selected':''}>Starts with</option>
                    <option value="ends_with" ${cond.operator==='ends_with'?'selected':''}>Ends with</option>
                    <option value="greater_than" ${cond.operator==='greater_than'?'selected':''}>></option>
                    <option value="less_than" ${cond.operator==='less_than'?'selected':''}><</option>
                    <option value="is_empty" ${cond.operator==='is_empty'?'selected':''}>Empty</option>
                    <option value="is_not_empty" ${cond.operator==='is_not_empty'?'selected':''}>Not empty</option>
                </select>
                <input type="text" class="p-select" value="${esc(cond.value)}" placeholder="Value" onkeyup="updateSegmentLive()">
                <button class="p-btn xs" onclick="this.parentNode.remove();updateSegmentLive()">×</button>`;
            document.getElementById('segmentConditions').appendChild(div);
        }
        async function updateSegmentLive() {
            const conditions = [];
            document.querySelectorAll('#segmentConditions .em-condition-row').forEach(row => {
                conditions.push({ field: row.children[0].value, operator: row.children[1].value, value: row.children[2].value });
            });
            document.getElementById('segmentLiveCount').textContent = `Calculating the result...`;
            if (!document.getElementById('segmentId').value) return;
            const r = await emApi(`/contacts/segments/${document.getElementById('segmentId').value}/preview`);
            if (r.success) document.getElementById('segmentLiveCount').textContent = `Current result: ${r.count} Contact`;
        }
        async function saveSegment() {
            const id = document.getElementById('segmentId').value;
            const conditions = [];
            document.querySelectorAll('#segmentConditions .em-condition-row').forEach(row => {
                conditions.push({ field: row.children[0].value, operator: row.children[1].value, value: row.children[2].value });
            });
            const body = {
                name: document.getElementById('segmentName').value,
                description: document.getElementById('segmentDesc').value,
                match_all: document.getElementById('segmentMatchAll').value === '1',
                conditions,
            };
            const r = id ? await emApi('/contacts/segments/' + id, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                          : await emPost('/contacts/segments', body);
            if (r.success) { document.getElementById('segmentModal').classList.remove('open'); loadSegments(); }
            else notify(r.error || 'An error occurred');
        }
        async function deleteSegment(id) {
            if (!confirm('Delete the segment?')) return;
            const r = await emApi('/contacts/segments/' + id, { method: 'DELETE' });
            if (r.success) loadSegments();
        }

        // ----- Suppressions -----
        function openSuppressionModal() {
            document.getElementById('supEmail').value = '';
            document.getElementById('supType').value = 'manual';
            document.getElementById('supReason').value = '';
            document.getElementById('suppressionModal').classList.add('open');
            document.getElementById('supEmail').focus();
        }
        let suppressionLoadVersion = 0;
        let suppressionPage = 1;
        async function loadSuppressions(page = suppressionPage) {
            page = Math.max(1, Math.floor(Number(page) || 1));
            const version = ++suppressionLoadVersion;
            const box = document.getElementById('emSuppressionsTable');
            box.innerHTML = '<div class="p-loading-row">Loading...</div>';
            try {
                const r = await emApi('/contacts/suppressions?page=' + page + '&per_page=50');
                if (version !== suppressionLoadVersion) return;
                if (!r || !r.success || !Array.isArray(r.data)) throw new Error('load');
                const data = r.data;
                const total = Math.max(0, Number(r.total) || data.length);
                const perPage = Math.max(1, Number(r.per_page) || 50);
                const pages = Math.max(1, Math.ceil(total / perPage));
                if (page > pages) { await loadSuppressions(pages); return; }
                suppressionPage = page;
                const rows = data.map(s => `
                    <tr>
                        <td><b>${esc(s.email)}</b></td>
                        <td>${s.type === 'bounce' ? '⚠️ Bounce' : s.type === 'complaint' ? '🚫 Complaint' : s.type === 'spam' ? '📧 Spam' : '✋ Manual'}</td>
                        <td>${esc(s.reason || '-')}</td>
                        <td>${esc(String(s.suppressed_at || '').slice(0, 10))}</td>
                        <td style="text-align:left;"><button class="p-btn xs" onclick="deleteSuppression(${Number(s.id)})">Remove</button></td>
                    </tr>`).join('');
                box.innerHTML = data.length
                    ? `<table class="p-table"><thead><tr><th>Email</th><th>Reason</th><th>Notes</th><th>Date</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                    : '<p class="p-cell-muted" style="padding:16px;">The suppression list is empty.</p>';
                box.innerHTML += `<div class="p-toolbar" style="margin-top:12px;" aria-label="تصفح Suppression list">
                    <span class="p-cell-muted">${total} Heading · Page ${page} of ${pages}</span>
                    <button type="button" class="p-btn outline xs" ${page <= 1 ? 'disabled' : ''} onclick="loadSuppressions(${page - 1})">Previous</button>
                    <button type="button" class="p-btn outline xs" ${page >= pages ? 'disabled' : ''} onclick="loadSuppressions(${page + 1})">Next</button>
                </div>`;
            } catch (error) {
                if (version !== suppressionLoadVersion) return;
                box.innerHTML = `<div class="p-cell-muted" role="alert">تعذر تحميل قائمة الممنوعين. <button type="button" class="p-btn outline xs" onclick="loadSuppressions(${page})">Retry</button></div>`;
            }
        }
        async function saveSuppression() {
            const r = await emPost('/contacts/suppressions', {
                email: document.getElementById('supEmail').value,
                type: document.getElementById('supType').value,
                reason: document.getElementById('supReason').value,
            });
            if (r.success) { document.getElementById('suppressionModal').classList.remove('open'); loadSuppressions(); }
            else notify(r.error || 'An error occurred');
        }
        async function deleteSuppression(id) {
            if (!confirm('Remove the address from the suppression list?')) return;
            const r = await emApi('/contacts/suppressions/' + id, { method: 'DELETE' });
            if (r.success) loadSuppressions();
        }

        emContactPage = 1;
        loadContactOverview();
        loadContactSubscribers();
        JS;
    }

    private function subscriberDetailJs(int $subscriberId): string
    {
        $js = <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        const SUB_ID = __SUB_ID__;
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let subDetail = null;
        let subAllTags = [];

        async function loadSubscriberDetail() {
            const r = await emApi('/subscribers/' + SUB_ID);
            if (!r.success) { document.getElementById('subDetailBody').innerHTML = '<p class="p-cell-muted" style="padding:16px;">Contact not found.</p>'; return; }
            subDetail = r.subscriber;
            const s = subDetail;
            document.getElementById('subDetailName').textContent = '👤 ' + (s.name || s.email);
            const tags = (s.tags || []).map(t => `<span class="em-tag-pill" style="background:${t.color || '#0b2436'};">${esc(t.name)}</span>`).join('') || '-';
            const lists = (s.lists || []).map(l => `<span class="em-seg-badge info">${esc(l.name)}</span>`).join('') || '-';
            const statusBadge = s.status === 'subscribed' ? '✅ Subscriber' : s.status === 'unsubscribed' ? '🚫 Cancelled' : '⚠️ Bounced';
            const cvs = Object.entries(s.custom_values || {}).map(([fid, v]) =>
                `<tr><td><code>${esc(v.name)}</code></td><td>${esc(v.value)}</td></tr>`).join('');

            document.getElementById('subDetailBody').innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">Email</div><b>${esc(s.email)}</b></div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">Status</div>${statusBadge}</div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">Engagement score</div><b>${s.engagement_score || 0}/100</b></div>
                <div class="p-cell" style="padding:16px;"><div style="font-size:12px;color:#6b7280;">Added</div>${esc((s.created_at || '').slice(0, 10))}</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="p-card">
                    <div class="p-card-head"><h3>📛 Tags</h3><button class="p-btn xs" onclick="toggleTagPicker()">+ Add</button></div>
                    <div style="margin-bottom:8px;">${tags}</div>
                    <div id="tagPicker" style="display:none;margin-top:8px;">
                        <select id="tagPickSel" class="p-select" style="width:100%;margin-bottom:6px;"></select>
                        <button class="p-btn primary xs" onclick="addTagToSub()">Add</button>
                    </div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>🗂️ Lists</h3></div>
                    <div>${lists}</div>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>🏷️ Custom fields</h3></div>
                    <table class="p-table"><tbody>${cvs || '<tr><td class="p-cell-muted">No values.</td></tr>'}</tbody></table>
                </div>
                <div class="p-card">
                    <div class="p-card-head"><h3>📊 Activity</h3></div>
                    ${(s.activity || []).map(a => `
                        <div style="padding:8px 0;border-bottom:1px solid #1f2937;">
                            <b>${esc(a.campaign_name)}</b><br>
                            <span style="font-size:12px;color:#6b7280;">${esc(a.status)} · Open: ${a.open_count} · Click: ${a.click_count} · ${esc((a.opened_at || a.created_at || '').slice(0, 16))}</span>
                        </div>`).join('') || '<p class="p-cell-muted">No activity yet.</p>'}
                </div>
            </div>`;
            loadSubTagsPicker();
        }
        async function loadSubTagsPicker() {
            const r = await emApi('/contacts/tags');
            subAllTags = r.tags || [];
            const have = new Set((subDetail.tags || []).map(t => t.id));
            document.getElementById('tagPickSel').innerHTML = subAllTags.filter(t => !have.has(t.id))
                .map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        }
        function toggleTagPicker() {
            const el = document.getElementById('tagPicker');
            el.style.display = el.style.display === 'none' ? '' : 'none';
            if (el.style.display !== 'none') loadSubTagsPicker();
        }
        async function addTagToSub() {
            const tagId = document.getElementById('tagPickSel').value;
            if (!tagId) return;
            const r = await emApi('/contacts/subscribers/' + SUB_ID + '/tags', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tag_id: tagId })
            });
            if (r.success) loadSubscriberDetail();
        }
        loadSubscriberDetail();
        JS;
        return str_replace('__SUB_ID__', (string) $subscriberId, $js);
    }

    private function automationsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            const headers = { 'Content-Type': 'application/json' };
            return emApi(path, { method: 'POST', headers, body: JSON.stringify(body) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        let emAutomations = [];
        let emAutoMeta = { triggers: {}, step_types: {} };
        let emAutoLists = [];
        let emAutoTemplates = [];
        let emAutoCampaigns = [];
        let emAutoEditingId = null;
        let emAutoStepCount = 0;

        const EM_AUTO_TRIGGER_OPTIONS = {
            subscribed: { label: 'List (optional)', type: 'list' },
            tag_added: { label: 'Tag name (optional)', type: 'text' },
            campaign_opened: { label: 'Campaign (optional)', type: 'campaign' },
            campaign_clicked: { label: 'Campaign (optional)', type: 'campaign' },
            date_after: { label: 'Number of days after subscribing', type: 'days' },
            booking_created: { label: 'Trip / service (optional)', type: 'product' },
            booking_confirmed: { label: 'Trip / service (optional)', type: 'product' },
            booking_cancelled: { label: 'Trip / service (optional)', type: 'product' },
            trip_upcoming: { label: 'Days before the trip', type: 'days', def: 3, product: true },
            trip_completed: { label: 'Days after the trip ends', type: 'days', def: 1, product: true },
            crm_lead_created: { label: '', type: 'none' },
            crm_deal_won: { label: '', type: 'none' },
        };
        const EM_AUTO_DAY_TRIGGERS = ['date_after', 'trip_upcoming', 'trip_completed'];
        const EM_AUTO_PRODUCT_TRIGGERS = ['booking_created', 'booking_confirmed', 'booking_cancelled', 'trip_upcoming', 'trip_completed'];
        let emAutoProducts = [];
        function productSelectHtml(id) {
            return `<select class="p-input" id="${id}" style="width:100%;"><option value="">All trips / services</option>${emAutoProducts.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>`;
        }
        const EM_AUTO_CHECKS = {
            has_tag: 'Has tag', not_has_tag: 'Does not have tag', in_list: 'In list', not_in_list: 'Not in list',
            opened_last_email: 'Opened previous email', not_opened_last_email: 'Did not open previous email',
            clicked_last_email: 'Clicked previous email', not_clicked_last_email: 'Did not click previous email',
        };

        async function loadPlatformIntegration() {
            const r = await emApi('/platform-integration');
            if (!r.success) return;
            const st = r.data.settings || {};
            const listOpts = '<option value="">No list</option>' + emAutoLists.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
            document.getElementById('emIntBookingList').innerHTML = listOpts;
            document.getElementById('emIntCrmList').innerHTML = listOpts;
            document.getElementById('emIntSyncBookings').checked = !!parseInt(st.sync_booking_customers || 0, 10);
            document.getElementById('emIntSyncCrm').checked = !!parseInt(st.sync_crm_contacts || 0, 10);
            document.getElementById('emIntAttribution').checked = !!parseInt(st.revenue_attribution ?? 1, 10);
            document.getElementById('emIntBookingList').value = st.booking_list_id ? String(st.booking_list_id) : '';
            document.getElementById('emIntCrmList').value = st.crm_list_id ? String(st.crm_list_id) : '';
            document.getElementById('emIntWindow').value = st.attribution_window_days || 30;
            document.getElementById('emIntVars').textContent = 'Booking variables for emails: ' + Object.keys(r.data.variables || {}).join(' ');
        }

        async function savePlatformIntegration(btn) {
            btn.disabled = true;
            const r = await emPost('/platform-integration', {
                sync_booking_customers: document.getElementById('emIntSyncBookings').checked ? 1 : 0,
                booking_list_id: parseInt(document.getElementById('emIntBookingList').value, 10) || 0,
                sync_crm_contacts: document.getElementById('emIntSyncCrm').checked ? 1 : 0,
                crm_list_id: parseInt(document.getElementById('emIntCrmList').value, 10) || 0,
                revenue_attribution: document.getElementById('emIntAttribution').checked ? 1 : 0,
                attribution_window_days: parseInt(document.getElementById('emIntWindow').value, 10) || 30,
            });
            btn.disabled = false;
            notify(r.success ? 'Connections saved' : (r.error || 'An error occurred'));
        }

        async function loadAutomations() {
            const r = await emApi('/automations');
            if (!r.success) return;
            emAutomations = r.data.automations || [];
            emAutoMeta = { triggers: r.data.triggers || {}, step_types: r.data.step_types || {} };
            const [listsR, tmplR, campR] = await Promise.all([emApi('/lists'), emApi('/templates'), emApi('/campaigns')]);
            emAutoLists = listsR.lists || [];
            emAutoTemplates = (tmplR.templates || []).filter(t => t.status === 'ready' || !t.status);
            emAutoCampaigns = campR.campaigns || [];
            try {
                const pr = await (await fetch('/api/crm/products?only_active=1')).json();
                emAutoProducts = (pr.data && pr.data.products) || pr.products || [];
            } catch (e) { emAutoProducts = []; }
            loadPlatformIntegration();

            const rows = emAutomations.map(a => {
                const trigLabel = emAutoMeta.triggers[a.trigger_type] || a.trigger_type;
                const steps = (a.steps_count ?? 0);
                const active = a.active_entries ?? 0;
                return `
                <tr>
                    <td><b>${esc(a.name)}</b>${a.description ? `<div class="p-cell-muted" style="font-size:12px;">${esc(a.description)}</div>` : ''}</td>
                    <td>${esc(trigLabel)}</td>
                    <td>${steps} Step</td>
                    <td>${active} Active</td>
                    <td>${a.status === 'active' ? '<span style="color:#16a34a;font-weight:700;">Enabled</span>' : '<span style="color:#9ca3af;font-weight:700;">Paused</span>'}</td>
                    <td style="text-align:left;white-space:nowrap;">
                        <button class="p-btn xs" onclick="toggleAutomationStatus(${a.id}, ${a.status === 'active' ? 0 : 1})">${a.status === 'active' ? 'Revoke' : 'Run'}</button>
                        <button class="p-btn xs" onclick="openAutomationModal(${a.id})">Edit</button>
                        <button class="p-btn xs" onclick="deleteAutomation(${a.id})">Delete</button>
                    </td>
                </tr>`;
            }).join('');

            document.getElementById('emAutomationsList').innerHTML = emAutomations.length
                ? `<table class="p-table"><thead><tr><th>Workflow</th><th>Operator</th><th>Steps</th><th>Active</th><th>Status</th><th></th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="p-cell-muted" style="padding:16px;">No workflows yet. Create the first automatic workflow.</p>';

            const triggerSel = document.getElementById('emAutoTrigger');
            if (!triggerSel.options.length) {
                triggerSel.innerHTML = Object.entries(emAutoMeta.triggers).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('');
            }
            const entrySel = document.getElementById('emAutoEntryAudience');
            if (!entrySel.options.length) {
                const opts = emAutoLists.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
                entrySel.innerHTML = opts;
                document.getElementById('emAutoExitAudience').innerHTML = opts;
            }
        }

        function automationTriggerValueHtml(type, def) {
            if (type === 'list') {
                const opts = '<option value="">All lists</option>' + emAutoLists.map(l => `<option value="${l.id}">${esc(l.name)}</option>`).join('');
                return `<select class="p-input" id="emAutoTriggerValue" style="width:100%;">${opts}</select>`;
            }
            if (type === 'campaign') {
                const opts = '<option value="">All campaigns</option>' + emAutoCampaigns.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
                return `<select class="p-input" id="emAutoTriggerValue" style="width:100%;">${opts}</select>`;
            }
            if (type === 'days') {
                return `<input class="p-input" id="emAutoTriggerValue" type="number" min="1" value="${def || 7}" style="width:100%;"/>`;
            }
            if (type === 'none') {
                return '';
            }
            if (type === 'product') {
                return productSelectHtml('emAutoTriggerValue');
            }
            return `<input class="p-input" id="emAutoTriggerValue" placeholder="Example: vip" style="width:100%;"/>`;
        }

        function onAutomationTriggerChange() {
            const type = EM_AUTO_TRIGGER_OPTIONS[document.getElementById('emAutoTrigger').value] || { label: '', type: 'text' };
            document.getElementById('emAutoTriggerValueLabel').textContent = type.label;
            const wrap = document.getElementById('emAutoTriggerValueWrap');
            wrap.innerHTML = '<label class="p-label" id="emAutoTriggerValueLabel">' + esc(type.label) + '</label>' + automationTriggerValueHtml(type.type, type.def)
                + (type.product ? '<label class="p-label" style="margin-top:6px;">Trip / service (optional)</label>' + productSelectHtml('emAutoTriggerProduct') : '');
        }

        function openAutomationModal(id) {
            emAutoEditingId = id || null;
            document.getElementById('emAutoModalTitle').textContent = id ? 'Edit workflow' : 'New workflow';
            document.getElementById('emAutoName').value = '';
            document.getElementById('emAutoDesc').value = '';
            document.getElementById('emAutoEntryAudience').value = '';
            document.getElementById('emAutoExitAudience').value = '';
            emAutoStepCount = 0;
            document.getElementById('emAutoSteps').innerHTML = '';
            document.getElementById('emAutoTrigger').value = 'subscribed';
            onAutomationTriggerChange();
            document.getElementById('emAutomationModal').style.display = 'flex';

            if (id) {
                emApi('/automations/' + id).then(r => {
                    if (!r.success) return;
                    const a = r.data;
                    document.getElementById('emAutoName').value = a.name || '';
                    document.getElementById('emAutoDesc').value = a.description || '';
                    document.getElementById('emAutoTrigger').value = a.trigger_type || 'subscribed';
                    onAutomationTriggerChange();
                    const tv = a.trigger_value || {};
                    const tvEl = document.getElementById('emAutoTriggerValue');
                    if (tvEl) {
                        if (a.trigger_type === 'subscribed' || a.trigger_type === 'campaign_opened' || a.trigger_type === 'campaign_clicked') {
                            tvEl.value = String(tv.list_id || tv.campaign_id || '');
                        } else if (a.trigger_type === 'tag_added') {
                            tvEl.value = tv.tag || '';
                        } else if (EM_AUTO_DAY_TRIGGERS.includes(a.trigger_type)) {
                            tvEl.value = tv.days || (EM_AUTO_TRIGGER_OPTIONS[a.trigger_type] || {}).def || 7;
                        } else if (EM_AUTO_PRODUCT_TRIGGERS.includes(a.trigger_type)) {
                            tvEl.value = tv.product_id ? String(tv.product_id) : '';
                        }
                    }
                    const prodEl = document.getElementById('emAutoTriggerProduct');
                    if (prodEl) prodEl.value = tv.product_id ? String(tv.product_id) : '';
                    // multiple select: .value = array بيختار عنصر واحد بس
                    const selectMany = (el, ids) => [...el.options].forEach(o => { o.selected = (ids || []).map(String).includes(o.value); });
                    selectMany(document.getElementById('emAutoEntryAudience'), a.entry_audience_ids);
                    selectMany(document.getElementById('emAutoExitAudience'), a.exit_audience_ids);
                    (a.steps || []).forEach(s => {
                        addAutomationStep(s.step_type, Object.assign({}, s.step_value || {}));
                    });
                    if (!(a.steps || []).length) addAutomationStep();
                });
            } else {
                addAutomationStep();
            }
        }

        function closeAutomationModal() {
            document.getElementById('emAutomationModal').style.display = 'none';
        }

        function addAutomationStep(stepType, value) {
            stepType = stepType || 'wait';
            value = value || {};
            const idx = emAutoStepCount++;
            const options = Object.entries(emAutoMeta.step_types).map(([k, v]) => {
                const sel = k === stepType ? ' selected' : '';
                return `<option value="${k}"${sel}>${esc(v)}</option>`;
            }).join('');
            const row = document.createElement('div');
            row.className = 'em-auto-step-row';
            row.style.cssText = 'border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#f9fafb;';
            row.dataset.idx = idx;
            row.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span class="em-auto-step-num" style="font-weight:700;color:#6b7280;">#${idx + 1}</span>
                    <select class="p-input" style="flex:1;" onchange="renderAutomationStepBody(this, ${idx})">
                        ${options}
                    </select>
                    <button class="p-btn xs" onclick="moveAutomationStep(${idx}, -1)">↑</button>
                    <button class="p-btn xs" onclick="moveAutomationStep(${idx}, 1)">↓</button>
                    <button class="p-btn xs" onclick="document.querySelectorAll('[data-idx]').forEach(r=>{ if(parseInt(r.dataset.idx)===${idx}) r.remove(); }); renumberAutomationSteps();">✕</button>
                </div>
                <div class="em-auto-step-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;"></div>`;
            document.getElementById('emAutoSteps').appendChild(row);
            const sel = row.querySelector('select');
            renderAutomationStepBody(sel, idx, value);
            renumberAutomationSteps();
        }

        // رقم الخطوة الظاهر = ترتيبها الفعلي (هو اللي "وإلا روح لخطوة #" بيعتمد عليه)
        function renumberAutomationSteps() {
            document.querySelectorAll('.em-auto-step-row').forEach((row, i) => {
                const num = row.querySelector('.em-auto-step-num');
                if (num) num.textContent = '#' + (i + 1);
            });
        }

        function moveAutomationStep(idx, dir) {
            const rows = [...document.querySelectorAll('.em-auto-step-row')];
            const i = rows.findIndex(r => parseInt(r.dataset.idx) === idx);
            const j = i + dir;
            if (i < 0 || j < 0 || j >= rows.length) return;
            const parent = rows[i].parentNode;
            parent.insertBefore(rows[i], dir < 0 ? rows[j] : rows[j].nextSibling);
            renumberAutomationSteps();
        }

        function renderAutomationStepBody(sel, idx, value) {
            value = value || {};
            const body = sel.closest ? sel.closest('.em-auto-step-row').querySelector('.em-auto-step-body') : null;
            if (!body) return;
            const type = sel.value;
            if (type === 'wait') {
                body.innerHTML = `
                    <input class="p-input" placeholder="days" type="number" min="0" data-step-k="days" value="${value.days || 0}"/>
                    <input class="p-input" placeholder="hours" type="number" min="0" data-step-k="hours" value="${value.hours || 0}"/>
                    <input class="p-input" placeholder="minutes" type="number" min="0" data-step-k="minutes" value="${value.minutes || 0}"/>`;
            } else if (type === 'send_email') {
                body.innerHTML = `
                    <input class="p-input" placeholder="Topic" data-step-k="subject" value="${esc(value.subject || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" placeholder="Email body (HTML or text)" data-step-k="html" value="${esc(value.html || '')}" style="grid-column:1/-1;"/>
                    <select class="p-input" data-step-k="template_id"><option value="">No template</option>
                        ${emAutoTemplates.map(t => `<option value="${t.id}"${String(value.template_id) === String(t.id) ? ' selected' : ''}>${esc(t.name)}</option>`).join('')}
                    </select>`;
            } else if (type === 'add_tag' || type === 'remove_tag') {
                body.innerHTML = `<input class="p-input" placeholder="Tag name" data-step-k="tag" value="${esc(value.tag || '')}" style="grid-column:1/-1;"/>`;
            } else if (type === 'add_to_list' || type === 'remove_from_list') {
                body.innerHTML = `<select class="p-input" data-step-k="list_id" style="grid-column:1/-1;"><option value="">Choose a list...</option>
                    ${emAutoLists.map(l => `<option value="${l.id}"${String(value.list_id) === String(l.id) ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}
                </select>`;
            } else if (type === 'condition' || type === 'goal') {
                // else_position محفوظ كترتيب الخطوة (0-based)؛ الواجهة بتعرضه كرقم الخطوة (#)
                const elsePos = parseInt(value.else_position ?? -1, 10);
                body.innerHTML = `
                    <select class="p-input" data-step-k="check">${Object.entries(EM_AUTO_CHECKS).map(([k, v]) => `<option value="${k}"${value.check === k ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>
                    <input class="p-input" placeholder="Tag (for tag checks)" data-step-k="tag" value="${esc(value.tag || '')}"/>
                    <select class="p-input" data-step-k="list_id"><option value="">List (for list checks)</option>
                        ${emAutoLists.map(l => `<option value="${l.id}"${String(value.list_id) === String(l.id) ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}
                    </select>
                    ${type === 'condition' ? `<input class="p-input" type="number" min="0" placeholder="If not: go to step # (empty = end)" data-step-k="else_step" value="${elsePos >= 0 ? elsePos + 1 : ''}"/>` : '<small class="p-cell-muted" style="grid-column:1/-1;">If the check is true the contact reached the goal and leaves the workflow.</small>'}`;
            } else if (type === 'create_crm_task') {
                body.innerHTML = `
                    <input class="p-input" placeholder="Task title, e.g. Call {{name}} about {{trip_name}}" data-step-k="title" value="${esc(value.title || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" type="number" min="0" placeholder="Due in (days)" data-step-k="due_days" value="${value.due_days ?? 1}"/>
                    <select class="p-input" data-step-k="priority">${['low', 'medium', 'high'].map(pr => `<option value="${pr}"${(value.priority || 'medium') === pr ? ' selected' : ''}>${pr}</option>`).join('')}</select>`;
            } else if (type === 'send_whatsapp') {
                body.innerHTML = `
                    <input class="p-input" placeholder="Approved UTILITY template name (WhatsApp Manager)" data-step-k="template_name" value="${esc(value.template_name || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" placeholder="Template variables in order, separated by | e.g. {{name}}|{{trip_name}}|{{trip_date}}" data-step-k="params" value="${esc(value.params || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" placeholder="Language code (ar, en, fr...)" data-step-k="language" value="${esc(value.language || 'ar')}"/>
                    <small class="p-cell-muted" style="grid-column:1/-1;">Sent to the booking phone or the contact's phone attribute. Contacts who opted out on WhatsApp are skipped.</small>`;
            } else if (type === 'request_review') {
                body.innerHTML = `
                    <select class="p-input" data-step-k="channel">${[['email', 'By email'], ['whatsapp', 'By WhatsApp']].map(([k, v]) => `<option value="${k}"${(value.channel || 'email') === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
                    <select class="p-input" data-step-k="destination">${[['google_business', 'Google Business'], ['tripadvisor', 'TripAdvisor'], ['other', 'Other link']].map(([k, v]) => `<option value="${k}"${(value.destination || 'google_business') === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
                    <small class="p-cell-muted" style="grid-column:1/-1;">Uses your Review Requests settings (review links, delay, opt-outs, duplicate protection).</small>`;
            } else if (type === 'notify_owner') {
                body.innerHTML = `
                    <input class="p-input" placeholder="Notification title" data-step-k="title" value="${esc(value.title || '')}" style="grid-column:1/-1;"/>
                    <input class="p-input" placeholder="Details (optional)" data-step-k="body" value="${esc(value.body || '')}" style="grid-column:1/-1;"/>`;
            } else {
                body.innerHTML = '';
            }
        }

        function collectAutomationSteps() {
            const steps = [];
            document.querySelectorAll('.em-auto-step-row').forEach((row, position) => {
                const type = row.querySelector('select').value;
                const value = {};
                row.querySelectorAll('[data-step-k]').forEach(inp => {
                    const k = inp.dataset.stepK;
                    let v = inp.value;
                    if (k === 'days' || k === 'hours' || k === 'minutes' || k === 'list_id' || k === 'template_id' || k === 'due_days') {
                        v = v === '' ? 0 : parseInt(v, 10) || 0;
                    }
                    value[k] = v;
                });
                if (type === 'send_email' && !value.subject && !value.html && !value.template_id) return;
                if ((type === 'add_tag' || type === 'remove_tag') && !value.tag) return;
                if ((type === 'create_crm_task') && !value.title) return;
                if (type === 'send_whatsapp' && !value.template_name) return;
                if ('else_step' in value) {
                    const n = parseInt(value.else_step, 10);
                    value.else_position = n > 0 ? n - 1 : -1; // ترتيب الخطوة في الشاشة
                    delete value.else_step;
                }
                steps.push({ position, step_type: type, step_value: value });
            });
            // الخطوات الفاضية بتتشال، فنحوّل else_position من ترتيب الشاشة لترتيب القائمة المرسلة
            // (لو الخطوة المقصودة اتشالت: أول خطوة محفوظة بعدها)
            steps.forEach(st => {
                if (st.step_value.else_position === undefined || st.step_value.else_position < 0) return;
                const target = steps.findIndex(o => o.position >= st.step_value.else_position);
                st.step_value.else_position = target;
            });
            return steps;
        }

        async function saveAutomation() {
            const name = document.getElementById('emAutoName').value.trim();
            if (!name) { notify('Workflow name is required'); return; }
            const triggerType = document.getElementById('emAutoTrigger').value;
            const tvEl = document.getElementById('emAutoTriggerValue');
            const tvRaw = tvEl ? tvEl.value : '';
            let triggerValue = {};
            if (triggerType === 'subscribed') triggerValue = { list_id: parseInt(tvRaw, 10) || 0 };
            else if (triggerType === 'campaign_opened' || triggerType === 'campaign_clicked') triggerValue = { campaign_id: parseInt(tvRaw, 10) || 0 };
            else if (triggerType === 'tag_added') triggerValue = { tag: tvRaw || '' };
            else if (EM_AUTO_DAY_TRIGGERS.includes(triggerType)) triggerValue = { days: parseInt(tvRaw, 10) || (EM_AUTO_TRIGGER_OPTIONS[triggerType] || {}).def || 7 };
            else if (EM_AUTO_PRODUCT_TRIGGERS.includes(triggerType)) triggerValue = { product_id: parseInt(tvRaw, 10) || 0 };
            const prodSel = document.getElementById('emAutoTriggerProduct');
            if (prodSel && EM_AUTO_PRODUCT_TRIGGERS.includes(triggerType)) triggerValue.product_id = parseInt(prodSel.value, 10) || 0;

            const body = {
                name,
                description: document.getElementById('emAutoDesc').value.trim(),
                trigger_type: triggerType,
                trigger_value: triggerValue,
                entry_audience_ids: [...document.getElementById('emAutoEntryAudience').selectedOptions].map(o => parseInt(o.value, 10)).filter(v => v > 0),
                exit_audience_ids: [...document.getElementById('emAutoExitAudience').selectedOptions].map(o => parseInt(o.value, 10)).filter(v => v > 0),
            };

            const steps = collectAutomationSteps();
            const url = emAutoEditingId ? '/automations/' + emAutoEditingId : '/automations';
            const r = emAutoEditingId
                ? await emApi(url, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                : await emPost(url, body);
            if (!r.success) { notify(r.error || 'An error occurred'); return; }
            const autoId = r.data.id || emAutoEditingId;
            const stepsR = await emPost('/automations/' + autoId + '/steps', { steps });
            if (!stepsR.success) { notify(stepsR.error || 'Failed to save the steps'); return; }
            closeAutomationModal();
            loadAutomations();
        }

        async function toggleAutomationStatus(id, active) {
            const r = await emPost('/automations/' + id + '/status', { status: active ? 'active' : 'paused' });
            if (r.success) loadAutomations();
        }

        async function deleteAutomation(id) {
            if (!confirm('Delete the workflow?')) return;
            const r = await emApi('/automations/' + id, { method: 'DELETE' });
            if (r.success) loadAutomations();
        }

        async function runAutomationsDue(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/automations/run-due', {});
                if (r.success) {
                    notify(`Processed: ${r.data.processed || 0} shares, completed ${r.data.completed || 0}`);
                    loadAutomations();
                }
            } finally { btnBusy(btn, false); }
        }

        loadAutomations();
        JS;
    }

    private function smtpSettingsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function loadSmtpSettings() {
            const r = await emApi('/smtp-settings');
            if (!r.success) return;
            const s = r.data.settings || {};
            if (s.host) document.getElementById('emSmtpHost').value = s.host;
            if (s.port) document.getElementById('emSmtpPort').value = s.port;
            if (s.encryption) document.getElementById('emSmtpEncryption').value = s.encryption;
            if (s.username) document.getElementById('emSmtpUsername').value = s.username;
            if (s.from_email) document.getElementById('emSmtpFromEmail').value = s.from_email;
            if (s.from_name) document.getElementById('emSmtpFromName').value = s.from_name;
            const active = parseInt(s.is_active) === 1;
            document.getElementById('emSmtpActive').checked = active;
            document.getElementById('emSmtpToggleBtn').textContent = active ? '⏻ Disable' : '⏻ Enable';
            const e = r.data.effective || {};
            const ready = e.ready ? '✅ Ready to send' : '⚠️ Incomplete - add the details of SMTP';
            document.getElementById('emSmtpStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:#f8fafc;border:1px solid #e5e7eb;">
                    <span style="font-size:20px;">${e.ready ? '📧' : '⚠️'}</span>
                    <span style="font-size:14px;">${ready} — Host: ${e.host || '—'}, sent: ${e.from_email || '—'}</span>
                </div>`;
        }
        async function saveSmtpSettings() {
            const body = {
                host: document.getElementById('emSmtpHost').value,
                port: parseInt(document.getElementById('emSmtpPort').value || '587', 10),
                encryption: document.getElementById('emSmtpEncryption').value,
                username: document.getElementById('emSmtpUsername').value,
                password: document.getElementById('emSmtpPassword').value,
                from_email: document.getElementById('emSmtpFromEmail').value,
                from_name: document.getElementById('emSmtpFromName').value,
                is_active: 1,
            };
            const r = await emPost('/smtp-settings', body);
            if (r.success) { notify('Settings saved'); loadSmtpSettings(); }
            else notify(r.error || 'An error occurred');
        }
        async function toggleSmtpSettings() {
            const target = !document.getElementById('emSmtpActive').checked;
            const r = await emPost('/smtp-settings', { is_active: target ? 1 : 0 });
            if (r.success) loadSmtpSettings();
            else notify(r.error || 'An error occurred');
        }
        async function deleteSmtpSettings() {
            if (!confirm('Delete SMTP settings? You will go back to using the global .env settings.')) return;
            const r = await emApi('/smtp-settings', { method: 'DELETE' });
            if (r.success) { notify('Settings deleted'); ['emSmtpHost','emSmtpPort','emSmtpEncryption','emSmtpUsername','emSmtpPassword','emSmtpFromEmail','emSmtpFromName'].forEach(id => document.getElementById(id).value = id === 'emSmtpPort' ? '587' : id === 'emSmtpEncryption' ? 'tls' : ''); loadSmtpSettings(); }
            else notify(r.error || 'An error occurred');
        }
        async function testSmtpSettings() {
            const body = {
                host: document.getElementById('emSmtpHost').value,
                port: parseInt(document.getElementById('emSmtpPort').value || '587', 10),
                encryption: document.getElementById('emSmtpEncryption').value,
                username: document.getElementById('emSmtpUsername').value,
                password: document.getElementById('emSmtpPassword').value,
                from_email: document.getElementById('emSmtpFromEmail').value,
                from_name: document.getElementById('emSmtpFromName').value,
            };
            const r = await emPost('/smtp-settings/test', body);
            if (r.success) notify('✅ Connected and authenticated successfully');
            else notify('❌ ' + (r.error || 'Test failed'));
        }
        async function loadDeliveryWebhook() {
            const r = await emApi('/smtp-settings/webhook');
            if (!r.success) return;
            const d = r.data || {};
            document.getElementById('emDeliveryWebhookEnabled').checked = !!d.enabled;
            document.getElementById('emDeliveryWebhookUrl').textContent = d.url || '—';
            document.getElementById('emDeliveryWebhookSecret').textContent = d.has_secret ? (d.secret_masked || '••••') : 'No key yet - press "Generate a new key"';
        }
        async function toggleDeliveryWebhook() {
            const enabled = document.getElementById('emDeliveryWebhookEnabled').checked;
            const r = await emPost('/smtp-settings/webhook', { enabled: enabled ? 1 : 0 });
            if (r.success) { notify(enabled ? 'Delivery tracking webhook enabled' : 'Delivery tracking webhook disabled'); loadDeliveryWebhook(); }
            else notify(r.error || 'An error occurred');
        }
        async function regenerateDeliveryWebhookSecret() {
            const r = await emPost('/smtp-settings/webhook', { regenerate: 1, enabled: document.getElementById('emDeliveryWebhookEnabled').checked ? 1 : 0 });
            if (!r.success) { notify(r.error || 'An error occurred'); return; }
            notify('New key generated - copy it now, it will not be shown in full again');
            document.getElementById('emDeliveryWebhookSecret').textContent = r.data.secret || '••••';
        }
        loadSmtpSettings();
        loadDeliveryWebhook();
        JS;
    }

    private function transactionalJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        let txEditingId = 0;
        let txSendingTemplateId = 0;
        async function loadTransactionalTemplates() {
            const r = await emApi('/transactional/templates');
            if (!r.success) return;
            const list = document.getElementById('emTxTemplates');
            list.innerHTML = (r.data.templates || []).length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Template</th><th>Topic</th><th>Times sent</th><th>Status</th><th></th></tr></thead>
                    <tbody>${r.data.templates.map(t => `
                        <tr>
                            <td>${emText(t.name)} <span class="p-cell-muted">(${t.slug})</span></td>
                            <td>${t.subject}</td>
                            <td>${t.send_count || 0}</td>
                            <td>${parseInt(t.is_active) ? '✅ Enabled' : '⛔ Disabled'}</td>
                            <td>
                                <button class="p-btn xs" onclick="previewTransactionalTemplate(${t.id})">👁 Preview</button>
                                <button class="p-btn xs" onclick="openSendTransactional(${t.id})">📤 Send</button>
                                <button class="p-btn xs" onclick="editTransactionalTemplate(${t.id})">Edit</button>
                                <button class="p-btn xs" onclick="duplicateTransactionalTemplate(${t.id})">Copy</button>
                                <button class="p-btn xs danger" onclick="deleteTransactionalTemplate(${t.id})">Delete</button>
                            </td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No transactional templates yet - create the first template.</p>';
            document.getElementById('emTxStats').innerHTML = renderTxStats(r.data);
        }
        function renderTxStats(r) {
            const k = r.templates || [];
            return `<div class="p-cell" style="flex:1;text-align:center;"><div style="font-size:20px;font-weight:700;">${k.length}</div><div style="font-size:12px;color:#6b7280;">Templates</div></div>`;
        }
        async function openTransactionalTemplateModal() {
            txEditingId = 0;
            document.getElementById('emTxModalTitle').textContent = 'New transactional template';
            document.getElementById('emTxName').value = '';
            document.getElementById('emTxSlug').value = '';
            document.getElementById('emTxSubject').value = '';
            document.getElementById('emTxHtml').value = '<h2>Hello {{first_name}}!</h2><p>Thank you for contacting us.</p>';
            document.getElementById('emTxTemplateModal').style.display = 'flex';
        }
        function closeTransactionalTemplateModal() {
            document.getElementById('emTxTemplateModal').style.display = 'none';
        }
        async function editTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id);
            if (!r.success) return;
            const t = r.data.template;
            txEditingId = id;
            document.getElementById('emTxModalTitle').textContent = 'Edit template';
            document.getElementById('emTxName').value = t.name;
            document.getElementById('emTxSlug').value = t.slug;
            document.getElementById('emTxSubject').value = t.subject;
            document.getElementById('emTxHtml').value = t.html_body;
            document.getElementById('emTxTemplateModal').style.display = 'flex';
        }
        async function saveTransactionalTemplate() {
            const body = {
                name: document.getElementById('emTxName').value,
                slug: document.getElementById('emTxSlug').value,
                subject: document.getElementById('emTxSubject').value,
                html_body: document.getElementById('emTxHtml').value,
                is_active: 1,
            };
            const path = txEditingId ? '/transactional/templates/' + txEditingId : '/transactional/templates';
            const opts = txEditingId
                ? { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
                : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
            const r = await emApi(path, opts);
            if (r.success) { closeTransactionalTemplateModal(); loadTransactionalTemplates(); }
            else notify(r.error || 'An error occurred');
        }
        async function deleteTransactionalTemplate(id) {
            if (!confirm('Delete the template?')) return;
            const r = await emApi('/transactional/templates/' + id, { method: 'DELETE' });
            if (r.success) loadTransactionalTemplates();
        }
        async function previewTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id);
            if (!r.success) return;
            const t = r.data.template || {};
            document.getElementById('previewBody').innerHTML = `<div style="background:#fff;max-width:620px;margin:0 auto;border-radius:12px;overflow:hidden;">
                <div style="padding:12px 20px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#374151;">Topic: <b>${esc(t.subject || '')}</b></div>
                <iframe sandbox="" style="width:100%;min-height:420px;border:none;background:#fff;" srcdoc="${encodeURI(t.html_body || '')}"></iframe>
            </div>`;
            document.getElementById('previewModal').classList.add('open');
        }
        async function duplicateTransactionalTemplate(id) {
            const r = await emApi('/transactional/templates/' + id + '/duplicate', { method: 'POST' });
            if (r.success) loadTransactionalTemplates();
            else notify(r.error || 'Copy failed');
        }
        async function openSendTransactional(id) {
            txSendingTemplateId = id;
            document.getElementById('emTxSendEmail').value = '';
            document.getElementById('emTxSendName').value = '';
            document.getElementById('emTxSendData').value = '{"first_name":"Ahmed"}';
            document.getElementById('emTxSendModal').style.display = 'flex';
        }
        function closeTransactionalSendModal() {
            document.getElementById('emTxSendModal').style.display = 'none';
        }
        async function sendTransactionalNow() {
            let data = {};
            try { data = JSON.parse(document.getElementById('emTxSendData').value || '{}'); }
            catch (e) { notify('Invalid JSON data'); return; }
            const r = await emPost('/transactional/send', {
                template_id: txSendingTemplateId,
                to_email: document.getElementById('emTxSendEmail').value,
                data: Object.assign({ to_name: document.getElementById('emTxSendName').value }, data),
            });
            if (r.success) { notify('Sent'); closeTransactionalSendModal(); loadTransactionalLogs(); }
            else notify('❌ ' + (r.error || 'Submission failed'));
        }
        async function loadTransactionalLogs() {
            const r = await emApi('/transactional/logs?limit=20');
            if (!r.success) return;
            const logs = r.data.logs || [];
            document.getElementById('emTxLogs').innerHTML = logs.length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Email</th><th>Topic</th><th>Status</th><th>Open</th><th>Click</th><th>Date</th></tr></thead>
                    <tbody>${logs.map(l => `
                        <tr>
                            <td>${l.to_email}</td>
                            <td>${l.subject}</td>
                            <td>${l.status === 'sent' ? '✅ Sent' : '❌ Failed'}</td>
                            <td>${l.open_count}</td>
                            <td>${l.click_count}</td>
                            <td>${l.created_at}</td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No sending log yet.</p>';
        }
        loadTransactionalTemplates();
        loadTransactionalLogs();
        JS;
    }

    private function abTestsJs(): string
    {
        return <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function loadAbTests() {
            const r = await emApi('/ab-tests');
            if (!r.success) return;
            document.getElementById('emAbCampaign').innerHTML = (r.data.campaigns || []).map(c =>
                `<option value="${c.id}">${emText(c.name)}</option>`).join('');
            const list = document.getElementById('emAbTestsList');
            list.innerHTML = (r.data.ab_tests || []).length ? `
                <table class="p-table" style="width:100%;">
                    <thead><tr><th>Test</th><th>Campaign</th><th>Rate</th><th>Metric</th><th>Status</th><th></th></tr></thead>
                    <tbody>${r.data.ab_tests.map(t => `
                        <tr>
                            <td>${emText(t.name)}</td>
                            <td>${t.base_name || '—'}</td>
                            <td>A ${100 - parseInt(t.split_percent)}% / B ${t.split_percent}%</td>
                            <td>${t.metric === 'click' ? 'Click' : 'Open'}</td>
                            <td>${t.status_label || t.status}</td>
                            <td><a class="p-btn xs" href="/email-marketing/ab-tests/${t.id}">Manage</a></td>
                        </tr>`).join('')}</tbody>
                </table>` : '<p class="p-cell-muted" style="padding:16px;">No tests yet - create the first A/B test.</p>';
        }
        function openAbTestModal() {
            document.getElementById('emAbName').value = '';
            document.getElementById('emAbSplit').value = '50';
            document.getElementById('emAbMetric').value = 'open';
            document.getElementById('emAbTestModal').style.display = 'flex';
        }
        function closeAbTestModal() {
            document.getElementById('emAbTestModal').style.display = 'none';
        }
        async function createAbTest() {
            const r = await emPost('/ab-tests', {
                name: document.getElementById('emAbName').value,
                base_campaign_id: parseInt(document.getElementById('emAbCampaign').value || '0', 10),
                split_percent: parseInt(document.getElementById('emAbSplit').value || '50', 10),
                metric: document.getElementById('emAbMetric').value,
            });
            if (r.success) { closeAbTestModal(); loadAbTests(); window.location = '/email-marketing/ab-tests/' + r.data.id; }
            else notify(r.error || 'An error occurred');
        }
        loadAbTests();
        JS;
    }

    private function abTestDetailsJs(int $id): string
    {
        $id = (int) $id;
        $js = <<<'JS'
        function emText(value) {
            return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        async function emApi(path, opts) {
            const res = await fetch('/api/email-marketing' + path, opts || {});
            return res.json();
        }
        function notify(msg, type) {
            window.__emFallbackAlert = window.__emFallbackAlert || function (m) { alert(m); };
            if (!type) {
                const m = String(msg || '');
                if (/^(Done|Done|Retried|✅|Succeeded|Saved)/.test(m) || m.indexOf('Success') !== -1) type = 'success';
                else if (/^(❌|None|Could not|Failed|Error)/.test(m) || m.indexOf('Error') !== -1 || m.indexOf('Required') !== -1 || m.indexOf('Not found') !== -1) type = 'error';
            }
            try { P.toast(msg, type); } catch (e) { window.__emFallbackAlert(msg); }
        }
        function btnBusy(btn, busy, doneLabel) {
            if (!btn) return;
            if (busy) {
                btn.dataset.orig = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.7;">In progress...</span>';
            } else {
                btn.disabled = false;
                if (doneLabel) btn.innerHTML = doneLabel;
                else if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig;
            }
        }
        async function emPost(path, body) {
            return emApi(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
        }
        async function saveAbVariant(variant) {
            const body = {
                variant,
                subject: document.getElementById('emAb' + (variant === 'a' ? 'A' : 'B') + 'Subject').value,
                html_body: document.getElementById('emAb' + (variant === 'a' ? 'A' : 'B') + 'Html').value,
            };
            const r = await emPost('/ab-tests/__AB_ID__/variant', body);
            if (r.success) notify('Variant saved ' + (variant === 'a' ? 'A' : 'B'));
            else notify(r.error || 'An error occurred');
        }
        async function runAbTest(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/start', {});
                if (r.success) notify('Ran: A = ' + r.data.a + ', B = ' + r.data.b + ' recipient');
                else notify(r.error || 'An error occurred');
            } finally { btnBusy(btn, false); }
        }
        async function sendAbBatch(btn) {
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/send-batch', {});
                if (r.success) {
                    notify('A/B test batch sending scheduled - it will be sent via cron');
                } else notify(r.error || 'An error occurred');
            } finally { btnBusy(btn, false); }
        }
        async function sendAbTestEmail(btn) {
            const email = (document.getElementById('emAbTestEmail') || {}).value || '';
            if (!email || email.indexOf('@') === -1) { notify('Enter a valid email to send the test'); return; }
            const variant = (document.getElementById('emAbTestVariant') || {}).value || 'all';
            btnBusy(btn, true);
            try {
                const r = await emPost('/ab-tests/__AB_ID__/send-test', { email, variant });
                if (r.success) notify('Test messages sent successfully to ' + email);
                else notify(r.error || 'An error occurred');
            } finally { btnBusy(btn, false); }
        }
        async function loadAbReport() {
            const r = await emApi('/ab-tests/__AB_ID__/report');
            if (!r.success) return;
            const rep = r.data.report;
            const va = rep.variant_a || {}, vb = rep.variant_b || {};
            const isClick = rep.metric === 'click';
            const rk = isClick ? 'click_rate' : 'open_rate';
            const sg = rep.significance || {};
            const conf = Number(sg.confidence || 0);
            const confColor = sg.significant ? '#059669' : (conf >= 80 ? '#d97706' : '#6b7280');
            const headline = rep.winner ? '🏆 ' + (rep.winner === 'a' ? 'Variant A' : 'Variant B')
                : (rep.leader ? '⏳ ' + (rep.leader === 'a' ? 'A' : 'B') + ' leading' : 'Tie');
            document.getElementById('emAbReport').innerHTML = `
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:14px;">
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;">🅰️ ${va[rk] || 0}%</div><div style="font-size:12px;color:#6b7280;">${isClick ? 'Click' : 'Open'} A</div></div>
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;">🅱️ ${vb[rk] || 0}%</div><div style="font-size:12px;color:#6b7280;">${isClick ? 'Click' : 'Open'} B</div></div>
                    <div class="p-cell" style="text-align:center;"><div style="font-size:20px;font-weight:700;color:${confColor};">${conf}%</div><div style="font-size:12px;color:#6b7280;">الثقة الإحصائية (لازم 95%)</div></div>
                    <div class="p-cell" style="text-align:center;${rep.winner ? 'background:#ecfdf5;' : ''}"><div style="font-size:20px;font-weight:700;">${headline}</div></div>
                </div>
                <div class="p-cell" style="margin-bottom:14px;font-size:13px;">💡 ${rep.recommendation || ''}${!isClick ? '<br><span style="color:#6b7280;font-size:12px;">ملحوظة: نسبة الفتح بتتأثر بخاصية حماية الخصوصية في آيفون (بتعلّم الإيميل مفتوح لوحده) - الكليك مقياس أدق.</span>' : ''}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <table class="p-table"><thead><tr><th>Metric</th><th>A</th><th>B</th></tr></thead><tbody>
                        <tr><td>Sent</td><td>${va.sent_count || 0}</td><td>${vb.sent_count || 0}</td></tr>
                        <tr><td>Open</td><td>${va.opened_count || 0}</td><td>${vb.opened_count || 0}</td></tr>
                        <tr><td>Click</td><td>${va.clicked_count || 0}</td><td>${vb.clicked_count || 0}</td></tr>
                        <tr><td>Open rate</td><td>${va.open_rate || 0}%</td><td>${vb.open_rate || 0}%</td></tr>
                        <tr><td>Click rate</td><td>${va.click_rate || 0}%</td><td>${vb.click_rate || 0}%</td></tr>
                    </tbody></table>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <button class="p-btn primary" onclick="declareWinner('a')">🏆 Declare variant A the winner</button>
                        <button class="p-btn primary" onclick="declareWinner('b')">🏆 Declare variant B the winner</button>
                        <button class="p-btn" onclick="applyWinner()">📋 Copy the winner to the main campaign</button>
                    </div>
                </div>`;
        }
        async function declareWinner(winner) {
            const r = await emPost('/ab-tests/__AB_ID__/winner', { winner });
            if (r.success) { notify('Winner declared'); loadAbReport(); }
            else notify(r.error || 'An error occurred');
        }
        async function applyWinner() {
            const r = await emPost('/ab-tests/__AB_ID__/apply-winner', {});
            if (r.success) notify('Winner copied to the main campaign');
            else notify(r.error || 'An error occurred');
        }
        loadAbReport();
        JS;
        return str_replace('__AB_ID__', (string) $id, $js);
    }

    /** GET /api/email-marketing/campaigns/{id}/insights?tz=120&days=14 */
    public function campaignInsights(array $params = []): array
    {
        $svc = new EmailReportService();
        $id = (int) ($params['id'] ?? 0);
        $uid = (int) $this->user['id'];
        $sum = $svc->summary($uid, $id);
        if (!$sum) {
            return $this->error('الحملة غير موجودة', 404);
        }
        $tl = $svc->timeline($uid, $id, (int) $this->get('tz', 0), (int) $this->get('days', 14));
        return $this->success(['summary' => $sum, 'timeline' => $tl]);
    }

    /** GET /api/email-marketing/reports/compare?ids=1,2,3 */
    public function compareReports(array $params = []): array
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $this->get('ids', ''))));
        return $this->success((new EmailReportService())->compare((int) $this->user['id'], $ids));
    }

    /** GET /api/email-marketing/campaigns/{id}/export.csv */
    public function exportCampaignCsv(array $params = []): array
    {
        $csv = (new EmailReportService())->recipientsCsv((int) $this->user['id'], (int) ($params['id'] ?? 0));
        if ($csv === null) {
            return $this->error('الحملة غير موجودة', 404);
        }
        return $this->success(['filename' => 'campaign-' . (int) ($params['id'] ?? 0) . '.csv', 'csv' => $csv]);
    }
}
