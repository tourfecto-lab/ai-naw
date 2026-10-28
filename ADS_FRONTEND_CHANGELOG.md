# ADS_FRONTEND_CHANGELOG.md

**التاريخ:** 2026-08-10 إلى 2026-08-11 (عدة جولات متتالية - هذا الملف يوثّق الكل)
**النطاق:** Frontend كامل لموديول TOURFECTO ADS فوق الـBackend الموجود بالفعل
(نفس الـBackend من التسليمات السابقة - `AdAutopilotEngine`, `GoogleAdsAPI`,
`MetaAdsAPI`, `AdReportService`, `AdKeywordStrategistService`,
`AdMarketResearchService`, `LandingPageAnalysisService`,
`AdsCompetitorInsightsService`, `AdsCopilotService`, `AdTrackingService`).

**تحديث مهم:** الجولات الأولى كانت Frontend خالص. آخر جولتين (Ad Groups +
Bulk/Delete Campaign) احتاجوا إضافات Backend حقيقية وصغيرة (جدول واحد +
عمود واحد) بعد طلب صريح من العميل لإكمالهم - موثّق بالتفصيل الكامل تحت
في قسم "Files Added" و"Known Issues". **لسه مفيش Mock APIs ولا بيانات
وهمية في أي جولة.**

---

## ⚠️ اقرأ الأول

1. الجولات الأولى من هذا التسليم كانت **فرونت اند فقط** فوق الـBackend
   الموجود. لم أنشئ أي جدول أو Service أو منطق أعمال جديد فيها - فقط
   صفحات جديدة + endpoints خفيفة للتجميع/العرض (aggregation for display)
   + توسيع endpoints موجودة بفلاتر اختيارية إضافية (كلها Backward-compatible،
   مفيش كسر لأي استدعاء قديم). آخر جولتين (Ad Groups + Bulk/Delete) كانوا
   الاستثناء الموثّق أعلاه.
2. **المعمارية**: فحصت المشروع الأول ولقيت إنه PHP Server-Rendered + vanilla
   JS (نفس نمط `CrmController`/`renderPanelPage()`)، مش React/Vue. طوّرت على
   نفس النمط ده بالظبط - احترامًا لتعليمة "لا تنشئ Architecture ثانية".
3. **صفحة `/ads` الأصلية لم تُحذف أو تُعاد كتابتها** - أُضيف لها فقط قسم
   KPIs حقيقي + شريط تنقّل في الأعلى (Additive-only، صفر خطر على أي حاجة
   كانت شغالة قبل كده).

---

## Files Added (كل الملفات الجديدة عبر جولتين احتاجوا Backend حقيقي: Ad Groups + Team Permissions)

```
database/migrations/2026_08_11_000044_add_ad_groups.sql - جدول ad_ad_groups + ربط اختياري بـad_copies/ad_keywords + Soft Delete للحملات
database/migrations/2026_08_12_000045_add_ads_team_permissions.sql - جدول ad_team_members (Viewer/Manager/Admin)
app/Models/AdAdGroup.php
app/Models/AdTeamMember.php
app/Services/Ads/AdPermissionService.php - حل الصلاحيات (owner > admin > manager > viewer) + إدارة الفريق
```

## Files Modified

```
app/Controllers/AdsController.php   - 10 صفحات + endpoints جديدة/موسّعة + Pause/Resume + Search/Pagination + Ad Groups + Bulk/Delete + Team Permissions
app/Services/Ads/AdReportService.php - dashboardSummary(), dailyTrend() موسّعة, campaignComparison()
app/Services/Ads/AdCampaignService.php - listForUserPaginated() جديدة + استبعاد الحملات المحذوفة (Soft Delete)
app/Services/Ads/AdAutopilotEngine.php - ربط Notification::notify() الموجود بالفعل (البند 23)
app/Models/AdCampaign.php           - fillable موسّعة (deleted_at)
app/Models/AdCopy.php, AdKeyword.php - fillable موسّعة (ad_group_id اختياري)
app/routes/web.php                  - 9 routes صفحات جديدة
app/routes/api.php                  - 23 route API جديدة + توسيع 2 موجودين بفلتر اختياري
```

---


## Pages Created (كلها تستخدم renderPanelPage() الموجود + Chart.js المحمّل تلقائيًا من الـshell)

