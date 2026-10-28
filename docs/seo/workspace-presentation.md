# Readable SEO workspace and product review

The Results product form mixed manual entry with import controls, stacked unspaced fields, and put long explanations before the customer's action. Shared SEO headings also inherited general-site colors that were unreadable on a dark panel. This update changes the shared SEO workspace/form styles and the Results presentation without changing backend services, catalog records, billing or measurement logic.

## Customer behavior

- Consistent readable headings, regular-weight explanatory text, field spacing, 44–46 px controls and visible keyboard focus across the SEO workspace.
- A clear import/manual choice. Import starts with one URL action; manual mode shows the review form and hides import controls. Switching preserves entered facts, clears consent and never saves automatically.
- Full-width product name/description, a paired price/currency row on desktop and one column on mobile. Consent and Save have a separate footer. URL/currency direction remains LTR inside Arabic forms.
- Short instructions with expandable details retaining the catalog/payment limitations. Native sales still explicitly avoid SEO attribution. Calculation details and WooCommerce's optional source remain accessible on demand; no action is removed.
- Clear date ranges in RTL, spaced workspace cards and a flexible statistics grid. Styles are scoped to SEO; this is not a redesign of every unrelated platform page.

## Hosting / تعليمات الرفع

ارفع الملفات الثلاثة التالية إلى استضافة **tourfecto.pro** في مساراتها الحالية:

- `public_html/assets/css/seo-expert.css`
- `public_html/assets/css/seo-workspace.css`
- `public_html/assets/js/seo-impact.js`

ثم امسح كاش ملفات CSS/JS في المتصفح وأي CDN مستخدم، وافتح صفحة النتائج بتحديث قوي. الروابط تستخدم `asset_v` الموجود بالفعل لتحديث النسخة. لا يوجد SQL جديد أو تعديل على إضافة/ملف ربط العميل. يفترض التحديث تثبيت تحديث الإضافة/الاستيراد `06dda3e`. الاختبارات والتوثيق والمعاينات المرفقة للمراجعة ولا يلزم رفعها للتشغيل.

## Validation

The actual Panel/product browser suite now has 22 assertions, retaining its CSRF transport, retry identity, consent, selection preservation, escaped imported content and cross-site race checks. Added checks cover a single initial entry method, selected mode state, preserved facts with fresh consent, desktop price/currency layout, Arabic mobile width, both themes' heading/action text contrast (at least 4.5:1) and regular-weight explanatory text using the real base/panel/theme/SEO stylesheets.

Reviewed the correct account's page with the three changed assets substituted locally in the browser, leaving production files unchanged. Arabic desktop and mobile views in light and dark themes had no JS errors or horizontal document overflow. No catalog save, booking or financial write occurred. These local visual checks do not establish a production deployment or business outcome.

The full required regression output is included in `seo-workspace-presentation-test-output.txt`. The report-language fixture initially raced its local PHP server's startup under load. Its fixed 250 ms sleep was replaced by a bounded readiness wait; the actual 15 report HTTP/language/status assertions then passed. This test-only change does not alter report/application behavior. The initial failure and successful rerun are both retained in the output.

The readiness browser test now opens the retained optional WooCommerce section before checking its guidance, and explicitly verifies that it starts collapsed. All previous source/owner/transport assertions remain; the focused rerun passed14 assertions. The final product browser rerun passed22 assertions. Final results: **72 groups, 70 passed after the focused rechecks**. Required phases1–6, connector, xpay and fx_bank pass. The same unrelated baseline failures remain plans_studio40/47 and plan_gate19/24. PHP lint checked1,293 files without syntax errors.
