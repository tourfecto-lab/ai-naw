<?php

/**
 * Tourfecto - Legal Controller
 * الصفحات القانونية (الشروط، الخصوصية، الكوكيز، GDPR)
 * @version 2.0.0
 *
 * ملاحظة مهمة: المحتوى هنا مسودة حقيقية مبنية على وظائف المنصة الفعلية
 * (البيانات اللي فعليًا بتتجمع، الخدمات الخارجية اللي فعليًا بتتستخدم:
 * Gemini AI, Google Business API, Meta Marketing API, UltraMsg
 * WhatsApp, TripAdvisor API, تشفير البيانات الحساسة عبر Encryption
 * class). مش نص عام مُلصَق من مكان تاني. **رغم كده، ده مش استشارة
 * قانونية - لازم يراجعها محامي مختص قبل ما تعتبرها نهائية**، خصوصًا لو
 * عندك عملاء في مناطق ليها قوانين خصوصية صارمة (زي الاتحاد الأوروبي/GDPR
 * أو كاليفورنيا/CCPA).
 */

class LegalController extends Controller
{
    private function pageShell(string $title, string $bodyHtml): string
    {
        // الصفحات القانونية جوه نفس هيدر وفوتر الموقع العام. النص الافتراضي إنجليزي،
        // فاتجاه المحتوى بيتحدد من النص نفسه (مش من لغة الزائر) عشان مايتقلبش.
        $plainText = strip_tags($bodyHtml);
        $rtl = preg_match_all('/\p{Arabic}/u', $plainText) > preg_match_all('/[A-Za-z]/', $plainText);
        $languageNotice = function_exists('current_lang') && current_lang() === 'ar' && !$rtl
            ? '<aside lang="ar" dir="rtl" class="p-card" style="margin:18px auto;padding:16px;max-width:900px">النص القانوني المعروض أدناه باللغة الإنجليزية. راجعه قبل الموافقة؛ هذا التنبيه يوضح لغة المستند ولا يمثل ترجمة له.</aside>'
            : '';
        $body = $languageNotice . '<section class="blk tight"><div class="container-narrow prose legal" dir="' . ($rtl ? 'rtl' : 'ltr') . '" lang="' . ($rtl ? 'ar' : 'en') . '">' . $bodyHtml . '</div></section>';
        if (class_exists('PublicLayout')) {
            return PublicLayout::render(['title' => $title, 'description' => $title . ' - Tourfecto', 'body' => $body]);
        }
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title></head><body>' . $body . '</body></html>';
    }

    /** GET /terms */
    public function terms(array $params = []): array
    {
        $body = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('terms_content', self::getDefaultTermsHtml())
            : self::getDefaultTermsHtml();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('Terms of Service', $body);
        exit;
    }

