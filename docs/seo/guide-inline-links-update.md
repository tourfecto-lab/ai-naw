# Render and verify links inside approved articles

Base fed9200, branch seo/content-publication-polish.

The customer publication test found that a reviewed article's Markdown links appeared as literal text. GuideContent supported headings, bold and lists but did not convert links. The shared renderer now turns absolute HTTP/HTTPS Markdown links into escaped anchors, including bold labels, query strings and fragments. HTML labels, unsafe schemes and credential-bearing URLs cannot produce executable markup. Existing headings, lists, theme reuse and approved wording are retained.

Publication verification compares the approved source's rendered visible text with public text. A separate quality check requires the actual inline anchor URLs inside the guide/article container, so label text or a similar footer link alone is insufficient. The full-publication reader records bounded article anchors and resets them between pages. Site header/navigation links are excluded. The UI names the new check in Arabic and English. This is a presentation/verification fix; it does not regenerate, republish or alter the saved article draft.

Installation order:
1. On PLATFORM hosting, back up and upload app and public_html from this archive preserving paths. Restart persistent queue workers/reload OPcache and refresh the SEO Expert page.
2. From the platform, download the customer-specific PHP connector again after step 1. Replace tourfecto-connect.php on the CUSTOMER hosting. The standalone connector embeds the renderer, so updating platform PHP alone does not change its article display.
3. Preserve the existing .user.ini auto_prepend_file and open_basedir settings. No SQL, new paid cycle or new article publication is needed.
4. Check the existing article on its verified-live URL, then use the existing cycle's verification action. Confirm clickable inline links, visible FAQ plus matching schema, canonical, theme, internal links and sitemap entry. HTTP success/sitemap discovery does not prove Google indexing or improved rankings.

A /guides URL may fall through a customer's PHP routing and return 404. The existing verifier uses the root ?tf_article=... fallback and updates publication evidence to that live URL. Configure clean /guides routing on the customer's server separately if desired; this update does not rewrite customer routing rules.

Validation: 37/39 groups pass. Known baseline billing failures remain plans_studio 40/47 and plan_gate 19/24. New link tests: 14 assertions, with fake transport and actual publication parsing. Generated PHP connector tests: 40 assertions, including a real local HTTP response with a clickable escaped link. All prior required phase, research, connector, plans, xpay, plan_gate and fx_bank suites were run. Complete output included.

Rollback: restore the backed-up platform PHP/JS and customer connector together; restart workers/reload OPcache. No database rollback or article deletion is needed. A mismatched older connector can correctly leave the new inline-link quality check requiring review.
