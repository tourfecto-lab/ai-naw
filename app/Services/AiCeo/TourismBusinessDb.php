<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';

/**
 * TOURFECTO AI CEO (المرحلة 3) — قاعدة بيانات شركات السياحة العالمية.
 *
 * الأساس: platform_leads (PlatformOutreachService): الاستيراد من Apify/JSON/CSV من غير تكرار (إيميل/دومين)، والتحليل
 * (OutreachAudit → data_json.audit و needs_json و track و score)، والرسايل (platform_lead_messages)، وإلغاء الاشتراك.
 * الكلاس ده بيضيف اللي ناقص من غير ما يكرر الموجود:
 *   - بحث وتصفية وتقسيم، وملف كامل لكل شركة (بيانات، سوشيال، خدمات محتاجاها، تقارير، رسايل، موافقات، دمج، اشتراك)
 *   - تاريخ التقارير: كل تحليل جديد بيتحفظ في tbi_reports بدل ما يتكتب فوق القديم
 *   - سجل الموافقات والاعتراضات (tbi_consent_events). الاعتراض أو إيقاف التواصل = status 'unsubscribed' (الإرسال بيقف عليه أصلًا)
 *   - طلب الحذف: بيمسح البيانات الشخصية/التواصل، وبيحفظ بصمة SHA-256 بس (tbi_suppression) عشان ماتتستوردش تاني
 *   - السجلات المكررة: بيلاقيها (دومين/تليفون/اسم+مدينة)، والدمج بيتعمل بموافقة صاحب المنصة وبيترجع
 * منفصل عن عملاء المنصة (users): مابيكتبش في أي جدول عميل؛ "اشترك" بيتعرف من مطابقة الإيميل/الدومين بس.
 * البيانات المنشورة للتواصل مش معناها موافقة على التسويق: الموافقة الصريحة بتتسجل كحدث opt_in لوحدها.
 */
final class TourismBusinessDb
{
    public const EVENTS = ['opt_in', 'objection', 'unsubscribe', 'do_not_contact', 'erasure'];
    public const CHANNELS = ['email', 'whatsapp', 'phone', 'all'];
    /** الحالات اللي معناها "ماتتواصلش" */
    private const STOP = ['unsubscribed', 'bounced', 'invalid'];

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        BrainSchema::ensure($this->db);
        // الجدول الأساسي بيتعمل من خدمة تسويق المنصة نفسها (مصدر واحد للتعريف)
        if (!$this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_leads'")) new PlatformOutreachService();
    }

    // ------------------------------------------------------------------ البحث والملف

    /**
     * @param array{q?:string,country?:string,city?:string,status?:string,track?:string,min_score?:int,has_email?:bool,converted?:bool} $f
     */
    public function search(array $f, int $page = 1, int $per = 50): array
    {
        [$where, $args] = $this->filters($f);
        $per = max(10, min(200, $per));
        $page = max(1, $page);
        $total = (int) ($this->db->query("SELECT COUNT(*) AS c FROM platform_leads l WHERE {$where}", $args)[0]['c'] ?? 0);
        $rows = $this->db->query("SELECT l.id, l.company, l.country, l.city, l.category, l.website, l.domain, l.email IS NOT NULL AS has_email, l.phone IS NOT NULL AS has_phone,
                l.rating, l.reviews, l.status, l.track, l.track2, l.score, l.source, l.created_at, l.scanned_at, l.last_sent_at,
                (SELECT COUNT(*) FROM platform_lead_messages m WHERE m.lead_id = l.id AND m.status = 'sent') AS messages
            FROM platform_leads l WHERE {$where} ORDER BY l.score IS NULL, l.score DESC, l.id DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $args) ?: [];
        return ['total' => $total, 'page' => $page, 'per' => $per, 'items' => array_map(fn ($r) => $r + ['services' => $this->services($r['track'], $r['track2'])], $rows)];
    }

    private function filters(array $f): array
    {
        $w = ['1=1'];
        $a = [];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') { $w[] = '(l.company LIKE ? OR l.domain LIKE ? OR l.city LIKE ?)'; array_push($a, "%{$q}%", "%{$q}%", "%{$q}%"); }
        foreach (['country', 'city', 'status', 'track'] as $k) if (($v = trim((string) ($f[$k] ?? ''))) !== '') { $w[] = "l.{$k} = ?"; $a[] = $v; }
        if (isset($f['min_score']) && $f['min_score'] !== '' && is_numeric($f['min_score'])) { $w[] = 'l.score >= ?'; $a[] = (int) $f['min_score']; }
        if (!empty($f['has_email'])) $w[] = 'l.email IS NOT NULL';
        if (!empty($f['converted'])) $w[] = "l.status = 'signed_up'";
        return [implode(' AND ', $w), $a];
    }

