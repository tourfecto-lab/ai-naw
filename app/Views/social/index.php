<?php
/**
 * Tourfecto - واجهة السوشيال ميديا الاحترافية (/social)
 * ثنائية اللغة بالكامل (عربي/إنجليزي حسب لغة اللوحة) - مفيش نصوص مخلوطة.
 * المتغيرات: $ar (bool), $uid (int), $cssUrl, $jsUrl, $monthlyCss, $monthlyJs, $pickerJs
 * @var bool $ar
 */
$t = static fn (string $a, string $e): string => htmlspecialchars($ar ? $a : $e, ENT_QUOTES, 'UTF-8');
?>
<link rel="stylesheet" href="<?= $cssUrl ?>">
<link rel="stylesheet" href="<?= $monthlyCss ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_v('/assets/css/social-autopilot.css'), ENT_QUOTES, 'UTF-8') ?>">
<div id="spRoot" class="sp-root" data-lang="<?= $ar ? 'ar' : 'en' ?>" data-can="<?= htmlspecialchars(implode(',', $team['can'] ?? ['view', 'draft', 'publish', 'inbox', 'manage']), ENT_QUOTES, 'UTF-8') ?>" data-role="<?= htmlspecialchars((string) ($team['role'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?>">
<?php if (!empty($deployWarning)): ?>
    <div class="alert alert-danger sp-small"><b><?= $t('تنبيه للأدمن: الرفع على السيرفر ناقص.', 'Admin notice: the server upload is incomplete.') ?></b> <?= $t('الملفات دي قديمة أو مش موجودة، فأجزاء من الصفحة هتطلع "تعذر التحميل" لحد ما ترفعها من ملف التحديث: ', 'These files are outdated or missing, so parts of this page will fail to load until you upload them from the update package: ') ?><code><?= htmlspecialchars(implode('، ', $deployWarning), ENT_QUOTES, 'UTF-8') ?></code></div>
<?php endif; ?>
<?php if (!empty($team['member'])): ?>
    <div class="alert alert-info sp-small"><?= $t('انت داخل كعضو في فريق الحساب بدور: ', 'You are working as a team member with the role: ') ?><b><?= htmlspecialchars((string) $team['role'], ENT_QUOTES, 'UTF-8') ?></b><?= in_array('publish', $team['can'] ?? [], true) ? '' : $t(' — المنشورات اللي بتعملها بتروح للمدير يعتمدها قبل النشر.', ' — posts you create go to a manager for approval before publishing.') ?></div>
