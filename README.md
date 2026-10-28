# Tourfecto (tourfecto.pro)

منصة SaaS بالـ PHP (من غير framework) لأصحاب الأنشطة السياحية والمحلية: SEO وظهور في إجابات الذكاء الاصطناعي، محادثات واتساب/ماسنجر/إنستجرام بالـ AI،
نشر على السوشيال، إعلانات، استوديو إبداعي، CRM، حجوزات ومدفوعات، سمعة (Google Business)، ووكالات White-Label.

A PHP SaaS (no framework) for tourism and local businesses: SEO and AI visibility, AI chat on WhatsApp/Messenger/Instagram, social publishing, ads,
creative studio, CRM, bookings and payments, reputation, and white-label agencies.

## بنية المشروع
| المسار | المحتوى |
|---|---|
| `public_html/index.php` | نقطة الدخول للويب |
| `app/Controllers` · `app/Services` · `app/Models` · `app/Jobs` | الكود (Controllers حوالي 140، Services مقسّمة بالمجال) |
| `app/routes/` | `web.php` · `api.php` · `webhooks.php` |
| `database/migrations/` | الهجرات (تُطبَّق يدويًا على السيرفر) · `tools/migration_report.php` يطبّقها على قاعدة اختبار ويعرض الفشل |
| `cron/` | مهام الجدولة |
| `tests/` | اختبارات PHPUnit (1151) |
| `docs/ops/` | ما يخص السيرفر والقرارات: `PENDING_FOR_OWNER_AR.md` أولًا |
| `docs/archive/` | ملفات الحزم والتحديثات القديمة |

## التشغيل المحلي للاختبارات
```bash
composer install
# قاعدة اختبار فاضية (اسمها لازم يحتوي test)
DB_HOST=127.0.0.1 DB_NAME=tourfecto_test DB_USER=root DB_PASS= vendor/bin/phpunit --configuration tests/phpunit.xml
```
التفاصيل والقواعد في [`CLAUDE.md`](CLAUDE.md). CI على GitHub Actions: فحص الصياغة، PHPStan (عادي وموسَّع)، وPHPUnit على MariaDB 10.11 (أي اختبار يفشل يكسر البناء).

## النشر
يدوي (رفع ملفات). **لا تنشر قبل مراجعة** `docs/ops/PENDING_FOR_OWNER_AR.md` و`docs/ops/PHASE0_SERVER_HARDENING_AR.md`.
