<?php
require_once __DIR__ . '/SalesCompliance.php';
require_once __DIR__ . '/TourismBusinessDb.php';

/**
 * TOURFECTO AI CEO (المرحلة 4) — محرك النمو والمبيعات لـ Tourfecto نفسها.
 *
 * فوق تسويق المنصة الموجود (PlatformOutreachService: سلسلة إيميلات حسب احتياج الشركة، موافقة الأدمن قبل أول رسالة،
 * حد يومي، لينك إلغاء اشتراك، عنوان الشركة، تتبع الفتح والضغط وعرض التقرير وضغطة واتساب، مطابقة اللي سجّلوا).
 * بيضيف:
 *   - العميل المثالي (ICP): الأسواق المستهدفة (أمريكا وأوروبا والخليج افتراضيًا) وملاءمة كل شركة.
 *   - بوابة قانونية (SalesCompliance) قبل أي موافقة على أول رسالة، ومراجعة الطابور اللي اتوافق عليه قبل كده.
 *   - مسار المبيعات (Pipeline) من الاكتشاف للدفع، حسب المنطقة والمصدر، وتكلفة جلب العميل لو صاحب المنصة دخّل المصاريف.
 *   - نقل المهتمين لـ CRM بتاع صاحب المنصة (مرة واحدة لكل شركة).
 *   - تقارير يومية وأسبوعية من أرقام حقيقية (في الذاكرة المؤسسية).
 * مفيش إرسال من هنا. الإرسال بيفضل في PlatformOutreachService بحدوده.
 */
final class SalesEngine
{
    /** أساس الإرسال لكل منطقة (صاحب المنصة بيأكده) + الأسواق المستهدفة + مصاريف التسويق الشهرية (لتكلفة جلب العميل) */
    public const SETTINGS = ['sales_basis_us' => 0, 'sales_basis_eu' => 0, 'sales_basis_gcc' => 0, 'sales_basis_other' => 0, 'sales_targets' => 'us,eu,gcc', 'sales_spend_usd_month' => ''];
    private const DEFAULT_ADDRESS = 'Tourfecto · Cairo, Egypt';

    private Database $db;
    /** @var callable|null للاختبارات: fn(): string عنوان الشركة في إعدادات الإيميل */
    private $address;

    public function __construct(?Database $db = null, ?callable $address = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->address = $address;
        new TourismBusinessDb($this->db); // الجداول
    }

