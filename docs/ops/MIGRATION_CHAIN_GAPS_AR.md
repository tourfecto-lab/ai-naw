# فجوات سلسلة الهجرات (database/migrations)

الأداة: `php tools/migration_report.php` تطبّق كل `database/migrations/20*.sql` بالترتيب على قاعدة اختبار فاضية
(ترفض العمل إن لم يحتوِ `DB_NAME` على كلمة test) وتطبع الجمل الفاشلة. القياس على MariaDB 10.4 المحلي (2026-10-28):
**258 ملفًا، 2331 جملة، 68 فشلت، 402 جدولًا** تُبنى.

## حميد (33): هجرات غير idempotent
27 «Duplicate column» و6 «Duplicate key name». العمود أو الفهرس موجود من هجرة سابقة. لا يضرّ.

## حقيقي (35): السلسلة وحدها لا تبني نفس المخطط الذي يتوقعه الكود
تحتاج مطابقة مع مخطط الإنتاج الفعلي (`database/audit_real_schema.php`) قبل إصلاحها. لم أخترع تعريفات أعمدة.

| الملف | المشكلة | الأثر على قاعدة جديدة |
|---|---|---|
| `2026_07_25_000026_create_subscription_plans_table.sql` | عمود `sort_order` غير موجود في INSERT | باقات ناقصة |
| (عدة هجرات: `2026_10_11_audit_repairs`, `2026_10_20_000002_seo_autopilot_plans`, `2026_10_24_000001_pricing_v3`) | العمود `subscription_plans.websites_limit` غير موجود (12 جملة فاشلة) | **أسعار وحدود الباقات لا تُطبّق** |
| `2026_08_09_000048_create_workspace_invites_table.sql` | `Invalid default value for 'expires_at'` (TIMESTAMP بقيمة افتراضية غير صالحة في الوضع الصارم) | جدول `workspace_invites` لا يُنشأ، ثم تفشل 9 جمل في `2026_10_08_000004_consolidate_schema_fixes` |
| `2026_10_11_whatsapp_completion.sql` | الجداول `wa_marketing_contacts/templates/campaigns` غير موجودة | لا توجد جداول تسويق واتساب (قد تُنشأ وقت التشغيل بواسطة `WhatsAppMarketingService`، غير مؤكد) |
| `2026_10_24_000002_outreach_clear_test_tracking.sql` | الجدول `platform_leads` غير موجود | هجرة تنظيف فقط |
| `2026_08_08_000001_create_crm_core_tables.sql` | `crm_pipeline_stages.pipeline_id` غير موجود في INSERT | مراحل CRM الافتراضية لا تُدخل |
| `2026_08_08_000011_create_crm_lifecycle_stages_table.sql` | `crm_contacts.status` غير موجود | عمود دورة الحياة لا يُضاف |
| `2026_08_28_000008_add_stat_lead_scoring.sql` | أعمدة `score_reason/conv_probability/score_confidence` على `crm_leads` | تقييم العملاء المحتملين ناقص |
| `2026_07_25_000026b_add_missing_subscriptions_reviews_columns.sql` | نسخ بيانات من أعمدة قديمة غير موجودة (`platform`, `sentiment_label`, ...) | حميد على قاعدة جديدة (لا بيانات قديمة) |

## ملاحظة تشغيلية
عند أي تثبيت جديد أو بيئة Staging: لا تعتمد على الهجرات وحدها حتى تُصلَح هذه الفجوات؛ قارن المخطط الناتج بمخطط الإنتاج
(`database/audit_real_schema.php`) واستخدم `database/schema.sql` + `fix_local_schema_divergences.sql` كمرجع إضافي.

## الاختبارات
`tests/bootstrap.php` يطبّق هذه السلسلة بالكامل افتراضيًا (`TOURFECTO_TEST_MIGRATION_MODE=legacy` يعيد الطريقة القديمة:
`schema.sql` + قائمة يدوية). النتيجتان متطابقتان على نفس بذرة الترتيب العشوائي.
