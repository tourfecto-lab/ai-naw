<?php

/**
 * Tourfecto - Competitor Intelligence: Weekly Digest Scheduler
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * يبعت ملخص أسبوعي تلقائي بالإيميل لأي مستخدم فعّل weekly_digest_enabled
 * من تاب Settings. يعتمد على AICompetitiveAnalyst::weeklySummary() (لو
 * البيانات كافية) ويحفظ نفس المحتوى كتقرير عادي في ci_reports كمان
 * (نفس مصدر الحقيقة المُستخدَم في صفحة Reports بالواجهة).
 *
 * لو الأسبوع مفيهوش نشاط كافٍ، مبيبعتش إيميل فاضي - بيتخطى المستخدم
 * ده بصمت في اللوج بس (احترام قاعدة "لا ترسل Notifications كاذبة").
 *
 * Recommended cron schedule: مرة واحدة كل يوم اثنين الساعة 8 صباحًا
 *   0 8 * * 1 php /home/USERNAME/domains/YOURSITE.com/cron/ci_weekly_digest.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ci_weekly_digest.log 2>&1
 *
 * ملاحظة: السكربت نفسه بيتأكد إنه يوم الاثنين قبل ما يبعت أي حاجة
 * (احتياطًا لو الأدمن شغّله بالغلط في يوم تاني أو غيّر جدولة الكرون).
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

if ((int) date('N') !== 1) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] CI weekly digest: not Monday, skipping.\n");
    exit(0);
}

try {
    $db = Database::getInstance();

    $users = $db->query(
        "SELECT p.user_id, u.email, u.company_name, w.id AS website_id
         FROM ci_user_preferences p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN websites w ON w.user_id = p.user_id
         WHERE p.weekly_digest_enabled = 1
         GROUP BY p.user_id"
    );

    $analyst = new AICompetitiveAnalyst();
    $mailer = new Mailer();
    $sent = 0;
    $skipped = 0;

    foreach ($users as $row) {
        $userId = (int) $row['user_id'];
        $websiteId = (int) ($row['website_id'] ?? 0);

        if ($websiteId <= 0 || empty($row['email'])) {
            $skipped++;
            continue;
        }

        // Phase 16C: لو المستخدم قفّل الملخص الأسبوعي من Settings >
        // Notifications (digest_weekly)، نتخطاه حتى لو weekly_digest_enabled
        // لسه فعّال من الإعدادات القديمة - الـ toggle الجديد هو الحاكم.
        $prefUser = (new User())->find($userId);
        if ($prefUser && !Notification::digestEnabledFor($prefUser, 'digest_weekly')) {
            $skipped++;
            continue;
        }

        $summary = $analyst->weeklySummary($userId, $websiteId);
        if (!($summary['available'] ?? false)) {
            $skipped++; // بيانات غير كافية - مبنبعتش إيميل فاضي
            continue;
        }

        // نحفظه كتقرير حقيقي كمان (نفس مصدر الحقيقة اللي صفحة Reports بتعرضه)
        try {
            $reportContent = $summary['summary'];
            $report = new CiReport([
                'user_id' => $userId, 'website_id' => $websiteId, 'type' => 'weekly',
                'title' => 'Weekly Competitive Digest', 'period_start' => date('Y-m-d', strtotime('-7 days')),
                'period_end' => date('Y-m-d'), 'content_json' => json_encode($reportContent, JSON_UNESCAPED_UNICODE),
                'generated_by' => 'ai',
            ]);
            $report->save();
        } catch (Throwable $e) {
            // فشل حفظ التقرير مش سبب كافٍ لإلغاء الإيميل - نكمل
            if (class_exists('Logger')) {
                Logger::warning('CI weekly digest: failed to persist report row', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        if (!$mailer->isConfigured()) {
            $skipped++;
            continue;
        }

        $s = $summary['summary'];
        $html = '<div style="font-family:Arial,sans-serif;line-height:1.7;max-width:600px;">'
            . '<h2>Your Weekly Competitive Digest</h2>'
            . '<h3>What Changed</h3><p>' . nl2br(htmlspecialchars((string) ($s['what_changed'] ?? '-'), ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<h3>Why It Matters</h3><p>' . nl2br(htmlspecialchars((string) ($s['why_it_matters'] ?? '-'), ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<h3>Threats</h3><p>' . nl2br(htmlspecialchars((string) ($s['threats'] ?? '-'), ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<h3>Opportunities</h3><p>' . nl2br(htmlspecialchars((string) ($s['opportunities'] ?? '-'), ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<h3>Recommended Actions</h3><p>' . nl2br(htmlspecialchars((string) ($s['recommended_actions'] ?? '-'), ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p style="color:#888;font-size:12px;margin-top:20px;">Tourfecto Competitor Intelligence - manage this in Settings.</p>'
            . '</div>';

        $result = $mailer->send((string) $row['email'], (string) ($row['company_name'] ?: ''), 'Your Weekly Competitive Digest', $html);
        if ($result['success'] ?? false) {
            $sent++;
        } else {
            $skipped++;
            if (class_exists('Logger')) {
                Logger::warning('CI weekly digest: mail send failed', ['user_id' => $userId, 'error' => $result['error'] ?? 'unknown']);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] CI Weekly Digest: sent=%d skipped=%d total_opted_in=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $sent,
        $skipped,
        count($users),
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] CI weekly digest error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Competitor Intelligence weekly digest failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
