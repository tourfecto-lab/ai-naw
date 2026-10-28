# تجهيز ربط TripAdvisor — أسهل بكتير من Google

## الخبر الحلو
على عكس Google، TripAdvisor Content API **self-serve** — بتاخد مفتاح API
خلال دقايق من غير موافقة يدوية طويلة. مفتاح واحد بيغطي كل عملائك (مش
OAuth منفصل لكل عميل).

## الحدود اللي لازم تعرفها
- بترجع **أحدث 5 مراجعات بس** لكل موقع (مش الأرشيف الكامل).
- **مفيش رد برمجي** — الـ API للعرض بس. العميل لازم ينسخ الرد المقترح
  ويحطه بنفسه على TripAdvisor مباشرة.
- الحد المجاني: 5000 طلب/شهر، محتاج بطاقة ائتمان مسجّلة لأي استخدام زيادة.
- **لازم تعرض شعار TripAdvisor** وصورة تقييم الفقاعات وقت عرض بياناتهم
  (Display Requirements بتاعتهم — راجع developer-tripadvisor.com).

## الخطوات

1. روح [tripadvisor-content-api.readme.io](https://tripadvisor-content-api.readme.io)
2. سجّل حساب (بالإيميل أو Google/Facebook)
3. حدد ميزانيتك الشهرية القصوى (Max daily budget) وبيانات الدفع
4. هتاخد الـ **API Key** فورًا

## حطه في `.env`

```
TRIPADVISOR_API_KEY=القيمة-اللي-من-TripAdvisor
```

## فعّل المزامنة الدورية

```
Command: php /home/USERNAME/domains/YOURSITE.com/cron/sync_tripadvisor_reviews.php
Schedule: كل 6 ساعات
```

## اختبار الربط
من `/reputation/platforms`، دوس "ربط الحساب" تحت TripAdvisor، اكتب اسم
شركتك، واختار النتيجة الصح من البحث.

## ملحوظة تقنية
أسماء الحقول في رد TripAdvisor (JSON) في الكود اتبنت على أفضل تخمين من
التوثيق العام لأن TripAdvisor مبتنشرش schema كامل رسمي. لو بعد ما تاخد
مفتاح API حقيقي لقيت المراجعات مش بتتجاب صح، ابعتلي رد فعلي من استعلام
تجريبي (Postman أو curl) وأظبط أسماء الحقول بالظبط.