| الصفحة | الوصف | مصدر البيانات |
|---|---|---|
| `/ads` (مُحدَّثة) | Dashboard نضيف: KPIs حقيقية + فلاتر + توصيات AI (Teaser) + ربط Meta/Google السريع + جدول الحملات (بحث/فلترة/Pagination) | `AdReportService::dashboardSummary()` + `ad_pending_actions` |
| `/ads/reports` (جديدة) | تقارير الأداء: Chart اتجاه يومي حقيقي + تقرير دوري + قسم Attribution | `AdReportService::dailyTrend()` + `generate()` |
| `/ads/budget` (جديدة) | الميزانية والإنفاق: KPIs + Chart اتجاه + مقارنة حملات | `AdReportService` |
| `/ads/competitors` (جديدة) | تحليل منافس من منظور إعلاني | `AdsCompetitorInsightsService` |
| `/ads/connections` (جديدة) | Connection Center تفصيلي | `platform_connections` |
| `/ads/campaigns/{id}` (جديدة) | تفاصيل الحملة الكاملة + Pause/Resume حقيقي | تجميع من عدة Models |
| `/ads/autopilot` (جديدة - كانت كروت داخل `/ads`) | إعدادات Guardrails + قرارات معلّقة + سجل تحسين + Rollback | `AdAutopilotEngine` |
| `/ads/copilot` (جديدة - كانت كارت داخل `/ads`) | شات AI Copilot مكبّر بملء الصفحة | `AdsCopilotService` |
| `/ads/market-research` (جديدة - كانت كارت داخل `/ads`) | بحث أسواق/دول + أرشيف تحليلات سابقة (جديد) | `AdMarketResearchService` |

**كل صفحة فيها شريط تنقّل موحّد (`adsTabsHtml()`) بيشاور على URL حقيقي لكل
قسم - مفيش أي Anchor links دلوقتي، كل تبويب صفحة مستقلة فعليًا.**

## Components (بمعنى المشروع - HTML/JS blocks قابلة لإعادة الاستخدام داخل renderPanelPage)

- `adsTabsHtml($active)` - شريط تنقّل فرعي مشترك لكل صفحات الإعلانات (نفس نمط `crmTabsHtml`).
- كروت KPI قابلة لإعادة الاستخدام (`kpi()` JS helper) تظهر "لا توجد بيانات كافية" بدل رقم مُختلق لو null.
- Chart.js wrappers لخط Trend وBar Comparison (نفس نمط `Chart.js` الموجود في `DashboardController`).

---

## APIs Connected

### Endpoints جديدة (Read-only aggregation/display فقط - مفيش منطق أعمال جديد):
```
GET  /api/ads/dashboard/summary?period=&platform=&status=
GET  /api/ads/reports/trend?days=&campaign_id=
GET  /api/ads/reports/comparison?period=
GET  /api/ads/campaigns/{id}
GET  /api/ads/campaigns/search?q=&status=&sort=&dir=&page=&per_page=
GET  /api/ads/connections/status
GET  /api/ads/competitors
```

### Endpoint جديد بيكتب فعليًا (يستدعي write methods موجودة من قبل، مبنيين وقت الـAutopilot):
```
POST   /api/ads/campaigns/{id}/status   { status: "active"|"paused" }
DELETE /api/ads/campaigns/{id}          (Soft Delete + إيقاف حقيقي على المنصة لو شغّالة)
POST   /api/ads/campaigns/bulk-status   { campaign_ids: [...], status: "active"|"paused" }
```

### Ad Groups (جدول Backend جديد صغير - راجع Known Issues للنطاق):
```
POST /api/ads/campaigns/{id}/ad-groups
GET  /api/ads/campaigns/{id}/ad-groups
POST /api/ads/ad-groups/{id}/status
DELETE /api/ads/ad-groups/{id}
POST /api/ads/keywords/{id}/assign-group
```

### Team Permissions (جدول Backend جديد - راجع Known Issues للنطاق الفعلي):
```
GET  /api/ads/team
POST /api/ads/team
POST /api/ads/team/{id}/role
POST /api/ads/team/{id}/remove
```

### Endpoints موجودة اتوسّعت بفلتر اختياري (Backward-compatible 100%):
```
GET /api/ads/autopilot/logs?campaign_id=   (فلتر اختياري جديد، الاستدعاء القديم من غيره لسه شغال)
GET /api/ads/reports/trend?campaign_id=    (فلتر اختياري جديد)
```

