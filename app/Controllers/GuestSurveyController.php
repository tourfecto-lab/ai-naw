<?php

/**
 * Guest private feedback surveys: public form (signed link per website) and the owner's inbox.
 * Public routes: GET/POST /survey/{code}. Owner routes: /reputation/surveys, /api/reputation/surveys*.
 */
class GuestSurveyController extends Controller
{
    private function wantsArabic(): bool
    {
        $lang = (string) $this->get('lang', '');
        return $lang === 'ar' || ($lang !== 'en' && function_exists('current_lang') && current_lang() === 'ar');
    }

    /** GET /survey/{code} */
    public function show(array $params = []): array
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        try {
            $websiteId = GuestSurveys::verify((string) ($params['code'] ?? ''));
        } catch (Throwable $e) {
            $websiteId = 0;
        }
        $site = $websiteId ? (new Website())->find($websiteId) : null;
        if (!$site) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><p style="font-family:sans-serif;text-align:center;margin-top:20vh">This link is not valid.</p>';
            exit;
        }
        $company = trim((string) ($site->getAttribute('company_name') ?: $site->getAttribute('main_url')));
        $links = ReviewLanding::destinations((new ReviewRequestService())->getSettings($websiteId));
        echo GuestSurveys::page($company, (string) $params['code'], $this->wantsArabic(), $links, GuestSurveys::ready());
        exit;
    }

    /** POST /survey/{code}  {score, comment, name, contact} - public, rate limited, honeypot */
    public function submit(array $params = []): array
    {
        try {
            $websiteId = GuestSurveys::verify((string) ($params['code'] ?? ''));
        } catch (Throwable $e) {
            $websiteId = 0;
        }
        if (!$websiteId) {
            return $this->error('This link is not valid.', 422);
        }
        if ($rate = $this->rateLimitGuard('ip', 'guest_survey', 20, 600)) {
            return $rate;
        }
        if (trim((string) $this->get('website_url', '')) !== '') {
            return $this->success([], 'ok'); // bots fill the hidden field: pretend success, store nothing
        }
        try {
            GuestSurveys::submit($websiteId, $this->all(), GuestSurveys::ipHash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));
            return $this->success([], 'ok');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'rate') {
                return $this->error('You already sent feedback a moment ago. Please try again later.', 429);
            }
            return $this->error('This page is not available right now.', 503);
        } catch (Throwable $e) {
            Logger::error('Guest survey failed', ['message' => $e->getMessage()]);
            return $this->error('Something went wrong, please try again.', 500);
        }
    }

    /** GET /api/reputation/surveys?status=new|handled&page= */
    public function listApi(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        if (!GuestSurveys::ready()) {
            return $this->success(['ready' => false]);
        }
        try {
            $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
            $links = [];
            foreach ($this->db->query('SELECT id, company_name, main_url FROM websites WHERE user_id = ? ORDER BY id ASC LIMIT 5', [$uid]) as $w) {
                $url = $base . '/survey/' . GuestSurveys::code((int) $w['id']);
                $links[] = ['website_id' => (int) $w['id'], 'name' => $w['company_name'] ?: $w['main_url'], 'url' => $url, 'svg' => QrCodeService::svg($url)];
            }
            $list = GuestSurveys::listFor($uid, (string) $this->get('status', ''), (int) $this->get('page', 1));
            return $this->success(['ready' => true, 'summary' => GuestSurveys::summary($uid), 'links' => $links] + $list);
        } catch (Throwable $e) {
            Logger::error('Guest survey list failed', ['message' => $e->getMessage()]);
            return $this->error('Could not load the feedback', 500);
        }
    }

    /** POST /api/reputation/surveys/{id}/handled  {handled: bool} */
    public function handled(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $ok = GuestSurveys::markHandled((int) $this->user['id'], (int) ($params['id'] ?? 0), filter_var($this->get('handled', true), FILTER_VALIDATE_BOOLEAN));
        return $ok ? $this->success([], 'Saved') : $this->error('Not found', 422);
    }

    /** GET /reputation/surveys */
    public function page(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/reputation/surveys'));
            exit;
        }
        $body = '<div id="svRoot"><p>Loading...</p></div>';
        $script = <<<'JS'