<?php endif; ?>

    <div class="sp-hero">
        <div>
            <h2 class="sp-hero-title"><?= $t('مركز السوشيال ميديا', 'Social media hub') ?></h2>
            <p class="sp-hero-sub"><?= $t('اكتب وصمّم وانشر وجدول، ورد على تعليقات عملائك، وتابع النتائج - من مكان واحد.', 'Write, design, publish and schedule, answer your customers\' comments, and track results — all in one place.') ?></p>
        </div>
        <div class="sp-hero-actions">
            <button class="p-btn primary" type="button" onclick="openNewPost()"><i data-ico="plus"></i> <?= $t('منشور جديد', 'New post') ?></button>
            <button class="p-btn outline" type="button" onclick="openNewPost('story')"><i data-ico="story"></i> <?= $t('ستوري جديدة', 'New story') ?></button>
            <a class="p-btn outline" id="spReportBtn" href="/social/report" target="_blank" rel="noopener"><i data-ico="report"></i> <?= $t('تقرير الشهر', 'Monthly report') ?></a>
            <button class="sp-pkg-chip" id="spPkgChip" type="button" hidden></button>
        </div>
    </div>

    <nav class="p-tabs sp-tabs" role="tablist" aria-label="<?= $t('أقسام السوشيال', 'Social sections') ?>"<?= !empty($headerNav) ? ' hidden' : '' ?>>
        <button class="p-tab active" role="tab" data-tab="overview" type="button"><i data-ico="overview"></i> <?= $t('نظرة عامة', 'Overview') ?></button>
        <button class="p-tab" role="tab" data-tab="posts" type="button"><i data-ico="posts"></i> <?= $t('المنشورات والتقويم', 'Posts & calendar') ?></button>
        <button class="p-tab" role="tab" data-tab="inbox" type="button"><i data-ico="chat"></i> <?= $t('صندوق التعليقات', 'Comments inbox') ?> <span class="sp-badge" id="spInboxBadge" hidden></span></button>
        <button class="p-tab" role="tab" data-tab="competitors" type="button"><i data-ico="target"></i> <?= $t('المنافسين', 'Competitors') ?></button>
        <button class="p-tab" role="tab" data-tab="plan" type="button"><i data-ico="planCheck"></i> <?= $t('الخطة الشهرية', 'Monthly plan') ?></button>
        <button class="p-tab" role="tab" data-tab="accounts" type="button"><i data-ico="link"></i> <?= $t('الحسابات', 'Accounts') ?></button>
        <button class="p-tab" role="tab" data-tab="package" type="button"><i data-ico="gem"></i> <?= $t('الباقة', 'Plan') ?></button>
    </nav>
    <div id="spUpgradeBar" class="sp-upbar" hidden></div>

    <!-- ============ نظرة عامة ============ -->
    <section class="sp-panel" data-panel="overview">
        <div class="p-card sp-start" id="spStart" hidden></div>
        <div class="p-toolbar sp-bar">
            <select id="spDays" class="p-select" aria-label="<?= $t('الفترة', 'Period') ?>">
                <option value="7"><?= $t('آخر 7 أيام', 'Last 7 days') ?></option>
                <option value="30" selected><?= $t('آخر 30 يوم', 'Last 30 days') ?></option>
                <option value="90"><?= $t('آخر 90 يوم', 'Last 90 days') ?></option>
            </select>
            <button class="p-btn outline" type="button" id="spMetricsBtn" onclick="refreshMetrics(true)"><i data-ico="refresh"></i> <?= $t('تحديث التفاعل من Meta', 'Refresh engagement from Meta') ?></button>
            <span class="p-cell-muted sp-note" id="spMetricsNote"></span>
        </div>

        <div class="p-grid cols-4" id="spStats">
            <div class="p-card stat-tile"><div class="stat-icon blue"><i data-ico="layers"></i></div><div class="stat-info"><div class="stat-value" id="stTotal">–</div><div class="stat-label"><?= $t('إجمالي المنشورات', 'Total posts') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green"><i data-ico="check"></i></div><div class="stat-info"><div class="stat-value" id="stPub">–</div><div class="stat-label"><?= $t('اتنشرت', 'Published') ?> <span id="stRate" class="p-cell-muted"></span></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple"><i data-ico="calendar"></i></div><div class="stat-info"><div class="stat-value" id="stSched">–</div><div class="stat-label"><?= $t('مجدولة / مسودات', 'Scheduled / drafts') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange"><i data-ico="alert"></i></div><div class="stat-info"><div class="stat-value" id="stFail">–</div><div class="stat-label"><?= $t('فشلت', 'Failed') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue"><i data-ico="eye"></i></div><div class="stat-info"><div class="stat-value" id="stReach">–</div><div class="stat-label"><?= $t('الوصول (مشاهدون فريدون)', 'Reach (unique viewers)') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green"><i data-ico="thumb"></i></div><div class="stat-info"><div class="stat-value" id="stLikes">–</div><div class="stat-label"><?= $t('إعجابات', 'Likes') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple"><i data-ico="chat"></i></div><div class="stat-info"><div class="stat-value" id="stComments">–</div><div class="stat-label"><?= $t('تعليقات', 'Comments') ?></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange"><i data-ico="share"></i></div><div class="stat-info"><div class="stat-value" id="stShares">–</div><div class="stat-label"><?= $t('مشاركات', 'Shares') ?></div></div></div>
        </div>

        <div class="p-card sp-gap sh-summary" id="metaHistoryOverviewCard" aria-live="polite">
            <div class="p-card-head"><h3>Historical Meta engagement</h3><span class="p-card-sub">Saved Meta posts · separate from publishing totals</span></div>
            <div id="metaHistoryOverviewBody" class="p-cell-muted">Loading saved history…</div>
        </div>
        <div class="p-card sp-roi" id="spRoi" hidden></div>
        <div class="p-card sp-health" id="spHealth" hidden></div>

        <div class="p-grid cols-2 sp-gap">
            <div class="p-card"><div class="p-card-head"><h3><i data-ico="trend"></i> <?= $t('النشر اليومي', 'Daily publishing') ?></h3><span class="p-card-sub" id="spDailySub"></span></div><div class="sp-bars" id="spDaily"></div><div class="sp-axis p-cell-muted" id="spDailyAxis"></div></div>
            <div class="p-card"><div class="p-card-head"><h3><i data-ico="bars"></i> <?= $t('حسب المنصة', 'By platform') ?></h3></div><div id="spPlat" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
        </div>
        <div class="p-card sp-gap" id="spGrowthCard" hidden><div class="p-card-head"><h3><i data-ico="users"></i> <?= $t('نمو المتابعين', 'Follower growth') ?></h3><span class="p-card-sub"><?= $t('لقطة يومية لكل حساب', 'Daily snapshot per account') ?></span></div><div id="spGrowth" class="sp-growth"></div></div>
        <div class="p-grid cols-2 sp-gap">
            <div class="p-card"><div class="p-card-head"><h3><i data-ico="clock"></i> <?= $t('أفضل مواعيد النشر', 'Best times to post') ?></h3><span class="p-card-sub" id="spBestSub"></span></div><div id="spBest" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
            <div class="p-card"><div class="p-card-head"><h3><i data-ico="trophy"></i> <?= $t('أعلى المنشورات تفاعلًا', 'Top posts by engagement') ?></h3></div><div id="spTop" class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
        </div>
    </section>

    <!-- ============ المنشورات والتقويم ============ -->
    <section class="sp-panel" data-panel="posts" hidden>
        <div class="p-card no-pad">
            <div class="p-toolbar sp-bar sp-pad">
                <input type="search" id="spQ" class="p-select" placeholder="<?= $t('ابحث في النصوص…', 'Search posts…') ?>">
                <select id="spFStatus" class="p-select" aria-label="<?= $t('الحالة', 'Status') ?>">
                    <option value=""><?= $t('كل الحالات', 'All statuses') ?></option>
                    <option value="published"><?= $t('اتنشر', 'Published') ?></option>
                    <option value="scheduled"><?= $t('مجدول', 'Scheduled') ?></option>
                    <option value="draft"><?= $t('مسودة', 'Draft') ?></option>
                    <option value="failed"><?= $t('فشل', 'Failed') ?></option>
                    <option value="partial"><?= $t('اتنشر جزئيًا', 'Partially published') ?></option>
                    <option value="uncertain"><?= $t('محتاج مراجعة', 'Needs review') ?></option>
                </select>
                <select id="spFPlat" class="p-select" aria-label="<?= $t('المنصة', 'Platform') ?>">
                    <option value=""><?= $t('كل المنصات', 'All platforms') ?></option>
                    <option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube</option><option value="google_business">Google Business</option><option value="threads">Threads</option><option value="linkedin">LinkedIn</option>
                </select>
            </div>
            <!-- Remote Meta history is read-only; it never creates local publishing jobs. -->
            <div class="sp-pad" id="metaHistorySection" style="border-top:1px solid var(--border,#293247);border-bottom:1px solid var(--border,#293247)">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
                    <strong><?= $t('منشورات Meta القديمة', 'Historical Meta posts') ?></strong>
                    <select class="p-select" id="metaHistoryAccount" style="max-width:300px" aria-label="<?= $t('اختر الحساب', 'Select account') ?>"><option value=""><?= $t('تحميل الحسابات…', 'Loading accounts…') ?></option></select>
                    <button class="p-btn outline sm" type="button" id="metaHistoryFetch"><?= $t('مزامنة من Meta', 'Sync from Meta') ?></button>
                    <button class="p-btn outline sm" type="button" id="metaHistoryImport"><?= $t('حفظ المعروض', 'Save displayed batch') ?></button>
                    <button class="p-btn outline sm" type="button" id="metaHistorySaved"><?= $t('عرض المحفوظ', 'View saved') ?></button>
                </div>
                <div id="metaHistoryNotice" class="p-cell-muted sp-small" role="status"><?= $t('معاينة للقراءة فقط لآخر 30 منشورًا. لا تغيّر أو تعيد نشر أي منشور.', 'Read-only preview of the latest 30 posts. Nothing is modified or republished.') ?></div>
                <div id="metaHistoryResults" class="mh-grid"></div>
            </div>
            <div id="postsList" class="sp-posts"><div class="p-cell-muted sp-pad"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
            <div class="sp-more"><button type="button" class="p-btn outline sm" id="spMore" hidden><?= $t('عرض المزيد', 'Show more') ?></button></div>
        </div>
        <div class="p-card no-pad sp-gap">
            <div class="p-card-head sp-pad"><h3><i data-ico="calendar"></i> <?= $t('تقويم المحتوى', 'Content calendar') ?></h3><span class="p-card-sub"><?= $t('الأسبوع اللي فات + الشهر الجاي', 'Last week + next month') ?></span></div>
            <div class="sp-pad sh-calendar" id="metaHistoryCalendarCard">
                <h4>Historical Meta posts by date <span class="p-cell-muted" style="font-size:12px">(read-only · original publication dates)</span></h4>
                <div id="metaHistoryCalendarBody" class="p-cell-muted">Loading saved history…</div>
            </div>
            <div class="sp-cal" id="calendarGrid"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
    </section>

    <!-- ============ صندوق التعليقات ============ -->
    <section class="sp-panel" data-panel="inbox" hidden>
        <div class="p-card">
            <div class="ib-pages" id="ibPages" hidden></div>
            <div class="p-toolbar sp-bar">
                <select id="ibPage" class="p-select" aria-label="<?= $t('الصفحة', 'Page') ?>" hidden>
                    <option value=""><?= $t('كل الصفحات', 'All pages') ?></option>
                </select>
                <select id="ibStatus" class="p-select" aria-label="<?= $t('الحالة', 'Status') ?>">
                    <option value="open"><?= $t('محتاجة رد', 'Needs reply') ?></option>
                    <option value="replied"><?= $t('اترد عليها', 'Replied') ?></option>
                    <option value="hidden"><?= $t('مخفية', 'Hidden') ?></option>
                    <option value="done"><?= $t('اتقفلت', 'Closed') ?></option>
                    <option value="all"><?= $t('الكل', 'All') ?></option>
                </select>
                <select id="ibIntent" class="p-select" aria-label="<?= $t('النوع', 'Type') ?>">
                    <option value=""><?= $t('كل الأنواع', 'All types') ?></option>
                    <option value="price"><?= $t('سؤال عن السعر', 'Price question') ?></option>
                    <option value="booking"><?= $t('حجز / تفاصيل', 'Booking / details') ?></option>
                    <option value="question"><?= $t('سؤال', 'Question') ?></option>
                    <option value="complaint"><?= $t('شكوى', 'Complaint') ?></option>
                    <option value="praise"><?= $t('مدح', 'Praise') ?></option>
                    <option value="spam"><?= $t('سبام', 'Spam') ?></option>
                    <option value="other"><?= $t('غير ذلك', 'Other') ?></option>
                </select>
                <button class="p-btn outline" type="button" id="ibSync"><i data-ico="refresh"></i> <?= $t('اسحب التعليقات الجديدة', 'Fetch new comments') ?></button>
                <span class="p-cell-muted sp-note" id="ibCounts"></span>
            </div>
            <details class="sp-autobox" id="ibSettingsBox">
                <summary><i data-ico="bot"></i> <?= $t('الرد الخاص التلقائي على أسئلة السعر والحجز', 'Automatic private replies to price & booking questions') ?> <span class="sp-badge" id="ibAutoState"></span></summary>
                <p class="p-cell-muted sp-small"><?= $t('أول ما حد يكتب "بكام؟" أو "عايز أحجز" تحت منشورك، المنصة تبعتله رسالة خاصة (ماسنجر/دايركت) بالتفاصيل ورابط الحجز، وترد علنيًا إنها بعتتله. ده بيحوّل التعليقات لحجوزات وانت نايم.', 'When someone comments "how much?" or "I want to book", the platform sends them a private message (Messenger/Direct) with details and your booking link, and replies publicly that it did. Comments turn into bookings while you sleep.') ?></p>
                <label class="sp-check"><input type="checkbox" id="ibAuto"> <b><?= $t('تفعيل الرد الخاص التلقائي', 'Enable automatic private replies') ?></b></label>
                <div class="sp-row sp-small" id="ibIntents">
                    <label class="sp-radio"><input type="checkbox" value="price" checked> <?= $t('أسئلة السعر', 'Price questions') ?></label>
                    <label class="sp-radio"><input type="checkbox" value="booking" checked> <?= $t('طلبات الحجز/التفاصيل', 'Booking / details requests') ?></label>
                    <label class="sp-radio"><input type="checkbox" value="question"> <?= $t('الأسئلة العامة', 'General questions') ?></label>
                </div>
                <div class="sp-field" id="ibAutoPagesWrap" hidden>
                    <div class="sp-row" style="justify-content:space-between"><b><?= $t('الصفحات اللي يرد عليها', 'Pages it replies on') ?></b><span class="sp-row sp-gap-s"><button type="button" class="p-btn ghost xs" data-ap-all="1"><?= $t('الكل', 'All') ?></button><button type="button" class="p-btn ghost xs" data-ap-all="0"><?= $t('ولا واحدة', 'None') ?></button></span></div>
                    <div class="ib-ap" id="ibAutoPages"></div>
                </div>
                <label class="sp-field"><?= $t('الرسالة الخاصة', 'Private message') ?> <span class="p-cell-muted sp-small"><?= $t('متغيرات: {name} الاسم، {company} الشركة، {link} رابط الحجز', 'Variables: {name}, {company}, {link}') ?></span><textarea id="ibTemplate" rows="4" class="p-select" maxlength="1500"></textarea></label>
                <label class="sp-field"><?= $t('الرد العلني القصير', 'Short public reply') ?><input type="text" id="ibAck" class="p-select" maxlength="300"></label>
                <div class="sp-row sp-gap-s"><button type="button" class="p-btn primary sm" id="ibSaveSettings"><?= $t('حفظ', 'Save') ?></button><span class="p-cell-muted sp-small"><?= $t('محتاج صلاحية رسائل الصفحة من Meta (pages_messaging). Meta بتسمح برسالة خاصة واحدة لكل تعليق خلال 7 أيام.', 'Requires Meta page messaging permission (pages_messaging). Meta allows one private message per comment within 7 days.') ?></span></div>
            </details>
            <div id="ibNotice"></div>
            <div id="ibList" class="ib-list"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
    </section>

    <!-- ============ المنافسين ============ -->
    <section class="sp-panel" data-panel="competitors" hidden>
        <div class="p-card">
            <div class="p-card-head"><h3><i data-ico="target"></i> <?= $t('مراقبة المنافسين على إنستجرام', 'Instagram competitor monitoring') ?></h3><button class="p-btn outline sm" type="button" id="cpRefresh"><i data-ico="refresh"></i> <?= $t('تحديث', 'Refresh') ?></button></div>
            <p class="p-cell-muted sp-small"><?= $t('ضيف لحد 5 حسابات بيزنس منافسة. كل يوم بنجيب متابعينهم ومعدل نشرهم ومتوسط تفاعلهم وأنجح منشوراتهم وهاشتاجاتهم، ونقارنهم بحسابك.', 'Add up to 5 competing business accounts. Every day we fetch their followers, posting rhythm, average engagement, top posts and hashtags, and compare them to your account.') ?></p>
            <form class="sp-row" id="cpForm" onsubmit="return false">
                <input type="text" id="cpUser" class="p-select sp-grow" placeholder="<?= $t('اسم الحساب أو الرابط - مثال: @competitor_tours', 'Username or link — e.g. @competitor_tours') ?>" maxlength="80" dir="ltr">
                <button class="p-btn primary" type="submit" id="cpAdd"><i data-ico="plus"></i> <?= $t('إضافة منافس', 'Add competitor') ?></button>
            </form>
            <div id="cpInsights"></div>
            <div id="cpList" class="cp-list"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
    </section>

    <!-- ============ الخطة الشهرية ============ -->
    <section class="sp-panel" data-panel="plan" hidden>
