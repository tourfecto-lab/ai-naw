<?php
/**
 * ملخص الدورة للعميل: بعد ما الدورة تخلص، إشعار في المنصة وإيميل فيه اللي اتعمل واللي محتاجه منه.
 * بيتبعت مرة واحدة لكل دورة، والإيميل بيتقفل من إعدادات الإشعارات (الملخص الأسبوعي).
 */
final class SeoCycleReport
{
    public static function send(Database $db, int $uid, int $wid, int $runId): bool
    {
        $pdo = $db->getConnection();
        $q = static function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s; };
        $run = $q('SELECT snapshot_json FROM seo_expert_runs WHERE id=? AND user_id=?', [$runId, $uid])->fetch(PDO::FETCH_ASSOC);
        if (!$run) return false;
        $snap = json_decode((string) $run['snapshot_json'], true) ?: [];
        if (!empty($snap['report_sent'])) return false;
        $tasks = $q('SELECT kind,field_name,status,title,page_url FROM seo_expert_tasks WHERE run_id=? AND user_id=?', [$runId, $uid])->fetchAll(PDO::FETCH_ASSOC);
        $data = self::summarize($tasks, $snap);
        // العلامة قبل الإرسال: لو الإيميل فشل مانبعتش نفس الملخص مرتين
        $q("UPDATE seo_expert_runs SET snapshot_json=JSON_SET(snapshot_json,'$.report_sent',?) WHERE id=? AND user_id=?", [gmdate('c'), $runId, $uid]);
        $user = $q('SELECT * FROM users WHERE id=?', [$uid])->fetch(PDO::FETCH_ASSOC) ?: [];
        $ar = ($user['language'] ?? 'ar') !== 'en';
        $site = (string) ($q('SELECT domain FROM websites WHERE id=?', [$wid])->fetchColumn() ?: '');
        $link = '/seo-expert?website_id=' . $wid;
        [$subject, $lines] = self::text($data, $site, $ar);
        if (class_exists('Notification')) Notification::notify($uid, 'seo_cycle_report', $subject, implode("\n", $lines), $link);
        $email = (string) ($user['email'] ?? '');
        if ($email === '' || !class_exists('Mailer')) return false;
        try {
            if (class_exists('User')) { $u = (new User())->find($uid); if ($u && !Notification::digestEnabledFor($u, 'digest_weekly')) return false; }
            $mailer = new Mailer();
            if (!$mailer->isConfigured()) return false;
            $base = rtrim(defined('APP_URL') && APP_URL ? (string) APP_URL : 'https://tourfecto.pro', '/');
            return !empty($mailer->context('seo_cycle_report', $uid)->send($email, trim((string) ($user['first_name'] ?? $user['name'] ?? '')), $subject, self::html($lines, $base . $link, $ar))['success']);
        } catch (Throwable $e) {
            if (class_exists('Logger')) Logger::warning('SEO cycle report email failed', ['run_id' => $runId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function summarize(array $tasks, array $snap): array
    {
        $d = ['titles' => 0, 'descriptions' => 0, 'schema' => 0, 'links' => 0, 'articles' => 0, 'broken' => 0, 'other' => 0, 'needs' => [], 'gsc' => null];
        foreach ($tasks as $t) {
            if ($t['status'] === 'needs_client') { $d['needs'][] = (string) $t['title']; continue; }
            if ($t['status'] !== 'verified') continue;
            $f = (string) $t['field_name'];
            if ($t['kind'] === 'content') $d['articles']++;
            elseif ($f === 'title') $d['titles']++;
            elseif ($f === 'description') $d['descriptions']++;
            elseif ($f === 'jsonld' || str_starts_with($f, 'schema')) $d['schema']++;
            elseif ($f === 'related_links') $d['links']++;
            elseif ($f === 'link_fix') $d['broken']++;
            else $d['other']++;
        }
        $d['needs'] = array_values(array_unique($d['needs']));
        $g = $snap['metrics']['gsc']['summary'] ?? null;
        if (($snap['metrics']['gsc']['state'] ?? '') === 'measured' && is_array($g)) $d['gsc'] = ['clicks' => (int) ($g['clicks'] ?? 0), 'impressions' => (int) ($g['impressions'] ?? 0)];
        return $d;
    }

    /** @return array{0:string,1:string[]} */
    public static function text(array $d, string $site, bool $ar): array
    {
        $done = $d['titles'] + $d['descriptions'] + $d['schema'] + $d['links'] + $d['broken'] + $d['articles'] + $d['other'];
        $subject = $ar ? 'ملخص السيو لموقع ' . $site . ': ' . ($done ? 'اتطبق ' . $done . ' تحسين' : 'الدورة خلصت') : 'SEO summary for ' . $site . ': ' . ($done ? $done . ' improvements applied' : 'cycle finished');
        $rows = [
            'titles' => [$ar ? 'عناوين صفحات اتحسّنت' : 'Page titles improved'],
            'descriptions' => [$ar ? 'أوصاف صفحات اتحسّنت' : 'Page descriptions improved'],
            'schema' => [$ar ? 'بيانات Schema اتضافت' : 'Schema blocks added'],
            'links' => [$ar ? 'صفحات اتربطت بصفحات تانية' : 'Pages linked to related pages'],
            'broken' => [$ar ? 'صفحات اتصلحت فيها روابط مكسورة' : 'Pages with broken links fixed'],
            'articles' => [$ar ? 'مقالات جديدة اتنشرت' : 'New articles published'],
            'other' => [$ar ? 'تحسينات تانية' : 'Other improvements'],
        ];
        $lines = [$ar ? 'المنصة خلصت دورة السيو الأسبوعية لموقعك واتأكدت إن التعديلات ظاهرة على الموقع:' : 'The platform finished this week\'s SEO cycle and confirmed the changes are live:'];
        foreach ($rows as $k => [$label]) if ($d[$k] > 0) $lines[] = '• ' . $label . ': ' . $d[$k];
        if (!$done) $lines[] = $ar ? '• مكانش فيه تعديلات آمنة جديدة الأسبوع ده؛ المنصة هتفحص تاني الدورة الجاية.' : '• No new safe changes this week; the platform checks again next cycle.';
        if ($d['gsc']) $lines[] = $ar ? 'جوجل (آخر 28 يوم): ' . number_format($d['gsc']['clicks']) . ' زيارة من ' . number_format($d['gsc']['impressions']) . ' مرة ظهور.' : 'Google (last 28 days): ' . number_format($d['gsc']['clicks']) . ' clicks from ' . number_format($d['gsc']['impressions']) . ' impressions.';
        if ($d['needs']) {
            $lines[] = $ar ? 'محتاجين منك ' . count($d['needs']) . ' خطوة (المنصة مش بتقدر تعملها لوحدها):' : count($d['needs']) . ' step(s) need you (the platform cannot do them alone):';
            foreach (array_slice($d['needs'], 0, 3) as $n) $lines[] = '• ' . $n;
        } else {
            $lines[] = $ar ? 'مش محتاجين منك أي حاجة دلوقتي.' : 'Nothing needs you right now.';
        }
        return [$subject, $lines];
    }

    private static function html(array $lines, string $url, bool $ar): string
    {
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        return '<div dir="' . ($ar ? 'rtl' : 'ltr') . '" style="font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.9;color:#111827">'
            . implode('<br>', array_map($e, $lines))
            . '<p style="margin-top:20px"><a href="' . $e($url) . '" style="background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block">' . ($ar ? 'افتح صفحة السيو' : 'Open SEO page') . '</a></p></div>';
    }
}
