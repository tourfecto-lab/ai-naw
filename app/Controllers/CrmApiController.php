<?php

/**
 * Tourfecto - CRM API Controller (إضافي)
 * @version 1.0.0
 *
 * كل نقاط الـAPI الجديدة لموديول AI CRM التي لم تكن موجودة إطلاقًا في
 * CrmController الأصلي (Contacts, Companies, Tasks, Notes, Appointments,
 * Customer 360, Dashboard/Reports, Global Search, Multiple Pipelines,
 * Import/Export). CrmController.php الأصلي لم يُلمس أو يُعدَّل بأي شكل -
 * هذا Controller منفصل تمامًا للحفاظ على "لا تعمل Refactor شامل" (بند 40)
 * وللفصل الواضح بين الخدمات (بند 35).
 *
 * كل Endpoint هنا مربوط بحساب المستخدم الحالي فقط (Tenant Isolation - بند 31):
 * إما عبر user_id مباشرة أو owner_user_id، بنفس نمط CrmController الأصلي.
 */
class CrmApiController extends Controller
{
    private $contactService;
    private $companyService;
    private $leadService;
    private $dealService;
    private $taskService;
    private $noteService;
    private $appointmentService;
    private $customer360Service;
    private $dashboardService;
    private $searchService;
    private $importExportService;
    private $permissionService;
    private $teamService;
    private $templateService;
    private $reportService;
    private $customFieldService;
    private $productService;
    private $leadRoutingService;
    private $lifecycleService;
    private $teamInviteService;
    private $chartService;
    private $activityService;
    private $emailTrackingService;
    private $externalImportService;
    private $reportBuilderService;
    private $sequenceService;
    private $webFormService;

    public function __construct()
    {
        parent::__construct();
        $this->contactService = new CrmContactService();
        $this->companyService = new CrmCompanyService();
        $this->leadService = new CrmLeadService();
        $this->dealService = new CrmDealService();
        $this->taskService = new CrmTaskService();
        $this->noteService = new CrmNoteService();
        $this->appointmentService = new CrmAppointmentService();
        $this->customer360Service = new CrmCustomer360Service();
        $this->dashboardService = new CrmDashboardService();
        $this->searchService = new CrmSearchService();
        $this->importExportService = new CrmImportExportService();
        $this->permissionService = new CrmPermissionService();
        $this->teamService = new CrmTeamService();
        $this->templateService = new CrmMessageTemplateService();
        $this->reportService = new CrmReportService();
        $this->customFieldService = new CrmCustomFieldService();
        $this->productService = new CrmProductService();
        $this->leadRoutingService = new CrmLeadRoutingService();
        $this->lifecycleService = new CrmLifecycleService();
        $this->teamInviteService = new CrmTeamInviteService();
        $this->chartService = new CrmChartService();
        $this->activityService = new CrmActivityService();
        $this->emailTrackingService = new CrmEmailTrackingService();
        $this->externalImportService = new CrmExternalImportService();
        $this->reportBuilderService = new CrmReportBuilderService();
        $this->sequenceService = new CrmSequenceService();
        $this->webFormService = new CrmWebFormService();
    }

    /** المستخدم المسجّل دخوله فعليًا (Actor الحقيقي - يُستخدم لحقول "مين اللي عمل ده") */
    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    /**
     * الحساب (Tenant) الفعلي اللي بيانات CRM تخصّه - نفس uid() لو المستخدم
     * صاحب الحساب، أو حساب صاحب الفريق لو المستخدم عضو مُضاف (بند 30، 31).
     * يُستخدم في كل استعلامات القراءة/العزل بدل uid() مباشرة، لكل الموديولات
     * المبنية بالكامل في هذا الملف (Contacts/Companies/Tasks/Notes/
     * Appointments/Automation/Communication...) - Leads/Deals لسه بتستخدم
     * uid() القديم مباشرة لأنها بتُدار من CrmController الأصلي غير المعدَّل
     * (راجع تعليق CrmPermissionService للتفصيل الكامل).
     */
    private function tenantId(): int
    {
        return $this->permissionService->resolveTenantId($this->uid());
    }

    /** بوابة صلاحية بسيطة - تُستخدم في بداية أي Endpoint حساس (Delete/Export/Manage Settings) */
    private function requirePermission(string $permission)
    {
        if (!$this->permissionService->can($this->uid(), $permission)) {
            return $this->error('ليس لديك صلاحية كافية لتنفيذ هذا الإجراء (' . $permission . ')', 403);
        }
        return null;
    }

    private function handleException(Exception $e, string $logLabel)
    {
        $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
        Logger::error($logLabel . ' Error', ['message' => $e->getMessage()]);
        return $this->error(CustomerError::message($e->getMessage(), 500) ?: 'حدث خطأ غير متوقع', $code);
    }

    // ============================================================
    // Companies (بند 1، 2)
    // ============================================================

    /** GET /api/crm/companies */
    public function listCompanies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $companies = $this->companyService->listForUser($this->tenantId());
            return $this->success(['companies' => array_map(fn ($c) => $c->toArray(), $companies)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listCompanies');
        }
    }