<?php if (!empty($team['member'])): ?>
        <div class="p-card"><div class="p-empty"><i data-ico="planCheck"></i> <?= $t('الخطة الشهرية بيجهزها ويعتمدها صاحب الحساب. تقدر تشوف منشوراتها المجدولة في تبويب «المنشورات والتقويم».', 'The monthly plan is prepared and approved by the account owner. You can see its scheduled posts in the "Posts & calendar" tab.') ?></div></div>
<?php else: ?>
        <div class="p-card sp-share" id="shareCard">
            <div class="p-card-head"><h3><i data-ico="hand"></i> <?= $t('موافقة العميل على الخطة', 'Client approval') ?></h3></div>
            <p class="p-cell-muted sp-small"><?= $t('ابعت لصاحب الشركة رابط خاص يشوف فيه منشورات الشهر بالصور والنصوص والمواعيد، ويكتب ملاحظاته أو يعتمد الخطة من غير ما يسجّل دخول. كل رد بيوصلك إشعار.', 'Send the business owner a private link to review the month\'s posts with images, captions and dates, leave comments or approve — no login needed. You get notified on every response.') ?></p>
            <div class="sp-row">
                <select id="shPlan" class="p-select" aria-label="<?= $t('الخطة', 'Plan') ?>"></select>
                <input type="text" id="shClient" class="p-select" maxlength="120" placeholder="<?= $t('اسم العميل (اختياري)', 'Client name (optional)') ?>">
                <select id="shDays" class="p-select"><option value="7">7 <?= $t('أيام', 'days') ?></option><option value="14" selected>14 <?= $t('يوم', 'days') ?></option><option value="30">30 <?= $t('يوم', 'days') ?></option></select>
                <label class="sp-radio"><input type="checkbox" id="shApprove" checked> <?= $t('يقدر يعتمد الخطة', 'Can approve') ?></label>
                <button type="button" class="p-btn primary sm" id="shCreate"><i data-ico="link"></i> <?= $t('اعمل رابط', 'Create link') ?></button>
            </div>
            <div id="shNew"></div>
            <div id="shLinks"></div>
            <div id="shFeedback"></div>
        </div>
        <section id="socialAutopilotRoot" class="sap-root p-card" aria-label="<?= $t('الطيار الآلي للسوشيال', 'Social autopilot') ?>"></section>
        <section id="monthlySocialRoot" class="smp-root p-card" data-user-id="<?= (int) $uid ?>" aria-label="<?= $t('الخطة الشهرية', 'Monthly social media plan') ?>"><p><?= $t('جارِ تحميل الخطط الشهرية…', 'Loading monthly plans…') ?></p></section>
