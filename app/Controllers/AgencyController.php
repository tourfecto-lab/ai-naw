<?php

/**
 * Tourfecto - Agency Controller (White-Label)
 * @version 1.0.0
 */
class AgencyController extends Controller
{
    /** @var AgencyService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new AgencyService();
    }

    /** GET /agency */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('newAgencyModal').classList.add('open')">+ New agency</button>
        </div>
        <div class="p-grid cols-2" id="agencyGrid"><div class="p-empty">Loading...</div></div>

        <div class="p-modal-overlay" id="newAgencyModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>New agency</h3><button class="p-modal-close" onclick="document.getElementById('newAgencyModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>Agency name</label>
                    <input type="text" id="agencyName" class="p-select" style="width:100%;">
                </div>
                <div class="p-modal-foot"><button class="p-btn" onclick="createAgency()">Create</button></div>
            </div>
        </div>

        <div class="p-modal-overlay" id="clientsModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3 id="clientsModalTitle">Agency clients</h3><button class="p-modal-close" onclick="document.getElementById('clientsModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <div style="display:flex;gap:8px;margin-bottom:14px;">
                        <input type="email" id="newClientEmail" class="p-select" style="flex:1;" placeholder="Client email (must already be registered on Tourfecto)">
                        <button class="p-btn" onclick="addClient()">+ Add</button>
                    </div>
                    <div id="clientsAlert" class="alert alert-danger" style="display:none;"></div>
                    <div id="clientsList" class="p-empty">Loading...</div>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    let currentAgencyId = null;
    let agencyLoadVersion = 0, clientsLoadVersion = 0;

    async function load() {
        const version = ++agencyLoadVersion;
        const grid = document.getElementById('agencyGrid');
        grid.innerHTML = '<div class="p-empty">Loading...</div>';
        let res;
        try { res = await fetchJSON('/api/agency/list'); } catch (error) { res = null; }
        if (version !== agencyLoadVersion) return;
        if (!res || !res.success || !res.data || !Array.isArray(res.data.agencies)) {
            grid.innerHTML = '<div class="p-empty" role="alert">تعذر تحميل الوكالات. <button class="p-btn outline xs" onclick="agencyReload()">Retry</button></div>';
            return;
        }
        if (res.success && res.data.agencies && res.data.agencies.length) {
            grid.innerHTML = res.data.agencies.map(a => `
                <div class="p-card">
                    <div class="p-card-head"><h3>${esc(a.name)}</h3><span class="p-card-sub">${esc(a.status)}</span></div>
                    <div class="p-kv"><span class="k">Number of seats</span><span class="v">${esc(a.plan_seats)}</span></div>
                    <div class="p-kv"><span class="k">Created at</span><span class="v">${esc(a.created_at)}</span></div>
                    <button class="p-btn outline xs" style="margin-top:10px;" data-agency-name="${esc(a.name)}" onclick="openClients(${Number(a.id)}, this.dataset.agencyName)">👥 Client management</button>
                </div>
            `).join('');
        } else {
            grid.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🏢</div>No agencies yet</div>';
        }
    }

    window.createAgency = async function () {
        const name = document.getElementById('agencyName').value.trim();
        if (!name) return;
        const res = await fetchJSON('/api/agency/create', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name }) });
        document.getElementById('newAgencyModal').classList.remove('open');
        if (res.success) { P.toast('Agency created', 'success'); load(); }
        else P.toast(res.error || 'Create failed', 'error');
    };

    window.openClients = function (agencyId, name) {
        currentAgencyId = agencyId;
        document.getElementById('clientsModalTitle').textContent = 'agency clients: ' + name;
        document.getElementById('clientsAlert').style.display = 'none';
        document.getElementById('newClientEmail').value = '';
        document.getElementById('clientsModal').classList.add('open');
        loadClients();
    };

    async function loadClients() {
        const box = document.getElementById('clientsList');
        box.innerHTML = 'Loading...';
        const agencyId = currentAgencyId;
        const version = ++clientsLoadVersion;
        let res;
        try { res = await fetchJSON('/api/agency/' + agencyId + '/clients'); } catch (error) { res = null; }
        if (version !== clientsLoadVersion || agencyId !== currentAgencyId) return;
        if (!res || !res.success || !res.data || !Array.isArray(res.data.clients)) {
            box.innerHTML = '<div class="p-cell-muted" role="alert">تعذر تحميل العملاء. <button class="p-btn outline xs" onclick="agencyReloadClients()">Retry</button></div>';
            return;
        }
        if (res.success && res.data.clients && res.data.clients.length) {
            box.innerHTML = res.data.clients.map(c => `
                <div class="p-kv">
                    <span class="k">${esc(c.company_name || c.email)}</span>
                    <span class="v" style="display:flex;align-items:center;gap:8px;">
                        <span class="pill green">${esc(c.status)}</span>
                        <button class="p-btn danger xs" onclick="removeClient(${c.id})">Remove</button>
                    </span>
                </div>`).join('');
        } else {
            box.innerHTML = '<div class="p-cell-muted" style="padding:10px 0;">No clients added to this agency yet</div>';
        }
    }

    window.addClient = async function () {
        const email = document.getElementById('newClientEmail').value.trim();
        const alertBox = document.getElementById('clientsAlert');
        alertBox.style.display = 'none';
        if (!email) return;

        const res = await fetchJSON('/api/agency/' + currentAgencyId + '/clients', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email }),
        });

        if (res.success) {
            document.getElementById('newClientEmail').value = '';
            P.toast(res.message || 'اتبعتت الدعوة', 'success');
            loadClients();
        } else {
            alertBox.textContent = res.error || 'Could not add';
            alertBox.style.display = 'block';
        }
    };

    window.removeClient = async function (clientId) {
        if (!confirm('Are you sure you want to remove this client from the agency?')) return;
        const res = await fetchJSON('/api/agency/' + currentAgencyId + '/clients/' + clientId, { method: 'DELETE' });
        if (res.success) { P.toast('Removed', 'success'); loadClients(); }
        else P.toast(res.error || 'Could not remove', 'error');
    };

    window.agencyReload = load;
    window.agencyReloadClients = loadClients;
    load();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('agency', 'White Label - Agencies', 'Manage agencies and their clients', $body, $script);
        exit;
    }

    /** GET /api/agency/list */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $agencies = (new Agency())->where(['owner_user_id' => $this->user['id']], ['created_at' => 'DESC']);
        return $this->success(['agencies' => array_map(fn ($a) => $a->toArray(), $agencies)]);
    }

    /** POST /api/agency/create */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['name' => 'required'])) {
            return $this->error('Agency name is required', 422);
        }

        if (!in_array($this->user['role'] ?? '', ['super_admin', 'admin', 'agency_owner'], true)) {
            return $this->error('Your current permissions do not allow creating a White-Label agency - contact support to upgrade your plan', 403);
        }

        try {
            $agency = $this->service->createAgency((int) $this->user['id'], (string) $this->get('name'));
            return $this->success(['agency' => $agency->toArray()], 'Agency created', 201);
        } catch (Exception $e) {
            Logger::error('createAgency Error', ['message' => $e->getMessage()]);
            return $this->error('Could not create the agency', 500);
        }
    }

    /** يتأكد إن الوكالة دي فعلاً ملك المستخدم الحالي، أو يرجّع null */
    private function ownedAgency(int $agencyId): ?Agency
    {
        $agency = (new Agency())->find($agencyId);
        if (!$agency || (int) $agency->getAttribute('owner_user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $agency;
    }

    /** GET /api/agency/{id}/clients */
    public function listClients(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        try {
            $links = (new AgencyClient())->where(['agency_id' => $agency->getAttribute('id')]);
            if (empty($links)) {
                return $this->success(['clients' => []]);
            }

            // تصحيح أداء: كان بيعمل استعلام منفصل لكل عميل جوه اللوب
            // (N+1) - لو الوكالة عندها 50 عميل كان بيبعت 50 استعلام
            // بدل واحد بس. دلوقتي استعلام واحد مجمّع بـ IN (...).
            $userIds = array_map(fn ($link) => (int) $link->getAttribute('client_user_id'), $links);
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $users = $this->db->query("SELECT id, email, company_name FROM users WHERE id IN ({$placeholders})", $userIds);
            $usersById = [];
            foreach ($users as $u) {
                $usersById[(int) $u['id']] = $u;
            }

            $clients = [];
            foreach ($links as $link) {
                $user = $usersById[(int) $link->getAttribute('client_user_id')] ?? null;
                if ($user) {
                    $clients[] = [
                        'id' => $link->getAttribute('id'),
                        'email' => $user['email'],
                        'company_name' => $user['company_name'],
                        'status' => $link->getAttribute('status'),
                    ];
                }
            }
            return $this->success(['clients' => $clients]);
        } catch (Exception $e) {
            Logger::error('listClients Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load clients', 500);
        }
    }

    /** POST /api/agency/{id}/clients - إضافة عميل موجود بالفعل في تورفكتو للوكالة عن طريق بريده */
    public function addClient(array $params = []): array
    {
        // 🔒 كان بيضم أي حساب مسجّل للوكالة من غير موافقة صاحبه، ورسالة الخطأ كانت بتكشف
        // لو الإيميل مسجّل عندنا ولا لأ. دلوقتي بيبعت دعوة، والعميل بيوافق بنفسه.
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rate = $this->rateLimitGuard('user', 'agency_invite', 20, 3600)) {
            return $rate;
        }
        if (!$this->validate(['email' => 'required|email'])) {
            return $this->error('Invalid email', 422);
        }
        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }
        $email = strtolower(trim((string) $this->get('email')));
        if ($email === strtolower((string) ($this->user['email'] ?? ''))) {
            return $this->error('You cannot add yourself as a client of your agency', 422);
        }
        try {
            $invitation = $this->service->createInvitation((int) $agency->getAttribute('id'), $email, (float) $this->get('commission_rate', 10.00), (int) $this->user['id']);
            $this->deliverInvitation($agency, $invitation);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
        // نفس الرد دايمًا سواء الإيميل مسجّل ولا لأ
        return $this->success(['invited' => true], 'Invitation sent to ' . $email . ' - the client will appear here once they accept', 201);
    }

    /** إشعار + إيميل بالدعوة (لو مسجّل: رابط موافقة، لو لأ: رابط تسجيل ثم موافقة) */
    private function deliverInvitation($agency, $invitation): void
    {
        $email = (string) $invitation->getAttribute('email');
        $token = (string) $invitation->getAttribute('token');
        $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
        $acceptUrl = $base . '/agency/accept?token=' . rawurlencode($token);
        $agencyName = (string) $agency->getAttribute('name');
        $user = User::findByEmail($email);
        if ($user && class_exists('Notification')) {
            try {
                Notification::notify((int) $user->getAttribute('id'), 'agency_invitation', 'Agency invitation', 'وكالة "' . $agencyName . '" عايزة تدير حسابك. وافق أو تجاهل.', '/agency/accept?token=' . rawurlencode($token));
            } catch (Throwable $e) {
            }
        }
        try {
            $mailer = new Mailer();
            if (!$mailer->isConfigured()) {
                return;
            }
            $link = $user ? $acceptUrl : ($base . '/register?redirect=' . rawurlencode('/agency/accept?token=' . $token));
            $safeName = htmlspecialchars($agencyName, ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $html = '<div dir="rtl" style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px"><h2>Invitation to join an agency ' . $safeName . '</h2>'
                . '<p style="line-height:1.8;color:#444">وكالة <b>' . $safeName . '</b> wants to manage your account on ' . (defined('APP_NAME') ? APP_NAME : 'Tourfecto') . ' (marketing, reviews and ads). If you agree, press the button. If you don\'t know them, ignore this email and nothing will change.</p>'
                . '<p style="text-align:center;margin:28px 0"><a href="' . $safeLink . '" style="background:#EFB05E;color:#14100a;padding:14px 32px;border-radius:30px;text-decoration:none;font-weight:bold">' . ($user ? 'View invitation' : 'Sign up & accept') . '</a></p>'
                . '<p style="color:#888;font-size:12px">الدعوة صالحة 3 أيام.</p></div>';
            $mailer->context('agency_invitation', $user ? (int) $user->getAttribute('id') : null)->send($email, '', 'Agency invitation ' . $agencyName, $html);
        } catch (Throwable $e) {
            Logger::warning('Agency invitation mail failed', ['message' => $e->getMessage()]);
        }
    }

    /** GET /agency/accept?token= - صفحة قبول/رفض دعوة الوكالة */
    public function acceptPage(array $params = []): array
    {
        $token = (string) ($_GET['token'] ?? '');
        if (empty($_SESSION['user_id'])) {
            header('Location: /login?redirect=' . rawurlencode('/agency/accept?token=' . $token));
            exit;
        }
        $inv = $token !== '' ? $this->service->getInvitationByToken($token) : null;
        $agency = $inv ? (new Agency())->find((int) $inv->getAttribute('agency_id')) : null;
        $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $valid = $inv && $agency && $inv->getAttribute('status') === 'pending' && strtotime((string) $inv->getAttribute('expires_at')) > time();
        $mine = $inv && strtolower((string) $inv->getAttribute('email')) === strtolower((string) ($this->user['email'] ?? ''));
        if (!$valid) {
            $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">✉️</div>This invitation is invalid, expired or already accepted.</div></div>';
        } elseif (!$mine) {
            $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🔒</div>This invitation was sent to a different email. Log in with the email the invitation was sent to.</div></div>';
        } else {
            $tk = json_encode($token);
            $body = '<div class="p-card" style="max-width:560px"><div class="p-card-head"><h3>Agency invitation ' . $esc($agency->getAttribute('name')) . '</h3></div>'
                . '<div style="padding:0 20px 20px;line-height:1.9">If you accept, the agency will be able to view and manage your account services (Websites, reviews, ads, content). You can remove it at any time later.'
                . '<div style="display:flex;gap:10px;margin-top:16px"><button class="p-btn primary" id="agAccept">Accept</button><a class="p-btn outline" href="/dashboard">Not now</a></div></div></div>'
                . '<script>document.getElementById("agAccept").onclick=async function(){this.disabled=true;const r=await Panel.fetchJSON("/api/agency/invitations/accept",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({token:' . $tk . '})});Panel.toast(r.message||r.error,r.success?"success":"error");if(r.success)setTimeout(()=>location.href="/dashboard",1200);else this.disabled=false;};</script>';
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('agency', 'Agency invitation', 'An agency wants to manage your account', $body);
        exit;
    }

    /** DELETE /api/agency/{id}/clients/{clientId} */
    public function removeClient(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        try {
            $link = (new AgencyClient())->find((int) ($params['clientId'] ?? 0));
            if (!$link || (int) $link->getAttribute('agency_id') !== (int) $agency->getAttribute('id')) {
                return $this->error('Client not found in this agency', 404);
            }
            $link->delete();
            ActivityLog::record('white_label', 'agency.client_removed', [
                'agency_id' => $agency->getAttribute('id'), 'subject_type' => 'agency_clients', 'subject_id' => (int) $params['clientId'],
            ]);
            return $this->success([], 'Client removed');
        } catch (Exception $e) {
            Logger::error('removeClient Error', ['message' => $e->getMessage()]);
            return $this->error('Could not remove', 500);
        }
    }

    /** GET /api/agency/{id}/commissions - عمولات الوكالة (عزل صارم: وكالة المستخدم الحالي فقط) */
    public function listCommissions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        try {
            $rows = $this->db->query(
                'SELECT c.id, c.booking_id, c.commission_amount, c.status, c.created_at,
                        b.booking_reference, b.customer_name, b.total_amount, b.currency,
                        u.company_name AS client_name
                 FROM agency_commissions c
                 JOIN bookings b ON b.id = c.booking_id
                 JOIN users u ON u.id = b.user_id
                 WHERE c.agency_id = ?
                 ORDER BY c.created_at DESC
                 LIMIT 200',
                [(int) $agency->getAttribute('id')]
            );
            return $this->success(['commissions' => $rows]);
        } catch (Exception $e) {
            Logger::error('listCommissions Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load commissions', 500);
        }
    }

    /** POST /api/agency/commissions/{id}/paid - تعليم العمولة كمدفوعة يدويًا (الوكيل/الأدمن) */
    public function markCommissionPaid(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $commissionId = (int) ($params['id'] ?? 0);
        if ($commissionId <= 0) {
            return $this->error('Invalid commission ID', 422);
        }

        try {
            $commission = (new AgencyCommission())->find($commissionId);
            if (!$commission) {
                return $this->error('Commission not found', 404);
            }

            // عزل صارم: العمولة لازم تتبع وكالة يملكها المستخدم الحالي
            // (تتجاهل الوكالات التانية تمامًا وكأنها غير موجودة)
            $agency = $this->ownedAgency((int) $commission->getAttribute('agency_id'));
            if (!$agency) {
                return $this->error('Commission not found', 404);
            }

            if ($commission->getAttribute('status') === 'paid') {
                return $this->success(['commission' => $commission->toArray()], 'This commission is already paid');
            }

            $commission->setAttribute('status', 'paid');
            $commission->save();
            ActivityLog::record('white_label', 'agency.commission_paid', [
                'agency_id' => (int) $agency->getAttribute('id'),
                'subject_type' => 'agency_commissions', 'subject_id' => $commissionId,
            ]);
            return $this->success(['commission' => $commission->toArray()], 'Commission marked as paid');
        } catch (Exception $e) {
            Logger::error('markCommissionPaid Error', ['message' => $e->getMessage()]);
            return $this->error('Could not update the commission', 500);
        }
    }

    /** GET /api/agency/{id}/performance - تقرير أداء الوكالة (عزل صارم ببيانات وكالته بس) */
    public function performanceReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }
        $agencyId = (int) $agency->getAttribute('id');

        try {
            // 1) العملاء النشطون
            $clients = (new AgencyClient())->where(['agency_id' => $agencyId, 'status' => 'active']);
            $activeClientsCount = count($clients);
            $clientUserIds = array_map(fn ($c) => (int) $c->getAttribute('client_user_id'), $clients);

            // 2) الحجوزات المؤكدة + إيرادها - مقتصرة على عملاء وكالته حصرًا
            $confirmedCount = 0;
            $totalRevenue = 0.0;
            if (!empty($clientUserIds)) {
                $placeholders = implode(',', array_fill(0, count($clientUserIds), '?'));
                $agg = $this->db->query(
                    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue
                     FROM bookings
                     WHERE status = 'confirmed' AND user_id IN ({$placeholders})",
                    $clientUserIds
                );
                if (!empty($agg)) {
                    $confirmedCount = (int) $agg[0]['cnt'];
                    $totalRevenue = (float) $agg[0]['revenue'];
                }
            }

            // 3) العمولات pending/paid - كلها لوكالة دي (عزل مضمون بـ agency_id)
            $commissions = $this->db->query(
                'SELECT status, COALESCE(SUM(commission_amount), 0) AS total, COUNT(*) AS cnt
                 FROM agency_commissions
                 WHERE agency_id = ?
                 GROUP BY status',
                [$agencyId]
            );
            $commissionTotals = ['pending' => 0.0, 'paid' => 0.0];
            $commissionCounts = ['pending' => 0, 'paid' => 0];
            foreach ($commissions as $c) {
                if (isset($commissionTotals[$c['status']])) {
                    $commissionTotals[$c['status']] = (float) $c['total'];
                    $commissionCounts[$c['status']] = (int) $c['cnt'];
                }
            }

            return $this->success([
                'agency' => ['id' => $agencyId, 'name' => $agency->getAttribute('name')],
                'active_clients_count' => $activeClientsCount,
                'confirmed_bookings_count' => $confirmedCount,
                'total_revenue' => round($totalRevenue, 2),
                'commissions' => [
                    'pending_total' => round($commissionTotals['pending'], 2),
                    'paid_total' => round($commissionTotals['paid'], 2),
                    'pending_count' => $commissionCounts['pending'],
                    'paid_count' => $commissionCounts['paid'],
                ],
            ]);
        } catch (Exception $e) {
            Logger::error('performanceReport Error', ['message' => $e->getMessage()]);
            return $this->error('Could not generate the performance report', 500);
        }
    }

    // ------------------------------------------------------------
    // دعوات العملاء (رمز/رابط القبول)
    // ------------------------------------------------------------

    /** POST /api/agency/{id}/invitations - إنشاء دعوة لعميل حقيقي (بريده) */
    public function createInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['email' => 'required|email'])) {
            return $this->error('Invalid email', 422);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        $clientUser = User::findByEmail((string) $this->get('email'));
        if (!$clientUser) {
            return $this->error('No account registered with this email on Tourfecto - the client must have a real account first', 404);
        }
        if ((int) $clientUser->getAttribute('id') === (int) $this->user['id']) {
            return $this->error('You cannot invite yourself as a client of your agency', 422);
        }
        if (!empty((new AgencyClient())->where(['agency_id' => (int) $agency->getAttribute('id'), 'client_user_id' => (int) $clientUser->getAttribute('id')]))) {
            return $this->error('This client is already added to this agency', 422);
        }

        $rate = (float) $this->get('commission_rate', 10.00);

        try {
            $invitation = $this->service->createInvitation(
                (int) $agency->getAttribute('id'),
                (string) $this->get('email'),
                $rate,
                (int) $this->user['id']
            );
            return $this->success(['invitation' => $invitation->toArray()], 'Join invitation created', 201);
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    /** GET /api/agency/{id}/invitations - قائمة دعوات الوكالة */
    public function listInvitations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        try {
            $invitations = array_map(
                fn ($inv) => $inv->toArray(),
                $this->service->listInvitations((int) $agency->getAttribute('id'))
            );
            return $this->success(['invitations' => $invitations]);
        } catch (Exception $e) {
            Logger::error('listInvitations Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load invitations', 500);
        }
    }

    /** DELETE /api/agency/{id}/invitations/{inviteId} - إلغاء دعوة */
    public function revokeInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }

        $invitation = (new AgencyInvitation())->find((int) ($params['inviteId'] ?? 0));
        if (!$invitation || (int) $invitation->getAttribute('agency_id') !== (int) $agency->getAttribute('id')) {
            return $this->error('Invitation not found in this agency', 404);
        }

        try {
            $this->service->revokeInvitation((int) $invitation->getAttribute('id'));
            return $this->success([], 'Invitation cancelled');
        } catch (Exception $e) {
            Logger::error('revokeInvitation Error', ['message' => $e->getMessage()]);
            return $this->error('Could not cancel the invitation', 500);
        }
    }

    /** POST /api/agency/invitations/accept - قبول الدعوة بالرمز (أي مستخدم مسجّل دخوله) */
    public function acceptInvitation(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $token = trim((string) $this->get('token'));
        if ($token === '') {
            return $this->error('Invitation code is required', 422);
        }

        try {
            $invitation = $this->service->acceptInvitation((int) $this->user['id'], $token);
            $data = $invitation->toArray();
            // الرمز حساس - لا يُعاد في الاستجابة بعد القبول
            unset($data['token']);
            return $this->success(['invitation' => $data], 'Joined the agency successfully');
        } catch (Exception $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        }
    }

    // ------------------------------------------------------------
    // لوحة تحكم الوكيل
    // ------------------------------------------------------------

    /** GET /api/agency/{id}/dashboard - إحصائيات اللوحة + أداء كل عميل */
    public function agencyDashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $agency = $this->ownedAgency((int) ($params['id'] ?? 0));
        if (!$agency) {
            return $this->error('Agency not found', 404);
        }
        $agencyId = (int) $agency->getAttribute('id');

        try {
            $stats = $this->service->agencyStats($agencyId);
            if (empty($stats)) {
                return $this->error('Agency not found', 404);
            }
            return $this->success([
                'stats' => $stats,
                'clients_performance' => $this->service->clientPerformance($agencyId),
            ]);
        } catch (Exception $e) {
            Logger::error('agencyDashboard Error', ['message' => $e->getMessage()]);
            return $this->error('Could not generate the agency dashboard', 500);
        }
    }
}