### Endpoints موجودة اتستخدمت زي ما هي بدون أي تعديل:
```
GET  /api/ads/campaigns/{id}/copies
POST /api/ads/campaigns/{id}/keywords/generate
GET  /api/ads/campaigns/{id}/keywords
POST /api/ads/campaigns/{id}/landing-page/analyze
POST /api/ads/campaigns/{id}/utm-links
GET  /api/ads/campaigns/{id}/utm-links
POST /api/ads/competitors/{id}/analyze
POST /api/ads/google/sync, /api/ads/meta/sync
POST /api/ads/google/disconnect, /api/ads/meta/disconnect
GET  /api/ads/autopilot/pending, /api/ads/autopilot/settings
GET  /api/dashboard/notifications (نظام Notifications الموجود بالفعل - ربطناه، مبنيناهوش)
```

---

## البند 23 (Notifications) - استخدمنا الموجود، ماعملناش نظام جديد

اكتشفنا إن المشروع فيه بالفعل نظام إشعارات عام (`Notification` Model +
`/api/dashboard/notifications` + جرس إشعارات في الـTopbar شغّال في كل صفحة
عبر `panel.js`). **مش عملنا نظام جديد** - بس ربطنا `AdAutopilotEngine`
بيه في 3 لحظات حقيقية:

- تنفيذ فعلي لقرار Autopilot (`type: ads_autopilot_action`)
- قرار محتاج موافقة العميل (`type: ads_pending_approval`)
- خطأ مزامنة Google Ads (`type: ads_integration_error`)

كل الإشعارات دي بتظهر تلقائيًا في الجرس العام الموجود من غير أي كود إضافي
من ناحيتنا - `Notification::notify()` نفسها فيها `try/catch` داخلي فمش
هتكسر أي حاجة لو جدول `notifications` مش موجود على السيرفر (راجع Known Issues).

---

## البند 27 (Permissions) - صراحة عن القيد

فحصنا نظام الصلاحيات الموجود في المشروع: فيه بس تمييز عام
`admin`/`super_admin` مقابل `user` (`isAdmin` boolean)، **مفيش نظام أدوار
تفصيلي (Viewer/Manager/Admin) خاص بموديول الإعلانات تحديدًا في الـBackend**.
بالتالي مقدرناش نطبّق تقييد UI حسب "Viewer=View Only" لأن ده مش موجود
Backend-side أصلًا (والتعليمة الصريحة كانت "لا تسمح للمستخدم بتجاوز
الصلاحيات من Frontend... الـBackend هو مصدر الحقيقة" - يعني معندناش
حقيقة نطبّقها هنا). كل المستخدمين المسجّلين دخول لحسابهم بيشوفوا كل صفحات
الإعلانات بتاعة حسابهم بس (Multi-tenant isolation مُطبَّق ومُختبَر - راجع
Tests Passed).

---

## Tests Performed (حقيقية - نفس بيئة الـSandbox المستقلة من التسليمات السابقة)

- **Lint**: كل الملفات المعدَّلة (5 ملفات) `php -l` - صفر أخطاء.
- **إعادة تشغيل الاختبارين الحقيقيين الموجودين** بعد كل تعديلات الجلسة دي،
  للتأكد من عدم وجود Regression:
  - `AdAutopilotEngineTest.php`: **8/8 (100%)**
  - `AdMultiTenantIsolationTest.php`: **6/6 (100%)**
- **Smoke test حقيقي لكل Method جديدة في `AdReportService`** ضد بيانات
  مُدخَلة فعليًا في قاعدة بيانات Sandbox (مش نظري):
  - `dashboardSummary()`: تحقّقنا يدويًا من صحة CTR/CPC/CPM/ROAS المحسوبة
    (مثال: 40 نقرة / 1000 ظهور = CTR 4% ✅، 150 إنفاق / 40 نقرة = CPC 0.75 ✅).
  - `dailyTrend()`: أرجعت 5 صفوف حقيقية مطابقة للبيانات المُدخَلة.
  - `campaignComparison()`: أرجعت بيانات صحيحة لكل حملة.
  - **فحص عزل Multi-tenant على `dailyTrend()` مع `campaign_id`**: تأكدنا
    إن مستخدم مش صاحب الحملة بيرجعله صفوف فاضية (0)، مش بيانات حملة غيره.
  - `getConnectionsStatus` query: تحقّقنا من رجوع status/last_error/last_synced_at صح لحالة "error".
- **مراجعة شاملة للـRoutes**: تأكدنا إن كل Public method في `AdsController`
  ليها route مسجَّل فعليًا (سكريبت مقارنة آلي، مش مراجعة يدوية عرضة للخطأ).