    /** المحتوى الافتراضي لشروط الخدمة - نص واحد بيُستخدم هنا وفي لوحة إعدادات الأدمن */
    public static function getDefaultTermsHtml(): string
    {
        return <<<'HTML'
<h1>Terms of Service</h1>
<div class="updated">Last updated: July 2026</div>

<p>These Terms of Service ("Terms") govern your access to and use of Tourfecto (the "Platform", "we", "us"), a SaaS platform providing AI-powered marketing, reputation management, and business intelligence tools for tourism and hospitality businesses. By creating an account or using the Platform, you agree to these Terms.</p>

<h2>1. The Service</h2>
<p>Tourfecto provides tools including but not limited to: reputation and review management, AI-generated marketing content (articles, ad copy, social media posts), competitor monitoring, CRM and sales pipeline tracking, revenue analytics, website technical audits, and integrations with third-party platforms (Google Business Profile, Meta Ads, TripAdvisor, WhatsApp via UltraMsg).</p>

<h2>2. Accounts</h2>
<p>You must provide accurate information when creating an account and are responsible for maintaining the confidentiality of your login credentials. You are responsible for all activity that occurs under your account, including actions taken by staff members you invite.</p>

<h2>3. AI-Generated Content</h2>
<p>Some features use third-party AI models (currently Google Gemini) to generate text such as review replies, articles, and ad copy. AI-generated content is provided as a draft or suggestion only. You are solely responsible for reviewing, editing, and approving any AI-generated content before it is published, sent to a customer, or used publicly. We do not guarantee the accuracy, appropriateness, or factual correctness of AI-generated output.</p>

<h2>4. Third-Party Integrations</h2>
<p>The Platform may connect to third-party services on your behalf when you explicitly authorize it (OAuth), including Google Business Profile, Meta Marketing API, TripAdvisor, and UltraMsg (WhatsApp Business API). Your use of those integrations is also subject to the respective third party's own terms of service. We are not responsible for the availability, accuracy, or policies of third-party platforms.</p>

<h2>5. Subscriptions and Billing</h2>
<p>Certain features require a paid subscription plan. Fees are billed in advance on a recurring basis as described at checkout. You may cancel your subscription at any time; cancellation takes effect at the end of the current billing period. Fees are non-refundable except where required by law.</p>

<h2>6. Acceptable Use</h2>
<ul>
    <li>You may not use the Platform to send spam, unsolicited bulk messages, or misleading content to customers.</li>
    <li>You may not attempt to reverse-engineer, scrape, or overload the Platform's infrastructure.</li>
    <li>You may not use the Platform to process data you do not have a lawful right to process (e.g., customer contact details collected without appropriate consent or legal basis).</li>
    <li>You are responsible for complying with applicable advertising, consumer protection, and data protection laws in the jurisdictions where you operate.</li>
</ul>

<h2>7. Intellectual Property</h2>
<p>The Platform, its software, design, and branding are owned by Tourfecto. Content you upload (your business data, customer data, media) remains yours; you grant us a limited license to process it solely to provide the Service to you.</p>

<h2>8. Termination</h2>
<p>We may suspend or terminate accounts that violate these Terms, engage in fraudulent activity, or pose a security risk to the Platform or other users. You may close your account at any time; see our <a href="/privacy">Privacy Policy</a> for how your data is handled after account closure.</p>

<h2>9. Limitation of Liability</h2>
<p>The Platform is provided "as is" without warranties of any kind. To the maximum extent permitted by law, Tourfecto is not liable for indirect, incidental, or consequential damages, including loss of revenue, arising from your use of the Platform, including reliance on AI-generated content or third-party integration data.</p>

<h2>10. Changes to These Terms</h2>
<p>We may update these Terms from time to time. Material changes will be communicated via the Platform or email. Continued use after changes take effect constitutes acceptance of the updated Terms.</p>

<h2>11. Contact</h2>
<p>Questions about these Terms can be sent via our <a href="/help/contact">contact page</a>.</p>
HTML;
    }

