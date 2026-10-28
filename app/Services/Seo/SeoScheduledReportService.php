<?php

/**
 * Tourfecto - SEO: Scheduled Email Reports Service (G6)
 * @version 1.0.0
 *
 * يسد فجوة "تقارير مجدولة (Email)" (Ahrefs/SEMrush) عبر cron
 * `cron/seo_scheduled_reports.php`: جدول `seo_report_schedules` يحدد
 * متى (daily/weekly/monthly) ولأي بريد، وبيُبنى تقرير HTML ملخص من
 * بيانات حقيقية (آخر درجة تدقيق + أهم مشاكل + أحدث التدقيقات) ويُرسل
 * عبر `Mailer`.
 *
 * Guardrail: لو Mailer مش متظبط → الـ cron يتجاوز الموقع بأمان ويسجّل
 * warning (لا إرسال وهمي). PDF خارج النطاق (يحتاج مكتبة توليد PDF -
 * بلا تبعيات خارجية).
 */
class SeoScheduledReportService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db, private $mailer = null)
    {
        $this->db = $db;
    }

    /** قائمة الجداول لموقع معيّن (عزل تينانت). */
    public function listSchedules(int $websiteId, int $userId): array
    {
        return $this->db->query(
            "SELECT id, website_id, frequency, weekday, hour, recipient_email, is_active, last_sent_at, created_at
             FROM seo_report_schedules WHERE website_id = ? AND user_id = ? ORDER BY id DESC",
            [$websiteId, $userId]
        );
    }

    /**
     * إنشاء/تحديث جدول. frequency إجباري؛ hour 0-23؛ weekday 0-6
     * (مطلوب للـ weekly)؛ recipient_email بريد صالح.
     * @return array{success:bool, schedule:?array, error:?string}
     */
    public function saveSchedule(int $websiteId, int $userId, array $data, ?int $scheduleId = null): array
    {
        $frequency=(string)($data['frequency']??'');
        $hour=filter_var($data['hour']??8,FILTER_VALIDATE_INT);
        $weekday=($data['weekday']??'')!==''?filter_var($data['weekday'],FILTER_VALIDATE_INT):null;
        $email=trim((string)($data['recipient_email']??''));
        if(!in_array($frequency,['daily','weekly','monthly'],true)||$hour===false||$hour<0||$hour>23||($weekday!==null&&($weekday===false||$weekday<0||$weekday>6))||($frequency==='weekly'&&$weekday===null)||!filter_var($email,FILTER_VALIDATE_EMAIL))
            return ['success'=>false,'schedule'=>null,'error'=>'راجع تكرار التقرير والبريد والساعة واليوم للتقرير الأسبوعي'];
        $active=(int)($data['is_active']??1);if(!in_array($active,[0,1],true))return ['success'=>false,'schedule'=>null,'error'=>'حالة تفعيل غير صالحة'];
        try {
            return $this->db->transaction(function()use($websiteId,$userId,$frequency,$hour,$weekday,$email,$active,$scheduleId){
                $pdo=$this->db->getConnection();$site=$pdo->prepare('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE');$site->execute([$websiteId,$userId]);
                if(!$site->fetchColumn())throw new InvalidArgumentException('الموقع غير موجود');
                if($scheduleId!==null){$find=$pdo->prepare('SELECT id FROM seo_report_schedules WHERE id=? AND website_id=? AND user_id=? FOR UPDATE');$find->execute([$scheduleId,$websiteId,$userId]);if(!$find->fetchColumn())throw new InvalidArgumentException('الجدول غير موجود');}
                else {$find=$pdo->prepare('SELECT id FROM seo_report_schedules WHERE website_id=? AND user_id=? AND frequency=? AND weekday<=>? AND hour=? AND recipient_email=? ORDER BY id LIMIT 1 FOR UPDATE');$find->execute([$websiteId,$userId,$frequency,$weekday,$hour,$email]);$existing=$find->fetchColumn();if($existing)$scheduleId=(int)$existing;}
                if($scheduleId!==null){$stmt=$pdo->prepare('UPDATE seo_report_schedules SET frequency=?,weekday=?,hour=?,recipient_email=?,is_active=? WHERE id=? AND website_id=? AND user_id=?');$ok=$stmt->execute([$frequency,$weekday,$hour,$email,$active,$scheduleId,$websiteId,$userId]);}
                else {$stmt=$pdo->prepare('INSERT INTO seo_report_schedules(website_id,user_id,frequency,weekday,hour,recipient_email,is_active) VALUES(?,?,?,?,?,?,?)');$ok=$stmt->execute([$websiteId,$userId,$frequency,$weekday,$hour,$email,$active]);$scheduleId=(int)$pdo->lastInsertId();}
                if(!$ok)throw new RuntimeException('Schedule write failed');
                $stmt=$pdo->prepare('SELECT id,website_id,frequency,weekday,hour,recipient_email,is_active,last_sent_at,created_at FROM seo_report_schedules WHERE id=? AND website_id=? AND user_id=?');$stmt->execute([$scheduleId,$websiteId,$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
                if(!$row)throw new RuntimeException('Schedule read failed');
                return ['success'=>true,'schedule'=>$row,'error'=>null];
            });
        }catch(InvalidArgumentException $e){return ['success'=>false,'schedule'=>null,'error'=>$e->getMessage()];}
        catch(Throwable $e){return ['success'=>false,'schedule'=>null,'error'=>'تعذر حفظ جدول التقرير؛ حاول لاحقًا'];}
    }

    /** حذف جدول (عزل تينانت). */
    public function deleteSchedule(int $websiteId, int $userId, int $scheduleId): bool
    {
        return $this->db->query(
            "DELETE FROM seo_report_schedules WHERE id = ? AND website_id = ? AND user_id = ?",
            [$scheduleId, $websiteId, $userId]
        ) > 0;
    }

    /**
     * الجداول المستحقة الآن (نشطة + بلغ موعدها حسب التردد).
     * @return array صفوف (id, website_id, user_id, frequency, weekday, hour, recipient_email, last_sent_at) + main_url
     */
    public function dueSchedules(int $limit = 50, ?DateTimeImmutable $now = null): array
    {
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        return $this->db->query(
            "SELECT s.id,s.website_id,s.user_id,s.frequency,s.weekday,s.hour,s.recipient_email,s.last_sent_at,w.main_url
             FROM seo_report_schedules s INNER JOIN websites w ON w.id=s.website_id AND w.user_id=s.user_id
             WHERE s.is_active=1 AND ((s.frequency='daily' AND (s.last_sent_at IS NULL OR DATE(s.last_sent_at)<?))
             OR (s.frequency='weekly' AND s.weekday=? AND (s.last_sent_at IS NULL OR DATE(s.last_sent_at)<?))
             OR (s.frequency='monthly' AND (s.last_sent_at IS NULL OR DATE_FORMAT(s.last_sent_at,'%Y-%m')<?)))
             AND s.hour<=? ORDER BY s.last_sent_at ASC LIMIT ?",
            [$now->format('Y-m-d'),(int)$now->format('w'),$now->format('Y-m-d'),$now->format('Y-m'),(int)$now->format('G'),max(1,min(200,$limit))]
        );
    }

    /**
     * إرسال التقارير المستحقة الآن.
     * @return array{attempted:int, sent:int, skipped_no_mailer:int, errors:array}
     */
    public function sendDue(int $limit = 50): array
    {
        $due = $this->dueSchedules($limit);
        $mailer = $this->mailer ?? new Mailer();
        $attempted = 0;
        $sent = 0;
        $skipped = 0;
        $errors = [];

        foreach ($due as $s) {
            $attempted++;
            if (!ExternalExecution::lock('seo-report:'.(int)$s['id'])) continue;
            try {
            $fresh=$this->db->query('SELECT last_sent_at FROM seo_report_schedules WHERE id=? AND user_id=? AND website_id=?',[$s['id'],$s['user_id'],$s['website_id']]);
            if (!$fresh || $fresh[0]['last_sent_at']!==$s['last_sent_at']) continue;
            if (!$mailer->isConfigured()) {
                $skipped++;
                if (class_exists('Logger')) {
                    Logger::warning('SEO scheduled report skipped: mailer not configured', ['schedule_id' => $s['id']]);
                }
                continue;
            }

            $body = $s['frequency']==='monthly' ? SeoMonthlyReportService::html((new SeoMonthlyReportService($this->db))->data((int)$s['user_id'],(int)$s['website_id']),(string)($s['main_url']??'')) : $this->buildReportHtml(
                (int) $s['website_id'],
                (int) $s['user_id'],
                (string) ($s['main_url'] ?? '')
            );
            if ($s['frequency']==='monthly') {
                $claim=$this->db->getConnection()->prepare('INSERT IGNORE INTO seo_phase5_claims(claim_key,created_at) VALUES(?,UTC_TIMESTAMP())');
                $claim->execute(['seo-monthly-mail:'.$s['id'].':'.gmdate('Y-m')]);
                if (!$claim->rowCount()) continue;
            }
            $result = $mailer->send(
                (string) $s['recipient_email'],
                '',
                ($s['frequency']==='monthly'?'تقرير SEO الشهري — ':'تقرير SEO دوري — ') . ($s['main_url'] ?? 'موقعك'),
                $body
            );
            if (!empty($result['success'])) {
                $this->db->exec("UPDATE seo_report_schedules SET last_sent_at = UTC_TIMESTAMP() WHERE id = ?", [$s['id']]);
                $sent++;
            } else {
                $errors[] = ['schedule_id' => $s['id'], 'error' => $result['error'] ?? 'send failed'];
            }
            } catch (Throwable $reportError) {$errors[]=['schedule_id'=>$s['id'],'error'=>'report_preparation_or_delivery_unavailable'];}
            finally {ExternalExecution::unlock('seo-report:'.(int)$s['id']);}
        }

        return ['attempted' => $attempted, 'sent' => $sent, 'skipped_no_mailer' => $skipped, 'errors' => $errors];
    }

    /**
     * بناء HTML التقرير البريدي من بيانات حقيقية (آخر تدقيق + أهم مشاكل +
     * أحدث لقطات). Escaping كامل لكل المدخلات.
     */
    public function buildReportHtml(int $websiteId, int $userId, string $mainUrl): string
    {
        $siteName = htmlspecialchars($mainUrl, ENT_QUOTES, 'UTF-8');

        $lastAudit = $this->db->query(
            "SELECT id, overall_score, completed_at FROM wo_audits
             WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$websiteId, $userId]
        );
        $score = !empty($lastAudit) && $lastAudit[0]['overall_score'] !== null
            ? round((float) $lastAudit[0]['overall_score'], 1)
            : null;
        $scoreLabel = $score !== null ? $score . ' / 100' : 'لا يوجد تدقيق بعد';

        $issues = [];
        if (!empty($lastAudit)) {
            $issues = $this->db->query(
                "SELECT title, severity FROM wo_audit_findings
                 WHERE audit_id = ? AND status IN ('fail','warn')
                 ORDER BY FIELD(severity, 'critical','high','medium','low') LIMIT 5",
                [$lastAudit[0]['id']]
            );
        }

        $recent = $this->db->query(
            "SELECT overall_score, created_at FROM seo_reports
             WHERE website_id = ? AND user_id = ? AND overall_score IS NOT NULL
             ORDER BY created_at DESC LIMIT 5",
            [$websiteId, $userId]
        );

        $issuesHtml = '';
        if (empty($issues)) {
            $issuesHtml = '<li style="color:#059669;">لا توجد مشاكل حرجة مسجّلة.</li>';
        } else {
            foreach ($issues as $i) {
                $sevColors = ['critical' => '#dc2626', 'high' => '#ea580c', 'medium' => '#d97706', 'low' => '#ca8a04'];
                $color = $sevColors[$i['severity']] ?? '#92400e';
                $issuesHtml .= '<li style="margin-bottom:6px;color:' . $color . ';">['
                    . htmlspecialchars((string) $i['severity'], ENT_QUOTES, 'UTF-8') . '] '
                    . htmlspecialchars((string) $i['title'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }

        $recentRows = '';
        foreach ($recent as $r) {
            $recentRows .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars((string) $r['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">' . round((float) $r['overall_score'], 1) . '</td>'
                . '</tr>';
        }
        if ($recentRows === '') {
            $recentRows = '<tr><td colspan="2" style="padding:6px 10px;color:#888;">لا توجد لقطات بعد</td></tr>';
        }

        return <<<HTML
<div style="font-family:Arial,'Helvetica Neue',sans-serif;max-width:620px;margin:0 auto;direction:rtl;">
  <h2 style="color:#1e293b;border-bottom:2px solid #6366f1;padding-bottom:8px;">تقرير SEO دوري — {$siteName}</h2>
  <table style="width:100%;border-collapse:collapse;margin:14px 0;">
    <tr>
      <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;text-align:center;">
        <div style="font-size:13px;color:#64748b;">آخر درجة تدقيق</div>
        <div style="font-size:28px;font-weight:bold;color:#0f172a;">{$scoreLabel}</div>
      </td>
    </tr>
  </table>
  <h3 style="color:#334155;">أهم المشاكل المفتوحة</h3>
  <ul style="color:#334155;padding-right:18px;">{$issuesHtml}</ul>
  <h3 style="color:#334155;">أحدث لقطات الدرجات</h3>
  <table style="width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
    <thead><tr><th style="padding:6px 10px;text-align:right;color:#64748b;">التاريخ</th><th style="padding:6px 10px;text-align:right;color:#64748b;">الدرجة</th></tr></thead>
    <tbody>{$recentRows}</tbody>
  </table>
  <p style="color:#94a3b8;font-size:12px;margin-top:18px;">تم إنشاء هذا التقرير تلقائيًا من بيانات حقيقية في منصة Tourfecto.</p>
</div>
HTML;
    }
}