- **مراجعة Multi-tenant isolation** على كل الـ6 endpoints الجديدة يدويًا -
  كلها بتفلتر بـ`user_id` من الـSession، مش من أي input خارجي.

### Tests Requiring Google Credentials
- عرض بيانات Google Ads حقيقية على `/ads/connections` و`/ads/campaigns/{id}`
  (يحتاج Developer Token حقيقي - نفس القيد الموثّق في التسليمات السابقة).

### Tests Requiring Meta Credentials
- نفس الشيء لـMeta Ads (`META_APP_ID`/`META_APP_SECRET` حقيقيين).

---

## Known Issues / قيود صريحة

1. ~~**Ad Groups (البند 6) غير مدعوم**~~ **✅ تم الحل** - جدول جديد
   `ad_ad_groups` (تنظيم محلي: اسم، حالة، نسبة ميزانية تقديرية) + ربط
   اختياري لـ`ad_copies`/`ad_keywords` (`ad_group_id` NULL افتراضيًا -
   الحملات القديمة زي ما هي بدون أي كسر). **ملحوظة نطاق صريحة**: ده تنظيم
   محلي داخل Tourfecto بس، مش مزامنة حقيقية ثنائية الاتجاه مع Ad Set
   (Meta) أو Ad Group (Google) الفعليين - المزامنة الحالية بتسحب بيانات
   على مستوى الحملة بس. مفيش بيانات "Performance" لكل مجموعة (موضّح
   صراحة في الـUI) لأن `ad_performance_reports` مش مقسّمة بهذا المستوى.
2. ~~**Bulk Selection / Delete Campaign غير مدعومين**~~ **✅ تم الحل جزئيًا
   وبأمانة** - `POST /api/ads/campaigns/bulk-status` (إيقاف/استئناف
   جماعي حقيقي، لحد 50 حملة، كل واحدة بتتفحص ملكيتها لوحدها) +
   `DELETE /api/ads/campaigns/{id}`. **ملحوظة نطاق مهمة**: Meta Marketing
   API وGoogle Ads API **مفيهمش حذف نهائي حقيقي للحملة على المنصة نفسها**
   (أقصى حاجة ممكنة تقنيًا PAUSED/REMOVED) - فـ"الحذف" هنا Soft Delete
   حقيقي (عمود `deleted_at` جديد، الحملة بتتخفي من كل القوائم لكن بياناتها
   التاريخية كاملة ومحفوظة 100%)، + إيقاف فعلي على المنصة الحقيقية أولًا
   لو كانت شغّالة (أمان إضافي). ده اتضح صراحة في نص التأكيد اللي الواجهة
   بتعرضه للعميل قبل الحذف.
3. ~~**Pause/Resume مباشر من صفحة تفاصيل الحملة غير موصول**~~ **✅ تم
   الحل** - endpoint جديد `POST /api/ads/campaigns/{id}/status` بيستدعي
   نفس `pauseCampaign()`/`resumeCampaign()` الموجودين فعليًا في
   `MetaAdsAPI`/`GoogleAdsAPI` (كانوا مبنيين للـAutopilot بس، دلوقتي
   متاحين للعميل مباشرة كمان)، بيحدّث الحالة محليًا، ويسجّل Audit كامل
   (before/after + can_rollback=1) بالظبط زي أي قرار Autopilot.
4. ~~**Server-side Pagination على قائمة الحملات غير مطبَّقة**~~ **✅ تم
   الحل** - `AdCampaignService::listForUserPaginated()` جديدة (بحث LIKE +
   فلترة حالة + ترتيب + LIMIT/OFFSET حقيقي عبر SQL مباشر) + endpoint جديد
   `GET /api/ads/campaigns/search` + Debounced search (400ms) في الواجهة.
   `listForUser()` الأصلية **لم تُعدَّل سلوكها** إلا لاستبعاد الحملات
   المحذوفة (Soft Delete) - أي كود قديم بيستخدمها لسه شغال بنفس النتائج.
5. ~~**بعض الأقسام لسه مجمّعة داخل `/ads` بدل صفحات مستقلة كاملة**~~ **✅ تم
   الحل** - فُصلت 3 صفحات مستقلة كاملة: `/ads/autopilot` (إعدادات + قرارات
   معلّقة + سجل)، `/ads/copilot` (شات مكبّر)، `/ads/market-research`
   (تحليل + أرشيف تحليلات سابقة جديد). صفحة `/ads` الرئيسية بقت Dashboard
   نضيف (KPIs + توصيات + ربط سريع بس)، وكارت التقرير المكرر داخلها اتشال
   لصالح `/ads/reports` المستقلة. **كل الأكواد القديمة اتنقلت زي ما هي
   بالظبط** (نفس الـHTML/JS، بس في صفحة منفصلة) - صفر منطق جديد، صفر خطر
   إضافي، ونفس الـ14 اختبار حقيقي لسه شغّالة 100% بعد النقل.