    public function settings(): array
    {
        $out = self::SETTINGS;
        $keys = array_keys(self::SETTINGS);
        try {
            foreach ($this->db->query('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys) ?: [] as $r) $out[$r['setting_key']] = $r['setting_value'];
        } catch (Throwable $e) {
        }
        $basis = [];
        foreach (SalesCompliance::REGIONS as $reg) if ($reg !== 'ca') $basis[$reg] = (bool) (int) ($out['sales_basis_' . $reg] ?? 0);
        $targets = array_values(array_intersect(SalesCompliance::REGIONS, array_map('trim', explode(',', (string) $out['sales_targets']))));
        $address = $this->address ? (string) call_user_func($this->address) : $this->outreachAddress();
        return ['basis' => $basis, 'targets' => $targets, 'spend_usd_month' => is_numeric($out['sales_spend_usd_month']) ? (float) $out['sales_spend_usd_month'] : null,
            'postal_address' => $address, 'has_address' => self::validAddress($address)];
    }

    public static function validAddress(string $a): bool
    {
        // العنوان الافتراضي (مدينة بس) مش عنوان بريدي كامل
        return trim($a) !== '' && trim($a) !== self::DEFAULT_ADDRESS && mb_strlen(trim($a)) >= 12;
    }

    private function outreachAddress(): string
    {
        try {
            return (string) ((new PlatformOutreachService())->getSettings()['company_address'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    public function saveSettings(array $in): array
    {
        $set = function (string $k, string $v): void {
            $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$k, $v]);
        };
        foreach (['us', 'eu', 'gcc', 'other'] as $reg) if (array_key_exists('basis_' . $reg, $in)) $set('sales_basis_' . $reg, filter_var($in['basis_' . $reg], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        if (array_key_exists('targets', $in)) {
            $t = array_values(array_intersect(SalesCompliance::REGIONS, (array) $in['targets']));
            $set('sales_targets', implode(',', $t));
        }
        if (array_key_exists('spend_usd_month', $in)) {
            $v = $in['spend_usd_month'];
            if ($v === '' || $v === null) $this->db->query("DELETE FROM system_settings WHERE setting_key = 'sales_spend_usd_month'");
            elseif (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000000) throw new InvalidArgumentException('Invalid monthly spend');
            else $set('sales_spend_usd_month', (string) round((float) $v, 2));
        }
        return $this->settings();
    }

    private function consentFor(array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        foreach ($this->db->query('SELECT lead_id, event, channel FROM tbi_consent_events WHERE lead_id IN (' . implode(',', array_map('intval', $ids)) . ')') ?: [] as $e) $out[(int) $e['lead_id']][] = $e;
        return $out;
    }

    // ------------------------------------------------------------------ البوابة والطابور

    /** للموافقة في تسويق المنصة: يرجّع بس الشركات اللي مسموح نبعتلها أول رسالة */
    public function compliantIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $s = $this->settings();
        $rows = $this->db->query('SELECT id, email, country, status FROM platform_leads WHERE id IN (' . implode(',', $ids) . ')') ?: [];
        $consent = $this->consentFor($ids);
        $ok = [];
        foreach ($rows as $r) if (SalesCompliance::evaluate($r, $s['basis'], $consent[(int) $r['id']] ?? [], $s['has_address'])['ok']) $ok[] = (int) $r['id'];
        return $ok;
    }

    /** الشركات المستنية موافقة قبل أول رسالة، مع حكم البوابة وملاءمتها للعميل المثالي */
    public function approvalQueue(int $limit = 100): array
    {
        $s = $this->settings();
        $rows = $this->db->query("SELECT id, company, email, country, city, category, website, status, score, track FROM platform_leads
            WHERE approved = 0 AND step = 0 AND status = 'new' AND track IS NOT NULL AND email IS NOT NULL ORDER BY score DESC LIMIT 1000") ?: [];
        $consent = $this->consentFor(array_column($rows, 'id'));
        $items = [];
        $counts = ['ok' => 0, 'blocked' => 0, 'reasons' => []];
        foreach ($rows as $r) {
            $ev = SalesCompliance::evaluate($r, $s['basis'], $consent[(int) $r['id']] ?? [], $s['has_address']);
            $ev['ok'] ? $counts['ok']++ : $counts['blocked']++;
            foreach ($ev['reasons'] as $reason) $counts['reasons'][$reason] = ($counts['reasons'][$reason] ?? 0) + 1;
            $items[] = ['id' => (int) $r['id'], 'company' => $r['company'], 'country' => $r['country'], 'city' => $r['city'], 'score' => $r['score'] !== null ? (int) $r['score'] : null,
                'track' => $r['track'], 'icp_fit' => SalesCompliance::icpFit($r, $s['targets']), 'role_email' => SalesCompliance::isRoleEmail($r['email']),
                'verdict' => $ev + ['reason_texts' => array_map([SalesCompliance::class, 'reasonText'], $ev['reasons'])]];
        }
        usort($items, static fn ($a, $b) => [$b['verdict']['ok'], $b['icp_fit']] <=> [$a['verdict']['ok'], $a['icp_fit']]);
        return ['total' => count($items), 'counts' => $counts, 'items' => array_slice($items, 0, $limit)];
    }

    /** الموافقة على المسموح بس (من الطابور أو من أرقام محددة)، عن طريق موافقة تسويق المنصة نفسها */
    public function approveCompliant(array $ids, bool $all): int
    {
        $pool = $all ? array_column(array_filter($this->approvalQueue(1000)['items'], static fn ($i) => $i['verdict']['ok']), 'id') : $ids;
        $ok = $this->compliantIds($pool);
        return $ok ? (new PlatformOutreachService())->approve($ok) : 0;
    }

    /** شركات اتوافق عليها قبل البوابة ولسه مااتبعتلهاش: اللي مش مسموح بترجع "مستنية موافقة" */
    public function reevaluateApproved(): int
    {
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE approved = 1 AND step = 0 AND status = 'new'") ?: [], 'id'));
        $bad = array_values(array_diff($ids, $this->compliantIds($ids)));
        if (!$bad) return 0;
        $this->db->query('UPDATE platform_leads SET approved = 0 WHERE id IN (' . implode(',', $bad) . ') AND step = 0');
        return count($bad);
    }

    /** شركات في نص السلسلة (اتبعتلها رسالة أو أكتر) والبوابة دلوقتي بتقول لأ: لصاحب المنصة يقرر يوقفها */
    public function activeNonCompliant(int $limit = 200): array
    {
        $s = $this->settings();
        $rows = $this->db->query("SELECT id, company, email, country, status, step FROM platform_leads WHERE status = 'contacted' AND step > 0 AND next_send_at IS NOT NULL LIMIT 2000") ?: [];
        $consent = $this->consentFor(array_column($rows, 'id'));
        $out = [];
        foreach ($rows as $r) {
            $ev = SalesCompliance::evaluate($r, $s['basis'], $consent[(int) $r['id']] ?? [], $s['has_address']);
            if (!$ev['ok']) $out[] = ['id' => (int) $r['id'], 'company' => $r['company'], 'country' => $r['country'], 'step' => (int) $r['step'], 'reasons' => array_map([SalesCompliance::class, 'reasonText'], $ev['reasons'])];
        }
        return array_slice($out, 0, $limit);
    }

    public function pauseSequences(array $ids): int
    {
        $n = 0;
        $svc = new PlatformOutreachService();
        foreach (array_filter(array_map('intval', $ids)) as $id) { $svc->setLeadStatus($id, 'paused', 'Paused by AI CEO compliance review'); $n++; }
        return $n;
    }

    // ------------------------------------------------------------------ المسار

    public function pipeline(): array
    {
        $rows = $this->db->query("SELECT l.id, l.country, l.source, l.status, l.approved, l.step, l.email IS NOT NULL AS has_email,
                (l.opened_at IS NOT NULL OR l.clicked_at IS NOT NULL OR l.report_viewed_at IS NOT NULL OR l.whatsapp_at IS NOT NULL) AS engaged
            FROM platform_leads l") ?: [];
        $paying = $this->payingLeadIds();
        $stages = ['discovered', 'contactable', 'approved', 'contacted', 'engaged', 'interested', 'signed_up', 'paying'];
        $blank = array_fill_keys($stages, 0);
        $total = $blank;
        $byRegion = [];
        $bySource = [];
        foreach ($rows as $r) {
            $hit = ['discovered' => true, 'contactable' => $r['has_email'] && !in_array($r['status'], ['unsubscribed', 'bounced', 'invalid'], true),
                'approved' => (int) $r['approved'] === 1 || (int) $r['step'] > 0, 'contacted' => (int) $r['step'] > 0, 'engaged' => (bool) $r['engaged'],
                'interested' => in_array($r['status'], ['replied', 'interested', 'signed_up'], true), 'signed_up' => $r['status'] === 'signed_up', 'paying' => isset($paying[(int) $r['id']])];
            $reg = SalesCompliance::region($r['country']);
            $src = (string) ($r['source'] ?: 'import');
            $byRegion[$reg] ??= $blank;
            $bySource[$src] ??= $blank;
            foreach ($hit as $k => $v) if ($v) { $total[$k]++; $byRegion[$reg][$k]++; $bySource[$src][$k]++; }
        }
        $s = $this->settings();
        $cac = $s['spend_usd_month'] !== null && $total['paying'] > 0 ? round($s['spend_usd_month'] / $total['paying'], 2) : null;
        $rate = static fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        return ['stages' => $total, 'by_region' => $byRegion, 'by_source' => $bySource,
            'conversion' => ['contacted_to_engaged' => $rate($total['engaged'], $total['contacted']), 'contacted_to_interested' => $rate($total['interested'], $total['contacted']),
                'contacted_to_signed_up' => $rate($total['signed_up'], $total['contacted']), 'signed_up_to_paying' => $rate($total['paying'], $total['signed_up'])],
            'cac_usd' => $cac, 'cac_note' => $s['spend_usd_month'] === null ? 'Enter monthly sales & marketing spend to compute CAC.' : 'Approximate: monthly spend ÷ paying customers that came from outreach.',
            'crm_pending' => $this->crmPending()];
    }

    /** شركات من القايمة بقت عملاء بيدفعوا (مطابقة الإيميل أو الدومين، قراية بس) */
    private function payingLeadIds(): array
    {
        $out = [];
        $q = "SELECT DISTINCT l.id FROM platform_leads l JOIN users u ON LOWER(u.email) = LOWER(l.email)
              JOIN subscriptions s ON s.user_id = u.id AND s.status IN ('active','past_due') AND COALESCE(s.price, 0) > 0 WHERE l.status = 'signed_up'";
        try { foreach ($this->db->query($q) ?: [] as $r) $out[(int) $r['id']] = true; } catch (Throwable $e) {}
        return $out;
    }

    // ------------------------------------------------------------------ CRM

    public function crmPending(): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) AS c FROM platform_leads l WHERE l.status IN ('replied','interested') AND NOT EXISTS (SELECT 1 FROM tbi_crm_handoffs h WHERE h.lead_id = l.id)")[0]['c'] ?? 0);
    }

    /** المهتمين بيتنقلوا لـ CRM بتاع صاحب المنصة (جهة اتصال + فرصة بمتابعة بكرة)، مرة واحدة لكل شركة */
    public function handoffToCrm(int $ownerUserId, int $actor, int $limit = 50): int
    {
        if ($ownerUserId <= 0) throw new InvalidArgumentException('No platform owner account');
        $rows = $this->db->query("SELECT l.* FROM platform_leads l WHERE l.status IN ('replied','interested') AND NOT EXISTS (SELECT 1 FROM tbi_crm_handoffs h WHERE h.lead_id = l.id) ORDER BY l.id LIMIT " . max(1, min(200, $limit))) ?: [];
        $svc = new CrmLeadService();
        $n = 0;
        foreach ($rows as $l) {
            $label = class_exists('OutreachNeeds') && $l['track'] ? OutreachNeeds::trackLabel((string) $l['track'], 'en') : (string) ($l['track'] ?? '');
            $contact = $svc->createContact($ownerUserId, ['name' => $l['company'] ?: ($l['domain'] ?: 'Tourism company'), 'email' => $l['email'], 'phone' => $l['phone'], 'country' => $l['country'],
                'language' => $l['language'], 'source' => 'tourfecto_outreach', 'notes' => trim('Tourfecto outreach lead #' . $l['id'] . ($l['website'] ? ' · ' . $l['website'] : '') . ($l['city'] ? ' · ' . $l['city'] : ''))]);
            $cid = (int) $contact->getAttribute('id');
            $lead = $svc->createLeadWithData($cid, $ownerUserId, ['source' => 'tourfecto_outreach', 'interest' => mb_substr($label, 0, 190), 'status' => 'new', 'next_follow_up_at' => date('Y-m-d 10:00:00', strtotime('+1 day'))]);
            $this->db->query('INSERT INTO tbi_crm_handoffs (lead_id, crm_contact_id, crm_lead_id, actor_id, created_at) VALUES (?, ?, ?, ?, ?)', [(int) $l['id'], $cid, (int) $lead->getAttribute('id'), $actor ?: null, gmdate('Y-m-d H:i:s')]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------ التقارير

    /** تقرير فترة من أرقام حقيقية (التسجيل في المنصة "اشترك" تقريبي: آخر تحديث للشركة في الفترة) */
    public function periodReport(string $from, string $to): array
    {
        $one = fn (string $sql, array $p) => $this->db->query($sql, $p)[0] ?? [];
        $m = $one("SELECT COUNT(*) AS sent, SUM(opened_at IS NOT NULL) AS opened, SUM(clicked_at IS NOT NULL) AS clicked FROM platform_lead_messages WHERE status = 'sent' AND sent_at >= ? AND sent_at < ?", [$from, $to]);
        $l = $one("SELECT SUM(created_at >= ? AND created_at < ?) AS imported, SUM(report_viewed_at >= ? AND report_viewed_at < ?) AS report_views, SUM(whatsapp_at >= ? AND whatsapp_at < ?) AS whatsapp,
                SUM(status IN ('replied','interested') AND updated_at >= ? AND updated_at < ?) AS interested, SUM(status = 'signed_up' AND updated_at >= ? AND updated_at < ?) AS signed_up,
                SUM(status = 'unsubscribed' AND updated_at >= ? AND updated_at < ?) AS unsubscribed FROM platform_leads", [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to]);
        $regions = [];
        foreach ($this->db->query("SELECT l.country, COUNT(*) AS sent FROM platform_lead_messages m JOIN platform_leads l ON l.id = m.lead_id WHERE m.status = 'sent' AND m.sent_at >= ? AND m.sent_at < ? GROUP BY l.country", [$from, $to]) ?: [] as $r) {
            $reg = SalesCompliance::region($r['country']);
            $regions[$reg] = ($regions[$reg] ?? 0) + (int) $r['sent'];
        }
        $i = static fn ($v) => (int) ($v ?? 0);
        return ['from' => $from, 'to' => $to, 'sent' => $i($m['sent'] ?? 0), 'opened' => $i($m['opened'] ?? 0), 'clicked' => $i($m['clicked'] ?? 0), 'imported' => $i($l['imported'] ?? 0),
            'report_views' => $i($l['report_views'] ?? 0), 'whatsapp_clicks' => $i($l['whatsapp'] ?? 0), 'interested' => $i($l['interested'] ?? 0), 'signed_up' => $i($l['signed_up'] ?? 0),
            'unsubscribed' => $i($l['unsubscribed'] ?? 0), 'sent_by_region' => $regions];
    }

    /** يكتب التقرير اليومي (أمس) والأسبوعي (الأسبوع اللي فات، يوم الاتنين) في الذاكرة لو مااتكتبوش */
    public function writeReports(AiCeoStore $store, ?int $now = null): array
    {
        $now ??= time();
        $done = [];
        $day = date('Y-m-d', $now - 86400);
        $title = 'Sales daily report ' . $day;
        if (!$this->db->query('SELECT 1 FROM aiceo_memory WHERE kind = ? AND title = ? LIMIT 1', ['result', $title])) {
            $store->remember('result', $title, self::reportText($this->periodReport($day . ' 00:00:00', date('Y-m-d', $now) . ' 00:00:00')), null, null);
            $done[] = 'daily';
        }
        if (date('N', $now) === '1') {
            $from = date('Y-m-d', $now - 7 * 86400);
            $wt = 'Sales weekly report ' . $from;
            if (!$this->db->query('SELECT 1 FROM aiceo_memory WHERE kind = ? AND title = ? LIMIT 1', ['result', $wt])) {
                $store->remember('result', $wt, self::reportText($this->periodReport($from . ' 00:00:00', date('Y-m-d', $now) . ' 00:00:00')), null, null);
                $done[] = 'weekly';
            }
        }
        return $done;
    }

    public static function reportText(array $r): string
    {
        $reg = $r['sent_by_region'] ? implode(', ', array_map(static fn ($k, $v) => strtoupper($k) . ' ' . $v, array_keys($r['sent_by_region']), $r['sent_by_region'])) : '—';
        return "Emails sent: {$r['sent']} (opened {$r['opened']}, clicked {$r['clicked']}) · by region: {$reg}\n"
            . "Report views: {$r['report_views']} · WhatsApp clicks: {$r['whatsapp_clicks']}\n"
            . "Replied/interested: {$r['interested']} · Signed up: {$r['signed_up']} (status changes in the period, approximate) · Unsubscribed: {$r['unsubscribed']}\n"
            . "Companies imported: {$r['imported']}";
    }
}