    public function profile(int $id): array
    {
        $l = $this->db->query('SELECT * FROM platform_leads WHERE id = ? LIMIT 1', [$id])[0] ?? null;
        if (!$l) throw new InvalidArgumentException('Company not found');
        $data = json_decode((string) ($l['data_json'] ?? ''), true) ?: [];
        $needs = json_decode((string) ($l['needs_json'] ?? ''), true) ?: [];
        unset($l['data_json'], $l['needs_json'], $l['token']);
        return [
            'company' => $l,
            'socials' => self::socials($data, (string) ($l['website'] ?? '')),
            'services' => $this->services($l['track'], $l['track2']),
            'opportunities' => array_map(static fn ($n) => ['track' => $n['track'] ?? '', 'score' => $n['score'] ?? null, 'facts' => $n['facts'] ?? null], $needs),
            'audit' => $data['audit'] ?? null,
            'reports' => $this->db->query('SELECT id, kind, score, summary_json, source, captured_at FROM tbi_reports WHERE lead_id = ? ORDER BY id DESC LIMIT 20', [$id]) ?: [],
            'messages' => $this->db->query('SELECT step, subject, status, track, sent_at, opened_at, clicked_at FROM platform_lead_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 30', [$id]) ?: [],
            'consent' => $this->db->query('SELECT event, channel, source, note, actor_id, created_at FROM tbi_consent_events WHERE lead_id = ? ORDER BY id DESC', [$id]) ?: [],
            'merges' => $this->db->query('SELECT id, primary_id, duplicate_id, reason, merged_at, undone_at FROM tbi_merges WHERE primary_id = ? OR duplicate_id = ? ORDER BY id DESC', [$id, $id]) ?: [],
            'customer' => $this->customerState((string) ($l['email'] ?? ''), (string) ($l['domain'] ?? '')),
        ];
    }

    /** حسابات السوشيال من تحليل الموقع (OutreachAudit)، أو لو "الموقع" نفسه صفحة سوشيال */
    public static function socials(array $data, string $website): array
    {
        $out = [];
        foreach ((array) ($data['site']['social'] ?? $data['site']['socials'] ?? []) as $net => $v) if (is_string($net) && $v) $out[$net] = is_string($v) ? $v : true;
        foreach (['facebook' => 'facebook.com', 'instagram' => 'instagram.com', 'tiktok' => 'tiktok.com', 'youtube' => 'youtube.com', 'linkedin' => 'linkedin.com'] as $net => $host) {
            if (!isset($out[$net]) && stripos($website, $host) !== false) $out[$net] = $website;
        }
        return $out;
    }

    private function services(?string $track, ?string $track2): array
    {
        $out = [];
        foreach (array_filter([$track, $track2]) as $t) $out[] = ['track' => $t, 'label' => class_exists('OutreachNeeds') ? OutreachNeeds::trackLabel((string) $t, 'en') : $t];
        return $out;
    }