<?php endif; ?>
    </section>

    <!-- ============ الحسابات ============ -->
    <section class="sp-panel" data-panel="accounts" hidden>
        <div class="p-card sp-pad">
            <div class="p-card-head"><h3><i data-ico="users"></i> <?= $t('حساباتك', 'Your accounts') ?></h3><span class="p-card-sub" id="spSlots"></span></div>
            <p class="p-cell-muted sp-small"><?= $t('اختار الصفحات اللي هتشتغل عليها: الصفحة الموقوفة بتختفي من النشر وما بتتحسبش من حسابات باقتك، وتقدر تشغّلها تاني في أي وقت.', 'Choose the pages you work with: a paused page is hidden from publishing and does not count toward your plan, and you can turn it back on any time.') ?></p>
            <div id="spAccounts" class="sp-acc-grid"><span class="p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></span></div>
        </div>
        <div class="p-card sp-pad sp-gap">
            <div class="p-card-head"><h3><i data-ico="plug"></i> <?= $t('اربط حساب جديد', 'Connect an account') ?></h3></div>
            <div class="sp-connect">
                <a class="sp-conn" data-plats="facebook,instagram" href="/ads/connect/meta?for=social"><span class="sp-conn-ic"><i data-brand="facebook" data-size="34"></i><i data-brand="instagram" data-size="34"></i></span><b>Facebook + Instagram</b><span><?= $t('صفحات فيسبوك وحسابات إنستجرام بيزنس: منشورات، ريلز، ألبومات، ستوري، تعليقات', 'Facebook Pages & Instagram Business: posts, reels, albums, stories, comments') ?></span><em><?= $t('اربط واختار الصفحات', 'Connect & pick pages') ?></em></a>
                <a class="sp-conn" data-plats="tiktok" href="/social/connect/tiktok"><span class="sp-conn-ic"><i data-brand="tiktok" data-size="34"></i></span><b>TikTok</b><span><?= $t('فيديوهات قصيرة بإعدادات الخصوصية المطلوبة من تيك توك', 'Short videos with TikTok\'s required privacy settings') ?></span><em><?= $t('اربط', 'Connect') ?></em></a>
                <a class="sp-conn" data-plats="youtube" href="/social/connect/youtube"><span class="sp-conn-ic"><i data-brand="youtube" data-size="34"></i></span><b>YouTube Shorts</b><span><?= $t('رفع الفيديوهات القصيرة على قناتك', 'Upload short videos to your channel') ?></span><em><?= $t('اربط', 'Connect') ?></em></a>
                <a class="sp-conn" data-plats="google_business" href="/reputation/platforms"><span class="sp-conn-ic"><i data-brand="google_business" data-size="34"></i></span><b>Google Business</b><span><?= $t('منشورات على ملفك في خرائط وبحث جوجل (صورة + زرار احجز/اعرف أكتر)', 'Posts on your Google Maps & Search profile (photo + book/learn-more button)') ?></span><em><?= $t('اربط', 'Connect') ?></em></a>
                <a class="sp-conn" data-plats="threads" href="/social/connect/threads"><span class="sp-conn-ic"><i data-brand="threads" data-size="34"></i></span><b>Threads</b><span><?= $t('نص وصور وفيديو على Threads (حتى 500 حرف)', 'Text, photos and video on Threads (up to 500 characters)') ?></span><em><?= $t('اربط', 'Connect') ?></em></a>
