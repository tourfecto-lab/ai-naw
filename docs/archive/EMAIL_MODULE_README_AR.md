# التسويق بالبريد + جزء الأدمن – Tourfecto

## أولًا: التسويق بالبريد (جهة العميل)
| الملف | الوظيفة |
|---|---|
| app/Controllers/EmailMarketingController.php | ≈5,300 سطر / 359 دالة: الـ API + HTML الصفحات (قوائم، جهات اتصال، قوالب، حملات، تقارير، أتمتة، A/B، إعدادات) |
| app/Services/EmailMarketing/EmailCampaignService.php | إنشاء الحملات والإرسال على دفعات (100) وتتبع المستلمين |
| .../ContactManagementService.php | المشتركين، الوسوم، الشرائح، الحقول المخصصة، قائمة الحظر (suppressions)، معالجة bounce/complaint |
| .../EmailListService.php, EmailTemplateEditorService.php, EmailRenderer.php | القوائم والقوالب والرسم (rendering) |
| .../EmailAutomationService.php + cron/process_email_automations.php | الأتمتة (WAIT / triggers) |
| .../AbTestService.php | اختبارات A/B |
| .../EmailTrackingService.php | فتح/نقر |
| .../SmtpSettingsService.php + app/Services/Mailer.php | إعدادات SMTP لكل عميل والإرسال الفعلي |
| .../TransactionalEmailService.php | الرسائل التشغيلية |
| app/Jobs/SendEmailCampaignBatchJob.php | تكرار الإرسال على دفعات عبر الـ Queue |
| app/Models/Email*.php + database/migrations/*email* | الموديلز والجداول |
| tests/Unit + tests/Integration | اختبارات موجودة |

## ثانيًا: جزء الأدمن (صاحب المنصة)
| الملف | الوظيفة |
|---|---|
| app/Services/Admin/MailLog.php | تسجيل كل إيميل خارج من المنصة (mail_log) |
| app/Controllers/AdminInsightsController.php | `emails` / `email` / `resendEmail` : سجل الإيميلات + إعادة الإرسال (أسطر ≈90–150) |
| excerpts/AdminController.emailsPage.php.txt | صفحة /admin/emails (من AdminController الضخم) |
| excerpts/AdminController.smtp_settings_section.php.txt | قسم إعدادات SMTP للنظام في لوحة الأدمن |
| app/Controllers/PlatformOutreachController.php | صفحة /admin/growth: حملات بريد بارد (Cold outreach) من المنصة لشركات السياحة + روابط p/u وp/c وp/o |
| public_html/assets/js/admin-growth.js (+css) | واجهة صفحة النمو (SMTP، المتابعات، الحالات) |
| app/Services/Outreach/* | توليد إيميلات الـ outreach والمتابعات والأداء |
| cron/generate_outreach_followups.php | توليد مسودات المتابعة |
| excerpts/routes_*.email.txt | الـ routes الخاصة بالبريد |

## إضافات بعد المراجعة
| الملف | الوظيفة |
|---|---|
| app/Services/PlatformGrowth/PlatformOutreachService.php + cron/platform_outreach.php | المنطق الفعلي لصفحة /admin/growth (جداول platform_leads وplatform_lead_messages) |
| database/migrations/2026_10_08_000003_admin_insights.sql | جدول mail_log لسجل الإيميلات |
| excerpts/WebhookController.emailDeliveryStatusWebhook.php.txt | استقبال حالات التسليم (bounce/complaint) من المزوّد |
| app/Services/System/SystemSettingsService.php | إعدادات النظام (منها SMTP) |
| app/Services/Auth/EmailVerification.php, Jobs/SendBookingConfirmationJob, SendRevenueDigestJob, Services/RegistrationAdminAlert, LoginAdminAlert, AdminAlertService | رسائل تشغيلية/تنبيهات بتستخدم Mailer |

## مش متضمن (قصدًا أو لإنه مشترك)
- أي استخدام تاني للـ Mailer في المشروع (مثلًا TripReminderService, MorningBriefService, ReviewRequestService, SeoScheduledReportService, CrmTeamInviteService): كلها بتبعت عبر نفس Mailer، ابحث عن `Mailer` في المشروع.
- جداول platform_leads غالبًا بتتعمل من داخل الكود (مفيش migration ليها في الأرشيف) - راجع PlatformOutreachService.
- Views/ترجمات app/Lang، ملفات الـ bootstrap والـ autoload، `process_queue.php`.
- routes: الملفات في excerpts فيها السطور اللي فيها كلمة mail/outreach بس، مش كل الـ routes.

## ملاحظات
- كلاسات مشتركة مش موجودة هنا: Database, Logger, Controller, ExternalExecution, QueueManager, SystemSettingsService, WebhookController (فيه `emailDeliveryStatusWebhook`).
- `AdminController.php` ضخم جدًا فاتأخد منه الأجزاء المهمة كـ excerpts بس.
- راجع `EMAIL_IMPROVEMENTS_AR.md`.
