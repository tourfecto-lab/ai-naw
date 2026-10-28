# 🌍 Tourfecto - منصة السياحة الذكية

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSE.md)
[![Version](https://img.shields.io/badge/Version-1.0.0-green.svg)](CHANGELOG.md)

## 🏆 نظرة عامة

**Tourfecto** هي منصة سياحية عالمية ذكية متكاملة بمفهوم الـ SaaS، مصممة خصيصاً لخدمة شركات السياحة العالمية. تعتمد المنصة على نموذج عمل هجين (Subscription + Usage-based) وتقدم حلولاً متكاملة في مجالات:

- 🤖 **الذكاء الاصطناعي**: تحليل SEO/AEO/GEO مع نظام كاش ذكي
- ⭐ **إدارة السمعة**: تحليل المشاعر والردود الذكية على منصات متعددة
- 💬 **الشات الذكي**: بوت واتساب مع نظام Human-in-the-Loop
- 📊 **التحليلات**: لوحات تحكم متقدمة وتقارير مفصلة
- 🔒 **الأمان**: تشفير AES-256 والامتثال الكامل لـ GDPR

## ✨ الميزات الرئيسية

### 🧠 محرك الذكاء الاصطناعي
- تحليل SEO/AEO/GEO متقدم
- نظام كاش ذكي لتوفير تكلفة API
- تحليل المنافسين العميق
- توليد محتوى ذكي

### 📱 إدارة السمعة
- تكامل مع TripAdvisor و Google Business
- تحليل المشاعر (Sentiment Analysis)
- ردود تلقائية ذكية مع كلمات مفتاحية محسنة
- متابعة التقييمات في الوقت الفعلي

### 💬 نظام الشات
- بوت واتساب ذكي
- نظام موافقات Human-in-the-Loop
- تعدد القنوات (WhatsApp, Telegram, Messenger)
- ردود توليدية بالذكاء الاصطناعي

### 💳 نظام الاشتراكات
- باقات مرنة (Starter, Professional, Enterprise)
- نموذج هجين (Subscription + Usage-based)
- نظام فوترة متكامل
- تتبع الاستخدام والحدود

### 🔐 الأمان والامتثال
- تشفير AES-256 للبيانات الحساسة
- الامتثال الكامل لـ GDPR
- حماية من CSRF و XSS و SQL Injection
- تحديد معدل الطلبات (Rate Limiting)

## 🛠️ المتطلبات التقنية

- **PHP**: 7.4 أو أحدث
- **MySQL**: 5.7 أو أحدث
- **Web Server**: Apache / Nginx
- **Extensions**: PDO, cURL, JSON, MBString, OpenSSL, GD, Sodium
- **Hosting**: متوافق مع Hostinger

## 📦 التثبيت السريع

### 1. تحميل المشروع

```bash
git clone https://github.com/tourfecto/platform.git
cd platform
```

## 🩺 استكشاف الأخطاء وإصلاحها (Troubleshooting)

سجل بالمشاكل الشائعة اللي بتسبب **خطأ 500** على استضافات PHP 8.x، وطريقة حلها:

### 1. `mb_http_input(): Argument #1 ($type) must be one of "G", "P", "C", "S", "I", or "L"`
**السبب:** استدعاء `mb_http_input('UTF-8')` في `app/Config/app.php`. الدالة دي getter بتاخد نوع مُدخل (G/P/C/S/I/L) مش اسم ترميز، وفي PHP 8.1+ بقت صارمة وبترمي `ValueError`.
**الحل:** احذف السطر `mb_http_input('UTF-8');` بالكامل (مش محتاج أصلاً، لأن `mb_internal_encoding()` و`mb_http_output()` كفاية).

### 2. `Uncaught Error: Undefined constant "SESSION_LIFETIME"`
**السبب:** استخدام `SESSION_LIFETIME` كـ constant في `app/Config/app.php` من غير ما يتعرّف قبل كده.
**الحل:** إضافة تعريف الـ constant قبل استخدامه:
```php
define('SESSION_LIFETIME', getenv('SESSION_LIFETIME') ?: 3600);
```

### 3. `Failed opening required '.../app/Services/Reputation/...php'`
**السبب:** رفع ناقص للمشروع على السيرفر — مجلد `app/Services/Reputation/` (وملفاته زي `ReputationManager.php`, `SentimentAnalyzer.php`, إلخ) متنقلش بالكامل.
**الحل:** التأكد من رفع كل مجلدات `app/` كاملة من الـ ZIP الأصلي، مش رفع ملفات فردية.

### 4. `Declaration of X::method() must be compatible with ParentClass::method()`
**السبب:** كنترولر بيرث من `Controller` وبيعرّف method بنفس اسم method موجودة في الكلاس الأب (زي `validate()`) لكن بـ signature مختلف (باراميترز/return type مختلفين). PHP 8 بيرفض ده بالكامل (Liskov Substitution).
**مثال حقيقي:** `SubscriptionController::validate(array $params = []): array` كانت بتتعارض مع `Controller::validate(array $rules): bool`.
**الحل:** غيّرنا اسم الـ method لحاجة أوضح ومايتعارضش زي `validateSubscriptionStatus()`، وأضفنا الـ route الناقصة ليها في `app/routes/api.php`.

### 🛠️ أداة تشخيص سريعة
لو حصل 500 تاني ومش واضح السبب، ارفع سكريبت تشخيص مؤقت في `public_html/` بيعمل:
- فحص syntax للملفات الأساسية (`php -l`)
- تشغيل `index.php` فعليًا داخل `try/catch` + `register_shutdown_function` عشان يمسك الـ Fatal Error الحقيقي مع اسم الملف ورقم السطر بالظبط
- عرض آخر أسطر من `storage/logs/php_errors.log`
- فحص وجود المجلدات/الملفات الأساسية (`app/`, `vendor/autoload.php`, `storage/`, `.env`)

⚠️ **مهم:** احذف أي سكريبت تشخيص من السيرفر فور ما تخلص، لأنه بيكشف مسارات وتفاصيل حساسة.