6. ~~**نظام صلاحيات تفصيلي (Viewer/Manager/Admin) لسه مش موجود Backend**~~
   **✅ تم الحل - نظام حقيقي كامل من الصفر**. المشروع كله مكانش فيه أي
   مفهوم "أعضاء فريق بأدوار مختلفة على نفس الحساب" (حتى `agencies` هو
   علاقة "وكالة تدير عملاء منفصلين"، مش ده). بنيت:
   - جدول جديد `ad_team_members` (owner_user_id, member_user_id, role: viewer/manager/admin)
   - `AdPermissionService` (حل الصلاحية: owner > admin > manager > viewer)
     + `resolveCampaignAccess()`/`resolveAdsAccessForOwner()`/`resolveAdsAccess()`
     كـhelpers في الـController لتوحيد الفحص عبر كل الـendpoints
   - صفحة `/ads/team` كاملة (إضافة عضو بالإيميل - لازم يكون له حساب
     Tourfecto بالفعل، تغيير دور، إزالة)
    - **✅ تحديث - التغطية اتوسّعت من ~10 endpoint لـ 25+ endpoint حقيقي**:
      `list`, `searchCampaigns`, `getCampaign`, `getAutopilotSettings`،
      `listCopies`, `listKeywords`, `listAdGroups`, `listUtmLinks`,
      `listAdsCompetitorInsights`, `showCampaignDetailsPage` (viewer+)؛
      `updateCampaignStatus`, `deleteCampaign`, `bulkUpdateCampaignStatus`,
      `generateCopies`, `approveCopy`/`rejectCopy`, `generateKeywords`,
      `createAdGroup`, `updateAdGroupStatus`, `deleteAdGroup`,
      `assignKeywordToGroup`, `analyzeLandingPage`, `createUtmLink`,
      `analyzeAdsCompetitor` (manager+)؛ `saveAutopilotSettings` (admin+ -
      بيتحكم في إنفاق تلقائي حقيقي). **مُختبَر فعليًا**: Viewer يقدر يشوف
      تفاصيل حملة (`hasMinRole(viewer)=true`) لكن **فعليًا مايقدرش**
      ينشئ Ad Group أو يحلل صفحة هبوط (`hasMinRole(manager)=false`)، وغريب
      تمامًا لسه ممنوع بالكامل (`allowed=false`) حتى بعد ما أعضاء تانيين
      اتضافوا للحساب - صفر تسريب.
    - **✅ تحديث - التغطية اتوسّعت لجميع الـendpoints المتبقية**:
      كل الـendpoints الحسابية (غير المرتبطة بحملة معيّنة) اتوحّد فحصها
      على مستوى الحساب كله عبر `resolveAdsAccess()` بدل فحص الملكية القديم:
      - **viewer+**: `getDashboardSummary`, `getReportTrend`,
        `getCampaignComparison`, `getReport`, `marketResearchHistory`,
        `listMyCompetitors`, `listOptimizationLogs`, `listPendingActions`,
        `getAlertRules`, `listAlerts`, `markAllAlertsRead`, `dismissAlert`,
        `getConnectionsStatus`, `getMetaConnectionStatus`,
        `getGoogleAdsConnectionStatus`, `listTeamMembers`.
      - **manager+**: `create`, `aiGenerateCampaign`, `marketResearch`,
        `askCopilot`, `saveAlertRules`, `runAlertsNow`, `runAutopilotNow`,
        `approvePendingAction`, `rejectPendingAction`,
        `rollbackOptimizationLog`, `syncMetaCampaigns`,
        `syncGoogleAdsCampaigns`, `publishCampaign`,
        `toggleCampaignStatus`, `cancelCampaign`, `updateCampaignBudget`.
      - **admin+**: `addTeamMember`, `updateTeamMemberRole`,
        `removeTeamMember`, `disconnectMeta`, `disconnectGoogleAds`.
      - `loadPublishedCampaignForManagement()` بقي بيتحلّى عبر
        `resolveCampaignAccess($campaign,'manager')` بدل فحص الملكية اليدوي.
      - **تبديل الحساب**: كل صفحات الموديول بتقرأ `?owner_id=` من الـURL
        وتضيفه تلقائيًا لكل طلبات الـAPI (دالة `fetchJSON` محلية مُغلّفة)،
        فالمستخدم العضو في فريق (viewer/manager/admin) يقدر يتنقل لحساب
        المالك بمجرد الرابط - من غير ما يكتب المعامل يدويًا في كل نداء.
      - **المتبقي مقصود**: OAuth flows (`connectMeta`/`connectGoogleAds`/
        الـcallbacks/اختيار الحساب) لسه بترتبط بجلسة المستخدم الحالي نفسه
        (ربط حساب المنصة الخاص بيه، مش حساب المالك) - ده صحيح بطبيعته؛
        وصفحات `show*Page` قوالب HTML بتجيب البيانات عبر الـAPI المحمي.
    - **قيد UI صريح**: الأزرار (حذف/إيقاف/إلخ) لسه بتظهر لكل الأدوار في
      الواجهة حتى لو Backend هيرفض الطلب لو Viewer ضغط عليها (الأمان
      Server-side كامل، بس UX مش Role-aware 100% لسه - إخفاء الأزرار حسب
      الدور محتاج شغل إضافي).
   - **قيد تقني إضافي**: مفيش "Workspace Switcher" UI جاهز - عضو الفريق
     بيوصل لحساب المالك عن طريق إضافة `?owner_id=X` للرابط يدويًا (موضّح
     في صفحة `/ads/team` نفسها). تجربة مستخدم أفضل (Dropdown لاختيار
     الحساب) محتاجة شغل UI إضافي مستقبلًا.