    /** GET /privacy */
    public function privacy(array $params = []): array
    {
        $body = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('privacy_content', self::getDefaultPrivacyHtml())
            : self::getDefaultPrivacyHtml();
        // مراجعة Meta بتدور على شرح بيانات WhatsApp Business Platform؛ لو الأدمن حافظ نص قديم من غيره بنضيفه
        if (stripos($body, 'id="whatsapp"') === false) {
            $body .= self::whatsAppPrivacyHtml();
        }
        // مراجعة Google (OAuth verification + Google Ads API access) بتدور على إفصاح Limited Use وبيانات كل API
        if (stripos($body, 'id="google-user-data"') === false) {
            $body .= self::googlePrivacyHtml();
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('Privacy Policy', $body);
        exit;
    }

    /** المحتوى الافتراضي لسياسة الخصوصية - نص واحد بيُستخدم هنا وفي لوحة إعدادات الأدمن */
    public static function getDefaultPrivacyHtml(): string
    {
        return <<<'HTML'
<h1>Privacy Policy</h1>
<div class="updated">Last updated: July 2026</div>

<p>This Privacy Policy explains how Tourfecto ("we", "us") collects, uses, and protects information when you use our platform.</p>

<h2>1. Information We Collect</h2>
<p><strong>Account information:</strong> company name, email address, phone number, and password (stored hashed, never in plain text).</p>
<p><strong>Customer/review data you import or connect:</strong> when you connect Google Business Profile, TripAdvisor, or similar platforms, we process reviewer names, review text, ratings, and — where the source platform provides it — reviewer email or phone number. Sensitive fields such as email and phone are encrypted at rest.</p>
<p><strong>CRM data:</strong> contact and lead information you or your team enter into the Platform's CRM module.</p>
<p><strong>Usage data:</strong> login history, IP address, device/browser type, and general activity logs, used for account security (e.g., detecting suspicious logins) and service improvement.</p>
<p><strong>Content you generate:</strong> AI prompts and generated content (articles, ad copy, replies) are processed to provide the feature and may be temporarily sent to our AI provider (Google Gemini) to generate a response.</p>
<p><strong>Cookies:</strong> we use a minimal set of cookies — a session/authentication cookie, and a language-preference cookie (<code>tf_lang</code>) that remembers your chosen interface language. We do not use third-party advertising or tracking cookies.</p>

<h2>2. How We Use Information</h2>
<ul>
    <li>To provide and operate the Platform's features (reputation management, CRM, analytics, AI content generation).</li>
    <li>To communicate with you about your account, billing, or support requests.</li>
    <li>To detect and prevent fraud, abuse, or security incidents.</li>
    <li>To improve the Platform based on aggregate, non-identifying usage patterns.</li>
</ul>

<h2>3. Third Parties We Share Data With</h2>
<p>We share data only as needed to provide the Service, and only with your explicit connection/authorization for platform integrations:</p>
<ul>
    <li><strong>Google (Gemini API, Google Business Profile API):</strong> AI content generation and review syncing, when you connect a Google Business account.</li>
    <li><strong>Meta (Marketing API):</strong> ad campaign data, only when you connect a Meta Ads account.</li>
    <li><strong>TripAdvisor API:</strong> review syncing, when connected.</li>
    <li><strong>Meta (WhatsApp Business Platform / Cloud API):</strong> to send and receive WhatsApp messages from the business's own WhatsApp Business Account, when the business connects it (see the WhatsApp section below).</li>
    <li><strong>UltraMsg:</strong> an optional WhatsApp messaging provider, used only if a business chooses the QR-code connection instead of the official WhatsApp Business Platform.</li>
    <li><strong>Payment processor:</strong> to process subscription payments. We do not store full payment card numbers ourselves.</li>
</ul>
<p>We do not sell your data or your customers' data to third parties for advertising purposes.</p>

<h2>4. Data Security</h2>
<p>Sensitive personal fields (such as customer email and phone numbers collected via reviews) are encrypted at rest. Passwords are hashed and never stored in plain text. We restrict internal access to production data to authorized personnel only.</p>

<h2>5. Data Retention</h2>
<p>We retain account and customer data for as long as your account is active, or as needed to provide the Service. You may request deletion of your account and associated data at any time (see Section 6).</p>

<h2>6. Your Rights</h2>
<p>Depending on your location, you may have rights to access, correct, export, or delete your personal data. You can request a data export or account deletion via your account settings or by contacting us through the <a href="/help/contact">contact page</a>. We aim to respond to verified requests within 30 days.</p>

<h2>7. Children's Privacy</h2>
<p>The Platform is intended for business use and is not directed at children. We do not knowingly collect personal data from individuals under 16.</p>

<h2>8. International Data Transfers</h2>
<p>Our infrastructure and some third-party processors (including our AI and hosting providers) may process data outside your country. We take reasonable steps to ensure such transfers are protected consistent with this Policy.</p>

<h2>9. Changes to This Policy</h2>
<p>We may update this Privacy Policy from time to time. We will note the "Last updated" date above when changes are made; material changes will be communicated via the Platform or email.</p>

<h2>10. Contact</h2>
<p>For privacy questions or data requests, please use our <a href="/help/contact">contact page</a>.</p>
HTML;
    }

    /** قسم بيانات مستخدمي Google في سياسة الخصوصية (بيتضاف تلقائيًا لو مش موجود) */
    public static function googlePrivacyHtml(): string
    {
        return <<<'HTML'

<h2 id="google-user-data">Google User Data</h2>
<p>Tourfecto lets a business connect <strong>its own</strong> Google accounts with Google OAuth. Each connection is optional, is requested only when the business opens the related feature, and can be revoked at any time. We only access the data needed for the feature the business connected:</p>
<ul>
    <li><strong>Google Sign-In</strong> (openid, email, profile): your name, email address and profile picture, to create your account and sign you in.</li>
    <li><strong>Google Business Profile</strong> (business.manage): your business locations, profile information, reviews and review replies, posts and photos, to show and answer your reviews, keep your profile up to date and publish posts you approve.</li>
    <li><strong>Google Ads</strong> (adwords): your Google Ads accounts, campaigns, ad groups, keywords, budgets, conversion actions and performance reports, to show your advertising results in your dashboard and, only when you approve it, create or pause campaigns and change budgets or bids. When a booking on your website comes from one of your campaigns, we send that conversion (with the guest's email or phone number hashed with SHA-256) to your own Google Ads account so your campaigns can measure and optimise bookings.</li>
    <li><strong>Google Analytics</strong> (analytics.readonly): read-only reports for the property you choose (sessions, users, traffic sources, conversions), shown in your dashboard and reports. We never change your Analytics data.</li>
    <li><strong>Google Search Console and Indexing</strong> (webmasters, indexing): search performance for your verified sites, and submitting your new or updated pages for indexing.</li>
    <li><strong>YouTube</strong> (youtube, youtube.upload): your channel details, to publish short videos you create in Tourfecto to your own channel when you choose to.</li>
</ul>
<p><strong>Limited Use:</strong> Tourfecto's use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>, including the Limited Use requirements. We use Google user data only to provide and improve the user-facing features described above for the business that connected it. We do not sell it, use it for advertising, share it with other businesses on the platform, or let humans read it except with your permission, for security purposes, or to comply with the law.</p>
<p><strong>AI:</strong> when you use an AI feature (for example drafting a review reply), the relevant text is sent to our AI provider only to produce that result for you. Google user data is not used to develop, improve or train generalized or non-personalized AI or machine-learning models.</p>
<p><strong>Storage and security:</strong> OAuth tokens are encrypted at rest and are never shown in the browser. Data received from Google is kept while the connection is active and your account exists.</p>
<p><strong>Revoking access and deletion:</strong> you can disconnect any Google service from the Integrations page in Tourfecto, or revoke access at <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>. Disconnecting removes the stored tokens immediately. To delete the data we received from Google, follow our <a href="/data-deletion">data deletion instructions</a>.</p>
HTML;
    }

    /** قسم WhatsApp Business Platform في سياسة الخصوصية (بيتضاف تلقائيًا لو مش موجود) */
    public static function whatsAppPrivacyHtml(): string
    {
        return <<<'HTML'

<h2 id="whatsapp">WhatsApp Business Platform</h2>
<p>Tourfecto is a technology provider that lets a travel business connect <strong>its own</strong> WhatsApp Business Account through Meta's Embedded Signup. We only act on behalf of the business that connected the account.</p>
<p><strong>What we access:</strong> the WhatsApp Business Account ID, phone number ID, display phone number and verified business name; message templates and their approval status; messages your customers send to the business number, messages the business sends from Tourfecto, and delivery/read statuses returned by Meta.</p>
<p><strong>Why:</strong> to show conversations in the business's inbox, let the business (or its AI assistant, when the business enables it) reply, send booking reminders and review requests, and send marketing messages only to customers the business has recorded as having agreed to receive them.</p>
<p><strong>Consent and opt-out:</strong> marketing messages require a recorded opt-in for each contact. A customer can stop marketing messages at any time by replying STOP (or "إلغاء"); the contact is then marked as opted out and excluded from future campaigns automatically.</p>
<p><strong>Storage and security:</strong> access tokens are encrypted at rest and never shown in the browser. Incoming webhook requests are accepted only with a valid Meta signature. Message data is stored for as long as the business keeps its Tourfecto account or until it is deleted.</p>
<p><strong>AI replies:</strong> when the business turns on its AI assistant, message text is sent to our AI provider only to draft a reply for that conversation, and may be used to improve that same business's own reply suggestions.</p>
<p><strong>What we do not do:</strong> we do not sell WhatsApp data, use it for advertising, share it with other businesses on the platform, or use it to train general-purpose AI models.</p>
<p><strong>Disconnecting and deletion:</strong> the business can disconnect WhatsApp at any time from Chat Settings, which immediately removes the stored access token. To delete message history and contacts, follow our <a href="/data-deletion">data deletion instructions</a>.</p>
HTML;
    }

    /** GET /cookies */
    public function cookies(array $params = []): array
    {
        $body = <<<'HTML'
<h1>Cookie Policy</h1>
<div class="updated">Last updated: July 2026</div>
<p>Tourfecto uses a minimal set of cookies necessary for the Platform to function:</p>
<ul>
    <li><strong>Session/authentication cookie</strong> — keeps you logged in securely.</li>
    <li><strong>Language preference cookie (<code>tf_lang</code>)</strong> — remembers your chosen interface language (English, Arabic, French, or German).</li>
</ul>
<p>We do not use third-party advertising or cross-site tracking cookies. See our <a href="/privacy">Privacy Policy</a> for more on how we handle data generally.</p>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('Cookie Policy', $body);
        exit;
    }

    /** GET /data-deletion */
    public function dataDeletion(array $params = []): array
    {
        $body = <<<'HTML'
<h1>Data Deletion Instructions</h1>
<div class="updated">Last updated: July 2026</div>

<p>If you connected Tourfecto through Facebook/Meta Login, or if you'd like any personal data we hold about you or your business deleted, follow the steps below.</p>

<h2>How to Request Deletion</h2>
<ol style="color:#C9D2E0;font-size:14.5px;line-height:1.9;padding-inline-start:22px;">
    <li>Send a deletion request through our <a href="/help/contact">contact page</a>, using the email address associated with your Tourfecto account.</li>
    <li>Include your account email and, if applicable, the connected platform (Google, Meta, TripAdvisor, WhatsApp) you'd like disconnected.</li>
    <li>We verify the request against the account on file, then permanently delete your account data — including profile information, connected platform tokens, and imported customer/review data — from our production systems.</li>
    <li>We aim to complete verified deletion requests within 30 days, and will confirm by email once complete.</li>
</ol>

<h2>What Gets Deleted</h2>
<p>Account and profile information, OAuth connection tokens for any linked platform (Google Business, Meta Ads, TripAdvisor), imported review and CRM data, and uploaded media. Some records may be retained for a limited period where required by law (e.g., billing records for tax compliance) or to prevent fraud.</p>

<h2>Disconnecting Just the Meta Integration</h2>
<p>If you only want to disconnect Meta Ads without deleting your whole Tourfecto account, you can do this yourself from the Ads page in your dashboard ("Disconnect" button), or by removing Tourfecto's access from your own Facebook Business settings.</p>

<h2>Disconnecting WhatsApp</h2>
<p>Businesses can disconnect their WhatsApp number from <strong>Chat Settings → Official WhatsApp → Disconnect</strong>. This immediately removes the stored access token, so Tourfecto can no longer send or receive messages for that number. You can also remove Tourfecto from <em>Business Settings → Accounts → WhatsApp Accounts → Partners</em> in Meta Business Suite. To also delete stored conversations, marketing contacts and consent records, send a deletion request as described above.</p>

<p>See also our <a href="/privacy">Privacy Policy</a> and <a href="/gdpr">GDPR & Data Rights</a> page.</p>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('Data Deletion Instructions', $body);
        exit;
    }

    /** GET /gdpr */
    public function gdpr(array $params = []): array
    {
        $body = <<<'HTML'
<h1>GDPR & Data Rights</h1>
<div class="updated">Last updated: July 2026</div>
<p>If you or your customers are located in the European Economic Area (EEA) or another jurisdiction with similar data protection law, the following applies in addition to our <a href="/privacy">Privacy Policy</a>.</p>

<h2>Your Rights</h2>
<ul>
    <li><strong>Right to access</strong> — request a copy of the personal data we hold about you.</li>
    <li><strong>Right to rectification</strong> — correct inaccurate data.</li>
    <li><strong>Right to erasure</strong> — request deletion of your data, subject to legal retention requirements.</li>
    <li><strong>Right to data portability</strong> — receive your data in a structured, machine-readable format.</li>
    <li><strong>Right to object</strong> — object to certain types of processing.</li>
</ul>

<h2>How to Exercise Your Rights</h2>
<p>Submit a request via our <a href="/help/contact">contact page</a>. We will verify your identity before processing any request and aim to respond within 30 days.</p>

<h2>Data Processing Basis</h2>
<p>We process account and customer data on the basis of contract performance (providing the Service you signed up for), legitimate interest (fraud prevention, service improvement), and, where applicable, your consent (e.g., optional communications).</p>
HTML;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->pageShell('GDPR & Data Rights', $body);
        exit;
    }
}