(function () {
    const P = window.Panel, esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    let status = '', page = 1;
    const root = document.getElementById('svRoot');
    async function load() {
        const r = await fetchJSON('/api/reputation/surveys?status=' + encodeURIComponent(status) + '&page=' + page);
        if (!r.success) { root.innerHTML = '<div class="p-card">' + esc(r.error || 'Could not load') + '</div>'; return; }
        const d = r.data;
        if (!d.ready) { root.innerHTML = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">🛠️</div>Private feedback is not available yet: a database update is pending. Contact support.</div></div>'; return; }
        const s = d.summary;
        const tile = (v, l) => '<div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">' + esc(v) + '</div><div class="stat-label">' + esc(l) + '</div></div></div>';
        const links = d.links.map(l => '<div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:8px 0"><div style="width:120px" role="img" aria-label="QR code">' + l.svg + '</div><div style="flex:1;min-width:220px"><b>' + esc(l.name) + '</b><input class="p-select" readonly style="width:100%;margin-top:6px" value="' + esc(l.url) + '" onclick="this.select()"></div></div>').join('');
        const rows = d.rows.length ? d.rows.map(x => '<tr><td>' + esc(x.created_at) + '</td><td><b style="color:' + (x.score <= 6 ? '#b91c1c' : x.score >= 9 ? '#15803d' : 'inherit') + '">' + Number(x.score) + '/10</b></td><td dir="auto">' + esc(x.comment || '-') + '</td><td dir="auto">' + esc([x.guest_name, x.guest_contact].filter(Boolean).join(' · ') || '-') + '</td><td>' + (x.status === 'new' ? '<button class="p-btn outline xs" data-h="' + x.id + '">Mark handled</button>' : '<span class="pill green">Handled</span>') + '</td></tr>').join('')
            : '<tr><td colspan="5" class="p-cell-muted">No feedback yet. Share the link or QR code with your guests.</td></tr>';
        root.innerHTML = '<div class="p-grid cols-4">' + tile(s.nps === null ? '-' : s.nps, 'Guest score (NPS, 90 days)') + tile(s.total, 'Responses (90 days)') + tile(s.avg_score === null ? '-' : s.avg_score, 'Average score') + tile(s.pending, 'Waiting for you') + '</div>'
            + '<div class="p-card" style="margin-top:14px"><div class="p-card-head"><h3>Your private feedback links</h3></div><p class="p-cell-muted" style="margin:0 0 6px">Guests who open the review page can also reach this form. It never replaces your public review links.</p>' + links + '</div>'
            + '<div class="p-card no-pad" style="margin-top:14px"><div class="p-card-head" style="padding:16px 20px 0"><h3>Responses</h3><select id="svStatus" class="p-select"><option value="">All</option><option value="new">Waiting for you</option><option value="handled">Handled</option></select></div>'
            + '<div class="p-table-scroll"><table class="p-table"><thead><tr><th>Date</th><th>Score</th><th>Comment</th><th>Guest</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            + '<div style="display:flex;gap:10px;justify-content:center;padding:12px"><button class="p-btn outline xs" id="svPrev"' + (page <= 1 ? ' disabled' : '') + '>Previous</button><span class="p-cell-muted">' + page + ' / ' + Math.max(1, d.pages) + '</span><button class="p-btn outline xs" id="svNext"' + (page >= d.pages ? ' disabled' : '') + '>Next</button></div></div>';
        document.getElementById('svStatus').value = status;
        document.getElementById('svStatus').onchange = e => { status = e.target.value; page = 1; load(); };
        document.getElementById('svPrev').onclick = () => { page--; load(); };
        document.getElementById('svNext').onclick = () => { page++; load(); };
        root.querySelectorAll('[data-h]').forEach(b => b.onclick = async () => {
            b.disabled = true;
            const res = await fetchJSON('/api/reputation/surveys/' + b.dataset.h + '/handled', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ handled: true }) });
            if (res.success) { toast('Saved', 'success'); load(); } else { toast(res.error || 'Could not save', 'error'); b.disabled = false; }
        });
    }
    load();
})();
JS;
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('reputation', 'Private feedback', 'What guests tell you privately', $body, $script);
        exit;
    }
}