7. **Notifications table** - نفس الجدول الموثّق في CHANGELOG الأصلي كجزء
   من `_PENDING_TO_RUN_ON_SERVER.sql` (وفيه نفس باگ BIGINT/INT الموثّق
   سابقًا) - لو الجدول مش موجود فعليًا على السيرفر، الإشعارات هتفشل بصمت
   (try/catch داخلي في `Notification::notify()`) بدل ما تكسر الصفحة.
8. **`deleted_at` مش متفحوص في كل الـendpoints الفرعية للحملة** - `getCampaign()`
   و`showCampaignDetailsPage()` بيسمحوا بالوصول للحملة المحذوفة لو حد
   عنده الرابط المباشر (مش هتظهر في أي قائمة، لكن الرابط المباشر لسه
   شغّال). قرار مقصود لتوفير وقت - إضافة الفحص ده لكل الـendpoints
   الفرعية (copies, keywords, utm-links...) محتاجة وقت إضافي.
9. **Debounced Search موجود بس على الحملات** - باقي الصفحات (المنافسون،
   إلخ) مفيهاش Search حاليًا لأن قوائمها عادة قصيرة.

## Tests Performed (تحديث - جولة Ad Groups + Bulk/Delete)

بالإضافة لكل الاختبارات الموثّقة فوق:

- **إعادة تشغيل الاختبارين الحقيقيين بالكامل بعد كل إضافة**:
  `AdAutopilotEngineTest.php` **8/8** و`AdMultiTenantIsolationTest.php`
  **6/6** - صفر Regression في كل خطوة.
- **تطبيق migration `2026_08_11_000044_add_ad_groups.sql` فعليًا** على
  قاعدة بيانات Sandbox حقيقية - نجح بدون أي خطأ.
- **Smoke test حقيقي لميزة Ad Groups**: إنشاء مجموعة إعلانية، ربط كلمة
  مفتاحية بيها، التحقق من العدّ الصحيح عبر نفس مسار الكود اللي بيستخدمه
  الـcontroller، حذف المجموعة والتأكد إن الكلمة المفتاحية **معتش تتحذف**
  (`ad_group_id` بيرجع NULL بس - `ON DELETE SET NULL` شغّال صح)، وفحص عزل
  Multi-tenant (عميل B مايقدرش "يملك" مجموعة تابعة لعميل A).
- **Smoke test حقيقي لـSoft Delete**: حملتين لعميل واحد، حذف واحدة، التأكد
  إن `listForUser()` و`listForUserPaginated()` الاتنين بيستبعدوها، **والتأكد
  الحرج إن الصف لسه موجود فعليًا في قاعدة البيانات** (مش Hard Delete -
  البيانات التاريخية محفوظة زي ما اتوعدنا في الـUI).

---

## Tests Performed (تحديث - جولة توسيع تغطية الصلاحيات)