    /** GET /api/crm/companies/search - Filters + Pagination (بند 29، 37) */
    public function searchCompanies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = array_filter([
                'industry' => $this->get('industry'), 'country' => $this->get('country'), 'search' => $this->get('search'),
            ], fn ($v) => $v !== null && $v !== '');
            return $this->success($this->companyService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchCompanies');
        }
    }

    /** POST /api/crm/companies */
    public function createCompany(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم الشركة مطلوب', 422);
        }
        try {
            $company = $this->companyService->create($this->tenantId(), $this->data);
            return $this->success(['company' => $company->toArray()], 'تم إنشاء الشركة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createCompany');
        }
    }

    /** PUT /api/crm/companies/{id} */
    public function updateCompany(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $company = $this->companyService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['company' => $company->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateCompany');
        }
    }

    // ============================================================
    // Contacts (بند 1، 2، 21، 22)
    // ============================================================

    /** GET /api/crm/contacts */
    public function listContacts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contacts = $this->contactService->listForUser($this->tenantId());
            return $this->success(['contacts' => array_map(fn ($c) => $c->toArray(), $contacts)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listContacts');
        }
    }

    /** GET /api/crm/contacts/search - نسخة بـFilters + Pagination حقيقي (بند 29، 37) */
    public function searchContacts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = [
                'status' => $this->get('status'), 'source' => $this->get('source'),
                'country' => $this->get('country'), 'company_id' => $this->get('company_id'),
                'tag' => $this->get('tag'), 'search' => $this->get('search'),
                'created_from' => $this->get('created_from'), 'created_to' => $this->get('created_to'),
                'last_activity_before_days' => $this->get('last_activity_before_days'),
                'min_lead_score' => $this->get('min_lead_score'), 'max_lead_score' => $this->get('max_lead_score'),
                'has_open_deal' => $this->get('has_open_deal'), 'min_deal_value' => $this->get('min_deal_value'),
            ];
            $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');
            $result = $this->contactService->search(
                $this->tenantId(),
                $filters,
                (int) $this->get('page', 1),
                (int) $this->get('per_page', 25)
            );
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'searchContacts');
        }
    }

    /** GET /api/crm/segments (بند 19) */
    public function listSegments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['segments' => (new CrmSegmentService())->listForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listSegments');
        }
    }

    /** POST /api/crm/segments {name, filters} */
    public function createSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['name' => 'required', 'filters' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $filters = $this->get('filters');
            $filters = is_array($filters) ? $filters : (json_decode((string) $filters, true) ?: []);
            $segment = (new CrmSegmentService())->create($this->tenantId(), (string) $this->get('name'), $filters);
            return $this->success(['segment' => $segment->toArray()], 'تم إنشاء القطاع', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createSegment');
        }
    }

    /** DELETE /api/crm/segments/{id} */
    public function deleteSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            (new CrmSegmentService())->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteSegment');
        }
    }

    /** GET /api/crm/segments/{id}/run */
    public function runSegment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $result = (new CrmSegmentService())->run(
                $this->tenantId(),
                (int) ($params['id'] ?? 0),
                (int) $this->get('page', 1),
                (int) $this->get('per_page', 25)
            );
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'runSegment');
        }
    }

    /** GET /api/crm/contacts/{id} */
    public function getContact(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contact = $this->contactService->findOwned($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['contact' => $contact->toArray()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'getContact');
        }
    }

    /** POST /api/crm/contacts */
    public function createContact(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم جهة الاتصال مطلوب', 422);
        }
        try {
            $contact = $this->contactService->create($this->tenantId(), $this->data);
            return $this->success(['contact' => $contact->toArray()], 'تم الإنشاء', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createContact');
        }
    }

    /** PUT /api/crm/contacts/{id} */
    public function updateContact(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $contact = $this->contactService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['contact' => $contact->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateContact');
        }
    }

    /** GET /api/crm/contacts/{id}/duplicates */
    public function contactDuplicates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contact = $this->contactService->findOwned($this->tenantId(), (int) ($params['id'] ?? 0));
            $dupes = $this->contactService->duplicateCandidates(
                $this->tenantId(),
                $contact->getAttribute('email'),
                $contact->getAttribute('phone'),
                (int) $params['id']
            );
            return $this->success(['duplicates' => $dupes]);
        } catch (Exception $e) {
            return $this->handleException($e, 'contactDuplicates');
        }
    }

    /** POST /api/crm/contacts/merge {primary_id, duplicate_id} */
    public function mergeContacts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        if (!$this->validate(['primary_id' => 'required', 'duplicate_id' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $contact = $this->contactService->merge($this->tenantId(), (int) $this->get('primary_id'), (int) $this->get('duplicate_id'));
            return $this->success(['contact' => $contact->toArray()], 'تم الدمج');
        } catch (Exception $e) {
            return $this->handleException($e, 'mergeContacts');
        }
    }

    // ============================================================
    // Customer 360 (بند 2)
    // ============================================================

    /** GET /api/crm/contacts/{id}/360 */
    public function customer360(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $profile = $this->customer360Service->build($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success($profile);
        } catch (Exception $e) {
            return $this->handleException($e, 'customer360');
        }
    }

    // ============================================================
    // Leads - عمليات إضافية (assign/convert/archive) فوق CrmController الحالي
    // ============================================================

    /** GET /api/crm/leads/search - Filters + Pagination (بند 29، 37) */
    public function searchLeads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'source' => $this->get('source'),
                'priority' => $this->get('priority'), 'owner_user_id' => $this->get('owner_user_id'),
                'min_score' => $this->get('min_score'), 'search' => $this->get('search'),
            ], fn ($v) => $v !== null && $v !== '');
            return $this->success($this->leadService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchLeads');
        }
    }

    /** POST /api/crm/leads/{id}/assign {owner_user_id} */
    public function assignLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('assign')) {
            return $denied;
        }
        if (!$this->validate(['owner_user_id' => 'required'])) {
            return $this->error('المسؤول مطلوب', 422);
        }
        try {
            $lead = $this->leadService->assignOwner((int) ($params['id'] ?? 0), (int) $this->get('owner_user_id'), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تم التعيين');
        } catch (Exception $e) {
            return $this->handleException($e, 'assignLead');
        }
    }

    /** POST /api/crm/leads/{id}/convert - تحويل لصفقة */
    public function convertLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $deal = $this->leadService->convertToDeal(
                (int) ($params['id'] ?? 0),
                $this->tenantId(),
                $this->get('stage_id') ? (int) $this->get('stage_id') : null,
                $this->get('value') !== null ? (float) $this->get('value') : null
            );
            return $this->success(['deal' => $deal->toArray()], 'تم التحويل لصفقة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'convertLead');
        }
    }

    /** POST /api/crm/leads/{id}/archive */
    public function archiveLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $lead = $this->leadService->archive((int) ($params['id'] ?? 0), $this->tenantId());
            return $this->success(['lead' => $lead->toArray()], 'تمت الأرشفة');
        } catch (Exception $e) {
            return $this->handleException($e, 'archiveLead');
        }
    }

    // ============================================================
    // Deals - عمليات إضافية (update/delete/at-risk) فوق CrmController الحالي
    // ============================================================

    /** GET /api/crm/deals/search - Filters + Pagination (بند 29، 37) */
    public function searchDeals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'stage_id' => $this->get('stage_id'),
                'pipeline_id' => $this->get('pipeline_id'), 'min_value' => $this->get('min_value'),
                'max_value' => $this->get('max_value'), 'search' => $this->get('search'),
            ], fn ($v) => $v !== null && $v !== '');
            return $this->success($this->dealService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchDeals');
        }
    }

    /** PUT /api/crm/deals/{id} */
    public function updateDeal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $deal = $this->dealService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['deal' => $deal->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateDeal');
        }
    }

    /** DELETE /api/crm/deals/{id} */
    public function deleteDeal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $this->dealService->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteDeal');
        }
    }

    /** GET /api/crm/deals/at-risk (بند 26) */
    public function dealsAtRisk(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['deals' => $this->dealService->atRiskDeals($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'dealsAtRisk');
        }
    }

    // ============================================================
    // Pipelines متعددة (بند 6)
    // ============================================================

    /** GET /api/crm/pipelines */
    public function listPipelines(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $pipelines = (new CrmPipeline())->availableForUser($this->tenantId());
            return $this->success(['pipelines' => $pipelines]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listPipelines');
        }
    }

    /** POST /api/crm/pipelines {name} */
    public function createPipeline(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم المسار مطلوب', 422);
        }
        try {
            $pipeline = new CrmPipeline([
                'user_id' => $this->tenantId(),
                'name' => $this->get('name'),
                'pipeline_key' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))) . '_' . time(),
                'is_default' => 0,
                'sort_order' => 0,
            ]);
            $pipeline->save();
            return $this->success(['pipeline' => $pipeline->toArray()], 'تم إنشاء المسار', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createPipeline');
        }
    }

    /** GET /api/crm/pipelines/{id}/stages */
    public function pipelineStages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $stages = (new CrmPipelineStage())->forPipeline((int) ($params['id'] ?? 0));
            return $this->success(['stages' => $stages]);
        } catch (Exception $e) {
            return $this->handleException($e, 'pipelineStages');
        }
    }

    /** POST /api/crm/pipelines/{id}/stages - مرحلة مخصصة جديدة (بند 6: Admin يقدر ينشئ مراحل مخصصة) */
    public function createPipelineStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم المرحلة مطلوب', 422);
        }
        try {
            $stage = new CrmPipelineStage([
                'pipeline_id' => (int) ($params['id'] ?? 0),
                'name' => $this->get('name'),
                'slug' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))),
                'sort_order' => (int) $this->get('sort_order', 0),
                'win_probability' => (int) $this->get('win_probability', 0),
                'is_won' => $this->get('is_won') ? 1 : 0,
                'is_lost' => $this->get('is_lost') ? 1 : 0,
                'color' => $this->get('color', '#6366f1'),
            ]);
            $stage->save();
            return $this->success(['stage' => $stage->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createPipelineStage');
        }
    }

    // ============================================================
    // Tasks / Follow-ups (بند 11، 13)
    // ============================================================

    /** GET /api/crm/tasks */
    public function listTasks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $tasks = $this->taskService->listForUser($this->tenantId());
            return $this->success(['tasks' => array_map(fn ($t) => $t->toArray(), $tasks)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTasks');
        }
    }

    /** GET /api/crm/tasks/search - Filters + Pagination (بند 29، 37) */
    public function searchTasks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'priority' => $this->get('priority'),
                'related_type' => $this->get('related_type'), 'due_before' => $this->get('due_before'),
                'due_after' => $this->get('due_after'), 'search' => $this->get('search'),
            ], fn ($v) => $v !== null && $v !== '');
            return $this->success($this->taskService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchTasks');
        }
    }

    /** GET /api/crm/tasks/overdue */
    public function overdueTasks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['tasks' => $this->taskService->overdue($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'overdueTasks');
        }
    }

    /** POST /api/crm/tasks */
    public function createTask(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['title' => 'required'])) {
            return $this->error('عنوان المهمة مطلوب', 422);
        }
        try {
            $task = $this->taskService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['task' => $task->toArray()], 'تم إنشاء المهمة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createTask');
        }
    }

    /** POST /api/crm/tasks/{id}/status {status} */
    public function updateTaskStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        if (!$this->validate(['status' => 'required'])) {
            return $this->error('الحالة مطلوبة', 422);
        }
        try {
            $task = $this->taskService->updateStatus($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
            return $this->success(['task' => $task->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateTaskStatus');
        }
    }

    // ============================================================
    // Notes (بند 1)
    // ============================================================

    /** POST /api/crm/notes */
    public function createNote(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['body' => 'required'])) {
            return $this->error('نص الملاحظة مطلوب', 422);
        }
        try {
            $note = $this->noteService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['note' => $note->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createNote');
        }
    }

    // ============================================================
    // Appointments (بند 18)
    // ============================================================

    /** GET /api/crm/appointments */
    public function listAppointments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $meetings = $this->appointmentService->listForUser($this->tenantId());
            return $this->success(['appointments' => array_map(fn ($m) => $m->toArray(), $meetings)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listAppointments');
        }
    }

    /** GET /api/crm/appointments/search - Filters + Pagination (بند 29، 37) */
    public function searchAppointments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $filters = array_filter([
                'status' => $this->get('status'), 'from' => $this->get('from'),
                'to' => $this->get('to'), 'search' => $this->get('search'),
            ], fn ($v) => $v !== null && $v !== '');
            return $this->success($this->appointmentService->search($this->tenantId(), $filters, (int) $this->get('page', 1), (int) $this->get('per_page', 25)));
        } catch (Exception $e) {
            return $this->handleException($e, 'searchAppointments');
        }
    }

    /** POST /api/crm/appointments */
    public function createAppointment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['title' => 'required', 'starts_at' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $meeting = $this->appointmentService->create($this->tenantId(), $this->data, $this->uid());
            return $this->success(['appointment' => $meeting->toArray()], 'تم حجز الموعد', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAppointment');
        }
    }

    /** POST /api/crm/appointments/{id}/status {status} */
    public function updateAppointmentStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        if (!$this->validate(['status' => 'required'])) {
            return $this->error('الحالة مطلوبة', 422);
        }
        try {
            $meeting = $this->appointmentService->updateStatus($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('status'));
            return $this->success(['appointment' => $meeting->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateAppointmentStatus');
        }
    }

    // ============================================================
    // Dashboard / Reports (بند 23، 24)
    // ============================================================

    /** GET /api/crm/dashboard/stats */
    public function dashboardStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->dashboardService->stats($this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'dashboardStats');
        }
    }

    // ============================================================
    // Global Search (بند 28)
    // ============================================================

    /** GET /api/crm/search?q= */
    public function globalSearch(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $q = trim((string) $this->get('q', ''));
        if (strlen($q) < 2) {
            return $this->error('أدخل حرفين على الأقل للبحث', 422);
        }
        try {
            return $this->success($this->searchService->search($this->tenantId(), $q));
        } catch (Exception $e) {
            return $this->handleException($e, 'globalSearch');
        }
    }

    // ============================================================
    // Lead Sources قابلة للتخصيص (بند 4)
    // ============================================================

    /** GET /api/crm/lead-sources */
    public function listLeadSources(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $sources = (new CrmLeadSource())->availableForUser($this->tenantId());
            return $this->success(['sources' => $sources]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listLeadSources');
        }
    }

    /** POST /api/crm/lead-sources {name} */
    public function createLeadSource(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('اسم المصدر مطلوب', 422);
        }
        try {
            $source = new CrmLeadSource([
                'user_id' => $this->tenantId(),
                'name' => $this->get('name'),
                'source_key' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $this->get('name'))),
                'is_active' => 1,
            ]);
            $source->save();
            return $this->success(['source' => $source->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createLeadSource');
        }
    }

    // ============================================================
    // Import / Export (بند 20، 21)
    // ============================================================

    /** POST /api/crm/contacts/import/preview {csv_content, field_mapping} */
    public function importPreview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['csv_content' => 'required', 'field_mapping' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $mapping = $this->get('field_mapping');
            $mapping = is_array($mapping) ? $mapping : json_decode((string) $mapping, true);
            $preview = $this->importExportService->preview($this->tenantId(), (string) $this->get('csv_content'), $mapping ?? []);
            return $this->success($preview);
        } catch (Exception $e) {
            return $this->handleException($e, 'importPreview');
        }
    }

    /** POST /api/crm/contacts/import/commit {rows: [...], skip_duplicates} */
    public function importCommit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['rows' => 'required'])) {
            return $this->error('لا توجد صفوف للاستيراد', 422);
        }
        try {
            $rows = $this->get('rows');
            $rows = is_array($rows) ? $rows : json_decode((string) $rows, true);
            $result = $this->importExportService->commit($this->tenantId(), $rows ?? [], $this->get('skip_duplicates', true) ? true : false);
            return $this->success($result, 'تم الاستيراد');
        } catch (Exception $e) {
            return $this->handleException($e, 'importCommit');
        }
    }

    /**
     * POST /api/crm/contacts/import/commit-async {rows, skip_duplicates} (بند 37)
     * بديل Background للاستيراد الكبير - يرجّع batch_id فورًا بدل ما ينتظر
     * المستخدم لحد ما الاستيراد يخلص بالكامل.
     */
    public function importCommitAsync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['rows' => 'required'])) {
            return $this->error('لا توجد صفوف للاستيراد', 422);
        }
        try {
            $rows = $this->get('rows');
            $rows = is_array($rows) ? $rows : json_decode((string) $rows, true);
            $batch = $this->importExportService->commitAsync($this->tenantId(), $rows ?? [], $this->get('skip_duplicates', true) ? true : false);
            return $this->success(['batch' => $batch->toArray()], 'بدأ الاستيراد في الخلفية', 202);
        } catch (Exception $e) {
            return $this->handleException($e, 'importCommitAsync');
        }
    }

    /** GET /api/crm/contacts/import/status/{id} (بند 37) */
    public function importBatchStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $batch = $this->importExportService->importBatchStatus($this->tenantId(), (int) ($params['id'] ?? 0));
            if (!$batch) {
                return $this->error('الدفعة غير موجودة', 404);
            }
            return $this->success(['batch' => $batch->toArray()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'importBatchStatus');
        }
    }

    /** GET /api/crm/contacts/export */
    public function exportContacts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('export')) {
            return $denied;
        }
        try {
            $csv = $this->importExportService->exportContactsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_contacts.csv"');
            echo "\xEF\xBB\xBF" . $csv; // BOM لدعم العربي في Excel
            exit;
        } catch (Exception $e) {
            Logger::error('exportContacts Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/deals/export (بند 20 - استكمال المرحلة 9) */
    public function exportDeals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('export')) {
            return $denied;
        }
        try {
            $csv = $this->importExportService->exportDealsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_deals.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportDeals Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/tasks/export (بند 20) */
    public function exportTasks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('export')) {
            return $denied;
        }
        try {
            $csv = $this->importExportService->exportTasksCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_tasks.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportTasks Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    /** GET /api/crm/leads/export (بند 20) */
    public function exportLeads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('export')) {
            return $denied;
        }
        try {
            $csv = $this->importExportService->exportLeadsCsv($this->tenantId());
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="crm_leads.csv"');
            echo "\xEF\xBB\xBF" . $csv;
            exit;
        } catch (Exception $e) {
            Logger::error('exportLeads Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التصدير', 500);
        }
    }

    // ============================================================
    // AI CRM - المرحلة 2 (بند 8، 9، 10، 25، 26، 27)
    // كل الخدمات هنا Rule-based شفافة فيما عدا assistantAsk/contactAiSummary
    // اللي بتستخدم GeminiClient الموحّد فقط لصياغة نص من بيانات حقيقية
    // (راجع تعليقات كل Service للتفاصيل الكاملة).
    // ============================================================

    /** POST /api/crm/leads/{id}/score - يحسب ويحفظ AI Lead Score (بند 8) */
    public function scoreLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $lead = (new CrmLeadScoringService())->scoreLead((int) ($params['id'] ?? 0));
            return $this->success(['lead' => $lead->toArray()], 'تم حساب التقييم');
        } catch (Exception $e) {
            return $this->handleException($e, 'scoreLead');
        }
    }

    /** GET /api/crm/leads/{id}/next-best-action (بند 10) */
    public function leadNextBestAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success((new CrmNextBestActionService())->forLead((int) ($params['id'] ?? 0)));
        } catch (Exception $e) {
            return $this->handleException($e, 'leadNextBestAction');
        }
    }

    /** GET /api/crm/deals/{id}/next-best-action (بند 10) */
    public function dealNextBestAction(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success((new CrmNextBestActionService())->forDeal((int) ($params['id'] ?? 0), $this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'dealNextBestAction');
        }
    }

    /** GET /api/crm/forecast (بند 25، 26) */
    public function forecast(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success((new CrmForecastService())->forecast($this->tenantId()));
        } catch (Exception $e) {
            return $this->handleException($e, 'forecast');
        }
    }

    /** POST /api/crm/assistant/ask {question} (بند 9) - يستهلك AI Credits (استدعاء GeminiClient فعلي) */
    public function assistantAsk(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['question' => 'required'])) {
            return $this->error('السؤال مطلوب', 422);
        }
        try {
            $result = (new CrmAiAssistantService())->ask($this->tenantId(), (string) $this->get('question'));
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'assistantAsk');
        }
    }

    /** GET /api/crm/contacts/{id}/ai-summary (بند 27) - يستهلك AI Credits */
    public function contactAiSummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $result = (new CrmAiSummaryService())->summarizeContact($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success($result);
        } catch (Exception $e) {
            return $this->handleException($e, 'contactAiSummary');
        }
    }

    // ============================================================
    // AI CRM - المرحلة 3 (بند 12، 15، 16، 17، 36)
    // Automation Workflows + WhatsApp/Email Communication
    // ============================================================

    /** GET /api/crm/automation/rules */
    public function listAutomationRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $rules = (new CrmAutomationService())->listForUser($this->tenantId());
            return $this->success(['rules' => array_map(fn ($r) => $r->toArray(), $rules)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listAutomationRules');
        }
    }

    /** GET /api/crm/automation/templates - أمثلة جاهزة مطابقة للطلب الأصلي (بند 12) */
    public function automationTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['templates' => CrmAutomationService::TEMPLATES]);
    }

    /** GET /api/crm/automation/schema - مصدر الحقيقة لأدوات الـVisual Builder (بند 12) */
    public function automationSchema(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(CrmAutomationService::SCHEMA);
    }

    /** POST /api/crm/automation/rules {name, trigger_event, conditions?, actions} */
    public function createAutomationRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['name' => 'required', 'trigger_event' => 'required', 'actions' => 'required'])) {
            return $this->error('بيانات القاعدة ناقصة', 422);
        }
        try {
            $rule = (new CrmAutomationService())->create($this->tenantId(), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم إنشاء القاعدة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAutomationRule');
        }
    }

    /** PUT /api/crm/automation/rules/{id} {name, trigger_event, conditions?, actions} */
    public function updateAutomationRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['name' => 'required', 'trigger_event' => 'required', 'actions' => 'required'])) {
            return $this->error('بيانات القاعدة ناقصة', 422);
        }
        try {
            $rule = (new CrmAutomationService())->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateAutomationRule');
        }
    }

    /** POST /api/crm/automation/rules/from-template {template_key} */
    public function createAutomationRuleFromTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['template_key' => 'required'])) {
            return $this->error('حدّد القالب', 422);
        }
        try {
            $rule = (new CrmAutomationService())->createFromTemplate($this->tenantId(), (string) $this->get('template_key'));
            return $this->success(['rule' => $rule->toArray()], 'تم إنشاء القاعدة من القالب', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createAutomationRuleFromTemplate');
        }
    }

    /** POST /api/crm/automation/rules/{id}/toggle */
    public function toggleAutomationRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $rule = (new CrmAutomationService())->toggle($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['rule' => $rule->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'toggleAutomationRule');
        }
    }

    /** DELETE /api/crm/automation/rules/{id} */
    public function deleteAutomationRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            (new CrmAutomationService())->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم الحذف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteAutomationRule');
        }
    }

    /** GET /api/crm/conversations */
    public function listConversations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['conversations' => (new CrmConversation())->allForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listConversations');
        }
    }

    /** GET /api/crm/conversations/{id}/messages */
    public function conversationMessages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $conversation = (new CrmConversation())->find((int) ($params['id'] ?? 0));
            if (!$conversation || (int) $conversation->getAttribute('user_id') !== $this->tenantId()) {
                return $this->error('المحادثة غير موجودة', 404);
            }
            return $this->success(['messages' => (new CrmMessage())->forConversation((int) $conversation->getAttribute('id'))]);
        } catch (Exception $e) {
            return $this->handleException($e, 'conversationMessages');
        }
    }

    /** POST /api/crm/contacts/{id}/send-whatsapp {text} (بند 16) */
    public function sendWhatsApp(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['text' => 'required'])) {
            return $this->error('نص الرسالة مطلوب', 422);
        }
        try {
            $result = (new CrmWhatsAppService($this->tenantId(),(int)$this->get('website_id',0)))->sendToContact($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('text'),(int)$this->get('website_id',0));
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendWhatsApp');
        }
    }

    /** POST /api/crm/contacts/{id}/send-email {subject, body} (بند 17) */
    public function sendEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['subject' => 'required', 'body' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $result = (new CrmEmailService())->sendToContact(
                $this->tenantId(),
                (int) ($params['id'] ?? 0),
                (string) $this->get('subject'),
                (string) $this->get('body')
            );
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendEmail');
        }
    }

    /** POST /api/crm/contacts/{id}/send-sms {text} (بند 15) */
    public function sendSms(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        if (!$this->validate(['text' => 'required'])) {
            return $this->error('نص الرسالة مطلوب', 422);
        }
        try {
            $result = (new CrmSmsService())->sendToContact($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('text'));
            return !empty($result['success']) ? $this->success($result, 'تم الإرسال') : $this->error($result['error'] ?? 'فشل الإرسال', 422);
        } catch (Exception $e) {
            return $this->handleException($e, 'sendSms');
        }
    }

    /** GET /api/crm/communication/status - هل واتساب/إيميل/SMS مفعّلين فعليًا؟ */
    public function communicationStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $q=Database::getInstance()->getConnection()->prepare("SELECT DISTINCT w.id,w.domain FROM websites w JOIN platform_connections c ON c.website_id=w.id AND c.user_id=w.user_id WHERE w.user_id=? AND c.platform='whatsapp_cloud' AND c.status='connected' ORDER BY w.id");
        $q->execute([$this->tenantId()]);
        $sites=$q->fetchAll(PDO::FETCH_ASSOC);
        return $this->success([
            'whatsapp_websites' => $sites,
            'whatsapp_configured' => (int)$this->get('website_id',0)>0 ? (new CrmWhatsAppService($this->tenantId(),(int)$this->get('website_id',0)))->isConfigured() : !empty($sites),
            'email_configured' => (new CrmEmailService())->isConfigured(),
            'sms_configured' => (new CrmSmsService())->isConfigured(),
        ]);
    }

    // ============================================================
    // AI CRM - المرحلة 5 (بند 30) - Team & Roles/Permissions
    // ============================================================

    /** GET /api/crm/team - قائمة الفريق + دوري وصلاحياتي في هذا الحساب */
    public function listTeam(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success([
                'members' => $this->teamService->listForTenant($this->tenantId()),
                'my_role' => $this->permissionService->roleFor($this->uid()),
                'my_permissions' => $this->permissionService->permissionsFor($this->uid()),
                'is_tenant_owner' => $this->tenantId() === $this->uid(),
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTeam');
        }
    }

    /** POST /api/crm/team {email, role} - إضافة عضو (لازم يكون له حساب Tourfecto بالفعل) */
    public function addTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['email' => 'required', 'role' => 'required'])) {
            return $this->error('البريد والدور مطلوبان', 422);
        }
        try {
            $member = $this->teamService->addMember($this->tenantId(), $this->uid(), (string) $this->get('email'), (string) $this->get('role'));
            return $this->success(['member' => $member->toArray()], 'تمت الإضافة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'addTeamMember');
        }
    }

    /** PUT /api/crm/team/{id} {role} */
    public function updateTeamMemberRole(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['role' => 'required'])) {
            return $this->error('الدور مطلوب', 422);
        }
        try {
            $member = $this->teamService->updateRole($this->tenantId(), (int) ($params['id'] ?? 0), (string) $this->get('role'));
            return $this->success(['member' => $member->toArray()], 'تم التحديث');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateTeamMemberRole');
        }
    }

    /** DELETE /api/crm/team/{id} */
    public function removeTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->teamService->removeMember($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تمت الإزالة');
        } catch (Exception $e) {
            return $this->handleException($e, 'removeTeamMember');
        }
    }

    // ============================================================
    // المرحلة 12 (G1) - Message Templates (Email/WhatsApp/SMS)
    // ============================================================

    /** GET /api/crm/templates?channel=whatsapp (اختياري) */
    public function listTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $templates = $this->templateService->listForUser($this->tenantId(), (string) $this->get('channel', ''));
            return $this->success(['templates' => array_map(fn ($t) => $t->toArray(), $templates)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTemplates');
        }
    }

    /** GET /api/crm/templates/variables - المتغيرات المسموحة في القوالب */
    public function templateVariables(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success(['variables' => $this->templateService->variables()]);
    }

    /** POST /api/crm/templates {channel, name, subject?, body, variables?} */
    public function createTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $template = $this->templateService->create($this->tenantId(), $this->uid(), $this->data);
            return $this->success(['template' => $template->toArray()], 'تم إنشاء القالب', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createTemplate');
        }
    }

    /** PUT /api/crm/templates/{id} */
    public function updateTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $template = $this->templateService->update($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['template' => $template->toArray()], 'تم تحديث القالب');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateTemplate');
        }
    }

    /** DELETE /api/crm/templates/{id} */
    public function deleteTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->templateService->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف القالب');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteTemplate');
        }
    }

    /** POST /api/crm/templates/{id}/render {context} - تجربة القالب قبل الإرسال */
    public function renderTemplate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $context = is_array($this->get('context')) ? $this->get('context') : [];
            $rendered = $this->templateService->render($this->tenantId(), (int) ($params['id'] ?? 0), $context);
            return $this->success($rendered);
        } catch (Exception $e) {
            return $this->handleException($e, 'renderTemplate');
        }
    }

    // ============================================================
    // المرحلة 12 (G4) - Win/Loss Analysis + Sales Goals
    // ============================================================

    /** GET /api/crm/reports/win-loss?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function winLossReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $report = $this->reportService->winLoss(
                $this->tenantId(),
                (string) $this->get('from', ''),
                (string) $this->get('to', '')
            );
            return $this->success($report);
        } catch (Exception $e) {
            return $this->handleException($e, 'winLossReport');
        }
    }

    /** GET /api/crm/reports/sales-goals - الأهداف مع الإنجاز */
    public function salesGoalsReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['goals' => $this->reportService->salesGoals($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'salesGoalsReport');
        }
    }

    /** POST /api/crm/reports/sales-goals {period, target_value} - إنشاء/تحديث هدف شهر */
    public function setSalesGoal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        if (!$this->validate(['period' => 'required'])) {
            return $this->error('الشهر مطلوب بصيغة YYYY-MM', 422);
        }
        try {
            $goal = $this->reportService->setGoal(
                $this->tenantId(),
                (string) $this->get('period'),
                (float) $this->get('target_value', 0)
            );
            return $this->success(['goal' => $goal], 'تم حفظ الهدف', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'setSalesGoal');
        }
    }

    /** DELETE /api/crm/reports/sales-goals/{id} */
    public function deleteSalesGoal(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->reportService->deleteGoal($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف الهدف');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteSalesGoal');
        }
    }

    // ============================================================
    // المرحلة 12 (G2) - Custom Fields (حقول مخصصة لكل كيان)
    // ============================================================

    /** GET /api/crm/custom-fields?entity_type=contact (اختياري) */
    public function listCustomFields(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $fields = $this->customFieldService->definitions($this->tenantId(), (string) $this->get('entity_type', ''));
            return $this->success(['fields' => $fields]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listCustomFields');
        }
    }

    /** POST /api/crm/custom-fields {entity_type, field_key, label, field_type?, options?} */
    public function createCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $field = $this->customFieldService->createDefinition($this->tenantId(), $this->data);
            return $this->success(['field' => $field->toArray()], 'تم إنشاء الحقل', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createCustomField');
        }
    }

    /** PUT /api/crm/custom-fields/{id} {label?, options?} */
    public function updateCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $field = $this->customFieldService->updateDefinition($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['field' => $field->toArray()], 'تم تحديث الحقل');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateCustomField');
        }
    }

    /** DELETE /api/crm/custom-fields/{id} */
    public function deleteCustomField(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->customFieldService->deleteDefinition($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف الحقل');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteCustomField');
        }
    }

    /** GET /api/crm/entities/{entityType}/{id}/custom-fields - قراءة قيم كيان */
    public function getEntityCustomFields(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $values = $this->customFieldService->getValues(
                $this->tenantId(),
                (string) ($params['entityType'] ?? ''),
                (int) ($params['id'] ?? 0)
            );
            return $this->success(['fields' => $values]);
        } catch (Exception $e) {
            return $this->handleException($e, 'getEntityCustomFields');
        }
    }

    /** POST /api/crm/entities/{entityType}/{id}/custom-fields {values: {key: value}} - كتابة قيم كيان */
    public function setEntityCustomFields(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $values = is_array($this->get('values')) ? $this->get('values') : [];
            $saved = $this->customFieldService->setValues(
                $this->tenantId(),
                (string) ($params['entityType'] ?? ''),
                (int) ($params['id'] ?? 0),
                $values
            );
            return $this->success(['saved' => $saved], 'تم حفظ القيم');
        } catch (Exception $e) {
            return $this->handleException($e, 'setEntityCustomFields');
        }
    }
    // المرحلة 13 (G3) - Product Catalog & Deal Line Items
    // ============================================================

    /** GET /api/crm/products?only_active=1 */
    public function listProducts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $products = $this->productService->listProducts($this->tenantId(), (int) $this->get('only_active', 0) === 1);
            return $this->success(['products' => $products]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listProducts');
        }
    }

    /** POST /api/crm/products {name, price, currency?, description?, sku?} */
    public function createProduct(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $product = $this->productService->createProduct($this->tenantId(), $this->data);
            return $this->success(['product' => $product->toArray()], 'تم إنشاء المنتج', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createProduct');
        }
    }

    /** PUT /api/crm/products/{id} */
    public function updateProduct(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $product = $this->productService->updateProduct($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['product' => $product->toArray()], 'تم تحديث المنتج');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateProduct');
        }
    }

    /** DELETE /api/crm/products/{id} */
    public function deleteProduct(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->productService->deleteProduct($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف المنتج');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteProduct');
        }
    }

    /** GET /api/crm/deals/{id}/items - بنود صفقة */
    public function listDealItems(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $items = $this->productService->listDealItems($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['items' => $items]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listDealItems');
        }
    }

    /** POST /api/crm/deals/{id}/items {product_id?, product_name?, unit_price, quantity?, discount?} */
    public function addDealItem(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $item = $this->productService->addDealItem($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['item' => $item->toArray()], 'تمت إضافة البند', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'addDealItem');
        }
    }

    /** PUT /api/crm/deals/{id}/items/{itemId} */
    public function updateDealItem(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $item = $this->productService->updateDealItem(
                $this->tenantId(),
                (int) ($params['id'] ?? 0),
                (int) ($params['itemId'] ?? 0),
                $this->data
            );
            return $this->success(['item' => $item->toArray()], 'تم تحديث البند');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateDealItem');
        }
    }

    /** DELETE /api/crm/deals/{id}/items/{itemId} */
    public function removeDealItem(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->productService->removeDealItem($this->tenantId(), (int) ($params['id'] ?? 0), (int) ($params['itemId'] ?? 0));
            return $this->success([], 'تم حذف البند');
        } catch (Exception $e) {
            return $this->handleException($e, 'removeDealItem');
        }
    }

    // ============================================================
    // المرحلة 13 (G5) - Lead Routing Rules
    // ============================================================

    /** GET /api/crm/routing-rules */
    public function listRoutingRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['rules' => $this->leadRoutingService->listRules($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listRoutingRules');
        }
    }

    /** POST /api/crm/routing-rules */
    public function createRoutingRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $rule = $this->leadRoutingService->createRule($this->tenantId(), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم إنشاء القاعدة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createRoutingRule');
        }
    }

    /** PUT /api/crm/routing-rules/{id} */
    public function updateRoutingRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $rule = $this->leadRoutingService->updateRule($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['rule' => $rule->toArray()], 'تم تحديث القاعدة');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateRoutingRule');
        }
    }

    /** DELETE /api/crm/routing-rules/{id} */
    public function deleteRoutingRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->leadRoutingService->deleteRule($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف القاعدة');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteRoutingRule');
        }
    }

    /** POST /api/crm/leads/{id}/route - تطبيق قواعد التوجيه على Lead */
    public function routeLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $result = $this->leadRoutingService->routeLead(
                $this->tenantId(),
                (int) ($params['id'] ?? 0),
                is_array($this->data) ? $this->data : []
            );
            return $this->success($result, $result['assigned'] ? 'تم توجيه الـLead' : 'لا توجد قاعدة مطابقة');
        } catch (Exception $e) {
            return $this->handleException($e, 'routeLead');
        }
    }

    // ============================================================
    // المرحلة 13 (G6) - Contact Lifecycle
    // ============================================================

    /** GET /api/crm/lifecycle/stages */
    public function listLifecycleStages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $stages = $this->lifecycleService->listStages($this->tenantId());
            $distribution = $this->lifecycleService->distribution($this->tenantId());
            return $this->success(['stages' => $stages, 'distribution' => $distribution]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listLifecycleStages');
        }
    }

    /** POST /api/crm/lifecycle/stages {stage_key, name, color?, sort_order?} - مرحلة مخصصة */
    public function createLifecycleStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $stage = $this->lifecycleService->createStage($this->tenantId(), $this->data);
            return $this->success(['stage' => $stage->toArray()], 'تم إنشاء المرحلة', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createLifecycleStage');
        }
    }

    /** PUT /api/crm/lifecycle/stages/{id} */
    public function updateLifecycleStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $stage = $this->lifecycleService->updateStage($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['stage' => $stage->toArray()], 'تم تحديث المرحلة');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateLifecycleStage');
        }
    }

    /** DELETE /api/crm/lifecycle/stages/{id} */
    public function deleteLifecycleStage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->lifecycleService->deleteStage($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف المرحلة');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteLifecycleStage');
        }
    }

    /** PUT /api/crm/contacts/{id}/lifecycle {stage_key} - تعيين مرحلة دورة حياة */
    public function setContactLifecycle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $contact = $this->lifecycleService->setStage(
                $this->tenantId(),
                (int) ($params['id'] ?? 0),
                (string) $this->get('stage_key')
            );
            return $this->success(['contact' => $contact->toArray()], 'تم تحديث مرحلة دورة الحياة');
        } catch (Exception $e) {
            return $this->handleException($e, 'setContactLifecycle');
        }
    }

    /** GET /api/crm/lifecycle/contacts?stage_key=qualified - فلترة جهات الاتصال بالمرحلة */
    public function contactsByLifecycle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contacts = $this->lifecycleService->contactsByStage($this->tenantId(), (string) $this->get('stage_key', ''));
            return $this->success(['contacts' => $contacts]);
        } catch (Exception $e) {
            return $this->handleException($e, 'contactsByLifecycle');
        }
    }

    // ============================================================
    // المرحلة 13 (G9) - Team Invite (via WorkspaceInvite)
    // ============================================================

    /** POST /api/crm/team/invite {email, role} - دعوة عضو (بريد مسجّل → إضافة مباشرة) */
    public function inviteTeamMember(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $result = $this->teamInviteService->invite(
                $this->tenantId(),
                $this->uid(),
                (string) $this->get('email'),
                (string) $this->get('role')
            );
            $message = $result['mode'] === 'direct_added'
                ? 'أُضيف العضو مباشرة (البريد مسجّل مسبقًا)'
                : ($result['email_sent'] ? 'تم إرسال الدعوة بالبريد الإلكتروني' : 'تم إنشاء الدعوة - انسخ الرابط وابعته يدويًا (البريد غير مُفعّل)');
            return $this->success($result, $message, $result['mode'] === 'direct_added' ? 201 : 200);
        } catch (Exception $e) {
            return $this->handleException($e, 'inviteTeamMember');
        }
    }

    /** GET /api/crm/team/invites */
    public function listTeamInvites(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['invites' => $this->teamInviteService->listInvites($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listTeamInvites');
        }
    }

    /** POST /api/crm/team/invites/{id}/revoke */
    public function revokeTeamInvite(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('manage_settings')) {
            return $denied;
        }
        try {
            $this->teamInviteService->revokeInvite($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم إلغاء الدعوة');
        } catch (Exception $e) {
            return $this->handleException($e, 'revokeTeamInvite');
        }
    }

    /** GET /api/crm/team/invite/{token} - عام (بلا Auth): بيانات دعوة لصفحة القبول */
    public function showTeamInvite(array $params = []): array
    {
        try {
            return $this->success($this->teamInviteService->showInvite((string) ($params['token'] ?? '')));
        } catch (Exception $e) {
            return $this->handleException($e, 'showTeamInvite');
        }
    }

    /** POST /api/crm/team/invite/{token}/accept - عام (بلا Auth): قبول دعوة وإنشاء حساب */
    public function acceptTeamInvite(array $params = []): array
    {
        try {
            if (!$this->validate(['first_name' => 'required', 'last_name' => 'required', 'password' => 'required|min:8'])) {
                return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
            }
            $result = $this->teamInviteService->acceptInvite(
                (string) ($params['token'] ?? ''),
                (string) $this->get('first_name'),
                (string) $this->get('last_name'),
                (string) $this->get('password')
            );
            return $this->success($result, 'تم قبول الدعوة والانضمام للفريق', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'acceptTeamInvite');
        }
    }

    // المرحلة 14 (G7) - Charts & Visualizations
    // ============================================================

    /** GET /api/crm/charts/pipeline - توزيع الصفقات المفتوحة على المراحل */
    public function chartPipeline(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['chart' => $this->chartService->pipelineChart($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartPipeline');
        }
    }

    /** GET /api/crm/charts/revenue-trend?months=12 */
    public function chartRevenueTrend(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $months = (int) $this->get('months', 12);
            return $this->success(['chart' => $this->chartService->revenueTrend($this->tenantId(), $months)]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartRevenueTrend');
        }
    }

    /** GET /api/crm/charts/win-loss?from=YYYY-MM-DD&to=YYYY-MM-DD */
    public function chartWinLoss(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $chart = $this->chartService->winLossTrend(
                $this->tenantId(),
                (string) $this->get('from'),
                (string) $this->get('to')
            );
            return $this->success(['chart' => $chart]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartWinLoss');
        }
    }

    /** GET /api/crm/charts/lead-sources */
    public function chartLeadSources(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['chart' => $this->chartService->leadSourceDistribution($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartLeadSources');
        }
    }

    /** GET /api/crm/charts/deal-status */
    public function chartDealStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['chart' => $this->chartService->dealStatusDistribution($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartDealStatus');
        }
    }

    /** GET /api/crm/charts/lifecycle */
    public function chartLifecycle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['chart' => $this->chartService->lifecycleDistribution($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'chartLifecycle');
        }
    }

    // ============================================================
    // المرحلة 14 (G8) - Email Open Tracking
    // ============================================================

    /**
     * GET /api/crm/email-track/{token}.gif - بكسل التتبع (مسار عام بلا
     * AuthMiddleware عمدًا: عميل البريد لا يحمل جلسة المستخدم). يرجع
     * صورة GIF 1x1 شفافة بعد تسجيل الفتح.
     */
    public function emailTrackingPixel(array $params = []): array
    {
        $raw = (string) ($params['token'] ?? '');
        $token = preg_replace('/\.gif$/', '', $raw);

        if ($token === '') {
            http_response_code(404);
            header('Content-Type: image/gif');
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        $this->emailTrackingService->recordOpen($token);

        header('Content-Type: image/gif');
        header('Content-Length: 43');
        http_response_code(200);
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    /** POST /api/crm/email-track {contact_id, subject, html_body, message_id?} - يرسل إيميل ببكسل تتبع */
    public function sendTrackedEmail(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contactId = (int) $this->get('contact_id', 0);
            $subject = (string) $this->get('subject');
            $htmlBody = (string) $this->get('html_body');
            if ($contactId <= 0) {
                return $this->error('معرّف جهة الاتصال مطلوب', 422);
            }
            if ($subject === '' || $htmlBody === '') {
                return $this->error('العنوان والجسم مطلوبان', 422);
            }

            $track = $this->emailTrackingService->appendPixel($htmlBody, $this->tenantId(), $contactId, $subject);
            $sent = (new CrmEmailService())->sendToContact($this->tenantId(), $contactId, $subject, $track['html_body']);

            return $this->success([
                'sent' => $sent,
                'tracking_token' => $track['token'],
            ], 'تم إرسال الإيميل مع تتبع الفتح');
        } catch (Exception $e) {
            return $this->handleException($e, 'sendTrackedEmail');
        }
    }

    /** GET /api/crm/email-track/stats?contact_id= - إحصاءات الفتح */
    public function emailTrackingStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $contactId = $this->get('contact_id') !== null ? (int) $this->get('contact_id') : null;
            $stats = $this->emailTrackingService->stats($this->tenantId(), $contactId);
            $stats['recent'] = $this->emailTrackingService->recent($this->tenantId(), 20);
            return $this->success(['stats' => $stats]);
        } catch (Exception $e) {
            return $this->handleException($e, 'emailTrackingStats');
        }
    }

    // ============================================================
    // المرحلة 14 (G10) - Custom Activity Types
    // ============================================================

    /** GET /api/crm/activity-types */
    public function listActivityTypes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['activity_types' => $this->activityService->listTypes($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listActivityTypes');
        }
    }

    /** POST /api/crm/activity-types {name, type_key?, icon?, color?} */
    public function createActivityType(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $type = $this->activityService->createType($this->tenantId(), $this->data);
            return $this->success(['activity_type' => $type->toArray()], 'تم إنشاء النوع', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createActivityType');
        }
    }

    /** PUT /api/crm/activity-types/{id} */
    public function updateActivityType(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('edit')) {
            return $denied;
        }
        try {
            $type = $this->activityService->updateType($this->tenantId(), (int) ($params['id'] ?? 0), $this->data);
            return $this->success(['activity_type' => $type->toArray()], 'تم تحديث النوع');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateActivityType');
        }
    }

    /** DELETE /api/crm/activity-types/{id} */
    public function deleteActivityType(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->activityService->deleteType($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف النوع');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteActivityType');
        }
    }

    /** GET /api/crm/activities?related_type=contact&related_id=5&activity_type_id=2 */
    public function listActivities(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $relatedType = $this->get('related_type') !== null ? (string) $this->get('related_type') : null;
            $relatedId = $this->get('related_id') !== null ? (int) $this->get('related_id') : null;
            $activityTypeId = $this->get('activity_type_id') !== null ? (int) $this->get('activity_type_id') : null;
            $activities = $this->activityService->listActivities($this->tenantId(), $relatedType, $relatedId, $activityTypeId, (int) $this->get('limit', 100));
            $distribution = $this->activityService->distributionByType($this->tenantId());
            return $this->success(['activities' => $activities, 'distribution' => $distribution]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listActivities');
        }
    }

    /** POST /api/crm/activities {activity_type_id, subject, related_type?, related_id?, notes?, performed_at?} */
    public function createActivity(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $activity = $this->activityService->recordActivity($this->tenantId(), $this->data);
            return $this->success(['activity' => $activity->toArray()], 'تم تسجيل النشاط', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createActivity');
        }
    }

    /** DELETE /api/crm/activities/{id} */
    public function deleteActivity(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->activityService->deleteActivity($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف النشاط');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteActivity');
        }
    }

    // ============================================================
    // Web Forms - التقاط Leads (المرحلة 15 - G11)
    // ============================================================

    /** GET /api/crm/web-forms - قائمة نماذج الحساب */
    public function listWebForms(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['web_forms' => $this->webFormService->listForms($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listWebForms');
        }
    }

    /** POST /api/crm/web-forms {name, slug?, fields?, success_message?, redirect_url?, owner_user_id?, source?} */
    public function createWebForm(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $form = $this->webFormService->saveForm($this->tenantId(), $this->data);
            return $this->success(['web_form' => $form->toArray()], 'تم إنشاء النموذج', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createWebForm');
        }
    }

    /** PUT /api/crm/web-forms/{id} */
    public function updateWebForm(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $form = $this->webFormService->saveForm($this->tenantId(), $this->data, (int) ($params['id'] ?? 0));
            return $this->success(['web_form' => $form->toArray()], 'تم تحديث النموذج');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateWebForm');
        }
    }

    /** DELETE /api/crm/web-forms/{id} */
    public function deleteWebForm(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->webFormService->deleteForm($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف النموذج');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteWebForm');
        }
    }

    /** GET /api/crm/web-forms/submissions - إرسالات النماذج (اختياري ?form_id) */
    public function webFormSubmissions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $formId = $this->get('form_id') !== null ? (int) $this->get('form_id') : null;
            return $this->success([
                'submissions' => $this->webFormService->submissions($this->tenantId(), $formId, (int) $this->get('limit', 100)),
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, 'webFormSubmissions');
        }
    }

    /** POST /api/crm/public/web-forms/{slug}/submit - إرسال عام (بلا جلسة) */
    public function submitWebForm(array $params = []): array
    {
        try {
            $result = $this->webFormService->handleSubmission((string) ($params['slug'] ?? ''), $this->data);
            if (!empty($result['ignored'])) {
                return $this->success(['success' => true, 'ignored' => true]);
            }
            return $this->success([
                'success' => true,
                'message' => $result['success_message'],
                'redirect_url' => $result['redirect_url'],
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, 'submitWebForm');
        }
    }

    // ============================================================
    // Sales Sequences (المرحلة 15 - G12)
    // ============================================================

    /** GET /api/crm/sequences/schema - أنواع الخطوات المدعومة */
    public function sequenceSchema(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['schema' => $this->sequenceService->schema()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'sequenceSchema');
        }
    }

    /** GET /api/crm/sequences - قائمة التسلسلات */
    public function listSequences(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['sequences' => $this->sequenceService->listForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listSequences');
        }
    }

    /** POST /api/crm/sequences {name, description?, steps: [...]} */
    public function createSequence(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $seq = $this->sequenceService->save($this->tenantId(), $this->data);
            return $this->success(['sequence' => $seq->toArray()], 'تم إنشاء التسلسل', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'createSequence');
        }
    }

    /** PUT /api/crm/sequences/{id} */
    public function updateSequence(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $seq = $this->sequenceService->save($this->tenantId(), $this->data, (int) ($params['id'] ?? 0));
            return $this->success(['sequence' => $seq->toArray()], 'تم تحديث التسلسل');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateSequence');
        }
    }

    /** DELETE /api/crm/sequences/{id} */
    public function deleteSequence(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->sequenceService->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف التسلسل');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteSequence');
        }
    }

    /** POST /api/crm/sequences/{id}/enroll {related_type, related_id} */
    public function enrollInSequence(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $data = $this->data;
            $data['sequence_id'] = (int) ($params['id'] ?? 0);
            $enrollment = $this->sequenceService->enroll($this->tenantId(), $data);
            return $this->success(['enrollment' => $enrollment->toArray()], 'تم تسجيل الكيان في التسلسل', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'enrollInSequence');
        }
    }

    /** GET /api/crm/sequences/enrollments - قائمة التسجيلات (?status=active|completed) */
    public function listSequenceEnrollments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $status = (string) $this->get('status', 'active');
            return $this->success([
                'enrollments' => $this->sequenceService->enrollments($this->tenantId(), $status, (int) $this->get('limit', 100)),
            ]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listSequenceEnrollments');
        }
    }

    /** POST /api/crm/sequences/enrollments/process-due - تنفيذ الخطوات المستحقة (Job دوري) */
    public function processDueSequences(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $results = $this->sequenceService->processDue($this->tenantId(), (int) $this->get('limit', 50));
            return $this->success(['processed' => $results]);
        } catch (Exception $e) {
            return $this->handleException($e, 'processDueSequences');
        }
    }

    /** POST /api/crm/sequences/enrollments/{id}/pause */
    public function pauseSequenceEnrollment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $enrollment = $this->sequenceService->pause($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['enrollment' => $enrollment->toArray()], 'تم إيقاف التسجيل مؤقتًا');
        } catch (Exception $e) {
            return $this->handleException($e, 'pauseSequenceEnrollment');
        }
    }

    /** POST /api/crm/sequences/enrollments/{id}/resume */
    public function resumeSequenceEnrollment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $enrollment = $this->sequenceService->resume($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['enrollment' => $enrollment->toArray()], 'تم استئناف التسجيل');
        } catch (Exception $e) {
            return $this->handleException($e, 'resumeSequenceEnrollment');
        }
    }

    /** POST /api/crm/sequences/enrollments/{id}/cancel */
    public function cancelSequenceEnrollment(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $enrollment = $this->sequenceService->cancel($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success(['enrollment' => $enrollment->toArray()], 'تم إلغاء التسجيل');
        } catch (Exception $e) {
            return $this->handleException($e, 'cancelSequenceEnrollment');
        }
    }

    // ============================================================
    // Report Builder (المرحلة 15 - G13)
    // ============================================================

    /** GET /api/crm/reports/builder/schema - الكيانات والحقول المتاحة */
    public function reportBuilderSchema(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['schema' => $this->reportBuilderService->schema()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'reportBuilderSchema');
        }
    }

    /** GET /api/crm/reports/builder - قائمة التقارير المحفوظة */
    public function listSavedReports(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['reports' => $this->reportBuilderService->listForUser($this->tenantId())]);
        } catch (Exception $e) {
            return $this->handleException($e, 'listSavedReports');
        }
    }

    /** POST /api/crm/reports/builder {name, entity, config} */
    public function saveReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $report = $this->reportBuilderService->save($this->tenantId(), $this->data);
            return $this->success(['report' => $report->toArray()], 'تم حفظ التقرير', 201);
        } catch (Exception $e) {
            return $this->handleException($e, 'saveReport');
        }
    }

    /** PUT /api/crm/reports/builder/{id} */
    public function updateSavedReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $report = $this->reportBuilderService->save($this->tenantId(), $this->data, (int) ($params['id'] ?? 0));
            return $this->success(['report' => $report->toArray()], 'تم تحديث التقرير');
        } catch (Exception $e) {
            return $this->handleException($e, 'updateSavedReport');
        }
    }

    /** POST /api/crm/reports/builder/run - تنفيذ تقرير فوري {entity, config} */
    public function runReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->reportBuilderService->execute(
                $this->tenantId(),
                (string) ($this->data['entity'] ?? ''),
                $this->data['config'] ?? []
            ));
        } catch (Exception $e) {
            return $this->handleException($e, 'runReport');
        }
    }

    /** GET /api/crm/reports/builder/{id}/run - تنفيذ تقرير محفوظ */
    public function runSavedReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->reportBuilderService->run($this->tenantId(), (int) ($params['id'] ?? 0)));
        } catch (Exception $e) {
            return $this->handleException($e, 'runSavedReport');
        }
    }

    /** DELETE /api/crm/reports/builder/{id} */
    public function deleteSavedReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('delete')) {
            return $denied;
        }
        try {
            $this->reportBuilderService->delete($this->tenantId(), (int) ($params['id'] ?? 0));
            return $this->success([], 'تم حذف التقرير');
        } catch (Exception $e) {
            return $this->handleException($e, 'deleteSavedReport');
        }
    }

    // ============================================================
    // Import from other CRMs (المرحلة 15 - G14)
    // ============================================================

    /** GET /api/crm/import/presets - قوالب الاستيراد من CRMs خارجية */
    public function importPresets(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success(['presets' => $this->externalImportService->presets()]);
        } catch (Exception $e) {
            return $this->handleException($e, 'importPresets');
        }
    }

    /** POST /api/crm/import/preview-external {preset, csv_content} */
    public function previewExternalImport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->externalImportService->preview(
                $this->tenantId(),
                (string) ($this->data['preset'] ?? ''),
                (string) ($this->data['csv_content'] ?? '')
            ));
        } catch (Exception $e) {
            return $this->handleException($e, 'previewExternalImport');
        }
    }

    /** POST /api/crm/import/commit-external {preset?, rows, skip_duplicates?} */
    public function commitExternalImport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($denied = $this->requirePermission('create')) {
            return $denied;
        }
        try {
            $result = $this->externalImportService->commit(
                $this->tenantId(),
                $this->data['rows'] ?? [],
                !empty($this->data['skip_duplicates'])
            );
            return $this->success($result, 'تم استيراد جهات الاتصال');
        } catch (Exception $e) {
            return $this->handleException($e, 'commitExternalImport');
        }
    }
}
