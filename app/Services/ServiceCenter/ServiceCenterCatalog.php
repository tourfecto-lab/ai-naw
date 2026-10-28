<?php
/** Routes are existing product screens; readiness is evidence, never a promise of provider approval. */
class ServiceCenterCatalog
{
    public static function all(): array {
        $rows=[
            ['account','Account and security','/profile','/profile/international','Account access, email verification and per-device sessions'],
            ['business','Business, branches and team','/business-center','/business-center','Company facts, services, branding, roles and audience'],
            ['subscription','Plans and usage','/subscription','/subscription','Plan allowances, renewal and usage reservations'],
            ['wallet','Wallet and statements','/subscription','/service-center','Credits, debits, reserved usage and refunds'],
            ['payments','Payments and deposits','/bookings','/operations','Collected payment status and original payment method'],
            ['invoices','Invoices and taxes','/subscription','/profile/settings','Actual billing currency and configured tax rules'],
            ['tours','Tours and booking policy','/bookings','/operations','Availability, extras, cancellation and policy refund review'],
            ['hotel','Room nights and rate plans','/website-builder','/operations','Per-night inventory, seasonal price rules and minimum stays'],
            ['tickets','Tickets and check-in','/operations','/operations','Private vouchers, QR and duplicate-safe check-in'],
            ['reminders','Trip reminders','/bookings','/operations','Local time, reply consent and approved Utility templates'],
            ['builder','Website builder and domains','/website-builder','/website-builder','English by default; explicit audience language and DNS ownership'],
            ['seo','Website optimizer and Auto SEO','/website-optimizer','/auto-seo','Inspect changes, apply supported fixes and review actual outcomes'],
            ['keywords','Keywords and rank tracking','/ai/keywords','/ai/keywords','Configured ranking source, location, language and device'],
            ['analytics','Search Console and Analytics','/integrations','/integrations','Selected properties, token expiry and reporting lag'],
            ['indexing','Search indexing','/auto-seo','/auto-seo','Submission accepted is separate from indexed'],
            ['articles','Articles and website publishing','/ai/articles','/integrations','Draft review, scheduling and external publication outcomes'],
            ['gbp','Google Business Profile','/gbp-content','/gbp-content','Branch connection, profile completeness and publishing'],
            ['reputation','Reputation and review replies','/reputation/reviews','/reputation/platforms','Google replies and explicit manual TripAdvisor actions'],
            ['reviews','Post-trip review requests','/review-requests','/review-requests','One request after completion with suppression and delivery tracking'],
            ['whatsapp','WhatsApp marketing','/whatsapp-marketing','/chat/settings','Templates, consent, audiences, media, experiments and booking attribution'],
            ['inbox','Inbox and approved replies','/chat/pending','/chat','Pending approvals and approved replies that were not sent'],
            ['knowledge','Knowledge base and document import','/chat/knowledge-base','/chat/knowledge-base','Source review, text PDF extraction and optional local OCR'],
            ['assistant','Marketing assistant','/marketing-assistant','/business-center','Audience-specific text with reserved and settled usage'],
            ['images','Creative images and branding','/creative-studio','/creative-studio','Private media, reference assets and editing'],
            ['video','Video generation and editing','/creative-studio','/creative-studio','Generation recovery plus local video processing readiness'],
            ['social','Social media publishing','/social','/social','Per-channel results, partial success and uncertain delivery review'],
            ['ads','Ads and Autopilot','/ads','/ads/connections','Budget limits, approvals, actual API execution and emergency pause'],
            ['crm','CRM, contacts and sequences','/crm','/crm/sequences','Unified customer facts, tasks and scheduled follow-up'],
            ['email','Email marketing','/email-marketing','/email-marketing/settings','Consent, SMTP, suppression, templates and delivery results'],
            ['competitors','Competitor intelligence','/competitor-intelligence','/competitor-monitoring','Dated evidence, comparable offers and rank measurements'],
            ['outreach','Outreach and backlinks','/outreach','/outreach','Review messages, confirm recipients and track real acquired links'],
            ['ota','OTA providers','/integrations','/integrations','Exact provider capabilities, mappings and dated supplier observations'],
            ['revenue','Revenue intelligence','/revenue/intelligence','/revenue/intelligence','Currency-separated revenue, customer MRR and measured forecasts'],
            ['agency','Agencies and White Label','/agency','/agency','Client access, branding, domain verification and commission settlement'],
            ['integrations','API keys and integrations','/integrations','/integrations','Stored credentials are separate from a verified working connection'],
            ['automation','Automation, workers and support','/automation','/help','Worker heartbeat, operation IDs, failures and recovery evidence'],
        ];$out=[];foreach($rows as [$key,$name,$url,$setup,$note])$out[$key]=['key'=>$key,'name'=>$name,'url'=>$url,'setup_url'=>$setup,'note'=>$note,'acceptance_checks'=>['page'=>'Page and authorized actions verified','success'=>'Successful result verified','failure'=>'Failure and safe recovery verified','isolation'=>'Another company cannot access records','mobile'=>'Mobile workflow verified']];return $out;
    }
    public static function get(string $key): array {$all=self::all();if(!isset($all[$key]))throw new InvalidArgumentException('Unknown service');return $all[$key];}
}
