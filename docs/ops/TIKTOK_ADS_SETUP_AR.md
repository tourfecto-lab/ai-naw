# تفعيل إعلانات TikTok (TikTok Ads)

الكود جاهز لكنه **مقفول** لحد ما تتضاف مفاتيح تطبيق TikTok for Business. ما اتجرّبش على حساب TikTok حقيقي، فأول نشر هو الاختبار الفعلي.

1. افتح https://business-api.tiktok.com/portal وسجّل كمطوّر، وأنشئ **تطبيق** (App) من نوع Marketing API.
2. في إعدادات التطبيق اطلب صلاحيات: Ad Account Management, Ads Management, Reporting, Creative Management.
3. ضيف Redirect URL: `https://tourfectoai.pro/ads/connect/tiktok/callback` (للتجربة) وبعدين رابط الموقع الأصلي.
4. TikTok بتراجع التطبيق قبل ما يشتغل مع حسابات عملاء غيرك. للتجربة على حسابك إنت بس مش لازم موافقة.
5. في `.env` على السيرفر:
   - `TIKTOK_ADS_APP_ID=...`
   - `TIKTOK_ADS_APP_SECRET=...`
   - (اختياري) `TIKTOK_ADS_REDIRECT_URI=...`
6. بعد كده يظهر كارت "TikTok Ads" في صفحة الإعلانات، ويظهر TikTok في نافذة النشر.
7. اختياري: طبّق `database/migrations/2026_10_30_000001_ad_campaigns_platform_tiktok.sql` يدويًا عشان الحملات تتعلّم "TikTok". من غيره الحملة بتتنشر عادي وتفضل علامتها "Linked".

ملاحظات: التطبيق ده منفصل عن تطبيق نشر المحتوى (`TIKTOK_CLIENT_KEY`) ولا يلمس إعداداته. كل حاجة بتتنشر **متوقفة**.