    /** هل الشركة بقت عميل فعلي؟ (من غير ما نكتب في جداول العملاء) */
    private function customerState(string $email, string $domain): ?array
    {
        $u = null;
        if ($email !== '') $u = $this->db->query('SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND deleted_at IS NULL LIMIT 1', [$email])[0] ?? null;
        if (!$u && $domain !== '') {
            $u = $this->db->query("SELECT w.user_id AS id FROM websites w WHERE LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url, '://', -1), '/', 1), 'www.', '')) = ? LIMIT 1", [strtolower($domain)])[0] ?? null;
        }
        if (!$u) return null;
        $s = $this->db->query('SELECT status, price, trial_ends_at FROM subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int) $u['id']])[0] ?? null;
        return ['user_id' => (int) $u['id'], 'subscription' => $s ? ['status' => $s['status'], 'paid' => (float) ($s['price'] ?? 0) > 0] : null];
    }

    public function stats(): array
    {
        $q = fn (string $sql) => $this->db->query($sql) ?: [];
        $pairs = static function (array $rows): array { $o = []; foreach ($rows as $r) $o[(string) $r['k']] = (int) $r['n']; return $o; };
        $t = $q("SELECT COUNT(*) AS total, SUM(email IS NOT NULL) AS with_email, SUM(scanned_at IS NOT NULL) AS analysed, SUM(status = 'signed_up') AS converted,
                SUM(status IN ('unsubscribed','bounced','invalid')) AS do_not_contact FROM platform_leads")[0] ?? [];
        return [
            'total' => (int) ($t['total'] ?? 0), 'with_email' => (int) ($t['with_email'] ?? 0), 'analysed' => (int) ($t['analysed'] ?? 0),
            'converted' => (int) ($t['converted'] ?? 0), 'do_not_contact' => (int) ($t['do_not_contact'] ?? 0),
            'countries' => $pairs($q("SELECT COALESCE(country, '?') AS k, COUNT(*) AS n FROM platform_leads GROUP BY k ORDER BY n DESC LIMIT 40")),
            'statuses' => $pairs($q('SELECT status AS k, COUNT(*) AS n FROM platform_leads GROUP BY status ORDER BY n DESC')),
            'tracks' => $pairs($q('SELECT track AS k, COUNT(*) AS n FROM platform_leads WHERE track IS NOT NULL GROUP BY track ORDER BY n DESC')),
            'score_bands' => $pairs($q("SELECT CASE WHEN score IS NULL THEN 'not scored' WHEN score >= 70 THEN '70+' WHEN score >= 40 THEN '40-69' ELSE '0-39' END AS k, COUNT(*) AS n FROM platform_leads GROUP BY k")),
            'reports' => (int) ($q('SELECT COUNT(*) AS c FROM tbi_reports')[0]['c'] ?? 0),
            'suppressed' => (int) ($q('SELECT COUNT(*) AS c FROM tbi_suppression')[0]['c'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------ تاريخ التقارير

    /** كل شركة اتحللت من جديد بعد آخر تقرير محفوظ: نحفظ نسخة من التحليل (مش بنعيد التحليل نفسه) */
    public function captureReports(int $limit = 500): int
    {
        $rows = $this->db->query("SELECT l.id, l.score, l.track, l.track2, l.needs_json, l.data_json, l.scanned_at FROM platform_leads l
            WHERE l.scanned_at IS NOT NULL AND l.scanned_at > COALESCE((SELECT MAX(r.captured_at) FROM tbi_reports r WHERE r.lead_id = l.id AND r.kind = 'site_audit'), '1970-01-01')
            ORDER BY l.scanned_at ASC LIMIT " . max(1, min(5000, $limit))) ?: [];
        foreach ($rows as $r) {
            $data = json_decode((string) ($r['data_json'] ?? ''), true) ?: [];
            $summary = ['track' => $r['track'], 'track2' => $r['track2'], 'needs' => json_decode((string) ($r['needs_json'] ?? ''), true) ?: [],
                'audit' => $data['audit'] ?? null, 'site_ok' => $data['site']['ok'] ?? null, 'site_status' => $data['site_status'] ?? null, 'socials' => self::socials($data, '')];
            $this->db->query('INSERT INTO tbi_reports (lead_id, kind, score, summary_json, source, captured_at) VALUES (?, ?, ?, ?, ?, ?)',
                [(int) $r['id'], 'site_audit', $r['score'] !== null ? (int) $r['score'] : null, json_encode($summary, JSON_UNESCAPED_UNICODE), 'outreach_audit', $r['scanned_at']]);
        }
        return count($rows);
    }

    // ------------------------------------------------------------------ الموافقات والاعتراضات والحذف

    public function recordConsent(int $leadId, string $event, string $channel, int $actor, string $note = '', string $source = 'admin'): void
    {
        if (!in_array($event, self::EVENTS, true) || $event === 'erasure') throw new InvalidArgumentException('Invalid event');
        if (!in_array($channel, self::CHANNELS, true)) throw new InvalidArgumentException('Invalid channel');
        if (!$this->db->query('SELECT 1 FROM platform_leads WHERE id = ?', [$leadId])) throw new InvalidArgumentException('Company not found');
        $this->event($leadId, $event, $channel, $source, $note, $actor);
        // أي اعتراض أو طلب إيقاف = مفيش تواصل تاني (الإرسال الحالي بيقف على الحالة دي)
        if ($event !== 'opt_in') $this->db->query("UPDATE platform_leads SET status = 'unsubscribed', next_send_at = NULL WHERE id = ?", [$leadId]);
    }

    /** إلغاء الاشتراك من لينك الإيميل اتسجل كحالة بس: نكمّل سجل الموافقات بيه (مرة واحدة لكل شركة) */
    public function backfillUnsubscribes(): int
    {
        $rows = $this->db->query("SELECT l.id FROM platform_leads l WHERE l.status = 'unsubscribed'
            AND NOT EXISTS (SELECT 1 FROM tbi_consent_events e WHERE e.lead_id = l.id AND e.event IN ('unsubscribe','objection','do_not_contact')) LIMIT 2000") ?: [];
        foreach ($rows as $r) $this->event((int) $r['id'], 'unsubscribe', 'email', 'email_link', 'Recorded from the lead status', null);
        return count($rows);
    }

    /**
     * طلب حذف: بيمسح التواصل والبيانات الشخصية والملاحظات والبيانات الخام، وبيحفظ بصمة بس عشان ماتتستوردش تاني.
     * الرسايل اللي اتبعتت بتفضل (عنوان وتاريخ بس) كسجل امتثال.
     */
    public function erase(int $leadId, int $actor, string $note = ''): void
    {
        $l = $this->db->query('SELECT id, email, domain, phone FROM platform_leads WHERE id = ? LIMIT 1', [$leadId])[0] ?? null;
        if (!$l) throw new InvalidArgumentException('Company not found');
        foreach (['email' => $l['email'], 'domain' => $l['domain'], 'phone' => $l['phone']] as $kind => $v) {
            if ($v === null || trim((string) $v) === '') continue;
            $this->db->query('INSERT IGNORE INTO tbi_suppression (kind, value_hash, reason, created_at) VALUES (?, ?, ?, ?)', [$kind, self::hash($kind, (string) $v), 'erasure', gmdate('Y-m-d H:i:s')]);
        }
        $this->db->query("UPDATE platform_leads SET company = 'Erased on request', email = NULL, phone = NULL, website = NULL, domain = NULL, maps_url = NULL, notes = NULL,
            data_json = NULL, needs_json = NULL, status = 'invalid', next_send_at = NULL WHERE id = ?", [$leadId]);
        $this->db->query('DELETE FROM tbi_reports WHERE lead_id = ?', [$leadId]);
        $this->event($leadId, 'erasure', 'all', 'admin', $note, $actor);
    }

    public static function hash(string $kind, string $value): string
    {
        $v = strtolower(trim($value));
        if ($kind === 'domain') $v = preg_replace('#^www\.#', '', $v);
        if ($kind === 'phone') $v = preg_replace('/\D+/', '', $v);
        return hash('sha256', $kind . ':' . $v);
    }

    /** للاستيراد: الشركة اتحذفت قبل كده بطلبها؟ */
    public function isSuppressed(?string $email, ?string $domain, ?string $phone): bool
    {
        $checks = [];
        foreach (['email' => $email, 'domain' => $domain, 'phone' => $phone] as $k => $v) if ($v !== null && trim($v) !== '') $checks[] = [$k, self::hash($k, $v)];
        foreach ($checks as [$k, $h]) if ($this->db->query('SELECT 1 FROM tbi_suppression WHERE kind = ? AND value_hash = ? LIMIT 1', [$k, $h])) return true;
        return false;
    }

    private function event(?int $leadId, string $event, string $channel, string $source, string $note, ?int $actor): void
    {
        $this->db->query('INSERT INTO tbi_consent_events (lead_id, event, channel, source, note, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$leadId, $event, $channel, $source, $note !== '' ? mb_substr($note, 0, 500) : null, $actor, gmdate('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------ المكرر والدمج

    /** مجموعات محتمل تكون نفس الشركة: نفس الدومين، أو نفس التليفون، أو نفس الاسم في نفس المدينة */
    public function duplicates(int $limit = 50): array
    {
        $groups = [];
        $add = function (string $reason, array $rows) use (&$groups) {
            foreach ($rows as $r) {
                $ids = array_map('intval', explode(',', (string) $r['ids']));
                if (count($ids) > 1) $groups[] = ['reason' => $reason, 'key' => (string) $r['k'], 'ids' => $ids];
            }
        };
        $add('same_domain', $this->db->query("SELECT domain AS k, GROUP_CONCAT(id ORDER BY id) AS ids FROM platform_leads WHERE domain IS NOT NULL AND domain <> '' AND status <> 'invalid' GROUP BY domain HAVING COUNT(*) > 1 LIMIT {$limit}") ?: []);
        $add('same_phone', $this->db->query("SELECT REGEXP_REPLACE(phone, '[^0-9]', '') AS k, GROUP_CONCAT(id ORDER BY id) AS ids FROM platform_leads WHERE phone IS NOT NULL AND LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) >= 8 AND status <> 'invalid' GROUP BY k HAVING COUNT(*) > 1 LIMIT {$limit}") ?: []);
        $add('same_name_city', $this->db->query("SELECT CONCAT(LOWER(TRIM(company)), ' · ', LOWER(COALESCE(city, ''))) AS k, GROUP_CONCAT(id ORDER BY id) AS ids FROM platform_leads WHERE company <> '' AND status <> 'invalid' GROUP BY k HAVING COUNT(*) > 1 LIMIT {$limit}") ?: []);
        // اللي اتدمج قبل كده (وماترجعش) مايظهرش تاني
        $merged = array_map('intval', array_column($this->db->query('SELECT duplicate_id FROM tbi_merges WHERE undone_at IS NULL') ?: [], 'duplicate_id'));
        $out = [];
        foreach ($groups as $g) {
            $g['ids'] = array_values(array_diff($g['ids'], $merged));
            if (count($g['ids']) > 1) $out[] = $g;
        }
        return array_slice($out, 0, $limit);
    }

    /** بيكمّل البيانات الناقصة في الأساسي من المكرر، وبيوقف المكرر (paused) بدل ما يمسحه. بيترجع بـ undoMerge. */
    public function merge(int $primaryId, int $duplicateId, int $actor, string $reason = 'manual'): int
    {
        if ($primaryId === $duplicateId) throw new InvalidArgumentException('Pick two different companies');
        $p = $this->db->query('SELECT * FROM platform_leads WHERE id = ?', [$primaryId])[0] ?? null;
        $d = $this->db->query('SELECT * FROM platform_leads WHERE id = ?', [$duplicateId])[0] ?? null;
        if (!$p || !$d) throw new InvalidArgumentException('Company not found');
        $filled = [];
        foreach (['phone', 'website', 'domain', 'city', 'country', 'category', 'rating', 'reviews', 'maps_url'] as $col) {
            if (($p[$col] === null || $p[$col] === '') && $d[$col] !== null && $d[$col] !== '') $filled[$col] = $d[$col];
        }
        // الإيميل عليه UNIQUE: بنسيبه على المكرر (المكرر متوقف فمش هيتبعتله)
        if ($filled) $this->db->query('UPDATE platform_leads SET ' . implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($filled))) . ' WHERE id = ?', array_merge(array_values($filled), [$primaryId]));
        // لو أي واحد منهم طلب ماحدش يكلمه، الاتنين يقفوا
        if (in_array($d['status'], self::STOP, true) && !in_array($p['status'], self::STOP, true)) {
            $this->db->query("UPDATE platform_leads SET status = 'unsubscribed', next_send_at = NULL WHERE id = ?", [$primaryId]);
            $filled['_status'] = $p['status'];
        }
        $this->db->query("UPDATE platform_leads SET status = 'paused', next_send_at = NULL, notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?", ["\n[merged into #{$primaryId}]", $duplicateId]);
        $this->db->query('INSERT INTO tbi_merges (primary_id, duplicate_id, reason, filled_json, duplicate_prev_status, actor_id, merged_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$primaryId, $duplicateId, mb_substr($reason, 0, 60), json_encode($filled, JSON_UNESCAPED_UNICODE), $d['status'], $actor, gmdate('Y-m-d H:i:s')]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM tbi_merges')[0]['id'] ?? 0);
    }

    public function undoMerge(int $mergeId): void
    {
        $m = $this->db->query('SELECT * FROM tbi_merges WHERE id = ? AND undone_at IS NULL', [$mergeId])[0] ?? null;
        if (!$m) throw new InvalidArgumentException('Merge not found or already undone');
        $filled = json_decode((string) $m['filled_json'], true) ?: [];
        $prevStatus = $filled['_status'] ?? null;
        unset($filled['_status']);
        if ($filled) $this->db->query('UPDATE platform_leads SET ' . implode(', ', array_map(static fn ($c) => "{$c} = NULL", array_keys($filled))) . ' WHERE id = ?', [(int) $m['primary_id']]);
        if ($prevStatus) $this->db->query('UPDATE platform_leads SET status = ? WHERE id = ?', [$prevStatus, (int) $m['primary_id']]);
        $this->db->query("UPDATE platform_leads SET status = ?, notes = REPLACE(COALESCE(notes, ''), ?, '') WHERE id = ?", [$m['duplicate_prev_status'], "\n[merged into #{$m['primary_id']}]", (int) $m['duplicate_id']]);
        $this->db->query('UPDATE tbi_merges SET undone_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $mergeId]);
    }
}