<?php if (in_array('linkedin', SocialPackages::config()['platforms_enabled'], true)): ?>
                <a class="sp-conn" data-plats="linkedin" href="/social/connect/linkedin"><span class="sp-conn-ic"><i data-brand="linkedin" data-size="34"></i></span><b>LinkedIn</b><span><?= $t('بروفايلك وصفحات شركتك: مناسب لعملاء الشركات والرحلات الجماعية', 'Your profile and company pages — great for corporate & group travel') ?></span><em><?= $t('اربط', 'Connect') ?></em></a>
<?php endif; ?>
            </div>
            <details class="sp-help">
                <summary><?= $t('ليه صندوق التعليقات أو أول تعليق مش شغالين؟', 'Why don\'t the comments inbox or first comment work?') ?></summary>
                <p><?= $t('قراءة التعليقات والرد عليها محتاجين صلاحيات إضافية من Meta: pages_read_user_content و pages_manage_engagement (فيسبوك) و instagram_manage_comments (إنستجرام). مدير المنصة يضيفهم في إعداد META_SOCIAL_EXTRA_SCOPES ويطلب موافقة Meta عليهم (App Review)، وبعدها اربط فيسبوك وإنستجرام تاني ووافق على كل الصلاحيات.', 'Reading and replying to comments needs extra Meta permissions: pages_read_user_content and pages_manage_engagement (Facebook) and instagram_manage_comments (Instagram). The platform admin adds them to META_SOCIAL_EXTRA_SCOPES and requests Meta App Review; then reconnect Facebook & Instagram and accept all permissions.') ?></p>
            </details>
        </div>
    </section>

    <!-- ============ الباقة ============ -->
    <section class="sp-panel" data-panel="package" hidden>
        <div id="pkRoot"><div class="p-card p-cell-muted"><?= $t('جارِ التحميل…', 'Loading…') ?></div></div>
    </section>

    <!-- ============ محرر المنشور ============ -->
    <div class="p-modal-overlay" id="newPostModal">
        <div class="p-modal wide sp-composer" role="dialog" aria-modal="true" aria-labelledby="spComposerTitle">
            <div class="p-modal-head"><h3 id="spComposerTitle"><?= $t('منشور جديد', 'New post') ?></h3><button class="p-modal-close" type="button" onclick="closeComposer()" aria-label="<?= $t('إغلاق', 'Close') ?>">×</button></div>
            <div class="p-modal-body sp-cmp-grid">
                <div class="sp-cmp-main">
                    <div class="sp-seg" role="radiogroup" aria-label="<?= $t('نوع المحتوى', 'Content type') ?>">
                        <label><input type="radio" name="spFormat" value="feed" checked> <i data-ico="posts"></i> <?= $t('منشور', 'Post') ?></label>
                        <label><input type="radio" name="spFormat" value="story"> <i data-ico="story"></i> <?= $t('ستوري', 'Story') ?></label>
                    </div>

                    <div class="sp-step"><span class="sp-num">1</span><b><?= $t('هتنشر فين؟', 'Where to publish?') ?></b>
                        <button type="button" class="p-btn ghost xs" onclick="refreshSocialTargets()"><i data-ico="refresh"></i> <?= $t('تحديث الصفحات', 'Refresh pages') ?></button></div>
                    <div id="targetsList" class="sp-targets p-cell-muted"><?= $t('جارِ تحميل الصفحات المربوطة…', 'Loading connected pages…') ?></div>
                    <div id="ttPanel" class="tt-panel" hidden></div>

                    <div class="sp-step"><span class="sp-num">2</span><b><?= $t('الصورة أو الفيديو', 'Image or video') ?></b></div>
                    <div class="sp-media">
                        <div class="sp-prev" id="spPrev"><i data-ico="image" data-size="30"></i></div>
                        <div class="sp-media-actions">
                            <div class="sp-row">
                                <label class="p-btn outline sm sp-file"><i data-ico="upload"></i> <?= $t('ارفع من جهازك', 'Upload') ?><input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" hidden></label>
                                <button type="button" class="p-btn outline sm" onclick="toggleLib()"><i data-ico="folder"></i> <?= $t('من المكتبة', 'From library') ?></button>
                                <button type="button" class="p-btn outline sm" id="spAlbumBtn" onclick="toggleAlbumMode()" title="<?= $t('من 2 لـ 10 صور (ألبوم فيسبوك / كاروسيل إنستجرام)', '2–10 photos (Facebook album / Instagram carousel)') ?>"><i data-ico="images"></i> <?= $t('صور متعددة', 'Multiple photos') ?></button>
                                <a class="p-btn outline sm" href="/creative-studio" target="_blank" rel="noopener"><i data-ico="sparkles"></i> <?= $t('صمّم بالذكاء الاصطناعي', 'Create with AI') ?> <i data-ico="external" data-size="13"></i></a>
                                <button type="button" class="p-btn ghost sm" id="spClear" hidden onclick="clearMedia()"><?= $t('إزالة', 'Remove') ?></button>
                            </div>
                            <div id="spAlbumStrip" class="sp-album"></div>
                            <div class="p-cell-muted sp-small" id="spMediaNote"><?= $t('صورة JPG/PNG/WEBP لحد 10 ميجا، أو فيديو MP4 لحد 100 ميجا. مقاس الصورة بيتظبط لإنستجرام تلقائي.', 'JPG/PNG/WEBP up to 10 MB, or MP4 up to 100 MB. Images are auto-fitted for Instagram.') ?></div>
                        </div>
                    </div>
                    <div class="sp-lib" id="spLib" hidden></div>

                    <div class="sp-feed-only">
                        <div class="sp-step"><span class="sp-num">3</span><b><?= $t('النص', 'Caption') ?></b></div>
                        <div class="sp-ai">
                            <div class="sp-row">
                                <input type="text" id="topicInput" class="p-select sp-grow" maxlength="400" placeholder="<?= $t('موضوع المنشور - مثال: رحلة سنوركلينج في الغردقة للعائلات', 'Post topic — e.g. family snorkeling trip in Hurghada') ?>">
                                <select id="aiTone" class="p-select" aria-label="<?= $t('الأسلوب', 'Tone') ?>">
                                    <option value="friendly"><?= $t('ودود', 'Friendly') ?></option><option value="luxury"><?= $t('فخم', 'Luxury') ?></option><option value="adventure"><?= $t('مغامرة', 'Adventure') ?></option><option value="family"><?= $t('عائلي', 'Family') ?></option><option value="professional"><?= $t('رسمي', 'Professional') ?></option><option value="fun"><?= $t('مرح', 'Fun') ?></option>
                                </select>
                                <select id="aiGoal" class="p-select" aria-label="<?= $t('الهدف', 'Goal') ?>">
                                    <option value="engagement"><?= $t('تفاعل', 'Engagement') ?></option><option value="bookings"><?= $t('حجوزات', 'Bookings') ?></option><option value="offer"><?= $t('عرض', 'Offer') ?></option><option value="awareness"><?= $t('انتشار', 'Awareness') ?></option><option value="trust"><?= $t('ثقة', 'Trust') ?></option>
                                </select>
                                <button class="p-btn outline" type="button" id="aiCapBtn" onclick="generateCaption()"><i data-ico="sparkles"></i> <?= $t('اكتب 3 نسخ', 'Write 3 versions') ?></button>
                            </div>
                            <div id="aiVariants" class="sp-variants" hidden></div>
                        </div>
                        <textarea id="contentInput" rows="6" class="p-select sp-text" placeholder="<?= $t('اكتب النص أو اختار نسخة من الذكاء الاصطناعي…', 'Write your caption or pick an AI version…') ?>"></textarea>
                        <div class="sp-counter" id="spCounter"></div>
                        <div class="sp-row sp-gap-s">
                            <label class="sp-field sp-grow"><?= $t('هاشتاجات', 'Hashtags') ?><input type="text" id="hashtagsInput" class="p-select" placeholder="#Hurghada #RedSea"></label>
                        </div>
                        <details class="sp-more">
                            <summary><?= $t('خيارات متقدمة: أول تعليق وتتبّع الروابط', 'Advanced: first comment & link tracking') ?></summary>
                            <label class="sp-field"><?= $t('أول تعليق تلقائي (رابط الحجز أو سؤال للجمهور) - فيسبوك وإنستجرام', 'Automatic first comment (booking link or a question) — Facebook & Instagram') ?><textarea id="firstCommentInput" rows="2" class="p-select" maxlength="2000"></textarea></label>
                            <label class="sp-check"><input type="checkbox" id="trackOn" checked> <?= $t('روابط تتبّع الحجوزات: روابط موقعك بتتحول لروابط قصيرة، وتعرف كل منشور جاب كام زيارة وكام حجز وكام فلوس', 'Booking-tracking links: your website links become short links so you see visits, bookings and revenue per post') ?></label>
                            <label class="sp-check"><input type="checkbox" id="utmOn" checked> <?= $t('تتبّع روابط موقعي بـ UTM (تعرف الحجوزات جت من أنهي منشور في Google Analytics)', 'Track my website links with UTM (see which post brought bookings in Google Analytics)') ?></label>
                            <label class="sp-field"><?= $t('اسم الحملة', 'Campaign name') ?><input type="text" id="utmCampaign" class="p-select" maxlength="60"></label>
                        </details>
                    </div>
                    <div class="sp-story-only alert alert-info" hidden><?= $t('الستوري بتتنشر صورة أو فيديو واحد من غير نص (فيسبوك وإنستجرام). اكتب العرض أو الرسالة على التصميم نفسه من "صمّم بالذكاء الاصطناعي"، والأفضل مقاس 9:16.', 'Stories publish a single image or video with no caption (Facebook & Instagram). Put your offer/message on the design itself via "Create with AI"; 9:16 works best.') ?></div>

                    <div class="sp-step"><span class="sp-num" id="spWhenNum">4</span><b><?= $t('امتى؟', 'When?') ?></b></div>
                    <div class="sp-row">
                        <label class="sp-radio"><input type="radio" name="spWhen" value="now" checked> <?= $t('دلوقتي', 'Now') ?></label>
                        <label class="sp-radio"><input type="radio" name="spWhen" value="later"> <?= $t('جدولة', 'Schedule') ?></label>
                        <input type="datetime-local" id="spAt" class="p-select" hidden>
                        <button type="button" class="p-btn ghost sm" id="spBestBtn" hidden onclick="pickBestTime()"><i data-ico="clock"></i> <?= $t('أفضل ميعاد', 'Best time') ?></button>
                    </div>
                    <div class="p-cell-muted sp-small" id="spWhenNote"><?= $t('المواعيد بتوقيت حسابك. لو ما اخترتش صفحة، المنشور هيتحفظ مسودة.', 'Times use your account timezone. With no page selected the post is saved as a draft.') ?></div>
                </div>

                <aside class="sp-cmp-side" aria-label="<?= $t('معاينة', 'Preview') ?>">
                    <div class="sp-prevtabs" id="spPrevTabs"></div>
                    <div class="sp-mock" id="spMock" data-no-translate></div>
                    <ul class="sp-checklist" id="spChecklist"></ul>
                </aside>
            </div>
            <div class="p-modal-foot">
                <button class="p-btn outline" type="button" onclick="closeComposer()"><?= $t('إلغاء', 'Cancel') ?></button>
                <button class="p-btn primary" type="button" id="spSubmit" onclick="createPost()"><?= $t('انشر', 'Publish') ?></button>
            </div>
        </div>
    </div>

    <!-- ============ تعديل منشور ============ -->
    <div class="p-modal-overlay" id="editPostModal">
        <div class="p-modal" role="dialog" aria-modal="true">
            <div class="p-modal-head"><h3><?= $t('تعديل المنشور', 'Edit post') ?></h3><button class="p-modal-close" type="button" onclick="document.getElementById('editPostModal').classList.remove('open')" aria-label="<?= $t('إغلاق', 'Close') ?>">×</button></div>
            <div class="p-modal-body">
                <label class="sp-field"><?= $t('نص المنشور', 'Post text') ?><textarea id="editContent" rows="6" class="p-select"></textarea></label>
                <div id="editWhenBox" hidden>
                    <label class="sp-field"><?= $t('موعد جديد (اختياري)', 'New time (optional)') ?><input type="datetime-local" id="editAt" class="p-select"></label>
                </div>
                <div class="p-cell-muted sp-small" id="editNote"></div>
            </div>
            <div class="p-modal-foot">
                <button class="p-btn outline" type="button" onclick="document.getElementById('editPostModal').classList.remove('open')"><?= $t('إلغاء', 'Cancel') ?></button>
                <button class="p-btn primary" type="button" id="editSubmit" onclick="saveEdit()"><?= $t('حفظ', 'Save') ?></button>
            </div>
        </div>
    </div>
</div>
<script defer src="<?= $pickerJs ?>"></script>
<?php if (empty($team['member'])): ?><script defer src="<?= htmlspecialchars(asset_v('/assets/js/social-autopilot.js'), ENT_QUOTES, 'UTF-8') ?>"></script><script defer src="<?= $monthlyJs ?>"></script><?php endif; ?>
<script defer src="<?= $iconsJs ?>"></script>
<script defer src="<?= $jsUrl ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_v('/assets/css/social-history-cards.css'), ENT_QUOTES, 'UTF-8') ?>">
<script defer src="<?= htmlspecialchars(asset_v('/assets/js/social-history-preview.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars(asset_v('/assets/css/social-history-summary.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_v('/assets/css/social-overview-redesign.css'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset_v('/assets/css/social-health-current.css'), ENT_QUOTES, 'UTF-8') ?>">
<script defer src="<?= htmlspecialchars(asset_v('/assets/js/social-history-summary.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