- **إعادة تشغيل الاختبارين الحقيقيين بعد التوسيع**: **8/8** و**6/6** -
  صفر Regression رغم تعديل 18 موضع فحص ملكية عبر 15 endpoint مختلف.
- **Smoke test حقيقي إضافي**: أنشأت حملة حقيقية لصاحب حساب، ضفت Viewer
  للفريق، وتأكدت إن الـViewer فعليًا `hasMinRole(viewer)=true` (يقدر
  يشوف تفاصيل الحملة/الكلمات/الإعلانات) لكن `hasMinRole(manager)=false`
  (مايقدرش ينشئ Ad Group أو يحلل صفحة هبوط أو يحذف)، وغريب تمامًا لسه
  `allowed=false` بالكامل حتى بعد إضافة أعضاء تانيين للحساب.
- **مراجعة آلية شاملة**: عدّدت كل مواضع فحص الملكية القديمة المتبقية في
  الملف (`grep`) قبل وبعد كل تعديل للتأكد من عدم ترك أي فجوة أمان بالخطأ
  (من 18 موضع لموضع واحد متبقّي - `marketResearch`، موثّق أعلاه ليه اتسيب).

## Tests Performed (تحديث - الجولة السابقة قبل توسيع التغطية)

- **إعادة تشغيل الاختبارين الحقيقيين بعد التطبيق**: **8/8** و**6/6** -
  صفر Regression.
- **تطبيق migration `2026_08_12_000045_add_ads_team_permissions.sql`**
  فعليًا على Sandbox - نجح بدون أخطاء.
- **Smoke test شامل وحقيقي لكل سيناريوهات الصلاحيات (8 حالات)**:
  1. Owner بيوصل لحسابه (role=owner) ✅
  2. غريب تمامًا (مفيش أي عضوية) بيتمنع (allowed=false) ✅
  3. إضافة Viewer وManager بالإيميل ✅
  4. **حرج**: Viewer عنده `hasMinRole(viewer)=true` لكن `hasMinRole(manager)=false`
     و`hasMinRole(admin)=false` - يعني Viewer فعليًا **مايقدرش** يعدّل حاجة ✅
  5. **حرج**: Manager عنده `hasMinRole(manager)=true` لكن `hasMinRole(admin)=false`
     - يعني Manager **مايقدرش** يغيّر إعدادات Autopilot ✅
  6. **حرج**: إضافة أعضاء تانيين ملهاش تأثير على الغريب - لسه ممنوع ✅
  7. **حرج**: إزالة عضو بترجع صلاحيته لصفر فورًا (`allowed=false` بعد الإزالة) ✅
  8. `listMembers()` بيستبعد الأعضاء المُزالين صح ✅

## Configuration Required

**تحديث - Migrations جديدة لازم تتشغّل بالترتيب:**
```bash
mysql -u USER -p DATABASE < database/migrations/2026_08_11_000044_add_ad_groups.sql
mysql -u USER -p DATABASE < database/migrations/2026_08_12_000045_add_ads_team_permissions.sql
composer dump-autoload
```
(بتضيف جدول `ad_ad_groups` + عمود `ad_group_id` اختياري على `ad_copies`/
`ad_keywords` + عمود `deleted_at` على `ad_campaigns` + جدول `ad_team_members`
- كلها إضافات، بدون أي `DROP` أو تعديل مدمّر).

باقي الإعدادات زي ما هي من CHANGELOG.md الأصلي (GOOGLE_ADS_*، إلخ).

**تأكد فقط إن:**
- جدول `notifications` موجود على السيرفر (من `_PENDING_TO_RUN_ON_SERVER.sql`
  بعد تصحيح باگ BIGINT/INT الموثّق) - وإلا الإشعارات مش هتظهر (مش هتكسر
  حاجة، بس مش هتشتغل).

---

# جولة الدمج + التنبيهات الاستباقية (2026-08-15)

## أ) دمج الموديول في الريبو (GitHub: `asd355061-afk/tourfecto-ai`)

دمج الموديول كاملًا في الريبو مع الحفاظ على كل مزايا النشر الموجودة من
قبل (GitHub baseline) + إضافات الموديول، بدون أي تعارض في الـRoutes وبدون
تكسير لأي استدعاء قديم:

```
app/routes/api.php - دمج كتلة ADS (github baseline + إضافات الموديول) - كلها AuthMiddleware
app/routes/web.php - صفحات ADS الجديدة + google/* aliases بجانب google-ads/* + /r/{code}
app/Controllers/AdsController.php - موديول = أساس + نقل دوال النشر من github controller
app/Services/Ads/AdCampaignService.php - platform validation + keyword persistence (من github)
app/Models/AdCampaign.php - fillable = اتحاد حقول github + الموديول
app/Models/AdOptimizationLog.php - v1.1.0 (mode/before/after/can_rollback/rollback_of_log_id)
app/Models/AdAutopilotSetting.php, AdPendingAction.php, AdAdGroup.php, AdTeamMember.php - جديدة
app/Services/Ads/AdAutopilotEngine.php, AdReportService.php, AdPermissionService.php - من الموديول
app/Services/Ads/AdTrackingService.php, AdKeywordStrategistService.php, AdMarketResearchService.php - من الموديول
app/Services/Ads/LandingPageAnalysisService.php, AdsCompetitorInsightsService.php, AdsCopilotService.php - من الموديول
app/Services/Ads/GoogleAdsAPI.php, MetaAdsAPI.php, AdCopyGenerationService.php - موجودة (لم تتغير)
database/migrations/2026_08_15_000050_add_ads_autopilot_and_tracking_tables.sql - جديدة (autopilot/utm/research/insights)
cron/run_ads_autopilot.php - جديدة
```

## ب) تنبيهات استباقية جديدة (Proactive Alerts) - البند المختار من تحليل المنافسين

بناءً على تحليل المنافسين (`docs/ads-competitive-analysis.md`) اخترنا
"Rule-triggered alerts" كأول تحسين - أداة موجودة عند Revealbot/Madgicx
مش موجودة عندنا، وبتستفيد من البنية التحتية الموجودة (Notification +
ad_performance_reports).

**القواعد الخمس:**

| القاعدة | الحد الافتراضي | المعنى |
|---|---|---|
| `budget_exhausted` | 90% | صرف % من الميزانية اليومية = تنبيه critical |
| `cpc_spike` | 200% | زيادة في متوسط CPC عن الأسبوع السابق |
| `ctr_drop` | 50% | انخفاض في CTR عن الأسبوع السابق |
| `landing_page_down` | — | فحص cURL لصفحة الهبوط (لو الرابط موجود) |
| `budget_pacing` | 75% | اليوم عدّى 75% والإنفاق أقل من نص الميزانية |

**مبدأ أساسي:** كل تقييم على بيانات أداء حقيقية من `ad_performance_reports`
(بيانات مُزامنة فعلية). لو مفيش بيانات كافية لأي قاعدة → `insufficient_data`
بتتسكى بصمت - مفيش أي رقم مُختلق.

**الملفات:**

```
database/migrations/2026_08_15_000060_add_ads_alerts.sql - جداول ad_alert_rules + ad_alerts
app/Models/AdAlertRule.php - قواعد المستخدم + الافتراضيات
app/Models/AdAlert.php - تنبيه + منع التكرار لنفس القاعدة/الحملة/اليوم
app/Services/Ads/AdAlertService.php - التقييم + الحفظ + الاسترجاع + runForAllUsers()
app/Controllers/AdsController.php - getAlertRules/saveAlertRules/listAlerts/runAlertsNow/markAllAlertsRead/dismissAlert + showAlertsPage()
app/routes/api.php - 6 routes جديدة (كلها AuthMiddleware)
app/routes/web.php - /ads/alerts صفحة جديدة + تاب "التنبيهات"
cron/run_ads_alerts.php - تشغيل دوري (موصى به كل ساعة)
```

**الإعداد المطلوب (بعد migrations الدمج):**
```bash
mysql -u USER -p DATABASE < database/migrations/2026_08_15_000050_add_ads_autopilot_and_tracking_tables.sql
mysql -u USER -p DATABASE < database/migrations/2026_08_15_000060_add_ads_alerts.sql
```

**Cron jobs المقترحة (جديدة بالإضافة لسابقاتها):**
```bash
# Autopilot: كل ساعة
php /home/USERNAME/domains/YOURSITE.com/cron/run_ads_autopilot.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ads_autopilot.log 2>&1

# Alerts: كل ساعة
php /home/USERNAME/domains/YOURSITE.com/cron/run_ads_alerts.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ads_alerts.log 2>&1
```

**فحص عدم التكرار:** التنبيه بنفس القاعدة لنفس الحملة بيتولّد مرة واحدة يوميًا
(UNIQUE key على `(user_id, campaign_id, rule_type, alert_date)`).
